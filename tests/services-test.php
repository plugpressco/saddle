<?php
/**
 * Services (#291): the registry, its REST routes, the Unsplash gate, the
 * WordPress Connectors mirror and the agent's context section.
 *
 * @package Saddle
 */

class Saddle_Services_Test extends WP_UnitTestCase {

	const KEY = 'testAccessKey_1234567890abcdef';

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
	}

	public function tear_down() {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		delete_option( Saddle_Unsplash::OPTION );
		delete_option( Saddle_Integrations::APPROVED_OPTION );
		delete_option( Saddle_Access::KEY_ROLES_OPTION );
		delete_option( Saddle_Capabilities::OPTION );
		remove_all_filters( 'saddle_services' );
		remove_all_filters( 'saddle_integrations' );
		remove_all_filters( 'pre_http_request' );
		remove_filter( 'saddle_yoast_active', '__return_true' );
		$this->within_abilities_init(
			static function () {
				$all = wp_get_abilities();
				foreach ( array( 'acme/get-report', 'saddle/acme-get-report', 'crm/list-contacts', 'saddle/crm-list-contacts' ) as $name ) {
					if ( isset( $all[ $name ] ) ) {
						wp_unregister_ability( $name );
					}
				}
			}
		);
		parent::tear_down();
	}

	/* ------------------------------------------------------------ helpers */

	private function within_abilities_init( callable $fn ) {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$fn();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	private function register_source_ability( $name, $label ) {
		$this->within_abilities_init(
			static function () use ( $name, $label ) {
				wp_register_ability(
					$name,
					array(
						'label'               => $label,
						'description'         => 'x',
						'category'            => 'saddle',
						'input_schema'        => array(
							'type'       => 'object',
							'default'    => (object) array(),
							'properties' => (object) array(),
						),
						'execute_callback'    => static function () {
							return array( 'ok' => true );
						},
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
				Saddle_Integrations::register_wrappers();
			}
		);
	}

	/**
	 * Enrol a plugin by slug with one tool. A third-party slug is Acme's; `crm`
	 * is first-party.
	 */
	private function enrol( $slug ) {
		$tool = array(
			'acme' => array( 'acme/get-report', 'Get report', 'Acme Forms' ),
			'crm'  => array( 'crm/list-contacts', 'List contacts', 'Saddle CRM' ),
		)[ $slug ];

		add_filter(
			'saddle_integrations',
			static function ( $integrations ) use ( $slug, $tool ) {
				$integrations[ $slug ] = array(
					'prefix'      => $slug . '/',
					'title'       => $tool[2],
					'description' => 'Things.',
				);
				return $integrations;
			}
		);
		$this->register_source_ability( $tool[0], $tool[1] );
	}

	private function record( $key ) {
		$record = Saddle_Services::get( $key );
		$this->assertNotNull( $record, "Expected a {$key} record." );

		return $record;
	}

	private function call( $method, $route, array $body = null ) {
		$request = new WP_REST_Request( $method, '/saddle/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	private function tools_listed() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
				)
			)
		);
		$data = json_decode( wp_json_encode( Saddle_MCP::handle( $req )->get_data() ), true );

		return array_column( $data['result']['tools'], 'name' );
	}

	/** A connection that holds a key with this role, as a connected app does. */
	private function sign_in_as_app( $role ) {
		update_option( Saddle_Access::KEY_ROLES_OPTION, array( 'uuid-services-test' => $role ) );
		$GLOBALS['wp_rest_application_password_uuid'] = 'uuid-services-test';
	}

	/* ------------------------------------------------------------ records */

	public function test_there_is_one_record_per_source_with_its_status_computed() {
		add_filter( 'saddle_yoast_active', '__return_true' );
		$this->enrol( 'acme' );
		$this->enrol( 'crm' );

		$unsplash = $this->record( 'unsplash' );
		$this->assertSame( 'account', $unsplash['kind'] );
		$this->assertSame( 'built-in', $unsplash['source'] );
		$this->assertSame( 'needs_key', $unsplash['status'] );
		$this->assertFalse( $unsplash['enabled'] );
		$this->assertFalse( $unsplash['can_toggle'] );
		$this->assertSame( 'api_key', $unsplash['credential']['method'] );
		$this->assertSame( 'https://unsplash.com/api-terms', $unsplash['terms_url'] );
		$this->assertSame( 'https://unsplash.com/privacy', $unsplash['privacy_url'] );
		$this->assertSame( 'https://unsplash.com/developers', $unsplash['credentials_url'] );
		$this->assertSame( array( 'api.unsplash.com', 'images.unsplash.com' ), wp_list_pluck( $unsplash['sends'], 'host' ) );

		$yoast = $this->record( 'yoast' );
		$this->assertSame( array( 'plugin', 'built-in', 'detected' ), array( $yoast['kind'], $yoast['source'], $yoast['status'] ) );
		$this->assertNull( $yoast['credential'] );
		$this->assertSame( array(), $yoast['sends'] );
		$this->assertNotEmpty( $yoast['tools'] );

		$crm = $this->record( 'crm' );
		$this->assertSame( array( 'addon', 'plugpress', 'active', true, false ), array( $crm['kind'], $crm['source'], $crm['status'], $crm['enabled'], $crm['can_toggle'] ) );
		$this->assertSame(
			array(
				array(
					'name'  => 'crm-list-contacts',
					'title' => 'List contacts',
					'role'  => 'read',
				),
			),
			$crm['tools']
		);

		$acme = $this->record( 'acme' );
		$this->assertSame( array( 'addon', 'third-party', 'off', false, true ), array( $acme['kind'], $acme['source'], $acme['status'], $acme['enabled'], $acme['can_toggle'] ) );
		$this->assertSame( 1, $acme['tool_count'], 'An add-on that is off still says what switching it on adds.' );

		$order  = array_map(
			static function ( $kind ) {
				return array_search( $kind, Saddle_Services::KINDS, true );
			},
			wp_list_pluck( Saddle_Services::all(), 'kind' )
		);
		$sorted = $order;
		sort( $sorted );
		$this->assertSame( $sorted, $order, 'Accounts, then plugins, then add-ons.' );
	}

	public function test_a_plugin_that_is_not_active_has_no_record() {
		add_filter( 'saddle_yoast_active', '__return_false' );
		$this->assertNull( Saddle_Services::get( 'yoast' ) );
		remove_filter( 'saddle_yoast_active', '__return_false' );
	}

	public function test_status_follows_the_key_and_the_approval() {
		$this->enrol( 'acme' );

		Saddle_Unsplash::set_key( self::KEY );
		$unsplash = $this->record( 'unsplash' );
		$this->assertSame( 'ready', $unsplash['status'] );
		$this->assertTrue( $unsplash['enabled'] );
		$this->assertTrue( $unsplash['credential']['configured'] );
		$this->assertSame( substr( self::KEY, -4 ), $unsplash['credential']['hint'] );

		Saddle_Unsplash::set_key( '' );
		$this->assertSame( 'needs_key', $this->record( 'unsplash' )['status'] );

		Saddle_Integrations::set_approved( 'acme', true );
		$this->assertSame( 'active', $this->record( 'acme' )['status'] );
		$this->assertTrue( $this->record( 'acme' )['enabled'] );
	}

	public function test_the_saddle_services_filter_adds_records_and_drops_invalid_ones() {
		add_filter(
			'saddle_services',
			static function () {
				return array(
					array(
						'key'        => 'cloud',
						'name'       => '<b>Cloud</b>',
						'kind'       => 'account',
						'source'     => 'plugpress',
						'credential' => array(
							'configured' => true,
							'hint'       => 'abcdwxyz',
							'value'      => 'SECRETVALUE',
						),
						'sends'      => array(
							array(
								'host' => 'cloud.example.com',
								'what' => 'Tasks',
								'when' => 'When connected',
							),
							array(
								'host' => 'not a host',
								'what' => 'x',
								'when' => 'x',
							),
						),
						'terms_url'  => 'javascript:alert(1)',
					),
					array(
						'key'  => 'unsplash',
						'name' => 'Imposter',
						'kind' => 'account',
					),
					array(
						'name' => 'No key',
						'kind' => 'account',
					),
					array(
						'key'  => 'odd',
						'name' => 'Odd',
						'kind' => 'wizard',
					),
					'not an array',
				);
			}
		);

		$cloud = $this->record( 'cloud' );
		$this->assertSame( 'Cloud', $cloud['name'] );
		$this->assertSame( 'ready', $cloud['status'] );
		$this->assertSame( 'wxyz', $cloud['credential']['hint'], 'A hint is at most the last four characters.' );
		$this->assertSame( array( 'cloud.example.com' ), wp_list_pluck( $cloud['sends'], 'host' ) );
		$this->assertSame( '', $cloud['terms_url'] );
		$this->assertStringNotContainsString( 'SECRETVALUE', wp_json_encode( $cloud ) );
		$this->assertSame( 'Unsplash', $this->record( 'unsplash' )['name'], 'A plugin cannot replace a built-in record.' );
		$this->assertNull( Saddle_Services::get( 'odd' ) );
		$this->assertCount(
			1,
			wp_list_filter(
				Saddle_Services::all(),
				array(
					'kind' => 'account',
					'key'  => 'cloud',
				)
			)
		);
	}

	public function test_rank_math_tools_are_not_claimed_by_saddle_rank() {
		add_filter(
			'saddle_integrations',
			static function ( $integrations ) {
				$integrations['rank'] = array(
					'prefix'      => 'saddle-rank/',
					'title'       => 'Saddle Rank',
					'description' => 'SEO.',
				);
				return $integrations;
			}
		);
		add_filter( 'saddle_rank_math_active', '__return_true' );

		foreach ( Saddle_Services::all() as $record ) {
			if ( 'rank' === $record['key'] ) {
				foreach ( $record['tools'] as $tool ) {
					$this->assertStringStartsNotWith( 'rank-math-', $tool['name'] );
				}
			}
		}
		remove_filter( 'saddle_rank_math_active', '__return_true' );
	}

	/* ------------------------------------------------------------ secrets */

	public function test_the_key_is_never_in_the_list_or_the_context() {
		Saddle_Unsplash::set_key( self::KEY );

		$response = $this->call( 'GET', '/services' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( substr( self::KEY, 0, 12 ), wp_json_encode( $response->get_data() ) );

		$this->assertStringNotContainsString( self::KEY, Saddle_Context::system_context() );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( Saddle_Services::context_section( array() ) ) );
	}

	/* ------------------------------------------------------------ the gate */

	public function test_unsplash_tools_are_hidden_and_refused_until_a_key_is_set() {
		Saddle_Capabilities::set_tier( 'admin' );

		$this->assertFalse( Saddle_Services::has_tools_available( 'saddle/unsplash-search' ) );
		$this->assertTrue( Saddle_Services::has_tools_available( 'saddle/get-post' ) );
		$this->assertFalse( Saddle_Capabilities::is_callable_now( 'saddle/unsplash-search' ) );
		$this->assertFalse( Saddle_Capabilities::is_callable_now( 'saddle/unsplash-import' ) );
		$this->assertTrue( Saddle_Capabilities::is_callable_now( 'saddle/get-post' ) );
		$this->assertNotContains( 'saddle-unsplash-search', $this->tools_listed() );
		$this->assertContains( 'saddle-get-post', $this->tools_listed() );

		$reason = Saddle_Capabilities::denial_reason( 'saddle/unsplash-search' );
		$this->assertSame( 'saddle_service_not_set_up', $reason['code'] );
		$this->assertStringStartsWith( 'Unsplash is not set up. Ask the owner to add a key under Saddle → Services.', $reason['message'] );

		$counts = Saddle_Capabilities::hidden_tool_counts();
		$this->assertSame( 2, $counts['service'] );

		Saddle_Unsplash::set_key( self::KEY );
		$this->assertTrue( Saddle_Services::has_tools_available( 'saddle/unsplash-search' ) );
		$this->assertTrue( Saddle_Capabilities::is_callable_now( 'saddle/unsplash-search' ) );
		$this->assertContains( 'saddle-unsplash-search', $this->tools_listed() );
		$this->assertContains( 'saddle-unsplash-import', $this->tools_listed() );
		Saddle_Capabilities::set_tier( 'read' );
	}

	public function test_a_read_only_app_cannot_search_unsplash_but_an_editing_app_can() {
		Saddle_Unsplash::set_key( self::KEY );
		add_filter( 'saddle_source_url_is_safe', '__return_true' );
		$hits = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$hits ) {
				++$hits;
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'total'       => 0,
							'total_pages' => 0,
							'results'     => array(),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}
		);

		$this->sign_in_as_app( 'read' );
		$this->assertSame( 'write', wp_get_ability( 'saddle/unsplash-search' )->get_meta()['saddle']['tier'] );
		$this->assertFalse( Saddle_Capabilities::is_callable_now( 'saddle/unsplash-search' ) );
		$refused = wp_get_ability( 'saddle/unsplash-search' )->execute( array( 'query' => 'mountains' ) );
		$this->assertWPError( $refused );
		$this->assertSame( 0, $hits, 'A read-only app must not spend the owner\'s Unsplash quota.' );

		$this->sign_in_as_app( 'write' );
		$this->assertTrue( Saddle_Capabilities::is_callable_now( 'saddle/unsplash-search' ) );
		$this->assertNotWPError( wp_get_ability( 'saddle/unsplash-search' )->execute( array( 'query' => 'mountains' ) ) );
		$this->assertSame( 1, $hits );
		remove_filter( 'saddle_source_url_is_safe', '__return_true' );
	}

	/* ------------------------------------------------------------ REST */

	public function test_the_list_has_the_contract_shape() {
		$this->enrol( 'acme' );
		$rows = array_column( $this->call( 'GET', '/services' )->get_data()['services'], null, 'key' );

		$unsplash = $rows['unsplash'];
		$this->assertSame(
			array( 'method', 'configured', 'hint', 'connector', 'connectors_url' ),
			array_keys( $unsplash['credential'] )
		);
		foreach ( array( 'key', 'name', 'description', 'kind', 'source', 'status', 'enabled', 'can_toggle', 'credential', 'tools', 'sends', 'terms_url', 'privacy_url', 'credentials_url' ) as $field ) {
			$this->assertArrayHasKey( $field, $unsplash );
		}
		$this->assertArrayNotHasKey( 'agent', $unsplash );
		$this->assertSame( array( 'name', 'title', 'role' ), array_keys( $unsplash['tools'][0] ) );
		$this->assertSame( array( 'host', 'what', 'when' ), array_keys( $unsplash['sends'][0] ) );
		$this->assertContains( 'write', wp_list_pluck( $unsplash['tools'], 'role' ) );
		$this->assertNull( $rows['acme']['credential'] );
	}

	public function test_the_routes_need_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array(
			array( 'GET', '/services', null ),
			array( 'POST', '/services/unsplash/key', array( 'key' => self::KEY ) ),
			array( 'POST', '/services/acme/enabled', array( 'enabled' => true ) ),
		) as $case ) {
			$this->assertSame( 403, $this->call( $case[0], $case[1], $case[2] )->get_status(), $case[1] );
		}
		$this->assertFalse( Saddle_Unsplash::is_configured() );
	}

	public function test_a_key_is_saved_replaced_and_removed() {
		$saved = $this->call( 'POST', '/services/unsplash/key', array( 'key' => self::KEY ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'ready', $saved->get_data()['status'] );
		$this->assertTrue( $saved->get_data()['credential']['configured'] );
		$this->assertSame( 'cdef', $saved->get_data()['credential']['hint'] );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $saved->get_data() ) );
		$this->assertSame( self::KEY, Saddle_Unsplash::get_key() );

		$removed = $this->call( 'POST', '/services/unsplash/key', array( 'key' => '' ) );
		$this->assertSame( 200, $removed->get_status() );
		$this->assertSame( 'needs_key', $removed->get_data()['status'] );
		$this->assertFalse( Saddle_Unsplash::is_configured() );
	}

	public function test_a_bad_key_is_refused_and_changes_nothing() {
		Saddle_Unsplash::set_key( self::KEY );
		$response = $this->call( 'POST', '/services/unsplash/key', array( 'key' => 'too short' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'saddle_invalid_key', $response->get_data()['code'] );
		$this->assertSame( self::KEY, Saddle_Unsplash::get_key() );
	}

	public function test_key_and_switch_routes_refuse_what_does_not_apply() {
		add_filter( 'saddle_yoast_active', '__return_true' );
		$this->enrol( 'crm' );

		$this->assertSame( 404, $this->call( 'POST', '/services/nope/key', array( 'key' => self::KEY ) )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/services/nope/enabled', array( 'enabled' => true ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/services/yoast/key', array( 'key' => self::KEY ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/services/unsplash/enabled', array( 'enabled' => false ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/services/crm/enabled', array( 'enabled' => false ) )->get_status(), 'PlugPress add-ons are always on.' );
		$this->assertSame( 400, $this->call( 'POST', '/services/unsplash/enabled', array() )->get_status(), 'enabled is required.' );
	}

	public function test_a_third_party_addon_is_switched_through_the_approval_path_and_logged() {
		$this->enrol( 'acme' );

		$on = $this->call( 'POST', '/services/acme/enabled', array( 'enabled' => true ) );
		$this->assertSame( 200, $on->get_status() );
		$this->assertSame( 'active', $on->get_data()['status'] );
		$this->assertSame( array( 'acme' ), get_option( Saddle_Integrations::APPROVED_OPTION ) );

		$logged = get_posts(
			array(
				'post_type'   => 'saddle_log',
				'post_status' => 'any',
				'numberposts' => -1,
				's'           => 'Acme Forms',
			)
		);
		$this->assertNotEmpty( $logged, 'Switching an add-on on is in the activity log.' );

		$off = $this->call( 'POST', '/services/acme/enabled', array( 'enabled' => false ) );
		$this->assertSame( 'off', $off->get_data()['status'] );
		$this->assertSame( array(), get_option( Saddle_Integrations::APPROVED_OPTION ) );
	}

	public function test_the_old_integrations_and_settings_routes_still_work() {
		$this->assertSame( 200, $this->call( 'GET', '/integrations' )->get_status() );
		$this->assertArrayHasKey( 'integrations', $this->call( 'GET', '/integrations' )->get_data() );

		$this->assertSame( 200, $this->call( 'POST', '/settings', array( 'unsplash_access_key' => self::KEY ) )->get_status() );
		$this->assertSame( self::KEY, Saddle_Unsplash::get_key() );
		$this->assertTrue( $this->call( 'GET', '/settings' )->get_data()['unsplash']['configured'] );
	}

	/* ------------------------------------------------------------ context */

	public function test_the_context_tells_the_agent_what_is_set_up() {
		$this->enrol( 'acme' );
		add_filter( 'saddle_yoast_active', '__return_true' );

		$section = Saddle_Services::context_section( array() )[0];
		$this->assertSame( 'Services on this site', $section['title'] );
		$text = implode( "\n", $section['lines'] );
		$this->assertStringContainsString( '- Unsplash: not set up; ask the owner to add a key under Saddle → Services.', $text );
		$this->assertStringContainsString( '- Acme Forms: switched off by the owner. Don\'t ask for it.', $text );
		$this->assertStringContainsString( '- Yoast SEO: detected; use the saddle-yoast-* tools.', $text );

		Saddle_Unsplash::set_key( self::KEY );
		$this->assertStringContainsString( '- Unsplash: ready (search and import stock photos).', implode( "\n", Saddle_Services::context_section( array() )[0]['lines'] ) );
		$this->assertStringContainsString( '# Services on this site', Saddle_Context::system_context() );
	}

	public function test_the_context_section_is_budgeted() {
		add_filter(
			'saddle_services',
			static function () {
				$records = array();
				for ( $i = 0; $i < 12; $i++ ) {
					$records[] = array(
						'key'  => 'svc' . $i,
						'name' => 'Svc ' . $i,
						'kind' => 'plugin',
					);
				}
				return $records;
			}
		);
		// Records with no tools do not get a line at all.
		$this->assertLessThanOrEqual( Saddle_Services::CONTEXT_LINES, count( Saddle_Services::context_section( array() )[0]['lines'] ) );
		$this->assertStringNotContainsString( 'Svc 0', implode( "\n", Saddle_Services::context_section( array() )[0]['lines'] ) );
	}

	/* ------------------------------------------------------------ pages */

	public function test_services_is_a_page_and_old_links_land_on_it() {
		$this->assertSame( 'services', Saddle_Modules::area_for_page( 'saddle-services' ) );
		$this->assertSame( array( 'overview' => 'Services' ), Saddle_Modules::areas()['services']['tabs'] );
		$this->assertSame( Saddle_Modules::url( 'services' ), Saddle_Settings::legacy_target( 'saddle-settings', '', 'services' ) );
		$this->assertSame( '', Saddle_Settings::legacy_target( 'saddle-settings', '', 'safety' ) );
		$this->assertSame( '', Saddle_Settings::legacy_target( 'saddle-services', '', 'services' ) );
	}

	public function test_the_modules_view_and_owner_admin_line_name_services() {
		$this->assertStringContainsString( 'AI apps, Services, Context, Settings.', Saddle_Context::system_context() );
		$by_key = array_column( Saddle_Modules_View::all(), null, 'key' );
		$this->assertArrayHasKey( 'services', $by_key );
		$this->assertNotEmpty( $by_key['services']['summary'] );
	}

	/* ------------------------------------------------------------ readme */

	public function test_every_host_a_service_sends_to_is_in_the_readme() {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		$this->assertStringContainsString( '== External services ==', $readme );
		$external = substr( $readme, (int) strpos( $readme, '== External services ==' ) );

		$checked = 0;
		foreach ( Saddle_Services::all() as $record ) {
			if ( 'built-in' !== $record['source'] ) {
				continue;
			}
			foreach ( $record['sends'] as $send ) {
				$this->assertStringContainsString( $send['host'], $external, "readme.txt does not disclose {$send['host']} ({$record['name']})." );
				++$checked;
			}
		}
		$this->assertGreaterThan( 1, $checked );
		$this->assertStringContainsString( 'Saddle → Services', $external );
	}

	/* ------------------------------------------------------------ connectors */

	/** A stand-in for the final core registry, which a test cannot subclass. */
	private function fake_registry( $taken = false ) {
		return new class( $taken ) {
			public $registered = array();
			private $taken;

			public function __construct( $taken ) {
				$this->taken = $taken;
			}

			public function is_registered( $id ) {
				return $this->taken;
			}

			public function register( $id, $args ) {
				$this->registered[ $id ] = $args;
			}
		};
	}

	public function test_the_connector_is_registered_on_the_same_option_when_core_has_the_registry() {
		if ( ! Saddle_Services_Connector::available() ) {
			// Core without Connectors: Saddle does nothing, and says nothing.
			$registry = $this->fake_registry();
			Saddle_Services_Connector::register( $registry );
			$this->assertSame( array(), $registry->registered );
			$this->assertNull( $this->record( 'unsplash' )['credential']['connector'] );
			return;
		}

		$connector = wp_get_connector( 'unsplash' );
		$this->assertIsArray( $connector );
		$this->assertSame( 'stock_photos', $connector['type'] );
		$this->assertSame( 'api_key', $connector['authentication']['method'] );
		$this->assertSame( 'saddle_unsplash_access_key', $connector['authentication']['setting_name'] );
		$this->assertSame( 'https://unsplash.com/developers', $connector['authentication']['credentials_url'] );
		$this->assertArrayNotHasKey( 'file', $connector['plugin'], 'No install button: Saddle is the plugin.' );

		$setting = get_registered_settings()['saddle_unsplash_access_key'];
		$this->assertSame( 'connectors', $setting['group'] );
		$this->assertTrue( $setting['show_in_rest'] );
		$this->assertSame( array( 'Saddle_Services_Connector', 'sanitize' ), $setting['sanitize_callback'] );

		$credential = $this->record( 'unsplash' )['credential'];
		$this->assertSame( 'unsplash', $credential['connector'] );
		$this->assertSame( admin_url( 'options-connectors.php' ), $credential['connectors_url'] );
	}

	public function test_the_connector_registers_with_the_expected_arguments_and_keeps_autoload_off() {
		if ( ! Saddle_Services_Connector::available() ) {
			$this->markTestSkipped( 'This WordPress has no Connectors registry; the no-op is covered above.' );
		}

		$registry = $this->fake_registry();
		Saddle_Services_Connector::register( $registry );

		$this->assertSame( array( 'unsplash' ), array_keys( $registry->registered ) );
		$this->assertSame( 'stock_photos', $registry->registered['unsplash']['type'] );
		$this->assertSame( 'saddle_unsplash_access_key', $registry->registered['unsplash']['authentication']['setting_name'] );

		$this->assertFalse( apply_filters( 'wp_default_autoload_value', null, 'saddle_unsplash_access_key', 'v', 's' ) );
		$this->assertNull( apply_filters( 'wp_default_autoload_value', null, 'some_other_option', 'v', 's' ) );
		remove_filter( 'wp_default_autoload_value', array( 'Saddle_Services_Connector', 'autoload' ), 10 );
	}

	public function test_a_taken_connector_id_means_saddle_keeps_its_own_row() {
		if ( ! Saddle_Services_Connector::available() ) {
			$this->markTestSkipped( 'This WordPress has no Connectors registry.' );
		}

		$registry = $this->fake_registry( true );
		Saddle_Services_Connector::register( $registry );

		$this->assertSame( array(), $registry->registered );
	}

	public function test_core_cannot_save_a_malformed_key_through_the_connectors_screen() {
		update_option( Saddle_Unsplash::OPTION, self::KEY, false );

		$this->assertSame( self::KEY, Saddle_Services_Connector::sanitize( '••••••••••••••••cdef' ), 'A masked or malformed value keeps the old key.' );
		$this->assertSame( self::KEY, Saddle_Services_Connector::sanitize( 'bad key!' ) );
		$this->assertSame( 'anotherValidKey_123456789', Saddle_Services_Connector::sanitize( ' anotherValidKey_123456789 ' ) );
		$this->assertSame( '', Saddle_Services_Connector::sanitize( '' ) );
	}

	public function test_a_key_saved_by_the_connectors_screen_stays_out_of_autoload() {
		if ( ! Saddle_Services_Connector::available() ) {
			$this->markTestSkipped( 'This WordPress has no Connectors registry.' );
		}

		global $wpdb;
		delete_option( Saddle_Unsplash::OPTION );
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'saddle_unsplash_access_key' => self::KEY ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::KEY, Saddle_Unsplash::get_key() );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Saddle_Unsplash::OPTION ) );
		$this->assertNotContains( $autoload, array( 'yes', 'on', 'auto', 'auto-on' ), 'The key must not be autoloaded.' );
	}
}
