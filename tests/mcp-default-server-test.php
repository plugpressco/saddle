<?php
/**
 * The MCP Adapter's shared default server (#86, #219).
 *
 * What must hold: Saddle's tools are never exposed on it; on a site where
 * nothing else uses it, it is not created (#86's single endpoint); where
 * another plugin exposes abilities to it — Gravity Forms in "Site MCP" mode —
 * it stays (#219). The route-level check for a Saddle-only site is in
 * rest-routes-test.php.
 *
 * @package Saddle
 */

class Saddle_MCP_Default_Server_Test extends WP_UnitTestCase {

	const FACTORY = array( 'WP\\MCP\\Servers\\DefaultServerFactory', 'create' );

	public function tear_down() {
		remove_action( 'mcp_adapter_init', self::FACTORY );
		if ( wp_has_ability( 'gf-test/list-forms' ) ) {
			wp_unregister_ability( 'gf-test/list-forms' );
		}
		parent::tear_down();
	}

	/**
	 * Register as if inside wp_abilities_api_init, which core requires.
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

	public function test_no_saddle_tool_is_marked_for_the_shared_server() {
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 !== strpos( $ability->get_name(), 'saddle/' ) ) {
				continue;
			}
			$meta = $ability->get_meta();
			$this->assertEmpty( $meta['mcp']['public'], $ability->get_name() . ' must not be served by the default server.' );
		}
	}

	public function test_the_shared_server_is_dropped_when_nothing_else_uses_it() {
		add_action( 'mcp_adapter_init', self::FACTORY );

		Saddle_MCP::limit_default_server();

		$this->assertFalse( has_action( 'mcp_adapter_init', self::FACTORY ) );
	}

	/**
	 * The #219 regression: Gravity Forms' "Site MCP" mode marks its abilities
	 * public and serves them through the shared server. Saddle used to switch
	 * that server off for every plugin.
	 */
	public function test_the_shared_server_stays_when_another_plugin_uses_it() {
		add_action( 'mcp_adapter_init', self::FACTORY );
		$this->within_abilities_init(
			static function () {
				wp_register_ability(
					'gf-test/list-forms',
					array(
						'label'               => 'List forms',
						'description'         => 'A stand-in for a plugin serving its tools through the shared server.',
						'category'            => 'saddle',
						'execute_callback'    => '__return_empty_array',
						'permission_callback' => '__return_true',
						'meta'                => array( 'mcp' => array( 'public' => true ) ),
					)
				);
			}
		);

		Saddle_MCP::limit_default_server();

		$this->assertNotFalse( has_action( 'mcp_adapter_init', self::FACTORY ), 'Another plugin\'s shared server must survive.' );
	}
}
