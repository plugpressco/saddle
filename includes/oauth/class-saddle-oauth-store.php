<?php
/**
 * Persistence for OAuth clients, grants, codes, and tokens.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the authorization server needs to remember, in one private CPT.
 *
 * This follows the pattern {@see Saddle_Approval} already established for
 * confirmation tokens, for the same reasons: no schema migration, no custom
 * table, and garbage collection rides the hourly cron Saddle already schedules.
 *
 * Four record kinds share the CPT, distinguished by `_saddle_oauth_kind`:
 *
 *   - `client`  — a registered client (via DCR) or a cached Client ID Metadata
 *                 Document. Holds no secret; a client id is public by design.
 *   - `grant`   — one authorization a person approved: which client, which
 *                 WordPress user, which scopes. This is what the Connections
 *                 screen lists and what Revoke deletes.
 *   - `code`    — a single-use authorization code, 60 seconds, PKCE-bound.
 *   - `access` / `refresh` — bearer tokens issued against a grant.
 *
 * Nothing secret is stored in the clear. A record is keyed by `post_name`, which
 * holds a SHA-256 digest of the secret **namespaced by kind** — so a value that
 * is valid as an authorization code can never be replayed as an access token,
 * even if the two digests were somehow made to collide. `post_name` is indexed
 * in `wp_posts`, which matters because an access token is looked up on every
 * single MCP request.
 */
class Saddle_OAuth_Store {

	/**
	 * Private CPT holding every OAuth record.
	 */
	const CPT = 'saddle_oauth';

	/**
	 * Authorization code lifetime (seconds). OAuth 2.1 recommends a maximum of
	 * one minute; codes are single-use on top of that.
	 */
	const CODE_TTL = 60;

	/**
	 * Access token lifetime (seconds).
	 */
	const ACCESS_TTL = 3600;

	/**
	 * Refresh token lifetime (seconds). Rotated on every use.
	 */
	const REFRESH_TTL = 2592000;

	/**
	 * How long a spent authorization code is remembered so a replay can be
	 * detected. Comfortably longer than the code's own lifetime.
	 */
	const REPLAY_TTL = 600;

	/**
	 * Pending-authorization lifetime (seconds) — how long someone has to read
	 * the consent screen and decide.
	 */
	const REQUEST_TTL = 900;

	/**
	 * How long a fetched Client ID Metadata Document is cached when the origin
	 * sends no usable cache headers (seconds).
	 */
	const CLIENT_TTL = 86400;

	/**
	 * Ceiling on stored client registrations. Dynamic Client Registration is an
	 * unauthenticated endpoint by protocol design, so the record count is capped
	 * and the oldest unused registrations are evicted rather than allowed to grow
	 * without bound. A registration confers nothing on its own — only an
	 * administrator completing the consent screen creates a grant.
	 */
	const MAX_CLIENTS = 200;

	/**
	 * Register the storage CPT. Hidden from every UI, feed, and export.
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'               => 'Saddle OAuth',
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * The lookup key for a secret of a given kind.
	 *
	 * Namespacing by kind is the point: it makes cross-kind replay structurally
	 * impossible rather than merely checked-for.
	 *
	 * @param string $kind   Record kind.
	 * @param string $secret Raw secret (token, code, or client id).
	 * @return string 64-char hex digest.
	 */
	private static function key( $kind, $secret ) {
		return hash( 'sha256', (string) $kind . '|' . (string) $secret );
	}

