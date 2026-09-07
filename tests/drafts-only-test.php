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
}
