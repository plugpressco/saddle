<?php
/**
 * Bearer token authentication and the 401 challenge.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where an OAuth access token becomes a WordPress user — and where a request
 * without one is told how to get one.
 *
 * Two responsibilities, both on the request path:
 *
 *   1. Resolve `Authorization: Bearer …` into the user the token was authorized
 *      for, carrying the scope forward as a ceiling on what that request may do.
 *   2. When a request to the MCP endpoint is refused, attach the RFC 9728
 *      challenge that tells a client where this resource's authorization server
 *      lives — the mechanism that lets ChatGPT start the connect flow at all.
 */
class Saddle_OAuth_Bearer {

	/**
	 * The ACCESS TOKEN record backing the current request, once one has resolved.
	 *
	 * Named for what it holds. It used to be called `$grant`, which was a lie
	 * worth the rename: the grant and its tokens each carry their own copy of
	 * `scope`, this is the token's, and reading it as though it were the grant's
	 * is how a change to a connection's access level would appear to do nothing
	 * for an hour. {@see Saddle_OAuth_Store::set_grant_scope()} rewrites both for
	 * the same reason.
	 *
	 * @var array|null
	 */
	private static $token_record = null;

	/**
	 * The scope currently on the grant behind that token.
	 *
	 * Read live on every request and applied as a second ceiling, so lowering a
	 * connection's access level takes effect on the next call rather than
	 * whenever its hour-long access token happens to expire. Raising still needs
	 * the token record rewritten, which is the safe direction to be strict in.
	 *
	 * @var string
	 */
	private static $grant_scope = '';

	/**
	 * Why the current request's token failed, for the challenge to report.
	 *
	 * @var string
	 */
	private static $failure = '';

	/**
	 * Resolve a Saddle OAuth bearer token to a WordPress user.
	 *
	 * Hooked to `determine_current_user` at priority 30 — after core's cookie
	 * resolver (10) and its Application Password resolver (20). That ordering
	 * has three consequences, all wanted:
	 *
	 *   - An existing session or a working Basic credential always wins, so
	 *     nothing about the legacy Application Password path changes.
	 *   - Running after core means `rest_cookie_check_errors()` sees a logged-in
	 *     user with no auth cookie and lets the request through without
	 *     demanding an `X-WP-Nonce` — correct, since there is no cookie to
	 *     forge a request with.
	 *   - `rest_get_authenticated_app_password()` stays null, so
	 *     {@see Saddle_Connection::scope_credentials()} correctly ignores this
	 *     request. Confinement for bearer traffic is this method's own endpoint
	 *     check, backed by {@see self::confine()}.
	 *
	 * The endpoint check is the primary confinement and it is deliberately
	 * placed here rather than downstream: a Saddle OAuth token is never resolved
	 * into a user on any request that is not aimed at the MCP endpoint. It
	 * therefore cannot authenticate `wp/v2`, `wp-abilities/v1`, XML-RPC,
	 * wp-admin, or Saddle's own control plane — not even far enough to be
	 * refused by something later.
	 *
	 * @param int|false $user_id User id resolved by an earlier callback, if any.
	 * @return int|false
	 */
	public static function resolve( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		if ( ! Saddle_OAuth::is_enabled() ) {
			return $user_id;
		}

		$token = self::bearer_token();
		if ( '' === $token ) {
			return $user_id;
		}

		if ( ! Saddle_Connection::targets_mcp_endpoint() ) {
			return $user_id;
		}

		// A bearer token in cleartext is a credential in cleartext. Same bar
		// core sets for Application Passwords, waived only for local dev.
		$readiness = Saddle_OAuth::readiness();
		if ( empty( $readiness['ssl'] ) ) {
			self::$failure = 'insecure_transport';
			return $user_id;
		}

		$record = Saddle_OAuth_Store::get_access_token( $token );
		if ( ! $record || empty( $record['user_id'] ) ) {
			self::$failure = 'invalid_token';
			return $user_id;
		}

		// RFC 8707 audience binding. Saddle is both authorization server and
		// resource server on the same site, so this rarely fires — but the
		// specification requires a resource server to reject a token that was
		// not issued for it, and a token minted for another site's Saddle must
		// not work here.
		if ( ! empty( $record['resource'] ) && Saddle_OAuth::resource_id() !== (string) $record['resource'] ) {
			self::$failure = 'invalid_token';
			return $user_id;
		}

		$grant = Saddle_OAuth_Store::get_grant( (string) $record['grant_id'] );
		if ( ! $grant ) {
			// The connection was revoked while this token was still inside its
			// hour. Revocation has to be immediate to mean anything.
			self::$failure = 'invalid_token';
			return $user_id;
		}

		self::$token_record = $record;
		self::$grant_scope  = isset( $grant['scope'] ) ? (string) $grant['scope'] : '';
		Saddle_OAuth_Store::touch_grant( (string) $record['grant_id'] );

		// The ceiling. Every ability's permission_callback reads the tier
		// through Saddle_Capabilities::get_tier(), which consults this filter,
		// so all 61 abilities inherit the clamp with no per-ability change.
		add_filter( 'saddle_tier_ceiling', array( __CLASS__, 'tier_ceiling' ) );

		return (int) $record['user_id'];
	}

