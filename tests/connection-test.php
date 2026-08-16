<?php
/**
 * Connection-health tests — the Authorization-header recovery shim and the
 * one-click .htaccess fix that make Application Password auth survive hosts
 * that strip the header.
 *
 * @package Saddle
 */

class Saddle_Connection_Test extends WP_UnitTestCase {

	private $server_backup;

	public function set_up() {
		parent::set_up();
		$this->server_backup = $_SERVER;
	}

	public function tear_down() {
		$_SERVER = $this->server_backup;
		parent::tear_down();
	}

	/* -------- recover_auth_header (the automatic shim) -------- */

	public function test_recovers_basic_credentials_from_header_into_php_auth() {
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		$_SERVER['REQUEST_URI']       = '/wp-json/saddle/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'SuperAdmin:app-pass-123' );

		Saddle_Connection::recover_auth_header();

		$this->assertSame( 'SuperAdmin', $_SERVER['PHP_AUTH_USER'] );
		$this->assertSame( 'app-pass-123', $_SERVER['PHP_AUTH_PW'] );
	}

	public function test_does_not_overwrite_existing_php_auth() {
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';
		$_SERVER['PHP_AUTH_USER']      = 'already-here';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'SuperAdmin:app-pass-123' );

		Saddle_Connection::recover_auth_header();

