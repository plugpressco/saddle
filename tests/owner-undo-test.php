<?php
/**
 * POST /saddle/v1/undo: the owner's Undo on Home's Activity feed.
 *
 * What must hold: only an administrator in wp-admin reaches it, never an app;
 * a call without a token previews and changes nothing; the token confirms
 * that preview exactly once; what saddle/undo-changes refuses (edited since,
 * a permanent delete, a plugin update) is refused here with the same reason;
 * and the owner's undo is logged as their own step, journaled so it can be
 * undone in turn.
 *
 * @package Saddle
 */

class Saddle_Owner_Undo_Test extends WP_UnitTestCase {

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'saddle_scope_credentials', '__return_false' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		delete_option( Saddle_Connections::OPTION );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	/** An app's edit of a post's title, logged with its journal. Returns [post id, entry id]. */
	private function app_retitles( $before = 'Before', $after = 'After' ) {
		$id = self::factory()->post->create( array( 'post_title' => $before ) );
		$this->assertNotWPError(
			$this->run_ability(
				'update-post',
				array(
					'id'    => $id,
					'title' => $after,
				)
			)
		);
		return array( $id, $this->newest_entry() );
	}

	private function newest_entry() {
		$recent = Saddle_Log::recent_executed( 1 );
		$this->assertNotEmpty( $recent );
		return (int) $recent[0]['id'];
	}

	private function undo( array $entries, $token = null ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/undo' );
		$request->set_param( 'entries', $entries );
		if ( null !== $token ) {
			$request->set_param( 'confirm_token', $token );
		}
		return rest_do_request( $request );
	}