	/**
	 * The tier ceiling implied by the current token's scope.
	 *
	 * The lower of the token's own scope and the scope on its grant right now.
	 * They are normally the same string; they differ for the minutes or hours
	 * after an administrator changes a connection's access level, and taking the
	 * lower of the two is what makes *lowering* it immediate.
	 *
	 * @param string|null $ceiling Existing ceiling, if another filter set one.
	 * @return string|null
	 */
	public static function tier_ceiling( $ceiling ) {
		if ( null === self::$token_record ) {
			return $ceiling;
		}

		$scoped = Saddle_OAuth::scope_to_tier( (string) self::$token_record['scope'] );

		if ( '' !== self::$grant_scope ) {
			$granted = Saddle_OAuth::scope_to_tier( self::$grant_scope );
			if ( Saddle_Capabilities::rank( $granted ) < Saddle_Capabilities::rank( $scoped ) ) {
				$scoped = $granted;
			}
		}

		// Never raise an existing ceiling — a ceiling is a maximum, and two of
		// them means the lower one applies.
		if ( null !== $ceiling && Saddle_Capabilities::rank( $ceiling ) < Saddle_Capabilities::rank( $scoped ) ) {
			return $ceiling;
		}

		return $scoped;
	}

	/**
	 * Belt-and-braces confinement for a token-authenticated request.
	 *
	 * {@see self::resolve()} never authenticates a token off the MCP endpoint, so
	 * this should be unreachable. It exists because "should be unreachable" is
	 * not the same as "is", and the cost of being wrong here is an OAuth token
	 * reaching Saddle's own control plane — where it could raise its own tier.
	 *
	 * Hooked one priority ahead of {@see Saddle_Connection::scope_credentials()}
	 * so a strayed bearer token gets told to reconnect the app, rather than
	 * getting the message about rotating an Application Password.
	 *
	 * @param mixed           $response Current response, if something decided already.
	 * @param array           $handler  Matched route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed
	 */
	public static function confine( $response, $handler, $request ) {
		if ( null !== $response || null === self::$token_record ) {
			return $response;
		}

		$allowed = '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE;
		if ( 0 === strpos( (string) $request->get_route(), $allowed ) ) {
			return $response;
		}

		return new WP_Error(
			'saddle_oauth_scope',
			__( 'This app signed in through Saddle and can only reach Saddle’s tools — the rest of the WordPress API is off limits to it by design.', 'saddle' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Attach the OAuth challenge to a refused MCP request.
	 *
	 * `rest_post_dispatch` is the only hook that sees both failure shapes: a 401
	 * produced by core's authentication filters (a revoked Application Password,
	 * or {@see Saddle_Connection::explain_auth_error()}'s relabel of it) and a
	 * 401/403 produced by the route's own permission callback. Both are
	 * converted to a response before this filter runs, and the headers set here
	 * are flushed immediately after — which is why the challenge cannot simply
	 * be attached to the WP_Error itself.
	 *
	 * Two deliberate restraints:
	 *
	 *   - Bearer only. A `Basic` challenge would make browsers pop a credential
	 *     dialog on a URL people do sometimes open by hand.
	 *   - Suppressed when the caller presented Basic credentials that were
	 *     refused. That is a revoked or mistyped Application Password, not an
	 *     invitation to start an OAuth flow — so Claude Code, Cursor and VS Code
	 *     users keep the legible "your key was rejected, reconnect the app"
	 *     error they have today instead of being pushed into a consent screen
	 *     they never asked for.
	 *
	 * @param WP_REST_Response $response Response about to be served.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  The request.
	 * @return WP_REST_Response
	 */
	public static function challenge( $response, $server, $request ) {
		if ( ! Saddle_OAuth::is_enabled() || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$route = '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE;
		if ( 0 !== strpos( (string) $request->get_route(), $route ) ) {
			return $response;
		}

		$status = (int) $response->get_status();
		if ( 401 !== $status && 403 !== $status ) {
			return $response;
		}

		if ( 'basic' === Saddle_Connection::credential_scheme() ) {
			return $response;
		}

		$params = array();

		if ( 403 === $status ) {
			$params['error']             = 'insufficient_scope';
			$params['error_description'] = 'The access token does not carry a Saddle scope.';
		} elseif ( '' !== self::$failure ) {
			$params['error']             = 'invalid_token';
			$params['error_description'] = 'insecure_transport' === self::$failure
				? 'Access tokens are only accepted over HTTPS.'
				: 'The access token is invalid, expired, or has been revoked.';
		}

		$params['resource_metadata'] = Saddle_OAuth_Discovery::protected_resource_url();

		// What this site would actually grant, not a constant. Naming the read
		// scope here told every spec-following client to ask for read-only even
		// on a site the owner had set to write or admin, and the answer to "why
		// can't my connected app edit anything" started right here.
		$params['scope'] = Saddle_OAuth::site_scope();

		$parts = array();
		foreach ( $params as $key => $value ) {
			// No caller input reaches these values, but strip the characters
			// that could split a header regardless — this is generated output on
			// an authentication path, and cheap to make structurally safe.
			$parts[] = sprintf( '%s="%s"', $key, str_replace( array( '"', "\r", "\n" ), '', (string) $value ) );
		}

		$response->header( 'WWW-Authenticate', 'Bearer ' . implode( ', ', $parts ) );

		return $response;
	}

	/**
	 * Let browser-based MCP clients read the challenge.
	 *
	 * Without this the header is present on the wire but invisible to fetch(),
	 * so a browser-hosted client can never discover where to authorize.
	 *
	 * @param string[] $headers Exposed header names.
	 * @return string[]
	 */
	public static function expose_cors_headers( $headers ) {
		$headers   = is_array( $headers ) ? $headers : array();
		$headers[] = 'WWW-Authenticate';

		return array_values( array_unique( $headers ) );
	}

	/**
	 * Revoke every grant belonging to a deleted user.
	 *
	 * @param int $user_id User being deleted.
	 */
	public static function revoke_user_grants( $user_id ) {
		foreach ( Saddle_OAuth_Store::list_grants() as $grant ) {
			if ( (int) $grant['user_id'] === (int) $user_id ) {
				Saddle_OAuth_Store::revoke_grant( (string) $grant['grant_id'] );
			}
		}
	}

	/**
	 * Revoke a user's grants when their role no longer clears the bar for
	 * authorizing one in the first place.
	 *
	 * Demoting someone should take away what the demotion was meant to take
	 * away. Leaving a live token that still acts as them would make the role
	 * change cosmetic.
	 *
	 * @param int    $user_id   User whose role changed.
	 * @param string $role      New role.
	 * @param array  $old_roles Previous roles.
	 */
	public static function reassess_user_grants( $user_id, $role, $old_roles ) {
		unset( $role, $old_roles );

		if ( user_can( (int) $user_id, Saddle_OAuth::authorize_capability() ) ) {
			return;
		}

		self::revoke_user_grants( $user_id );
	}

	/**
	 * The bearer token on the current request, if any.
	 *
	 * Reuses {@see Saddle_Connection::authorization_header()}, which already
	 * knows every place a mangled `Authorization` header can hide on a shared
	 * host. Worth noting that header stripping breaks bearer exactly as it
	 * breaks Basic, so the existing connection self-check and its `.htaccess`
	 * fix are load-bearing for OAuth too.
	 *
	 * @return string Raw token, or '' when none was sent.
	 */
	private static function bearer_token() {
		$header = Saddle_Connection::authorization_header();

		if ( '' === $header || 0 !== stripos( $header, 'bearer ' ) ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}
}
