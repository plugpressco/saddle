<?php
/**
 * The connection registry: which credential reached the MCP endpoint, what it
 * says it is, and when it made its first real tool call. On both transports,
 * bounded, and silent in the activity log.
 *
 * @package Saddle
 */

class Saddle_Connections_Test extends WP_UnitTestCase {

	private $admin;

	private $request_uri;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['wp_rest_application_password_uuid'] );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		Saddle_OAuth_Store::register_cpt();
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'saddle_oauth_enabled', '__return_true' );
		remove_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		$this->sign_out_of_apps();
		delete_option( Saddle_Connections::OPTION );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( 'permalink_structure' );
		delete_user_meta( $this->admin, 'mcp_adapter_sessions' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * Mint a Saddle key the way the connect wizard does.
	 *
	 * @param int    $user_id Owner.
	 * @param string $label   The app picked in the wizard.
	 * @return string uuid.
	 */
	private function saddle_key( $user_id, $label = 'Cursor' ) {
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . $label ) );
		Saddle_Connection::mark_issued( $user_id, $item['uuid'] );

		return $item['uuid'];
	}

	/**
	 * Make the request look signed in with a key, as core leaves it after
	 * authenticating an Application Password.
	 *
	 * @param string $uuid Key uuid.
	 */
	private function sign_in_with_key( $uuid ) {
		$GLOBALS['wp_rest_application_password_uuid'] = $uuid;
	}

	/**
	 * Mint a grant and token and resolve it, as the bearer resolver does on a
	 * real request.
	 *
	 * @param string $client_id Client id on the grant.
	 * @param string $name      Client name on the grant.
	 * @return string Grant id.
	 */
	private function sign_in_with_oauth( $client_id = 'saddle_test', $name = 'Claude' ) {
		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		update_option( 'permalink_structure', '/%postname%/' );

		$grant = $this->grant( $client_id, $name );
		$token = Saddle_OAuth::random_secret( 32 );
		Saddle_OAuth_Store::save_token(
			'access',
			$token,
			array(
				'grant_id'  => $grant,
				'client_id' => $client_id,
				'user_id'   => $this->admin,
				'scope'     => 'saddle:read',
				'resource'  => Saddle_OAuth::resource_id(),
			)
		);

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';
		wp_set_current_user( 0 );
		wp_set_current_user( Saddle_OAuth_Bearer::resolve( false ) );

		return $grant;
	}

	/**
	 * Back to the owner's browser session: no key, no token. A resolved token
	 * is confined to the MCP route, so an admin route needs it gone.
	 */
	private function sign_out_of_apps() {
		remove_filter( 'saddle_tier_ceiling', array( 'Saddle_OAuth_Bearer', 'tier_ceiling' ) );

		foreach ( array(
			'token_record' => null,
			'grant_scope'  => '',
		) as $property => $empty ) {
			$reset = new ReflectionProperty( 'Saddle_OAuth_Bearer', $property );
			$reset->setAccessible( true );
			$reset->setValue( null, $empty );
		}

		unset( $_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['wp_rest_application_password_uuid'] );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		wp_set_current_user( $this->admin );
	}

	/**
	 * A grant, as the consent screen saves one.
	 *
	 * @param string $client_id Client id.
	 * @param string $name      Client name.
	 * @return string Grant id.
	 */
	private function grant( $client_id = 'saddle_test', $name = 'Claude' ) {
		$grant = Saddle_OAuth::random_secret( 16 );
		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => $grant,
				'client_id'   => $client_id,
				'client_name' => $name,
				'user_id'     => $this->admin,
				'scope'       => 'saddle:read',
				'resource'    => Saddle_OAuth::resource_id(),
			)
		);

		return $grant;
	}

	/**
	 * One JSON-RPC message.
	 *
	 * @param string   $method Method.
	 * @param array    $params Params.
	 * @param int|null $id     Id; null for a notification.
	 * @return array
	 */
	private function message( $method, array $params = array(), $id = 1 ) {
		$message = array(
			'jsonrpc' => '2.0',
			'method'  => $method,
		);
		if ( null !== $id ) {
			$message['id'] = $id;
		}
		if ( $params ) {
			$message['params'] = $params;
		}

		return $message;
	}

	private function initialize( $name = 'cursor-vscode', $version = '1.0.0' ) {
		return $this->message(
			'initialize',
			array(
				'protocolVersion' => '2025-06-18',
				'capabilities'    => array(),
				'clientInfo'      => array(
					'name'    => $name,
					'version' => $version,
				),
			)
		);
	}

	private function tool( $name, array $arguments = array(), $id = 1 ) {
		return $this->message(
			'tools/call',
			array(
				'name'      => $name,
				'arguments' => (object) $arguments,
			),
			$id
		);
	}

	/**
	 * Build a request aimed at the MCP route.
	 *
	 * @param array $body    JSON-RPC body.
	 * @param array $headers Headers.
	 * @return WP_REST_Request
	 */
	private function request( $body, array $headers = array() ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( wp_json_encode( $body ) );

		return $request;
	}

	/**
	 * Through whatever transport serves the route (the vendored adapter), and
	 * the same rest_post_dispatch chain a served request runs.
	 *
	 * @param array $body    JSON-RPC body.
	 * @param array $headers Headers.
	 * @return WP_REST_Response
	 */
	private function via_adapter( $body, array $headers = array() ) {
		$request  = $this->request( $body, $headers );
		$response = rest_do_request( $request );

		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	}

	/**
	 * Through Saddle's built-in JSON-RPC transport.
	 *
	 * @param array $body    JSON-RPC body.
	 * @param array $headers Headers.
	 * @return WP_REST_Response
	 */
	private function via_builtin( $body, array $headers = array() ) {
		$request = $this->request( $body, $headers );

		return apply_filters( 'rest_post_dispatch', Saddle_MCP::handle( $request ), rest_get_server(), $request );
	}

	private function record( $id ) {
		$all = Saddle_Connections::all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	private function log_rows() {
		return (int) ( new WP_Query(
			array(
				'post_type'      => Saddle_Log::CPT,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		) )->found_posts;
	}

	/**
	 * What makes "both transports" true here: via_adapter() is routed to the
	 * vendored adapter, and via_builtin() calls Saddle's own handler.
	 */
	public function test_the_two_helpers_reach_two_different_transports() {
		$route = rest_get_server()->get_routes( 'saddle/v1' )['/saddle/v1/mcp'];

		$this->assertTrue( Saddle::adapter_available() );
		$this->assertNotSame( array( 'Saddle_MCP', 'handle' ), $route[0]['callback'] );
	}

	/* ---------------------------------------------------------- the handshake */

	/**
	 * Accept check: a handshake without a tool call leaves first_tool_at empty.
	 */
	public function test_a_handshake_alone_records_the_client_but_no_tool_call() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );

		$this->via_adapter( $this->initialize( 'cursor-vscode', '1.0.0' ), array( 'User-Agent' => 'Cursor/1.6 (darwin arm64)' ) );
		$this->via_adapter( $this->message( 'notifications/initialized', array(), null ) );
		$this->via_adapter( $this->message( 'tools/list' ) );

		$record = $this->record( 'key:' . $uuid );
		$this->assertNotNull( $record );
		$this->assertSame( 'key', $record['kind'] );
		$this->assertSame( 'cursor-vscode', $record['client_name'] );
		$this->assertSame( '1.0.0', $record['client_version'] );
		$this->assertSame( 'Cursor', $record['agent'] );
		$this->assertGreaterThan( 0, $record['first_seen_at'] );
		$this->assertSame( 0, $record['first_tool_at'], 'A handshake is not a tool call.' );
		$this->assertSame( array(), $record['recent_tools'] );
	}

	/**
	 * The first successful tools/call stamps first_tool_at, on the adapter.
	 */
	public function test_the_first_successful_tool_call_is_stamped_on_the_adapter() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );

		$this->via_adapter( $this->initialize() );
		$this->via_adapter( $this->tool( 'saddle-get-site-info' ) );

		$record = $this->record( 'key:' . $uuid );
		$this->assertGreaterThan( 0, $record['first_tool_at'] );
		$this->assertSame( 'saddle/get-site-info', $record['last_tool'] );
		$this->assertSame( array( 'saddle/get-site-info' ), $record['recent_tools'] );
	}

	/**
	 * And on the built-in transport, which the adapter-less build serves.
	 */
	public function test_the_first_successful_tool_call_is_stamped_on_the_builtin_transport() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );

		$this->via_builtin( $this->initialize( 'codex-mcp-client', '0.157.0' ) );
		$this->assertSame( 0, $this->record( 'key:' . $uuid )['first_tool_at'] );

		$this->via_builtin( $this->tool( 'saddle-get-site-info' ) );

		$record = $this->record( 'key:' . $uuid );
		$this->assertSame( 'codex-mcp-client', $record['client_name'] );
		$this->assertGreaterThan( 0, $record['first_tool_at'] );
		$this->assertSame( array( 'saddle/get-site-info' ), $record['recent_tools'] );
	}

	/**
	 * A refusal proves the request reached WordPress, not that the app can do
	 * anything. It must not tick "first tool call".
	 */
	public function test_a_refused_tool_call_is_not_a_first_tool_call() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );

		// A write tool on a site at the read tier: an isError result.
		$this->via_adapter( $this->tool( 'saddle-create-post', array( 'title' => 'No' ) ) );
		$this->via_builtin( $this->tool( 'saddle-create-post', array( 'title' => 'No' ) ) );
		// An unknown tool: a JSON-RPC error. Core notes the lookup as misuse.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$this->via_builtin( $this->tool( 'saddle-no-such-tool' ) );

		$record = $this->record( 'key:' . $uuid );
		$this->assertNotNull( $record, 'The connection was still seen.' );
		$this->assertSame( 0, $record['first_tool_at'] );
		$this->assertSame( array(), $record['recent_tools'] );
	}

	/**
	 * In a batch, each call is matched to its own answer by id.
	 */
	public function test_a_batch_counts_only_the_calls_that_succeeded() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );

		$this->via_builtin(
			array(
				$this->tool( 'saddle-create-post', array( 'title' => 'No' ), 'refused' ),
				$this->tool( 'saddle-get-site-info', array(), 'fine' ),
			)
		);

		$record = $this->record( 'key:' . $uuid );
		$this->assertGreaterThan( 0, $record['first_tool_at'] );
		$this->assertSame( array( 'saddle/get-site-info' ), $record['recent_tools'] );
	}

	/**
	 * A browser session is not a connection.
	 */
	public function test_a_request_without_an_app_credential_records_nothing() {
		$this->via_adapter( $this->initialize() );
		$this->via_adapter( $this->tool( 'saddle-get-site-info' ) );

		$this->assertFalse( get_option( Saddle_Connections::OPTION ) );
	}

	/**
	 * Only the MCP route is observed.
	 */
	public function test_other_routes_are_not_observed() {
		$this->sign_in_with_key( $this->saddle_key( $this->admin ) );

		$request = new WP_REST_Request( 'GET', '/saddle/v1/mcp-diagnostics' );
		apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );

		$this->assertFalse( get_option( Saddle_Connections::OPTION ) );
	}

	/* ------------------------------------------------------ the address path */

	/**
	 * A connection by address (OAuth) is keyed by its grant.
	 */
	public function test_an_oauth_connection_is_recorded_under_its_grant() {
		$grant = $this->sign_in_with_oauth();

		$this->via_adapter( $this->initialize( 'claude-ai', '0.1.0' ) );
		$this->via_adapter( $this->tool( 'saddle-get-site-info' ) );

		$record = $this->record( 'oauth:' . $grant );
		$this->assertNotNull( $record );
		$this->assertSame( 'oauth', $record['kind'] );
		$this->assertSame( $this->admin, $record['user'] );
		$this->assertSame( 'claude-ai', $record['client_name'] );
		$this->assertGreaterThan( 0, $record['first_tool_at'] );
	}

	/* ------------------------------------------------------ what is written */

	/**
	 * Accept check: reads still create no saddle_log row, and no row per call
	 * is written anywhere else.
	 */
	public function test_tool_calls_write_no_log_rows_and_no_posts() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );

		$logs  = $this->log_rows();
		$posts = (int) wp_count_posts( Saddle_Log::CPT )->publish;

		for ( $i = 0; $i < 5; $i++ ) {
			$this->via_adapter( $this->tool( 'saddle-get-site-info' ) );
		}

		$this->assertSame( $logs, $this->log_rows() );
		$this->assertSame( $posts, (int) wp_count_posts( Saddle_Log::CPT )->publish );
		$this->assertCount( 1, Saddle_Connections::all() );
	}

	/**
	 * A repeat of a known tool inside the minute writes nothing. A tool the
	 * waiting screens haven't seen is written at once.
	 */
	public function test_repeat_calls_are_throttled_and_new_tools_are_not() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );

		$this->via_builtin( $this->tool( 'saddle-get-site-info' ) );
		$first = get_option( Saddle_Connections::OPTION );

		$writes = 0;
		$count  = static function () use ( &$writes ) {
			++$writes;
		};
		add_action( 'update_option_' . Saddle_Connections::OPTION, $count );

		$this->via_builtin( $this->tool( 'saddle-get-site-info' ) );
		$this->via_builtin( $this->message( 'tools/list' ) );
		$this->assertSame( 0, $writes, 'A repeat inside the minute must not write.' );
		$this->assertSame( $first, get_option( Saddle_Connections::OPTION ) );

		$this->via_builtin( $this->tool( 'saddle-list-post-types' ) );
		$this->assertSame( 1, $writes, 'A new tool name is written at once.' );
		$this->assertSame( array( 'saddle/list-post-types', 'saddle/get-site-info' ), $this->record( 'key:' . $uuid )['recent_tools'] );

		// A minute later, a repeat is written again.
		$all                                   = Saddle_Connections::all();
		$all[ 'key:' . $uuid ]['last_seen_at'] = time() - 120;
		update_option( Saddle_Connections::OPTION, $all, false );
		$writes = 0;

		$this->via_builtin( $this->tool( 'saddle-get-site-info' ) );
		$this->assertSame( 1, $writes );
		$this->assertGreaterThanOrEqual( time() - 1, $this->record( 'key:' . $uuid )['last_seen_at'] );
		$this->assertSame( 'saddle/get-site-info', $this->record( 'key:' . $uuid )['recent_tools'][0] );

		remove_action( 'update_option_' . Saddle_Connections::OPTION, $count );
	}

	/**
	 * Accept check: the option stays bounded after 1,000 calls.
	 */
	public function test_the_option_stays_bounded_after_a_thousand_calls() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );

		$tools = array( 'saddle-get-site-info', 'saddle-list-post-types', 'saddle-list-posts', 'saddle-list-pages', 'saddle-list-media', 'saddle-list-categories', 'saddle-list-tags' );
		for ( $i = 0; $i < 1000; $i++ ) {
			$this->via_builtin( $this->tool( $tools[ $i % count( $tools ) ] ) );
		}

		$all = Saddle_Connections::all();
		$this->assertCount( 1, $all );
		$this->assertCount( Saddle_Connections::RECENT_TOOLS, $all[ 'key:' . $uuid ]['recent_tools'] );
		$this->assertLessThan( 1024, strlen( maybe_serialize( get_option( Saddle_Connections::OPTION ) ) ) );
	}

	/**
	 * Past MAX_RECORDS, the connection seen longest ago goes first.
	 */
	public function test_the_least_recently_seen_record_is_evicted_past_the_cap() {
		$seeded = array();
		for ( $i = 0; $i < Saddle_Connections::MAX_RECORDS; $i++ ) {
			$seeded[ 'key:seed-' . $i ] = array(
				'kind'         => 'key',
				'user'         => $this->admin,
				'last_seen_at' => time() - 1000 + $i,
			);
		}
		update_option( Saddle_Connections::OPTION, $seeded, false );

		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );
		$this->via_builtin( $this->message( 'ping' ) );

		$all = Saddle_Connections::all();
		$this->assertCount( Saddle_Connections::MAX_RECORDS, $all );
		$this->assertArrayHasKey( 'key:' . $uuid, $all );
		$this->assertArrayNotHasKey( 'key:seed-0', $all, 'The oldest record makes room.' );
		$this->assertArrayHasKey( 'key:seed-1', $all );
	}

	/* ------------------------------------------------------------- forgetting */

	public function test_deleting_a_key_anywhere_forgets_its_record() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );
		$this->via_builtin( $this->message( 'ping' ) );
		$this->assertNotNull( $this->record( 'key:' . $uuid ) );

		// Core's own delete, as the user profile screen does it.
		WP_Application_Passwords::delete_application_password( $this->admin, $uuid );

		$this->assertNull( $this->record( 'key:' . $uuid ) );
	}

	public function test_revoking_a_key_in_saddle_forgets_its_record() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );
		$this->via_builtin( $this->message( 'ping' ) );
		$this->sign_out_of_apps();

		$response = rest_do_request( new WP_REST_Request( 'DELETE', '/saddle/v1/clients/' . $uuid ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $this->record( 'key:' . $uuid ) );
	}

	public function test_revoking_a_grant_forgets_its_record() {
		$grant = $this->sign_in_with_oauth();
		$this->via_builtin( $this->message( 'ping' ) );
		$this->assertNotNull( $this->record( 'oauth:' . $grant ) );

		Saddle_OAuth_Store::revoke_grant( $grant );

		$this->assertNull( $this->record( 'oauth:' . $grant ) );
	}

	/**
	 * The sweep catches what no revoke path saw, and keeps the live ones.
	 */
	public function test_the_sweep_drops_records_whose_credential_is_gone() {
		$live = $this->saddle_key( $this->admin );
		update_option(
			Saddle_Connections::OPTION,
			array(
				'key:' . $live                   => array(
					'kind'         => 'key',
					'user'         => $this->admin,
					'last_seen_at' => time(),
				),
				'key:gone'                       => array(
					'kind'         => 'key',
					'user'         => $this->admin,
					'last_seen_at' => time(),
				),
				'oauth:' . str_repeat( 'a', 32 ) => array(
					'kind'         => 'oauth',
					'user'         => $this->admin,
					'last_seen_at' => time(),
				),
			),
			false
		);

		do_action( Saddle_Approval::GC_HOOK );

		$this->assertSame( array( 'key:' . $live ), array_keys( Saddle_Connections::all() ) );
	}

	/* ---------------------------------------------------------------- REST */

	/**
	 * The Dashboard's blind spot: an address-only site has connections.
	 */
	public function test_connections_lists_an_address_only_connection() {
		$grant = $this->sign_in_with_oauth( 'saddle_test', 'Claude' );
		$this->via_adapter( $this->initialize( 'claude-ai', '0.1.0' ) );
		$this->via_adapter( $this->tool( 'saddle-get-site-info' ) );

		$this->sign_out_of_apps();
		$response = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/connections' ) );
		$rows     = $response->get_data()['connections'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'oauth:' . $grant, $rows[0]['id'] );
		$this->assertSame( 'claude', $rows[0]['app'] );
		$this->assertSame( 'claude-ai 0.1.0', $rows[0]['client'] );
		$this->assertSame( 'read', $rows[0]['level'] );
		$this->assertGreaterThan( 0, $rows[0]['first_seen_at'] );
		$this->assertGreaterThan( 0, $rows[0]['first_tool_at'] );
		$this->assertSame( array( 'saddle/get-site-info' ), $rows[0]['recent_tools'] );
	}

	/**
	 * Keys and grants in one list; a Saddle key shows before it is ever used,
	 * and a key Saddle didn't issue shows only once it has reached the MCP
	 * endpoint.
	 */
	public function test_connections_joins_keys_and_grants() {
		$unused  = $this->saddle_key( $this->admin, 'Claude Code' );
		$used    = $this->saddle_key( $this->admin, 'AI app' );
		$foreign = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'Backup plugin' ) )[1]['uuid'];
		$this->grant( 'saddle_test', 'ChatGPT' );

		$this->sign_in_with_key( $used );
		$this->via_builtin( $this->initialize( 'cursor-vscode', '1.0.0' ) );
		$this->sign_out_of_apps();

		$rows = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/connections' ) )->get_data()['connections'];
		$byid = array_column( $rows, null, 'id' );

		$this->assertCount( 3, $rows );
		$this->assertArrayNotHasKey( 'key:' . $foreign, $byid );

		$this->assertSame( 'claude-code', $byid[ 'key:' . $unused ]['app'], 'The app picked in the wizard.' );
		$this->assertSame( 0, $byid[ 'key:' . $unused ]['first_seen_at'] );
		$this->assertSame( 'cursor', $byid[ 'key:' . $used ]['app'], 'clientInfo outranks the key\'s name.' );
		$this->assertSame( 'AI app', $byid[ 'key:' . $used ]['name'] );
		$this->assertArrayHasKey( 'hint', $byid[ 'key:' . $used ] );

		$chatgpt = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return 'oauth' === $row['kind'];
				}
			)
		);
		$this->assertSame( 'chatgpt', $chatgpt[0]['app'] );

		// The foreign key appears once it has been used on the MCP endpoint.
		$this->sign_in_with_key( $foreign );
		$this->via_builtin( $this->message( 'ping' ) );
		$this->sign_out_of_apps();

		$rows = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/connections' ) )->get_data()['connections'];
		$this->assertContains( 'key:' . $foreign, array_column( $rows, 'id' ) );
	}

	/**
	 * The pulse returns only what is new since the time it last handed out.
	 */
	public function test_the_pulse_returns_only_what_changed_since() {
		$uuid = $this->saddle_key( $this->admin );
		$this->sign_in_with_key( $uuid );
		$this->via_builtin( $this->initialize() );
		$this->sign_out_of_apps();

		$request = new WP_REST_Request( 'GET', '/saddle/v1/connections/pulse' );
		$request->set_param( 'since', time() - 60 );
		$data = rest_do_request( $request )->get_data();

		$this->assertGreaterThanOrEqual( time() - 1, $data['now'] );
		$this->assertSame( array( 'key:' . $uuid ), array_column( $data['connections'], 'id' ) );
		$this->assertSame( 0, $data['connections'][0]['first_tool_at'] );

		$request->set_param( 'since', $data['now'] );
		$this->assertSame( array(), rest_do_request( $request )->get_data()['connections'] );
	}

	public function test_both_routes_need_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array( '/saddle/v1/connections', '/saddle/v1/connections/pulse' ) as $route ) {
			$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status(), $route );
		}
	}

	/* ------------------------------------------------------ app detection */

	/**
	 * @dataProvider detections
	 */
	public function test_app_detection( array $evidence, $expected ) {
		$this->assertSame( $expected, Saddle_Connection_Apps::detect( $evidence ) );
	}

	public static function detections() {
		return array(
			'Claude Code by its metadata URL'  => array( array( 'urls' => array( 'https://claude.ai/oauth/claude-code-client-metadata' ) ), 'claude-code' ),
			'Claude by its redirect'           => array( array( 'urls' => array( 'abc123', 'https://claude.ai/api/mcp/auth_callback' ) ), 'claude' ),
			'ChatGPT by its redirect host'     => array( array( 'urls' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ) ), 'chatgpt' ),
			'OAuth outranks clientInfo'        => array(
				array(
					'urls'        => array( 'https://chatgpt.com/x' ),
					'client_name' => 'claude-ai',
				),
				'chatgpt',
			),
			'Cursor by clientInfo'             => array( array( 'client_name' => 'cursor-vscode' ), 'cursor' ),
			'VS Code by its product name'      => array( array( 'client_name' => 'Visual Studio Code' ), 'vscode' ),
			'Codex by clientInfo'              => array( array( 'client_name' => 'codex-mcp-client' ), 'codex' ),
			'clientInfo outranks the key name' => array(
				array(
					'client_name' => 'claude-code',
					'name'        => 'Cursor',
				),
				'claude-code',
			),
			'Claude Code by its User-Agent'    => array( array( 'agent' => 'claude-code' ), 'claude-code' ),
			'the wizard\'s second key'         => array( array( 'name' => 'Claude Code 2' ), 'claude-code' ),
			'Claude is not Claude Code'        => array( array( 'name' => 'Claude' ), 'claude' ),
			'a name with a note'               => array( array( 'name' => 'Cursor (laptop)' ), 'cursor' ),
			'a prefix is not a match'          => array( array( 'name' => 'Cursory notes' ), '' ),
			'an unknown client stays unnamed'  => array( array( 'client_name' => 'my-agent' ), '' ),
			'nothing at all'                   => array( array(), '' ),
		);
	}
}
