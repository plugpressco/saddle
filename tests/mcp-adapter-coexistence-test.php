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
}
