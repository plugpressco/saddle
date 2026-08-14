<?php
/**
 * The adapter transport — the one that actually serves /saddle/v1/mcp in
 * production, and which had no test coverage at all before this file.
 *
 * The existing mcp-transport-test.php calls Saddle_MCP::handle() directly, so it
 * only ever exercises the built-in JSON-RPC fallback. Everything here goes
 * through rest_do_request(), which means it hits whatever transport is really
 * registered on the route — the vendored WP\MCP adapter.
 *
 * @package Saddle
 */

class Saddle_MCP_Adapter_Transport_Test extends WP_UnitTestCase {

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		// Other suites leave Basic credentials on $_SERVER; a leftover changes
		// which credential scheme Saddle thinks it is looking at.
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'] );
	}

	public function tear_down() {
		delete_user_meta( $this->admin, 'mcp_adapter_sessions' );
		delete_option( Saddle_Capabilities::OPTION );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The tool list as plain arrays.
	 *
	 * The adapter returns DTOs that json_encode to objects, so a response read
	 * in-process is a mix of arrays and stdClass. Normalize once here rather
	 * than teaching every assertion about both shapes.
	 *
	 * @param WP_REST_Response $response Dispatched response.
	 * @return array
	 */
	private function tools_from( $response ) {
		$data = json_decode( wp_json_encode( $response->get_data() ), true );

		return isset( $data['result']['tools'] ) ? $data['result']['tools'] : array();
	}

	/**
	 * Dispatch a JSON-RPC message at the real MCP route.
	 *
	 * @param string $method  JSON-RPC method.
	 * @param array  $params  JSON-RPC params.
	 * @param array  $headers Extra request headers, e.g. Mcp-Session-Id.
	 * @return WP_REST_Response
	 */
	private function rpc( $method, array $params = array(), array $headers = array() ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$body = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
		);
		if ( ! empty( $params ) ) {
			$body['params'] = $params;
		}
		$request->set_body( wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * Run initialize and return the session id the adapter minted for it.
	 *
	 * The id normally reaches the client as a response header attached on
	 * rest_post_dispatch, which rest_do_request() never fires — so read it from
	 * where the adapter actually stored it.
	 *
	 * @return string
	 */
	private function initialize_and_get_session_id() {
		$this->rpc(
			'initialize',
			array(
				'protocolVersion' => '2025-06-18',
				'capabilities'    => array(),
				'clientInfo'      => array(
					'name'    => 'Test',
					'version' => '1',
				),
			)
		);

		$sessions = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $this->admin );
		$this->assertNotEmpty( $sessions, 'initialize must mint a session.' );

		return (string) array_key_first( $sessions );
	}

	/**
	 * The adapter — not the fallback — owns the route. If this ever fails, every
	 * other test in this file is testing the wrong transport.
	 */
	public function test_the_vendored_adapter_owns_the_mcp_route() {
		$this->assertTrue(
			class_exists( '\WP\MCP\Core\McpAdapter' ),
			'The bundled adapter must be loaded; without it these tests silently exercise the fallback.'
		);

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/saddle/v1/mcp', $routes );
	}

	/**
	 * THE BUG (issue #80). ChatGPT issues a bare tools/list, with no
	 * Mcp-Session-Id header. The vendored adapter refuses it before it ever
	 * reaches the tools handler, so the connector shows as connected while
	 * reporting that the site "exposes no callable WordPress actions".
	 *
	 * Pinned here as it actually behaves today, per the house rule, so the fix
	 * has something to flip.
	 */
	public function test_tools_list_without_a_session_header_is_refused_today() {
		$response = $this->rpc( 'tools/list' );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'Mcp-Session-Id', $data['error']['message'] );
	}

	/**
	 * The same call succeeds with the session header — which is what proves the
	 * refusal above is about the header and not about registration, auth, or an
	 * empty tool list.
	 */
	public function test_tools_list_with_a_session_header_returns_every_saddle_tool() {
		$session_id = $this->initialize_and_get_session_id();

		$response = $this->rpc( 'tools/list', array(), array( 'Mcp-Session-Id' => $session_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->tools_from( $response ), 'The adapter must list Saddle abilities as tools.' );
	}

	/**
	 * Tool names must satisfy both the MCP charset and OpenAI's stricter
	 * ^[a-zA-Z0-9_-]{1,64}$ — a slash-bearing name is rejected outright by the
	 * client we are trying to support, and would fail silently at our end.
	 */
	public function test_every_tool_name_is_legal_for_a_strict_client() {
		$session_id = $this->initialize_and_get_session_id();

		$tools = $this->tools_from( $this->rpc( 'tools/list', array(), array( 'Mcp-Session-Id' => $session_id ) ) );

		$this->assertNotEmpty( $tools );

		foreach ( $tools as $tool ) {
			$this->assertMatchesRegularExpression(
				'/^[a-zA-Z0-9_-]{1,64}$/',
				$tool['name'],
				sprintf( 'Tool name %s is not accepted by strict clients.', $tool['name'] )
			);
		}
	}
}
