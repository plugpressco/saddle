<?php
/**
 * Connect + revoke tests — the last two manual items on the release checklist.
 *
 * The browser hop to core's Authorize Application screen is core's own code, not
 * Saddle's; what Saddle owns (and what these tests pin down) is: the connect URL
 * it builds, the Saddle-prefixed filtering of connected clients, and — the
 * security-critical property — that revoking a client actually invalidates its
 * Application Password so it can no longer authenticate.
 *
 * @package Saddle
 */

class Saddle_Connect_Test extends WP_UnitTestCase {

	private $admin;
	private $login;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->login = get_userdata( $this->admin )->user_login;
		wp_set_current_user( $this->admin );

		// Application Passwords are gated off on non-SSL "production" installs and
		// application-password auth only runs for API requests. Force both on so
		// the auth path is exercisable in the test harness.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		parent::tear_down();
	}

	/** Create an Application Password exactly as core does when a user approves. */
	private function issue_password( $name ) {
		$created = WP_Application_Passwords::create_new_application_password(
			$this->admin,
			array( 'name' => $name )
		);
		$this->assertNotWPError( $created );
		return $created; // array( $raw_password, $item ).
	}

	/* -------- connect URL -------- */

	public function test_connect_url_targets_core_authorize_screen_with_prefixed_name() {
		$req = new WP_REST_Request( 'GET', '/saddle/v1/connect-url' );
		$req->set_param( 'name', 'My Laptop' );

		$url = Saddle_REST_Admin::get_connect_url( $req )->get_data()['url'];

		$this->assertStringContainsString( 'authorize-application.php', $url );

		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		// app_name must carry Saddle's prefix so clients filter back to it.
		$this->assertSame( 'Saddle: My Laptop', $query['app_name'] );
		// Success/reject round-trip back to the Saddle admin page.
		$this->assertStringContainsString( 'page=saddle', $query['success_url'] );
		$this->assertStringContainsString( 'connected=1', $query['success_url'] );
		$this->assertStringContainsString( 'page=saddle', $query['reject_url'] );
	}

	public function test_connect_url_falls_back_to_a_default_name() {
		$req = new WP_REST_Request( 'GET', '/saddle/v1/connect-url' );
		$req->set_param( 'name', '' );

		$url   = Saddle_REST_Admin::get_connect_url( $req )->get_data()['url'];
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'Saddle: ', $query['app_name'] );
		$this->assertNotSame( 'Saddle: ', $query['app_name'], 'An empty name must fall back to a label.' );
	}

	/* -------- direct credential creation (the wizard's path) -------- */

	public function test_create_client_issues_a_working_saddle_prefixed_credential() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/clients' );
		$req->set_param( 'name', 'Claude Code' );

		$res = Saddle_REST_Admin::create_client( $req );
		$this->assertNotWPError( $res );
		$this->assertSame( 201, $res->get_status() );

		$data = $res->get_data();
		$this->assertSame( 'Saddle: Claude Code', $data['name'] );
		$this->assertSame( 'Claude Code', $data['label'] );
		$this->assertSame( $this->login, $data['user_login'] );
		$this->assertNotEmpty( $data['password'] );

		// The returned raw password must actually authenticate the owning user.
		$authed = wp_authenticate_application_password( null, $this->login, $data['password'] );
		$this->assertInstanceOf( 'WP_User', $authed );
		$this->assertSame( $this->admin, $authed->ID );

		// And the credential shows up in the clients list.
		$names = wp_list_pluck( Saddle_REST_Admin::get_clients()->get_data()['clients'], 'name' );
		$this->assertContains( 'Saddle: Claude Code', $names );
	}

	public function test_create_client_suffixes_duplicate_names() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/clients' );
		$req->set_param( 'name', 'Cursor' );

		$first  = Saddle_REST_Admin::create_client( $req )->get_data();
		$second = Saddle_REST_Admin::create_client( $req )->get_data();

		$this->assertSame( 'Saddle: Cursor', $first['name'] );
		$this->assertSame( 'Saddle: Cursor 2', $second['name'], 'A repeat name must be suffixed, not rejected.' );
	}

	public function test_create_client_falls_back_to_a_default_name() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/clients' );
		$req->set_param( 'name', '' );

		$data = Saddle_REST_Admin::create_client( $req )->get_data();
		$this->assertStringStartsWith( 'Saddle: ', $data['name'] );
		$this->assertNotSame( 'Saddle: ', $data['name'] );
	}

	public function test_create_client_fails_cleanly_when_app_passwords_unavailable() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$req = new WP_REST_Request( 'POST', '/saddle/v1/clients' );
		$req->set_param( 'name', 'Claude Code' );
		$result = Saddle_REST_Admin::create_client( $req );

		remove_filter( 'wp_is_application_passwords_available', '__return_false' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_app_passwords_unavailable', $result->get_error_code() );
	}

	/* -------- connected clients listing -------- */

	public function test_clients_lists_only_saddle_prefixed_passwords() {
		$this->issue_password( 'Saddle: Claude' );
		$this->issue_password( 'Some Other Plugin' ); // must be excluded.

		$clients = Saddle_REST_Admin::get_clients()->get_data()['clients'];

		$names = wp_list_pluck( $clients, 'name' );
		$this->assertContains( 'Saddle: Claude', $names );
		$this->assertNotContains( 'Some Other Plugin', $names, 'Non-Saddle credentials must never be listed.' );

		// The human label strips the prefix.
		$labels = wp_list_pluck( $clients, 'label' );
		$this->assertContains( 'Claude', $labels );
	}

	/* -------- revoke: the security-critical property -------- */

	public function test_revoke_invalidates_the_credential() {
		list( $raw_password, $item ) = $this->issue_password( 'Saddle: Cursor' );

		// Before revoke: the credential authenticates the owning user.
		$authed = wp_authenticate_application_password( null, $this->login, $raw_password );
		$this->assertInstanceOf( 'WP_User', $authed, 'A freshly issued credential must authenticate.' );
		$this->assertSame( $this->admin, $authed->ID );

		// Revoke through Saddle's endpoint.
		$req = new WP_REST_Request( 'DELETE', '/saddle/v1/clients/' . $item['uuid'] );
		$req->set_param( 'uuid', $item['uuid'] );
		$result = Saddle_REST_Admin::revoke_client( $req );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result->get_data()['revoked'] );

		// After revoke: the same credential must no longer authenticate.
		$reauth = wp_authenticate_application_password( null, $this->login, $raw_password );
		$this->assertNotInstanceOf( 'WP_User', $reauth, 'A revoked credential must fail to authenticate.' );

		// And it disappears from the clients list.
		$names = wp_list_pluck( Saddle_REST_Admin::get_clients()->get_data()['clients'], 'name' );
		$this->assertNotContains( 'Saddle: Cursor', $names );
	}

	public function test_client_hint_is_last_four_captured_at_issuance() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/clients' );
		$req->set_param( 'name', 'Hinted App' );

		$data = Saddle_REST_Admin::create_client( $req )->get_data();
		$expected = substr( str_replace( ' ', '', $data['password'] ), -4 );

		$this->assertSame( $expected, $data['hint'], 'Create response must carry the last four of the raw key.' );

		// The clients list carries the same hint…
		$clients = Saddle_REST_Admin::get_clients()->get_data()['clients'];
		$row     = array_values( array_filter( $clients, fn( $c ) => $c['uuid'] === $data['uuid'] ) )[0];
		$this->assertSame( $expected, $row['hint'] );

		// …and never anything longer than four characters, anywhere.
		$this->assertSame( 4, strlen( $row['hint'] ) );
	}

	public function test_client_hint_is_null_for_credentials_issued_outside_saddle() {
		$this->issue_password( 'Saddle: Legacy App' );

		$clients = Saddle_REST_Admin::get_clients()->get_data()['clients'];
		$row     = array_values( array_filter( $clients, fn( $c ) => 'Saddle: Legacy App' === $c['name'] ) )[0];
		$this->assertNull( $row['hint'], 'Pre-existing credentials have no captured hint.' );
	}

	public function test_revoke_removes_the_stored_hint() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/clients' );
		$req->set_param( 'name', 'Short Lived' );
		$data = Saddle_REST_Admin::create_client( $req )->get_data();

		$hints = get_user_meta( $this->admin, 'saddle_client_hints', true );
		$this->assertArrayHasKey( $data['uuid'], $hints );

		$del = new WP_REST_Request( 'DELETE', '/saddle/v1/clients/' . $data['uuid'] );
		$del->set_param( 'uuid', $data['uuid'] );
		Saddle_REST_Admin::revoke_client( $del );

		$hints = get_user_meta( $this->admin, 'saddle_client_hints', true );
		$this->assertTrue( ! is_array( $hints ) || ! isset( $hints[ $data['uuid'] ] ), 'Revoke must drop the stored hint.' );
	}

	public function test_revoke_refuses_non_saddle_credentials() {
		list( , $item ) = $this->issue_password( 'Not A Saddle Client' );

		$req = new WP_REST_Request( 'DELETE', '/saddle/v1/clients/' . $item['uuid'] );
		$req->set_param( 'uuid', $item['uuid'] );
		$result = Saddle_REST_Admin::revoke_client( $req );

		$this->assertWPError( $result, 'Revoke must not delete credentials Saddle did not issue.' );
		$this->assertSame( 'saddle_client_not_found', $result->get_error_code() );

		// The non-Saddle password must still exist and still authenticate.
		$still = WP_Application_Passwords::get_user_application_password( $this->admin, $item['uuid'] );
		$this->assertNotEmpty( $still );
	}

	public function test_revoke_unknown_uuid_is_a_clean_404() {
		$req = new WP_REST_Request( 'DELETE', '/saddle/v1/clients/deadbeef' );
		$req->set_param( 'uuid', 'deadbeef-0000-0000-0000-000000000000' );
		$result = Saddle_REST_Admin::revoke_client( $req );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_client_not_found', $result->get_error_code() );
	}
}
