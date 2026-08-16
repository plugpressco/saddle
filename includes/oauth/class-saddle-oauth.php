<?php
/**
 * OAuth 2.1 authorization server — configuration, constants, and hook wiring.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Why this exists at all.
 *
 * Saddle's MCP endpoint authenticates with core Application Passwords over
 * `Authorization: Basic`. Every client that lets a person type an HTTP header —
 * Claude Code, Cursor, VS Code, Gemini CLI — connects that way, and that remains
 * the default path. ChatGPT's custom-connector screen has no header field: it
 * offers "no authentication", "API key", or "OAuth", and none of those can carry
 * a Basic credential. The MCP specification's answer is OAuth 2.1, so Saddle
 * speaks it.
 *
 * The authorization server runs INSIDE the owner's WordPress. There is no relay,
 * no PlugPress-hosted endpoint, and no third party in the flow — non-negotiable
 * #1 holds exactly as it did before. The only outbound request this subsystem
 * can make is fetching a Client ID Metadata Document from the URL a connecting
 * client presents as its own identity (see {@see Saddle_OAuth_Clients}).
 *
 * Default-safe, per non-negotiable #2: the whole subsystem is OFF until the
 * owner turns it on. While off, discovery documents 404 and the token and
 * registration endpoints refuse — a site that never connects ChatGPT never
 * exposes an OAuth surface at all.
 */
class Saddle_OAuth {

	/**
	 * Option key for the owner's enable switch. Absent/false = off.
	 */
	const ENABLED_OPTION = 'saddle_oauth_enabled';

	/**
	 * REST route prefix (within Saddle's own `saddle/v1` namespace) under which
	 * every OAuth endpoint and discovery mirror lives.
	 */
	const ROUTE_PREFIX = '/oauth';

	/**
	 * Scope names, weakest first. These map 1:1 onto Saddle's access tiers, so a
	 * token can only ever narrow what the site already allows — never widen it.
	 */
	const SCOPES = array( 'saddle:read', 'saddle:write', 'saddle:admin' );

	/**
	 * The scope challenged on an unauthenticated request. Least privilege: a
	 * client that knows nothing else asks for read, and steps up if it needs to.
	 */
	const DEFAULT_SCOPE = 'saddle:read';

	/**
	 * Admin page slug for the consent screen.
	 */
	const AUTHORIZE_PAGE = 'saddle-authorize';