	private function preview( array $entries ) {
		$response = $this->undo( $entries );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function log_count() {
		return Saddle_Log::query( 1, 1 )['total'];
	}

	/* -------- who may call it -------- */

	public function test_an_editor_is_refused() {
		list( $id, $entry ) = $this->app_retitles();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = $this->undo( array( $entry ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'After', get_post( $id )->post_title );
	}

	public function test_a_logged_out_request_is_refused() {
		list( , $entry ) = $this->app_retitles();

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->undo( array( $entry ) )->get_status() );
	}

	/** Even if a filter lets Saddle keys reach admin routes, an app can't use the owner's undo. */
	public function test_an_app_connection_is_refused() {
		list( $id, $entry ) = $this->app_retitles();

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'saddle_scope_credentials', '__return_false' );
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . 'Claude' ) );
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid'];

		$response = $this->undo( array( $entry ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'saddle_owner_only', $response->get_data()['code'] );
		$this->assertSame( 'After', get_post( $id )->post_title );
	}

	public function test_the_route_needs_entries() {
		$this->assertSame( 400, $this->undo( array() )->get_status() );
	}

	/* -------- preview, then confirm once -------- */

	public function test_preview_changes_nothing_and_returns_a_token() {
		list( $id, $entry ) = $this->app_retitles();
		$logged             = $this->log_count();

		$preview = $this->preview( array( $entry ) );

		$this->assertSame( 1, $preview['ready'] );
		$this->assertSame( 'ready', $preview['entries'][0]['status'] );
		$this->assertNotEmpty( $preview['entries'][0]['steps'] );
		$this->assertNotEmpty( $preview['confirm_token'] );
		$this->assertSame( 'After', get_post( $id )->post_title, 'A preview must not change anything.' );
		$this->assertSame( $logged, $this->log_count(), 'A preview must not log anything.' );
		$this->assertSame( 'available', Saddle_Undo::availability( $entry ) );
	}

	/** The owner's preview is theirs: it never shows up in Needs your OK. */
	public function test_preview_is_not_a_request_waiting_for_the_owner() {
		list( , $entry ) = $this->app_retitles();

		$this->preview( array( $entry ) );

		$this->assertSame( array(), Saddle_Approval::pending() );
	}

	public function test_confirm_runs_once() {
		list( $id, $entry ) = $this->app_retitles();
		$token              = $this->preview( array( $entry ) )['confirm_token'];

		$first = $this->undo( array( $entry ), $token );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 1, $first->get_data()['undone'] );
		$this->assertSame( 'Before', get_post( $id )->post_title );

		// Someone puts the title back; a replay must not undo it again.
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'After',
			)
		);
		$logged = $this->log_count();
		$again  = $this->undo( array( $entry ), $token );

		$this->assertSame( 403, $again->get_status() );
		$this->assertSame( 'saddle_undo_token', $again->get_data()['code'] );
		$this->assertSame( 'After', get_post( $id )->post_title, 'A used token must change nothing.' );
		$this->assertSame( $logged, $this->log_count() );
	}

	public function test_a_token_is_bound_to_its_entries() {
		list( $id_a, $entry_a ) = $this->app_retitles( 'A before', 'A after' );
		list( $id_b, $entry_b ) = $this->app_retitles( 'B before', 'B after' );

		$token    = $this->preview( array( $entry_a ) )['confirm_token'];
		$response = $this->undo( array( $entry_b ), $token );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'A after', get_post( $id_a )->post_title );
		$this->assertSame( 'B after', get_post( $id_b )->post_title );
	}

	public function test_a_made_up_token_is_refused() {
		list( $id, $entry ) = $this->app_retitles();

		$response = $this->undo( array( $entry ), 'not-a-token' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'After', get_post( $id )->post_title );
	}

	/** What the owner saw is what runs: a change between preview and confirm stops it. */
	public function test_a_change_after_the_preview_stops_the_confirm() {
		list( $id, $entry ) = $this->app_retitles();
		$token              = $this->preview( array( $entry ) )['confirm_token'];

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'By a person',
			)
		);
		$response = $this->undo( array( $entry ), $token );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'saddle_undo_changed', $response->get_data()['code'] );
		$this->assertSame( 'By a person', get_post( $id )->post_title );
	}

	/* -------- refusals, with undo's own reasons -------- */

	public function test_a_later_edit_is_refused_with_the_reason_and_no_token() {
		list( $id, $entry ) = $this->app_retitles();
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'By a person',
			)
		);

		$preview = $this->preview( array( $entry ) );

		$this->assertSame( 0, $preview['ready'] );
		$this->assertArrayNotHasKey( 'confirm_token', $preview );
		$this->assertSame( 'skipped', $preview['entries'][0]['status'] );
		$this->assertStringContainsString( 'changed since', implode( ' ', $preview['entries'][0]['reasons'] ) );
		$this->assertSame( 'By a person', get_post( $id )->post_title );
	}

	public function test_a_permanent_delete_is_refused_with_the_reason() {
		$id      = self::factory()->post->create( array( 'post_title' => 'Gone' ) );
		$preview = $this->run_ability(
			'delete-post',
			array(
				'id'    => $id,
				'force' => true,
			)
		);
		$this->run_ability(
			'delete-post',
			array(
				'id'            => $id,
				'force'         => true,
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$plan = $this->preview( array( $this->newest_entry() ) );

		$this->assertSame( 0, $plan['ready'] );
		$this->assertStringContainsString( 'permanently deleted', implode( ' ', $plan['entries'][0]['reasons'] ) );
	}

	/**
	 * The owner's browser has no app, so the access level it reads is the
	 * old site-wide one, Read only by default. That level must not stop the
	 * owner undoing a setting an app changed; WordPress capabilities still
	 * apply. Found while joining the owner Undo to Activity (1.5.0).
	 */
	public function test_the_owner_can_undo_a_setting_whatever_the_old_site_level() {
		Saddle_Capabilities::set_tier( 'admin' );
		update_option( 'blogdescription', 'Before' );
		$input  = array(
			'name'  => 'blogdescription',
			'value' => 'After',
		);
		$result = $this->run_ability( 'update-option', $input );
		if ( is_array( $result ) && ! empty( $result['confirm_token'] ) ) {
			$result = $this->run_ability( 'update-option', $input + array( 'confirm_token' => $result['confirm_token'] ) );
		}
		$this->assertNotWPError( $result );
		$this->assertSame( 'After', get_option( 'blogdescription' ) );
		$entry = $this->newest_entry();

		Saddle_Capabilities::set_tier( 'read' );
		$plan = $this->preview( array( $entry ) );
		$this->assertSame( 1, $plan['ready'], 'The owner may undo a setting change.' );

		$this->assertSame( 200, $this->undo( array( $entry ), $plan['confirm_token'] )->get_status() );
		$this->assertSame( 'Before', get_option( 'blogdescription' ) );
	}

	public function test_a_plugin_update_is_refused_with_the_reason() {
		Saddle_Log::record_action( 'update-plugin', 'akismet/akismet.php', 'Queued an update.' );

		$plan = $this->preview( array( $this->newest_entry() ) );

		$this->assertSame( 0, $plan['ready'] );
		$this->assertStringContainsString( 'updates can’t be undone', $plan['entries'][0]['reasons'][0] );
	}

	/* -------- the owner's step in the log -------- */

	public function test_the_undo_is_logged_as_the_owners_step_and_can_be_undone() {
		list( $id, $entry ) = $this->app_retitles();
		$token              = $this->preview( array( $entry ) )['confirm_token'];
		$this->undo( array( $entry ), $token );

		$feed = Saddle_Log::query( 1, 1 )['entries'][0];
		$this->assertSame( 'undo-changes', $feed['action'] );
		$this->assertSame( (string) $entry, $feed['target'] );
		$this->assertSame( 'executed', $feed['type'] );
		$this->assertSame( get_userdata( $this->admin )->user_login, $feed['user'] );
		$this->assertSame( '', $feed['app'], 'The owner acted, not an app.' );
		$this->assertStringStartsWith( 'Undid this change: ', $feed['summary'] );
		$this->assertSame( 'undone', Saddle_Undo::availability( $entry ) );

		// The owner's undo has its own journal: undoing it puts the app's edit back.
		$own   = $this->newest_entry();
		$token = $this->preview( array( $own ) )['confirm_token'];
		$this->assertSame( 200, $this->undo( array( $own ), $token )->get_status() );

		$this->assertSame( 'After', get_post( $id )->post_title );
		$this->assertSame( 'available', Saddle_Undo::availability( $entry ), 'Redo clears the undone mark.' );
	}

	public function test_several_entries_log_one_line() {
		list( , $a ) = $this->app_retitles( 'A before', 'A after' );
		list( , $b ) = $this->app_retitles( 'B before', 'B after' );

		$token  = $this->preview( array( $a, $b ) )['confirm_token'];
		$result = $this->undo( array( $b, $a ), $token )->get_data();

		$this->assertSame( 2, $result['undone'] );
		$this->assertSame( 'Undid 2 changes.', Saddle_Log::query( 1, 1 )['entries'][0]['summary'] );
	}
}
