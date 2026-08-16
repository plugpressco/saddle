<?php
/**
 * OAuth protocol endpoints — authorize, token, revoke.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The three public endpoints an OAuth 2.1 authorization server has to expose.
 *
 * All three are unauthenticated, and necessarily so — a client arriving here has
 * no credentials yet, which is the entire reason it is here. What protects them
 * is not a permission callback but the protocol itself: an authorization code is
 * useless without the PKCE verifier that produced its challenge, a token request
 * is useless without a code, and a code only exists because an administrator
 * looked at a consent screen and pressed Allow.
 */
class Saddle_OAuth_Endpoints {

	/**
	 * Hourly ceilings on unauthenticated `/authorize` attempts.
	 *
	 * Generous — a real person approving an app makes a handful of these, so
	 * these limits are invisible in normal use and only bite automation.
	 */
	const AUTHORIZE_PER_IP = 30;
	const AUTHORIZE_GLOBAL = 300;

	/**
	 * Register the protocol routes.
	 */
	public static function register_routes() {
		$prefix = Saddle_OAuth::ROUTE_PREFIX;

		register_rest_route(
			Saddle_MCP::REST_NAMESPACE,
			$prefix . '/authorize',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'authorize' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Saddle_MCP::REST_NAMESPACE,
			$prefix . '/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Saddle_MCP::REST_NAMESPACE,
			$prefix . '/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'revoke' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The authorization endpoint.
	 *
	 * Validates everything the client sent, parks it server-side, and redirects
	 * to the consent screen carrying nothing but an opaque request id. That
	 * indirection matters: no client-supplied string ever reaches the wp-admin
	 * URL, so nothing has to survive being escaped through the
	 * `wp-login.php?redirect_to=` round trip, and the consent screen has no
	 * reflected-parameter surface at all.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function authorize( WP_REST_Request $request ) {
		// Throttled before anything expensive happens. Unthrottled, a single
		// registered client_id could drive two kinds of amplification from an
		// unauthenticated endpoint: every accepted request writes a parked
		// request record (post + ~10 postmeta) that the hourly GC only sweeps
		// 200 at a time, and resolving a client that identifies itself by URL
		// triggers an outbound fetch. Both are individually harmless — the GC
		// catches up, the fetch is SSRF-guarded — but neither should be
		// repeatable without bound by anonymous callers.
		if ( self::authorize_throttled() ) {
			return self::fatal(
				__( 'Too many sign-in attempts have been made to this site in the last hour. Wait a little and try again.', 'saddle' )
			);
		}
		self::record_authorize_attempt();

		$client_id    = (string) $request->get_param( 'client_id' );
		$redirect_uri = (string) $request->get_param( 'redirect_uri' );

		$client = Saddle_OAuth_Clients::resolve( $client_id );

		// RFC 6749 §4.1.2.1: when the client or its redirect URI cannot be
		// trusted, the error MUST NOT be redirected — that would make this
		// endpoint an open redirector that anyone can aim anywhere. Render it
		// here instead.
		if ( is_wp_error( $client ) ) {
			return self::fatal( $client->get_error_message() );
		}

		$registered = isset( $client['redirect_uris'] ) ? (array) $client['redirect_uris'] : array();

		// Exact string comparison, deliberately. Prefix matching on redirect URIs
		// is the single most common way authorization codes get stolen.
		if ( '' === $redirect_uri || ! in_array( $redirect_uri, $registered, true ) ) {
			return self::fatal(
				__( 'That app asked to be sent back to an address it has not registered with this site. Nothing has been authorized.', 'saddle' )
			);
		}

		$state = (string) $request->get_param( 'state' );

		if ( 'code' !== (string) $request->get_param( 'response_type' ) ) {
			return self::bounce( $redirect_uri, 'unsupported_response_type', __( 'Only the authorization code flow is supported.', 'saddle' ), $state );
		}

		$challenge = (string) $request->get_param( 'code_challenge' );
		$method    = (string) $request->get_param( 'code_challenge_method' );

		// PKCE is mandatory and S256 only. `plain` offers no protection against
		// an intercepted authorization code, and OAuth 2.1 removes it.
		if ( '' === $challenge || 'S256' !== $method ) {
			return self::bounce( $redirect_uri, 'invalid_request', __( 'This server requires PKCE with the S256 challenge method.', 'saddle' ), $state );
		}

		$resource = (string) $request->get_param( 'resource' );
		if ( '' !== $resource && untrailingslashit( $resource ) !== Saddle_OAuth::resource_id() ) {
			return self::bounce( $redirect_uri, 'invalid_target', __( 'The requested resource is not served by this site.', 'saddle' ), $state );
		}

		$request_id = Saddle_OAuth::random_secret( 16 );
		if ( is_wp_error( $request_id ) ) {
			return self::bounce( $redirect_uri, 'server_error', $request_id->get_error_message(), $state );
		}

		$asked = trim( (string) $request->get_param( 'scope' ) );

		// A client that named its scopes is taken at its word — widening a
		// deliberate `saddle:read` request would be both a spec violation and a
		// safety regression, and some clients compare the scope they get back
		// against the one they asked for.
		//
		// A client that asked for nothing has expressed no preference, and until
		// now inherited read forever with no screen anywhere able to raise it.
		// ChatGPT is exactly that client: it registers dynamically and starts the
		// flow with no `scope` parameter, which is why its connections could only
		// ever be read-only. It now arrives at consent proposing the site's own
		// level — a proposal, still one explicit click away from being granted,
		// and still clamped to the site tier when it is.
		$scope = '' === $asked
			? Saddle_OAuth::normalize_scope( '', Saddle_OAuth::site_scope() )
			: Saddle_OAuth::normalize_scope( $asked );

		$saved = Saddle_OAuth_Store::save_request(
			$request_id,
			array(
				'client_id'      => (string) $client['client_id'],
				'client_name'    => isset( $client['client_name'] ) ? (string) $client['client_name'] : '',
				'client_source'  => isset( $client['client_source'] ) ? (string) $client['client_source'] : 'dcr',
				'logo_uri'       => isset( $client['logo_uri'] ) ? (string) $client['logo_uri'] : '',
				'redirect_uri'   => $redirect_uri,
				'state'          => $state,
				'scope'          => $scope,
				'code_challenge' => $challenge,
				'resource'       => '' === $resource ? Saddle_OAuth::resource_id() : untrailingslashit( $resource ),
			)
		);

		if ( is_wp_error( $saved ) ) {
			return self::bounce( $redirect_uri, 'server_error', $saved->get_error_message(), $state );
		}

		$consent = add_query_arg(
			array(
				'page'       => Saddle_OAuth::AUTHORIZE_PAGE,
				'saddle_req' => $request_id,
			),
			admin_url( 'admin.php' )
		);

		return self::redirect( $consent );
	}

	/**
	 * The token endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function token( WP_REST_Request $request ) {
		$params = self::form_params( $request );
		$grant  = isset( $params['grant_type'] ) ? (string) $params['grant_type'] : '';

		switch ( $grant ) {
			case 'authorization_code':
				return self::grant_authorization_code( $params );

			case 'refresh_token':
				return self::grant_refresh_token( $params );

			default:
				return Saddle_OAuth::error_response(
					'unsupported_grant_type',
					__( 'This server issues tokens for the authorization code and refresh token grants only.', 'saddle' )
				);
		}
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param array $params Request parameters.
	 * @return WP_REST_Response
	 */
	private static function grant_authorization_code( array $params ) {
		$code = isset( $params['code'] ) ? (string) $params['code'] : '';
		if ( '' === $code ) {
			return Saddle_OAuth::error_response( 'invalid_request', __( 'No authorization code was supplied.', 'saddle' ) );
		}

		// Consuming also detects replay, and revokes the whole grant family when
		// it finds one — two parties holding one code means one of them stole it.
		$record = Saddle_OAuth_Store::consume_code( $code );
		if ( ! $record ) {
			return Saddle_OAuth::error_response( 'invalid_grant', __( 'That authorization code is invalid, expired, or has already been used.', 'saddle' ) );
		}

		$client_id = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		if ( $client_id !== (string) $record['client_id'] ) {
			return Saddle_OAuth::error_response( 'invalid_grant', __( 'That authorization code was issued to a different app.', 'saddle' ) );
		}

		$redirect_uri = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		if ( $redirect_uri !== (string) $record['redirect_uri'] ) {
			return Saddle_OAuth::error_response( 'invalid_grant', __( 'The redirect address does not match the one used to authorize.', 'saddle' ) );
		}

		$verifier = isset( $params['code_verifier'] ) ? (string) $params['code_verifier'] : '';
		if ( ! self::verify_pkce( $verifier, (string) $record['code_challenge'] ) ) {
			return Saddle_OAuth::error_response( 'invalid_grant', __( 'The PKCE code verifier does not match the challenge sent when authorizing.', 'saddle' ) );
		}

		if ( isset( $params['resource'] ) && '' !== $params['resource']
			&& untrailingslashit( (string) $params['resource'] ) !== (string) $record['resource'] ) {
			return Saddle_OAuth::error_response( 'invalid_target', __( 'The requested resource does not match the one authorized.', 'saddle' ) );
		}

		return self::issue_tokens( (string) $record['grant_id'], (string) $record['scope'] );
	}

	/**
	 * Exchange a refresh token for a fresh pair.
	 *
	 * @param array $params Request parameters.
	 * @return WP_REST_Response
	 */
	private static function grant_refresh_token( array $params ) {
		$token = isset( $params['refresh_token'] ) ? (string) $params['refresh_token'] : '';
		if ( '' === $token ) {
			return Saddle_OAuth::error_response( 'invalid_request', __( 'No refresh token was supplied.', 'saddle' ) );
		}

		$record = Saddle_OAuth_Store::consume_refresh_token( $token );
		if ( ! $record ) {
			return Saddle_OAuth::error_response( 'invalid_grant', __( 'That refresh token is invalid, expired, or has already been used.', 'saddle' ) );
		}

		$grant = Saddle_OAuth_Store::get_grant( (string) $record['grant_id'] );
		if ( ! $grant ) {
			return Saddle_OAuth::error_response( 'invalid_grant', __( 'That connection has been revoked. The app needs to be authorized again.', 'saddle' ) );
		}

		$client_id = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		if ( '' !== $client_id && $client_id !== (string) $grant['client_id'] ) {
			return Saddle_OAuth::error_response( 'invalid_grant', __( 'That refresh token was issued to a different app.', 'saddle' ) );
		}

		$scope = (string) $grant['scope'];

		// A refresh may narrow the scope but never widen it — otherwise a
		// read-only grant could quietly promote itself on renewal.
		if ( isset( $params['scope'] ) && '' !== $params['scope'] ) {
			$requested = Saddle_OAuth::normalize_scope( (string) $params['scope'] );
			if ( ! self::scope_is_subset( $requested, $scope ) ) {
				return Saddle_OAuth::error_response( 'invalid_scope', __( 'A refresh cannot ask for more access than was originally granted.', 'saddle' ) );
			}
			$scope = $requested;
		}

		return self::issue_tokens( (string) $record['grant_id'], $scope );
	}

	/**
	 * Mint and return an access/refresh pair for a grant.
	 *
	 * @param string $grant_id Grant identifier.
	 * @param string $scope    Scope to bind to the tokens.
	 * @return WP_REST_Response
	 */
	private static function issue_tokens( $grant_id, $scope ) {
		$grant = Saddle_OAuth_Store::get_grant( $grant_id );
		if ( ! $grant ) {
			return Saddle_OAuth::error_response( 'invalid_grant', __( 'That connection no longer exists.', 'saddle' ) );
		}

		$access  = Saddle_OAuth::random_secret( 32 );
		$refresh = Saddle_OAuth::random_secret( 32 );

		if ( is_wp_error( $access ) || is_wp_error( $refresh ) ) {
			return Saddle_OAuth::error_response( 'temporarily_unavailable', __( 'Could not generate a secure token on this server.', 'saddle' ), 503 );
		}

		$meta = array(
			'grant_id'  => (string) $grant_id,
			'client_id' => (string) $grant['client_id'],
			'user_id'   => (int) $grant['user_id'],
			'scope'     => (string) $scope,
			'resource'  => (string) $grant['resource'],
		);

		Saddle_OAuth_Store::save_token( 'access', $access, $meta );
		Saddle_OAuth_Store::save_token( 'refresh', $refresh, $meta );

		$response = new WP_REST_Response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => Saddle_OAuth_Store::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => (string) $scope,
			),
			200
		);

		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * The revocation endpoint (RFC 7009).
	 *
	 * Always answers 200, including for a token it has never seen — a revocation
	 * endpoint that distinguishes "revoked" from "unknown" is a token oracle.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function revoke( WP_REST_Request $request ) {
		$params = self::form_params( $request );
		$token  = isset( $params['token'] ) ? (string) $params['token'] : '';

		if ( '' !== $token ) {
			$record = Saddle_OAuth_Store::get_access_token( $token );
			if ( ! $record ) {
				$record = Saddle_OAuth_Store::get( 'refresh', $token );
			}

			// Revoking any token of a connection revokes the connection. RFC 7009
			// permits this, and it is what a person means when they click Revoke.
			if ( $record && ! empty( $record['grant_id'] ) ) {
				Saddle_OAuth_Store::revoke_grant( (string) $record['grant_id'] );
			}
		}

		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Verify an RFC 7636 S256 code verifier against a stored challenge.
	 *
	 * The length and charset check is not decoration: it pins the entropy floor,
	 * so a degenerate one-character verifier cannot be brute-forced against a
	 * captured challenge.
	 *
	 * @param string $verifier  Verifier from the token request.
	 * @param string $challenge Challenge stored with the authorization code.
	 * @return bool
	 */
	private static function verify_pkce( $verifier, $challenge ) {
		$length = strlen( $verifier );

		if ( $length < 43 || $length > 128 || ! preg_match( '/^[A-Za-z0-9\-._~]+$/', $verifier ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url is the encoding RFC 7636 specifies for a PKCE challenge; there is nothing to obfuscate.
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		return hash_equals( (string) $challenge, $computed );
	}

	/**
	 * Whether every scope in $subset also appears in $superset.
	 *
	 * @param string $subset   Candidate scope string.
	 * @param string $superset Granted scope string.
	 * @return bool
	 */
	private static function scope_is_subset( $subset, $superset ) {
		$have = preg_split( '/\s+/', trim( $superset ), -1, PREG_SPLIT_NO_EMPTY );
		$want = preg_split( '/\s+/', trim( $subset ), -1, PREG_SPLIT_NO_EMPTY );

		return empty( array_diff( (array) $want, (array) $have ) );
	}

	/**
	 * Read token-endpoint parameters from either encoding.
	 *
	 * The spec says form-encoded, and that is what most clients send; a few send
	 * JSON. Accepting both costs nothing and avoids a class of opaque
	 * "invalid_request" reports.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array
	 */
	private static function form_params( WP_REST_Request $request ) {
		$body = $request->get_body_params();
		if ( is_array( $body ) && ! empty( $body ) ) {
			return $body;
		}

		$json = $request->get_json_params();

		return is_array( $json ) ? $json : array();
	}

	/**
	 * A 302 response.
	 *
	 * @param string $url Destination.
	 * @return WP_REST_Response
	 */
	private static function redirect( $url ) {
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Redirect an error back to the client, per RFC 6749 §4.1.2.1.
	 *
	 * Safe to redirect here — and only here — because this is reached solely
	 * after $redirect_uri matched one the client registered.
	 *
	 * @param string $redirect_uri Verified redirect URI.
	 * @param string $error        OAuth error code.
	 * @param string $description  Human-readable detail.
	 * @param string $state        Client state to echo back.
	 * @return WP_REST_Response
	 */
	private static function bounce( $redirect_uri, $error, $description, $state ) {
		$args = array(
			'error'             => $error,
			'error_description' => $description,
			// RFC 9207: naming ourselves in the response lets the client detect a
			// mix-up attack, where a response from one server is replayed at
			// another the client also talks to.
			'iss'               => Saddle_OAuth::issuer(),
		);

		if ( '' !== $state ) {
			$args['state'] = $state;
		}

		return self::redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) );
	}

	/**
	 * Whether `/authorize` should refuse right now.
	 *
	 * Deliberately parallel to — not shared with —
	 * {@see Saddle_OAuth_Clients}'s registration throttle. That one is private,
	 * keyed for registration, and covered by its own tests; generalising it to
	 * serve two callers would mean editing a tested security path to add an
	 * unrelated one. Two small counters are cheaper to reason about than one
	 * parameterised one.
	 *
	 * Two hourly windows: per source address so a single actor can't flood, and
	 * site-wide so a distributed one can't either.
	 *
	 * @return bool
	 */
	private static function authorize_throttled() {
		$window = (int) floor( time() / HOUR_IN_SECONDS );

		if ( (int) get_transient( 'saddle_oauth_authz_all_' . $window ) >= self::AUTHORIZE_GLOBAL ) {
			return true;
		}

		return (int) get_transient( self::authorize_ip_key( $window ) ) >= self::AUTHORIZE_PER_IP;
	}

	/**
	 * Count one attempt against both windows.
	 */
	private static function record_authorize_attempt() {
		$window = (int) floor( time() / HOUR_IN_SECONDS );

		$global = 'saddle_oauth_authz_all_' . $window;
		set_transient( $global, (int) get_transient( $global ) + 1, HOUR_IN_SECONDS );

		$per_ip = self::authorize_ip_key( $window );
		set_transient( $per_ip, (int) get_transient( $per_ip ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Transient key for this caller's hourly attempt count.
	 *
	 * `REMOTE_ADDR` only, for the same reason registration does it: an
	 * `X-Forwarded-For` header is caller-controlled, so honouring it by default
	 * would make the per-IP limit trivially bypassable.
	 *
	 * @param int $window Hour bucket.
	 * @return string
	 */
	private static function authorize_ip_key( $window ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'saddle_oauth_authz_' . md5( $ip ) . '_' . $window;
	}

	/**
	 * Render a terminal error that must not be redirected.
	 *
	 * A person is looking at this in a browser — they clicked "connect" in some
	 * app and landed here — so it has to be a readable page, not the JSON blob a
	 * value returned from a REST callback would become. `wp_die()` gives that,
	 * and gives it in the site's own styling.
	 *
	 * @param string $message Explanation for the person looking at the screen.
	 * @return void
	 */
	private static function fatal( $message ) {
		wp_die(
			esc_html( $message ) . '<p>' . esc_html__( 'Nothing on your site has changed. You can close this window.', 'saddle' ) . '</p>',
			esc_html__( 'Saddle did not authorize this app', 'saddle' ),
			array( 'response' => 400 )
		);
	}
}
