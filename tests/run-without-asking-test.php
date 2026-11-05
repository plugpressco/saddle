<?php
/**
 * Run without asking (Fahim, 2026-11-06): the owner's per-agent switch on
 * Agent setup. An agent set to Manage the site, with the switch on, makes
 * changes and deletes on the first call: no Ask first, no preview, no token.
 *
 * What must hold: it works only for Manage the site, and only for the agent
 * it was turned on for; another agent still previews and asks; Off still
 * refuses; deletes still go to the Trash; the change is logged and can be
 * undone; rehearsal, drafts only and the pause still apply; no tool and no
 * agent can turn it on; the agent is told it runs directly and nothing about
 * waiting; a lowered role, a ceiling, a deleted key and a revoked grant each
 * turn it off.
 *
 * @package Saddle
 */

class Saddle_Run_Without_Asking_Test extends WP_UnitTestCase {

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
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		Saddle_OAuth_Store::register_cpt();
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		remove_all_filters( 'saddle_tier_ceiling' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		foreach ( array( Saddle_Run_Without_Asking::OPTION, Saddle_Access::KEY_ROLES_OPTION, Saddle_Tool_Modes::ASK_OPTION, Saddle_Connections::OPTION ) as $option ) {
			delete_option( $option );
		}
		Saddle_Capabilities::set_disabled_abilities( array() );
		Saddle_Capabilities::set_rehearsal( false );
		Saddle_Capabilities::set_drafts_only( false );
		Saddle_Capabilities::set_paused( false );
		if ( wp_has_ability( 'saddle/test-arrived-later' ) ) {
			global $wp_current_filter;
			$wp_current_filter[] = 'wp_abilities_api_init';
			wp_unregister_ability( 'saddle/test-arrived-later' );
			array_pop( $wp_current_filter );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * A connected agent: a Saddle key with a role. Its calls come next.
	 */
	private function app( $role = 'admin', $label = 'Claude Code' ) {
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . $label ) );
		Saddle_Connection::mark_issued( $this->admin, $item['uuid'] );
		Saddle_Access::set_role( 'key:' . $item['uuid'], $role );
		$this->sign_in( $item['uuid'] );

		return $item['uuid'];
	}

	/**
	 * The same agent, set to Run without asking by the owner in wp-admin.
	 */
	private function trusted_app( $label = 'Claude Code' ) {
		$uuid = $this->app( 'admin', $label );
		$this->as_owner(
			function () use ( $uuid ) {
				$this->assertTrue( Saddle_Run_Without_Asking::set( 'key:' . $uuid, true ) );
			}
		);

		return $uuid;
	}

	private function sign_in( $uuid ) {
		$GLOBALS['wp_rest_application_password_uuid'] = $uuid;
	}

	/**
	 * Run something as the owner in the browser, then go back to the agent.
	 */
	private function as_owner( callable $run ) {
		$uuid = isset( $GLOBALS['wp_rest_application_password_uuid'] ) ? $GLOBALS['wp_rest_application_password_uuid'] : null;
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$result = $run();
		if ( null !== $uuid ) {
			$GLOBALS['wp_rest_application_password_uuid'] = $uuid;
		}

		return $result;
	}

	private function call( $tool, array $input ) {
		return wp_get_ability( 'saddle/' . $tool )->execute( $input );
	}

	private function rest( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );

		return rest_do_request( $request );
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

