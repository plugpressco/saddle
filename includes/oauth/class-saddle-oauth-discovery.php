<?php
/**
 * OAuth discovery documents — RFC 9728 and RFC 8414.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * How a client finds its way in.
 *
 * An MCP client that gets a 401 from Saddle's endpoint needs to learn two
 * things: where this resource's authorization server lives (RFC 9728 protected
 * resource metadata), and what that server can do (RFC 8414 authorization
 * server metadata). Both documents are public, static, and secret-free.
 *
 * Serving them from a WordPress plugin is the awkward part, because the
 * well-known URIs live at the HOST root and a plugin does not own the host root
 * on a subdirectory install. Saddle therefore publishes each document at two
 * addresses:
 *
 *   1. A REST route, which is always reachable. Because the issuer identifier
 *      deliberately carries a path (`…/wp-json/saddle/v1/oauth`), RFC 8414's
 *      third and final lookup form — `<issuer>/.well-known/openid-configuration`
 *      — lands on an ordinary REST route. That is not a workaround; it is the
 *      spec's own fallback, and it is why the issuer is shaped this way.
 *   2. The host-root well-known paths, intercepted on `parse_request`. This is
 *      the form clients try FIRST, so it is the fast path — but it is best
 *      effort. It cannot work on a subdirectory install, and it cannot work with
 *      plain permalinks, because the web server 404s the request before PHP ever
 *      sees it.
 *
 * On top of both, the 401 challenge carries `resource_metadata` pointing
 * straight at the REST route (see {@see Saddle_OAuth_Bearer::challenge()}), which
 * is the mechanism RFC 9728 §5.1 defines and the one that needs no root access
 * at all.
 */
class Saddle_OAuth_Discovery {

	/**
	 * Well-known suffix for protected resource metadata.
	 */
	const PRM_PATH = '/.well-known/oauth-protected-resource';

	/**
	 * Well-known suffix for authorization server metadata.
	 */
	const ASM_PATH = '/.well-known/oauth-authorization-server';

	/**
	 * OpenID Connect discovery suffix. MCP clients try this as an alternate for
	 * both of the RFC 8414 forms, so the same document answers to it.
	 */
	const OIDC_PATH = '/.well-known/openid-configuration';

	/**
	 * Seconds the loopback discovery probe waits before giving up.
	 */
	const PROBE_TIMEOUT = 5;

	/**
	 * Seconds a discovery fetch may take before it counts as too slow.
	 *
	 * Connecting clients budget only a few seconds for metadata fetches and
	 * report a site that misses it as not implementing OAuth at all, so a
	 * document that is correct but late fails exactly like one that is missing.
	 */
	const PROBE_BUDGET = 3.0;

	/**
	 * Register the REST-route copies of both documents.
	 *
	 * All public and cacheable — a discovery document tells an anonymous caller
	 * only what it must in order to start an authorization flow, and every one of
	 * those facts is already implied by the endpoint URL.
	 */
	public static function register_routes() {
		$ns     = Saddle_MCP::REST_NAMESPACE;
		$prefix = ltrim( Saddle_OAuth::ROUTE_PREFIX, '/' );

		$public_get = array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
		);

		// The RFC 8414 path-insertion fallback for a path-bearing issuer. This is
		// the one that keeps discovery working on a subdirectory install.
		register_rest_route(
			$ns,
			'/' . $prefix . '/\.well-known/openid-configuration',
			array_merge( $public_get, array( 'callback' => array( __CLASS__, 'serve_authorization_server' ) ) )
		);

		register_rest_route(
			$ns,
			'/' . $prefix . '/\.well-known/oauth-authorization-server',
			array_merge( $public_get, array( 'callback' => array( __CLASS__, 'serve_authorization_server' ) ) )
		);

		// Dot-free aliases. A minority of hosts block any path segment beginning
		// with a dot at the web-server layer, which would take out every
		// `.well-known` form at once — including the REST ones. These are what the
		// 401 challenge actually points at, so discovery survives that config.
		register_rest_route(
			$ns,
			'/' . $prefix . '/authorization-server',
			array_merge( $public_get, array( 'callback' => array( __CLASS__, 'serve_authorization_server' ) ) )
		);

