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
	 * Boot the REST server outside any test's incorrect-usage capture — the
	 * MCP adapter's registration emits a `_doing_it_wrong` in the test
	 * environment (same reason `nonce-fallback-test.php` does this).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
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
