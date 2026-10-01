<?php
/**
 * The module registry and settings routes (K2, K3): `/modules`,
 * `/modules/{key}`, `/preferences/{scope}`, and that `/preferences` itself
 * still answers as it did, without the dead theme setting.
 *
 * @package Saddle
 */

class Saddle_Modules_REST_Test extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Test_Modules::register( array( 'demo', 'broken' ) );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_modules' );
		delete_option( 'demo_settings' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( 'saddle_memory_recent_limit' );
		parent::tear_down();
	}

	private function call( $method, $route, array $body = null ) {
		$request = new WP_REST_Request( $method, '/saddle/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	/* -------- the gate -------- */

	public function test_every_route_needs_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array(
			array( 'GET', '/modules' ),
			array( 'GET', '/modules/demo' ),
			array( 'GET', '/preferences/saddle' ),
			array( 'POST', '/preferences/demo' ),
		) as $case ) {
			$this->assertSame( 403, $this->call( $case[0], $case[1], array( 'values' => array() ) )->get_status(), $case[1] );
		}

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', '/modules' )->get_status() );
	}

	/* -------- /modules -------- */

	public function test_modules_lists_every_area_in_menu_order() {
		$response = $this->call( 'GET', '/modules' );

		$this->assertSame( 200, $response->get_status() );
		$areas = $response->get_data()['areas'];
		$this->assertSame( array( 'home', 'broken', 'demo', 'connections', 'services', 'context', 'settings' ), wp_list_pluck( $areas, 'key' ) );
	}

	public function test_a_module_area_has_the_documented_shape() {
		$area = $this->call( 'GET', '/modules/demo' )->get_data();

		$this->assertSame( array( 'key', 'kind', 'title', 'product', 'version', 'summary', 'admin_url', 'tabs', 'state', 'line', 'setup', 'tools', 'settings' ), array_keys( $area ) );
		$this->assertSame( 'module', $area['kind'] );
		$this->assertSame( 'A demo module.', $area['summary'] );
		$this->assertSame( 'ready', $area['state'] );
		$this->assertSame( 'All good', $area['line'] );
		$this->assertStringContainsString( 'page=saddle-demo', $area['admin_url'] );
		$this->assertSame( array( 'key', 'label', 'admin_url' ), array_keys( $area['tabs'][0] ) );
		$this->assertSame( array( 'overview', 'settings' ), wp_list_pluck( $area['tabs'], 'key' ) );

		$this->assertSame( 1, $area['setup']['done'] );
		$this->assertSame( 2, $area['setup']['total'] );
		$this->assertSame( array( 'id', 'title', 'done', 'line', 'waiting', 'after', 'action' ), array_keys( $area['setup']['tasks'][1] ) );
		$this->assertSame( array( 'label', 'url', 'external' ), array_keys( $area['setup']['tasks'][1]['action'] ) );

		$this->assertSame(
			array(
				'total'  => 0,
				'usable' => 0,
			),
			$area['tools']
		);
		$this->assertSame(
			array(
				'fields'         => 5,
				'agent_writable' => array( 'track_admins', 'limit', 'note' ),
			),
			$area['settings']
		);
	}

	public function test_core_areas_say_nothing_of_state_or_setup() {
		$areas = array_column( $this->call( 'GET', '/modules' )->get_data()['areas'], null, 'key' );

		foreach ( array( 'home', 'connections', 'context', 'settings' ) as $key ) {
			$this->assertSame( 'core', $areas[ $key ]['kind'], $key );
			$this->assertNull( $areas[ $key ]['state'], $key );
			$this->assertNull( $areas[ $key ]['setup'], $key );
			$this->assertNotSame( '', $areas[ $key ]['summary'], $key );
		}
		$this->assertSame( array( 'general' ), wp_list_pluck( $areas['settings']['tabs'], 'key' ) );
		$this->assertGreaterThan( 50, $areas['home']['tools']['total'], "Home counts Saddle's own tools" );
		$this->assertSame( 0, $areas['connections']['tools']['total'] );
	}

	public function test_a_broken_module_still_appears_without_its_extras() {
		$area = $this->call( 'GET', '/modules/broken' )->get_data();

		$this->assertNull( $area['state'] );
		$this->assertSame( '', $area['line'] );
		$this->assertNull( $area['setup'] );
		$this->assertNull( $area['settings'] );
	}

	public function test_an_unknown_module_is_a_404() {
		$response = $this->call( 'GET', '/modules/nothing' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'saddle_unknown_module', $response->get_data()['code'] );
	}

	/* -------- /preferences/{scope} -------- */

	public function test_get_returns_the_fields_with_their_values() {
		update_option( 'demo_settings', array( 'limit' => 9 ) );

		$body = $this->call( 'GET', '/preferences/demo' )->get_data();

		$this->assertSame( 'demo', $body['scope'] );
		$this->assertSame( 'Demo', $body['title'] );
		$limit = array_column( $body['fields'], null, 'key' )['limit'];
		$this->assertSame(
			array( 'key', 'type', 'minimum', 'maximum', 'default', 'label', 'help', 'level', 'agent', 'destructive', 'screen', 'section', 'control', 'admin_url', 'value' ),
			array_keys( $limit )
		);
		$this->assertSame( 9, $limit['value'] );
		$this->assertSame( 'demo/settings', $limit['screen'] );
		$this->assertSame( 'write', $limit['agent'] );
	}

	public function test_post_validates_stores_and_returns_the_same_body_as_get() {
		$response = $this->call( 'POST', '/preferences/demo', array( 'values' => array( 'limit' => 4, 'note' => 'HI' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 4, get_option( 'demo_settings' )['limit'] );
		$this->assertSame( 'hi', get_option( 'demo_settings' )['note'] );
		$this->assertSame( $this->call( 'GET', '/preferences/demo' )->get_data(), $response->get_data() );
	}

	public function test_the_owner_can_change_a_core_field_here() {
		$response = $this->call( 'POST', '/preferences/saddle', array( 'values' => array( 'tier' => 'write', 'memory_recent_limit' => 30 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'write', Saddle_Capabilities::get_site_tier() );
		$this->assertSame( 30, (int) get_option( 'saddle_memory_recent_limit' ) );
		$this->assertSame( 'write', array_column( $response->get_data()['fields'], null, 'key' )['tier']['value'] );
	}

	public function test_an_unknown_field_is_a_400_naming_it() {
		$response = $this->call( 'POST', '/preferences/demo', array( 'values' => array( 'bogus' => 1 ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'saddle_unknown_field', $response->get_data()['code'] );
		$this->assertSame( 'bogus', $response->get_data()['data']['field'] );
		$this->assertFalse( get_option( 'demo_settings' ) );
	}

	public function test_a_bad_value_is_a_400_and_nothing_is_saved() {
		$response = $this->call( 'POST', '/preferences/demo', array( 'values' => array( 'limit' => 99, 'note' => 'x' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'limit', $response->get_data()['data']['field'] );
		$this->assertFalse( get_option( 'demo_settings' ), 'the whole set is refused, not half of it' );
	}

	public function test_a_post_without_values_is_a_400() {
		$this->assertSame( 400, $this->call( 'POST', '/preferences/demo', array( 'limit' => 4 ) )->get_status() );
	}

	public function test_an_unknown_scope_or_one_without_settings_is_a_404() {
		foreach ( array( 'nothing', 'broken', 'home' ) as $scope ) {
			$this->assertSame( 404, $this->call( 'GET', '/preferences/' . $scope )->get_status(), $scope );
			$this->assertSame( 404, $this->call( 'POST', '/preferences/' . $scope, array( 'values' => array() ) )->get_status(), $scope );
		}
	}

	/* -------- the old routes -------- */

	public function test_preferences_without_a_scope_keeps_its_handler_and_loses_theme() {
		$response = $this->call( 'GET', '/preferences' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'read', $data['tier'] );
		$this->assertArrayHasKey( 'onboarded', $data );
		$this->assertArrayHasKey( 'drafts_only', $data );
		$this->assertArrayNotHasKey( 'theme', $data );

		$saved = $this->call( 'POST', '/preferences', array( 'tier' => 'write' ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'write', Saddle_Capabilities::get_site_tier() );

		// A theme param is no longer a thing; it is ignored, not stored.
		$this->call( 'POST', '/preferences', array( 'theme' => 'dark' ) );
		$this->assertSame( '', get_user_meta( get_current_user_id(), 'saddle_admin_theme', true ) );
	}

	public function test_the_oauth_and_memory_routes_still_work() {
		$this->assertSame( 200, $this->call( 'GET', '/oauth-settings' )->get_status() );

		$refused = $this->call( 'POST', '/oauth-settings', array( 'enabled' => true ) );
		$this->assertSame( 409, $refused->get_status(), 'the readiness check still gates turning sign-in on' );
		$this->assertSame( 'saddle_oauth_not_ready', $refused->get_data()['code'] );

		$this->assertSame( 200, $this->call( 'POST', '/memory-settings', array( 'core_budget' => 2000 ) )->get_status() );
		$this->assertSame( 2000, (int) get_option( Saddle_Memory::OPTION_CORE_BUDGET ) );
		delete_option( Saddle_Memory::OPTION_CORE_BUDGET );
	}
}
