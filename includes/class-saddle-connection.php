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
	 * User-meta key recording the UUIDs of app passwords Saddle issued.
	 *
	 * The immutable identity credential scoping keys on: the display name
	 * ("Saddle: …") is user-editable in wp-admin, and scoping that silently
	 * stops applying after a rename would hand the key full wp/v2 access.
	 */
	const ISSUED_META = 'saddle_issued_credentials';

	/**
	 * Scope Saddle-issued credentials to Saddle's own REST surface.
	 *
	 * A core Application Password authenticates the ENTIRE REST API as its
	 * user — so without this, an agent holding a Saddle key could sidestep
	 * every Saddle safety layer (tier, pause, per-tool toggles, approval
	 * gate, audit log) by calling wp/v2 or wp-abilities/v1 directly with the
	 * same Basic auth. Requests authenticated with a `Saddle: `-prefixed
	 * Application Password are therefore confined to the MCP endpoint alone —
	 * NOT the whole saddle/v1 namespace, because that namespace also carries
	 * Saddle's own control plane (settings/tier, per-tool toggles, skills,
	 * client issuance), and a key that can raise its own tier or mint fresh
	 * credentials would undo the entire safety model. Control-plane routes
	 * require a cookie session; the key Saddle issues only opens the tools
	 * door.
	 *
	 * Hooked to `rest_request_before_callbacks` (after auth, before any
	 * handler runs). Cookie sessions and non-Saddle app passwords are
	 * untouched.
	 *
	 * @param mixed           $response Current response (null unless already decided).
	 * @param array           $handler  Matched route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed WP_Error to refuse, otherwise $response unchanged.
	 */
	public static function scope_credentials( $response, $handler, $request ) {
		if ( null !== $response ) {
			return $response; // Another callback already decided this request.
		}

		/**
		 * Filter whether Saddle-issued credentials are confined to saddle/v1.
		 *
		 * @param bool $scope Default true.
		 */
		if ( ! apply_filters( 'saddle_scope_credentials', true ) ) {
			return $response;
		}

		$uuid = function_exists( 'rest_get_authenticated_app_password' )
			? rest_get_authenticated_app_password()
			: null;
		if ( ! $uuid || ! self::is_saddle_issued( get_current_user_id(), $uuid ) ) {
			return $response;
		}

		$route = $request->get_route();

		/**
		 * Filter the route prefixes a Saddle-issued credential may access.
		 *
		 * Defaults to the MCP endpoint only. Widening this to all of
		 * `/saddle/v1` would let a connected agent drive Saddle's own
		 * control plane (raise its tier, re-enable tools, mint credentials)
		 * with plain Basic auth — widen deliberately, and never to a prefix
		 * that carries admin routes.
		 *
		 * @param string[] $allowed Route prefixes (leading slash).
		 */
		$allowed = (array) apply_filters(
			'saddle_credential_allowed_routes',
			array( '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE )
		);
		foreach ( $allowed as $prefix ) {
			if ( '' !== (string) $prefix && 0 === strpos( $route, (string) $prefix ) ) {
				return $response;
			}
		}

		return new WP_Error(
			'saddle_credential_scope',
			__( 'This sign-in key was issued by Saddle and works only with Saddle’s endpoint — the rest of the WordPress REST API is off limits to it by design. Use the Saddle tools instead.', 'saddle' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Refuse Saddle-issued credentials over XML-RPC — the other API surface
	 * Application Passwords authenticate, with none of Saddle's safety model.
	 *
	 * Hooked to `wp_authenticate_application_password_errors`; adding an
	 * error makes core reject the authentication attempt itself.
	 *
	 * @param WP_Error $error Error accumulator core checks after this action.
	 * @param WP_User  $user  User being authenticated.
	 * @param array    $item  The matched application password item.
	 */
	public static function block_xmlrpc_credentials( $error, $user, $item ) {
		/**
		 * Filter whether the current request counts as XML-RPC (overridable
		 * for tests and unusual gateway setups).
		 *
		 * @param bool $is_xmlrpc Default: the XMLRPC_REQUEST constant.
		 */
		$is_xmlrpc = apply_filters( 'saddle_is_xmlrpc_request', defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
		if ( ! $is_xmlrpc ) {
			return;
		}
		if ( ! apply_filters( 'saddle_scope_credentials', true ) ) {
			return;
		}
		$is_saddle = isset( $item['uuid'] ) && $user instanceof WP_User
			? self::is_saddle_issued( $user->ID, (string) $item['uuid'] )
			: ( isset( $item['name'] ) && 0 === strpos( (string) $item['name'], class_exists( 'Saddle_REST_Admin' ) ? Saddle_REST_Admin::CLIENT_PREFIX : 'Saddle: ' ) );
		if ( $is_saddle ) {
			$error->add(
				'saddle_credential_scope',
				__( 'Saddle sign-in keys cannot be used over XML-RPC.', 'saddle' )
			);
		}
	}

	/**
	 * Record an app-password UUID as Saddle-issued for a user.
	 *
	 * @param int    $user_id User the credential belongs to.
	 * @param string $uuid    Application password UUID.
	 */
	public static function mark_issued( $user_id, $uuid ) {
		$uuids = get_user_meta( (int) $user_id, self::ISSUED_META, true );
		$uuids = is_array( $uuids ) ? $uuids : array();
		if ( ! in_array( (string) $uuid, $uuids, true ) ) {
			$uuids[] = (string) $uuid;
			update_user_meta( (int) $user_id, self::ISSUED_META, $uuids );
		}
	}

	/**
	 * Forget a revoked credential's UUID.
	 *
	 * @param int    $user_id User the credential belonged to.
	 * @param string $uuid    Application password UUID.
	 */
	public static function unmark_issued( $user_id, $uuid ) {
		$uuids = get_user_meta( (int) $user_id, self::ISSUED_META, true );
		if ( ! is_array( $uuids ) || ! in_array( (string) $uuid, $uuids, true ) ) {
			return;
		}
		$uuids = array_values( array_diff( $uuids, array( (string) $uuid ) ) );
		if ( $uuids ) {
			update_user_meta( (int) $user_id, self::ISSUED_META, $uuids );
		} else {
			delete_user_meta( (int) $user_id, self::ISSUED_META );
		}
	}

	/**
	 * Whether the given application password UUID is one Saddle issued for
	 * this user.
	 *
	 * Keyed on the stored UUID marker, which survives a rename in wp-admin →
	 * Application Passwords. Keys issued before the marker existed are only
	 * recognizable by their `Saddle: ` name prefix — those migrate into the
	 * marker on first sight, so a later rename can no longer un-scope them.
	 *
	 * @param int    $user_id User the request authenticated as.
	 * @param string $uuid    Application password UUID.
	 * @return bool
	 */
	public static function is_saddle_issued( $user_id, $uuid ) {
		if ( ! $user_id || '' === (string) $uuid || ! class_exists( 'WP_Application_Passwords' ) ) {
			return false;
		}

		$uuids = get_user_meta( (int) $user_id, self::ISSUED_META, true );
		if ( is_array( $uuids ) && in_array( (string) $uuid, $uuids, true ) ) {
			return true;
		}

		$item = WP_Application_Passwords::get_user_application_password( (int) $user_id, (string) $uuid );
		if ( ! $item || empty( $item['name'] ) ) {
			return false;
		}
		$prefix = class_exists( 'Saddle_REST_Admin' ) ? Saddle_REST_Admin::CLIENT_PREFIX : 'Saddle: ';
		if ( 0 === strpos( (string) $item['name'], $prefix ) ) {
			self::mark_issued( (int) $user_id, (string) $uuid );
			return true;
		}

		return false;
	}

	/**
	 * Recover a stripped Basic Authorization header into the variables core's
	 * Application Password authenticator reads. Hooked very early (before REST
	 * auth). Idempotent and side-effect-free when the server already did its job.
	 */
	public static function recover_auth_header() {
		// Only bother for REST/API traffic — never touch normal browser requests.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a standard RFC 7617 Basic credential, not obfuscated code.
		$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return;
		}

		list( $user, $pass )      = explode( ':', $decoded, 2 );
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
				return trim( sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) );
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
	 * Whether Basic credentials actually reached PHP on the current request.
	 *
	 * True when core surfaced them (`PHP_AUTH_USER`) or {@see self::recover_auth_header()}
	 * recovered them, or when a raw `Basic` Authorization header is still readable.
	 * A 401 with credentials present means they were rejected (revoked/wrong); a 401
	 * with none present means they never arrived (stripped header).
	 *
	 * @return bool
	 */
	public static function request_carried_credentials() {
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			return true;
		}
		$header = self::authorization_header();
		return '' !== $header && 0 === stripos( $header, 'basic ' );
	}

	/**
	 * Whether the current request is aimed at Saddle's MCP endpoint.
	 *
	 * Read from the request URI because this runs at `rest_authentication_errors`,
	 * before the REST route is resolved.
	 *
	 * @return bool
	 */
	private static function targets_mcp_endpoint() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}
		$path = Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE; // e.g. saddle/v1/mcp.
		return false !== strpos( $uri, $path ) || false !== strpos( rawurldecode( $uri ), $path );
	}

	/**
	 * Relabel core's generic application-password 401 into a legible one when the
	 * request targets Saddle's MCP endpoint and credentials were actually present.
	 *
	 * Core rejects a revoked/invalid Application Password at
	 * `rest_authentication_errors` — before the route's permission_callback runs —
	 * so {@see Saddle_MCP::authenticated()} never sees it. Without this, the owner
	 * gets an opaque "invalid username/password" and can't tell a revoked key from
	 * a header problem. Scoped to Saddle's own endpoint so no other REST auth error
	 * is touched.
	 *
	 * @param WP_Error|null|true $errors Current authentication result.
	 * @return WP_Error|null|true
	 */
	public static function explain_auth_error( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}
		if ( ! self::targets_mcp_endpoint() || ! self::request_carried_credentials() ) {
			return $errors;
		}

		$data   = $errors->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : (int) $data;
		if ( 401 !== $status && 403 !== $status ) {
			return $errors;
		}

		return new WP_Error(
			'saddle_credential_rejected',
			__( 'Your sign-in key was rejected — it was most likely revoked or removed. Reconnect the app from Saddle to issue a fresh key.', 'saddle' ),
			array(
				'status' => 401,
				'reason' => 'credential_rejected',
			)
		);
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
			'auth_header'             => $auth_header, // One of: ok, stripped, unknown.
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
				// Loopback to our own rest_url with a throwaway credential —
				// local dev/staging often serves a self-signed cert, and the
				// probe reads only a boolean, never trusting the response body.
				'sslverify'   => false,
				'redirection' => 0,
				'cookies'     => array(),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a standard RFC 7617 Basic header for the loopback probe.
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- read-only capability probe; the actual write goes through core's insert_with_markers().
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- read-only capability probe; the actual write goes through core's insert_with_markers().
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
		return isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	}
}
