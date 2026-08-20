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

			// And into $_SERVER, because that is where the credential actually
			// lives as far as every consumer is concerned. Saddle_Connection
			// reads $_SERVER on purpose: the failure this whole surface exists
			// to diagnose is a host stripping the header BEFORE PHP, and only
			// $_SERVER can tell you that. A WP_REST_Request built in a test is
			// synthetic and populates neither.
			if ( 0 === strcasecmp( $name, 'Authorization' ) ) {
				$_SERVER['HTTP_AUTHORIZATION'] = $value;
			}
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

		// The row now names the SHAPE of the credential, which is the whole
		// point — and must still name nothing else. Asserted here rather than
		// in its own test so the two can never drift apart: whatever new field
		// describes a credential gets added above this line and is covered by
		// the assertions above it.
		$entry = Saddle_MCP_Diagnostics::entries()[0];
		$this->assertSame( 'bearer', $entry['scheme'] );
		$this->assertSame( 'present', $entry['auth'] );
	}

	/**
	 * The pair that answers a 401. "The key was rejected" and "no key arrived"
	 * are the same HTTP status and opposite fixes — one is reconnect the app,
	 * the other is talk to your host about a stripped Authorization header. A
	 * customer lost two weeks inside that ambiguity.
	 */
	public function test_a_request_with_no_credential_is_recorded_as_such() {
		Saddle_MCP_Diagnostics::start_recording();

		$this->rpc( 'tools/list' );

		$entry = Saddle_MCP_Diagnostics::entries()[0];

		$this->assertSame( 'absent', $entry['auth'], 'No Authorization header must read as absent, never as present.' );
		$this->assertSame( 'none', $entry['scheme'] );
		$this->assertSame( 'POST', $entry['method'], 'The HTTP method disambiguates a row that carried no MCP method.' );
	}

	/**
	 * Both facts have to survive into the text that actually gets pasted into a
	 * support reply. They were recorded on every row for a month and rendered
	 * nowhere, so the one question the trace could answer was the one question
	 * we kept asking the customer to answer for us.
	 */
	public function test_the_report_names_the_credential_scheme() {
		Saddle_MCP_Diagnostics::start_recording();

		$this->rpc( 'tools/list', array( 'Authorization' => 'Bearer super-secret-token-value' ) );

		$report = Saddle_MCP_Diagnostics::report();

		$this->assertStringContainsString( 'auth:present', $report );
		$this->assertStringContainsString( 'scheme:bearer', $report );
		$this->assertStringNotContainsString( 'super-secret-token-value', $report );
	}

	/**
	 * The panel polls this route every 5 seconds while recording, and the admin
	 * API shares the `saddle/v1` namespace — so a prefix test matched it and the
	 * instrument filled its own ring buffer with itself. At 25 entries that was
	 * a ~125-second memory; a customer's capture of a failing connection came
	 * back 23 parts panel to 2 parts evidence.
	 */
	public function test_the_diagnostics_route_is_not_recorded_as_mcp_traffic() {
		Saddle_MCP_Diagnostics::start_recording();

		// Through rest_post_dispatch, not rest_do_request() alone. The recorder
		// closes an entry on that filter, and dispatch() does not fire it — so
		// the obvious version of this test passes against the BUG, because
		// nothing ever gets written either way. Verified red before the fix
		// only in this form.
		$request  = new WP_REST_Request( 'GET', '/saddle/v1/mcp-diagnostics' );
		$response = rest_do_request( $request );
		apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );

		$this->assertSame(
			array(),
			Saddle_MCP_Diagnostics::entries(),
			'The panel must not record its own polling as MCP traffic.'
		);

		// And the real endpoint still is recorded — a filter that records
		// nothing would also pass the assertion above.
		$this->rpc( 'tools/list' );
		$this->assertCount( 1, Saddle_MCP_Diagnostics::entries() );
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
