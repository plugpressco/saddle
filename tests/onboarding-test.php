<?php
/**
 * Onboarding state (#277): the migration from `saddle_onboarded`, every event,
 * and the promise that no event touches the access tier.
 *
 * @package Saddle
 */

class Saddle_Onboarding_Test extends WP_UnitTestCase {

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		delete_option( Saddle_Onboarding::OPTION );
		delete_option( Saddle_Onboarding::LEGACY_OPTION );
		Saddle_OAuth_Store::register_cpt();
		Saddle_Log::register_cpt();
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		delete_option( Saddle_Onboarding::OPTION );
		delete_option( Saddle_Onboarding::LEGACY_OPTION );
		delete_option( Saddle_Capabilities::OPTION );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function post( array $body ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/onboarding' );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	/* ------------------------------------------------------------ migration */

	public function test_a_fresh_site_starts_new_and_is_not_finished() {
		$state = Saddle_Onboarding::state();

		$this->assertSame( 'new', $state['first_run']['state'] );
		$this->assertFalse( Saddle_Onboarding::is_finished() );
		$this->assertIsArray( get_option( Saddle_Onboarding::OPTION ) );
	}

	public function test_the_old_flag_counts_as_done_and_is_deleted() {
		update_option( 'saddle_onboarded', true );

		$this->assertTrue( Saddle_Onboarding::is_finished() );
		$this->assertFalse( get_option( 'saddle_onboarded', false ) );
		$this->assertSame( 'done', Saddle_Onboarding::state()['first_run']['state'] );
	}

	public function test_a_key_saddle_issued_counts_as_done() {
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'Saddle: Cursor' ) );
		Saddle_Connection::mark_issued( $this->admin, $item['uuid'] );

