<?php
/**
 * The MCP Adapter being loadable is not the adapter running.
 *
 * Gravity Forms 3.1 ships the canonical adapter in its autoloader on every
 * request and only starts it when its own MCP setting is on — off by default.
 * Saddle saw the class, handed /saddle/v1/mcp to an adapter nobody had started,
 * and the endpoint was a 404 on every site that installed Gravity Forms 3.1.
 *
 * Saddle::setup_mcp_transport() now starts the adapter itself. That half is
 * not tested here: the adapter's singleton is a typed, non-nullable static, so
 * a process that has started it cannot be put back in the "loaded, never
 * started" state. It was verified live against Gravity Forms 3.1.2 — see the
 * PR. What is tested is the net under it: the route exists whatever the
 * adapter did.
 *
 * @package Saddle
 */

class Saddle_MCP_Adapter_Coexistence_Test extends WP_UnitTestCase {

	/**
	 * @var WP_REST_Server|null
	 */
	private $saved_server;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$this->saved_server = $wp_rest_server;

		$this->set_fell_back( false );

		// ensure_route() runs on rest_api_init. In full-suite order that action
		// may not be counted as fired by the time this class runs, and core's
		// register_rest_route() checks exactly that. WP_UnitTestCase restores
		// $wp_actions after each test, so this does not leak.
		global $wp_actions;
		if ( ! did_action( 'rest_api_init' ) ) {
			$wp_actions['rest_api_init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'admin' );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = $this->saved_server;

		$this->set_fell_back( false );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function set_fell_back( $value ) {
		$property = new ReflectionProperty( 'Saddle_MCP', 'fell_back' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, $value );
	}

	private function initialize_request() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2025-06-18',
						'capabilities'    => array(),
						'clientInfo'      => array(
							'name'    => 'Test',
							'version' => '1',
						),
					),
				)
			)
		);

		return $request;
	}

	/**
	 * Whatever went wrong between the adapter and our server, the route must
	 * still exist — served by the built-in transport.
	 */
	public function test_ensure_route_registers_the_builtin_transport_when_nobody_served_it() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		$this->assertArrayNotHasKey( '/saddle/v1/mcp', $wp_rest_server->get_routes( 'saddle/v1' ) );

		Saddle_MCP::ensure_route( $wp_rest_server );

		$routes = $wp_rest_server->get_routes( 'saddle/v1' );
		$this->assertArrayHasKey( '/saddle/v1/mcp', $routes, 'The route must exist even when the adapter never served it.' );
		$this->assertTrue( Saddle_MCP::fell_back() );

		$response = $wp_rest_server->dispatch( $this->initialize_request() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2025-06-18', $response->get_data()['result']['protocolVersion'] );
	}

	public function test_ensure_route_does_not_register_twice() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		Saddle_MCP::ensure_route( $wp_rest_server );
		$count = count( $wp_rest_server->get_routes( 'saddle/v1' )['/saddle/v1/mcp'] );

		Saddle_MCP::ensure_route( $wp_rest_server );
		$this->assertCount( $count, $wp_rest_server->get_routes( 'saddle/v1' )['/saddle/v1/mcp'] );
	}

	/**
	 * When the adapter is serving, the fallback must stay out of its way.
	 */
	public function test_ensure_route_leaves_a_served_route_alone() {
		$server = rest_get_server();
		$before = $server->get_routes( 'saddle/v1' );
		$this->assertArrayHasKey( '/saddle/v1/mcp', $before );

		Saddle_MCP::ensure_route( $server );

		$this->assertSame( count( $before['/saddle/v1/mcp'] ), count( $server->get_routes( 'saddle/v1' )['/saddle/v1/mcp'] ) );
		$this->assertFalse( Saddle_MCP::fell_back() );
	}

	/**
	 * WooCommerce 11 autoloads MCP Adapter 0.1.0 on every request. Saddle
	 * handed it the route, called McpPrompt::fromArray(), which that copy does
	 * not have, and every REST request on the site was a fatal error. An
	 * adapter that old is now not "available": the built-in transport serves.
	 */
	public function test_an_adapter_older_than_0_5_is_not_handed_the_route() {
		$this->assertFalse( Saddle::adapter_is_current( 'Saddle_Test_Adapter_010_Prompt' ) );
	}

	/**
	 * MCP Adapter 0.7.0 made McpServer::get_tools() (and get_resources(),
	 * get_prompts()) require the request's protocol schema. Saddle called it
	 * bare and every request on the site fatalled (#340). A server with that
	 * API is not "current" yet: the built-in transport serves the route.
	 */
	public function test_an_adapter_whose_server_needs_a_schema_is_not_handed_the_route() {
		$this->assertFalse( Saddle::adapter_is_current( 'Saddle_Test_Adapter_Prompt', 'Saddle_Test_Adapter_070_Server' ) );
		$this->assertTrue( Saddle::adapter_is_current( 'Saddle_Test_Adapter_Prompt', 'Saddle_Test_Adapter_061_Server' ) );
		$this->assertFalse( Saddle::adapter_is_current( 'Saddle_Test_Adapter_Prompt', 'Saddle_Test_No_Such_Server' ) );
	}

	/**
	 * A stray call to a schema-taking server method is skipped, not made.
	 */
	public function test_server_lists_are_read_only_from_the_api_saddle_knows() {
		$list = new ReflectionMethod( 'Saddle_MCP', 'server_list' );
		$list->setAccessible( true );

		$this->assertNull( $list->invoke( null, new Saddle_Test_Adapter_070_Server(), 'get_tools' ) );
		$this->assertSame( array( 'saddle-get-site-info' => true ), $list->invoke( null, new Saddle_Test_Adapter_061_Server(), 'get_tools' ) );
		$this->assertNull( $list->invoke( null, null, 'get_tools' ) );
		$this->assertNull( $list->invoke( null, new Saddle_Test_Adapter_061_Server(), 'get_prompts' ) );
	}

	/**
	 * With the official plugin installed, active or not, the bundle stays off:
	 * activating it next to a loaded bundle redeclared WP\MCP\Autoloader
	 * (#340). Any folder a release zip unpacks to counts.
	 */
	public function test_the_bundle_stands_aside_for_an_installed_official_plugin() {
		$dir = trailingslashit( get_temp_dir() ) . 'saddle-plugins-' . wp_generate_password( 6, false );
		wp_mkdir_p( $dir . '/akismet' );
		$this->assertFalse( Saddle_Bundled_Adapter::standalone_installed( $dir ) );

		wp_mkdir_p( $dir . '/mcp-adapter-0.7.0' );
		file_put_contents( $dir . '/mcp-adapter-0.7.0/mcp-adapter.php', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->assertTrue( Saddle_Bundled_Adapter::standalone_installed( $dir ) );

		unlink( $dir . '/mcp-adapter-0.7.0/mcp-adapter.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
		rmdir( $dir . '/mcp-adapter-0.7.0' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test fixture.
		rmdir( $dir . '/akismet' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test fixture.
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test fixture.
	}

	/**
	 * The other half: the copy this suite runs (0.6.1, the one Gravity Forms
	 * sites run) still gets the route, so the probe did not switch the
	 * adapter path off for everyone.
	 */
	public function test_the_current_adapter_is_still_handed_the_route() {
		$this->assertTrue( Saddle::adapter_is_current() );
		$this->assertTrue( Saddle::adapter_available() );
	}
}

/**
 * The shape of MCP Adapter 0.1.0's McpPrompt: a snake_case from_array() and
 * no fromArray().
 */
class Saddle_Test_Adapter_010_Prompt {

	public static function from_array( array $data ) {
		return $data;
	}
}

/**
 * A current adapter's McpPrompt: has fromArray().
 */
class Saddle_Test_Adapter_Prompt {

	public static function fromArray( array $data ) {
		return $data;
	}
}

/**
 * MCP Adapter 0.6.1's McpServer: get_tools() takes nothing.
 */
class Saddle_Test_Adapter_061_Server {

	public function get_tools() {
		return array( 'saddle-get-site-info' => true );
	}

	public function get_prompts( $schema ) {
		return array( $schema );
	}
}

/**
 * MCP Adapter 0.7.0's McpServer: get_tools() wants the protocol schema.
 */
class Saddle_Test_Adapter_070_Server {

	public function get_tools( $schema ) {
		return array( $schema );
	}
}
