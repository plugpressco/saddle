<?php
/**
 * Two-step confirmation gate for destructive abilities.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The approval gate.
 *
 * Any destructive ability routes its mutation through {@see self::gate()}. The
 * first call (no token) performs a dry run: it returns a human-readable preview
 * and issues a single-use confirm token, mutating nothing. A second call that
 * echoes that token back executes the mutation exactly once. Tokens are
 * single-use, action-bound, and expire after 15 minutes.
 *
 * Tokens are stored as posts of the private `saddle_approval` CPT — the title
 * holds the token, post meta holds the bound action and the expiry timestamp.
 * Reusing the existing CPT pattern keeps Saddle schema-migration-free.
 */
class Saddle_Approval {

	/**
	 * Private CPT used to persist pending confirmation tokens.
	 */
	const CPT = 'saddle_approval';

	/**
	 * Token lifetime in seconds (15 minutes).
	 */
	const TOKEN_TTL = 900;

	/**
	 * Cron hook for expired-token garbage collection.
	 */
	const GC_HOOK = 'saddle_gc_tokens';

	const META_CONNECTION = '_saddle_connection';
	const META_APP        = '_saddle_app';
	const META_SUMMARY    = '_saddle_summary';
	const META_PREVIEW    = '_saddle_preview';
	const META_DECISION   = '_saddle_decision';
	const META_DECIDED_BY = '_saddle_decided_by';
	const META_DECIDED_AT = '_saddle_decided_at';

	const DECISION_APPROVED = 'approved';
	const DECISION_REJECTED = 'rejected';

	/**
	 * Register the token-storage CPT. Hidden from every UI and export.
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'               => 'Saddle Approvals',
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
	 * Gate a destructive action behind a preview + confirm-token handshake.
	 *
	 * @param array $args {
	 *     Gate arguments.
	 *
	 *     @type string   $action  Stable action identifier, e.g. 'delete_post'.
	 *                             The token is bound to this — a token issued for
	 *                             one action cannot confirm another.
	 *     @type string   $target  Item the action affects (e.g. post id). The
	 *                             token is bound to this too — a token previewed
	 *                             for one item cannot be replayed against another.
	 *     @type string   $bind    Optional. Any confirmation-relevant parameter
	 *                             that changes what the confirmed action does —
	 *                             most importantly recoverability (trash vs.
	 *                             permanent delete). Folded into the token
	 *                             identity so a preview shown for a reversible
	 *                             action can't be confirmed into an irreversible
	 *                             one. Keep it out of $action/$target so logging
	 *                             stays clean.
	 *     @type string   $summary One-line plain-language description of the
	 *                             effect, shown in the preview.
	 *     @type string|callable $done Optional. What the confirmed call did,
	 *                             in the past tense ("Moved post #5 to the
	 *                             trash."). The activity log records it
	 *                             instead of the summary once the change has
	 *                             run. A callable receives the executor's
	 *                             result and returns the line, for a result
	 *                             that can differ from the preview (the item
	 *                             was already in that state). Without it, or
	 *                             when it returns '', the log keeps the
	 *                             summary, so callers that predate it work
	 *                             unchanged.
	 *     @type array    $preview Structured detail of what will change.
	 *     @type array    $input   The ability's input (read for `confirm_token`).
	 *     @type callable $execute Zero-arg callable that performs the mutation
	 *                             and returns the result (or WP_Error).
	 * }
	 * @return array|WP_Error Preview array (dry run), the execute() result
	 *                        (confirmed), or WP_Error on an invalid token.
	 */
	public static function gate( array $args ) {
		$action  = isset( $args['action'] ) ? (string) $args['action'] : '';
		$target  = isset( $args['target'] ) ? (string) $args['target'] : '';
		$bind    = isset( $args['bind'] ) ? (string) $args['bind'] : '';
		$input   = ( isset( $args['input'] ) && is_array( $args['input'] ) ) ? $args['input'] : array();
		$execute = isset( $args['execute'] ) ? $args['execute'] : null;

		if ( '' === $action || ! is_callable( $execute ) ) {
			return new WP_Error( 'saddle_gate_misconfigured', __( 'Internal error: approval gate was called without an action or executor.', 'saddle' ) );
		}

		$token = ( isset( $input['confirm_token'] ) && is_string( $input['confirm_token'] ) ) ? trim( $input['confirm_token'] ) : '';

		// Confirmation path: validate, consume, execute. The token is bound to
		// both the action AND the specific target, so a token previewed for one
		// item cannot be replayed to act on a different item.
		if ( '' !== $token ) {
			$ok = self::consume_token( $token, $action, $target, $bind, self::current_connection() );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$result = call_user_func( $execute );

			// Log the executed destructive action (not the preview). A WP_Error
			// result is logged too — the executor may have partially mutated
			// before failing, and a confirmed destructive call with no audit
			// trail is worse than a noisy one.
			if ( class_exists( 'Saddle_Log' ) ) {
				$summary = isset( $args['summary'] ) ? (string) $args['summary'] : '';
				if ( is_wp_error( $result ) ) {
					$summary = sprintf(
						/* translators: 1: original summary, 2: error message. */
						__( '%1$s. Failed after confirmation: %2$s', 'saddle' ),
						rtrim( $summary, '. ' ),
						$result->get_error_message()
					);
				} else {
					$done    = self::done_line( isset( $args['done'] ) ? $args['done'] : null, $result );
					$summary = '' !== $done ? $done : $summary;
				}
				Saddle_Log::record(
					array(
						'action'  => $action,
						'target'  => $target,
						'summary' => $summary,
					)
				);
			}

			return $result;
		}

		// Dry-run path: issue a token, return a preview, mutate nothing.
		$new_token = self::issue_token(
			$action,
			$target,
			$bind,
			array(
				'summary' => isset( $args['summary'] ) ? (string) $args['summary'] : '',
				'preview' => isset( $args['preview'] ) ? $args['preview'] : null,
			)
		);
		if ( is_wp_error( $new_token ) ) {
			return $new_token;
		}

		return array(
			'requires_confirmation' => true,
			'confirm_token'         => $new_token,
			'expires_in_seconds'    => self::TOKEN_TTL,
			'action'                => $action,
			'summary'               => isset( $args['summary'] ) ? (string) $args['summary'] : '',
			'preview'               => isset( $args['preview'] ) ? $args['preview'] : null,
			'instructions'          => __( 'This is a preview — nothing has changed. To proceed, call this tool again with the same arguments plus "confirm_token" set to the value above. The token is single-use and expires in 15 minutes. The owner can also approve this on their Saddle Home page.', 'saddle' ),
		);
	}

