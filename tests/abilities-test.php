<?php
/**
 * End-to-end ability tests — driving the real wp_get_ability()->execute() path
 * an MCP client hits, so tier enforcement, the approval gate, and logging are
 * proven together rather than in isolation.
 *
 * @package Saddle
 */

class Saddle_Abilities_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down() {
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		parent::tear_down();
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	private function log_total() {
		return Saddle_Log::query( 100, 1 )['total'];
	}

	/* -------- tier enforcement, end to end -------- */

	public function test_write_ability_denied_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$result = $this->ability( 'saddle/create-post' )->execute( array( 'title' => 'Should not exist' ) );

		$this->assertWPError( $result, 'A write ability must be denied while the site is read-only.' );
		$this->assertSame( 0, count( get_posts( array( 'title' => 'Should not exist', 'post_status' => 'any' ) ) ) );
	}

	public function test_write_ability_allowed_at_write_tier() {
		Saddle_Capabilities::set_tier( 'write' );

		$result = $this->ability( 'saddle/create-post' )->execute( array( 'title' => 'Hello from Saddle' ) );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'id', $result );
		$post = get_post( $result['id'] );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertSame( 'draft', $post->post_status, 'Create must default to draft.' );
	}

	/* -------- approval gate, end to end -------- */

	public function test_delete_preview_mutates_nothing() {
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create();

		$result = $this->ability( 'saddle/delete-post' )->execute( array( 'id' => $id ) );

		$this->assertTrue( $result['requires_confirmation'] );
		$this->assertNotEmpty( $result['confirm_token'] );
		$this->assertSame( 'publish', get_post( $id )->post_status, 'A preview must not change the post.' );
	}

	public function test_delete_confirm_trashes_recoverably() {
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create();

		$token  = $this->ability( 'saddle/delete-post' )->execute( array( 'id' => $id ) )['confirm_token'];
		$result = $this->ability( 'saddle/delete-post' )->execute( array( 'id' => $id, 'confirm_token' => $token ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'trash', get_post( $id )->post_status, 'Without force, the post must be trashed, not deleted.' );
	}

	public function test_delete_force_removes_permanently() {
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create();

		$token = $this->ability( 'saddle/delete-post' )->execute( array( 'id' => $id, 'force' => true ) )['confirm_token'];
		$this->ability( 'saddle/delete-post' )->execute( array( 'id' => $id, 'force' => true, 'confirm_token' => $token ) );

		$this->assertNull( get_post( $id ), 'With force, the post must be permanently gone.' );
	}

	public function test_delete_page_trashes_recoverably() {
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$token = $this->ability( 'saddle/delete-page' )->execute( array( 'id' => $id ) )['confirm_token'];
		$this->ability( 'saddle/delete-page' )->execute( array( 'id' => $id, 'confirm_token' => $token ) );

		$this->assertSame( 'trash', get_post( $id )->post_status );
	}

	/* -------- logging, end to end -------- */

	public function test_confirmed_delete_is_logged_but_preview_is_not() {
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create();

		$before = $this->log_total();

		// Preview: no log entry.
		$token = $this->ability( 'saddle/delete-post' )->execute( array( 'id' => $id ) )['confirm_token'];
		$this->assertSame( $before, $this->log_total(), 'A preview must not be logged.' );

		// Confirm: exactly one log entry.
		$this->ability( 'saddle/delete-post' )->execute( array( 'id' => $id, 'confirm_token' => $token ) );
		$this->assertSame( $before + 1, $this->log_total(), 'A confirmed destructive action must be logged once.' );
	}

	public function test_reads_are_never_logged() {
		Saddle_Capabilities::set_tier( 'read' );
		self::factory()->post->create();

		$before = $this->log_total();
		$this->ability( 'saddle/list-posts' )->execute( array() );
		$this->assertSame( $before, $this->log_total(), 'Read operations must never be logged.' );
	}

	/* -------- custom fields (meta) -------- */

	public function test_create_post_with_meta_sets_custom_fields() {
		Saddle_Capabilities::set_tier( 'write' );

		$result = $this->ability( 'saddle/create-post' )->execute(
			array(
				'title' => 'Meta post',
				'slug'  => 'meta-post-slug',
				'meta'  => array(
					'subtitle' => 'Hello',
					'priority' => 5,
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'meta-post-slug', $result['slug'] );
		$this->assertSame( 'Hello', get_post_meta( $result['id'], 'subtitle', true ) );
		$this->assertEquals( 5, get_post_meta( $result['id'], 'priority', true ) );
		$this->assertSame( 'Hello', $result['meta']['subtitle'], 'The response detail must echo the stored meta.' );
		$this->assertArrayNotHasKey( 'meta_denied', $result );
	}

	public function test_update_page_meta_null_deletes_key() {
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $id, 'subtitle', 'Old value' );

		$result = $this->ability( 'saddle/update-page' )->execute(
			array(
				'id'   => $id,
				'meta' => array( 'subtitle' => null ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( array(), get_post_meta( $id, 'subtitle' ), 'Passing null must delete the key.' );
	}

	public function test_unregistered_protected_meta_is_denied_not_written() {
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create();

		$result = $this->ability( 'saddle/update-post' )->execute(
			array(
				'id'   => $id,
				'meta' => array(
					'_secret_internal' => 'nope',
					'visible_field'    => 'yes',
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( array(), get_post_meta( $id, '_secret_internal' ), 'An unregistered protected key must not be written, even by an admin.' );
		$this->assertSame( 'yes', get_post_meta( $id, 'visible_field', true ), 'Allowed keys in the same request must still apply.' );
		$this->assertContains( '_secret_internal', $result['meta_denied'] );
	}

	public function test_meta_string_values_are_kses_sanitized() {
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create();

		$result = $this->ability( 'saddle/update-post' )->execute(
			array(
				'id'   => $id,
				'meta' => array( 'blurb' => 'Safe <strong>bold</strong><script>alert(1)</script>' ),
			)
		);

		$this->assertNotWPError( $result );
		$stored = get_post_meta( $id, 'blurb', true );
		$this->assertStringNotContainsString( '<script>', $stored );
		$this->assertStringContainsString( '<strong>bold</strong>', $stored );
	}

	public function test_get_post_returns_meta_but_hides_protected_internals() {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'subtitle', 'Visible' );
		update_post_meta( $id, '_secret_internal', 'hidden' );

		$result = $this->ability( 'saddle/get-post' )->execute( array( 'id' => $id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Visible', $result['meta']['subtitle'] );
		$this->assertArrayNotHasKey( '_secret_internal', $result['meta'], 'Unregistered protected keys must stay hidden.' );
	}

	/* -------- block content normalization -------- */

	public function test_unclosed_block_is_normalized_to_valid_markup() {
		Saddle_Capabilities::set_tier( 'write' );

		$result = $this->ability( 'saddle/create-post' )->execute(
			array(
				'title'   => 'Block post',
				'content' => '<!-- wp:paragraph --><p>Hi</p>',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame(
			'<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			get_post( $result['id'] )->post_content,
			'An unclosed block comment must be auto-closed at write time.'
		);
	}

	public function test_plain_html_content_is_left_untouched() {
		Saddle_Capabilities::set_tier( 'write' );

		$result = $this->ability( 'saddle/create-post' )->execute(
			array(
				'title'   => 'Plain post',
				'content' => '<p>Just plain HTML.</p>',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '<p>Just plain HTML.</p>', get_post( $result['id'] )->post_content );
	}

	/* -------- per-ability disable, end to end -------- */

	public function test_individually_disabled_ability_is_denied_even_at_sufficient_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_disabled_abilities( array( 'create-post' ) );

		$result = $this->ability( 'saddle/create-post' )->execute( array( 'title' => 'Should not exist' ) );

		$this->assertWPError( $result, 'A disabled ability must be denied regardless of tier.' );
		$this->assertSame( 0, count( get_posts( array( 'title' => 'Should not exist', 'post_status' => 'any' ) ) ) );
	}

	public function test_disabling_one_ability_leaves_others_working() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_disabled_abilities( array( 'delete-post' ) );

		$result = $this->ability( 'saddle/create-post' )->execute( array( 'title' => 'Still allowed' ) );

		$this->assertNotWPError( $result, 'Disabling one ability must not affect unrelated abilities.' );
	}

	/* -------- denial logging, end to end -------- */

	public function test_denied_write_at_read_tier_is_logged_as_denied() {
		Saddle_Capabilities::set_tier( 'read' );
		$before = $this->log_total();

		$result = $this->ability( 'saddle/create-post' )->execute( array( 'title' => 'nope' ) );

		$this->assertWPError( $result );
		$this->assertSame( $before + 1, $this->log_total(), 'A blocked write attempt must be logged.' );

		$entry = Saddle_Log::query( 1, 1 )['entries'][0];
		$this->assertSame( 'denied', $entry['type'] );
		$this->assertStringContainsString( 'create-post', $entry['action'] );
	}

	public function test_allowed_read_is_not_logged() {
		Saddle_Capabilities::set_tier( 'read' );
		$before = $this->log_total();

		$this->ability( 'saddle/list-posts' )->execute( array() );

		$this->assertSame( $before, $this->log_total(), 'An allowed read must never produce a log entry.' );
	}

	public function test_repeated_denials_are_throttled_to_one_entry() {
		Saddle_Capabilities::set_tier( 'read' );
		$before = $this->log_total();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->ability( 'saddle/create-post' )->execute( array( 'title' => "try {$i}" ) );
		}

		$this->assertSame( $before + 1, $this->log_total(), 'A retry loop on a blocked tool must not flood the log.' );
	}

	public function test_anonymous_denial_is_not_logged() {
		wp_set_current_user( 0 );
		Saddle_Capabilities::set_tier( 'write' );
		$before = $this->log_total();

		$result = $this->ability( 'saddle/create-post' )->execute( array( 'title' => 'anon' ) );

		$this->assertWPError( $result );
		$this->assertSame( $before, $this->log_total(), 'Anonymous denials must not be logged (noise/flood vector).' );
	}

	public function test_paused_denial_is_logged() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_paused( true );
		$before = $this->log_total();

		$this->ability( 'saddle/list-posts' )->execute( array() );

		$this->assertSame( $before + 1, $this->log_total(), 'A call refused because Saddle is paused must be logged.' );
		$this->assertSame( 'denied', Saddle_Log::query( 1, 1 )['entries'][0]['type'] );
	}

	/* -------- pause, end to end -------- */

	public function test_paused_denies_a_read_ability_for_a_full_admin() {
		Saddle_Capabilities::set_tier( 'admin' );
		Saddle_Capabilities::set_paused( true );

		$result = $this->ability( 'saddle/list-posts' )->execute( array() );

		$this->assertWPError( $result, 'Pause must override even the highest tier for a fully-capable user.' );
	}

	public function test_resuming_restores_the_prior_tier_without_reconfiguring() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_paused( true );
		Saddle_Capabilities::set_paused( false );

		$result = $this->ability( 'saddle/create-post' )->execute( array( 'title' => 'Back after resume' ) );

		$this->assertNotWPError( $result );
	}
}
