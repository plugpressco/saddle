<?php
/**
 * Built-in JSON-RPC transport (the fallback used when the MCP Adapter library
 * isn't present). Focuses on the initialize handshake: protocol negotiation and
 * the steering instructions delivered to the client.
 *
 * @package Saddle
 */

class Saddle_MCP_Transport_Test extends WP_UnitTestCase {

	private function initialize( $protocol_version = null ) {
		$params = array(
			'capabilities' => array(),
			'clientInfo'   => array( 'name' => 'Test', 'version' => '1' ),
		);
		if ( null !== $protocol_version ) {
			$params['protocolVersion'] = $protocol_version;
		}

		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => $params,
				)
			)
		);

		return Saddle_MCP::handle( $req )->get_data()['result'];
	}

	public function test_initialize_echoes_a_supported_requested_protocol_version() {
		$result = $this->initialize( '2025-06-18' );
		$this->assertSame( '2025-06-18', $result['protocolVersion'], 'A supported client version must be echoed back.' );
	}

	public function test_initialize_falls_back_to_newest_for_unknown_version() {
		$result = $this->initialize( '1999-01-01' );
		$this->assertSame( '2025-11-25', $result['protocolVersion'], 'An unknown client version must fall back to the newest supported.' );
	}

	public function test_initialize_defaults_when_no_version_requested() {
		$result = $this->initialize( null );
		$this->assertSame( '2025-11-25', $result['protocolVersion'] );
	}

	public function test_initialize_advertises_steering_instructions() {
		$result = $this->initialize( '2025-11-25' );
		$this->assertArrayHasKey( 'instructions', $result );
		$this->assertNotEmpty( $result['instructions'] );
		// The instructions reuse Saddle_Context, which always names the scope.
		$this->assertStringContainsString( 'posts', strtolower( $result['instructions'] ) );
	}

	/**
	 * Drive a tools/call through the built-in JSON-RPC transport and return the
	 * decoded JSON-RPC envelope (result or error).
	 *
	 * @param string $tool_name Full ability id, e.g. 'saddle/create-post'.
	 * @param array  $arguments Tool arguments.
	 * @return array Decoded JSON-RPC response.
	 */
	private function call_tool( $tool_name, array $arguments = array() ) {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 7,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => $tool_name,
						'arguments' => $arguments,
					),
				)
			)
		);

		return Saddle_MCP::handle( $req )->get_data();
	}

	/**
	 * Regression pin for the permission contract the fallback transport relies
	 * on: WP_Ability::execute() must run the permission_callback and return a
	 * WP_Error BEFORE the execute_callback runs. If a core change ever broke
	 * that, every tier/approval check behind this transport would silently
	 * vanish — verified true on WP 6.9 core, and this test keeps it that way.
	 *
	 * A write-tier tool called while the site sits at the default `read` tier
	 * (by an administrator, so capabilities are NOT the limiting factor) must be
	 * refused, and must not create anything.
	 */
	public function test_tools_call_enforces_tier_before_executing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'read' );

		$before = (int) wp_count_posts( 'post' )->publish;

		$response = $this->call_tool(
			'saddle/create-post',
			array(
				'title'   => 'Should never be created',
				'content' => 'Blocked by the read tier.',
				'status'  => 'publish',
			)
		);

		$this->assertArrayHasKey( 'error', $response, 'A tier-denied tool call must return a JSON-RPC error envelope.' );
		$this->assertArrayNotHasKey( 'result', $response );

		$after = (int) wp_count_posts( 'post' )->publish;
		$this->assertSame( $before, $after, 'A denied tool call must not create a post — proving the permission gate ran before the execute callback.' );

		wp_set_current_user( 0 );
	}

	/**
	 * The same path succeeds once the tier permits it — proving the test drives
	 * a real create through the transport, so the denial above is meaningful.
	 */
	public function test_tools_call_succeeds_when_tier_permits() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );

		$response = $this->call_tool(
			'saddle/create-post',
			array(
				'title'   => 'Allowed at write tier',
				'content' => 'Created through the fallback transport.',
				'status'  => 'draft',
			)
		);

		$this->assertArrayHasKey( 'result', $response, 'A permitted tool call must return a JSON-RPC result envelope.' );
		$this->assertArrayNotHasKey( 'error', $response );

		wp_set_current_user( 0 );
	}

	/* -------- agent-legible denials (issue: connections hardening) -------- */

	public function test_tier_denial_explains_the_access_level_to_the_agent() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'read' );

		$response = $this->call_tool(
			'saddle/create-post',
			array(
				'title'  => 'x',
				'status' => 'draft',
			)
		);

		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( 'saddle_tier_denied', $response['error']['data']['wp_error_code'] );
		$this->assertStringContainsString( 'access level', $response['error']['message'] );
		$this->assertStringContainsString( 'Do not retry', $response['error']['message'] );

		wp_set_current_user( 0 );
	}

	public function test_paused_denial_tells_the_agent_saddle_is_paused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_paused( true );

		$response = $this->call_tool( 'saddle/get-site-info', array() );

		Saddle_Capabilities::set_paused( false );

		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( 'saddle_paused', $response['error']['data']['wp_error_code'] );
		$this->assertStringContainsString( 'paused', $response['error']['message'] );

		wp_set_current_user( 0 );
	}

	public function test_disabled_tool_denial_names_the_toggle() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_disabled_abilities( array( 'create-post' ) );

		$response = $this->call_tool(
			'saddle/create-post',
			array(
				'title'  => 'x',
				'status' => 'draft',
			)
		);

		Saddle_Capabilities::set_disabled_abilities( array() );

		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( 'saddle_tool_disabled', $response['error']['data']['wp_error_code'] );
		$this->assertStringContainsString( 'create-post', $response['error']['message'] );
		$this->assertStringContainsString( 'turned', $response['error']['message'] );

		wp_set_current_user( 0 );
	}
}