	/**
	 * Persist a record.
	 *
	 * @param string $kind   Record kind.
	 * @param string $secret Raw secret the record is looked up by.
	 * @param array  $meta   Meta to store (keys are prefixed automatically).
	 * @param int    $ttl    Lifetime in seconds; 0 for no expiry.
	 * @return int|WP_Error Post id, or WP_Error on failure.
	 */
	private static function put( $kind, $secret, array $meta, $ttl ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				// Stored as 'publish' for the same reason Saddle_Approval does:
				// 'private' would make WP_Query apply read_private_posts filtering,
				// so a record owned by a lower-privilege user would look missing.
				// The CPT is non-public and non-queryable, so nothing is exposed.
				'post_status' => 'publish',
				'post_title'  => $kind,
				'post_name'   => self::key( $kind, $secret ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error(
				'saddle_oauth_persist_failed',
				__( 'Could not store the OAuth record.', 'saddle' )
			);
		}

		update_post_meta( $post_id, '_saddle_oauth_kind', $kind );
		update_post_meta( $post_id, '_saddle_oauth_expires', $ttl > 0 ? time() + (int) $ttl : 0 );

		foreach ( $meta as $meta_key => $value ) {
			update_post_meta( $post_id, '_saddle_' . $meta_key, $value );
		}

		return (int) $post_id;
	}

	/**
	 * Find a record's post id by kind and secret. Does not check expiry.
	 *
	 * Direct SQL rather than WP_Query, for two reasons.
	 *
	 * The correctness reason: the bearer resolver runs on
	 * `determine_current_user`, which can fire before `init` — that is, before
	 * {@see self::register_cpt()} has run. WP_Query filters on registered post
	 * types and would silently return nothing, so a perfectly good access token
	 * would fail to authenticate depending only on when in the request it was
	 * examined. A direct lookup is registration-independent.
	 *
	 * The performance reason: `post_name` is indexed in `wp_posts` and
	 * `post_title` is not. {@see Saddle_Approval} keys on the title, which is
	 * fine for a handful of confirmation tokens a day; an access token is looked
	 * up on every single MCP request, so this one keys on the slug.
	 *
	 * @param string $kind   Record kind.
	 * @param string $secret Raw secret.
	 * @return int 0 when not found.
	 */
	private static function find( $kind, $secret ) {
		global $wpdb;

		if ( '' === (string) $secret ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed single-row lookup on a private CPT that must work before init; see docblock.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status = 'publish' LIMIT 1",
				self::CPT,
				self::key( $kind, $secret )
			)
		);

		if ( ! $post_id ) {
			return 0;
		}