	/**
	 * Create and persist a single-use token bound to an action, a target, and
	 * the previewing user.
	 *
	 * @param string $action Action identifier.
	 * @param string $target Target identifier the token is bound to (e.g. post id).
	 * @param string $bind   Confirmation-relevant parameter bound to the token
	 *                       (e.g. permanent-vs-trash); '' when the action has none.
	 * @param array  $detail Optional. `summary` and `preview`, kept so the owner
	 *                       can decide on the Dashboard.
	 * @return string|WP_Error 32-char hex token, or WP_Error on failure.
	 */
	private static function issue_token( $action, $target = '', $bind = '', array $detail = array() ) {
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'saddle_token_generation_failed', __( 'Could not generate a secure confirmation token.', 'saddle' ) );
		}

		// Stored as 'publish' on a non-public, non-queryable CPT. Using 'private'
		// would make WP_Query apply read_private_posts capability filtering, so a
		// valid token issued to a write-tier author/contributor would look
		// invalid on confirmation. The CPT is hidden from every surface, so
		// 'publish' here is not publicly exposed.
		//
		// Only the SHA-256 hash of the token is persisted, never the token
		// itself: a DB-read path (a backup, an over-broad plugin query) then sees
		// an unusable digest, not a live confirmation token. The raw token is
		// returned to the caller and never stored.
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_title'  => self::hash_token( $token ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error( 'saddle_token_persist_failed', __( 'Could not persist the confirmation token.', 'saddle' ) );
		}

		update_post_meta( $post_id, '_saddle_action', $action );
		update_post_meta( $post_id, '_saddle_target', $target );
		update_post_meta( $post_id, '_saddle_bind', $bind );
		// The token belongs to whoever saw the preview: with several agents on
		// one site (separate app passwords / users), agent A's preview must
		// not be confirmable by agent B.
		update_post_meta( $post_id, '_saddle_user', get_current_user_id() );
		update_post_meta( $post_id, '_saddle_expires', time() + self::TOKEN_TTL );

		// Which app asked. A preview is the asking app's own: another key of
		// the same user cannot confirm it, and a browser session ('') cannot
		// confirm an app's.
		$connection = self::current_connection();
		update_post_meta( $post_id, self::META_CONNECTION, $connection );

		// What the owner needs to decide on the Dashboard ("Needs your OK").
		// Post meta on the token post, so it goes with the token: same expiry,
		// same clean-up, no table and no autoloaded option.
		update_post_meta( $post_id, self::META_APP, self::app_name( $connection ) );
		update_post_meta( $post_id, self::META_SUMMARY, isset( $detail['summary'] ) ? (string) $detail['summary'] : '' );
		$preview = wp_json_encode( isset( $detail['preview'] ) ? $detail['preview'] : null );
		update_post_meta( $post_id, self::META_PREVIEW, wp_slash( false === $preview ? 'null' : $preview ) );
		update_post_meta( $post_id, self::META_DECISION, '' );

		return $token;
	}

	/**
	 * Deterministic digest a token is stored and looked up under. SHA-256 is
	 * fine here: the token is already 128 bits of CSPRNG output, so there is no
	 * low-entropy input to protect against — the hash exists only so the value
	 * at rest can't be replayed as a live token.
	 *
	 * @param string $token Raw token.
	 * @return string 64-char hex digest.
	 */
	private static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * The past-tense log line for a confirmed call, from the gate's `done`.
	 *
	 * @param string|callable|null $done   A line, or a callable that builds one
	 *                                     from the executor's result.
	 * @param mixed                $result What the executor returned.
	 * @return string '' when there is no line, so the caller keeps the summary.
	 */
	private static function done_line( $done, $result ) {
		if ( is_callable( $done ) && ! is_string( $done ) ) {
			$done = call_user_func( $done, $result );
		}

		return is_string( $done ) ? trim( $done ) : '';
	}

	/**
	 * Validate and consume a token. Single-use: the token record is deleted on
	 * lookup regardless of outcome, so even a mismatched/expired token cannot be
	 * retried. The consumer must be the same user the preview was issued to.
	 *
	 * @param string      $token  Candidate token.
	 * @param string      $action Action the token must be bound to.
	 * @param string      $target Target the token must be bound to (e.g. post id).
	 * @param string      $bind   Confirmation-relevant parameter the token must be
	 *                            bound to (e.g. permanent-vs-trash); '' when none.
	 * @param string|null $connection Connection id the token must be bound to
	 *                       (`key:<uuid>`, `oauth:<grant>`, or '' for a browser
	 *                       session). Null reads the calling connection.
	 * @return true|WP_Error
	 */
	public static function consume_token( $token, $action, $target = '', $bind = '', $connection = null ) {
		if ( null === $connection ) {
			$connection = self::current_connection();
		}

		// WP_Query's `title` parameter is an exact match (since WP 4.4), which is
		// the security property we need — a partial match would let a prefix of a
		// valid token confirm an action. We match on the token's hash, since only
		// the hash is stored (see issue_token()).
		$query = new WP_Query(
			array(
				'post_type'              => self::CPT,
				'title'                  => self::hash_token( $token ),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $query->posts ) ) {
			return new WP_Error(
				'saddle_invalid_token',
				__( 'Invalid or already-used confirmation token. Request a new preview to get a fresh token.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		$post_id       = (int) $query->posts[0];
		$stored_action = get_post_meta( $post_id, '_saddle_action', true );
		$stored_target = (string) get_post_meta( $post_id, '_saddle_target', true );
		$stored_bind   = (string) get_post_meta( $post_id, '_saddle_bind', true );
		$stored_user   = (int) get_post_meta( $post_id, '_saddle_user', true );
		$expires       = (int) get_post_meta( $post_id, '_saddle_expires', true );
		$stored_conn   = (string) get_post_meta( $post_id, self::META_CONNECTION, true );
		$decision      = (string) get_post_meta( $post_id, self::META_DECISION, true );

		// Single-use: burn the token now, before any further branching.
		wp_delete_post( $post_id, true );

		if ( get_current_user_id() !== $stored_user ) {
			return new WP_Error(
				'saddle_token_user_mismatch',
				__( 'This confirmation token was issued to a different user. Preview the action yourself, then confirm with the token it returns.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		// Bound to the app that asked, like it is to the item: two keys of one
		// user must not confirm each other's previews.
		if ( $stored_conn !== (string) $connection ) {
			return new WP_Error(
				'saddle_token_connection_mismatch',
				__( 'This confirmation token was issued to a different app. Preview the action yourself, then confirm with the token it returns.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		if ( $stored_action !== $action ) {
			return new WP_Error(
				'saddle_token_mismatch',
				__( 'This confirmation token was issued for a different action and cannot be used here.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		if ( $stored_target !== (string) $target ) {
			return new WP_Error(
				'saddle_token_target_mismatch',
				__( 'This confirmation token was issued for a different item. Preview the exact item you intend to change, then confirm with the token it returns.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		// A token previewed for a reversible action (e.g. move to trash) must not
		// be replayed to confirm an irreversible one (permanent delete). The
		// recoverability-relevant parameter is part of the bound identity, and
		// some tools also bind what they previewed (a page's content), so a
		// mismatch can also mean the item changed since the preview.
		if ( $stored_bind !== (string) $bind ) {
			return new WP_Error(
				'saddle_token_bind_mismatch',
				__( 'This confirmation token no longer matches the request. Either the item changed since the preview, or this call asks for more than the preview showed (for example, a permanent delete after a preview of moving to trash). Preview the exact action again, then confirm with the token it returns.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		if ( time() > $expires ) {
			return new WP_Error(
				'saddle_token_expired',
				__( 'This confirmation token has expired. Request a new preview to get a fresh token.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		// The owner's word on the Dashboard wins over the agent's confirm.
		if ( self::DECISION_REJECTED === $decision ) {
			return new WP_Error(
				'saddle_rejected_by_owner',
				__( 'The site owner said no to this change. Don’t retry it; ask them what they want instead.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * The calling app's connection id, or '' for a browser session.
	 *
	 * @return string
	 */
	private static function current_connection() {
		if ( ! class_exists( 'Saddle_Connections' ) ) {
			return '';
		}
		$credential = Saddle_Connections::credential();

		return ( is_array( $credential ) && isset( $credential['id'] ) ) ? (string) $credential['id'] : '';
	}

	/**
	 * What to call the app behind a connection: its known name ("Claude"),
	 * else what it calls itself, else the key's name.
	 *
	 * @param string $connection Connection id.
	 * @return string
	 */
	private static function app_name( $connection ) {
		if ( '' === $connection ) {
			return __( 'Your browser', 'saddle' );
		}

		$records = class_exists( 'Saddle_Connections' ) ? Saddle_Connections::all() : array();
		$record  = isset( $records[ $connection ] ) ? $records[ $connection ] : array();
		$client  = isset( $record['client_name'] ) ? (string) $record['client_name'] : '';
		$name    = '';

		list( $kind, $ref ) = array_pad( explode( ':', $connection, 2 ), 2, '' );
		if ( 'key' === $kind && class_exists( 'WP_Application_Passwords' ) ) {
			$item = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $ref );
			if ( is_array( $item ) && isset( $item['name'] ) ) {
				$name = (string) $item['name'];
				if ( 0 === strpos( $name, Saddle_REST_Admin::CLIENT_PREFIX ) ) {
					$name = trim( substr( $name, strlen( Saddle_REST_Admin::CLIENT_PREFIX ) ) );
				}
			}
		} elseif ( 'oauth' === $kind && class_exists( 'Saddle_OAuth_Store' ) ) {
			$grant = Saddle_OAuth_Store::get_grant( $ref );
			if ( is_array( $grant ) && ! empty( $grant['client_name'] ) ) {
				$name = (string) $grant['client_name'];
			}
		}

		$app = class_exists( 'Saddle_Connection_Apps' ) ? Saddle_Connection_Apps::detect(
			array(
				'client_name' => $client,
				'name'        => $name,
			)
		) : '';

		$labels = array(
			'claude-code' => 'Claude Code',
			'gemini-cli'  => 'Gemini CLI',
			'claude'      => 'Claude',
			'chatgpt'     => 'ChatGPT',
			'codex'       => 'Codex',
			'cursor'      => 'Cursor',
			'vscode'      => 'VS Code',
			'windsurf'    => 'Windsurf',
			'openclaw'    => 'OpenClaw',
			'grok'        => 'Grok',
		);

		if ( '' !== $app && isset( $labels[ $app ] ) ) {
			return $labels[ $app ];
		}

		foreach ( array( $name, $client ) as $fallback ) {
			if ( '' !== $fallback ) {
				return $fallback;
			}
		}

		return __( 'An app', 'saddle' );
	}

	/**
	 * Delete expired tokens. Wired to an hourly cron event.
	 */
	public static function gc() {
		$query = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Hourly GC over a tiny private token CPT; the meta filter is the point of the sweep.
				'meta_query'     => array(
					array(
						'key'     => '_saddle_expires',
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
	 * The requests waiting on the owner: unexpired, undecided, newest first.
	 *
	 * @return array[] `id`, `app`, `connection`, `tool`, `target`, `summary`,
	 *                 `preview`, `created_at`, `expires_at`.
	 */
	public static function pending() {
		$query = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A handful of live tokens in a private CPT; expiry is the filter.
				'meta_query'     => array(
					array(
						'key'     => '_saddle_expires',
						'value'   => time(),
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::META_SUMMARY,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			if ( '' !== (string) get_post_meta( $post->ID, self::META_DECISION, true ) ) {
				continue;
			}
			$expires = (int) get_post_meta( $post->ID, '_saddle_expires', true );
			$preview = json_decode( (string) get_post_meta( $post->ID, self::META_PREVIEW, true ), true );
			$rows[]  = array(
				'id'         => (int) $post->ID,
				'app'        => (string) get_post_meta( $post->ID, self::META_APP, true ),
				'connection' => (string) get_post_meta( $post->ID, self::META_CONNECTION, true ),
				'tool'       => (string) get_post_meta( $post->ID, '_saddle_action', true ),
				'target'     => (string) get_post_meta( $post->ID, '_saddle_target', true ),
				'summary'    => (string) get_post_meta( $post->ID, self::META_SUMMARY, true ),
				'preview'    => $preview,
				'created_at' => $expires - self::TOKEN_TTL,
				'expires_at' => $expires,
			);
		}

		return $rows;
	}

	/**
	 * Record the owner's decision on a pending request.
	 *
	 * @param int    $id       Token post id.
	 * @param string $decision `approve` or `reject`.
	 * @return array|WP_Error `id` and `decision`.
	 */
	public static function decide( $id, $decision ) {
		$id   = (int) $id;
		$post = $id ? get_post( $id ) : null;

		if ( ! $post || self::CPT !== $post->post_type || '' === (string) get_post_meta( $id, self::META_SUMMARY, true ) ) {
			return new WP_Error( 'saddle_approval_not_found', __( 'That request is no longer waiting.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( time() > (int) get_post_meta( $id, '_saddle_expires', true ) ) {
			return new WP_Error( 'saddle_approval_expired', __( 'That request has expired. Ask your AI to try again.', 'saddle' ), array( 'status' => 410 ) );
		}
		if ( '' !== (string) get_post_meta( $id, self::META_DECISION, true ) ) {
			return new WP_Error( 'saddle_approval_decided', __( 'That request was already decided.', 'saddle' ), array( 'status' => 409 ) );
		}

		$approved = 'approve' === $decision;

		update_post_meta( $id, self::META_DECISION, $approved ? self::DECISION_APPROVED : self::DECISION_REJECTED );
		update_post_meta( $id, self::META_DECIDED_BY, get_current_user_id() );
		update_post_meta( $id, self::META_DECIDED_AT, time() );

		if ( class_exists( 'Saddle_Log' ) ) {
			Saddle_Log::record(
				array(
					'action'  => $approved ? 'owner-approved' : 'owner-rejected',
					'target'  => (string) get_post_meta( $id, '_saddle_target', true ),
					'summary' => self::decision_line( $id, $approved ),
				)
			);
		}

		return array(
			'id'       => $id,
			'decision' => $decision,
		);
	}

	/**
	 * The owner's decision as the activity log says it: "You approved Claude
	 * Code’s request: Publish post #18 …". The log is the owner's own record,
	 * so it speaks to them, and it names the app that asked.
	 *
	 * @param int  $id       Token post id.
	 * @param bool $approved Whether the owner approved.
	 * @return string
	 */
	private static function decision_line( $id, $approved ) {
		$asked = (string) get_post_meta( $id, self::META_SUMMARY, true );

		// A request made with no app behind it came from a signed-in browser.
		if ( '' === (string) get_post_meta( $id, self::META_CONNECTION, true ) ) {
			return $approved
				/* translators: %s: what was asked, such as "Publish post #18". */
				? sprintf( __( 'You approved a request made in a browser: %s', 'saddle' ), $asked )
				/* translators: %s: what was asked, such as "Publish post #18". */
				: sprintf( __( 'You rejected a request made in a browser: %s', 'saddle' ), $asked );
		}

		$app = (string) get_post_meta( $id, self::META_APP, true );

		return $approved
			/* translators: 1: app name, such as Claude Code, 2: what it asked, such as "Publish post #18". */
			? sprintf( __( 'You approved %1$s’s request: %2$s', 'saddle' ), $app, $asked )
			/* translators: 1: app name, such as Claude Code, 2: what it asked, such as "Publish post #18". */
			: sprintf( __( 'You rejected %1$s’s request: %2$s', 'saddle' ), $app, $asked );
	}
}
