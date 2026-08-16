<?php
/**
 * Admin route surface — the WAF-motivated /preferences rename.
 *
 * Some host WAFs intercept any REST path ending in the literal `settings`
 * segment before WordPress runs (20i's StackProtect, proven on a customer
 * site). The dashboard talks to /preferences; /settings must stay registered
 * as an alias so an old cached admin bundle keeps working. These tests pin
 * both registrations so neither half of that contract is dropped silently.
 *
 * @package Saddle
 */

/**
 * Pins the /preferences primary + /settings alias registration contract.
 */
class Saddle_REST_Routes_Test extends WP_UnitTestCase {

	/**
	 * Saddle declares exactly one MCP endpoint. The vendored adapter used to
	 * stand up a second of its own at /mcp/mcp-adapter-default-server, serving
	 * discover-abilities, get-ability-info and execute-ability with no
	 * transport permission callback — a public surface we never declared
	 * (issue #86). It is disabled on plugins_loaded; this is what would notice
	 * if that filter ever stopped being registered early enough.
	 */
	public function test_no_second_undeclared_mcp_endpoint_is_served() {
		$mcp = array_values(
			array_filter(
				array_keys( rest_get_server()->get_routes() ),
				static function ( $route ) {
					return false !== strpos( $route, 'mcp' );
				}
			)
		);

		$this->assertContains( '/saddle/v1/mcp', $mcp, 'Saddle’s own endpoint must still be there.' );

		foreach ( $mcp as $route ) {
			$this->assertStringStartsWith(
				'/saddle/v1/',
				$route,
				sprintf( 'Every MCP route must be one of Saddle’s own; %s is not.', $route )
			);
		}
	}

	/** Both paths must answer: the app needs /preferences, old bundles /settings. */
	public function test_preferences_and_settings_are_both_registered() {
		$routes = rest_get_server()->get_routes( 'saddle/v1' );

		$this->assertArrayHasKey( '/saddle/v1/preferences', $routes, 'The dashboard route must exist.' );
		$this->assertArrayHasKey( '/saddle/v1/settings', $routes, 'The alias for old cached bundles must exist.' );
	}

	/** The alias must never drift from the primary — same handlers, methods, args. */
	public function test_preferences_mirrors_settings_exactly() {
		$routes = rest_get_server()->get_routes( 'saddle/v1' );

		$normalize = static function ( $handlers ) {
			$out = array();
			foreach ( $handlers as $handler ) {
				$out[] = array(
					'methods'  => $handler['methods'],
					'callback' => $handler['callback'],
					'args'     => array_keys( $handler['args'] ),
				);
			}
			return $out;
		};

		$this->assertSame(
			$normalize( $routes['/saddle/v1/settings'] ),
			$normalize( $routes['/saddle/v1/preferences'] ),
			'Alias and primary must share handlers, methods, and args.'
		);
	}
}
