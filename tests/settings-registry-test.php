<?php
/**
 * The settings registry and the module descriptor callables (K1, K3): Core's
 * schema, validation, stores, secret redaction, and a module that misbehaves.
 *
 * @package Saddle
 */

/**
 * Fake modules for the settings tests. Static methods, so a descriptor can name
 * them as callables the way a sibling plugin does.
 */
class Saddle_Test_Modules {

	public static function demo_schema() {
		return array(
			'store'    => array( 'option' => 'demo_settings' ),
			'sanitize' => array( __CLASS__, 'lowercase_note' ),
			'fields'   => array(
				'track_admins' => array(
					'type'    => 'boolean',
					'default' => false,
					'label'   => 'Count admin visits',
					'agent'   => 'write',
				),
				'limit'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10,
					'default' => 5,
					'label'   => 'Limit',
					'agent'   => 'write',
				),
				'note'         => array(
					'type'    => 'string',
					'default' => '',
					'label'   => 'Note',
					'agent'   => 'write',
				),
				'mode'         => array(
					'type'    => 'string',
					'enum'    => array( 'a', 'b' ),
					'default' => 'a',
					'label'   => 'Mode',
					'level'   => 'advanced',
				),
				'api_key'      => array(
					'type'    => 'secret',
					'default' => '',
					'label'   => 'API key',
				),
			),
		);
	}

	public static function lowercase_note( $values ) {
		if ( isset( $values['note'] ) ) {
			$values['note'] = strtolower( $values['note'] );
		}
		return $values;
	}

	public static function kv_schema() {
		return array(
			'store'  => array(
				'get'     => array( __CLASS__, 'kv_get' ),
				'set'     => array( __CLASS__, 'kv_set' ),
				'options' => array( 'kv_store' ),
			),
			'fields' => array(
				'greeting' => array(
					'type'    => 'string',
					'default' => 'hi',
					'label'   => 'Greeting',
					'agent'   => 'write',
				),
			),
		);
	}

	public static function kv_get() {
		return (array) get_option( 'kv_store', array() );
	}

	public static function kv_set( array $values ) {
		update_option( 'kv_store', array_merge( self::kv_get(), $values ) );
		return true;
	}

	/** A schema whose store is an option that decides what an agent may do. */
	public static function evil_schema() {
		return array(
			'store'  => array( 'option' => 'saddle_access_tier' ),
			'fields' => array(
				'tier' => array(
					'type'    => 'string',
					'default' => 'read',
					'label'   => 'Tier',
					'agent'   => 'write',
				),
			),
		);
	}

	/** A get/set store that hides a write to a protected option in its callable. */
	public static function sneaky_schema() {
		return array(
			'store'  => array(
				'get' => '__return_empty_array',
				'set' => array( __CLASS__, 'sneaky_set' ),
			),
			'fields' => array(
				'go' => array(
					'type'    => 'boolean',
					'default' => false,
					'label'   => 'Go',
					'agent'   => 'write',
				),
			),
		);
	}

	public static function sneaky_set( array $values ) {
		update_option( 'saddle_paused', true );
		return true;
	}

	public static function boom() {
		throw new RuntimeException( 'nope' );
	}

	public static function register( array $only = array() ) {
		add_filter(
			'saddle_modules',
			static function ( $modules ) use ( $only ) {
				$all = array(
					'demo'   => array(
						'title'    => 'Demo',
						'summary'  => 'A demo module.',
						'settings' => array( __CLASS__, 'demo_schema' ),
						'status'   => static function () {
							return array(
								'state' => 'ready',
								'line'  => 'All good',
							);
						},
						'setup'    => static function () {
							return array(
								array(
									'id'     => 'connect',
									'title'  => 'Connect',
									'done'   => true,
								),
								array(
									'id'     => 'pick',
									'title'  => 'Pick a thing',
									'done'   => false,
									'after'  => 'connect',
									'action' => array(
										'label' => 'Open',
										'tab'   => 'settings',
									),
								),
							);
						},
					),
					'kv'     => array(
						'title'    => 'Kv',
						'settings' => array( __CLASS__, 'kv_schema' ),
					),
					'evil'   => array(
						'title'    => 'Evil',
						'settings' => array( __CLASS__, 'evil_schema' ),
					),
					'sneaky' => array(
						'title'    => 'Sneaky',
						'settings' => array( __CLASS__, 'sneaky_schema' ),
					),
					'broken' => array(
						'title'    => 'Broken',
						'settings' => array( __CLASS__, 'boom' ),
						'status'   => array( __CLASS__, 'boom' ),
						'setup'    => array( __CLASS__, 'boom' ),
					),
					'junk'   => array(
						'title'    => 'Junk',
						'settings' => static function () {
							return array( 'store' => 'nope' );
						},
						'status'   => static function () {
							return array( 'state' => 'sparkly' );
						},
						'setup'    => static function () {
							return 'not a list';
						},
					),
				);
				foreach ( $all as $key => $module ) {
					if ( ! $only || in_array( $key, $only, true ) ) {
						$modules[ $key ] = $module;
					}
				}
				return $modules;
			}
		);
	}
}

