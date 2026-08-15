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
	 * The handshake runs before any ability's permission_callback — its only
	 * check is that someone is logged in. So it must not become the way around
	 * the switch the owner flipped to stop everything.
	 */
	public function test_initialize_on_a_paused_site_withholds_the_site_context() {
		Saddle_Capabilities::set_paused( true );

		$result = $this->initialize( '2025-11-25' );

		Saddle_Capabilities::set_paused( false );

		$this->assertStringContainsString( 'paused', $result['instructions'] );
		$this->assertStringNotContainsString( 'About this WordPress site', $result['instructions'] );
	}

	/**
	 * And it must never hand out more than the equivalent ability would at the
	 * same tier — it used to serve the whole context plus the owner's private
	 * instructions, with fewer checks than get-instructions.
	 */
	public function test_initialize_does_not_leak_inventory_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$result = $this->initialize( '2025-11-25' );

		$this->assertStringNotContainsString( 'Plugins active on this site', $result['instructions'] );
	}

	/**
	 * Drive a tools/list through the built-in transport.
	 *
	 * @return array List of tool definitions.
	 */
	private function list_tools() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list' ) ) );

		$data = json_decode( wp_json_encode( Saddle_MCP::handle( $req )->get_data() ), true );

		return isset( $data['result']['tools'] ) ? $data['result']['tools'] : array();
	}

	/**
	 * This transport is what WordPress.org installs get, so its tool list has to
	 * be non-empty — an assertion that did not exist while the adapter was the
	 * only path anyone looked at.
	 */
	public function test_tools_list_is_not_empty() {
		$this->assertNotEmpty( $this->list_tools() );
	}

	/**
	 * Ability names carry a slash; MCP tool names may not, and OpenAI's clients
	 * reject the entire list over one bad name. This is the assertion that would
	 * have caught it.
	 */
	public function test_every_tool_name_is_legal_for_a_strict_client() {
		foreach ( $this->list_tools() as $tool ) {
			$this->assertMatchesRegularExpression(
				'/^[a-zA-Z0-9_-]{1,64}$/',
				$tool['name'],
				sprintf( 'Tool name %s is not accepted by strict clients.', $tool['name'] )
			);
		}
	}

	/**
	 * Saddle already knows whether each ability reads or destroys — the client
	 * should be told, so an agent can weigh a call instead of learning from a
	 * refusal.
	 */
	public function test_tools_carry_a_title_and_behaviour_hints() {
		$byname = array();
		foreach ( $this->list_tools() as $tool ) {
			$byname[ $tool['name'] ] = $tool;
		}

		$this->assertArrayHasKey( 'saddle-get-site-info', $byname );
		$read = $byname['saddle-get-site-info'];
		$this->assertNotEmpty( $read['title'] );
		$this->assertTrue( $read['annotations']['readOnlyHint'] );
		$this->assertFalse( $read['annotations']['destructiveHint'] );

		$this->assertArrayHasKey( 'saddle-delete-post', $byname );
		$this->assertTrue( $byname['saddle-delete-post']['annotations']['destructiveHint'] );
	}

	/**
	 * A client that cached either name form keeps working.
	 */
	public function test_a_tool_can_be_called_by_either_name_form() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( 'saddle-get-site-info', 'saddle/get-site-info' ) as $name ) {
			$response = $this->call_tool( $name );
			$this->assertArrayHasKey( 'result', $response, sprintf( '%s should resolve to the ability.', $name ) );
			$this->assertFalse( $response['result']['isError'] );
		}

		wp_set_current_user( 0 );
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

		// A refusal now comes back as a tool RESULT carrying isError, not a
		// JSON-RPC error envelope. MCP reserves protocol errors for protocol
		// faults, and several clients hide a JSON-RPC error from the model
		// entirely — which meant the agent never read the one message written
		// to tell it what to do instead.
		$this->assertArrayHasKey( 'result', $response, 'A tier denial is a tool result, not a protocol fault.' );
		$this->assertArrayNotHasKey( 'error', $response );
		$this->assertTrue( $response['result']['isError'] );
		$this->assertSame( 'saddle_tier_denied', $response['result']['_meta']['saddle']['wp_error_code'] );

		$after = (int) wp_count_posts( 'post' )->publish;
		$this->assertSame( $before, $after, 'A denied tool call must not create a post — proving the permission gate ran before the execute callback.' );

		wp_set_current_user( 0 );
	}

	/**
	 * The refusal text has to reach the model, because it is written for the
	 * model: it names the gate and tells it not to retry.
	 */
	public function test_a_refusal_puts_its_reason_in_readable_content() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'read' );

		$response = $this->call_tool( 'saddle/create-post', array( 'title' => 'x', 'status' => 'draft' ) );

		$text = $response['result']['content'][0]['text'];
		$this->assertStringContainsString( 'access level', $text );
		$this->assertStringContainsString( 'Do not retry', $text );

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

		$this->assertTrue( $response['result']['isError'] );
		$this->assertSame( 'saddle_tier_denied', $response['result']['_meta']['saddle']['wp_error_code'] );
		$this->assertStringContainsString( 'access level', $response['result']['content'][0]['text'] );
		$this->assertStringContainsString( 'Do not retry', $response['result']['content'][0]['text'] );

		wp_set_current_user( 0 );
	}

	public function test_paused_denial_tells_the_agent_saddle_is_paused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_paused( true );

		$response = $this->call_tool( 'saddle/get-site-info', array() );

		Saddle_Capabilities::set_paused( false );

		$this->assertTrue( $response['result']['isError'] );
		$this->assertSame( 'saddle_paused', $response['result']['_meta']['saddle']['wp_error_code'] );
		$this->assertStringContainsString( 'paused', $response['result']['content'][0]['text'] );

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

		$this->assertTrue( $response['result']['isError'] );
		$this->assertSame( 'saddle_tool_disabled', $response['result']['_meta']['saddle']['wp_error_code'] );
		$this->assertStringContainsString( 'create-post', $response['result']['content'][0]['text'] );
		$this->assertStringContainsString( 'turned', $response['result']['content'][0]['text'] );

		wp_set_current_user( 0 );
	}
}