		// The kind is already namespaced into the lookup key, so a cross-kind hit
		// is not reachable. Assert it anyway — this is an authentication path.
		if ( (string) get_post_meta( $post_id, '_saddle_oauth_kind', true ) !== (string) $kind ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Read a record as a plain array, or null when missing or expired.
	 *
	 * An expired record is deleted on the way out, so the sweep is continuous
	 * rather than depending solely on cron.
	 *
	 * @param string $kind   Record kind.
	 * @param string $secret Raw secret.
	 * @return array|null
	 */
	public static function get( $kind, $secret ) {
		$post_id = self::find( $kind, $secret );
		if ( ! $post_id ) {
			return null;
		}

		$record = self::hydrate( $post_id );

		if ( $record['expires'] > 0 && time() > $record['expires'] ) {
			wp_delete_post( $post_id, true );
			return null;
		}

		return $record;
	}

	/**
	 * Build the array shape for a stored record.
	 *
	 * @param int $post_id Record post id.
	 * @return array
	 */
	private static function hydrate( $post_id ) {
		$record = array(
			'id'      => (int) $post_id,
			'kind'    => (string) get_post_meta( $post_id, '_saddle_oauth_kind', true ),
			'expires' => (int) get_post_meta( $post_id, '_saddle_oauth_expires', true ),
		);

		foreach ( (array) get_post_meta( $post_id ) as $meta_key => $values ) {
			if ( 0 !== strpos( $meta_key, '_saddle_' ) || 0 === strpos( $meta_key, '_saddle_oauth_' ) ) {
				continue;
			}
			$record[ substr( $meta_key, strlen( '_saddle_' ) ) ] = maybe_unserialize( $values[0] );
		}

		return $record;
	}

	/**
	 * Delete a record by kind and secret.
	 *
	 * @param string $kind   Record kind.
	 * @param string $secret Raw secret.
	 * @return bool Whether a record was removed.
	 */
	public static function forget( $kind, $secret ) {
		$post_id = self::find( $kind, $secret );
		if ( ! $post_id ) {
			return false;
		}

		wp_delete_post( $post_id, true );
		return true;
	}

	/*
	 * Clients.
	 */

	/**
	 * Store (or refresh) a client record.
	 *
	 * @param string $client_id Client identifier — an opaque id for DCR, or the
	 *                          metadata document URL for CIMD.
	 * @param array  $metadata  Validated client metadata.
	 * @param int    $ttl       Lifetime in seconds; 0 to keep indefinitely.
	 * @return int|WP_Error
	 */
	public static function save_client( $client_id, array $metadata, $ttl = 0 ) {
		self::forget( 'client', $client_id );
		self::evict_surplus_clients();

		return self::put(
			'client',
			$client_id,
			array(
				'client_id'                  => (string) $client_id,
				'client_name'                => isset( $metadata['client_name'] ) ? (string) $metadata['client_name'] : '',
				'client_uri'                 => isset( $metadata['client_uri'] ) ? (string) $metadata['client_uri'] : '',
				'logo_uri'                   => isset( $metadata['logo_uri'] ) ? (string) $metadata['logo_uri'] : '',
				'redirect_uris'              => isset( $metadata['redirect_uris'] ) ? (array) $metadata['redirect_uris'] : array(),
				'token_endpoint_auth_method' => isset( $metadata['token_endpoint_auth_method'] ) ? (string) $metadata['token_endpoint_auth_method'] : 'none',
				'client_source'              => isset( $metadata['client_source'] ) ? (string) $metadata['client_source'] : 'dcr',
				'client_created'             => time(),
			),
			$ttl
		);
	}

	/**
	 * Read a client record.
	 *
	 * @param string $client_id Client identifier.
	 * @return array|null
	 */
	public static function get_client( $client_id ) {
		return self::get( 'client', $client_id );
	}

	/**
	 * Keep the client table bounded. Registrations are unauthenticated by
	 * protocol design, so the oldest records are evicted once the cap is hit.
	 * Grants are unaffected — revoking access is a separate, explicit action.
	 */
	private static function evict_surplus_clients() {
		$query = new WP_Query(
			array(
				'post_type'              => self::CPT,
				'post_status'            => 'publish',
				'title'                  => 'client',
				'posts_per_page'         => 50,
				'offset'                 => self::MAX_CLIENTS,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}

	/*
	 * Grants — one per authorization a person approved.
	 */

	/**
	 * Record an approved authorization.
	 *
	 * @param array $args Grant fields: grant_id, client_id, client_name,
	 *                     user_id, scope, and resource.
	 * @return int|WP_Error
	 */
	public static function save_grant( array $args ) {
		return self::put(
			'grant',
			(string) $args['grant_id'],
			array(
				'grant_id'      => (string) $args['grant_id'],
				'client_id'     => (string) $args['client_id'],
				'client_name'   => isset( $args['client_name'] ) ? (string) $args['client_name'] : '',
				'user_id'       => (int) $args['user_id'],
				'scope'         => (string) $args['scope'],
				'resource'      => isset( $args['resource'] ) ? (string) $args['resource'] : '',
				'grant_created' => time(),
				'last_used'     => 0,
			),
			0
		);
	}

	/**
	 * Read a grant.
	 *
	 * @param string $grant_id Grant identifier.
	 * @return array|null
	 */
	public static function get_grant( $grant_id ) {
		return self::get( 'grant', $grant_id );
	}

	/**
	 * Stamp a grant as used. Throttled to once a minute so a busy agent doesn't
	 * turn every tool call into a database write.
	 *
	 * @param string $grant_id Grant identifier.
	 */
	public static function touch_grant( $grant_id ) {
		$post_id = self::find( 'grant', $grant_id );
		if ( ! $post_id ) {
			return;
		}

		$last = (int) get_post_meta( $post_id, '_saddle_last_used', true );
		if ( time() - $last < MINUTE_IN_SECONDS ) {
			return;
		}

		update_post_meta( $post_id, '_saddle_last_used', time() );
	}

	/**
	 * Every grant on the site, newest first, for the Connections screen.
	 *
	 * @return array[]
	 */
	public static function list_grants() {
		$query = new WP_Query(
			array(
				'post_type'              => self::CPT,
				'post_status'            => 'publish',
				'title'                  => 'grant',
				'posts_per_page'         => 100,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$grants = array();
		foreach ( $query->posts as $post_id ) {
			$grants[] = self::hydrate( (int) $post_id );
		}

		return $grants;
	}

	/**
	 * Revoke a grant and every token issued against it.
	 *
	 * @param string $grant_id Grant identifier.
	 * @return bool Whether the grant existed.
	 */
	public static function revoke_grant( $grant_id ) {
		$existed = self::forget( 'grant', $grant_id );
		self::delete_by_grant( $grant_id );

		return $existed;
	}

	/**
	 * Delete every code and token bound to a grant.
	 *
	 * @param string $grant_id Grant identifier.
	 */
	private static function delete_by_grant( $grant_id ) {
		$query = new WP_Query(
			array(
				'post_type'              => self::CPT,
				'post_status'            => 'publish',
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Revocation must reach every token bound to the grant; leaving any behind would make Revoke a lie.
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Revocation must find every token bound to this grant; that is exactly a meta lookup.
				'meta_query'             => array(
					array(
						'key'   => '_saddle_grant_id',
						'value' => (string) $grant_id,
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}

	/*
	 * Codes and tokens.
	 */

	/**
	 * Park a validated authorization request while the person decides.
	 *
	 * The authorize endpoint validates everything the client sent, stores it
	 * here, and redirects to the consent screen carrying nothing but this opaque
	 * id. That indirection is deliberate: no client-supplied string ever reaches
	 * the wp-admin URL, so nothing has to survive escaping through the
	 * `wp-login.php?redirect_to=` round trip and there is no reflected-parameter
	 * surface on the consent screen itself.
	 *
	 * @param string $request_id Opaque request identifier.
	 * @param array  $meta       Validated request parameters.
	 * @return int|WP_Error
	 */
	public static function save_request( $request_id, array $meta ) {
		return self::put( 'authreq', $request_id, $meta, self::REQUEST_TTL );
	}

	/**
	 * Read a pending authorization request without consuming it.
	 *
	 * @param string $request_id Opaque request identifier.
	 * @return array|null
	 */
	public static function get_request( $request_id ) {
		return self::get( 'authreq', $request_id );
	}

	/**
	 * Validate and burn a pending authorization request. Single-use, so the
	 * consent screen cannot be submitted twice.
	 *
	 * @param string $request_id Opaque request identifier.
	 * @return array|null
	 */
	public static function consume_request( $request_id ) {
		$post_id = self::find( 'authreq', $request_id );
		if ( ! $post_id ) {
			return null;
		}

		$record = self::hydrate( $post_id );
		wp_delete_post( $post_id, true );

		if ( $record['expires'] > 0 && time() > $record['expires'] ) {
			return null;
		}

		return $record;
	}

	/**
	 * Store a single-use authorization code.
	 *
	 * @param string $code Raw authorization code.
	 * @param array  $meta Code metadata (client_id, grant_id, redirect_uri,
	 *                     scope, resource, code_challenge, user_id).
	 * @return int|WP_Error
	 */
	public static function save_code( $code, array $meta ) {
		return self::put( 'code', $code, $meta, self::CODE_TTL );
	}

	/**
	 * Validate and burn an authorization code.
	 *
	 * The record is deleted before any branching, so a code that fails validation
	 * is still spent — replaying it after a failed exchange gets nothing.
	 *
	 * A tombstone outlives the code. If the same code is presented twice, the
	 * second attempt lands on the tombstone, and OAuth 2.1 §4.1.2 requires the
	 * authorization server to revoke every token already issued from that code:
	 * two parties holding one code means one of them stole it, and there is no
	 * way to tell which. Revoking the family is the only safe response.
	 *
	 * @param string $code Raw authorization code.
	 * @return array|null Code metadata, or null when unknown, expired, or replayed.
	 */
	public static function consume_code( $code ) {
		$post_id = self::find( 'code', $code );

		if ( ! $post_id ) {
			$replayed = self::get( 'code_used', $code );
			if ( $replayed && ! empty( $replayed['grant_id'] ) ) {
				self::revoke_grant( (string) $replayed['grant_id'] );
			}
			return null;
		}

		$record = self::hydrate( $post_id );
		wp_delete_post( $post_id, true );

		if ( $record['expires'] > 0 && time() > $record['expires'] ) {
			return null;
		}

		self::put(
			'code_used',
			$code,
			array( 'grant_id' => isset( $record['grant_id'] ) ? (string) $record['grant_id'] : '' ),
			self::REPLAY_TTL
		);

		return $record;
	}

	/**
	 * Store an access or refresh token.
	 *
	 * @param string $kind  'access' or 'refresh'.
	 * @param string $token Raw token.
	 * @param array  $meta  Token metadata.
	 * @return int|WP_Error
	 */
	public static function save_token( $kind, $token, array $meta ) {
		$ttl = ( 'refresh' === $kind ) ? self::REFRESH_TTL : self::ACCESS_TTL;

		return self::put( $kind, $token, $meta, $ttl );
	}

	/**
	 * Read a live access token.
	 *
	 * @param string $token Raw token.
	 * @return array|null
	 */
	public static function get_access_token( $token ) {
		return self::get( 'access', $token );
	}

	/**
	 * Validate and burn a refresh token.
	 *
	 * Refresh tokens are single-use and rotated on every exchange. That is what
	 * makes theft detectable: if a stolen token is redeemed, the legitimate
	 * client's next refresh lands on the tombstone, and vice versa. Either way
	 * two parties hold one token, so the whole grant is revoked and the person
	 * has to re-authorize — which is the point at which they find out.
	 *
	 * @param string $token Raw token.
	 * @return array|null Token metadata, or null when unknown, expired, or reused.
	 */
	public static function consume_refresh_token( $token ) {
		$post_id = self::find( 'refresh', $token );

		if ( ! $post_id ) {
			$reused = self::get( 'refresh_used', $token );
			if ( $reused && ! empty( $reused['grant_id'] ) ) {
				self::revoke_grant( (string) $reused['grant_id'] );
			}
			return null;
		}

		$record = self::hydrate( $post_id );
		wp_delete_post( $post_id, true );

		if ( $record['expires'] > 0 && time() > $record['expires'] ) {
			return null;
		}

		self::put(
			'refresh_used',
			$token,
			array( 'grant_id' => isset( $record['grant_id'] ) ? (string) $record['grant_id'] : '' ),
			self::REFRESH_TTL
		);

		return $record;
	}

	/**
	 * Delete every expired record. Wired to Saddle's existing hourly cron.
	 */
	public static function gc() {
		$query = new WP_Query(
			array(
				'post_type'              => self::CPT,
				'post_status'            => 'publish',
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Fixed-size hourly sweep batch, not an unbounded query.
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Hourly GC over a tiny private CPT; the meta filter is the point of the sweep.
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => '_saddle_oauth_expires',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_saddle_oauth_expires',
						'value'   => time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}

	/**
	 * Remove every OAuth record. Used when the owner turns OAuth off, so
	 * disabling the server actually disconnects the apps rather than leaving
	 * live tokens waiting for it to come back on.
	 */
	public static function purge() {
		$query = new WP_Query(
			array(
				'post_type'              => self::CPT,
				'post_status'            => 'publish',
				'posts_per_page'         => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page, WordPress.WP.PostsPerPage.posts_per_page_posts_per_page_-1 -- Runs only when the owner switches OAuth off, and must leave nothing live behind.
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}