		register_rest_route(
			$ns,
			'/' . $prefix . '/protected-resource',
			array_merge( $public_get, array( 'callback' => array( __CLASS__, 'serve_protected_resource' ) ) )
		);

		// Path-APPENDED forms under the MCP route itself. RFC 9728 defines
		// path-insertion at the host root, but an MCP client that derives its
		// discovery base from the MCP endpoint URL also probes
		// `<mcp-url>/.well-known/…` appended forms — and on a subdirectory
		// install (or with root interception blocked) these REST forms are the
		// only ones that resolve at all.
		$mcp = ltrim( Saddle_MCP::ROUTE, '/' );

		register_rest_route(
			$ns,
			'/' . $mcp . '/\.well-known/oauth-protected-resource',
			array_merge( $public_get, array( 'callback' => array( __CLASS__, 'serve_protected_resource' ) ) )
		);

		register_rest_route(
			$ns,
			'/' . $mcp . '/\.well-known/oauth-authorization-server',
			array_merge( $public_get, array( 'callback' => array( __CLASS__, 'serve_authorization_server' ) ) )
		);

		register_rest_route(
			$ns,
			'/' . $mcp . '/\.well-known/openid-configuration',
			array_merge( $public_get, array( 'callback' => array( __CLASS__, 'serve_authorization_server' ) ) )
		);
	}

	/**
	 * REST handler for the authorization server metadata document.
	 *
	 * @return WP_REST_Response
	 */
	public static function serve_authorization_server() {
		return self::rest_document( self::authorization_server_metadata() );
	}

	/**
	 * REST handler for the protected resource metadata document.
	 *
	 * @return WP_REST_Response
	 */
	public static function serve_protected_resource() {
		return self::rest_document( self::protected_resource_metadata() );
	}

	/**
	 * Wrap a document in a cacheable, CORS-open REST response.
	 *
	 * `Access-Control-Allow-Origin: *` without credentials is correct here and
	 * deliberately different from WordPress's default, which reflects the request
	 * origin and sets `Allow-Credentials: true`. A discovery document must be
	 * readable by a browser-based MCP client from any origin, and must never be
	 * fetched with the visitor's ambient cookie authority.
	 *
	 * @param array $document Document payload.
	 * @return WP_REST_Response
	 */
	private static function rest_document( array $document ) {
		$response = new WP_REST_Response( $document, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Serve a discovery document from the host root.
	 *
	 * Hooked to `parse_request` at priority 0 — before `WP::query_posts()`, so a
	 * static JSON document never triggers a post query, and before any theme or
	 * SEO plugin's 404 handling can claim the request.
	 *
	 * Only the two exact prefixes are claimed, and only with a suffix that names
	 * something Saddle actually serves. Everything else under `.well-known` —
	 * ACME challenges, `security.txt`, Apple's association files — passes
	 * through untouched.
	 */
	public static function maybe_serve() {
		if ( ! Saddle_OAuth::is_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing decision for a public, secret-free document; the value is normalized and compared against a fixed allow list in document_for(), never stored or echoed.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';

		$document = self::document_for( $uri );
		if ( 'protected-resource' === $document ) {
			self::send( self::protected_resource_metadata() );
		}
		if ( 'authorization-server' === $document ) {
			self::send( self::authorization_server_metadata() );
		}
	}

	/**
	 * Which document a request URI is asking for — the interceptor's whole
	 * match table, as a pure function so the suite drives the real code.
	 *
	 * @param string $uri Raw request URI (query string and encoding tolerated).
	 * @return string 'protected-resource' | 'authorization-server' | '' (not ours).
	 */
	public static function document_for( $uri ) {
		$path = self::normalize_path( $uri );
		if ( 0 !== strpos( $path, '/.well-known/' ) ) {
			return '';
		}

		$issuer_path = self::path_of( Saddle_OAuth::issuer() );
		$mcp_path    = self::path_of( Saddle_OAuth::resource_id() );

		// RFC 9728 permits the bare form and the form carrying the protected
		// resource's own path. Anything else is a request about a resource this
		// site does not serve, and gets a 404 rather than a misleading document.
		if ( self::PRM_PATH === $path || self::PRM_PATH . $mcp_path === $path ) {
			return 'protected-resource';
		}

		$as_forms = array(
			self::ASM_PATH,
			self::OIDC_PATH,
			self::ASM_PATH . $issuer_path,
			self::OIDC_PATH . $issuer_path,
			// The RESOURCE-path insertion forms. An MCP client that never
			// fetched PRM derives the authorization-server base from the MCP
			// endpoint URL itself and inserts THAT path (observed: ChatGPT's
			// connector cold-starting from the pasted MCP address). The served
			// document keeps the real issuer — a strictly-validating RFC 8414
			// client discards it, but it would have received a 404 here
			// otherwise, and spec-correct clients reach the issuer via PRM.
			self::ASM_PATH . $mcp_path,
			self::OIDC_PATH . $mcp_path,
		);

		return in_array( $path, $as_forms, true ) ? 'authorization-server' : '';
	}

	/**
	 * Normalize a request URI's path for exact comparison.
	 *
	 * Decoded, query stripped, repeated slashes collapsed, trailing slash
	 * removed — so an encoded or doubled variant cannot slip past the exact
	 * matches above and reach a different branch than it appears to.
	 *
	 * @param string $uri Raw request URI.
	 * @return string Leading-slash path.
	 */
	private static function normalize_path( $uri ) {
		if ( '' === $uri ) {
			return '';
		}

		// Strip the query by hand rather than via wp_parse_url(). A request URI
		// beginning with `//` is a valid protocol-relative URL, so the parser
		// would read the first segment as a host and hand back a path missing it
		// — which is exactly the sort of near-miss that turns an exact-match
		// allow list into a bypass. Collapse first, parse never.
		$path = strtok( $uri, '?' );
		$path = strtok( (string) $path, '#' );
		$path = rawurldecode( (string) $path );
		$path = '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );

		return '/' === $path ? '/' : untrailingslashit( $path );
	}

	/**
	 * The path component of an absolute URL, without a trailing slash.
	 *
	 * @param string $url Absolute URL.
	 * @return string Leading-slash path, or '' for a root URL.
	 */
	private static function path_of( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

		return '' === $path || '/' === $path ? '' : untrailingslashit( $path );
	}

	/**
	 * Emit a document and stop.
	 *
	 * @param array $document Document payload.
	 */
	private static function send( array $document ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );
			header( 'Access-Control-Allow-Origin: *' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		echo wp_json_encode( $document );
		exit;
	}

	/**
	 * The URL the 401 challenge points clients at for resource metadata.
	 *
	 * Deliberately the dot-free REST alias rather than a `.well-known` path: this
	 * value is handed to the client directly, so it should be the address least
	 * likely to be blocked by a host's server configuration.
	 *
	 * @return string
	 */
	public static function protected_resource_url() {
		return Saddle_OAuth::endpoint( 'protected-resource' );
	}

	/**
	 * RFC 9728 protected resource metadata.
	 *
	 * @return array
	 */
	public static function protected_resource_metadata() {
		$document = array(
			'resource'                 => Saddle_OAuth::resource_id(),
			'authorization_servers'    => array( Saddle_OAuth::issuer() ),
			'scopes_supported'         => Saddle_OAuth::SCOPES,
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => Saddle_MCP::server_name(),
			'resource_documentation'   => 'https://wordpress.org/plugins/saddle/',
		);

		/**
		 * Filter Saddle's protected resource metadata document.
		 *
		 * @param array $document RFC 9728 document.
		 */
		return (array) apply_filters( 'saddle_oauth_protected_resource_metadata', $document );
	}

	/**
	 * RFC 8414 authorization server metadata.
	 *
	 * `issuer` must byte-match the identifier the client used to build the
	 * request URL, or a conformant client discards the whole document.
	 *
	 * @return array
	 */
	public static function authorization_server_metadata() {
		$document = array(
			'issuer'                                     => Saddle_OAuth::issuer(),
			'authorization_endpoint'                     => Saddle_OAuth::endpoint( 'authorize' ),
			'token_endpoint'                             => Saddle_OAuth::endpoint( 'token' ),
			'revocation_endpoint'                        => Saddle_OAuth::endpoint( 'revoke' ),
			'scopes_supported'                           => Saddle_OAuth::SCOPES,
			'response_types_supported'                   => array( 'code' ),
			'response_modes_supported'                   => array( 'query' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported'      => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			// S256 only. OAuth 2.1 removes `plain`, and advertising it would let a
			// client downgrade itself into an interceptable flow.
			'code_challenge_methods_supported'           => array( 'S256' ),
			'authorization_response_iss_parameter_supported' => true,
			'client_id_metadata_document_supported'      => Saddle_OAuth_Clients::cimd_enabled(),
			'resource_indicators_supported'              => true,
			'service_documentation'                      => 'https://wordpress.org/plugins/saddle/',
		);

		// Omitted rather than left pointing at a 404 when self-registration is
		// off, so a client falls through to a mechanism that does work instead of
		// failing on one that doesn't.
		if ( Saddle_OAuth_Clients::dcr_enabled() ) {
			$document['registration_endpoint'] = Saddle_OAuth::endpoint( 'register' );
		}

		/**
		 * Filter Saddle's authorization server metadata document.
		 *
		 * @param array $document RFC 8414 document.
		 */
		return (array) apply_filters( 'saddle_oauth_authorization_server_metadata', $document );
	}

	/**
	 * Whether the root `.well-known` documents are actually reachable.
	 *
	 * A loopback probe, the same shape as {@see Saddle_Connection::self_check()}'s
	 * Authorization-header probe. Two things commonly break it: WordPress living
	 * in a subdirectory (the plugin never owns the host root), and plain
	 * permalinks (no rewrite sends unknown paths to `index.php`). Neither is
	 * fatal — the REST routes and the 401 challenge still work — but a client
	 * that only probes the root will fail, so the owner should be told.
	 *
	 * @return string 'ok' | 'slow' | 'unreachable' | 'unknown'
	 */
	public static function probe_root() {
		/**
		 * Filter how long a discovery fetch may take before it counts as slow.
		 *
		 * @param float $budget Seconds.
		 */
		$budget = (float) apply_filters( 'saddle_oauth_discovery_probe_budget', self::PROBE_BUDGET );

		$started  = microtime( true );
		$response = wp_remote_get(
			home_url( self::ASM_PATH ),
			array(
				'timeout'     => self::PROBE_TIMEOUT,
				// Loopback to our own host; dev and staging often serve a
				// self-signed certificate, and the probe reads only our own
				// issuer string back, never trusting anything else in the body.
				'sslverify'   => false,
				'redirection' => 0,
				'cookies'     => array(),
			)
		);

		$elapsed = microtime( true ) - $started;

		if ( is_wp_error( $response ) ) {
			// Burning the whole timeout on a loopback fetch of a static JSON
			// document is not an unknown result — it is a site too slow to
			// finish discovery, and a connecting client gives up long before we
			// do. Measured rather than sniffed out of the error message, which
			// is host- and transport-specific.
			return $elapsed >= ( self::PROBE_TIMEOUT * 0.9 ) ? 'slow' : 'unknown';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['issuer'] ) ) {
			return 'unreachable';
		}

		if ( Saddle_OAuth::issuer() !== $body['issuer'] ) {
			return 'unreachable';
		}

		// Served the right document, but slowly enough that a client with a
		// tighter budget than ours would have abandoned the fetch and reported
		// the site as not supporting OAuth at all.
		return $elapsed > $budget ? 'slow' : 'ok';
	}
}
