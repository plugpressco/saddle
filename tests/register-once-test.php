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