	private function grant( $role ) {
		$grant = Saddle_OAuth::random_secret( 16 );
		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => $grant,
				'client_id'   => 'saddle_test',
				'client_name' => 'ChatGPT',
				'user_id'     => $this->admin,
				'scope'       => Saddle_OAuth::tier_to_scope( $role ),
				'resource'    => Saddle_OAuth::resource_id(),
			)
		);

		return $grant;
	}

	/* ------------------------------------------------- what it lets through */

	public function test_a_trusted_manage_the_site_agent_runs_an_ask_first_tool_without_the_owner() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$this->trusted_app();

		$result = $this->call( 'create-post', array( 'title' => 'Straight in' ) );

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'requires_confirmation', (array) $result );
		$this->assertCount( 1, $this->posts_titled( 'Straight in' ) );
		$this->assertEmpty( $this->as_owner( array( 'Saddle_Approval', 'pending' ) ), 'Nothing waits under Needs your OK.' );
	}

	public function test_an_agent_the_owner_did_not_trust_still_asks() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$this->trusted_app( 'Claude Code' );
		$this->app( 'admin', 'Cursor' ); // Manage the site too, but not trusted.

		$preview = $this->call( 'create-post', array( 'title' => 'Held' ) );

		$this->assertTrue( $preview['needs_owner_approval'] );
		$this->assertEmpty( $this->posts_titled( 'Held' ) );

		$early = $this->call(
			'create-post',
			array(
				'title'         => 'Held',
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertSame( 'saddle_awaiting_owner', $early->get_error_code(), 'A yes in the chat is still not enough.' );
		$this->assertEmpty( $this->posts_titled( 'Held' ) );
	}

	/* ------------------------------------------------- the role is the bar */

	public function test_it_can_not_be_turned_on_below_manage_the_site_and_is_ignored_there() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$uuid = $this->app( 'write' );

		$refused = $this->as_owner(
			static function () use ( $uuid ) {
				return Saddle_Run_Without_Asking::set( 'key:' . $uuid, true );
			}
		);
		$this->assertSame( 'saddle_run_without_asking_role', $refused->get_error_code() );
		$this->assertFalse( Saddle_Run_Without_Asking::is_on( 'key:' . $uuid ) );

		// Stored anyway (an old backup, a hand edit): still ignored.
		update_option( Saddle_Run_Without_Asking::OPTION, array( 'key:' . $uuid ) );
		$this->assertFalse( Saddle_Run_Without_Asking::applies( 'key:' . $uuid ) );
		$this->assertTrue( $this->call( 'create-post', array( 'title' => 'Edit content' ) )['needs_owner_approval'] );
		$this->assertEmpty( $this->posts_titled( 'Edit content' ) );

		$post = self::factory()->post->create( array( 'post_title' => 'Edit content keeps' ) );
		$this->assertTrue( $this->call( 'delete-post', array( 'id' => $post ) )['requires_confirmation'] );
		$this->assertSame( 'publish', get_post_status( $post ) );
	}

	public function test_lowering_the_role_clears_it_and_raising_it_again_does_not_bring_it_back() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$uuid = $this->trusted_app();

		$this->as_owner(
			static function () use ( $uuid ) {
				Saddle_Access::set_role( 'key:' . $uuid, 'write' );
				Saddle_Access::set_role( 'key:' . $uuid, 'admin' );
			}
		);

		$this->assertFalse( Saddle_Run_Without_Asking::is_on( 'key:' . $uuid ) );
		$this->assertTrue( $this->call( 'create-post', array( 'title' => 'Asks again' ) )['needs_owner_approval'] );
	}

	public function test_a_ceiling_on_the_request_turns_it_off_for_that_request() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$uuid = $this->trusted_app();
		add_filter(
			'saddle_tier_ceiling',
			static function () {
				return 'write';
			}
		);

		$this->assertFalse( Saddle_Run_Without_Asking::applies( 'key:' . $uuid ) );
		$this->assertTrue( $this->call( 'create-post', array( 'title' => 'Capped' ) )['needs_owner_approval'] );

		$post = self::factory()->post->create( array( 'post_title' => 'Capped keeps' ) );
		$this->assertTrue( $this->call( 'delete-post', array( 'id' => $post ) )['requires_confirmation'] );
	}

	/* ------------------------------------------------- what it never lets through */

	public function test_a_tool_set_to_off_stays_refused() {
		$post = self::factory()->post->create( array( 'post_title' => 'Stays' ) );
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::OFF );
		Saddle_Tool_Modes::set_mode( 'delete-post', Saddle_Tool_Modes::OFF );
		$this->trusted_app();

		$this->assertWPError( $this->call( 'create-post', array( 'title' => 'Switched off' ) ) );
		$this->assertEmpty( $this->posts_titled( 'Switched off' ) );
		$this->assertWPError( $this->call( 'delete-post', array( 'id' => $post ) ) );
		$this->assertSame( 'publish', get_post_status( $post ) );
	}

	public function test_a_trusted_agent_deletes_with_no_token_into_the_trash_logged_and_undoable() {
		$post = self::factory()->post->create( array( 'post_title' => 'Old news' ) );
		$this->trusted_app();

		$done = $this->call( 'delete-post', array( 'id' => $post ) );

		$this->assertNotWPError( $done );
		$this->assertArrayNotHasKey( 'requires_confirmation', (array) $done, 'No preview, no token.' );
		$this->assertSame( 'trash', get_post_status( $post ), 'Moved to the Trash, never deleted for good without force.' );

		$entries = wp_list_filter( Saddle_Log::query( 20 )['entries'], array( 'target' => (string) $post ) );
		$this->assertCount( 1, $entries, 'The delete is in Logs.' );
		$entry = reset( $entries );
		$this->assertSame( 'available', $entry['undo'], 'And it can be undone.' );

		$undone = $this->call( 'undo-changes', array( 'entries' => array( $entry['id'] ) ) );
		$this->assertNotWPError( $undone );
		$this->assertArrayNotHasKey( 'requires_confirmation', (array) $undone );
		$this->assertSame( 'publish', get_post_status( $post ), 'Undo puts it back.' );
	}

	public function test_an_agent_without_the_switch_still_previews_a_delete() {
		$post = self::factory()->post->create( array( 'post_title' => 'Ask me first' ) );
		$this->trusted_app( 'Claude Code' );
		$this->app( 'admin', 'Cursor' ); // Manage the site too, switch off.

		$preview = $this->call( 'delete-post', array( 'id' => $post ) );

		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertSame( 'publish', get_post_status( $post ) );

		$done = $this->call(
			'delete-post',
			array(
				'id'            => $post,
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertNotWPError( $done );
		$this->assertSame( 'trash', get_post_status( $post ) );
	}

	public function test_a_token_it_brings_is_still_checked() {
		$post = self::factory()->post->create( array( 'post_title' => 'Checked' ) );
		$this->trusted_app();

		$made_up = $this->call(
			'delete-post',
			array(
				'id'            => $post,
				'confirm_token' => str_repeat( 'b', 32 ),
			)
		);

		$this->assertWPError( $made_up );
		$this->assertSame( 'publish', get_post_status( $post ) );
	}

	public function test_a_request_held_before_the_switch_runs_once_it_is_on() {
		$post = self::factory()->post->create( array( 'post_title' => 'Held before' ) );
		Saddle_Tool_Modes::set_mode( 'delete-post', Saddle_Tool_Modes::ASK );
		$uuid = $this->app( 'admin' );

		$held = $this->call( 'delete-post', array( 'id' => $post ) );
		$this->assertTrue( $held['needs_owner_approval'] );
		$this->assertSame( 'publish', get_post_status( $post ) );

		$this->as_owner(
			static function () use ( $uuid ) {
				Saddle_Run_Without_Asking::set( 'key:' . $uuid, true );
			}
		);

		$result = $this->call(
			'delete-post',
			array(
				'id'            => $post,
				'confirm_token' => $held['confirm_token'],
			)
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 'trash', get_post_status( $post ) );
	}

	public function test_rehearsal_drafts_only_and_the_pause_still_apply() {
		Saddle_Tool_Modes::set_mode( 'create-post', Saddle_Tool_Modes::ASK );
		$this->trusted_app();

		Saddle_Capabilities::set_rehearsal( true );
		$this->assertTrue( $this->call( 'create-post', array( 'title' => 'Practice' ) )['rehearsal'] );
		$this->assertEmpty( $this->posts_titled( 'Practice' ) );
		Saddle_Capabilities::set_rehearsal( false );

		Saddle_Capabilities::set_drafts_only( true );
		$this->call(
			'create-post',
			array(
				'title'  => 'Not live yet',
				'status' => 'publish',
			)
		);
		$ids = $this->posts_titled( 'Not live yet' );
		$this->assertCount( 1, $ids );
		$this->assertSame( 'draft', get_post_status( $ids[0] ) );

		$publish = $this->call(
			'update-post',
			array(
				'id'     => $ids[0],
				'status' => 'publish',
			)
		);
		$this->assertTrue( $publish['requires_confirmation'], 'Publishing under drafts only still previews: it is the owner’s rule.' );
		$this->assertSame( 'draft', get_post_status( $ids[0] ) );
		Saddle_Capabilities::set_drafts_only( false );

		Saddle_Capabilities::set_paused( true );
		$this->assertWPError( $this->call( 'create-post', array( 'title' => 'Paused' ) ) );
		$this->assertEmpty( $this->posts_titled( 'Paused' ) );
	}

	/* ------------------------------------------------- only the owner sets it */

	public function test_no_tool_and_no_agent_can_turn_it_on() {
		$this->assertTrue( Saddle_Settings_Guard::is_protected_option( Saddle_Run_Without_Asking::OPTION ) );

		$uuid = $this->app( 'admin' );
		$tool = $this->call(
			'update-option',
			array(
				'name'  => Saddle_Run_Without_Asking::OPTION,
				'value' => 'key:' . $uuid,
			)
		);
		$this->assertWPError( $tool );

		// The owner's route, called with the agent's own sign-in.
		$response = $this->rest( 'POST', '/saddle/v1/connections/key:' . $uuid . '/run-without-asking', array( 'on' => true ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( Saddle_Run_Without_Asking::is_on( 'key:' . $uuid ) );

		// Only the owner's REST route turns it on.
		foreach ( glob( SADDLE_DIR . 'includes/{*,*/*,*/*/*}.php', GLOB_BRACE ) as $file ) {
			if ( false !== strpos( $file, '/includes/lib/' ) || false !== strpos( $file, 'class-saddle-connections-rest.php' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( 'Saddle_Run_Without_Asking::set(', (string) file_get_contents( $file ), $file );
		}
	}

	public function test_the_owner_turns_it_on_and_off_on_agent_setup() {
		$uuid = $this->app( 'admin' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] ); // The owner, in wp-admin.
		$id = 'key:' . $uuid;

		$rows = wp_list_pluck( $this->rest( 'GET', '/saddle/v1/connections' )->get_data()['connections'], 'run_without_asking', 'id' );
		$this->assertFalse( $rows[ $id ], 'It never starts on.' );

		$on = $this->rest( 'POST', '/saddle/v1/connections/' . $id . '/run-without-asking', array( 'on' => true ) );
		$this->assertSame( 200, $on->get_status() );
		$this->assertTrue( $on->get_data()['run_without_asking'] );
		$rows = wp_list_pluck( $this->rest( 'GET', '/saddle/v1/connections' )->get_data()['connections'], 'run_without_asking', 'id' );
		$this->assertTrue( $rows[ $id ] );

		$off = $this->rest( 'POST', '/saddle/v1/connections/' . $id . '/run-without-asking', array( 'on' => false ) );
		$this->assertSame( 200, $off->get_status() );
		$this->assertFalse( Saddle_Run_Without_Asking::is_on( $id ) );

		$writer = $this->app( 'write', 'Cursor' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$this->assertSame( 409, $this->rest( 'POST', '/saddle/v1/connections/key:' . $writer . '/run-without-asking', array( 'on' => true ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'POST', '/saddle/v1/connections/key:no-such-key/run-without-asking', array( 'on' => true ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'POST', '/saddle/v1/connections/' . $id . '/run-without-asking', array( 'on' => true ) )->get_status() );
	}

	/* ------------------------------------------------- what the agent is told */

	public function test_only_the_trusted_agent_is_told_it_runs_directly() {
		Saddle_Tool_Modes::set_mode( 'delete-post', Saddle_Tool_Modes::ASK );

		$this->app( 'admin', 'Cursor' );
		$others = Saddle_Context::system_context();
		$this->assertStringContainsString( 'needs_owner_approval', $others );
		$this->assertStringContainsString( 'single-use confirmation token', $others );
		$this->assertStringNotContainsString( 'run without asking', $others );

		$this->trusted_app();
		$context = Saddle_Context::system_context();
		$this->assertStringContainsString( 'run without asking', $context );
		$this->assertStringContainsString( 'no confirmation token', $context );
		$this->assertStringNotContainsString( 'needs_owner_approval', $context );
		$this->assertStringNotContainsString( 'Needs your OK', $context );
		$this->assertStringNotContainsString( 'single-use confirmation token', $context );
	}

	/* ------------------------------------------------- clean-up */

	public function test_disconnecting_the_agent_clears_it() {
		$uuid = $this->trusted_app();
		$this->assertTrue( Saddle_Run_Without_Asking::is_on( 'key:' . $uuid ) );

		WP_Application_Passwords::delete_application_password( $this->admin, $uuid );
		$this->assertFalse( Saddle_Run_Without_Asking::is_on( 'key:' . $uuid ) );

		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$grant = $this->grant( 'admin' );
		$this->assertTrue( Saddle_Run_Without_Asking::set( 'oauth:' . $grant, true ) );
		$this->assertTrue( Saddle_Run_Without_Asking::applies( 'oauth:' . $grant ) );

		Saddle_OAuth_Store::revoke_grant( $grant );
		$this->assertFalse( Saddle_Run_Without_Asking::is_on( 'oauth:' . $grant ) );
		$this->assertFalse( get_option( Saddle_Run_Without_Asking::OPTION, false ), 'The option goes once nothing is left.' );
	}

	public function test_uninstall_removes_it() {
		$this->assertStringContainsString( "'" . Saddle_Run_Without_Asking::OPTION . "'", (string) file_get_contents( SADDLE_DIR . 'uninstall.php' ) );
	}
}
