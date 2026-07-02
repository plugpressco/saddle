<?php
/**
 * REST layer for the capability catalog + per-ability disable toggle.
 *
 * @package Saddle
 */

class Saddle_REST_Abilities_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down() {
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		delete_option( Saddle_Capabilities::TIER_DOMAIN_OPTION );
		parent::tear_down();
	}

	public function test_capabilities_catalog_reports_enabled_state_per_ability() {
		Saddle_Capabilities::set_disabled_abilities( array( 'create-post' ) );

		$data = Saddle_REST_Admin::get_capabilities()->get_data();

		$this->assertSame( array( 'create-post' ), $data['disabled'] );

		$create = current( array_filter( $data['capabilities'], static fn( $c ) => 'create-post' === $c['short'] ) );
		$this->assertNotFalse( $create );
		$this->assertFalse( $create['enabled'] );

		$list = current( array_filter( $data['capabilities'], static fn( $c ) => 'list-posts' === $c['short'] ) );
		$this->assertNotFalse( $list );
		$this->assertTrue( $list['enabled'] );
	}

	public function test_update_disabled_abilities_persists_the_list() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/abilities' );
		$req->set_param( 'disabled', array( 'delete-media', 'delete-post' ) );

		$data = Saddle_REST_Admin::update_disabled_abilities( $req )->get_data();

		$this->assertSame( array( 'delete-media', 'delete-post' ), $data['disabled'] );
		$this->assertSame( array( 'delete-media', 'delete-post' ), Saddle_Capabilities::disabled_abilities() );
	}

	public function test_update_disabled_abilities_can_clear_the_list() {
		Saddle_Capabilities::set_disabled_abilities( array( 'delete-media' ) );

		$req = new WP_REST_Request( 'POST', '/saddle/v1/abilities' );
		$req->set_param( 'disabled', array() );

		Saddle_REST_Admin::update_disabled_abilities( $req );

		$this->assertSame( array(), Saddle_Capabilities::disabled_abilities() );
	}

	/* -------- pause + domain warning, surfaced via /settings -------- */

	public function test_settings_reports_paused_state() {
		Saddle_Capabilities::set_paused( true );

		$data = Saddle_REST_Admin::get_settings()->get_data();

		$this->assertTrue( $data['paused'] );
	}

	public function test_settings_post_can_toggle_paused() {
		// update_settings() reads changed fields via get_json_params(), which only
		// parses an actual JSON request body — set_param() alone doesn't populate it.
		$req = new WP_REST_Request( 'POST', '/saddle/v1/settings' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'paused' => true ) ) );

		$data = Saddle_REST_Admin::update_settings( $req )->get_data();

		$this->assertTrue( $data['paused'] );
		$this->assertTrue( Saddle_Capabilities::is_paused() );
	}

	public function test_settings_reports_domain_warning_after_mismatch() {
		Saddle_Capabilities::set_tier( 'write' );
		update_option( Saddle_Capabilities::TIER_DOMAIN_OPTION, 'old-domain.example.test' );

		$data = Saddle_REST_Admin::get_settings()->get_data();

		$this->assertTrue( $data['domain_warning'] );
		$this->assertSame( 'old-domain.example.test', $data['domain']['recorded'] );
	}

	public function test_settings_reports_no_domain_warning_when_matching() {
		Saddle_Capabilities::set_tier( 'write' );

		$data = Saddle_REST_Admin::get_settings()->get_data();

		$this->assertFalse( $data['domain_warning'] );
	}
}
