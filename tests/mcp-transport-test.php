<?php
/**
 * Built-in JSON-RPC transport (the fallback used when the MCP Adapter library
 * isn't present). Focuses on the initialize handshake: protocol negotiation and
 * the steering instructions delivered to the client.
 *
 * @package Saddle
 */

class Saddle_MCP_Transport_Test extends WP_UnitTestCase {

	/**
	 * tools/list is only reachable through the transport gate, which requires
	 * an authenticated user — and the list is now filtered to what that
	 * credential can call. So the baseline for these tests is a real
	 * connection: an administrator on an admin-tier site, which is what the
	 * "every tool is listed" assertions below have always assumed.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'admin' );
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		parent::tear_down();
	}

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

	/* -------- Streamable HTTP conformance (issue #97) -------- */

	/**
	 * POST a raw JSON-RPC body and return the whole response object, so a test
	 * can assert on the STATUS as well as the payload. list_tools() below reads
	 * only the body, which is how a transport could go years answering the
	 * wrong status codes without a red test.
	 *
	 * @param array|string $body    JSON-RPC body (array is encoded).
	 * @param array        $headers Extra request headers.
	 * @return WP_REST_Response
	 */
	private function post( $body, array $headers = array() ) {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$req->set_header( $name, $value );
		}
		$req->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );

		return Saddle_MCP::handle( $req );
	}

	/**
	 * THE BUG (issue #97). Every client's handshake is initialize →
	 * notifications/initialized → tools/list. The middle step is a
	 * notification, and the spec is unambiguous: "If the input is a JSON-RPC
	 * response or notification … the server MUST return HTTP status code 202
	 * Accepted with no body."
	 *
	 * Saddle answered 200 with the JSON literal `null`. mcp-remote shrugs and
	 * carries on — which is why Claude Desktop worked against the very same
	 * site. A strict client treats the handshake as unfinished and never sends
	 * tools/list, which reaches the user as "connected, but it exposes no
	 * callable actions".
	 */
	public function test_a_notification_is_accepted_with_202_and_no_body() {
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			)
		);

		$this->assertSame( 202, $response->get_status(), 'A notification must be acknowledged with 202 Accepted.' );
		$this->assertNull( $response->get_data(), 'A 202 acknowledgement must carry no body.' );
	}

	public function test_an_all_notification_batch_is_also_202() {
		$response = $this->post(
			array(
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/cancelled' ),
			)
		);

		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	/**
	 * The other half of the 202 change: a real request must still come back
	 * 200 with its envelope. Suppressing the body is scoped to the
	 * acknowledgement, and a filter that leaked would empty every response.
	 */
	public function test_a_real_request_still_returns_200_with_its_envelope() {
		$response = $this->post( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2.0', $response->get_data()['jsonrpc'] );
	}

	/**
	 * The status is only half of it: "with no body" is delivered by a
	 * rest_pre_serve_request short-circuit, which lives in the serving stage
	 * that handle() never reaches. These drive that filter directly, because
	 * a 202 whose body still says `null` would look identical in every other
	 * assertion here.
	 */
	public function test_the_acknowledgement_body_is_suppressed_at_the_serving_stage() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );

		$this->assertTrue(
			Saddle_MCP::serve_empty_acknowledgement( false, new WP_REST_Response( null, 202 ), $request ),
			'Reporting the response as already served is what stops WordPress printing "null".'
		);
	}

	public function test_the_suppressor_leaves_every_other_response_alone() {
		$ours = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );

		$this->assertFalse(
			Saddle_MCP::serve_empty_acknowledgement( false, new WP_REST_Response( array( 'jsonrpc' => '2.0' ), 200 ), $ours ),
			'A real JSON-RPC response must still be printed.'
		);

		$this->assertFalse(
			Saddle_MCP::serve_empty_acknowledgement( false, new WP_REST_Response( array( 'x' => 1 ), 202 ), new WP_REST_Request( 'POST', '/wp/v2/posts' ) ),
			'A 202 from someone else’s route is none of our business.'
		);

		$this->assertTrue(
			Saddle_MCP::serve_empty_acknowledgement( true, new WP_REST_Response( null, 202 ), $ours ),
			'Already served by someone else stays served.'
		);
	}

	/**
	 * The full handshake, in order. Nothing exercised this sequence end to end
	 * before, which is exactly how a break in the middle of it shipped.
	 */
	public function test_the_whole_handshake_runs_and_ends_with_tools() {
		$init = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array( 'protocolVersion' => '2025-06-18' ),
			)
		);
		$this->assertSame( 200, $init->get_status() );
		$this->assertSame( '2025-06-18', $init->get_data()['result']['protocolVersion'] );

		$ack = $this->post( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
		$this->assertSame( 202, $ack->get_status(), 'The step between "connected" and "has tools".' );

		$list = $this->post( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) );
		$this->assertSame( 200, $list->get_status() );
		$this->assertNotEmpty( $list->get_data()['result']['tools'] );
	}

	/**
	 * "The server MUST either return Content-Type: text/event-stream in
	 * response to this HTTP GET, or else return HTTP 405 Method Not Allowed."
	 * Saddle has no stream to offer, so 405 it is.
	 *
	 * A warning for anyone changing this: leaving GET unregistered LOOKS like
	 * it satisfies the spec, because WP_REST_Server::dispatch() answers a
	 * method mismatch with 405 — so a test written against dispatch() passes
	 * while real clients get 404 rest_no_route. That is exactly the false
	 * negative this test used to give, and only a curl against a real install
	 * caught it. Assert on the Allow header too: it is the part that proves
	 * our own handler produced this rather than core's routing.
	 */
	public function test_get_and_delete_are_refused_with_405() {
		foreach ( array( 'GET', 'DELETE' ) as $method ) {
			$response = Saddle_MCP::refuse_method( new WP_REST_Request( $method, '/saddle/v1/mcp' ) );

			$this->assertSame( 405, $response->get_status(), "{$method} must be refused with 405, not 404." );
			$this->assertSame( -32600, $response->get_data()['error']['code'] );
		}
	}

	/**
	 * The handler is only half of it — it has to be ROUTED to, and this suite
	 * cannot prove that: the dev tree contains the vendored adapter, so
	 * Saddle::setup_mcp_transport() hands /saddle/v1/mcp to the adapter and
	 * Saddle_MCP::register_routes() never runs here. Dispatching through
	 * rest_get_server() in this file reaches the ADAPTER's route, not ours.
	 *
	 * That is precisely how the 404 got missed: dispatch() answers a method
	 * mismatch with 405 regardless, so the old test passed while real clients
	 * on a .org build got 404. The routing half is verified with curl against
	 * a Playground install running the built wporg zip — see the PR.
	 */
	public function test_this_suite_exercises_the_builtin_handler_not_the_adapter_route() {
		$this->assertTrue(
			Saddle::adapter_available(),
			'If this ever fails, the note above is stale and these tests changed meaning.'
		);
	}

	/**
	 * Registering GET must not turn the endpoint into an unauthenticated probe
	 * that confirms Saddle is installed here.
	 */
	public function test_an_unauthenticated_get_is_still_401() {
		$current = get_current_user_id();
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/mcp' ) );

		wp_set_current_user( $current );

		$this->assertSame( 401, $response->get_status(), 'Authentication comes before method negotiation.' );
	}

	/**
	 * Saddle advertises only the tools capability, so a conformant client
	 * should never ask — but Mark's ChatGPT demonstrably probes all three, and
	 * Method-not-found is a poor answer to give a client mid-handshake. The
	 * vendored adapter already answers two of these with empty lists, so this
	 * also closes a difference between Saddle's two transports.
	 */
	public function test_resource_and_prompt_probes_get_empty_lists_not_method_not_found() {
		foreach ( array(
			'resources/list'           => 'resources',
			'resources/templates/list' => 'resourceTemplates',
			'prompts/list'             => 'prompts',
		) as $method => $key ) {
			$data = $this->post( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => $method ) )->get_data();

			$this->assertArrayNotHasKey( 'error', $data, "{$method} must not answer Method not found." );
			$this->assertSame( array(), $data['result'][ $key ], "{$method} must answer with an empty list." );
		}
	}

	/**
	 * An unknown method that ISN'T one of those probes must still be refused —
	 * answering everything would be worse than answering nothing.
	 */
	public function test_a_genuinely_unknown_method_is_still_refused() {
		$data = $this->post( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'completion/complete' ) )->get_data();

		$this->assertSame( -32601, $data['error']['code'] );
	}

	/**
	 * "If the server receives a request with an invalid or unsupported
	 * MCP-Protocol-Version, it MUST respond with 400 Bad Request."
	 */
	public function test_an_unsupported_protocol_version_header_is_400() {
		$response = $this->post(
			array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping' ),
			array( 'MCP-Protocol-Version' => '1999-01-01' )
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_supported_protocol_version_header_passes_and_is_echoed() {
		$response = $this->post(
			array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'ping' ),
			array( 'MCP-Protocol-Version' => '2025-06-18' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2025-06-18', $response->get_headers()['MCP-Protocol-Version'] );
	}

	/**
	 * Absent the header the spec says assume 2025-03-26 — i.e. carry on, never
	 * refuse. Every Application Password client in the field omits it.
	 */
	public function test_a_missing_protocol_version_header_is_fine() {
		$this->assertSame( 200, $this->post( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping' ) )->get_status() );
	}

	public function test_an_unparseable_body_is_400() {
		$response = $this->post( 'this is not json' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32700, $response->get_data()['error']['code'] );
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

	/* -------- the list is what this credential can call, nothing more -------- */

	/**
	 * The default install sits at `read`, and a third of the free toolset needs
	 * more than that. Advertising those tools spends a schema each on a
	 * guaranteed refusal.
	 */
	public function test_read_tier_is_not_offered_the_tools_it_cannot_call() {
		Saddle_Capabilities::set_tier( 'read' );

		$names = wp_list_pluck( $this->list_tools(), 'name' );

		$this->assertNotEmpty( $names, 'A read-tier connection still gets the whole read surface.' );
		$this->assertContains( 'saddle-get-site-info', $names );
		$this->assertContains( 'saddle-list-posts', $names );

		$this->assertNotContains( 'saddle-create-post', $names, 'A write tool must not be advertised at the read tier.' );
		$this->assertNotContains( 'saddle-delete-post', $names );
		$this->assertNotContains( 'saddle-list-plugins', $names, 'Nor an admin tool.' );
	}

	public function test_raising_the_tier_offers_the_write_tools() {
		Saddle_Capabilities::set_tier( 'write' );

		$names = wp_list_pluck( $this->list_tools(), 'name' );

		$this->assertContains( 'saddle-create-post', $names );
		$this->assertNotContains( 'saddle-list-plugins', $names, 'write is not admin.' );
	}

	public function test_a_tool_the_owner_switched_off_is_not_advertised() {
		Saddle_Capabilities::set_disabled_abilities( array( 'delete-post' ) );

		$names = wp_list_pluck( $this->list_tools(), 'name' );

		$this->assertNotContains( 'saddle-delete-post', $names );
		$this->assertContains( 'saddle-delete-page', $names, 'Only the tool that was switched off disappears.' );
	}

	/**
	 * Pause denies everything anyway, so emptying the list would buy nothing
	 * and would force every connected client to reconnect on resume. The
	 * instructions carry the warning instead.
	 */
	public function test_pause_leaves_the_tool_list_intact() {
		Saddle_Capabilities::set_paused( true );

		$names = wp_list_pluck( $this->list_tools(), 'name' );

		Saddle_Capabilities::set_paused( false );

		$this->assertContains( 'saddle-get-site-info', $names );
	}

	/**
	 * Filtering costs the agent the ability to say "that exists, you just
	 * haven't enabled it" — so the instructions have to say it instead.
	 */
	public function test_the_instructions_say_how_many_tools_are_withheld() {
		Saddle_Capabilities::set_tier( 'read' );

		$instructions = $this->initialize( '2025-11-25' )['instructions'];

		$this->assertStringContainsString( 'not offered to you', $instructions );
		$this->assertStringContainsString( 'Saddle → Permissions', $instructions );
	}

	public function test_nothing_is_claimed_to_be_withheld_when_nothing_is() {
		// Administrator at the admin tier with no toggles: the list is complete,
		// so the context must not invent a shortfall.
		$this->assertStringNotContainsString( 'not offered to you', $this->initialize( '2025-11-25' )['instructions'] );
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
