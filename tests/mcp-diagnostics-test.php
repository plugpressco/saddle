<?php
/**
 * The MCP diagnostics record: what it captures, what it must never capture,
 * and the property that makes it worth having — that a refused tools/list and
 * an empty one look different.
 *
 * @package Saddle
 */

class Saddle_MCP_Diagnostics_Test extends WP_UnitTestCase {

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

		// See the note in mcp-adapter-transport-test.php: the harness restores
		// the filter table to a snapshot taken before these were registered.
		Saddle_MCP_Diagnostics::register();
	}

	public function tear_down() {
		Saddle_MCP_Diagnostics::stop_recording();
		Saddle_MCP_Diagnostics::clear();
		delete_option( Saddle_MCP_Diagnostics::HEALTH_OPTION );
		delete_user_meta( $this->admin, 'mcp_adapter_sessions' );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Dispatch a JSON-RPC message at the MCP route, through the same filter
	 * chain a served request runs.
	 *
	 * rest_do_request() calls dispatch() but not serve_request(), so
	 * rest_post_dispatch — where the recorder closes an entry, and where the
	 * OAuth challenge is attached — never fires on its own. Applying it here
	 * drives the real callbacks rather than re-implementing them, which is the
	 * pattern oauth-bearer-test.php already uses.
	 *
	 * @param string $method  JSON-RPC method.
	 * @param array  $headers Extra request headers.
	 * @return WP_REST_Response
	 */
	private function rpc( $method, array $headers = array() ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
				)
			)
		);

		$response = rest_do_request( $request );

		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	}

	/**
	 * Off unless asked for, and silent while off — the always-on half of this
	 * feature is the health record, not the trace.
	 */
	public function test_recording_is_off_by_default_and_writes_nothing() {
		$this->assertFalse( Saddle_MCP_Diagnostics::is_recording() );

		$this->rpc( 'tools/list' );

		$this->assertSame( array(), Saddle_MCP_Diagnostics::entries() );
		$this->assertFalse( get_option( Saddle_MCP_Diagnostics::TRACE_OPTION ) );
	}

	/**
	 * The row has to carry enough to diagnose from: what was called, whether
	 * the client held a session, and what it got back.
	 */
	public function test_a_recorded_request_names_the_method_session_and_status() {
		Saddle_MCP_Diagnostics::start_recording();

		$this->rpc( 'tools/list' );

		$entries = Saddle_MCP_Diagnostics::entries();
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertContains( 'tools/list', $entry['methods'] );
		$this->assertSame( 'absent', $entry['session'], 'A stateless client must be recorded as such.' );
		$this->assertSame( 200, $entry['status'] );
		$this->assertArrayHasKey( 'tools', $entry );
		$this->assertGreaterThan( 0, $entry['tools'] );
	}

	/**
	 * The reason this exists. A tools/list refused before the handler and a
	 * tools/list that answers with nothing both present to the owner as "my app
	 * says it has no actions" — they must not look the same here.
	 *
	 * The refused shape is synthesized rather than provoked, because the shim
	 * this ships alongside means Saddle no longer produces it.
	 */
	public function test_a_refused_list_and_an_empty_one_read_differently() {
		Saddle_MCP_Diagnostics::start_recording();

		$this->rpc( 'tools/list' );

		$served = Saddle_MCP_Diagnostics::entries()[0];

		update_option(
			Saddle_MCP_Diagnostics::TRACE_OPTION,
			array(
				array(
					'time'     => time(),
					'methods'  => array( 'tools/list' ),
					'session'  => 'absent',
					'protocol' => 'absent',
					'status'   => 400,
					'error'    => -32600,
				),
				array(
					'time'     => time(),
					'methods'  => array( 'tools/list' ),
					'session'  => 'sent',
					'protocol' => 'absent',
					'status'   => 200,
					'tools'    => 0,
				),
			),
			false
		);

		$report = Saddle_MCP_Diagnostics::report();

		$this->assertStringContainsString( 'status:400', $report );
		$this->assertStringContainsString( 'rpc:-32600', $report );
		$this->assertStringContainsString( 'tools:0', $report );

		// And the healthy row carries a real count, so "answered with nothing"
		// is distinguishable from "answered".
		$this->assertGreaterThan( 0, $served['tools'] );
	}

	/**
	 * A debugging instrument must not become a place credentials accumulate.
	 */
	public function test_a_recorded_request_contains_no_credential() {
		Saddle_MCP_Diagnostics::start_recording();

		$this->rpc( 'tools/list', array( 'Authorization' => 'Bearer super-secret-token-value' ) );

		$serialized = wp_json_encode( Saddle_MCP_Diagnostics::entries() );

		$this->assertStringNotContainsString( 'super-secret-token-value', $serialized );
		$this->assertStringNotContainsString( 'Authorization', $serialized );
	}

	/**
	 * Bounded, so it cannot grow into a problem of its own.
	 */
	public function test_the_ring_buffer_is_bounded() {
		Saddle_MCP_Diagnostics::start_recording();

		for ( $i = 0; $i < Saddle_MCP_Diagnostics::MAX_ENTRIES + 5; $i++ ) {
			$this->rpc( 'ping' );
		}

		$this->assertCount( Saddle_MCP_Diagnostics::MAX_ENTRIES, Saddle_MCP_Diagnostics::entries() );
	}

	/**
	 * Time-boxed, so it cannot be left running by someone who forgot.
	 */
	public function test_recording_self_disables_after_its_window() {
		Saddle_MCP_Diagnostics::start_recording();
		$this->assertTrue( Saddle_MCP_Diagnostics::is_recording() );

		update_option( Saddle_MCP_Diagnostics::RECORDING_OPTION, time() - 1, false );

		$this->assertFalse( Saddle_MCP_Diagnostics::is_recording() );

		$this->rpc( 'tools/list' );
		$this->assertSame( array(), Saddle_MCP_Diagnostics::entries() );
	}

	/**
	 * The health record names the tools that failed conversion, which the
	 * adapter otherwise drops one at a time into error_log.
	 */
	public function test_assess_names_the_tools_that_went_missing() {
		$assessment = Saddle_MCP_Diagnostics::assess(
			array( 'saddle/get-site-info', 'saddle/list-posts' ),
			array( 'saddle-get-site-info' )
		);

		$this->assertSame( 2, $assessment['expected'] );
		$this->assertSame( 1, $assessment['registered'] );
		$this->assertSame( array( 'saddle-list-posts' ), $assessment['missing'] );
		$this->assertTrue( $assessment['degraded'] );
	}

	/**
	 * Ability names carry a slash, registered tool names don't. Comparing the
	 * two raw forms would report every tool as missing on a healthy site.
	 */
	public function test_a_healthy_server_is_not_reported_as_degraded() {
		$assessment = Saddle_MCP_Diagnostics::assess(
			array( 'saddle/get-site-info', 'saddle/list-posts' ),
			array( 'saddle-get-site-info', 'saddle-list-posts' )
		);

		$this->assertSame( array(), $assessment['missing'] );
		$this->assertFalse( $assessment['degraded'] );
	}

	/**
	 * An empty server is degraded even though nothing is technically "missing"
	 * relative to an empty expectation — a zero-tool MCP server is never a
	 * legitimate state on a live install.
	 */
	public function test_an_empty_server_is_degraded() {
		$this->assertTrue( Saddle_MCP_Diagnostics::assess( array(), array() )['degraded'] );
	}

	/**
	 * The health record is written on every request that builds the server, so
	 * an unchanged one must not write — otherwise the always-on half stops
	 * being free.
	 */
	public function test_an_unchanged_health_record_is_not_rewritten() {
		$facts = Saddle_MCP_Diagnostics::assess( array( 'saddle/get-site-info' ), array( 'saddle-get-site-info' ) );

		Saddle_MCP_Diagnostics::record_health( $facts );
		$first = Saddle_MCP_Diagnostics::health();

		// Move time on, so a timestamp-sensitive comparison would rewrite.
		sleep( 1 );
		Saddle_MCP_Diagnostics::record_health( $facts );

		$this->assertSame(
			$first['recorded_at'],
			Saddle_MCP_Diagnostics::health()['recorded_at'],
			'An unchanged health record must not be rewritten.'
		);
	}

	/**
	 * The endpoint is for site owners, not for the agent and not for the world.
	 */
	public function test_the_diagnostics_endpoint_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/mcp-diagnostics' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The report is what gets pasted into a support reply, so it has to stand
	 * alone: which build, which endpoint, which adapter.
	 */
	public function test_the_report_identifies_the_install() {
		$report = Saddle_MCP_Diagnostics::report();

		$this->assertStringContainsString( 'Saddle MCP diagnostics', $report );
		$this->assertStringContainsString( home_url( '/' ), $report );
		$this->assertStringContainsString( '/saddle/v1/mcp', $report );
	}
}
