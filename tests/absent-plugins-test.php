<?php
/**
 * D3: tools for a plugin that is not active are left out of tools/list, the
 * way Unsplash's are until it has a key. They stay registered, so a call by
 * name still refuses with the tool's own reason, and the context says in
 * prose which plugins are missing.
 *
 * @package Saddle
 */

class Saddle_Absent_Plugins_Test extends WP_UnitTestCase {

	/**
	 * Detection filter => a tool that plugin owns, as an agent sees it.
	 */
	const PLUGINS = array(
		'saddle_divi_active'     => 'saddle-divi-get-page',
		'saddle_yoast_active'    => 'saddle-yoast-get-post-seo',
		'saddle_rankmath_active' => 'saddle-rank-math-get-post-seo',
		'saddle_aioseo_active'   => 'saddle-aioseo-get-post-seo',
		'saddle_wc_active'       => 'saddle-wc-get-product',
	);

	const PREFIXES = array( 'saddle-divi-', 'saddle-yoast-', 'saddle-rank-math-', 'saddle-aioseo-', 'saddle-wc-' );

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
		add_filter( 'mcp_adapter_tools_list', array( 'Saddle_MCP', 'filter_adapter_tools_list' ), 10, 2 );
		Saddle_Capabilities::set_tier( 'admin' );
	}

	public function tear_down() {
		foreach ( array_keys( self::PLUGINS ) as $filter ) {
			remove_all_filters( $filter );
		}
		delete_user_meta( $this->admin, 'mcp_adapter_sessions' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Access::KEY_ROLES_OPTION );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * Mark the native plugins active or not.
	 *
	 * @param bool          $active Whether they are active.
	 * @param string[]|null $only   Detection filters to set; all when null.
	 */
	private function plugins_active( $active, array $only = null ) {
		foreach ( null === $only ? array_keys( self::PLUGINS ) : $only as $filter ) {
			remove_all_filters( $filter );
			add_filter( $filter, $active ? '__return_true' : '__return_false' );
		}
	}

	/**
	 * One JSON-RPC message through the built-in transport.
	 *
	 * @param string $method JSON-RPC method.
	 * @param array  $params Params.
	 * @return array
	 */
	private function fallback( $method, array $params = array() ) {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$body = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
		);
		if ( $params ) {
			$body['params'] = $params;
		}
		$req->set_body( wp_json_encode( $body ) );

		return json_decode( wp_json_encode( Saddle_MCP::handle( $req )->get_data() ), true );
	}

	/**
	 * One JSON-RPC message through the route's real transport, the adapter.
	 *
	 * @param string $method  JSON-RPC method.
	 * @param array  $params  Params.
	 * @param array  $headers Headers.
	 * @return array
	 */
	private function adapter( $method, array $params = array(), array $headers = array() ) {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$req->set_header( $name, $value );
		}
		$body = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
		);
		if ( $params ) {
			$body['params'] = $params;
		}
		$req->set_body( wp_json_encode( $body ) );

		return json_decode( wp_json_encode( rest_do_request( $req )->get_data() ), true );
	}

	private function adapter_session() {
		$this->adapter(
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

		return array( 'Mcp-Session-Id' => (string) array_key_first( $sessions ) );
	}

	private function names( array $data ) {
		return array_column( $data['result']['tools'], 'name' );
	}

	private function with_prefix( array $names ) {
		return array_values(
			array_filter(
				$names,
				static function ( $name ) {
					foreach ( self::PREFIXES as $prefix ) {
						if ( 0 === strpos( $name, $prefix ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/* ------------------------------------------------------------ tools/list */

	public function test_absent_plugins_tools_are_left_out_of_tools_list() {
		$this->plugins_active( false );
		$names = $this->names( $this->fallback( 'tools/list' ) );

		$this->assertSame( array(), $this->with_prefix( $names ), 'No tool of an absent plugin is offered.' );
		$this->assertContains( 'saddle-get-post', $names );

		$this->plugins_active( true );
		$names = $this->names( $this->fallback( 'tools/list' ) );
		foreach ( self::PLUGINS as $tool ) {
			$this->assertContains( $tool, $names, "{$tool} is offered once its plugin is active." );
		}
	}

	public function test_one_absent_plugin_hides_only_its_own_tools() {
		$this->plugins_active( true );
		$this->plugins_active( false, array( 'saddle_yoast_active' ) );
		$names = $this->names( $this->fallback( 'tools/list' ) );

		$this->assertNotContains( 'saddle-yoast-get-post-seo', $names );
		$this->assertContains( 'saddle-rank-math-get-post-seo', $names, 'rank-math-* is Rank Math\'s, whatever Yoast does.' );
		$this->assertContains( 'saddle-wc-get-product', $names );
	}

	public function test_the_adapter_path_leaves_them_out_too() {
		$this->plugins_active( false );
		$names = $this->names( $this->adapter( 'tools/list', array(), $this->adapter_session() ) );

		$this->assertNotEmpty( $names, 'The adapter answered.' );
		$this->assertSame( array(), $this->with_prefix( $names ) );

		$this->plugins_active( true );
		$names = $this->names( $this->adapter( 'tools/list', array(), $this->adapter_session() ) );
		$this->assertContains( 'saddle-yoast-get-post-seo', $names );
	}

	public function test_tools_list_at_read_only_is_smaller_without_absent_plugins_tools() {
		$this->plugins_active( true );
		$before = wp_json_encode( $this->fallback( 'tools/list' )['result']['tools'] );
		$this->plugins_active( false );
		$after = wp_json_encode( $this->fallback( 'tools/list' )['result']['tools'] );

		$this->assertLessThan( strlen( $before ), strlen( $after ) );
	}

	/* ------------------------------------------------------------ by name */

	public function test_a_hidden_tool_called_by_name_still_refuses_with_its_own_reason() {
		$this->plugins_active( false );
		$post = self::factory()->post->create();

		$yoast = $this->fallback(
			'tools/call',
			array(
				'name'      => 'saddle-yoast-get-post-seo',
				'arguments' => array( 'post_id' => $post ),
			)
		);
		$this->assertTrue( $yoast['result']['isError'] );
		$this->assertStringContainsString( 'Yoast SEO is not active', $yoast['result']['content'][0]['text'] );

		$wc = $this->fallback(
			'tools/call',
			array(
				'name'      => 'saddle-wc-get-product',
				'arguments' => array( 'product_id' => 1 ),
			)
		);
		$this->assertTrue( $wc['result']['isError'] );
		$this->assertSame( 'WooCommerce is not active on this site.', $wc['result']['content'][0]['text'] );
	}

	public function test_a_hidden_tool_called_by_name_on_the_adapter_refuses_cleanly() {
		$this->plugins_active( false );
		$post    = self::factory()->post->create();
		$headers = $this->adapter_session();

		$data = $this->adapter(
			'tools/call',
			array(
				'name'      => 'saddle-yoast-get-post-seo',
				'arguments' => array( 'post_id' => $post ),
			),
			$headers
		);

		$this->assertArrayNotHasKey( 'error', $data, 'Not a protocol error: the tool exists.' );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertStringContainsString( 'Yoast SEO is not active', wp_json_encode( $data['result']['content'] ) );
	}

	/* ------------------------------------------------------------ the prose */

	public function test_the_context_names_the_plugins_whose_tools_are_hidden() {
		$this->plugins_active( false );
		$context = Saddle_Context::system_context();

		$this->assertStringContainsString( 'Plugins not on this site', $context );
		foreach ( array( 'Divi 5', 'Yoast SEO', 'Rank Math', 'AIOSEO', 'WooCommerce' ) as $plugin ) {
			$this->assertStringContainsString( $plugin, $context );
		}
		$this->assertStringContainsString( 'those plugins are not active here, so their tools are not offered', $context );

		$this->plugins_active( true );
		$this->plugins_active( false, array( 'saddle_wc_active' ) );
		$this->assertStringContainsString( 'Saddle has tools for WooCommerce, but that plugin is not active here', Saddle_Context::system_context() );

		$this->plugins_active( true );
		$this->assertStringNotContainsString( 'Plugins not on this site', Saddle_Context::system_context(), 'Nothing is hidden, so nothing is said.' );
	}

	public function test_the_higher_access_count_leaves_out_tools_whose_plugin_is_absent() {
		Saddle_Capabilities::set_tier( 'read' );

		$this->plugins_active( true );
		$present = Saddle_Capabilities::hidden_tool_counts();
		$this->plugins_active( false );
		$absent = Saddle_Capabilities::hidden_tool_counts();

		// Raising the access level would not unlock yoast-edit-post-seo on a
		// site without Yoast, so the "needs a higher access level" line must
		// not count it.
		$this->assertLessThan( $present['tier'], $absent['tier'] );
		$this->assertGreaterThan( $present['service'], $absent['service'] );
		$this->assertSame( $present['total'], $absent['total'], 'Still registered.' );
	}

	/* ------------------------------------------------------------ the admin */

	public function test_the_capabilities_payload_says_whether_each_tool_can_run_here() {
		$this->plugins_active( false );
		$rows = array_column( rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/capabilities' ) )->get_data()['capabilities'], null, 'name' );

		foreach ( $rows as $name => $row ) {
			$this->assertIsBool( $row['available'], "{$name} carries available." );
		}
		$this->assertFalse( $rows['saddle/yoast-get-post-seo']['available'] );
		$this->assertFalse( $rows['saddle/divi-get-page']['available'] );
		$this->assertFalse( $rows['saddle/unsplash-search']['available'], 'No key, so it cannot run either.' );
		$this->assertTrue( $rows['saddle/get-post']['available'] );

		$this->plugins_active( true );
		$rows = array_column( rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/capabilities' ) )->get_data()['capabilities'], null, 'name' );
		$this->assertTrue( $rows['saddle/yoast-get-post-seo']['available'] );
	}
}
