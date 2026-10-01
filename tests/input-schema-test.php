<?php
/**
 * Tool input schemas, as WordPress validates them and as MCP clients read them.
 *
 * @package Saddle
 */

class Saddle_Input_Schema_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'read' );
	}

	/**
	 * A no-argument tool declared `'properties' => (object) array()` so the
	 * map would serialize as {}. WordPress's validator indexes that map as an
	 * array, so an agent passing any argument to such a tool (divi-list-modules
	 * with "search") got a PHP fatal and a 500, not a validation answer.
	 */
	public function test_no_tool_schema_crashes_the_validator_on_an_unexpected_argument() {
		$checked = 0;
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 !== strpos( $ability->get_name(), 'saddle/' ) ) {
				continue;
			}
			$schema = $ability->get_input_schema();
			if ( ! is_array( $schema ) || ! isset( $schema['properties'] ) ) {
				continue;
			}
			$this->assertIsArray( $schema['properties'], $ability->get_name() . ' declares its properties as an object.' );
			try {
				rest_validate_value_from_schema( array( 'saddle_unexpected' => 1 ), $schema, 'input' );
			} catch ( Error $e ) {
				$this->fail( $ability->get_name() . ': ' . $e->getMessage() );
			}
			++$checked;
		}
		$this->assertGreaterThan( 100, $checked );
	}

	/**
	 * The other half: dropping the cast must not bring back `"properties": []`,
	 * which strict MCP clients reject. Both transports list it as {}.
	 */
	public function test_an_empty_properties_map_is_listed_as_an_object_on_both_transports() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );

		$builtin = wp_json_encode( Saddle_MCP::handle( $request )->get_data() );
		$adapter = wp_json_encode( rest_do_request( $request )->get_data() );

		foreach ( array( 'built-in' => $builtin, 'adapter' => $adapter ) as $transport => $json ) {
			$this->assertStringContainsString( '"name":"saddle-get-site-info"', $json, $transport );
			$this->assertStringNotContainsString( '"properties":[]', $json, $transport );
		}
	}
}
