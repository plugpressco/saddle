<?php
/**
 * The free first-party integration engine — partner abilities wrapped in
 * Saddle's safety model.
 *
 * Uses synthetic abilities registered under the REAL `waggle/` prefix (the
 * default catalog entry), so this pins both the engine and the decision that
 * Waggle integrates free: wrappers land in the saddle/ namespace with tier
 * meta derived from the source annotations, the source's own permission
 * still applies inside execution, destructive calls go through the approval
 * gate, mutations are logged, the pause switch stops everything, and the
 * system context advertises the tools.
 *
 * @package Saddle
 */

class Saddle_Integrations_Test extends WP_UnitTestCase {

	private $admin;

	/**
	 * Run $fn as if inside the wp_abilities_api_init action — core guards
	 * wp_register_ability/wp_unregister_ability with doing_action(), and the
	 * action has already fired (lazily) by the time tests run.
	 *
	 * @param callable $fn Registration work.
	 */
	private function within_abilities_init( callable $fn ) {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$fn();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );

		$this->within_abilities_init( array( $this, 'register_waggle_stand_ins' ) );
	}

	/**
	 * Synthetic stand-ins for the Waggle plugin's abilities: a readonly tool,
	 * a plain write with its own capability rule, and a destructive write
	 * (Waggle ships none today — this pins the gate for when one appears).
	 */
	public function register_waggle_stand_ins() {
		wp_register_ability(
			'waggle/get-aeo-score',
			array(
				'label'               => 'Get AEO score',
				'description'         => 'Returns a post\'s AEO score.',
				'category'            => 'saddle',
				'input_schema'        => array( 'type' => 'object', 'default' => (object) array(), 'properties' => (object) array() ),
				'execute_callback'    => static function () {
					return array( 'score' => 87 );
				},
				'permission_callback' => '__return_true',
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ) ),
			)
		);
		wp_register_ability(
			'waggle/update-seo-meta',
			array(
				'label'               => 'Update SEO meta',
				'description'         => 'Writes a post\'s SEO meta.',
				'category'            => 'saddle',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
				),
				'execute_callback'    => static function ( $input ) {
					return array( 'updated' => (int) $input['post_id'] );
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false ) ),
			)
		);
		wp_register_ability(
			'waggle/reset-settings',
			array(
				'label'               => 'Reset settings',
				'description'         => 'Resets Waggle settings.',
				'category'            => 'saddle',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'scope' => array( 'type' => 'string' ) ),
				),
				'execute_callback'    => static function ( $input ) {
					return array( 'reset' => (string) $input['scope'] );
				},
				'permission_callback' => '__return_true',
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ) ),
			)
		);

		Saddle_Integrations::register_wrappers();
	}

	public function tear_down() {
		// The registry is a process-wide singleton — clean our synthetic
		// abilities out so the next test can re-register them.
		$this->within_abilities_init(
			static function () {
				$names = array(
					'waggle/get-aeo-score',
					'waggle/update-seo-meta',
					'waggle/reset-settings',
					'saddle/waggle-get-aeo-score',
					'saddle/waggle-update-seo-meta',
					'saddle/waggle-reset-settings',
					'zzz/get-stuff',
					'saddle/zzz-get-stuff',
				);
				$all = wp_get_abilities();
				foreach ( $names as $name ) {
					if ( isset( $all[ $name ] ) ) {
						wp_unregister_ability( $name );
					}
				}
			}
		);

		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	/* -------- registration + meta mapping -------- */

	public function test_waggle_is_in_the_default_catalog_and_wrappers_map_tiers() {
		$this->assertArrayHasKey( 'waggle', Saddle_Integrations::integrations() );

		$abilities = wp_get_abilities();
		foreach ( array( 'saddle/waggle-get-aeo-score', 'saddle/waggle-update-seo-meta', 'saddle/waggle-reset-settings' ) as $name ) {
			$this->assertArrayHasKey( $name, $abilities, "{$name} must exist." );
		}

		$read_meta = $abilities['saddle/waggle-get-aeo-score']->get_meta();
		$this->assertSame( 'read', $read_meta['saddle']['tier'] );
		$this->assertTrue( $read_meta['annotations']['readonly'] );

		$write_meta = $abilities['saddle/waggle-update-seo-meta']->get_meta();
		$this->assertSame( 'write', $write_meta['saddle']['tier'] );

		// Destructive wrappers gain the gate's handshake field.
		$schema = $abilities['saddle/waggle-reset-settings']->get_input_schema();
		$this->assertArrayHasKey( 'confirm_token', $schema['properties'] );
	}

	/* -------- delegation + safety layers -------- */

	public function test_readonly_wrapper_delegates_and_works_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$result = wp_get_ability( 'saddle/waggle-get-aeo-score' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertSame( 87, $result['score'] );
	}

	public function test_write_wrapper_is_denied_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertFalse(
			wp_get_ability( 'saddle/waggle-update-seo-meta' )->check_permissions( array( 'post_id' => 1 ) )
		);
	}

	public function test_write_wrapper_executes_and_logs_at_write_tier() {
		$result = wp_get_ability( 'saddle/waggle-update-seo-meta' )->execute( array( 'post_id' => 12 ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 12, $result['updated'] );

		$actions = wp_list_pluck( Saddle_Log::query( 5, 1 )['entries'], 'action' );
		$this->assertContains( 'waggle-update-seo-meta', $actions );
	}

	public function test_source_permission_still_binds_inside_execution() {
		// A write-tier editor passes Saddle's tier but must still fail the
		// source's own manage_options check — wrappers never grant more.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$result = wp_get_ability( 'saddle/waggle-update-seo-meta' )->execute( array( 'post_id' => 3 ) );

		$this->assertWPError( $result, 'The partner plugin’s own permission must still apply.' );
	}

	public function test_destructive_wrapper_gates_with_preview_then_token() {
		$ability = wp_get_ability( 'saddle/waggle-reset-settings' );

		$preview = $ability->execute( array( 'scope' => 'cache' ) );
		$this->assertNotWPError( $preview );
		$this->assertArrayHasKey( 'confirm_token', $preview );

		$done = $ability->execute( array( 'scope' => 'cache', 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertNotWPError( $done, 'A keyless destructive tool must be confirmable with the same arguments.' );
		$this->assertSame( 'cache', $done['reset'] );

		// Target-bound: a token previewed for one scope must not confirm another.
		$preview2 = $ability->execute( array( 'scope' => 'all' ) );
		$stolen   = $ability->execute( array( 'scope' => 'other', 'confirm_token' => $preview2['confirm_token'] ) );
		$this->assertWPError( $stolen );
	}

	public function test_pause_stops_integration_tools_too() {
		Saddle_Capabilities::set_paused( true );

		$this->assertFalse(
			wp_get_ability( 'saddle/waggle-get-aeo-score' )->check_permissions( array() )
		);

		Saddle_Capabilities::set_paused( false );
	}

	/**
	 * A wrapper name already taken by a foreign saddle ability is skipped —
	 * with a dev notice, so the shadowed tool is diagnosable — while the
	 * engine's own wrappers stay silently idempotent across re-runs (the
	 * set_up pass plus this one would otherwise warn on every waggle tool).
	 */
	public function test_foreign_collision_emits_dev_notice_and_keeps_existing_ability() {
		$this->setExpectedIncorrectUsage( 'Saddle_Integrations::wrap' );

		$add = static function ( $integrations ) {
			$integrations['coll'] = array( 'prefix' => 'coll/', 'title' => 'Coll' );
			return $integrations;
		};
		add_filter( 'saddle_integrations', $add );

		$this->within_abilities_init(
			static function () {
				// A native saddle ability already occupies the wrapper id.
				wp_register_ability(
					'saddle/coll-get-stuff',
					array(
						'label'               => 'Native occupant',
						'description'         => 'x',
						'category'            => 'saddle',
						'input_schema'        => array( 'type' => 'object', 'default' => (object) array(), 'properties' => (object) array() ),
						'execute_callback'    => '__return_empty_array',
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
				wp_register_ability(
					'coll/get-stuff',
					array(
						'label'               => 'Partner tool',
						'description'         => 'x',
						'category'            => 'saddle',
						'input_schema'        => array( 'type' => 'object', 'default' => (object) array(), 'properties' => (object) array() ),
						'execute_callback'    => '__return_empty_array',
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
				Saddle_Integrations::register_wrappers();
			}
		);
		remove_filter( 'saddle_integrations', $add );

		$this->assertSame(
			'Native occupant',
			wp_get_ability( 'saddle/coll-get-stuff' )->get_label(),
			'The pre-existing ability must never be overwritten by a wrapper.'
		);
	}

	public function test_disabled_integration_registers_nothing() {
		// A fresh prefix, disabled via the filter before wrappers run.
		$add = static function ( $integrations ) {
			$integrations['zzz'] = array( 'prefix' => 'zzz/', 'title' => 'ZZZ' );
			return $integrations;
		};
		$off = static function ( $enabled, $slug ) {
			return 'zzz' === $slug ? false : $enabled;
		};
		add_filter( 'saddle_integrations', $add );
		add_filter( 'saddle_integration_enabled', $off, 10, 2 );

		$this->within_abilities_init(
			static function () {
				wp_register_ability(
					'zzz/get-stuff',
					array(
						'label'               => 'Get stuff',
						'description'         => 'x',
						'category'            => 'saddle',
						'input_schema'        => array( 'type' => 'object', 'default' => (object) array(), 'properties' => (object) array() ),
						'execute_callback'    => '__return_empty_array',
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
				Saddle_Integrations::register_wrappers();
			}
		);

		remove_filter( 'saddle_integrations', $add );
		remove_filter( 'saddle_integration_enabled', $off );

		$this->assertArrayNotHasKey( 'saddle/zzz-get-stuff', wp_get_abilities() );
	}

	/* -------- agents learn the tools exist -------- */

	public function test_system_context_advertises_active_integrations() {
		$context = Saddle_Integrations::append_context( 'Base context.' );

		$this->assertStringContainsString( 'Waggle is installed', $context );
		$this->assertStringContainsString( 'saddle/waggle-', $context );

		// Nothing active → context passes through untouched.
		$this->within_abilities_init(
			static function () {
				foreach ( array( 'saddle/waggle-get-aeo-score', 'saddle/waggle-update-seo-meta', 'saddle/waggle-reset-settings' ) as $name ) {
					wp_unregister_ability( $name );
				}
			}
		);
		$this->assertSame( 'Base context.', Saddle_Integrations::append_context( 'Base context.' ) );
	}
}
