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
}
