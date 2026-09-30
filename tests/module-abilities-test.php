<?php
/**
 * saddle/list-modules, saddle/get-module-settings and
 * saddle/update-module-settings (K4): tiers, the approval gate, undo, and the
 * hard rule that no tool changes what an agent may do.
 *
 * @package Saddle
 */

class Saddle_Module_Abilities_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'admin' );
		Saddle_Test_Modules::register();
	}

	public function tear_down() {
		remove_all_filters( 'saddle_modules' );
		foreach ( array( 'demo_settings', 'kv_store', 'saddle_paused', Saddle_Capabilities::OPTION, Saddle_Unsplash::OPTION ) as $option ) {
			delete_option( $option );
		}
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		$ability = wp_get_ability( 'saddle/' . $name );
		$this->assertNotNull( $ability, $name );
		return $ability->execute( $input );
	}

	/** Preview then confirm. */
	private function confirmed( array $input ) {
		$preview = $this->run_ability( 'update-module-settings', $input );
		$this->assertIsArray( $preview );
		$this->assertTrue( $preview['requires_confirmation'] );
		return $this->run_ability( 'update-module-settings', $input + array( 'confirm_token' => $preview['confirm_token'] ) );
	}

	/* -------- saddle/list-modules -------- */

	public function test_list_modules_returns_the_areas_with_a_summary_each() {
		$result = $this->run_ability( 'list-modules' );

		$this->assertSame( array( 'areas' ), array_keys( $result ) );
		$keys = wp_list_pluck( $result['areas'], 'key' );
		$this->assertSame( 'home', $keys[0] );
		$this->assertContains( 'demo', $keys );
		foreach ( $result['areas'] as $area ) {
			$this->assertNotSame( '', $area['summary'], $area['key'] );
			$this->assertStringContainsString( 'page=saddle', $area['admin_url'] );
		}
		$this->assertSame( $result, json_decode( wp_json_encode( $result ), true ) );
	}

	public function test_list_modules_shows_which_settings_the_agent_may_change() {
		$demo = array_column( $this->run_ability( 'list-modules' )['areas'], null, 'key' )['demo'];

		$this->assertSame( array( 'track_admins', 'limit', 'note' ), $demo['settings']['agent_writable'] );
		$this->assertSame( 'ready', $demo['state'] );
	}

	public function test_the_read_tools_are_read_tier_and_read_only() {
		foreach ( array( 'list-modules', 'get-module-settings' ) as $name ) {
			$meta = wp_get_ability( 'saddle/' . $name )->get_meta();
			$this->assertSame( 'read', $meta['saddle']['tier'], $name );
			$this->assertTrue( $meta['annotations']['readonly'], $name );
			$this->assertFalse( $meta['annotations']['destructive'], $name );
			$this->assertFalse( $meta['annotations']['openWorldHint'], $name );
		}

		Saddle_Capabilities::set_tier( 'read' );
		$this->assertNotWPError( $this->run_ability( 'list-modules' ) );
		$this->assertNotWPError( $this->run_ability( 'get-module-settings', array( 'module' => 'saddle' ) ) );
	}

	public function test_the_read_tools_need_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'list-modules' ) );
		$this->assertWPError( $this->run_ability( 'get-module-settings', array( 'module' => 'saddle' ) ) );
	}

	/* -------- saddle/get-module-settings -------- */

	public function test_get_module_settings_returns_the_preferences_body() {
		$body = $this->run_ability( 'get-module-settings', array( 'module' => 'demo' ) );

		$this->assertSame( array( 'scope', 'title', 'fields' ), array_keys( $body ) );
		$this->assertSame( 'demo', $body['scope'] );
		$this->assertSame( 5, array_column( $body['fields'], null, 'key' )['limit']['value'] );

		$rest = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/preferences/demo' ) )->get_data();
		$this->assertSame( $rest, $body, 'the tool and the route tell the same thing' );
	}

	public function test_get_module_settings_covers_saddles_own_and_never_a_secret() {
		update_option( Saddle_Unsplash::OPTION, 'abcdefghijklmnopqrst4f2a' );

		$body = $this->run_ability( 'get-module-settings', array( 'module' => 'saddle' ) );

		$this->assertCount( 14, $body['fields'] );
		$this->assertSame( array( 'read' ), array_values( array_unique( wp_list_pluck( $body['fields'], 'agent' ) ) ) );
		$this->assertStringNotContainsString( 'abcdefghijklmnopqrst', wp_json_encode( $body ) );
	}

	public function test_get_module_settings_refuses_an_unknown_module() {
		foreach ( array( 'nothing', 'broken', '' ) as $module ) {
			$result = $this->run_ability( 'get-module-settings', array( 'module' => $module ) );
			$this->assertWPError( $result, $module );
			$this->assertSame( 'saddle_unknown_module', $result->get_error_code() );
		}
	}

	/* -------- saddle/update-module-settings: tier and gate -------- */

	public function test_update_is_an_admin_tier_destructive_tool() {
		$meta = wp_get_ability( 'saddle/update-module-settings' )->get_meta();

		$this->assertSame( 'admin', $meta['saddle']['tier'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['readonly'] );

		Saddle_Capabilities::set_tier( 'write' );
		$this->assertWPError( $this->run_ability( 'update-module-settings', array( 'module' => 'demo', 'values' => array( 'limit' => 3 ) ) ) );
		$this->assertFalse( get_option( 'demo_settings' ) );
	}

	public function test_update_needs_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'update-module-settings', array( 'module' => 'demo', 'values' => array( 'limit' => 3 ) ) ) );
	}

	public function test_without_a_token_it_previews_in_words_and_changes_nothing() {
		update_option( 'demo_settings', array( 'limit' => 5 ) );

		$preview = $this->run_ability( 'update-module-settings', array( 'module' => 'demo', 'values' => array( 'limit' => 8, 'track_admins' => true ) ) );

		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertNotEmpty( $preview['confirm_token'] );
		$this->assertSame( 'update-module-settings', $preview['action'] );
		$this->assertSame( 'Change Demo settings: Limit from 5 to 8; Count admin visits from off to on.', $preview['summary'] );
		$this->assertSame(
			array(
				array(
					'key'   => 'limit',
					'label' => 'Limit',
					'from'  => '5',
					'to'    => '8',
				),
				array(
					'key'   => 'track_admins',
					'label' => 'Count admin visits',
					'from'  => 'off',
					'to'    => 'on',
				),
			),
			$preview['preview']['changes']
		);
		$this->assertSame( array( 'limit' => 5 ), get_option( 'demo_settings' ) );
	}

	public function test_a_valid_token_applies_it_once_and_a_reused_token_fails() {
		$input   = array( 'module' => 'demo', 'values' => array( 'limit' => 8 ) );
		$preview = $this->run_ability( 'update-module-settings', $input );
		$with    = $input + array( 'confirm_token' => $preview['confirm_token'] );

		$done = $this->run_ability( 'update-module-settings', $with );

		$this->assertNotWPError( $done );
		$this->assertSame( array( 'updated', 'module', 'changes', 'undoable', 'settings' ), array_keys( $done ) );
		$this->assertTrue( $done['updated'] );
		$this->assertTrue( $done['undoable'] );
		$this->assertSame( 8, get_option( 'demo_settings' )['limit'] );
		$this->assertSame( 8, array_column( $done['settings']['fields'], null, 'key' )['limit']['value'] );

		update_option( 'demo_settings', array( 'limit' => 2 ) );
		$this->assertWPError( $this->run_ability( 'update-module-settings', $with ), 'a token is single use' );
		$this->assertSame( 2, get_option( 'demo_settings' )['limit'] );
	}

	public function test_a_token_does_not_confirm_different_values() {
		$preview = $this->run_ability( 'update-module-settings', array( 'module' => 'demo', 'values' => array( 'limit' => 8 ) ) );

		$result = $this->run_ability( 'update-module-settings', array( 'module' => 'demo', 'values' => array( 'limit' => 9 ), 'confirm_token' => $preview['confirm_token'] ) );

		$this->assertWPError( $result );
		$this->assertFalse( get_option( 'demo_settings' ) );
	}

	public function test_invalid_input_is_refused_before_any_preview() {
		foreach ( array(
			array( 'module' => 'demo', 'values' => array( 'limit' => 99 ) ),
			array( 'module' => 'demo', 'values' => array( 'bogus' => 1 ) ),
			array( 'module' => 'demo', 'values' => array() ),
			array( 'module' => 'nothing', 'values' => array( 'limit' => 1 ) ),
		) as $input ) {
			$result = $this->run_ability( 'update-module-settings', $input );
			$this->assertWPError( $result, wp_json_encode( $input ) );
			$this->assertArrayNotHasKey( 'requires_confirmation', (array) $result );
		}
	}

	public function test_a_field_the_owner_keeps_is_refused_with_a_link() {
		$result = $this->run_ability( 'update-module-settings', array( 'module' => 'demo', 'values' => array( 'mode' => 'b' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_setting_owner_only', $result->get_error_code() );
		$this->assertSame( 'mode', $result->get_error_data()['field'] );
		$this->assertStringContainsString( 'page=saddle-demo&tab=settings#saddle-field-demo-mode', $result->get_error_data()['admin_url'] );
		$this->assertStringContainsString( 'Mode', $result->get_error_message() );
		$this->assertStringContainsString( $result->get_error_data()['admin_url'], $result->get_error_message() );
	}

	public function test_a_secret_is_owner_only_and_a_get_set_store_is_written_through_its_callables() {
		$refused = $this->run_ability( 'update-module-settings', array( 'module' => 'demo', 'values' => array( 'api_key' => 'x' ) ) );
		$this->assertSame( 'saddle_setting_owner_only', $refused->get_error_code() );

		$done = $this->confirmed( array( 'module' => 'kv', 'values' => array( 'greeting' => 'hello' ) ) );
		$this->assertNotWPError( $done );
		$this->assertSame( array( 'greeting' => 'hello' ), get_option( 'kv_store' ) );
	}

	/* -------- undo -------- */

	public function test_the_change_is_logged_and_undone_by_undo_changes() {
		update_option( 'demo_settings', array( 'limit' => 5, 'track_admins' => false ) );

		$this->assertNotWPError( $this->confirmed( array( 'module' => 'demo', 'values' => array( 'limit' => 8, 'track_admins' => true ) ) ) );
		$this->assertSame( 8, get_option( 'demo_settings' )['limit'] );

		$changes = $this->run_ability( 'recall-changes', array( 'limit' => 1 ) );
		$entry   = $changes['changes'][0];
		$this->assertSame( 'available', $entry['undo'] );

		$preview = $this->run_ability( 'undo-changes', array( 'entries' => array( $entry['id'] ) ) );
		$undone  = $this->run_ability( 'undo-changes', array( 'entries' => array( $entry['id'] ), 'confirm_token' => $preview['confirm_token'] ) );

		$this->assertSame( 1, $undone['undone'] );
		$this->assertSame( array( 'limit' => 5, 'track_admins' => false ), get_option( 'demo_settings' ) );
	}

	public function test_a_get_set_store_is_undone_through_the_options_it_declared() {
		update_option( 'kv_store', array( 'greeting' => 'hi' ) );

		$this->assertNotWPError( $this->confirmed( array( 'module' => 'kv', 'values' => array( 'greeting' => 'yo' ) ) ) );
		$entry = $this->run_ability( 'recall-changes', array( 'limit' => 1 ) )['changes'][0];
		$this->assertSame( 'available', $entry['undo'] );

		$preview = $this->run_ability( 'undo-changes', array( 'entries' => array( $entry['id'] ) ) );
		$this->run_ability( 'undo-changes', array( 'entries' => array( $entry['id'] ), 'confirm_token' => $preview['confirm_token'] ) );

		$this->assertSame( array( 'greeting' => 'hi' ), get_option( 'kv_store' ) );
	}

	/* -------- the hard rule -------- */

	public function test_no_core_field_can_be_changed_by_a_tool() {
		$values = array(
			'tier'                    => 'admin',
			'drafts_only'             => true,
			'rehearsal'               => true,
			'paused'                  => true,
			'oauth_enabled'           => true,
			'oauth_dcr'               => false,
			'oauth_cimd'              => false,
			'unsplash_key'            => 'abcdefghijklmnopqrstuvwx',
			'memory_autoinject_agent' => true,
			'enforce_tier_domain'     => true,
			'memory_max_entries'      => 20,
			'memory_core_budget'      => 300,
			'memory_recent_changes'   => false,
			'memory_recent_limit'     => 3,
		);
		$this->assertCount( 14, $values );

		foreach ( $values as $key => $value ) {
			$result = $this->run_ability( 'update-module-settings', array( 'module' => 'saddle', 'values' => array( $key => $value ) ) );
			$this->assertWPError( $result, $key );
			$this->assertSame( 'saddle_setting_owner_only', $result->get_error_code(), $key );
			$this->assertStringContainsString( 'page=saddle', $result->get_error_data()['admin_url'], $key );
		}

		$this->assertSame( 'admin', Saddle_Capabilities::get_site_tier(), 'still the tier this test set up' );
		$this->assertFalse( Saddle_Capabilities::is_paused() );
		$this->assertFalse( Saddle_Capabilities::is_rehearsal() );
		$this->assertFalse( Saddle_Capabilities::is_drafts_only() );
	}

	public function test_a_malicious_module_cannot_point_a_writable_field_at_the_tier() {
		Saddle_Capabilities::set_tier( 'admin' );

		$result = $this->run_ability( 'update-module-settings', array( 'module' => 'evil', 'values' => array( 'tier' => 'admin' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_setting_owner_only', $result->get_error_code() );

		// Not even a preview: there is no token to confirm.
		$this->assertArrayNotHasKey( 'confirm_token', (array) $result->get_error_data() );

		// And the listing does not offer it to the agent either.
		$evil = array_column( $this->run_ability( 'list-modules' )['areas'], null, 'key' )['evil'];
		$this->assertSame( array(), $evil['settings']['agent_writable'] );
	}

	public function test_a_protected_option_is_put_back_if_a_modules_setter_touches_it() {
		$this->assertFalse( Saddle_Capabilities::is_paused() );

		$result = $this->confirmed( array( 'module' => 'sneaky', 'values' => array( 'go' => true ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_protected_option', $result->get_error_code() );
		$this->assertFalse( Saddle_Capabilities::is_paused(), 'the protected option is restored' );
		$this->assertFalse( get_option( 'saddle_paused', false ) );
	}

	public function test_every_protected_option_is_named_in_one_place() {
		foreach ( array( 'saddle_access_tier', 'saddle_paused', 'saddle_rehearsal', 'saddle_drafts_only', 'saddle_enabled_integrations', 'saddle_disabled_abilities', 'saddle_tier_domain', 'saddle_enforce_tier_domain', 'saddle_oauth_enabled', 'saddle_oauth_anything' ) as $name ) {
			$this->assertTrue( Saddle_Settings_Guard::is_protected_option( $name ), $name );
		}
		$this->assertFalse( Saddle_Settings_Guard::is_protected_option( 'demo_settings' ) );
	}

	/* -------- context -------- */

	public function test_the_context_names_the_owners_pages() {
		$context = Saddle_Context::system_context();

		$this->assertStringContainsString( "# The owner's admin", $context );
		$this->assertStringContainsString( "Owner's admin: Saddle → Dashboard (Activity tab), ", $context );
		$this->assertStringContainsString( 'Demo', $context );
		$this->assertStringContainsString( 'Apps, Permissions, Context, Settings.', $context );
		$this->assertStringContainsString( 'call saddle-get-module-settings and give the owner the admin_url instead of describing clicks.', $context );
	}
}
