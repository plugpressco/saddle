<?php
/**
 * saddle_register_ability_once() — the guard that lets free ship tools an
 * older add-on still registers under the same name.
 *
 * The add-on registers at `wp_abilities_api_init` priority 20, free at 30:
 * the add-on's copy must win, with no duplicate-registration notice, and free
 * must fill the name in when nothing else registered it.
 *
 * @package Saddle
 */

class Saddle_Register_Once_Test extends WP_UnitTestCase {

	const PROBE = 'saddle/compat-probe';

	public function tear_down() {
		if ( wp_has_ability( self::PROBE ) ) {
			wp_unregister_ability( self::PROBE );
		}
		parent::tear_down();
	}

	/**
	 * Fire wp_abilities_api_init with only the given callbacks attached, so the
	 * rest of the registry is not registered a second time.
	 *
	 * @param array<int, callable> $callbacks Priority => callback.
	 */
	private function fire_init( array $callbacks ) {
		global $wp_filter;

		// The registry initializes lazily and fires wp_abilities_api_init on
		// first access; do that with the real hooks in place, before swapping.
		WP_Abilities_Registry::get_instance();

		$saved = isset( $wp_filter['wp_abilities_api_init'] ) ? $wp_filter['wp_abilities_api_init'] : null;
		unset( $wp_filter['wp_abilities_api_init'] );
		try {
			foreach ( $callbacks as $priority => $callback ) {
				add_action( 'wp_abilities_api_init', $callback, $priority );
			}
			do_action( 'wp_abilities_api_init' );
		} finally {
			unset( $wp_filter['wp_abilities_api_init'] );
			if ( null !== $saved ) {
				$wp_filter['wp_abilities_api_init'] = $saved;
			}
		}
	}

	/**
	 * Minimal ability arguments, labelled so the winner is identifiable.
	 *
	 * @param string $label Ability label.
	 * @return array
	 */
	private function args( $label ) {
		return array(
			'label'               => $label,
			'description'         => 'Probe.',
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => '__return_true',
			'permission_callback' => '__return_true',
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		);
	}

	public function test_an_earlier_registration_of_the_same_name_wins() {
		$this->fire_init(
			array(
				20 => function () {
					wp_register_ability( self::PROBE, $this->args( 'Add-on copy' ) );
				},
				30 => function () {
					$this->assertNull( saddle_register_ability_once( self::PROBE, $this->args( 'Free copy' ) ) );
				},
			)
		);

		$this->assertSame( 'Add-on copy', wp_get_ability( self::PROBE )->get_label() );
	}

	public function test_registers_when_nothing_else_did() {
		$this->fire_init(
			array(
				30 => function () {
					$this->assertInstanceOf( 'WP_Ability', saddle_register_ability_once( self::PROBE, $this->args( 'Free copy' ) ) );
				},
			)
		);

		$this->assertSame( 'Free copy', wp_get_ability( self::PROBE )->get_label() );
	}