	/**
	 * Wire the subsystem. Called from `Saddle::init()`.
	 *
	 * The bearer resolver and the 401 challenge are registered unconditionally
	 * and check {@see self::is_enabled()} themselves, so switching OAuth off
	 * stops live tokens working on the very next request rather than at the next
	 * page load. Everything that publishes a public surface — discovery, token,
	 * registration, the consent screen — is only registered when OAuth is on, so
	 * a site that never enables it never exposes any of it.
	 */
	public static function register() {
		// Priority 30: after core's cookie resolver (10) and its Application
		// Password resolver (20), so an existing session or a working Basic
		// credential always wins and this only ever fills a vacuum. Nothing about
		// the legacy path changes.
		add_filter( 'determine_current_user', array( 'Saddle_OAuth_Bearer', 'resolve' ), 30 );

		// Priority 9 — one ahead of Saddle_Connection::scope_credentials(), so a
		// bearer token that strayed off the MCP endpoint gets the message about
		// reconnecting the app rather than the one about rotating a key.
		add_filter( 'rest_request_before_callbacks', array( 'Saddle_OAuth_Bearer', 'confine' ), 9, 3 );
		add_filter( 'rest_post_dispatch', array( 'Saddle_OAuth_Bearer', 'challenge' ), 10, 3 );
		add_filter( 'rest_exposed_cors_headers', array( 'Saddle_OAuth_Bearer', 'expose_cors_headers' ) );

		// Revoking access must follow the account: a deleted user, or one demoted
		// below the bar for authorizing in the first place, should not leave live
		// tokens acting as them.
		add_action( 'deleted_user', array( 'Saddle_OAuth_Bearer', 'revoke_user_grants' ) );
		add_action( 'set_user_role', array( 'Saddle_OAuth_Bearer', 'reassess_user_grants' ), 10, 3 );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'parse_request', array( 'Saddle_OAuth_Discovery', 'maybe_serve' ), 0 );
		add_action( 'rest_api_init', array( 'Saddle_OAuth_Discovery', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'Saddle_OAuth_Endpoints', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'Saddle_OAuth_Clients', 'register_routes' ) );
		// Priority 11 so Saddle's own top-level menu (registered at 10) exists to
		// hang the hidden consent page off.
		add_action( 'admin_menu', array( 'Saddle_OAuth_Consent', 'register_page' ), 11 );
		add_action( 'admin_post_' . Saddle_OAuth_Consent::ACTION, array( 'Saddle_OAuth_Consent', 'handle' ) );
	}

	/**
	 * Whether this install can host an OAuth authorization server at all.
	 *
	 * Two hard requirements, both structural rather than preferences:
	 *
	 *   - **Pretty permalinks.** Without them `rest_url()` returns
	 *     `…/index.php?rest_route=/saddle/v1/mcp`. A URL carrying a query string
	 *     cannot be an RFC 8707 canonical resource identifier, and the issuer's
	 *     `/.well-known/openid-configuration` fallback stops existing as a path.
	 *   - **HTTPS.** A bearer token in cleartext is a credential in cleartext.
	 *     This is the same bar core sets for Application Passwords, and it is
	 *     waived only for a local development environment.
	 *
	 * @return array {
	 *     @type bool $ready      Whether OAuth can be enabled.
	 *     @type bool $permalinks Whether pretty permalinks are on.
	 *     @type bool $ssl        Whether the transport requirement is satisfied.
	 * }
	 */
	public static function readiness() {
		$permalinks = '' !== (string) get_option( 'permalink_structure' );

		/**
		 * Filter whether the transport is secure enough to carry bearer tokens.
		 *
		 * Overriding this on a site genuinely served over plain HTTP hands out
		 * credentials in cleartext. It exists for environments that terminate TLS
		 * somewhere WordPress cannot see — and for the test suite.
		 *
		 * @param bool $ssl Whether the transport requirement is satisfied.
		 */
		$ssl = (bool) apply_filters(
			'saddle_oauth_readiness_ssl',
			is_ssl() || 0 === strpos( (string) home_url(), 'https://' ) || 'local' === wp_get_environment_type()
		);

		return array(
			'ready'      => $permalinks && $ssl,
			'permalinks' => $permalinks,
			'ssl'        => $ssl,
		);
	}

	/**
	 * Whether the owner has enabled the OAuth authorization server.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Filter whether Saddle's OAuth authorization server is active.
		 *
		 * @param bool $enabled Whether OAuth is enabled. Default false.
		 */
		return (bool) apply_filters( 'saddle_oauth_enabled', (bool) get_option( self::ENABLED_OPTION, false ) );
	}

	/**
	 * Turn the authorization server on or off.
	 *
	 * @param bool $enabled Desired state.
	 * @return bool True when the option was persisted.
	 */
	public static function set_enabled( $enabled ) {
		return update_option( self::ENABLED_OPTION, (bool) $enabled ? 1 : 0 );
	}

	/**
	 * The canonical URI of the protected resource — Saddle's MCP endpoint.
	 *
	 * This is the value clients must send as RFC 8707 `resource` and the audience
	 * every access token is bound to. Normalized without a trailing slash, per the
	 * MCP specification's canonical-URI guidance.
	 *
	 * @return string
	 */
	public static function resource_id() {
		return untrailingslashit( rest_url( ltrim( Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE, '/' ) ) );
	}

	/**
	 * The authorization server's issuer identifier.
	 *
	 * Deliberately path-bearing (`…/wp-json/saddle/v1/oauth`). A client
	 * discovering metadata for an issuer with a path component is required to try
	 * `<issuer>/.well-known/openid-configuration` as its last fallback — which is
	 * an ordinary REST route, and therefore reachable on every install regardless
	 * of permalink settings or whether WordPress owns the host root. The root
	 * `.well-known` documents are served too (see {@see Saddle_OAuth_Discovery}),
	 * but this guarantees discovery never depends on them alone.
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( rest_url( ltrim( Saddle_MCP::REST_NAMESPACE . self::ROUTE_PREFIX, '/' ) ) );
	}

	/**
	 * Absolute URL of an OAuth REST endpoint.
	 *
	 * @param string $path Path below the OAuth prefix, e.g. 'token'.
	 * @return string
	 */
	public static function endpoint( $path ) {
		return rest_url( ltrim( Saddle_MCP::REST_NAMESPACE . self::ROUTE_PREFIX, '/' ) . '/' . ltrim( (string) $path, '/' ) );
	}

	/**
	 * The consent screen URL advertised as `authorization_endpoint`.
	 *
	 * Hosted in wp-admin so WordPress supplies the login redirect, the capability
	 * check, the nonce, and the chrome — the same reasoning behind core's own
	 * `wp-admin/authorize-application.php`. The URL carries a query component,
	 * which RFC 6749 §3.1 explicitly permits (clients append with `&`); the filter
	 * is the escape hatch if a client ever mishandles that.
	 *
	 * @return string
	 */
	public static function authorization_endpoint() {
		/**
		 * Filter the OAuth authorization (consent screen) endpoint.
		 *
		 * @param string $url Absolute URL of the consent screen.
		 */
		return (string) apply_filters(
			'saddle_oauth_authorization_endpoint',
			admin_url( 'admin.php?page=' . self::AUTHORIZE_PAGE )
		);
	}

	/**
	 * The WordPress capability required to approve an OAuth authorization.
	 *
	 * Administrators only by default — the same bar as every other Saddle
	 * control-plane route, and as the connect wizard's credential issuer. The
	 * resulting token acts as that user and is still clamped by the site tier and
	 * the granted scope.
	 *
	 * @return string
	 */
	public static function authorize_capability() {
		/**
		 * Filter the capability required to complete the OAuth consent screen.
		 *
		 * Widen deliberately: whoever holds this can hand an AI agent a token that
		 * acts as themselves.
		 *
		 * @param string $capability Capability name. Default 'manage_options'.
		 */
		return (string) apply_filters( 'saddle_oauth_authorize_capability', 'manage_options' );
	}

	/**
	 * Normalize a requested scope string to the scopes Saddle actually grants.
	 *
	 * Unknown scope tokens are dropped rather than rejected: a general-purpose MCP
	 * client may ask for extras it learned elsewhere, and failing the whole
	 * authorization over one unrecognized word is worse UX than granting the
	 * intersection.
	 *
	 * When the intersection is empty the caller decides what that means, through
	 * `$fallback`. Two different situations reach this line and they do not
	 * deserve the same answer: a client that asked for nothing at all has
	 * expressed no preference, while one that asked only for scopes we do not
	 * recognize has. The authorize endpoint passes {@see self::site_scope()} for
	 * the first case — ChatGPT registers dynamically and starts the flow with no
	 * `scope` parameter, so read-only-forever was the only outcome it could ever
	 * reach. Everything else keeps the least-privilege default.
	 *
	 * @param string      $requested Space-delimited scope string.
	 * @param string|null $fallback  Scope to grant when nothing recognized was asked for.
	 *                               Defaults to {@see self::DEFAULT_SCOPE}.
	 * @return string Space-delimited, deduplicated, ordered scope string.
	 */
	public static function normalize_scope( $requested, $fallback = null ) {
		$asked = preg_split( '/\s+/', trim( (string) $requested ), -1, PREG_SPLIT_NO_EMPTY );
		$asked = is_array( $asked ) ? $asked : array();

		$granted = array();
		foreach ( self::SCOPES as $scope ) {
			if ( in_array( $scope, $asked, true ) ) {
				$granted[] = $scope;
			}
		}

		if ( empty( $granted ) ) {
			// A fallback is a suggestion, not an escape hatch: run it back through
			// the same intersection (with no fallback of its own, so this can
			// recurse exactly once) so nothing can introduce a scope Saddle does
			// not grant.
			if ( null !== $fallback ) {
				return self::normalize_scope( (string) $fallback );
			}

			return self::DEFAULT_SCOPE;
		}

		return implode( ' ', $granted );
	}

	/**
	 * The scope string that carries a given Saddle tier.
	 *
	 * Cumulative, because the tiers are: `write` includes reading, `admin`
	 * includes both. A client reading the string back gets the whole set rather
	 * than having to know Saddle's ordering.
	 *
	 * @param string $tier One of 'read'|'write'|'admin'.
	 * @return string Space-delimited scope string.
	 */
	public static function tier_to_scope( $tier ) {
		switch ( (string) $tier ) {
			case 'admin':
				return implode( ' ', self::SCOPES );
			case 'write':
				return 'saddle:read saddle:write';
			case 'read':
			default:
				return self::DEFAULT_SCOPE;
		}
	}

	/**
	 * The scope matching what the owner has actually enabled on this site.
	 *
	 * Deliberately the *site* tier and not {@see Saddle_Capabilities::get_tier()}:
	 * this answers "what could a new connection be granted here", which is a
	 * question about configuration, not about whoever is holding a credential
	 * right now. It is a ceiling on what may be granted, never a grant in itself
	 * — the consent screen still has to be clicked.
	 *
	 * @return string Space-delimited scope string.
	 */
	public static function site_scope() {
		if ( ! class_exists( 'Saddle_Capabilities' ) ) {
			return self::DEFAULT_SCOPE;
		}

		return self::tier_to_scope( Saddle_Capabilities::get_site_tier() );
	}

	/**
	 * The highest Saddle tier a scope string authorizes.
	 *
	 * @param string $scope Space-delimited scope string.
	 * @return string One of 'read'|'write'|'admin'.
	 */
	public static function scope_to_tier( $scope ) {
		$scopes = preg_split( '/\s+/', trim( (string) $scope ), -1, PREG_SPLIT_NO_EMPTY );
		$scopes = is_array( $scopes ) ? $scopes : array();

		if ( in_array( 'saddle:admin', $scopes, true ) ) {
			return 'admin';
		}
		if ( in_array( 'saddle:write', $scopes, true ) ) {
			return 'write';
		}

		return 'read';
	}

	/**
	 * Plain-language label for a scope, for the consent screen.
	 *
	 * @param string $scope A single scope name.
	 * @return string
	 */
	public static function describe_scope( $scope ) {
		switch ( $scope ) {
			case 'saddle:write':
				return __( 'Create and edit posts, pages, and media', 'saddle' );
			case 'saddle:admin':
				return __( 'Manage site settings, plugins, and themes', 'saddle' );
			case 'saddle:read':
			default:
				return __( 'Read posts, pages, media, and site information', 'saddle' );
		}
	}

	/**
	 * A URL-safe, high-entropy opaque secret (tokens, codes, client ids).
	 *
	 * @param int $bytes Entropy in bytes.
	 * @return string|WP_Error Hex string, or WP_Error when no CSPRNG is available.
	 */
	public static function random_secret( $bytes = 32 ) {
		try {
			return bin2hex( random_bytes( (int) $bytes ) );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'saddle_oauth_no_entropy',
				__( 'Could not generate a secure token on this server.', 'saddle' )
			);
		}
	}

	/**
	 * Constant-time comparison that tolerates non-string input.
	 *
	 * @param string $known    Expected value.
	 * @param string $supplied Value from the request.
	 * @return bool
	 */
	public static function secure_equals( $known, $supplied ) {
		return is_string( $known ) && is_string( $supplied ) && hash_equals( $known, $supplied );
	}

	/**
	 * Emit an OAuth error response in the RFC 6749 §5.2 shape.
	 *
	 * @param string $code        OAuth error code, e.g. 'invalid_grant'.
	 * @param string $description Human-readable detail.
	 * @param int    $status      HTTP status.
	 * @return WP_REST_Response
	 */
	public static function error_response( $code, $description, $status = 400 ) {
		$response = new WP_REST_Response(
			array(
				'error'             => (string) $code,
				'error_description' => (string) $description,
			),
			(int) $status
		);

		// RFC 6749 §5.2: token-endpoint errors must not be cached.
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		if ( 401 === (int) $status ) {
			$response->header( 'WWW-Authenticate', 'Bearer error="' . $code . '"' );
		}

		return $response;
	}
}