		$this->assertTrue( Saddle_Onboarding::is_finished() );
	}

	public function test_a_revoked_key_does_not_count() {
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'Saddle: Cursor' ) );
		Saddle_Connection::mark_issued( $this->admin, $item['uuid'] );
		WP_Application_Passwords::delete_application_password( $this->admin, $item['uuid'] );

		$this->assertFalse( Saddle_Onboarding::is_finished() );
	}

	public function test_a_key_saddle_did_not_issue_does_not_count() {
		WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'Some other tool' ) );

		$this->assertFalse( Saddle_Onboarding::is_finished() );
	}

	public function test_an_oauth_grant_counts_as_done() {
		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => 'g1',
				'client_id'   => 'saddle_x',
				'client_name' => 'Claude',
				'user_id'     => $this->admin,
				'scope'       => 'saddle:read',
			)
		);

		$this->assertTrue( Saddle_Onboarding::is_finished() );
	}

	public function test_a_logged_change_counts_as_done() {
		wp_insert_post(
			array(
				'post_type'   => Saddle_Log::CPT,
				'post_status' => 'publish',
				'post_title'  => 'Updated "Pricing"',
			)
		);

		$this->assertTrue( Saddle_Onboarding::is_finished() );
	}

	public function test_migration_is_idempotent() {
		update_option( 'saddle_onboarded', true );
		$first = Saddle_Onboarding::migrate();

		// A later signal must not rewrite what was decided.
		delete_option( 'saddle_onboarded' );
		$second = Saddle_Onboarding::migrate();

		$this->assertSame( $first, $second );
		$this->assertSame( 'done', Saddle_Onboarding::state()['first_run']['state'] );
	}

	public function test_a_stale_old_flag_is_removed_once_the_state_exists() {
		Saddle_Onboarding::state();
		update_option( 'saddle_onboarded', true );

		Saddle_Onboarding::migrate();

		$this->assertFalse( get_option( 'saddle_onboarded', false ) );
		$this->assertFalse( Saddle_Onboarding::is_finished() );
	}

	/* --------------------------------------------------------------- events */

	public function test_a_step_marks_the_run_active_and_is_remembered() {
		$response = $this->post(
			array(
				'event' => 'first_run.step',
				'step'  => 'connect',
				'app'   => 'claude',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'active', $data['first_run']['state'] );
		$this->assertSame( 'connect', $data['first_run']['step'] );
		$this->assertSame( 'claude', $data['first_run']['app'] );
		$this->assertFalse( Saddle_Onboarding::is_finished() );
	}

	public function test_an_unknown_step_is_refused() {
		$response = $this->post(
			array(
				'event' => 'first_run.step',
				'step'  => 'bogus',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'saddle_onboarding_invalid', $response->get_data()['code'] );
	}

	public function test_skip_finishes_the_run() {
		$data = $this->post( array( 'event' => 'first_run.skip' ) )->get_data();

		$this->assertSame( 'skipped', $data['first_run']['state'] );
		$this->assertGreaterThan( 0, $data['first_run']['finished_at'] );
		$this->assertTrue( Saddle_Onboarding::is_finished() );
	}

	public function test_done_records_the_tier_choice() {
		$data = $this->post(
			array(
				'event'       => 'first_run.done',
				'tier_choice' => 'write',
			)
		)->get_data();

		$this->assertSame( 'done', $data['first_run']['state'] );
		$this->assertSame( 'write', $data['first_run']['tier_choice'] );
		$this->assertSame( '', $data['first_run']['step'] );
	}

	public function test_a_bad_tier_choice_is_refused() {
		$this->assertSame(
			400,
			$this->post(
				array(
					'event'       => 'first_run.done',
					'tier_choice' => 'admin',
				)
			)->get_status()
		);
	}

	public function test_running_setup_again_never_unfinishes_the_site() {
		$this->post( array( 'event' => 'first_run.skip' ) );

		$data = $this->post(
			array(
				'event' => 'first_run.step',
				'step'  => 'app',
			)
		)->get_data();
		$this->assertSame( 'skipped', $data['first_run']['state'] );
		$this->assertTrue( Saddle_Onboarding::is_finished() );

		$data = $this->post( array( 'event' => 'first_run.skip' ) )->get_data();
		$this->assertSame( 'skipped', $data['first_run']['state'] );
	}

	public function test_the_tour_is_per_user() {
		$this->assertFalse( $this->post( array( 'event' => 'first_run.skip' ) )->get_data()['user']['tour_done'] );

		$data = $this->post( array( 'event' => 'tour.done' ) )->get_data();
		$this->assertTrue( $data['user']['tour_done'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$other = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/onboarding' ) )->get_data();
		$this->assertFalse( $other['user']['tour_done'] );
	}

	public function test_intro_seen_and_setup_hide_are_kept_per_module() {
		$this->post(
			array(
				'event'   => 'intro.seen',
				'module'  => 'analytics',
				'version' => '1.0.0',
			)
		);
		$data = $this->post(
			array(
				'event'  => 'setup.hide',
				'module' => 'analytics',
			)
		)->get_data();

		$this->assertSame( '1.0.0', $data['modules']['analytics']['intro_seen'] );
		$this->assertGreaterThan( 0, $data['modules']['analytics']['setup_hidden_at'] );
	}

	public function test_a_module_event_needs_a_module() {
		$this->assertSame( 400, $this->post( array( 'event' => 'setup.hide' ) )->get_status() );
	}

	public function test_an_unknown_event_is_refused() {
		$this->assertSame( 400, $this->post( array( 'event' => 'first_run.explode' ) )->get_status() );
	}

	public function test_no_event_changes_the_tier() {
		delete_option( Saddle_Capabilities::OPTION );

		foreach ( array(
			array( 'event' => 'first_run.step', 'step' => 'choose' ),
			array( 'event' => 'first_run.done', 'tier_choice' => 'write' ),
			array( 'event' => 'first_run.skip' ),
			array( 'event' => 'tour.done' ),
			array( 'event' => 'intro.seen', 'module' => 'x', 'version' => '1' ),
			array( 'event' => 'setup.hide', 'module' => 'x' ),
		) as $event ) {
			$this->assertSame( 200, $this->post( $event )->get_status(), $event['event'] );
		}

		// The DB row, not the accessor: a new install must still say nothing.
		$this->assertFalse( get_option( Saddle_Capabilities::OPTION, false ) );
		$this->assertSame( 'read', Saddle_Capabilities::get_site_tier() );
	}

	/* --------------------------------------------------------------- routes */

	public function test_the_state_shape() {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/onboarding' ) )->get_data();

		$this->assertSame( array( 'first_run', 'modules', 'user' ), array_keys( $data ) );
		$this->assertSame( array( 'state', 'step', 'app', 'tier_choice', 'finished_at' ), array_keys( $data['first_run'] ) );
		$this->assertSame( array( 'tour_done' ), array_keys( $data['user'] ) );
	}

	public function test_both_methods_need_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/onboarding' ) )->get_status() );
		$this->assertSame( 403, $this->post( array( 'event' => 'first_run.skip' ) )->get_status() );
		$this->assertFalse( Saddle_Onboarding::is_finished() );
	}

	public function test_the_old_preferences_param_still_works_both_ways() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/preferences' );
		$request->set_body_params( array( 'onboarded' => true ) );
		$this->assertTrue( rest_do_request( $request )->get_data()['onboarded'] );
		$this->assertTrue( Saddle_Onboarding::is_finished() );

		$request->set_body_params( array( 'onboarded' => false ) );
		$this->assertFalse( rest_do_request( $request )->get_data()['onboarded'] );
		$this->assertSame( 'new', Saddle_Onboarding::state()['first_run']['state'] );
	}

	/* --------------------------------------------------------------- pulse */

	private function register_client( $name, $uri ) {
		$id = 'saddle_' . wp_generate_password( 8, false );
		Saddle_OAuth_Store::save_client(
			$id,
			array(
				'client_name'   => $name,
				'redirect_uris' => array( $uri ),
				'client_source' => 'dcr',
			)
		);

		return $id;
	}

	public function test_the_pulse_lists_a_registered_app_with_no_grant_as_pending() {
		$id = $this->register_client( 'Claude', 'https://claude.ai/api/mcp/auth_callback' );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/connections/pulse' ) )->get_data();

		$this->assertCount( 1, $data['pending'] );
		$this->assertSame( $id, $data['pending'][0]['client_id'] );
		$this->assertSame( 'Claude', $data['pending'][0]['client_name'] );
		$this->assertSame( 'claude', $data['pending'][0]['app'] );
		$this->assertGreaterThan( 0, $data['pending'][0]['registered_at'] );
	}

	public function test_an_approved_app_is_no_longer_pending() {
		$id = $this->register_client( 'Claude', 'https://claude.ai/api/mcp/auth_callback' );
		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => 'g2',
				'client_id'   => $id,
				'client_name' => 'Claude',
				'user_id'     => $this->admin,
				'scope'       => 'saddle:read',
			)
		);

		$data = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/connections/pulse' ) )->get_data();

		$this->assertSame( array(), $data['pending'] );
	}

	public function test_only_registrations_after_since_are_pending() {
		$this->register_client( 'Cursor', 'cursor://anysphere.cursor-retrieval/oauth/callback' );

		$request = new WP_REST_Request( 'GET', '/saddle/v1/connections/pulse' );
		$request->set_param( 'since', time() + 60 );

		$this->assertSame( array(), rest_do_request( $request )->get_data()['pending'] );
	}

	public function test_a_metadata_document_client_is_not_pending() {
		Saddle_OAuth_Store::save_client(
			'https://example.com/client.json',
			array(
				'client_name'   => 'Claude Code',
				'redirect_uris' => array( 'http://localhost:1234/cb' ),
				'client_source' => 'cimd',
			)
		);

		$data = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/connections/pulse' ) )->get_data();

		$this->assertSame( array(), $data['pending'] );
	}

	/* --------------------------------------------------------- Plugins row */

	public function test_the_plugins_row_follows_is_finished() {
		$links = Saddle_Settings::action_links( array() );
		$this->assertStringContainsString( 'Get started', $links[0] );

		Saddle_Onboarding::apply( array( 'event' => 'first_run.done', 'tier_choice' => 'read' ) );
		$links = Saddle_Settings::action_links( array() );
		$this->assertStringContainsString( 'page=saddle-settings', $links[0] );
	}
}
