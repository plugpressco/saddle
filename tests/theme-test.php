<?php
/**
 * Admin theme preference — personal (user meta), validated, and clearable.
 *
 * @package Saddle
 */

class Saddle_Theme_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	private function post_settings( array $data ) {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/settings' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $data ) );
		return Saddle_REST_Admin::update_settings( $req );
	}

	public function test_theme_defaults_to_system_and_round_trips() {
		$this->assertSame( 'system', Saddle_REST_Admin::get_settings()->get_data()['theme'] );

		$res = $this->post_settings( array( 'theme' => 'dark' ) );
		$this->assertSame( 'dark', $res->get_data()['theme'] );
		$this->assertSame( 'dark', get_user_meta( $this->admin, 'saddle_admin_theme', true ) );
	}

	public function test_choosing_system_clears_the_stored_preference() {
		$this->post_settings( array( 'theme' => 'light' ) );
		$this->assertSame( 'light', get_user_meta( $this->admin, 'saddle_admin_theme', true ) );

		$res = $this->post_settings( array( 'theme' => 'system' ) );
		$this->assertSame( 'system', $res->get_data()['theme'] );
		$this->assertSame( '', get_user_meta( $this->admin, 'saddle_admin_theme', true ) );
	}

	public function test_theme_is_per_user_not_site_wide() {
		$this->post_settings( array( 'theme' => 'dark' ) );

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		$this->assertSame( 'system', Saddle_REST_Admin::get_settings()->get_data()['theme'] );
	}
}
