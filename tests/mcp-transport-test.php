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
}
