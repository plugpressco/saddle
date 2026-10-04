<?php
/**
 * Per-app access: the role a connection holds decides what it may do, not a
 * site-wide level. Two keys of one WordPress user can differ; a browser
 * session keeps the legacy site tier; an unknown or new key is read-only; an
 * update never widens what an existing install could do (R3).
 *
 * @package Saddle
 */

class Saddle_Access_Test extends WP_UnitTestCase {

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
		unset( $_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['wp_rest_application_password_uuid'] );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		Saddle_OAuth_Store::register_cpt();
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		$this->sign_out();
		foreach ( array( Saddle_Access::KEY_ROLES_OPTION, Saddle_Access::VERSION_OPTION, Saddle_Capabilities::OPTION, Saddle_Connections::OPTION ) as $option ) {
			delete_option( $option );
		}
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * A Saddle-issued key for the admin, the way the wizard mints one.
	 *
	 * @return string uuid.
	 */
	private function key( $label = 'Cursor' ) {
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . $label ) );
		Saddle_Connection::mark_issued( $this->admin, $item['uuid'] );

		return $item['uuid'];
	}

	/**
	 * Look signed in with a key, as core leaves the request after authenticating
	 * an Application Password.
	 */
	private function sign_in_with_key( $uuid ) {
		$GLOBALS['wp_rest_application_password_uuid'] = $uuid;
	}

	private function grant( $scope, $client = 'saddle_test' ) {
		$grant = Saddle_OAuth::random_secret( 16 );
		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => $grant,
				'client_id'   => $client,
				'client_name' => 'ChatGPT',
				'user_id'     => $this->admin,
				'scope'       => $scope,
				'resource'    => Saddle_OAuth::resource_id(),
			)
		);

		return $grant;
	}

	/**
	 * Sign in with an OAuth grant, as the bearer resolver does on a request.
	 */
	private function sign_in_with_grant( $grant_id, $scope ) {
		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		update_option( 'permalink_structure', '/%postname%/' );

		$token = Saddle_OAuth::random_secret( 32 );
		Saddle_OAuth_Store::save_token(
			'access',
			$token,
			array(
				'grant_id'  => $grant_id,
				'client_id' => 'saddle_test',
				'user_id'   => $this->admin,
				'scope'     => $scope,
				'resource'  => Saddle_OAuth::resource_id(),
			)
		);

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';
		wp_set_current_user( Saddle_OAuth_Bearer::resolve( false ) );
	}

	private function sign_out() {
		remove_filter( 'saddle_tier_ceiling', array( 'Saddle_OAuth_Bearer', 'tier_ceiling' ) );
		remove_filter( 'saddle_oauth_enabled', '__return_true' );
		remove_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		delete_option( 'permalink_structure' );

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

	private function create_post_as_current() {
		return wp_get_ability( 'saddle/create-post' )->execute(
			array(
				'title'   => 'Access test',
				'content' => 'Body',
			)
		);
	}

	private function rest( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	private function tool_names() {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );

		return wp_list_pluck( Saddle_MCP::handle( $req )->get_data()['result']['tools'], 'name' );
	}

	/* -------------------------------------------------- per-key enforcement */

	public function test_two_keys_of_one_user_are_held_to_their_own_role() {
		Saddle_Capabilities::set_tier( 'read' ); // The legacy tier is irrelevant to a key.
		$editor = $this->key( 'Editor app' );
		$viewer = $this->key( 'Viewer app' );
		Saddle_Access::set_role( 'key:' . $editor, 'write' );

		$this->sign_in_with_key( $editor );
		$this->assertSame( 'write', Saddle_Capabilities::get_tier() );
		$this->assertNotWPError( $this->create_post_as_current() );

		$this->sign_in_with_key( $viewer );
		$this->assertSame( 'read', Saddle_Capabilities::get_tier(), 'Same WordPress user, no role stored: read.' );
		$refused = $this->create_post_as_current();
		$this->assertWPError( $refused );
		$this->assertSame( 'ability_invalid_permissions', $refused->get_error_code() );

		$reason = Saddle_Capabilities::denial_reason( 'saddle/create-post' );
		$this->assertSame( 'saddle_tier_denied', $reason['code'] );
		$this->assertStringContainsString( 'Saddle → AI apps', $reason['message'] );
	}

	public function test_a_key_is_not_raised_by_a_high_legacy_site_tier() {
		Saddle_Capabilities::set_tier( 'admin' );
		$this->sign_in_with_key( $this->key() );

		$this->assertSame( 'read', Saddle_Capabilities::get_tier() );
	}

	public function test_an_unknown_key_gets_read() {
		Saddle_Capabilities::set_tier( 'admin' );
		$this->sign_in_with_key( 'not-a-key-we-issued' );

		$this->assertSame( 'read', Saddle_Access::role_for( Saddle_Access::current_connection() ) );
		$this->assertSame( 'read', Saddle_Capabilities::get_tier() );
	}

	public function test_a_browser_session_keeps_the_legacy_site_tier() {
		Saddle_Capabilities::set_tier( 'write' );

		$this->assertSame( '', Saddle_Access::current_connection() );
		$this->assertSame( 'write', Saddle_Capabilities::get_tier() );
	}

	public function test_a_key_role_can_only_be_lowered_by_the_ceiling_filter() {
		$uuid = $this->key();
		Saddle_Access::set_role( 'key:' . $uuid, 'write' );
		$this->sign_in_with_key( $uuid );

		add_filter( 'saddle_tier_ceiling', static fn() => 'read' );
		$this->assertSame( 'read', Saddle_Capabilities::get_tier() );
		remove_all_filters( 'saddle_tier_ceiling' );

		add_filter( 'saddle_tier_ceiling', static fn() => 'admin' );
		$this->assertSame( 'write', Saddle_Capabilities::get_tier(), 'A ceiling never raises.' );
		remove_all_filters( 'saddle_tier_ceiling' );
	}

	public function test_tools_list_for_a_read_key_hides_write_tools() {
		$uuid = $this->key();
		$this->sign_in_with_key( $uuid );

		$names = $this->tool_names();
		$this->assertContains( 'saddle-list-posts', $names );
		$this->assertNotContains( 'saddle-create-post', $names );

		Saddle_Access::set_role( 'key:' . $uuid, 'write' );
		$names = $this->tool_names();
		$this->assertContains( 'saddle-create-post', $names );
		$this->assertNotContains( 'saddle-list-plugins', $names, 'write is not admin.' );

		Saddle_Access::set_role( 'key:' . $uuid, 'admin' );
		$this->assertContains( 'saddle-list-plugins', $this->tool_names() );
	}

	public function test_the_context_states_the_apps_role() {
		$uuid = $this->key();
		Saddle_Access::set_role( 'key:' . $uuid, 'write' );
		$this->sign_in_with_key( $uuid );

		$this->assertStringContainsString( 'Your access on this site: Edit content. The owner sets it for each app on Saddle → AI apps.', Saddle_Context::system_context() );

		Saddle_Access::set_role( 'key:' . $uuid, 'read' );
		$this->assertStringContainsString( 'Your access on this site: Read only.', Saddle_Context::system_context() );
	}

	/* -------------------------------------------------------------- OAuth */

	public function test_an_oauth_grants_role_is_its_stored_level() {
		Saddle_Capabilities::set_tier( 'read' );
		$grant = $this->grant( 'saddle:read saddle:write' );
		$this->sign_in_with_grant( $grant, 'saddle:read saddle:write' );

		$this->assertSame( 'oauth:' . $grant, Saddle_Access::current_connection() );
		$this->assertSame( 'write', Saddle_Capabilities::get_tier(), 'Not clamped to the site tier any more.' );
		$this->assertNotWPError( $this->create_post_as_current() );
	}

	public function test_set_role_on_a_grant_updates_the_same_stored_scope() {
		$grant = $this->grant( 'saddle:read' );

		$this->assertTrue( Saddle_Access::set_role( 'oauth:' . $grant, 'admin' ) );
		$this->assertSame( 'saddle:read saddle:write saddle:admin', Saddle_OAuth_Store::get_grant( $grant )['scope'] );
		$this->assertSame( 'admin', Saddle_Access::role_for( 'oauth:' . $grant ) );

		$this->assertSame( 'saddle_unknown_connection', Saddle_Access::set_role( 'oauth:nope', 'read' )->get_error_code() );
	}

	public function test_a_grants_scope_can_still_only_lower_what_the_grant_holds() {
		$grant = $this->grant( 'saddle:read saddle:write saddle:admin' );
		$this->sign_in_with_grant( $grant, 'saddle:read' ); // The token carries less.

		$this->assertSame( 'read', Saddle_Capabilities::get_tier() );
	}

	/* ------------------------------------------------------------- new keys */

	public function test_a_key_created_from_the_admin_starts_read_only() {
		Saddle_Capabilities::set_tier( 'admin' );

		$response = $this->rest( 'POST', '/saddle/v1/clients', array( 'name' => 'Fresh app' ) );
		$this->assertSame( 201, $response->get_status() );

		$uuid = $response->get_data()['uuid'];
		$this->assertSame( 'read', Saddle_Access::role_for( 'key:' . $uuid ) );
		$this->assertSame( 'read', get_option( Saddle_Access::KEY_ROLES_OPTION )[ $uuid ] );
	}

	public function test_rotating_a_key_keeps_its_role() {
		$response = $this->rest( 'POST', '/saddle/v1/clients', array( 'name' => 'Rotating app' ) );
		$old      = $response->get_data()['uuid'];
		Saddle_Access::set_role( 'key:' . $old, 'write' );

		$rotated = $this->rest( 'POST', '/saddle/v1/clients/' . $old . '/rotate' );
		$new     = $rotated->get_data()['uuid'];

		$this->assertNotSame( $old, $new );
		$this->assertSame( 'write', Saddle_Access::role_for( 'key:' . $new ) );
		$this->assertArrayNotHasKey( $old, get_option( Saddle_Access::KEY_ROLES_OPTION ) );
	}

	public function test_deleting_a_key_removes_its_role() {
		$gone = $this->key( 'Gone' );
		$kept = $this->key( 'Kept' );
		Saddle_Access::set_role( 'key:' . $gone, 'admin' );
		Saddle_Access::set_role( 'key:' . $kept, 'write' );

		WP_Application_Passwords::delete_application_password( $this->admin, $gone );

		$roles = get_option( Saddle_Access::KEY_ROLES_OPTION );
		$this->assertArrayNotHasKey( $gone, $roles );
		$this->assertSame( 'write', $roles[ $kept ] );
	}

	/* ------------------------------------------------------- R3 migration */

	public function test_an_update_never_widens_what_an_install_could_do() {
		Saddle_Capabilities::set_tier( 'write' );
		$key   = $this->key( 'Existing key' );
		$grant = $this->grant( 'saddle:read saddle:write saddle:admin' );

		// Before: a key ran at the site tier; a saddle:admin grant was clamped to it.
		delete_option( Saddle_Access::VERSION_OPTION );
		delete_option( Saddle_Access::KEY_ROLES_OPTION );

		Saddle_Access::maybe_migrate();

		$this->assertSame( 'write', Saddle_Access::role_for( 'key:' . $key ), 'The key keeps exactly the site tier.' );
		$this->assertSame( 'write', Saddle_Access::role_for( 'oauth:' . $grant ), 'The grant ends at the lower of site tier and its own level.' );
		$this->assertSame( 'write', Saddle_Capabilities::get_site_tier(), 'The legacy option stays for rollback.' );
		$this->assertSame( Saddle_Access::VERSION, (int) get_option( Saddle_Access::VERSION_OPTION ) );

		$this->sign_in_with_key( $key );
		$this->assertNotWPError( $this->create_post_as_current() );
		$this->assertNotContains( 'saddle-list-plugins', $this->tool_names(), 'Nothing widened to admin.' );
	}

	public function test_the_migration_covers_keys_the_registry_has_seen_and_never_lowers_a_grant() {
		Saddle_Capabilities::set_tier( 'admin' );
		$foreign = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'Backup plugin' ) )[1]['uuid'];
		$seen    = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'Seen by registry' ) )[1]['uuid'];
		update_option( Saddle_Connections::OPTION, array( 'key:' . $seen => array( 'kind' => 'key', 'user' => $this->admin, 'last_seen_at' => time() ) ), false );
		$grant = $this->grant( 'saddle:read' );

		delete_option( Saddle_Access::VERSION_OPTION );
		Saddle_Access::maybe_migrate();

		$this->assertSame( 'admin', Saddle_Access::role_for( 'key:' . $seen ) );
		$this->assertArrayNotHasKey( $foreign, get_option( Saddle_Access::KEY_ROLES_OPTION ), 'A key that never reached Saddle gets no role at migration.' );
		$this->assertSame( 'read', Saddle_Access::role_for( 'oauth:' . $grant ), 'Migration only ever lowers a grant.' );
	}

	/**
	 * Legacy support (Fahim, 2026-10-04: "keep legacy support"). In 1.3.0 any
	 * Application Password reached Saddle at the site tier, including one the
	 * owner made by hand under Users → Profile, and 1.3.0 kept no record of
	 * which keys used Saddle. Such a key keeps the old site tier the first
	 * time it calls Saddle after the update, and that becomes its role.
	 */
	public function test_a_hand_made_key_from_before_the_update_keeps_the_old_site_tier() {
		$hand_made = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'My laptop' ) )[1]['uuid'];
		update_option( Saddle_Access::LEGACY_BEFORE_OPTION, time() + 60 );
		update_option( Saddle_Access::LEGACY_TIER_OPTION, 'write' );

		$this->assertArrayNotHasKey( $hand_made, (array) get_option( Saddle_Access::KEY_ROLES_OPTION, array() ), 'No role is handed out ahead of use.' );

		$this->sign_in_with_key( $hand_made );
		$this->assertSame( 'write', Saddle_Capabilities::get_tier(), 'The key does what it could do in 1.3.0.' );
		$this->assertSame( 'write', get_option( Saddle_Access::KEY_ROLES_OPTION )[ $hand_made ], 'It is stored, so AI apps shows it and the owner can change it.' );
		$this->assertNotWPError( $this->create_post_as_current() );
	}

	public function test_a_key_made_after_the_update_starts_read_only() {
		update_option( Saddle_Access::LEGACY_BEFORE_OPTION, time() - 60 );
		update_option( Saddle_Access::LEGACY_TIER_OPTION, 'admin' );
		$new_key = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'Made today' ) )[1]['uuid'];

		$this->sign_in_with_key( $new_key );
		$this->assertSame( 'read', Saddle_Capabilities::get_tier() );
	}

	public function test_a_legacy_key_the_owner_lowers_stays_lowered() {
		$hand_made = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'My laptop' ) )[1]['uuid'];
		update_option( Saddle_Access::LEGACY_BEFORE_OPTION, time() + 60 );
		update_option( Saddle_Access::LEGACY_TIER_OPTION, 'admin' );
		Saddle_Access::set_role( 'key:' . $hand_made, 'read' );

		$this->sign_in_with_key( $hand_made );
		$this->assertSame( 'read', Saddle_Capabilities::get_tier() );
	}

	public function test_the_legacy_moment_is_recorded_once() {
		delete_option( Saddle_Access::VERSION_OPTION );
		update_option( Saddle_Access::LEGACY_BEFORE_OPTION, 1000 );
		update_option( Saddle_Access::LEGACY_TIER_OPTION, 'write' );
		Saddle_Capabilities::set_tier( 'admin' );

		Saddle_Access::maybe_migrate();

		$this->assertSame( 1000, (int) get_option( Saddle_Access::LEGACY_BEFORE_OPTION ), 'A re-run never moves the line.' );
		$this->assertSame( 'write', get_option( Saddle_Access::LEGACY_TIER_OPTION ) );
	}

	public function test_the_migration_runs_once_and_is_idempotent() {
		Saddle_Capabilities::set_tier( 'write' );
		$key = $this->key();
		delete_option( Saddle_Access::VERSION_OPTION );
		delete_option( Saddle_Access::KEY_ROLES_OPTION );

		Saddle_Access::maybe_migrate();
		Saddle_Access::set_role( 'key:' . $key, 'read' ); // The owner lowers it afterwards.
		Saddle_Capabilities::set_tier( 'admin' );

		Saddle_Access::maybe_migrate(); // Version is current: a no-op.
		$this->assertSame( 'read', Saddle_Access::role_for( 'key:' . $key ) );

		Saddle_Access::migrate(); // Forced again: still does not overwrite a stored role.
		$this->assertSame( 'read', Saddle_Access::role_for( 'key:' . $key ) );
	}

	/* ---------------------------------------------------------- the route */

	public function test_the_role_route_is_gated_to_people_who_can_manage_the_site() {
		$uuid = $this->key();
		$this->rest( 'GET', '/saddle/v1/connections' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = $this->rest( 'POST', '/saddle/v1/connections/key:' . $uuid . '/role', array( 'role' => 'admin' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( 'read', Saddle_Access::role_for( 'key:' . $uuid ) );
	}

	public function test_the_role_route_sets_a_key_and_returns_the_row() {
		$uuid     = $this->key( 'Claude Code' );
		$response = $this->rest( 'POST', '/saddle/v1/connections/key:' . $uuid . '/role', array( 'role' => 'write' ) );

		$this->assertSame( 200, $response->get_status() );
		$row = $response->get_data();
		$this->assertSame( 'key:' . $uuid, $row['id'] );
		$this->assertSame( 'write', $row['role'] );
		$this->assertSame( 'Edit content', $row['role_label'] );
		$this->assertSame( 'write', get_option( Saddle_Access::KEY_ROLES_OPTION )[ $uuid ] );
	}

	public function test_the_role_route_sets_a_grant_in_its_scope() {
		$grant    = $this->grant( 'saddle:read' );
		$response = $this->rest( 'POST', '/saddle/v1/connections/oauth:' . $grant . '/role', array( 'role' => 'admin' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'admin', $response->get_data()['role'] );
		$this->assertSame( 'Manage the site', $response->get_data()['role_label'] );
		$this->assertSame( 'admin', Saddle_OAuth::scope_to_tier( Saddle_OAuth_Store::get_grant( $grant )['scope'] ) );
		$this->assertArrayNotHasKey( $grant, (array) get_option( Saddle_Access::KEY_ROLES_OPTION, array() ), 'A grant is not stored as a key.' );
	}

	public function test_the_role_route_refuses_a_bad_role_and_an_unknown_connection() {
		$uuid = $this->key();

		$bad = $this->rest( 'POST', '/saddle/v1/connections/key:' . $uuid . '/role', array( 'role' => 'superuser' ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'read', Saddle_Access::role_for( 'key:' . $uuid ) );

		$missing = $this->rest( 'POST', '/saddle/v1/connections/key:00000000-0000-0000-0000-000000000000/role', array( 'role' => 'write' ) );
		$this->assertSame( 404, $missing->get_status() );

		$missing_grant = $this->rest( 'POST', '/saddle/v1/connections/oauth:nope/role', array( 'role' => 'write' ) );
		$this->assertSame( 404, $missing_grant->get_status() );
	}

	public function test_connection_rows_carry_role_and_role_label() {
		$uuid  = $this->key();
		$grant = $this->grant( 'saddle:read saddle:write' );
		Saddle_Access::set_role( 'key:' . $uuid, 'admin' );

		$rows = array();
		foreach ( $this->rest( 'GET', '/saddle/v1/connections' )->get_data()['connections'] as $row ) {
			$rows[ $row['id'] ] = $row;
		}

		$this->assertSame( 'admin', $rows[ 'key:' . $uuid ]['role'] );
		$this->assertSame( 'Manage the site', $rows[ 'key:' . $uuid ]['role_label'] );
		$this->assertSame( 'write', $rows[ 'oauth:' . $grant ]['role'] );
		$this->assertSame( 'Edit content', $rows[ 'oauth:' . $grant ]['role_label'] );
	}

	public function test_the_legacy_oauth_route_no_longer_clamps_to_the_site_tier() {
		Saddle_Capabilities::set_tier( 'read' );
		$grant    = $this->grant( 'saddle:read' );
		$response = $this->rest( 'POST', '/saddle/v1/oauth-connections/' . $grant, array( 'level' => 'write' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'write', Saddle_Access::role_for( 'oauth:' . $grant ) );
	}

	/* ------------------------------------------ no tool can change a role */

	public function test_no_tool_can_write_a_role_option() {
		$this->assertTrue( Saddle_Settings_Guard::is_protected_option( 'saddle_key_roles' ) );
		$this->assertTrue( Saddle_Settings_Guard::is_protected_option( 'saddle_access_version' ) );

		Saddle_Capabilities::set_tier( 'admin' );
		foreach ( array( 'saddle_key_roles', 'saddle_access_version' ) as $name ) {
			$result = wp_get_ability( 'saddle/update-option' )->execute( array( 'name' => $name, 'value' => 'x' ) );
			$this->assertWPError( $result, $name );
		}
		$this->assertEmpty( get_option( Saddle_Access::KEY_ROLES_OPTION, array() ), 'No key was given a role by a tool.' );
	}

	/* -------------------------------------------------- agent-facing words */

	public function test_the_agent_is_sent_to_the_right_screen() {
		Saddle_Capabilities::set_paused( true );
		$reason = Saddle_Capabilities::denial_reason( 'saddle/list-posts' );
		Saddle_Capabilities::set_paused( false );
		$this->assertStringContainsString( 'the AI switch at the top of any Saddle page', $reason['message'] );

		Saddle_Capabilities::set_disabled_abilities( array( 'list-posts' ) );
		$reason = Saddle_Capabilities::denial_reason( 'saddle/list-posts' );
		Saddle_Capabilities::set_disabled_abilities( array() );
		$this->assertStringContainsString( 'Saddle → Settings', $reason['message'] );

		foreach ( glob( SADDLE_DIR . 'includes/{*,*/*}.php', GLOB_BRACE ) as $file ) {
			if ( false !== strpos( $file, '/includes/lib/' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( 'Saddle → Permissions', (string) file_get_contents( $file ), $file );
			$this->assertStringNotContainsString( 'Saddle → Apps', (string) file_get_contents( $file ), $file );
			// Every other way #285's removed screens were named (#296).
			$this->assertDoesNotMatchRegularExpression( '/Permissions screen|Integrations screen|Saddle Permissions/', (string) file_get_contents( $file ), $file );
		}
	}
}
