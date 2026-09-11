<?php
/**
 * Drafts-only write policy tests — driving the real
 * wp_get_ability()->execute() path, same as abilities-test.php, so the
 * policy is proven at the point an MCP client actually hits it rather than
 * against a private helper in isolation.
 *
 * @package Saddle
 */

class Saddle_Drafts_Only_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		delete_option( Saddle_Capabilities::DRAFTS_ONLY_OPTION );
		parent::tear_down();
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	/* -------- policy off: existing behavior is unchanged -------- */

	public function test_create_post_publishes_normally_when_policy_is_off() {
		Saddle_Capabilities::set_drafts_only( false );

		$result = $this->ability( 'saddle/create-post' )->execute(
			array(
				'title'  => 'Published while off',
				'status' => 'publish',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'publish', get_post( $result['id'] )->post_status );
		$this->assertArrayNotHasKey( 'drafts_only_override', $result );
	}

	/* -------- create: silent downgrade -------- */

	public function test_create_post_with_status_publish_lands_as_draft_when_on() {
		Saddle_Capabilities::set_drafts_only( true );

		$result = $this->ability( 'saddle/create-post' )->execute(
			array(
				'title'  => 'Should stay a draft',
				'status' => 'publish',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'draft', get_post( $result['id'] )->post_status );
		$this->assertNotEmpty( $result['drafts_only_override'], 'Response must say the status was overridden.' );
	}

	public function test_create_page_with_status_publish_lands_as_draft_when_on() {
		Saddle_Capabilities::set_drafts_only( true );

		$result = $this->ability( 'saddle/create-page' )->execute(
			array(
				'title'  => 'Should stay a draft page',
				'status' => 'publish',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'draft', get_post( $result['id'] )->post_status );
		$this->assertNotEmpty( $result['drafts_only_override'] );
	}

	public function test_create_post_without_status_is_unaffected() {
		Saddle_Capabilities::set_drafts_only( true );

		$result = $this->ability( 'saddle/create-post' )->execute( array( 'title' => 'No status requested' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'draft', get_post( $result['id'] )->post_status, 'Omitted status already defaults to draft.' );
		$this->assertArrayNotHasKey( 'drafts_only_override', $result, 'Nothing was overridden — publish was never requested.' );
	}

	/* -------- update: gated instead of downgraded -------- */

	public function test_update_post_to_publish_previews_without_changing_anything_when_on() {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$result = $this->ability( 'saddle/update-post' )->execute(
			array(
				'id'     => $id,
				'status' => 'publish',
			)
		);

		$this->assertTrue( $result['requires_confirmation'] );
		$this->assertNotEmpty( $result['confirm_token'] );
		$this->assertSame( 'draft', get_post( $id )->post_status, 'A preview must not change the post.' );
	}

	public function test_update_post_to_publish_confirmed_actually_publishes() {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$token  = $this->ability( 'saddle/update-post' )->execute( array( 'id' => $id, 'status' => 'publish' ) )['confirm_token'];
		$result = $this->ability( 'saddle/update-post' )->execute(
			array(
				'id'            => $id,
				'status'        => 'publish',
				'confirm_token' => $token,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'publish', get_post( $id )->post_status, 'A confirmed publish must actually publish — drafts-only guards new content, not the approval itself.' );
	}

	public function test_update_page_to_publish_is_gated_when_on() {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		$result = $this->ability( 'saddle/update-page' )->execute(
			array(
				'id'     => $id,
				'status' => 'publish',
			)
		);

		$this->assertTrue( $result['requires_confirmation'] );
		$this->assertSame( 'draft', get_post( $id )->post_status );
	}

	public function test_update_post_to_publish_is_not_gated_when_already_published() {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = $this->ability( 'saddle/update-post' )->execute(
			array(
				'id'     => $id,
				'title'  => 'Already live, resaving status=publish',
				'status' => 'publish',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'requires_confirmation', $result, 'Nothing is being flipped to publish — it already was.' );
		$this->assertSame( 'Already live, resaving status=publish', get_post( $id )->post_title );
	}

	public function test_update_post_confirm_token_cannot_be_replayed_against_a_different_edit() {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$token = $this->ability( 'saddle/update-post' )->execute(
			array(
				'id'     => $id,
				'title'  => 'First edit',
				'status' => 'publish',
			)
		)['confirm_token'];

		// Same token, different payload (title changed) — must not confirm.
		$result = $this->ability( 'saddle/update-post' )->execute(
			array(
				'id'            => $id,
				'title'         => 'A different edit',
				'status'        => 'publish',
				'confirm_token' => $token,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'draft', get_post( $id )->post_status );
	}

	public function test_update_post_without_status_change_is_unaffected() {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$result = $this->ability( 'saddle/update-post' )->execute(
			array(
				'id'    => $id,
				'title' => 'Just a title edit',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'requires_confirmation', $result );
		$this->assertSame( 'Just a title edit', get_post( $id )->post_title );
	}
	public static function content_types() {
		return array( 'post' => array( 'post' ), 'page' => array( 'page' ) );
	}

	/** @dataProvider content_types */
	public function test_update_schema_advertises_confirmation_token( $type ) {
		$schema = $this->ability( 'saddle/update-' . $type )->get_input_schema();
		$this->assertArrayHasKey( 'confirm_token', $schema['properties'] );
	}

	/** @dataProvider content_types */
	public function test_confirmed_publish_cannot_execute_again_after_publication( $type ) {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft' ) );
		$input = array( 'id' => $id, 'status' => 'publish', 'title' => 'Approved title' );
		$ability = $this->ability( 'saddle/update-' . $type );
		$preview = $ability->execute( $input );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$input['confirm_token'] = $preview['confirm_token'];
		$this->assertNotWPError( $ability->execute( $input ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Subsequent owner edit' ) );
		$this->assertWPError( $ability->execute( $input ) );
		$this->assertSame( 'Subsequent owner edit', get_post( $id )->post_title );
	}

	/** @dataProvider content_types */
	public function test_publish_preview_shows_the_other_requested_changes( $type ) {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft' ) );
		$changes = array( 'status' => 'publish', 'title' => 'New title', 'content' => 'New body', 'meta' => array( 'campaign' => 'launch' ) );
		$result = $this->ability( 'saddle/update-' . $type )->execute( array_merge( array( 'id' => $id ), $changes ) );
		$this->assertSame( $changes, $result['preview']['changes'] );
		$this->assertNotSame( 'New title', get_post( $id )->post_title );
		$this->assertSame( '', get_post_meta( $id, 'campaign', true ) );
	}

	/** @dataProvider content_types */
	public function test_scheduling_new_content_stays_draft( $type ) {
		Saddle_Capabilities::set_drafts_only( true );
		$result = $this->ability( 'saddle/create-' . $type )->execute( array( 'title' => 'Scheduled', 'status' => 'future', 'date' => '2099-01-01 12:00:00' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'draft', get_post_status( $result['id'] ) );
		$this->assertNotEmpty( $result['drafts_only_override'] );
	}

	/** @dataProvider content_types */
	public function test_scheduling_existing_content_requires_confirmation( $type ) {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'draft' ) );
		$input = array( 'id' => $id, 'status' => 'future', 'date' => '2099-01-01 12:00:00' );
		$ability = $this->ability( 'saddle/update-' . $type );
		$preview = $ability->execute( $input );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$input['confirm_token'] = $preview['confirm_token'];
		$this->assertNotWPError( $ability->execute( $input ) );
		$this->assertSame( 'future', get_post_status( $id ) );
	}

	public function test_preferences_round_trip_and_subscriber_cannot_change_policy() {
		$get = new WP_REST_Request( 'GET', '/saddle/v1/preferences' );
		$this->assertFalse( rest_do_request( $get )->get_data()['drafts_only'] );
		$post = new WP_REST_Request( 'POST', '/saddle/v1/preferences' );
		$post->set_param( 'drafts_only', true );
		$this->assertTrue( rest_do_request( $post )->get_data()['drafts_only'] );
		$this->assertTrue( rest_do_request( $get )->get_data()['drafts_only'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post->set_param( 'drafts_only', false );
		$this->assertSame( 403, rest_do_request( $post )->get_status() );
		$this->assertTrue( Saddle_Capabilities::is_drafts_only() );
	}

	/** @dataProvider content_types */
	public function test_read_tier_and_subscriber_cannot_publish_under_policy( $type ) {
		Saddle_Capabilities::set_drafts_only( true );
		$ability = $this->ability( 'saddle/create-' . $type );
		$input = array( 'title' => 'Forbidden', 'status' => 'publish' );
		Saddle_Capabilities::set_tier( 'read' );
		$this->assertWPError( $ability->execute( $input ) );
		Saddle_Capabilities::set_tier( 'write' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $ability->execute( $input ) );
	}

	public function test_set_blocks_preserves_status_and_does_not_offer_publishing() {
		Saddle_Capabilities::set_drafts_only( true );
		$ability = $this->ability( 'saddle/set-blocks' );
		$this->assertArrayNotHasKey( 'status', $ability->get_input_schema()['properties'] );
		foreach ( array( 'draft', 'publish' ) as $status ) {
			$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => $status ) );
			$result = $ability->execute( array( 'post_id' => $id, 'nodes' => array( array( 'type' => 'core/paragraph', 'content' => 'Updated body' ) ) ) );
			$this->assertNotWPError( $result );
			$this->assertSame( $status, get_post_status( $id ) );
			$this->assertStringContainsString( 'Updated body', get_post( $id )->post_content );
		}
	}

	public function test_token_still_requires_validation_after_policy_disabled() {
		Saddle_Capabilities::set_drafts_only( true );
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$ability = $this->ability( 'saddle/update-post' );
		$input = array( 'id' => $id, 'status' => 'publish' );
		$input['confirm_token'] = $ability->execute( $input )['confirm_token'];
		Saddle_Capabilities::set_drafts_only( false );
		$this->assertNotWPError( $ability->execute( $input ) );
		$this->assertWPError( $ability->execute( $input ) );
	}

}
