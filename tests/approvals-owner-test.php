<?php
/**
 * "Needs your OK": previews bound to the asking app, pending approvals the
 * owner decides on the Dashboard, and the gate honouring that decision.
 *
 * @package Saddle
 */

class Saddle_Approvals_Owner_Test extends WP_UnitTestCase {

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
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function key( $label = 'Claude' ) {
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . $label ) );

		return $item['uuid'];
	}

	private function as_key( $uuid ) {
		$GLOBALS['wp_rest_application_password_uuid'] = $uuid;
	}

	private function args( &$calls, array $confirm = array() ) {
		$calls = 0;

		return array(
			'action'  => 'publish_post',
			'target'  => '7',
			'summary' => 'Publish “Spring sale”',
			'preview' => array(
				'before' => array( 'status' => 'draft' ),
				'after'  => array( 'status' => 'publish' ),
			),
			'input'   => $confirm,
			'execute' => function () use ( &$calls ) {
				++$calls;
				return array( 'executed' => true );
			},
		);
	}

	private function pending() {
		unset( $GLOBALS['wp_rest_application_password_uuid'] ); // The owner is in the browser.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/approvals' ) );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data()['approvals'];
	}

	private function decide( $id, $decision ) {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$request = new WP_REST_Request( 'POST', '/saddle/v1/approvals/' . $id );
		$request->set_param( 'decision', $decision );

		return rest_do_request( $request );
	}

	public function test_key_b_cannot_confirm_key_a_preview() {
		$a = $this->key( 'Claude' );
		$b = $this->key( 'Cursor' );

		$this->as_key( $a );
		$token = Saddle_Approval::gate( $this->args( $calls ) )['confirm_token'];

		$this->as_key( $b );
		$result = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_connection_mismatch', $result->get_error_code() );
		$this->assertSame( 0, $calls );

		// Burned: even the right app cannot use it now.
		$this->as_key( $a );
		$again = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );
		$this->assertSame( 'saddle_invalid_token', $again->get_error_code() );
	}

	public function test_browser_session_and_key_do_not_share_tokens() {
		$a = $this->key();

		$this->as_key( $a );
		$token = Saddle_Approval::gate( $this->args( $calls ) )['confirm_token'];
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$result = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );
		$this->assertSame( 'saddle_token_connection_mismatch', $result->get_error_code() );

		$token = Saddle_Approval::gate( $this->args( $calls ) )['confirm_token'];
		$this->as_key( $a );
		$result = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );
		$this->assertSame( 'saddle_token_connection_mismatch', $result->get_error_code() );
	}

	public function test_same_app_confirms_as_before() {
		$a = $this->key();
		$this->as_key( $a );
		$token  = Saddle_Approval::gate( $this->args( $calls ) )['confirm_token'];
		$result = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );

		$this->assertSame( array( 'executed' => true ), $result );
		$this->assertSame( 1, $calls );
	}

	public function test_preview_tells_the_agent_about_the_dashboard() {
		$reply = Saddle_Approval::gate( $this->args( $calls ) );

		$this->assertStringContainsString( 'The owner can also approve this on their Saddle Dashboard.', $reply['instructions'] );
	}

	public function test_pending_approval_is_recorded_and_listed() {
		$a = $this->key( 'Claude' );
		$this->as_key( $a );
		Saddle_Approval::gate( $this->args( $calls ) );

		$list = $this->pending();
		$this->assertCount( 1, $list );
		$row = $list[0];
		$this->assertSame( 'Claude', $row['app'] );
		$this->assertSame( 'key:' . $a, $row['connection'] );
		$this->assertSame( 'publish_post', $row['tool'] );
		$this->assertSame( '7', $row['target'] );
		$this->assertSame( 'Publish “Spring sale”', $row['summary'] );
		$this->assertSame( 'publish', $row['preview']['after']['status'] );
		$this->assertSame( $row['created_at'] + Saddle_Approval::TOKEN_TTL, $row['expires_at'] );
		$this->assertEqualsCanonicalizing( array( 'id', 'app', 'connection', 'tool', 'target', 'summary', 'preview', 'created_at', 'expires_at' ), array_keys( $row ) );
	}

	public function test_list_is_newest_first_and_admin_only() {
		$first = $this->args( $calls );
		Saddle_Approval::gate( $first );
		$second            = $this->args( $calls );
		$second['summary'] = 'Delete “Old page”';
		Saddle_Approval::gate( $second );

		$list = $this->pending();
		$this->assertSame( 'Delete “Old page”', $list[0]['summary'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/saddle/v1/approvals' ) )->get_status() );
		$this->assertSame( 403, $this->decide( $list[0]['id'], 'approve' )->get_status() );
	}

	public function test_approve_then_confirm_executes_once() {
		$token = Saddle_Approval::gate( $this->args( $calls ) )['confirm_token'];
		$id    = $this->pending()[0]['id'];

		$response = $this->decide( $id, 'approve' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'id'       => $id,
				'decision' => 'approve',
			),
			$response->get_data()
		);
		$this->assertSame( array(), $this->pending(), 'A decided request leaves the list.' );
		$this->assertSame( 'approved', get_post_meta( $id, '_saddle_decision', true ) );
		$this->assertSame( $this->admin, (int) get_post_meta( $id, '_saddle_decided_by', true ) );
		$this->assertNotEmpty( get_post_meta( $id, '_saddle_decided_at', true ) );

		$result = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );
		$this->assertSame( array( 'executed' => true ), $result );
		$this->assertSame( 1, $calls );

		$again = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );
		$this->assertWPError( $again );
		$this->assertSame( 0, $calls, 'The second confirm must not execute.' );
	}

	public function test_reject_refuses_burns_the_token_and_logs() {
		$token = Saddle_Approval::gate( $this->args( $calls ) )['confirm_token'];
		$id    = $this->pending()[0]['id'];

		$this->assertSame( 200, $this->decide( $id, 'reject' )->get_status() );

		$result = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_rejected_by_owner', $result->get_error_code() );
		$this->assertSame( 'The site owner said no to this change. Don’t retry it; ask them what they want instead.', $result->get_error_message() );
		$this->assertSame( 0, $calls );

		$again = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );
		$this->assertSame( 'saddle_invalid_token', $again->get_error_code() );

		$log = Saddle_Log::query( 10, 1 );
		$this->assertStringContainsString( 'Owner rejected: Publish “Spring sale”', $log['entries'][0]['summary'] );
	}

	public function test_approval_is_logged_with_the_app_name() {
		$this->as_key( $this->key( 'Claude' ) );
		Saddle_Approval::gate( $this->args( $calls ) );
		$this->decide( $this->pending()[0]['id'], 'approve' );

		$log = Saddle_Log::query( 10, 1 );
		$this->assertSame( 'Owner approved: Publish “Spring sale” (Claude)', $log['entries'][0]['summary'] );
	}

	public function test_undecided_agent_confirm_still_works() {
		$token  = Saddle_Approval::gate( $this->args( $calls ) )['confirm_token'];
		$result = Saddle_Approval::gate( $this->args( $calls, array( 'confirm_token' => $token ) ) );

		$this->assertSame( array( 'executed' => true ), $result );
	}

	public function test_expired_request_is_not_listed_or_decidable() {
		$token = Saddle_Approval::gate( $this->args( $calls ) )['confirm_token'];
		$id    = $this->pending()[0]['id'];
		update_post_meta( $id, '_saddle_expires', time() - 5 );

		$this->assertSame( array(), $this->pending() );
		$this->assertSame( 410, $this->decide( $id, 'approve' )->get_status() );
		unset( $token );
	}

	public function test_decide_validates_input() {
		Saddle_Approval::gate( $this->args( $calls ) );
		$id = $this->pending()[0]['id'];

		$this->assertSame( 400, $this->decide( $id, 'maybe' )->get_status() );
		$this->assertSame( 404, $this->decide( 999999, 'approve' )->get_status() );

		$this->assertSame( 200, $this->decide( $id, 'approve' )->get_status() );
		$this->assertSame( 409, $this->decide( $id, 'reject' )->get_status() );
	}
}
