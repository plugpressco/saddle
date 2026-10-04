<?php
/**
 * #292: a refusal names its gate on the adapter path too.
 *
 * The vendored MCP adapter runs a tool's permission check itself, before the
 * call reaches the ability, and answered a refused check with a bare
 * "Permission denied". These tests go through rest_do_request(), so they hit
 * the adapter that really serves /saddle/v1/mcp, not the JSON-RPC fallback.
 *
 * @package Saddle
 */

class Saddle_Refusal_Reason_Adapter_Test extends WP_UnitTestCase {

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'] );

		// Re-added per test for the reason mcp-adapter-transport-test.php gives:
		// the filter snapshot can drop what the first REST dispatch added.
		add_filter( 'mcp_adapter_initialize_response', array( 'Saddle_MCP', 'filter_adapter_initialize' ), 10, 2 );
		add_filter( 'mcp_adapter_tools_list', array( 'Saddle_MCP', 'filter_adapter_tools_list' ), 10, 2 );
	}

	public function tear_down() {
		delete_user_meta( $this->admin, 'mcp_adapter_sessions' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Send a JSON-RPC message to the real MCP route.
	 *
	 * @param string $method  JSON-RPC method.
	 * @param array  $params  Params.
	 * @param array  $headers Extra headers.
	 * @return array The response, as plain arrays.
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
		if ( $params ) {
			$body['params'] = $params;
		}
		$request->set_body( wp_json_encode( $body ) );

		return json_decode( wp_json_encode( rest_do_request( $request )->get_data() ), true );
	}

	/**
	 * Start a session and call one tool in it.
	 *
	 * @param string $tool      MCP tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array The tools/call result.
	 */
	private function call( $tool, array $arguments ) {
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

		$response = $this->rpc(
			'tools/call',
			array(
				'name'      => $tool,
				'arguments' => $arguments,
			),
			array( 'Mcp-Session-Id' => (string) array_key_first( $sessions ) )
		);
		$this->assertArrayHasKey( 'result', $response, wp_json_encode( $response ) );

		return $response['result'];
	}

	/** The text of a tool result. */
	private function text( array $result ) {
		return implode( ' ', wp_list_pluck( isset( $result['content'] ) ? $result['content'] : array(), 'text' ) );
	}

	/**
	 * The issue's repro: Read only, a direct call to a write tool. Regression:
	 * the answer was a bare "Permission denied".
	 */
	public function test_a_refused_call_on_the_adapter_names_the_access_level() {
		Saddle_Capabilities::set_tier( 'read' );

		$result = $this->call( 'saddle-create-post', array( 'title' => 'Hello' ) );

		$this->assertTrue( $result['isError'] );
		$reason = Saddle_Capabilities::denial_reason( 'saddle/create-post' );
		$this->assertSame( 'saddle_tier_denied', $reason['code'] );
		$this->assertSame( $reason['message'], $this->text( $result ) );
		$this->assertStringNotContainsString( 'Permission denied', $this->text( $result ) );
		$this->assertSame( 0, (int) wp_count_posts( 'post' )->draft, 'Nothing was created.' );
	}

	public function test_a_tool_the_owner_turned_off_says_so_on_the_adapter() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_disabled_abilities( array( 'create-post' ) );

		$result = $this->call( 'saddle-create-post', array( 'title' => 'Hello' ) );

		$this->assertTrue( $result['isError'] );
		$this->assertSame( Saddle_Capabilities::denial_reason( 'saddle/create-post' )['message'], $this->text( $result ) );
		$this->assertStringContainsString( 'turned the "create-post" tool off', $this->text( $result ) );
	}

	public function test_pause_says_so_on_the_adapter() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_paused( true );

		$result = $this->call( 'saddle-get-site-info', array() );

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'paused', $this->text( $result ) );
	}

	/** An allowed call is untouched. */
	public function test_an_allowed_call_runs_on_the_adapter() {
		Saddle_Capabilities::set_tier( 'read' );

		$result = $this->call( 'saddle-get-site-info', array() );

		$this->assertEmpty( isset( $result['isError'] ) ? $result['isError'] : false );
	}

	/**
	 * Every other caller keeps the old contract: false from check_permissions(),
	 * and execute()'s generic refusal with no _doing_it_wrong() notice (the
	 * test case fails on an unexpected one).
	 */
	public function test_other_callers_still_get_false() {
		Saddle_Capabilities::set_tier( 'read' );
		$ability = wp_get_ability( 'saddle/create-post' );

		$this->assertFalse( $ability->check_permissions( array( 'title' => 'Hello' ) ) );

		$result = $ability->execute( array( 'title' => 'Hello' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
