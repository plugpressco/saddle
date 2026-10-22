<?php
/**
 * Allow, Ask first or Off for each tool (#364, product plan Phase 2).
 *
 * What must hold: a tool set to Ask first never runs its own code until the
 * owner approves the request in wp-admin, whether or not the tool previews by
 * itself; a yes in the chat is not enough; a reject stops it; the approved
 * call runs exactly once. A site updated in place can do exactly what it could
 * before, a tool that arrives later asks first, and the recommended set only
 * tightens.
 *
 * @package Saddle
 */

class Saddle_Tool_Modes_Test extends WP_UnitTestCase {

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		delete_option( Saddle_Connections::OPTION );
		if ( wp_has_ability( 'saddle/test-arrived-later' ) ) {
			global $wp_current_filter;
			$wp_current_filter[] = 'wp_abilities_api_init';
			wp_unregister_ability( 'saddle/test-arrived-later' );
			array_pop( $wp_current_filter );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A connected app: a Saddle key with a role, used for the calls after it.
	 */
	private function app( $role = 'write', $label = 'Claude Code' ) {
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . $label ) );
		Saddle_Access::set_role( 'key:' . $item['uuid'], $role );
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid'];

		return $item['uuid'];
	}

	private function call( $tool, array $input ) {
		return wp_get_ability( 'saddle/' . $tool )->execute( $input );
	}

	private function owner_decides( $decision ) {
		$uuid = isset( $GLOBALS['wp_rest_application_password_uuid'] ) ? $GLOBALS['wp_rest_application_password_uuid'] : null;
		unset( $GLOBALS['wp_rest_application_password_uuid'] ); // The owner is in the browser.

		$pending = Saddle_Approval::pending();
		$this->assertCount( 1, $pending, 'One request waits under Needs your OK.' );
		$this->assertTrue( $pending[0]['waits'], 'It waits for the owner, not the chat.' );

		$request = new WP_REST_Request( 'POST', '/saddle/v1/approvals/' . $pending[0]['id'] );
		$request->set_param( 'decision', $decision );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$GLOBALS['wp_rest_application_password_uuid'] = $uuid;
		return $pending[0];
	}

	private function posts_titled( $title ) {
		return get_posts(
			array(
				'title'       => $title,
				'post_type'   => 'post',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);
	}

	/* -------- R3: nothing changes for a site that is updated -------- */

	public function test_a_site_updated_in_place_can_do_exactly_what_it_could_before() {
		Saddle_Tool_Modes::set_mode( 'delete-media', Saddle_Tool_Modes::OFF );
		delete_option( Saddle_Tool_Modes::KNOWN_OPTION ); // As on the first request after the update.

		foreach ( array_keys( wp_get_abilities() ) as $name ) {
			if ( 0 !== strpos( $name, 'saddle/' ) ) {
				continue;
			}
			$short = substr( $name, 7 );
			$this->assertFalse( Saddle_Tool_Modes::asks_first( $short ), $short . ' must not start asking after an update.' );
			$this->assertSame(
				Saddle_Capabilities::is_ability_enabled( $short ) ? Saddle_Tool_Modes::ALLOW : Saddle_Tool_Modes::OFF,
				Saddle_Tool_Modes::mode( $short ),
				$short . ' keeps its on or off.'
			);
		}

		$this->assertSame( Saddle_Tool_Modes::OFF, Saddle_Tool_Modes::mode( 'delete-media' ) );
		$this->assertContains( 'create-post', get_option( Saddle_Tool_Modes::KNOWN_OPTION ), 'The first look records every changing tool as known.' );
		$this->assertNotContains( 'get-post', get_option( Saddle_Tool_Modes::KNOWN_OPTION ), 'Read tools never ask, so they are not listed.' );
	}

	public function test_a_fresh_install_is_read_only_and_a_new_app_starts_read_only() {
		$this->assertSame( 'read', get_option( Saddle_Capabilities::OPTION, Saddle_Capabilities::DEFAULT_TIER ) );

		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . 'Claude' ) );
		$this->assertSame( 'read', Saddle_Access::role_for( 'key:' . $item['uuid'] ) );
	}

	public function test_a_tool_that_arrives_later_asks_first_until_the_owner_chooses() {
		Saddle_Tool_Modes::remember_known();

		// Core allows registering only inside wp_abilities_api_init.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		wp_register_ability(
			'saddle/test-arrived-later',
			array(
				'label'               => 'Arrived later',
				'description'         => 'A tool from a plugin activated after the update.',
				'category'            => 'saddle',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'title' => array( 'type' => 'string' ) ),
				),
				'execute_callback'    => static function () {
					return array( 'ran' => true );
				},
				'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'test-arrived-later' ),
				'meta'                => saddle_ability_meta( false, false, false, 'write' ),
			)
		);
		array_pop( $wp_current_filter );

		$this->assertTrue( Saddle_Tool_Modes::is_new( 'test-arrived-later' ) );
		$this->assertSame( Saddle_Tool_Modes::ASK, Saddle_Tool_Modes::mode( 'test-arrived-later' ) );

		Saddle_Tool_Modes::set_mode( 'test-arrived-later', Saddle_Tool_Modes::ALLOW );
		$this->assertFalse( Saddle_Tool_Modes::is_new( 'test-arrived-later' ) );
		$this->assertSame( Saddle_Tool_Modes::ALLOW, Saddle_Tool_Modes::mode( 'test-arrived-later' ) );
	}

	/* -------- Ask first on a tool with no preview of its own -------- */

	public function test_ask_first_holds_a_create_until_the_owner_approves_then_runs_it_once() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$this->app( 'write' );
		$input = array(
			'title'   => 'Spring sale',
			'content' => 'Ten percent off.',
		);

		$preview = $this->call( 'create-post', $input );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertTrue( $preview['needs_owner_approval'] );
		$this->assertStringContainsString( 'Needs your OK', $preview['instructions'] );
		$this->assertEmpty( $this->posts_titled( 'Spring sale' ), 'A preview creates nothing.' );

		// A yes in the chat is not enough.
		$early = $this->call( 'create-post', $input + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertWPError( $early );
		$this->assertSame( 'saddle_awaiting_owner', $early->get_error_code() );
		$this->assertEmpty( $this->posts_titled( 'Spring sale' ) );

		$row = $this->owner_decides( 'approve' );
		$this->assertStringContainsString( 'Create', $row['summary'] );
		$this->assertSame( 'Spring sale', $row['preview']['title'] );

		// The same token works now, exactly once.
		$done = $this->call( 'create-post', $input + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertNotWPError( $done );
		$this->assertCount( 1, $this->posts_titled( 'Spring sale' ) );

		$again = $this->call( 'create-post', $input + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertWPError( $again );
		$this->assertCount( 1, $this->posts_titled( 'Spring sale' ) );
	}

	/**
	 * A spent, made-up or other tool's token must not slip past Ask first
	 * into the tool's own code (found while building #364).
	 */
	public function test_a_token_that_is_not_this_requests_never_reaches_the_tool() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$this->app( 'write' );

		$made_up = $this->call(
			'create-post',
			array(
				'title'         => 'Sneaky',
				'confirm_token' => str_repeat( 'a', 32 ),
			)
		);
		$this->assertWPError( $made_up );
		$this->assertEmpty( $this->posts_titled( 'Sneaky' ) );

		$post    = self::factory()->post->create( array( 'post_title' => 'Other' ) );
		$foreign = $this->call( 'delete-post', array( 'id' => $post ) );
		$this->assertArrayHasKey( 'confirm_token', $foreign );

		$swapped = $this->call(
			'create-post',
			array(
				'title'         => 'Sneaky',
				'confirm_token' => $foreign['confirm_token'],
			)
		);
		$this->assertWPError( $swapped );
		$this->assertEmpty( $this->posts_titled( 'Sneaky' ) );
	}

	public function test_a_reject_stops_the_call() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$this->app( 'write' );
		$input = array( 'title' => 'Not wanted' );

		$preview = $this->call( 'create-post', $input );
		$this->owner_decides( 'reject' );

		$result = $this->call( 'create-post', $input + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertSame( 'saddle_rejected_by_owner', $result->get_error_code() );
		$this->assertEmpty( $this->posts_titled( 'Not wanted' ) );
	}

	public function test_the_approval_covers_only_the_call_that_was_previewed() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$this->app( 'write' );

		$preview = $this->call( 'create-post', array( 'title' => 'Draft one' ) );
		$this->owner_decides( 'approve' );

		$swapped = $this->call(
			'create-post',
			array(
				'title'         => 'Something else',
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertSame( 'saddle_token_bind_mismatch', $swapped->get_error_code() );
		$this->assertEmpty( $this->posts_titled( 'Something else' ) );
	}

	public function test_the_owners_own_browser_session_is_not_asked() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		Saddle_Capabilities::set_tier( 'write' );

		$result = $this->call( 'create-post', array( 'title' => 'From the browser' ) );

		$this->assertArrayNotHasKey( 'requires_confirmation', (array) $result );
		$this->assertCount( 1, $this->posts_titled( 'From the browser' ) );
		Saddle_Capabilities::set_tier( 'read' );
	}

	public function test_a_tool_at_allow_runs_as_before() {
		$this->app( 'write' );

		$result = $this->call( 'create-post', array( 'title' => 'Allowed' ) );

		$this->assertArrayNotHasKey( 'requires_confirmation', (array) $result );
		$this->assertCount( 1, $this->posts_titled( 'Allowed' ) );
	}

	/* -------- Ask first on a tool that previews by itself -------- */

	public function test_ask_first_on_delete_post_asks_once_and_the_approval_answers_its_own_preview() {
		$post = self::factory()->post->create( array( 'post_title' => 'Old news' ) );
		Saddle_Tool_Modes::set_mode( 'delete-post', Saddle_Tool_Modes::ASK );
		$this->app( 'write' );

		$preview = $this->call( 'delete-post', array( 'id' => $post ) );
		$this->assertTrue( $preview['needs_owner_approval'] );
		$this->assertSame( 'publish', get_post_status( $post ) );

		$early = $this->call(
			'delete-post',
			array(
				'id'            => $post,
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertSame( 'saddle_awaiting_owner', $early->get_error_code() );

		$row = $this->owner_decides( 'approve' );
		$this->assertStringContainsString( 'Old news', $row['summary'] );

		$done = $this->call(
			'delete-post',
			array(
				'id'            => $post,
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertNotWPError( $done );
		$this->assertArrayNotHasKey( 'requires_confirmation', (array) $done, 'The owner is not asked a second time.' );
		$this->assertSame( 'trash', get_post_status( $post ), 'Trashed, never deleted for good without force.' );
	}

	/**
	 * remove-block takes out a single block at once, with no preview. Ask
	 * first must hold it anyway: the tool's own code does not run.
	 */
	public function test_ask_first_holds_a_tool_that_would_have_run_without_a_preview() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => "<!-- wp:paragraph -->\n<p>Keep me</p>\n<!-- /wp:paragraph -->",
			)
		);
		Saddle_Tool_Modes::set_mode( 'remove-block', Saddle_Tool_Modes::ASK );
		$this->app( 'write' );

		$result = $this->call(
			'remove-block',
			array(
				'post_id' => $page,
				'address' => '0',
			)
		);

		$this->assertTrue( $result['needs_owner_approval'] );
		$this->assertStringContainsString( 'Keep me', get_post( $page )->post_content );
	}

	/* -------- rehearsal, context, schema -------- */

	public function test_rehearsal_answers_before_anything_asks_the_owner() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		Saddle_Capabilities::set_rehearsal( true );
		$this->app( 'write' );

		$result = $this->call( 'create-post', array( 'title' => 'Practice' ) );

		$this->assertTrue( $result['rehearsal'] );
		$this->assertEmpty( Saddle_Approval::pending(), 'Nothing waits for an OK that would save nothing.' );
		Saddle_Capabilities::set_rehearsal( false );
	}

	public function test_the_context_names_the_tools_that_ask_first() {
		Saddle_Tool_Modes::set_mode( 'delete-post', Saddle_Tool_Modes::ASK );
		$this->app( 'write' );

		$context = Saddle_Context::system_context();

		$this->assertStringContainsString( 'saddle-delete-post', $context );
		$this->assertStringContainsString( 'needs_owner_approval', $context );
	}

	public function test_the_context_says_nothing_when_no_tool_asks_first() {
		$this->app( 'write' );

		$this->assertStringNotContainsString( 'needs_owner_approval', Saddle_Context::system_context() );
	}

	public function test_a_tool_that_asks_first_gets_a_confirm_token_input() {
		update_option( Saddle_Tool_Modes::ASK_OPTION, array( 'create-post' ) );

		$args = Saddle_Tool_Modes::wrap(
			array(
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array( 'title' => array( 'type' => 'string' ) ),
				),
				'execute_callback' => '__return_true',
				'meta'             => saddle_ability_meta( false, false, false, 'write' ),
			),
			'saddle/create-post'
		);

		$this->assertArrayHasKey( 'confirm_token', $args['input_schema']['properties'] );

		$read = Saddle_Tool_Modes::wrap(
			array(
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback' => '__return_true',
				'meta'             => saddle_ability_meta( true, false, true, 'read' ),
			),
			'saddle/get-post'
		);
		$this->assertSame( '__return_true', $read['execute_callback'], 'A read tool is not wrapped.' );
	}

	/* -------- setting modes, and the recommended set -------- */

	public function test_set_mode_refuses_ask_first_on_a_read_tool_and_an_unknown_tool() {
		$this->assertSame( 'saddle_bad_mode', Saddle_Tool_Modes::set_mode( 'get-post', Saddle_Tool_Modes::ASK )->get_error_code() );
		$this->assertSame( 'saddle_unknown_tool', Saddle_Tool_Modes::set_mode( 'no-such-tool', Saddle_Tool_Modes::ALLOW )->get_error_code() );
		$this->assertSame( Saddle_Tool_Modes::OFF, Saddle_Tool_Modes::set_mode( 'get-post', Saddle_Tool_Modes::OFF ) );
		$this->assertSame( Saddle_Tool_Modes::ALLOW, Saddle_Tool_Modes::set_mode( 'get-post', Saddle_Tool_Modes::ALLOW ) );
	}

	public function test_off_is_the_existing_switch() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::OFF );

		$this->assertContains( 'create-post', Saddle_Capabilities::disabled_abilities() );
		$this->assertFalse( Saddle_Capabilities::is_ability_enabled( 'create-post' ) );

		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$this->assertNotContains( 'create-post', Saddle_Capabilities::disabled_abilities() );
		$this->assertSame( Saddle_Tool_Modes::ASK, Saddle_Tool_Modes::mode( 'create-post' ) );
	}

	public function test_the_recommended_set_only_tightens() {
		Saddle_Tool_Modes::set_mode( 'update-plugin', Saddle_Tool_Modes::OFF );
		$recommended = Saddle_Tool_Modes::recommended();

		foreach ( array( 'delete-post', 'delete-page', 'delete-media', 'update-plugin', 'install-plugin', 'update-core', 'activate-plugin', 'update-option', 'set-auto-update' ) as $tool ) {
			$this->assertContains( $tool, $recommended );
		}
		foreach ( array( 'create-post', 'update-post', 'set-blocks', 'remove-block', 'get-post', 'list-plugins' ) as $tool ) {
			$this->assertNotContains( $tool, $recommended );
		}

		$moved = Saddle_Tool_Modes::apply_recommended();

		$this->assertContains( 'delete-post', $moved );
		$this->assertSame( Saddle_Tool_Modes::ASK, Saddle_Tool_Modes::mode( 'delete-post' ) );
		$this->assertSame( Saddle_Tool_Modes::OFF, Saddle_Tool_Modes::mode( 'update-plugin' ), 'Off stays Off.' );
		$this->assertSame( Saddle_Tool_Modes::ALLOW, Saddle_Tool_Modes::mode( 'create-post' ), 'Content edits stay Allow.' );
		$this->assertTrue( Saddle_Capabilities::is_drafts_only(), 'Publishing needs the owner’s OK.' );

		$this->assertSame( array(), Saddle_Tool_Modes::apply_recommended(), 'A second time changes nothing.' );
	}

	public function test_no_tool_can_change_a_mode() {
		$this->assertTrue( Saddle_Settings_Guard::is_protected_option( Saddle_Tool_Modes::ASK_OPTION ) );
		$this->assertTrue( Saddle_Settings_Guard::is_protected_option( Saddle_Tool_Modes::KNOWN_OPTION ) );
	}

	public function test_the_rest_routes_set_a_mode_and_apply_the_set() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/tool-modes' );
		$request->set_param( 'tool', 'delete-post' );
		$request->set_param( 'mode', 'ask' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ask', $response->get_data()['mode'] );

		$caps = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/capabilities' ) )->get_data()['capabilities'];
		$row  = wp_list_filter( $caps, array( 'short' => 'delete-post' ) );
		$row  = reset( $row );
		$this->assertSame( 'ask', $row['mode'] );
		$this->assertTrue( $row['askable'] );
		$this->assertTrue( $row['recommended'] );

		$applied = rest_do_request( new WP_REST_Request( 'POST', '/saddle/v1/tool-modes/recommended' ) );
		$this->assertSame( 200, $applied->get_status() );
		$this->assertTrue( $applied->get_data()['drafts_only'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}
}
