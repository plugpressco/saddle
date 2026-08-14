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
	 * Mcp-Session-Id header. The vendored adapter refused it before it ever
	 * reached the tools handler — HTTP 400, "Missing Mcp-Session-Id header" —
	 * so the connector showed as connected while reporting that the site
	 * "exposes no callable WordPress actions".
	 */
	public function test_tools_list_without_a_session_header_now_lists_tools() {
		$response = $this->rpc( 'tools/list' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->tools_from( $response ), 'A stateless client must still get the tool list.' );
	}

	/**
	 * The other half of the same gate: an Mcp-Protocol-Version the adapter does
	 * not know hard-rejects every non-initialize call. 2025-03-26 is a real,
	 * widely-deployed revision that the vendored list omits.
	 */
	public function test_an_unsupported_protocol_version_header_no_longer_rejects() {
		$response = $this->rpc( 'tools/list', array(), array( 'Mcp-Protocol-Version' => '2025-03-26' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->tools_from( $response ) );
	}

	/**
	 * A version the adapter does support must reach it untouched — the shim
	 * drops what would be refused, it does not blanket-strip the header.
	 */
	public function test_a_supported_protocol_version_header_survives() {
		$response = $this->rpc( 'tools/list', array(), array( 'Mcp-Protocol-Version' => '2025-06-18' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->tools_from( $response ) );
	}

	/**
	 * A session id that expired or was evicted (the adapter keeps 32 per user
	 * and drops them after a day idle) is healed rather than 404ing. This is
	 * the failure that looks intermittent: connected yesterday, dead this
	 * morning.
	 */
	public function test_a_stale_session_header_is_healed() {
		$response = $this->rpc(
			'tools/list',
			array(),
			array( 'Mcp-Session-Id' => '00000000-0000-4000-8000-000000000000' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->tools_from( $response ) );
	}

	/**
	 * A live client session must be passed through with nothing written. The
	 * shim exists for clients that cannot hold a session; it must be invisible
	 * to the ones that can.
	 */
	public function test_a_valid_client_session_is_left_untouched() {
		$session_id = $this->initialize_and_get_session_id();
		$before      = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $this->admin );

		$response = $this->rpc( 'tools/list', array(), array( 'Mcp-Session-Id' => $session_id ) );
		$after    = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $this->admin );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array_keys( $before ),
			array_keys( $after ),
			'Serving a client-held session must not mint another one.'
		);
	}

	/**
	 * Reuse before create. A session per request would churn the adapter's
	 * 32-entry FIFO continuously and write user meta on every call.
	 */
	public function test_many_stateless_requests_create_exactly_one_session() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->rpc( 'tools/list' );
		}

		$sessions = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $this->admin );

		$this->assertCount( 1, $sessions, 'Five stateless calls must share one session.' );
	}

	/**
	 * The shim must never be a way in. An unauthenticated caller is refused by
	 * the transport gate exactly as before, and — since writing user meta for
	 * anonymous callers would be a free denial-of-service — mints nothing.
	 */
	public function test_an_unauthenticated_request_is_refused_and_mints_nothing() {
		wp_set_current_user( 0 );

		$response = $this->rpc( 'tools/list' );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame(
			array(),
			\WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $this->admin )
		);
	}

	/**
	 * The shim moves two headers. It must not have moved the safety model with
	 * them: a write tool called statelessly at the default read tier is still
	 * refused, and still creates nothing.
	 */
	public function test_a_stateless_tools_call_is_still_tier_gated() {
		Saddle_Capabilities::set_tier( 'read' );

		$before = (int) wp_count_posts( 'post' )->publish;

		$response = $this->rpc(
			'tools/call',
			array(
				'name'      => 'saddle-create-post',
				'arguments' => array(
					'title'   => 'Should never be created',
					'content' => 'Blocked by the read tier.',
					'status'  => 'publish',
				),
			)
		);

		$data = json_decode( wp_json_encode( $response->get_data() ), true );

		$this->assertTrue(
			isset( $data['error'] ) || ! empty( $data['result']['isError'] ),
			'A write tool at the read tier must be refused over the stateless path too.'
		);
		$this->assertSame( $before, (int) wp_count_posts( 'post' )->publish );
	}

	/**
	 * And the same call succeeds once the tier allows it — which is what proves
	 * the refusal above is the tier gate and not the shim swallowing the call.
	 */
	public function test_a_stateless_tools_call_succeeds_when_the_tier_permits() {
		Saddle_Capabilities::set_tier( 'write' );

		$response = $this->rpc(
			'tools/call',
			array(
				'name'      => 'saddle-create-post',
				'arguments' => array(
					'title'   => 'Allowed at write tier',
					'content' => 'Created over the stateless path.',
					'status'  => 'draft',
				),
			)
		);

		$data = json_decode( wp_json_encode( $response->get_data() ), true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertEmpty( isset( $data['result']['isError'] ) ? $data['result']['isError'] : false );
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