		$this->assertSame( 'already-here', $_SERVER['PHP_AUTH_USER'], 'Must not clobber server-provided credentials.' );
	}

	public function test_ignores_non_rest_requests() {
		unset( $_SERVER['PHP_AUTH_USER'] );
		$_SERVER['REQUEST_URI']        = '/wp-admin/index.php';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'SuperAdmin:app-pass-123' );

		Saddle_Connection::recover_auth_header();

		$this->assertTrue( empty( $_SERVER['PHP_AUTH_USER'] ), 'Normal browser requests must be untouched.' );
	}

	public function test_ignores_non_basic_scheme() {
		unset( $_SERVER['PHP_AUTH_USER'] );
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sometoken';

		Saddle_Connection::recover_auth_header();

		$this->assertTrue( empty( $_SERVER['PHP_AUTH_USER'] ), 'Only Basic credentials feed core Application Passwords.' );
	}

	public function test_authorization_header_prefers_server_var() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic aaa';
		$this->assertSame( 'Basic aaa', Saddle_Connection::authorization_header() );

		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic bbb';
		$this->assertSame( 'Basic bbb', Saddle_Connection::authorization_header() );
	}

	/* -------- one-click .htaccess fix -------- */

	public function test_htaccess_fix_writes_a_removable_marked_block() {
		$tmp = wp_tempnam( 'saddle-htaccess' );
		$filter = function () use ( $tmp ) {
			return $tmp;
		};
		add_filter( 'saddle_htaccess_path', $filter );
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';

		// Short-circuit the post-write loopback probe so the test doesn't wait on
		// a real network timeout to the (non-resolving) test domain.
		$http = function () {
			return array(
				'body'     => wp_json_encode( array( 'received' => true ) ),
				'response' => array( 'code' => 200 ),
			);
		};
		add_filter( 'pre_http_request', $http );

		$this->assertTrue( Saddle_Connection::htaccess_fixable(), 'Apache + writable file must be auto-fixable.' );

		$result = Saddle_Connection::apply_htaccess_fix();
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['applied'] );

		$contents = file_get_contents( $tmp );
		$this->assertStringContainsString( '# BEGIN Saddle Authorization Header', $contents );
		$this->assertStringContainsString( 'HTTP_AUTHORIZATION', $contents );
		$this->assertStringContainsString( '# END Saddle Authorization Header', $contents );

		remove_filter( 'saddle_htaccess_path', $filter );
		remove_filter( 'pre_http_request', $http );
		unlink( $tmp );
	}

	public function test_htaccess_not_fixable_on_nginx() {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24';
		$this->assertFalse( Saddle_Connection::htaccess_fixable() );

		$result = Saddle_Connection::apply_htaccess_fix();
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_htaccess_not_fixable', $result->get_error_code() );
	}

	public function test_fix_snippet_covers_apache_and_nginx() {
		$snippet = Saddle_Connection::fix_snippet();
		$this->assertStringContainsString( 'HTTP_AUTHORIZATION', $snippet['apache'] );
		$this->assertStringContainsString( 'fastcgi_param HTTP_AUTHORIZATION', $snippet['nginx'] );
	}

	/* -------- auth probe -------- */

	public function test_auth_probe_reports_header_presence() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'saddle-probe:x' );
		$this->assertTrue( Saddle_Connection::rest_auth_probe()->get_data()['received'] );

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$this->assertFalse( Saddle_Connection::rest_auth_probe()->get_data()['received'] );
	}

	public function test_auth_probe_reports_which_credentials_arrived() {
		$_SERVER['HTTP_X_WP_NONCE']            = 'whatever';
		$_SERVER['HTTP_X_SADDLE_PROBE']        = '1';
		$_REQUEST['_wpnonce']                  = 'whatever';
		$_COOKIE['wordpress_logged_in_abc123'] = 'value';

		$data = Saddle_Connection::rest_auth_probe()->get_data();

		$this->assertTrue( $data['nonce_header'] );
		$this->assertTrue( $data['nonce_query'] );
		$this->assertTrue( $data['cookie'] );
		$this->assertTrue( $data['custom_header'] );

		unset( $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_X_SADDLE_PROBE'], $_REQUEST['_wpnonce'], $_COOKIE['wordpress_logged_in_abc123'] );

		$data = Saddle_Connection::rest_auth_probe()->get_data();

		$this->assertFalse( $data['nonce_header'] );
		$this->assertFalse( $data['nonce_query'] );
		$this->assertFalse( $data['cookie'] );
		$this->assertFalse( $data['custom_header'] );
	}

	/**
	 * The probe is a public route. Its whole safety argument is that it reports
	 * booleans about the caller's own request and nothing else, so pin the exact
	 * key set — a new key must be a deliberate decision, not a slip.
	 */
	public function test_auth_probe_returns_only_the_documented_booleans() {
		$data = Saddle_Connection::rest_auth_probe()->get_data();

		$this->assertSame(
			array( 'received', 'nonce_header', 'nonce_query', 'cookie', 'identified', 'custom_header' ),
			array_keys( $data )
		);

		foreach ( $data as $key => $value ) {
			$this->assertIsBool( $value, "Probe key '{$key}' must be a boolean." );
		}
	}

	public function test_auth_probe_never_echoes_a_credential_or_a_user() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$nonce                                 = wp_create_nonce( 'wp_rest' );
		$_SERVER['HTTP_X_WP_NONCE']            = $nonce;
		$_REQUEST['_wpnonce']                  = $nonce;
		$_COOKIE['wordpress_logged_in_abc123'] = 'secret-cookie-value';

		$json = wp_json_encode( Saddle_Connection::rest_auth_probe()->get_data() );

		$this->assertStringNotContainsString( $nonce, $json );
		$this->assertStringNotContainsString( 'secret-cookie-value', $json );
		$this->assertStringNotContainsString( (string) $user, $json );
		$this->assertStringNotContainsString( get_userdata( $user )->user_login, $json );

		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'], $_COOKIE['wordpress_logged_in_abc123'] );
		wp_set_current_user( 0 );
	}

	/* -------- self-check: telling the two stripped headers apart -------- */

	/**
	 * Point the loopback probe at a canned response.
	 *
	 * Also forces Application Passwords "available": the test site is plain HTTP,
	 * where core turns them off, and `app_passwords_off` outranks everything else
	 * in the status ladder — so without this every status assertion would read
	 * that instead of the thing under test.
	 *
	 * @param array $body    What the probe endpoint "returns".
	 * @param array $capture Filled with the request args, by reference.
	 * @return callable Cleanup — call it to unhook everything this added.
	 */
	private function fake_loopback( array $body, &$capture = null ) {
		$http = function ( $pre, $args ) use ( $body, &$capture ) {
			$capture = $args;
			return array(
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => 200 ),
			);
		};
		add_filter( 'pre_http_request', $http, 10, 2 );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		return function () use ( $http ) {
			remove_filter( 'pre_http_request', $http, 10 );
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		};
	}

	public function test_self_check_reports_a_stripped_nonce_header() {
		$args   = null;
		$restore = $this->fake_loopback(
			array(
				'received'     => true,
				'nonce_header' => false,
			),
			$args
		);

		$report = Saddle_Connection::self_check();

		$this->assertSame( 'ok', $report['auth_header'] );
		$this->assertSame( 'stripped', $report['nonce_header'] );
		$this->assertSame( 'nonce_header_stripped', $report['status'] );

		// The loopback has to actually send the header it is asking about.
		$this->assertSame( 'saddle-probe', $args['headers']['X-WP-Nonce'] );

		$restore();
	}

	/**
	 * A stripped Authorization header breaks every external app; a stripped nonce
	 * only costs this dashboard a workaround it already applies. The louder
	 * problem must win, or the fixable one hides behind the cosmetic one.
	 */
	public function test_self_check_prefers_the_authorization_problem() {
		$restore = $this->fake_loopback(
			array(
				'received'     => false,
				'nonce_header' => false,
			)
		);

		$report = Saddle_Connection::self_check();

		$this->assertSame( 'stripped', $report['auth_header'] );
		$this->assertSame( 'stripped', $report['nonce_header'] );
		$this->assertSame( 'auth_header_stripped', $report['status'] );

		$restore();
	}

	/**
	 * There is no .htaccess rule that fixes a stripped nonce — the standard rule
	 * forwards Authorization only, and an edge-level strip is upstream of Apache
	 * anyway. Offering a button that cannot work would be worse than saying so.
	 */
	public function test_stripped_nonce_alone_offers_no_htaccess_fix() {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
		$restore                    = $this->fake_loopback(
			array(
				'received'     => true,
				'nonce_header' => false,
			)
		);

		$report = Saddle_Connection::self_check();

		$this->assertSame( 'nonce_header_stripped', $report['status'] );
		$this->assertFalse( $report['htaccess_fixable'] );

		$restore();
	}

	/**
	 * Older responses (a cached probe, a site mid-upgrade) have no nonce key.
	 * That must read as "don't know", never as "stripped".
	 */
	public function test_self_check_treats_a_missing_nonce_key_as_unknown() {
		$restore = $this->fake_loopback( array( 'received' => true ) );

		$report = Saddle_Connection::self_check();

		$this->assertSame( 'unknown', $report['nonce_header'] );
		$this->assertSame( 'ok', $report['status'] );

		$restore();
	}

	/* -------- legible 401s: revoked key vs stripped header (#36) -------- */

	public function test_request_carried_credentials_true_from_php_auth_user() {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$_SERVER['PHP_AUTH_USER'] = 'someone';
		$this->assertTrue( Saddle_Connection::request_carried_credentials() );
	}

	public function test_request_carried_credentials_true_from_basic_header() {
		unset( $_SERVER['PHP_AUTH_USER'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'u:p' );
		$this->assertTrue( Saddle_Connection::request_carried_credentials() );
	}

	public function test_request_carried_credentials_false_when_absent() {
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$this->assertFalse( Saddle_Connection::request_carried_credentials() );
	}

	public function test_authenticated_gate_reports_stripped_header_when_no_credentials() {
		wp_set_current_user( 0 );
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$result = Saddle_MCP::authenticated();
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_no_credentials', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
		$this->assertSame( 'no_credentials', $data['reason'] );
	}

	public function test_authenticated_gate_reports_rejected_key_when_credentials_present() {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$_SERVER['PHP_AUTH_USER'] = 'someone';

		$result = Saddle_MCP::authenticated();
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_credential_rejected', $result->get_error_code() );
		$this->assertSame( 'credential_rejected', $result->get_error_data()['reason'] );
	}

	public function test_explain_auth_error_relabels_core_401_on_mcp_endpoint() {
		$_SERVER['REQUEST_URI'] = '/wp-json/saddle/v1/mcp';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$_SERVER['PHP_AUTH_USER'] = 'someone';

		$core = new WP_Error( 'rest_not_logged_in', 'Sorry.', array( 'status' => 401 ) );
		$out  = Saddle_Connection::explain_auth_error( $core );

		$this->assertWPError( $out );
		$this->assertSame( 'saddle_credential_rejected', $out->get_error_code() );
	}

	public function test_explain_auth_error_ignores_non_mcp_requests() {
		$_SERVER['REQUEST_URI']   = '/wp-json/wp/v2/posts';
		$_SERVER['PHP_AUTH_USER'] = 'someone';

		$core = new WP_Error( 'rest_not_logged_in', 'Sorry.', array( 'status' => 401 ) );
		$out  = Saddle_Connection::explain_auth_error( $core );

		$this->assertSame( 'rest_not_logged_in', $out->get_error_code() );
	}

	public function test_explain_auth_error_ignores_stripped_header_request() {
		// No credentials present → this is the header-stripping case, handled by
		// the route gate, not this relabeler. Leave core's result untouched.
		$_SERVER['REQUEST_URI'] = '/wp-json/saddle/v1/mcp';
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$core = new WP_Error( 'rest_not_logged_in', 'Sorry.', array( 'status' => 401 ) );
		$out  = Saddle_Connection::explain_auth_error( $core );

		$this->assertSame( 'rest_not_logged_in', $out->get_error_code() );
	}

	public function test_explain_auth_error_passes_through_non_errors() {
		$this->assertTrue( Saddle_Connection::explain_auth_error( true ) );
		$this->assertNull( Saddle_Connection::explain_auth_error( null ) );
	}
}
