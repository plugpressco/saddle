<?php
/**
 * Connection health: make Application Password auth work on hosts that mangle
 * the Authorization header, and let the owner diagnose/fix it in one click.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single most common failure for any self-hosted MCP/REST product is the
 * web server stripping the `Authorization` header before PHP sees it — so the
 * user's Application Password never reaches WordPress and every call 401s.
 *
 * Saddle handles this without abandoning core Application Passwords (unlike
 * competitors that ship their own token auth to sidestep it):
 *
 *   1. {@see self::recover_auth_header()} — runs early and, if the server hid
 *      the Basic credentials from `$_SERVER['PHP_AUTH_*']` but the raw header is
 *      still reachable (getallheaders / REDIRECT_HTTP_AUTHORIZATION), recovers
 *      it and hands it to CORE's authenticator. No custom auth, no user action.
 *   2. {@see self::self_check()} — a loopback probe that tells the owner whether
 *      the header is arriving, in plain language.
 *   3. {@see self::apply_htaccess_fix()} — a one-click Apache/LiteSpeed fix that
 *      writes the standard forwarding rule the same way WordPress writes its own
 *      permalink block. nginx / non-writable hosts get the exact snippet instead.
 */
class Saddle_Connection {

	/**
	 * Marker used for Saddle's managed block in `.htaccess`.
	 */
	const HTACCESS_MARKER = 'Saddle Authorization Header';

	/**
	 * Recover a stripped Basic Authorization header into the variables core's
	 * Application Password authenticator reads. Hooked very early (before REST
	 * auth). Idempotent and side-effect-free when the server already did its job.
	 */
	public static function recover_auth_header() {
		// Only bother for REST/API traffic — never touch normal browser requests.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( false === strpos( $uri, 'wp-json' ) && false === strpos( $uri, 'rest_route' ) ) {
			return;
		}

