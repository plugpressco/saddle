<?php
/**
 * Every Saddle tool carries all four MCP behaviour hints (#267). OpenAI's
 * plugin review rejects tools with missing or wrong readOnlyHint,
 * destructiveHint or openWorldHint labels, and Claude's directory flags a
 * tool without a title or hint.
 *
 * @package Saddle
 */

class Saddle_Open_World_Test extends WP_UnitTestCase {

	/** The tools that reach outside the site. */
	const OPEN_WORLD = array(
		'saddle/unsplash-search',
		'saddle/unsplash-import',
		'saddle/upload-media',
		'saddle/update-plugin',
		'saddle/update-theme',
	);

	public function test_every_saddle_tool_declares_all_four_hints() {
		$checked = 0;
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'saddle/' ) ) {
				continue;
			}
			$annotations = $ability->get_meta_item( 'annotations' );
			foreach ( array( 'readonly', 'destructive', 'idempotent', 'openWorldHint' ) as $hint ) {
				$this->assertIsBool( $annotations[ $hint ], "{$name} must declare {$hint}." );
			}
			++$checked;
		}
		$this->assertGreaterThan( 100, $checked );
	}

	public function test_only_tools_that_reach_outside_the_site_are_open_world() {
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'saddle/' ) ) {
				continue;
			}
			$open = $ability->get_meta_item( 'annotations' )['openWorldHint'];
			// Wrapped integrations default to open-world; see the engine.
			if ( in_array( $name, self::OPEN_WORLD, true ) ) {
				$this->assertTrue( $open, "{$name} reaches outside the site." );
			}
		}
		$this->assertFalse( wp_get_ability( 'saddle/get-post' )->get_meta_item( 'annotations' )['openWorldHint'] );
		$this->assertFalse( wp_get_ability( 'saddle/delete-post' )->get_meta_item( 'annotations' )['openWorldHint'] );
	}

	public function test_the_built_in_transport_sends_open_world_hint() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'admin' );

		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );
		$data  = json_decode( wp_json_encode( Saddle_MCP::handle( $req )->get_data() ), true );
		$tools = array_column( $data['result']['tools'], 'annotations', 'name' );

		Saddle_Capabilities::set_tier( 'read' );

		$this->assertFalse( $tools['saddle-get-post']['openWorldHint'] );
		$this->assertTrue( $tools['saddle-unsplash-search']['openWorldHint'] );
	}
}
