<?php
/**
 * Tokenized front-end previews — the safe window the agent's own client
 * screenshots.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Real pixels without custody (https://github.com/plugpressco/saddle/issues/25):
 * Saddle never renders or screenshots anything itself — it mints a signed,
 * short-lived URL onto the site's OWN front end, and the agent's MCP client
 * (which has a browser) does the seeing. Nothing leaves the install.
 *
 * The serving mechanic is the proven public-preview pattern: the URL carries
 * `preview=1` plus an HMAC token; when the token verifies, the main query's
 * post is flipped to publish IN MEMORY (core's read-cap enforcement for
 * non-public statuses runs after `posts_results`, so the flip is exactly the
 * sanctioned hook point). The token — not a login — is the access control:
 * post-bound, 5-minute TTL, HMAC over a rotating site-local secret. Previews
 * are marked noindex and never listed anywhere.
 */
class Saddle_Preview {

	/**
	 * Token lifetime, in seconds. Long enough to open and screenshot, short
	 * enough that a leaked URL goes stale before it travels.
	 */
	const TTL = 300;

	/**
	 * Query arg carrying the token.
	 */
	const QUERY_ARG = 'saddle_preview';

	/**
	 * Option holding { secret, previous, rotated }.
	 */
	const OPTION = 'saddle_preview_secret';

	/**
	 * Rotate the signing secret when older than this. Verification accepts
	 * the previous secret too, so rotation never breaks a just-minted token.
	 */
	const ROTATE_AFTER = DAY_IN_SECONDS;

	/**
	 * Hook the serving path. Called unconditionally from the bootstrap —
	 * serving a token must work even when the Abilities API is absent.
	 */
	public static function register() {
		add_filter( 'posts_results', array( __CLASS__, 'filter_posts_results' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'harden_response' ), 0 );
	}

	/**
	 * Mint a preview URL for a post.
	 *
	 * @param WP_Post $post The post.
	 * @return array { url, expires_in }
	 */
	public static function mint( WP_Post $post ) {
		$expires = time() + self::TTL;
		$token   = $expires . '.' . self::signature( $post->ID, $expires, self::secrets()['secret'] );

		$args = array(
			'preview'       => 1,
			self::QUERY_ARG => $token,
		);
		// Hierarchical types resolve by page_id, everything else by p.
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$args['page_id'] = $post->ID;
		} else {
			$args['p'] = $post->ID;
		}

		return array(
			'url'        => add_query_arg( $args, home_url( '/' ) ),
			'expires_in' => self::TTL,
		);
	}

	/**
	 * Whether a token grants a view of a post right now.
	 *
	 * @param string $token   Token from the URL.
	 * @param int    $post_id The post the request resolves to.
	 * @return bool
	 */
	public static function verify( $token, $post_id ) {
		$parts = explode( '.', (string) $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		list( $expires, $signature ) = $parts;
		$expires                     = (int) $expires;
		if ( $expires < time() ) {
			return false;
		}

		$secrets = self::secrets();
		foreach ( array( $secrets['secret'], $secrets['previous'] ) as $secret ) {
			if ( '' !== $secret && hash_equals( self::signature( (int) $post_id, $expires, $secret ), $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Serve a verified preview: flip the queried post to publish in memory so
	 * core's public-status check (which runs after this filter) lets the
	 * site's own front end render it. The flip is bound to the exact post the
	 * token signs — a token for one draft opens nothing else.
	 *
	 * @param WP_Post[] $posts Main-query results.
	 * @param WP_Query  $query The query.
	 * @return WP_Post[]
	 */
	public static function filter_posts_results( $posts, $query ) {
		if ( ! self::requested() || ! $query->is_main_query() || 1 !== count( $posts ) ) {
			return $posts;
		}
		if ( ! $query->is_preview() || ! $query->is_singular() ) {
			return $posts;
		}

		$post = $posts[0];
		if ( ! self::verify( self::requested(), $post->ID ) ) {
			return $posts;
		}

		if ( 'publish' !== $post->post_status ) {
			$posts[0]->post_status = 'publish';
		}
		return $posts;
	}

	/**
	 * Mark an active preview response noindex and chrome-free.
	 */
	public static function harden_response() {
		if ( ! self::requested() || ! is_singular() ) {
			return;
		}
		// Only harden when the token actually verified for this very post —
		// a garbage token on a public URL is just a normal page view.
		if ( ! self::verify( self::requested(), get_queried_object_id() ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		show_admin_bar( false );
	}

	/*
	---------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------
	 */

	/**
	 * The raw token from the request, or '' when none.
	 *
	 * @return string
	 */
	private static function requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The HMAC token IS the verification.
		return isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';
	}

	/**
	 * HMAC over the (post, expiry) pair.
	 *
	 * @param int    $post_id Post id.
	 * @param int    $expires Expiry timestamp.
	 * @param string $secret  Signing secret.
	 * @return string
	 */
	private static function signature( $post_id, $expires, $secret ) {
		return hash_hmac( 'sha256', $post_id . '|' . $expires, $secret );
	}

	/**
	 * The signing secrets, created on first use and rotated lazily. The
	 * previous secret stays valid so rotation can never invalidate a token
	 * younger than its TTL.
	 *
	 * @return array { secret, previous, rotated }
	 */
	private static function secrets() {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		$secret   = isset( $stored['secret'] ) && is_string( $stored['secret'] ) ? $stored['secret'] : '';
		$previous = isset( $stored['previous'] ) && is_string( $stored['previous'] ) ? $stored['previous'] : '';
		$rotated  = isset( $stored['rotated'] ) ? (int) $stored['rotated'] : 0;

		if ( '' === $secret || ( time() - $rotated ) > self::ROTATE_AFTER ) {
			$previous = $secret;
			$secret   = wp_generate_password( 64, false );
			$rotated  = time();
			update_option(
				self::OPTION,
				array(
					'secret'   => $secret,
					'previous' => $previous,
					'rotated'  => $rotated,
				),
				false
			);
		}

		return array(
			'secret'   => $secret,
			'previous' => $previous,
			'rotated'  => $rotated,
		);
	}
}