	/**
	 * The Divi 5 tools moved from the add-on keep their exact name, tier and
	 * destructive flag (values taken from the add-on's 1.6.1 registrations).
	 */
	public function test_moved_divi_tools_keep_name_tier_and_destructive_flag() {
		$expect = array(
			'saddle/divi-check-setup'                   => array( 'read', false ),
			'saddle/divi-list-modules'                  => array( 'read', false ),
			'saddle/divi-get-page'                      => array( 'read', false ),
			'saddle/divi-set-page'                      => array( 'write', false ),
			'saddle/divi-set-page-settings'             => array( 'write', false ),
			'saddle/divi-get-module-schema'             => array( 'read', false ),
			'saddle/divi-get-style-schema'              => array( 'read', false ),
			'saddle/divi-add-module'                    => array( 'write', false ),
			'saddle/divi-edit-module'                   => array( 'write', false ),
			'saddle/divi-move-module'                   => array( 'write', false ),
			'saddle/divi-remove-module'                 => array( 'write', true ),
			'saddle/divi-list-loop-query-types'         => array( 'read', false ),
			'saddle/divi-enable-loop'                   => array( 'write', false ),
			'saddle/divi-edit-loop'                     => array( 'write', false ),
			'saddle/divi-disable-loop'                  => array( 'write', false ),
			'saddle/divi-list-dynamic-sources'          => array( 'read', false ),
			'saddle/divi-apply-dynamic-content'         => array( 'write', false ),
			'saddle/divi-clear-dynamic-content'         => array( 'write', false ),
			'saddle/divi-apply-global-preset'           => array( 'write', false ),
			'saddle/divi-list-condition-types'          => array( 'read', false ),
			'saddle/divi-set-display-conditions'        => array( 'write', false ),
			'saddle/divi-context-bundle'                => array( 'read', false ),
			'saddle/divi-list-global-colors'            => array( 'read', false ),
			'saddle/divi-get-global-fonts'              => array( 'read', false ),
			'saddle/divi-list-variables'                => array( 'read', false ),
			'saddle/divi-list-global-presets'           => array( 'read', false ),
			'saddle/divi-get-global-preset'             => array( 'read', false ),
			'saddle/divi-list-library-items'            => array( 'read', false ),
			'saddle/divi-list-theme-builder-templates'  => array( 'read', false ),
			'saddle/divi-list-theme-builder-conditions' => array( 'read', false ),
		);

		foreach ( $expect as $name => list( $tier, $destructive ) ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "{$name} must be registered." );
			$this->assertSame( $tier, $ability->get_meta()['saddle']['tier'], "{$name} tier" );
			$this->assertSame( $destructive, $ability->get_meta()['annotations']['destructive'], "{$name} destructive flag" );
		}
	}

	/**
	 * Tools that stay in the add-on are never registered by this plugin.
	 */
	public function test_site_operation_tools_are_not_registered_here() {
		foreach ( array( 'divi-swap-image', 'divi-bulk-apply-preset', 'divi-undo-batch', 'divi-create-global-color', 'divi-set-global-fonts', 'divi-create-library-item', 'divi-set-theme-builder-conditions', 'divi-compose-page', 'divi-clone-page', 'divi-commit-brief', 'wc-bulk-update-prices', 'wc-undo-batch' ) as $short ) {
			$this->assertFalse( wp_has_ability( 'saddle/' . $short ), "saddle/{$short} belongs to the add-on." );
		}
	}

	/**
	 * The tools moved from the add-on keep their exact name, tier and
	 * destructive flag — tool names and their contract are a public API.
	 */
	public function test_moved_integration_tools_keep_name_tier_and_destructive_flag() {
		$expect = array(
			'saddle/yoast-check-setup'       => array( 'read', false ),
			'saddle/yoast-get-post-seo'      => array( 'read', false ),
			'saddle/yoast-edit-post-seo'     => array( 'write', false ),
			'saddle/yoast-get-term-seo'      => array( 'read', false ),
			'saddle/yoast-edit-term-seo'     => array( 'write', false ),
			'saddle/yoast-get-post-schema'   => array( 'read', false ),
			'saddle/yoast-edit-post-schema'  => array( 'write', false ),
			'saddle/rank-math-check-setup'   => array( 'read', false ),
			'saddle/rank-math-get-post-seo'  => array( 'read', false ),
			'saddle/rank-math-edit-post-seo' => array( 'write', false ),
			'saddle/rank-math-get-term-seo'  => array( 'read', false ),
			'saddle/rank-math-edit-term-seo' => array( 'write', false ),
			'saddle/aioseo-check-setup'      => array( 'read', false ),
			'saddle/aioseo-get-post-seo'     => array( 'read', false ),
			'saddle/aioseo-edit-post-seo'    => array( 'write', false ),
			'saddle/wc-check-setup'          => array( 'read', false ),
			'saddle/wc-list-products'        => array( 'read', false ),
			'saddle/wc-get-product'          => array( 'read', false ),
			'saddle/wc-list-orders'          => array( 'read', false ),
		);

		foreach ( $expect as $name => list( $tier, $destructive ) ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "{$name} must be registered." );
			$this->assertSame( $tier, $ability->get_meta()['saddle']['tier'], "{$name} tier" );
			$this->assertSame( $destructive, $ability->get_meta()['annotations']['destructive'], "{$name} destructive flag" );
		}
	}
}
