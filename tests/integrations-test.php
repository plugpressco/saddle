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
			'waggle/rewrite-meta',
			array(
				'label'               => 'Rewrite SEO meta',
				'description'         => 'Overwrites a post\'s SEO meta.',
				'category'            => 'saddle',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'description' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input ) {
					return array(
						'post_id'     => (int) $input['post_id'],
						'description' => (string) $input['description'],
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ) ),
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
					'waggle/delete-report',
					'waggle/purge-everything',
					'waggle/wipe-history',
					'waggle/rewrite-meta',
					'saddle/waggle-get-aeo-score',
					'saddle/waggle-update-seo-meta',
					'saddle/waggle-reset-settings',
					'saddle/waggle-delete-report',
					'saddle/waggle-purge-everything',
					'saddle/waggle-wipe-history',
					'saddle/waggle-rewrite-meta',
					'zzz/get-stuff',
					'saddle/zzz-get-stuff',
					'coll/get-stuff',
					'saddle/coll-get-stuff',
					'acme/get-report',
					'acme/get-design-tokens',
					'saddle/acme-get-report',
					'saddle/acme-get-design-tokens',
					'mailyard/get-log',
					'saddle/mailyard-get-log',
				);
				$all = wp_get_abilities();
				foreach ( $names as $name ) {
					if ( isset( $all[ $name ] ) ) {
						wp_unregister_ability( $name );
					}
				}
			}
		);

		delete_option( Saddle_Integrations::APPROVED_OPTION );
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

	public function test_destructive_wrapper_confirm_cannot_change_the_arguments_it_previewed() {
		// The regression this pins: `$target` is only the id, so on a tool that
		// takes an id PLUS a payload every other argument was unbound — the
		// preview showed one thing and the confirm ran another. See issue #89.
		$ability = wp_get_ability( 'saddle/waggle-rewrite-meta' );

		$preview = $ability->execute(
			array(
				'post_id'     => 12,
				'description' => 'the description the user approved',
			)
		);
		$this->assertNotWPError( $preview );
		$this->assertArrayHasKey( 'confirm_token', $preview );

		$swapped = $ability->execute(
			array(
				'post_id'       => 12,
				'description'   => 'something else entirely',
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$this->assertWPError(
			$swapped,
			'A confirm that changes an argument the preview showed must be refused, not executed.'
		);
		$this->assertSame( 'saddle_token_bind_mismatch', $swapped->get_error_code() );
	}

	public function test_destructive_wrapper_confirms_with_the_same_arguments() {
		// The other half of the bind: unchanged arguments must still confirm,
		// or the gate would be unusable on every id-bearing partner tool.
		$ability = wp_get_ability( 'saddle/waggle-rewrite-meta' );

		$args    = array(
			'post_id'     => 12,
			'description' => 'the description the user approved',
		);
		$preview = $ability->execute( $args );
		$done    = $ability->execute( array_merge( $args, array( 'confirm_token' => $preview['confirm_token'] ) ) );

		$this->assertNotWPError( $done );
		$this->assertSame( 'the description the user approved', $done['description'] );
	}

	public function test_pause_stops_integration_tools_too() {
		Saddle_Capabilities::set_paused( true );

		$this->assertFalse(
			wp_get_ability( 'saddle/waggle-get-aeo-score' )->check_permissions( array() )
		);

		Saddle_Capabilities::set_paused( false );
	}

	/* -------- engine hardening: bind, schema normalization, force_destructive -------- */

	/**
	 * The full argument set is bound into the preview token: a confirm call
	 * that keeps the id (the target) but flips another destructive-relevant
	 * flag must be rejected — only the exact previewed action may execute.
	 */
	public function test_destructive_confirm_cannot_change_arguments_after_preview() {
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );

		$this->within_abilities_init(
			static function () {
				wp_register_ability(
					'waggle/delete-report',
					array(
						'label'               => 'Delete report',
						'description'         => 'Deletes a report.',
						'category'            => 'saddle',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'id'        => array( 'type' => 'integer' ),
								'permanent' => array( 'type' => 'boolean' ),
							),
						),
						'execute_callback'    => static function ( $input ) {
							return array(
								'deleted'   => (int) $input['id'],
								'permanent' => ! empty( $input['permanent'] ),
							);
						},
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ) ),
					)
				);
				Saddle_Integrations::register_wrappers();
			}
		);

		$tool = wp_get_ability( 'saddle/waggle-delete-report' );

		// Preview the recoverable version…
		$preview = $tool->execute( array( 'id' => 5, 'permanent' => false ) );
		$this->assertTrue( $preview['requires_confirmation'] );

		// …then try to confirm the irreversible one with the same token. The
		// id (the target) is unchanged — only the args bind catches this.
		$result = $tool->execute( array( 'id' => 5, 'permanent' => true, 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_bind_mismatch', $result->get_error_code() );

		// The honest confirm still works.
		$preview = $tool->execute( array( 'id' => 5, 'permanent' => false ) );
		$done    = $tool->execute( array( 'id' => 5, 'permanent' => false, 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertNotWPError( $done );
		$this->assertSame( 5, $done['deleted'] );
	}

	/**
	 * A destructive source declaring `properties` as an (object) cast of an
	 * empty array (the house style for no-input schemas) must be normalized
	 * before the handshake field is injected — on PHP 8 the raw assignment
	 * fatals ("Cannot use object of type stdClass as array").
	 */
	public function test_destructive_wrapper_normalizes_object_cast_properties() {
		$this->within_abilities_init(
			static function () {
				wp_register_ability(
					'waggle/purge-everything',
					array(
						'label'               => 'Purge everything',
						'description'         => 'x',
						'category'            => 'saddle',
						'input_schema'        => array( 'type' => 'object', 'default' => (object) array(), 'properties' => (object) array() ),
						'execute_callback'    => '__return_empty_array',
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ) ),
					)
				);
				Saddle_Integrations::register_wrappers();
			}
		);

		$schema = wp_get_ability( 'saddle/waggle-purge-everything' )->get_input_schema();
		$this->assertIsArray( $schema['properties'], 'stdClass properties must be normalized before the handshake field is added.' );
		$this->assertArrayHasKey( 'confirm_token', $schema['properties'] );
	}

	/**
	 * A partner that forgot its annotations entirely gets a write-tier,
	 * ungated wrapper by default — the catalog's force_destructive override
	 * is the owner-side fail-safe that puts such a tool behind the gate.
	 */
	public function test_force_destructive_catalog_override_gates_an_unannotated_tool() {
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );

		$force = static function ( $integrations ) {
			$integrations['waggle']['force_destructive'] = array( 'wipe-history' );
			return $integrations;
		};
		add_filter( 'saddle_integrations', $force );

		$this->within_abilities_init(
			static function () {
				wp_register_ability(
					'waggle/wipe-history',
					array(
						'label'               => 'Wipe history',
						'description'         => 'x',
						'category'            => 'saddle',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array( 'days' => array( 'type' => 'integer' ) ),
						),
						'execute_callback'    => static function () {
							return array( 'wiped' => true );
						},
						'permission_callback' => '__return_true',
						'meta'                => array(),
					)
				);
				Saddle_Integrations::register_wrappers();
			}
		);
		remove_filter( 'saddle_integrations', $force );

		$tool = wp_get_ability( 'saddle/waggle-wipe-history' );
		$this->assertTrue( $tool->get_meta()['annotations']['destructive'], 'The catalog override must flag the wrapper destructive.' );

		$preview = $tool->execute( array( 'days' => 30 ) );
		$this->assertIsArray( $preview );
		$this->assertTrue( $preview['requires_confirmation'], 'An unannotated tool under force_destructive must hit the gate, not execute directly.' );
	}

	/**
	 * A wrapper name already taken by a foreign saddle ability is skipped —
	 * with a dev notice, so the shadowed tool is diagnosable — while the
	 * engine's own wrappers stay silently idempotent across re-runs (the
	 * set_up pass plus this one would otherwise warn on every waggle tool).
	 */
	public function test_foreign_collision_emits_dev_notice_and_keeps_existing_ability() {
		$this->setExpectedIncorrectUsage( 'Saddle_Integration_Engine::wrap' );

		$add = static function ( $integrations ) {
			$integrations['coll'] = array( 'prefix' => 'coll/', 'title' => 'Coll' );
			return $integrations;
		};
		add_filter( 'saddle_integrations', $add );
		update_option( Saddle_Integrations::APPROVED_OPTION, array( 'coll' ) );

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
		// Approved, so the filter alone is what keeps it off.
		update_option( Saddle_Integrations::APPROVED_OPTION, array( 'zzz' ) );

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
		$sections = Saddle_Integrations::context_section( array() );

		$this->assertCount( 1, $sections );
		$this->assertSame( 'first-party-integrations', $sections[0]['id'] );
		$this->assertNotEmpty( $sections[0]['title'], 'The seam renders the heading, so a section must carry one.' );

		$body = implode( "\n", $sections[0]['lines'] );
		$this->assertStringContainsString( 'Waggle is installed', $body );
		$this->assertStringContainsString( 'saddle/waggle-', $body );

		// Nothing active → contribute nothing rather than an empty heading.
		$this->within_abilities_init(
			static function () {
				foreach ( array( 'saddle/waggle-get-aeo-score', 'saddle/waggle-update-seo-meta', 'saddle/waggle-reset-settings', 'saddle/waggle-rewrite-meta' ) as $name ) {
					wp_unregister_ability( $name );
				}
			}
		);
		$this->assertSame( array(), Saddle_Integrations::context_section( array() ) );
	}

	/**
	 * The whole point of the seam: whatever a contributor supplies, the
	 * rendered document has one heading level. This used to emit a bare
	 * "First-party integrations:" line into a document of `#` headings.
	 */
	public function test_the_integration_block_is_a_real_heading_in_the_context() {
		$context = Saddle_Context::system_context();

		$this->assertStringContainsString( '# Plugins connected to Saddle', $context );
		$this->assertStringNotContainsString( 'First-party integrations:', $context );
	}

	/**
	 * The Permissions screen groups tools by name prefix, and that list was
	 * hand-kept — so a plugin that self-enrols through `saddle_integrations`
	 * had its tools filed under "Other" and nobody would think to look in a
	 * REST controller for the reason (issue #59). Derived now, so enrolling is
	 * the only step.
	 */
	public function test_a_self_enrolled_integration_is_grouped_under_integrations() {
		$add = static function ( $integrations ) {
			$integrations['mailyard'] = array( 'prefix' => 'mailyard/', 'title' => 'Mailyard' );
			return $integrations;
		};
		add_filter( 'saddle_integrations', $add );

		$catalog = wp_list_pluck(
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/capabilities' ) )->get_data()['capabilities'],
			'category',
			'short'
		);

		remove_filter( 'saddle_integrations', $add );

		// Waggle is the catalog default and must not have regressed.
		$this->assertSame( 'Integrations', $catalog['waggle-get-aeo-score'] );
	}

	public function test_the_prefix_list_picks_up_the_live_catalog() {
		$add = static function ( $integrations ) {
			$integrations['mailyard'] = array( 'prefix' => 'mailyard/', 'title' => 'Mailyard' );
			return $integrations;
		};
		add_filter( 'saddle_integrations', $add );

		$prefixes = ( new ReflectionMethod( 'Saddle_REST_Admin', 'integration_prefixes' ) );
		$prefixes->setAccessible( true );
		$list = $prefixes->invoke( null );

		remove_filter( 'saddle_integrations', $add );

		$this->assertContains( 'mailyard-', $list, 'A self-enrolled integration must reach the UI grouping.' );
		$this->assertContains( 'waggle-', $list );
		$this->assertContains( 'knovia-', $list, 'The literal floor keeps Pro’s grouping from regressing.' );
	}

	/* -------- third-party integrations: owner-approved -------- */

	/**
	 * Enrol a third-party "acme" plugin and register its source abilities.
	 *
	 * @param string[] $tools Source short names, each registered readonly.
	 * @return callable The catalog filter, for removal.
	 */
	private function enrol_acme( array $tools = array( 'get-report' ) ) {
		$add = static function ( $integrations ) {
			$integrations['acme'] = array(
				'prefix'      => 'acme/',
				'title'       => 'Acme Forms',
				'description' => 'Form entries.',
				'author'      => 'Acme Inc.',
				'url'         => 'https://example.com/acme',
			);
			return $integrations;
		};
		add_filter( 'saddle_integrations', $add );

		$this->within_abilities_init(
			static function () use ( $tools ) {
				foreach ( $tools as $tool ) {
					wp_register_ability(
						'acme/' . $tool,
						array(
							'label'               => 'Acme ' . $tool,
							'description'         => 'x',
							'category'            => 'saddle',
							'input_schema'        => array( 'type' => 'object', 'default' => (object) array(), 'properties' => (object) array() ),
							'execute_callback'    => static function () {
								return array( 'entries' => 3 );
							},
							'permission_callback' => '__return_true',
							'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
						)
					);
				}
				Saddle_Integrations::register_wrappers();
			}
		);

		return $add;
	}

	private function rewrap() {
		$this->within_abilities_init(
			static function () {
				Saddle_Integrations::register_wrappers();
			}
		);
	}

	public function test_a_third_party_integration_stays_off_until_the_owner_approves() {
		$add = $this->enrol_acme();

		$this->assertArrayNotHasKey( 'saddle/acme-get-report', wp_get_abilities(), 'Enrolling alone must not put a third-party tool in front of an agent.' );

		$rows = array_column( Saddle_Integrations::listing(), null, 'slug' );
		$this->assertSame( 'third-party', $rows['acme']['source'] );
		$this->assertFalse( $rows['acme']['enabled'] );
		$this->assertSame( 1, $rows['acme']['tools'], 'The owner sees what switching it on would add.' );

		$context = implode( "\n", Saddle_Integrations::context_section( array() )[0]['lines'] );
		$this->assertStringContainsString( 'Acme Forms is installed, but the site owner has not switched on its tools', $context );

		$this->assertTrue( Saddle_Integrations::set_approved( 'acme', true ) );
		$this->rewrap();
		remove_filter( 'saddle_integrations', $add );

		$tool = wp_get_ability( 'saddle/acme-get-report' );
		$this->assertNotNull( $tool, 'Once approved, the tool is wrapped like any other.' );
		$this->assertSame( 'read', $tool->get_meta()['saddle']['tier'] );
		$this->assertSame( array( 'entries' => 3 ), $tool->execute( array() ) );
	}

	public function test_the_enabled_filter_cannot_switch_on_an_unapproved_integration() {
		$force_on = static function () {
			return true;
		};
		add_filter( 'saddle_integration_enabled', $force_on, 99 );
		$add = $this->enrol_acme();
		remove_filter( 'saddle_integration_enabled', $force_on, 99 );
		remove_filter( 'saddle_integrations', $add );

		$this->assertArrayNotHasKey( 'saddle/acme-get-report', wp_get_abilities(), 'Only the owner can approve a third-party integration.' );
	}

	public function test_first_party_integrations_need_no_approval() {
		$this->assertSame( array(), get_option( Saddle_Integrations::APPROVED_OPTION, array() ) );
		$this->assertNotNull( wp_get_ability( 'saddle/waggle-get-aeo-score' ), 'Waggle is live with nothing approved.' );

		$add = static function ( $integrations ) {
			$integrations['mailyard'] = array( 'prefix' => 'mailyard/', 'title' => 'Mailyard' );
			return $integrations;
		};
		add_filter( 'saddle_integrations', $add );
		$this->within_abilities_init(
			static function () {
				wp_register_ability(
					'mailyard/get-log',
					array(
						'label'               => 'Get log',
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
		$rows = array_column( Saddle_Integrations::listing(), null, 'slug' );
		remove_filter( 'saddle_integrations', $add );

		$this->assertNotNull( wp_get_ability( 'saddle/mailyard-get-log' ), 'Mailyard self-enrols and is live with nothing approved.' );
		$this->assertSame( 'plugpress', $rows['mailyard']['source'] );
		$this->assertInstanceOf( 'WP_Error', Saddle_Integrations::set_approved( 'waggle', false ), 'A PlugPress integration has no owner switch to flip.' );
	}

	/**
	 * Saddle Analytics enrols as `analytics` with its own `saddle-analytics/`
	 * namespace (the slug and the prefix differ on purpose): PlugPress's, so
	 * live with nothing approved, like Mailyard (#211).
	 */
	public function test_saddle_analytics_is_first_party() {
		$this->assertTrue( Saddle_Integrations::is_first_party( 'analytics' ) );

		$add = static function ( $integrations ) {
			$integrations['analytics'] = array( 'prefix' => 'saddle-analytics/', 'title' => 'Saddle Analytics' );
			return $integrations;
		};
		add_filter( 'saddle_integrations', $add );
		$this->within_abilities_init(
			static function () {
				wp_register_ability(
					'saddle-analytics/get-overview',
					array(
						'label'               => 'Traffic overview',
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
		$rows = array_column( Saddle_Integrations::listing(), null, 'slug' );
		remove_filter( 'saddle_integrations', $add );

		$this->assertSame( array(), get_option( Saddle_Integrations::APPROVED_OPTION, array() ) );
		$this->assertNotNull( wp_get_ability( 'saddle/analytics-get-overview' ), 'Saddle Analytics is live with nothing approved.' );
		$this->assertSame( 'plugpress', $rows['analytics']['source'] );
	}

	/**
	 * An empty prefix would wrap every ability on the site; `saddle/` and
	 * `core/` would re-expose abilities that aren't the partner's to offer.
	 */
	public function test_invalid_catalog_entries_are_skipped_with_a_notice() {
		$this->setExpectedIncorrectUsage( 'Saddle_Integration_Engine::normalize' );

		$bad = static function ( $integrations ) {
			$integrations['empty-prefix'] = array( 'prefix' => '', 'title' => 'x' );
			$integrations['own-saddle']   = array( 'prefix' => 'saddle/', 'title' => 'x' );
			$integrations['own-core']     = array( 'prefix' => 'core/', 'title' => 'x' );
			$integrations['no-slash']     = array( 'prefix' => 'noslash', 'title' => 'x' );
			$integrations['Upper']        = array( 'prefix' => 'upper/', 'title' => 'x' );
			$integrations['divi']         = array( 'prefix' => 'divi/', 'title' => 'x' );
			return $integrations;
		};
		add_filter( 'saddle_integrations', $bad );
		update_option( Saddle_Integrations::APPROVED_OPTION, array( 'empty-prefix', 'own-saddle', 'own-core', 'no-slash', 'upper', 'divi' ) );
		$this->rewrap();
		$catalog = Saddle_Integrations::integrations();
		remove_filter( 'saddle_integrations', $bad );

		foreach ( array( 'empty-prefix', 'own-saddle', 'own-core', 'no-slash', 'Upper', 'divi' ) as $slug ) {
			$this->assertArrayNotHasKey( $slug, $catalog );
		}
		$this->assertArrayHasKey( 'waggle', $catalog, 'A valid entry survives next to invalid ones.' );

		foreach ( array_keys( wp_get_abilities() ) as $name ) {
			$this->assertStringStartsNotWith( 'saddle/own-saddle-', $name );
			$this->assertStringStartsNotWith( 'saddle/empty-prefix-', $name );
		}
	}

	/* -------- the owner's Integrations screen -------- */

	public function test_the_integrations_endpoint_lists_every_installed_integration() {
		$add  = $this->enrol_acme();
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/integrations' ) )->get_data();
		remove_filter( 'saddle_integrations', $add );

		$rows = array_column( $data['integrations'], null, 'slug' );
		$this->assertSame(
			array( 'slug', 'title', 'description', 'author', 'url', 'source', 'enabled', 'tools' ),
			array_keys( $rows['acme'] ),
			'The Integrations screen depends on this row shape.'
		);
		$this->assertSame( 'Acme Inc.', $rows['acme']['author'] );
		$this->assertSame( 'https://example.com/acme', $rows['acme']['url'] );
		$this->assertSame( 'plugpress', $rows['waggle']['source'] );
		$this->assertSame( 4, $rows['waggle']['tools'] );
		$this->assertTrue( $rows['waggle']['enabled'] );
	}

	public function test_switching_an_integration_needs_manage_options() {
		$add = $this->enrol_acme();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/saddle/v1/integrations' );
		$request->set_param( 'slug', 'acme' );
		$request->set_param( 'enabled', true );
		$response = rest_get_server()->dispatch( $request );
		remove_filter( 'saddle_integrations', $add );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), get_option( Saddle_Integrations::APPROVED_OPTION, array() ) );
	}

	public function test_the_owner_switches_a_third_party_integration_on_and_off() {
		$add = $this->enrol_acme();

		$on = new WP_REST_Request( 'POST', '/saddle/v1/integrations' );
		$on->set_param( 'slug', 'acme' );
		$on->set_param( 'enabled', true );
		$response = rest_get_server()->dispatch( $on );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'acme' ), get_option( Saddle_Integrations::APPROVED_OPTION ) );
		$rows = array_column( $response->get_data()['integrations'], null, 'slug' );
		$this->assertTrue( $rows['acme']['enabled'] );

		$off = new WP_REST_Request( 'POST', '/saddle/v1/integrations' );
		$off->set_param( 'slug', 'acme' );
		$off->set_param( 'enabled', false );
		rest_get_server()->dispatch( $off );
		remove_filter( 'saddle_integrations', $add );

		$this->assertSame( array(), get_option( Saddle_Integrations::APPROVED_OPTION ) );
	}

	public function test_an_uninstalled_integration_cannot_be_switched_on() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/integrations' );
		$request->set_param( 'slug', 'not-installed' );
		$request->set_param( 'enabled', true );

		$this->assertSame( 404, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Grouping used to be a substring match that ran after "Design system",
	 * so a partner tool named for design tokens was filed there.
	 */
	public function test_integration_tools_are_grouped_by_the_start_of_their_name() {
		update_option( Saddle_Integrations::APPROVED_OPTION, array( 'acme' ) );
		$add = $this->enrol_acme( array( 'get-design-tokens' ) );

		$catalog = wp_list_pluck(
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/capabilities' ) )->get_data()['capabilities'],
			'category',
			'short'
		);
		remove_filter( 'saddle_integrations', $add );

		$this->assertSame( 'Integrations', $catalog['acme-get-design-tokens'] );
		$this->assertSame( 'Design system', $catalog['get-design-tokens'], 'Saddle’s own tool must not move.' );
	}
}