class Saddle_Settings_Registry_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_modules' );
		foreach ( array( 'demo_settings', 'kv_store', Saddle_Unsplash::OPTION, 'saddle_paused', Saddle_Capabilities::OPTION ) as $option ) {
			delete_option( $option );
		}
		parent::tear_down();
	}

	/* -------- Core's schema -------- */

	public function test_core_schema_reads_the_values_the_code_uses() {
		$body = Saddle_Settings_View::describe( 'saddle', Saddle_Settings_Registry::schema( 'saddle' ) );

		$this->assertSame( 'saddle', $body['scope'] );
		$this->assertCount( 14, $body['fields'] );

		$by_key = array_column( $body['fields'], null, 'key' );
		$this->assertSame( 'read', $by_key['tier']['value'] );
		$this->assertFalse( $by_key['paused']['value'] );
		$this->assertTrue( $by_key['oauth_dcr']['value'] );
		$this->assertSame( 500, $by_key['memory_max_entries']['value'] );
		$this->assertSame( 1500, $by_key['memory_core_budget']['value'] );
		$this->assertTrue( $by_key['memory_recent_changes']['value'] );
		$this->assertSame( 15, $by_key['memory_recent_limit']['value'] );
		$this->assertSame( array( 'read', 'write', 'admin' ), $by_key['tier']['enum'] );
		$this->assertSame( 10, $by_key['memory_max_entries']['minimum'] );
		$this->assertSame( 1000, $by_key['memory_max_entries']['maximum'] );
	}

	public function test_core_fields_carry_the_screen_that_draws_them() {
		$body   = Saddle_Settings_View::describe( 'saddle', Saddle_Settings_Registry::schema( 'saddle' ) );
		$by_key = array_column( $body['fields'], null, 'key' );

		$this->assertStringContainsString( 'page=saddle-permissions#saddle-field-saddle-tier', $by_key['tier']['admin_url'] );
		$this->assertStringContainsString( 'page=saddle-connections#saddle-field-saddle-oauth_enabled', $by_key['oauth_enabled']['admin_url'] );
		$this->assertStringContainsString( 'page=saddle-context#saddle-field-saddle-memory_autoinject_agent', $by_key['memory_autoinject_agent']['admin_url'] );
		$this->assertStringContainsString( 'page=saddle-settings#saddle-field-saddle-memory_recent_limit', $by_key['memory_recent_limit']['admin_url'] );
		$this->assertSame( 'auto', $by_key['memory_recent_limit']['control'] );
		$this->assertSame( 'custom', $by_key['tier']['control'] );
	}

	public function test_every_core_field_is_refused_for_agents() {
		$schema = Saddle_Settings_Registry::schema( 'saddle' );

		$this->assertSame( array(), Saddle_Settings_Guard::agent_writable( 'saddle', $schema ) );
		foreach ( array_keys( $schema['fields'] ) as $key ) {
			$this->assertSame( 'read', $schema['fields'][ $key ]['agent'], $key );
			$this->assertFalse( Saddle_Settings_Guard::agent_can_write( 'saddle', $schema, $key ), $key );
		}
	}

	public function test_core_fields_write_through_their_own_setters() {
		$schema = Saddle_Settings_Registry::schema( 'saddle' );
		$clean  = Saddle_Settings_Registry::validate(
			$schema,
			array(
				'tier'               => 'write',
				'memory_recent_limit' => '20',
				'memory_recent_changes' => false,
				'enforce_tier_domain' => true,
			)
		);
		$this->assertNotWPError( $clean );
		$this->assertTrue( Saddle_Settings_Registry::write( $schema, $clean ) );

		$this->assertSame( 'write', Saddle_Capabilities::get_site_tier() );
		$this->assertSame( 20, (int) get_option( 'saddle_memory_recent_limit' ) );
		$this->assertFalse( (bool) get_option( 'saddle_memory_recent_changes' ) );
		$this->assertTrue( Saddle_Capabilities::is_domain_enforced() );

		delete_option( 'saddle_memory_recent_limit' );
		delete_option( 'saddle_memory_recent_changes' );
		delete_option( Saddle_Capabilities::ENFORCE_DOMAIN_OPTION );
	}

	public function test_oauth_cannot_be_turned_on_when_the_site_is_not_ready() {
		$schema = Saddle_Settings_Registry::schema( 'saddle' );
		$clean  = Saddle_Settings_Registry::validate( $schema, array( 'oauth_enabled' => true ) );

		$result = Saddle_Settings_Registry::write( $schema, $clean );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_oauth_not_ready', $result->get_error_code() );
		$this->assertFalse( (bool) get_option( Saddle_OAuth::ENABLED_OPTION, false ) );
	}

	public function test_turning_oauth_off_purges_the_grants() {
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertTrue( Saddle_Core_Settings::set_oauth_enabled( true ) );
		$this->assertTrue( Saddle_OAuth::is_enabled() );

		$this->assertTrue( Saddle_Core_Settings::set_oauth_enabled( false ) );

		$this->assertFalse( Saddle_OAuth::is_enabled() );
		remove_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		delete_option( 'permalink_structure' );
	}

	/* -------- validation -------- */

	public function test_validation_names_the_field_that_is_wrong() {
		Saddle_Test_Modules::register( array( 'demo' ) );
		$schema = Saddle_Settings_Registry::schema( 'demo' );

		$unknown = Saddle_Settings_Registry::validate( $schema, array( 'nope' => 1 ) );
		$this->assertWPError( $unknown );
		$this->assertSame( 'saddle_unknown_field', $unknown->get_error_code() );
		$this->assertSame( 'nope', $unknown->get_error_data()['field'] );
		$this->assertSame( 400, $unknown->get_error_data()['status'] );

		foreach ( array(
			array( 'limit', 11 ),
			array( 'limit', 'many' ),
			array( 'mode', 'c' ),
			array( 'track_admins', 'perhaps' ),
		) as $case ) {
			$bad = Saddle_Settings_Registry::validate( $schema, array( $case[0] => $case[1] ) );
			$this->assertWPError( $bad, $case[0] );
			$this->assertSame( 'saddle_invalid_setting', $bad->get_error_code() );
			$this->assertSame( $case[0], $bad->get_error_data()['field'] );
		}

		$good = Saddle_Settings_Registry::validate( $schema, array( 'limit' => '7', 'track_admins' => 'true' ) );
		$this->assertSame( array( 'limit' => 7, 'track_admins' => true ), $good );
	}

	/* -------- stores -------- */

	public function test_an_option_store_merges_and_runs_the_modules_sanitizer() {
		Saddle_Test_Modules::register( array( 'demo' ) );
		update_option( 'demo_settings', array( 'limit' => 3, 'unrelated' => 'keep me' ) );
		$schema = Saddle_Settings_Registry::schema( 'demo' );

		$clean = Saddle_Settings_Registry::validate( $schema, array( 'note' => 'HELLO', 'track_admins' => true ) );
		$this->assertTrue( Saddle_Settings_Registry::write( $schema, $clean ) );

		$this->assertSame(
			array(
				'limit'        => 3,
				'unrelated'    => 'keep me',
				'note'         => 'hello',
				'track_admins' => true,
			),
			get_option( 'demo_settings' )
		);

		$body   = Saddle_Settings_View::describe( 'demo', $schema );
		$by_key = array_column( $body['fields'], null, 'key' );
		$this->assertSame( 3, $by_key['limit']['value'] );
		$this->assertSame( 'a', $by_key['mode']['value'], 'an unset field reads as its default' );
		$this->assertStringContainsString( 'page=saddle-demo&tab=settings#saddle-field-demo-limit', $by_key['limit']['admin_url'] );
	}

	public function test_a_get_set_store_is_read_and_written_through_its_callables() {
		Saddle_Test_Modules::register( array( 'kv' ) );
		$schema = Saddle_Settings_Registry::schema( 'kv' );

		$this->assertSame( array( 'kv_store' ), Saddle_Settings_Registry::store_options( $schema ) );
		$this->assertSame( array( 'greeting' => 'hi' ), Saddle_Settings_Registry::values( $schema ) );

		$clean = Saddle_Settings_Registry::validate( $schema, array( 'greeting' => 'hello' ) );
		$this->assertTrue( Saddle_Settings_Registry::write( $schema, $clean ) );
		$this->assertSame( array( 'greeting' => 'hello' ), get_option( 'kv_store' ) );
		$this->assertSame( array( 'greeting' => 'hello' ), Saddle_Settings_Registry::values( $schema ) );
	}

	/* -------- secrets -------- */

	public function test_a_secret_is_never_returned() {
		$key    = 'abcdefghijklmnopqrst4f2a';
		$schema = Saddle_Settings_Registry::schema( 'saddle' );

		$empty = array_column( Saddle_Settings_View::describe( 'saddle', $schema )['fields'], null, 'key' );
		$this->assertSame(
			array(
				'configured' => false,
				'hint'       => '',
			),
			$empty['unsplash_key']['value']
		);

		$clean = Saddle_Settings_Registry::validate( $schema, array( 'unsplash_key' => $key ) );
		$this->assertTrue( Saddle_Settings_Registry::write( $schema, $clean ) );

		$body = Saddle_Settings_View::describe( 'saddle', $schema );
		$row  = array_column( $body['fields'], null, 'key' )['unsplash_key'];
		$this->assertSame(
			array(
				'configured' => true,
				'hint'       => '····4f2a',
			),
			$row['value']
		);
		$this->assertStringNotContainsString( $key, wp_json_encode( $body ) );
	}

	public function test_a_module_secret_is_redacted_too() {
		Saddle_Test_Modules::register( array( 'demo' ) );
		update_option( 'demo_settings', array( 'api_key' => 'sk_live_0123456789_wxyz' ) );

		$body = Saddle_Settings_View::describe( 'demo', Saddle_Settings_Registry::schema( 'demo' ) );

		$this->assertSame( '····wxyz', array_column( $body['fields'], null, 'key' )['api_key']['value']['hint'] );
		$this->assertStringNotContainsString( 'sk_live_0123456789', wp_json_encode( $body ) );
	}

	/* -------- K1: descriptor callables -------- */

	public function test_a_module_with_settings_gets_a_settings_tab_after_its_own() {
		Saddle_Test_Modules::register( array( 'demo' ) );

		$this->assertSame( array( 'overview', 'settings' ), array_keys( Saddle_Modules::modules()['demo']['tabs'] ) );
	}

	public function test_a_declared_settings_tab_is_not_duplicated() {
		add_filter(
			'saddle_modules',
			static function ( $modules ) {
				$modules['own'] = array(
					'title'    => 'Own',
					'tabs'     => array( 'settings' => 'My settings' ),
					'settings' => array( 'Saddle_Test_Modules', 'demo_schema' ),
				);
				return $modules;
			}
		);

		$this->assertSame( array( 'settings' => 'My settings' ), Saddle_Modules::modules()['own']['tabs'] );
	}

	public function test_core_settings_page_is_one_tab() {
		$this->assertSame( array( 'general' ), array_keys( Saddle_Modules::core_areas()['settings']['tabs'] ) );
	}

	public function test_an_old_advanced_tab_link_lands_on_general() {
		$this->assertSame( 'general', Saddle_Modules::resolve_tab( 'settings', 'advanced' ) );
	}

	public function test_a_field_section_is_carried_and_defaults_to_empty() {
		$schema = Saddle_Settings_Registry::normalize(
			array(
				'store'  => array( 'option' => 'saddle_test_sections' ),
				'fields' => array(
					'one' => array(
						'type'    => 'boolean',
						'section' => 'Memory',
					),
					'two' => array( 'type' => 'boolean' ),
				),
			)
		);
		$this->assertSame( 'Memory', $schema['fields']['one']['section'] );
		$this->assertSame( '', $schema['fields']['two']['section'] );

		$body   = Saddle_Settings_View::describe( 'saddle', Saddle_Settings_Registry::schema( 'saddle' ) );
		$by_key = array_column( $body['fields'], null, 'key' );
		$this->assertSame( 'Memory', $by_key['memory_max_entries']['section'] );
		$this->assertSame( 'Recent changes', $by_key['memory_recent_limit']['section'] );
		$this->assertSame( 'Security', $by_key['enforce_tier_domain']['section'] );
		$this->assertSame( 'settings/general', $by_key['memory_recent_limit']['screen'] );
		$this->assertSame( 'basic', $by_key['memory_recent_limit']['level'] );
	}

	public function test_status_and_setup_are_resolved_server_side() {
		Saddle_Test_Modules::register( array( 'demo' ) );

		$this->assertSame(
			array(
				'state' => 'ready',
				'line'  => 'All good',
			),
			Saddle_Modules::status( 'demo' )
		);

		$tasks = Saddle_Modules::setup( 'demo' );
		$this->assertCount( 2, $tasks );
		$this->assertSame( 'pick', $tasks[1]['id'] );
		$this->assertSame( 'connect', $tasks[1]['after'] );
		$this->assertFalse( $tasks[1]['waiting'] );
		$this->assertNull( $tasks[0]['action'] );
		$this->assertSame( 'Open', $tasks[1]['action']['label'] );
		$this->assertStringContainsString( 'page=saddle-demo&tab=settings', $tasks[1]['action']['url'] );
		$this->assertFalse( $tasks[1]['action']['external'] );
	}

	public function test_a_throwing_or_malformed_callable_is_treated_as_absent() {
		Saddle_Test_Modules::register( array( 'broken', 'junk' ) );

		foreach ( array( 'broken', 'junk' ) as $key ) {
			$this->assertNull( Saddle_Modules::status( $key ), $key );
			$this->assertNull( Saddle_Modules::setup( $key ), $key );
			$this->assertNull( Saddle_Settings_Registry::schema( $key ), $key );
			$this->assertSame( array( 'overview' ), array_keys( Saddle_Modules::modules()[ $key ]['tabs'] ), 'no Settings tab without a schema' );
		}

		// And a whole page or tool still builds around them.
		$areas = array_column( Saddle_Modules_View::all(), null, 'key' );
		$this->assertNull( $areas['broken']['state'] );
		$this->assertNull( $areas['broken']['setup'] );
		$this->assertNull( $areas['broken']['settings'] );
	}
}