		// If the server already surfaced Basic credentials, there is nothing to do.
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			return;
		}

		$header = self::authorization_header();
		if ( '' === $header || 0 !== stripos( $header, 'basic ' ) ) {
			return;
		}

		$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return;
		}

		list( $user, $pass ) = explode( ':', $decoded, 2 );
		$_SERVER['PHP_AUTH_USER'] = $user;
		$_SERVER['PHP_AUTH_PW']   = $pass;
	}

	/**
	 * Best-effort read of the raw Authorization header across server quirks.
	 *
	 * @return string The header value, or '' if none can be found.
	 */
	public static function authorization_header() {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return trim( (string) $_SERVER[ $key ] );
			}
		}

		foreach ( array( 'getallheaders', 'apache_request_headers' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				$headers = call_user_func( $fn );
				if ( is_array( $headers ) ) {
					foreach ( $headers as $name => $value ) {
						if ( 0 === strcasecmp( (string) $name, 'Authorization' ) && '' !== trim( (string) $value ) ) {
							return trim( (string) $value );
						}
					}
				}
			}
		}

		return '';
	}

	/**
	 * Register the connection-health REST routes (admin diagnostics + a tiny
	 * public probe used only by the loopback self-check).
	 */
	public static function register_routes() {
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/self-check',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_self_check' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/fix-auth-header',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_fix_auth_header' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
			)
		);

		// Unauthenticated: reports only whether an Authorization header arrived.
		// Used exclusively by self_check()'s loopback request to itself.
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/auth-probe',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_auth_probe' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /auth-probe — echoes whether the request carried an Authorization
	 * header that PHP could see. No credentials are read, validated, or returned.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_auth_probe() {
		return new WP_REST_Response( array( 'received' => ( '' !== self::authorization_header() ) ), 200 );
	}

	/**
	 * GET /self-check — connection diagnostics for the admin UI.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_self_check() {
		return new WP_REST_Response( self::self_check(), 200 );
	}

	/**
	 * POST /fix-auth-header — attempt the one-click Apache/LiteSpeed fix.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_fix_auth_header() {
		$result = self::apply_htaccess_fix();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Assemble the connection health report.
	 *
	 * @return array
	 */
	public static function self_check() {
		$auth_header = self::probe_auth_header();

		$report = array(
			'app_passwords_available' => function_exists( 'wp_is_application_passwords_available' ) ? (bool) wp_is_application_passwords_available() : false,
			'is_ssl'                  => is_ssl(),
			'server'                  => self::server_software(),
			'endpoint'                => rest_url( ltrim( Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE, '/' ) ),
			'auth_header'             => $auth_header, // 'ok' | 'stripped' | 'unknown'
			'htaccess_fixable'        => ( 'stripped' === $auth_header ) && self::htaccess_fixable(),
			'fix_snippet'             => self::fix_snippet(),
		);

		if ( ! $report['app_passwords_available'] ) {
			$report['status'] = 'app_passwords_off';
		} elseif ( 'stripped' === $auth_header ) {
			$report['status'] = 'auth_header_stripped';
		} elseif ( 'ok' === $auth_header ) {
			$report['status'] = 'ok';
		} else {
			$report['status'] = 'unknown';
		}

		return $report;
	}

	/**
	 * Loopback probe: does an Authorization header survive the trip to our own
	 * REST endpoint on this server?
	 *
	 * @return string 'ok' | 'stripped' | 'unknown'
	 */
	private static function probe_auth_header() {
		$url  = rest_url( Saddle_REST_Admin::REST_NAMESPACE . '/auth-probe' );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'sslverify'   => false,
				'redirection' => 0,
				'cookies'     => array(),
				'headers'     => array( 'Authorization' => 'Basic ' . base64_encode( 'saddle-probe:x' ) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return 'unknown';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || ! array_key_exists( 'received', $body ) ) {
			return 'unknown';
		}

		return ! empty( $body['received'] ) ? 'ok' : 'stripped';
	}

	/**
	 * Whether Saddle can write the fix to `.htaccess` itself (Apache/LiteSpeed
	 * with a writable file).
	 *
	 * @return bool
	 */
	public static function htaccess_fixable() {
		$server = strtolower( self::server_software() );
		$apache = ( false !== strpos( $server, 'apache' ) ) || ( false !== strpos( $server, 'litespeed' ) );
		if ( ! $apache ) {
			return false;
		}

		$path = self::htaccess_path();
		if ( '' === $path ) {
			return false;
		}

		return file_exists( $path ) ? is_writable( $path ) : is_writable( dirname( $path ) );
	}

	/**
	 * Write the Authorization-forwarding rule into `.htaccess`, the same managed-
	 * block way core writes its permalink rules (idempotent, removable).
	 *
	 * @return array|WP_Error
	 */
	public static function apply_htaccess_fix() {
		if ( ! self::htaccess_fixable() ) {
			return new WP_Error(
				'saddle_htaccess_not_fixable',
				__( 'Saddle can’t edit this server’s configuration automatically. Add the rule shown below by hand or send it to your host.', 'saddle' ),
				array( 'status' => 409 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$path  = self::htaccess_path();
		$rules = array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{HTTP:Authorization} .',
			'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
			'</IfModule>',
			'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
		);

		if ( ! insert_with_markers( $path, self::HTACCESS_MARKER, $rules ) ) {
			return new WP_Error(
				'saddle_htaccess_write_failed',
				__( 'Saddle could not write to .htaccess. Check file permissions, or add the rule by hand.', 'saddle' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'applied'     => true,
			'auth_header' => self::probe_auth_header(),
		);
	}

	/**
	 * Remove Saddle's managed `.htaccess` block, if present. Called on uninstall
	 * so the plugin leaves no server-config residue behind. Best-effort and
	 * silent: a missing file or an unwritable path is simply a no-op.
	 */
	public static function remove_htaccess_fix() {
		$path = self::htaccess_path();
		if ( '' === $path || ! file_exists( $path ) || ! is_writable( $path ) ) {
			return;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		// An empty rule set removes the marked block entirely.
		insert_with_markers( $path, self::HTACCESS_MARKER, array() );
	}

	/**
	 * The plain-text fix, shown for hosts we can't fix automatically.
	 *
	 * @return array{apache:string,nginx:string}
	 */
	public static function fix_snippet() {
		return array(
			'apache' => "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{HTTP:Authorization} .\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n</IfModule>\nSetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=\$1",
			'nginx'  => 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;',
		);
	}

	/**
	 * Absolute path to the site's `.htaccess`. Filterable for tests.
	 *
	 * @return string
	 */
	public static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$path = rtrim( (string) $home, '/\\' ) . '/.htaccess';

		/**
		 * Filter the `.htaccess` path Saddle reads/writes for the auth-header fix.
		 *
		 * @param string $path Absolute path to .htaccess.
		 */
		return (string) apply_filters( 'saddle_htaccess_path', $path );
	}

	/**
	 * The reported web-server software string.
	 *
	 * @return string
	 */
	public static function server_software() {
		return isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
	}
}
