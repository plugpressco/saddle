<?php
/**
 * The oauth-settings admin endpoint — the switch the connect wizard flips.
 *
 * What this pins: enabling refuses (409, with a reason the wizard can show
 * verbatim) until the site can actually host an authorization server; the
 * stored option round-trips through GET; and disabling purges every grant —
 * "off" means off, never live tokens waiting for the switch to come back.
 *
 * Unlike the other OAuth suites this one does NOT force the
 * saddle_oauth_enabled filter: the endpoint under test drives the real
 * option, and masking it would fake every assertion here.
 *
 * @package Saddle
 */

class Saddle_OAuth_Settings_Test extends WP_UnitTestCase {

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		update_option( 'permalink_structure', '/%postname%/' );
		Saddle_OAuth_Store::register_cpt();
	}

	public function tear_down() {
		delete_option( 'permalink_structure' );
		delete_option( 'saddle_oauth_enabled' );
		parent::tear_down();
	}

	private function post_settings( array $params ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth-settings' );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	private function get_settings() {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/oauth-settings' ) );
	}

	public function test_enabling_refuses_without_https_and_names_the_reason() {
		// Pretty permalinks are set, so the blocker is SSL — the message must
		// name it, because ChatGPT's side of the failure says nothing useful.
		$response = $this->post_settings( array( 'enabled' => true ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'saddle_oauth_not_ready', $response->get_data()['code'] );
		$this->assertStringContainsString( 'HTTPS', $response->get_data()['message'] );
		$this->assertFalse( Saddle_OAuth::is_enabled(), 'a refused enable must not half-apply' );
	}

	public function test_enabling_refuses_on_plain_permalinks_and_names_the_reason() {
		delete_option( 'permalink_structure' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );

		$response = $this->post_settings( array( 'enabled' => true ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'saddle_oauth_not_ready', $response->get_data()['code'] );
		$this->assertStringContainsString( 'permalinks', strtolower( $response->get_data()['message'] ) );
	}

	public function test_enabling_succeeds_when_ready_and_round_trips_through_get() {
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );

		$response = $this->post_settings( array( 'enabled' => true ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['enabled'] );
		$this->assertTrue( Saddle_OAuth::is_enabled() );
		$this->assertTrue( $this->get_settings()->get_data()['enabled'] );
	}

	public function test_disabling_purges_every_grant() {
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		$this->post_settings( array( 'enabled' => true ) );

		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => str_repeat( 'ab', 16 ),
				'client_id'   => 'https://client.example/id.json',
				'client_name' => 'Test App',
				'user_id'     => $this->admin,
				'scope'       => 'saddle:read',
				'resource'    => Saddle_OAuth::resource_id(),
			)
		);
		$this->assertNotEmpty( Saddle_OAuth_Store::list_grants() );

		$response = $this->post_settings( array( 'enabled' => false ) );

		// "Off" must mean off — no live tokens waiting for the switch to
		// come back on.
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['enabled'] );
		$this->assertSame( array(), Saddle_OAuth_Store::list_grants() );
	}

	public function test_the_endpoint_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->get_settings()->get_status() );
		$this->assertSame( 403, $this->post_settings( array( 'enabled' => true ) )->get_status() );
	}
}
