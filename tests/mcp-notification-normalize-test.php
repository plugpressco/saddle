<?php
/**
 * The notification acknowledgement, enforced independently of the transport.
 *
 * Saddle answers a JSON-RPC notification with 202 and no body — but only its
 * OWN transport ran that code. When the official MCP Adapter plugin is
 * installed, saddle.php hands the whole request to it and none of Saddle's
 * spec handling is in play, so the answer is that plugin's, at whatever
 * version the site has. A tester on Codex hit exactly that: `initialize` 200,
 * then `notifications/initialized` 200 with an empty body, then
 * "EOF while parsing a value" — which is what a strict client does when it
 * tries to JSON-parse nothing.
 *
 * These drive the real filter chain rather than Saddle_MCP::handle(), because
 * the whole point is the case where handle() never runs.
 *
 * @package Saddle
 */

class Saddle_MCP_Notification_Normalize_Test extends WP_UnitTestCase {

	/**
	 * The guards are hooked on `rest_api_init`, which fires exactly once per
	 * process because `rest_get_server()` memoizes the server — while
	 * WP_UnitTestCase restores `$wp_filter` after every test. So without this,
	 * only whichever test ran first would have them registered. Re-registering
	 * is idempotent: add_filter with the same callback and priority replaces.
	 */
	public function set_up() {
		parent::set_up();
		rest_get_server();
		Saddle_MCP::register_spec_guards();
	}

	private function request( array $body, $route = '/saddle/v1/mcp' ) {
		$req = new WP_REST_Request( 'POST', $route );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return $req;
	}

	/**
	 * Stands in for a transport that answered the notification wrongly — the
	 * adapter's job on that path, and the exact shape the tester reported.
	 */
	private function dispatched( WP_REST_Request $request, $status = 200, $data = null ) {
		return apply_filters(
			'rest_post_dispatch',
			new WP_REST_Response( $data, $status ),
			rest_get_server(),
			$request
		);
	}

	/* -------- the bug -------- */

	public function test_a_notification_answered_200_is_corrected_to_202() {
		$request  = $this->request(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			)
		);
		$response = $this->dispatched( $request, 200 );

		$this->assertSame(
			202,
			$response->get_status(),
			'A notification must leave Saddle as 202 whichever transport answered it.'
		);
	}

	public function test_an_all_notification_batch_answered_200_is_corrected() {
		$request  = $this->request(
			array(
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/cancelled' ),
			)
		);
		$response = $this->dispatched( $request, 200 );

		$this->assertSame( 202, $response->get_status() );
	}

	public function test_the_corrected_202_carries_no_body() {
		$request  = $this->request(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			)
		);
		$response = $this->dispatched( $request, 200 );

		$served = apply_filters( 'rest_pre_serve_request', false, $response, $request, rest_get_server() );

		$this->assertTrue(
			$served,
			'The acknowledgement must be reported as already served, so WordPress prints nothing.'
		);
	}

	/* -------- and nothing else moves -------- */

	public function test_a_real_request_on_our_route_is_left_alone() {
		$request  = $this->request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			)
		);
		$response = $this->dispatched( $request, 200, array( 'result' => array() ) );

		$this->assertSame( 200, $response->get_status(), 'A call with an id is not a notification.' );
		$this->assertNotNull( $response->get_data(), 'A real response keeps its envelope.' );
	}

	public function test_a_batch_containing_a_real_call_is_left_alone() {
		$request  = $this->request(
			array(
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
				array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ),
			)
		);
		$response = $this->dispatched( $request, 200 );

		$this->assertSame(
			200,
			$response->get_status(),
			'A batch that contains one real call expects a real response.'
		);
	}

	public function test_a_notification_shaped_body_on_someone_elses_route_is_left_alone() {
		$request  = $this->request(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			),
			'/wp/v2/posts'
		);
		$response = $this->dispatched( $request, 200 );

		$this->assertSame(
			200,
			$response->get_status(),
			'Saddle must only ever touch its own MCP route.'
		);
	}

	public function test_a_non_json_body_on_our_route_is_left_alone() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( 'not json at all' );

		$response = $this->dispatched( $req, 400 );

		$this->assertSame( 400, $response->get_status(), 'An unparseable body keeps its error status.' );
	}

	/**
	 * The guard that stops this emptying the next response in the same
	 * process — the failure mode the own-transport version was careful about.
	 */
	public function test_a_non_202_response_is_never_emptied() {
		$request  = $this->request( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ) );
		$response = $this->dispatched( $request, 200, array( 'result' => 'pong' ) );

		$served = apply_filters( 'rest_pre_serve_request', false, $response, $request, rest_get_server() );

		$this->assertFalse( $served, 'Only the 202 acknowledgement may be short-circuited.' );
	}
}
