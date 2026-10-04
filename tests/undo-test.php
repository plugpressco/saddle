<?php
/**
 * saddle/undo-changes and the change journal behind it (#181).
 *
 * What must hold: every write tool leaves a journal on its log entry; a
 * create → edit → trash sequence undoes step by step and as a set; a later
 * edit by someone else makes the entry skip with a reason instead of being
 * overwritten; the undo is gated, logged, and itself undoable; site-wide
 * settings need the admin tier; what can't come back (a permanent delete, an
 * update) says so.
 *
 * @package Saddle
 */

class Saddle_Undo_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	/** Run a gated ability through preview and confirm. */
	private function confirmed( $name, array $input ) {
		$preview = $this->run_ability( $name, $input );
		$this->assertIsArray( $preview );
		$this->assertTrue( $preview['requires_confirmation'], 'Expected a preview first.' );
		return $this->run_ability( $name, $input + array( 'confirm_token' => $preview['confirm_token'] ) );
	}

	/** The newest log entry ids, newest first. */
	private function entry_ids( $count ) {
		$changes = $this->run_ability( 'recall-changes', array( 'limit' => $count ) );
		return wp_list_pluck( $changes['changes'], 'id' );
	}

	private function undo( array $ids ) {
		return $this->confirmed( 'undo-changes', array( 'entries' => $ids ) );
	}

	/** create-post → update-post → delete-post (trash); returns [post id, entry ids newest first]. */
	private function create_edit_trash() {
		$created = $this->run_ability(
			'create-post',
			array(
				'title'   => 'One',
				'content' => '<!-- wp:paragraph --><p>First words.</p><!-- /wp:paragraph -->',
				'status'  => 'publish',
			)
		);
		$this->assertNotWPError( $created );
		$id = (int) $created['id'];

		$this->assertNotWPError(
			$this->run_ability(
				'update-post',
				array(
					'id'      => $id,
					'title'   => 'Two',
					'content' => '<!-- wp:paragraph --><p>Second words.</p><!-- /wp:paragraph -->',
				)
			)
		);
		$this->assertNotWPError( $this->confirmed( 'delete-post', array( 'id' => $id ) ) );
		$this->assertSame( 'trash', get_post_status( $id ) );

		return array( $id, $this->entry_ids( 3 ) );
	}

	/* -------- the sequence -------- */

	/**
	 * Found in the 1.5.0 release QA: undoing a trash listed WordPress's own
	 * bookkeeping ("Restore the _wp_trash_meta_status field…") to the app and
	 * on Needs your OK. It is still restored, just not listed.
	 */
	public function test_undoing_a_trash_lists_no_wordpress_bookkeeping() {
		list( $id, $entries ) = $this->create_edit_trash();

		$preview = $this->run_ability( 'undo-changes', array( 'entries' => array( $entries[0] ) ) );
		$steps   = implode( "\n", $preview['preview']['entries'][0]['steps'] );
		$this->assertNotSame( '', $steps );
		$this->assertStringNotContainsString( '_wp_', $steps );

		$this->undo( array( $entries[0] ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_wp_trash_meta_status', true ), 'The bookkeeping is still restored.' );
	}

	public function test_create_edit_trash_undoes_step_by_step() {
		list( $id, $entries ) = $this->create_edit_trash();

		$changes = $this->run_ability( 'recall-changes', array( 'limit' => 3 ) );
		$this->assertSame( array( 'available', 'available', 'available' ), wp_list_pluck( $changes['changes'], 'undo' ) );

		// Newest first: the trash comes back as a published post titled Two.
		$result = $this->undo( array( $entries[0] ) );
		$this->assertSame( 1, $result['undone'] );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( 'Two', get_post( $id )->post_title );

		// Then the edit: the first title and words.
		$this->undo( array( $entries[1] ) );
		$post = get_post( $id );
		$this->assertSame( 'One', $post->post_title );
		$this->assertStringContainsString( 'First words.', $post->post_content );

		// Then the creation: the post goes to the trash, never deleted.
		$this->undo( array( $entries[2] ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertNotNull( get_post( $id ) );
	}

	public function test_create_edit_trash_undoes_as_a_set() {
		list( $id, $entries ) = $this->create_edit_trash();

		// Any order in; newest first out.
		$result = $this->undo( array_reverse( $entries ) );

		$this->assertSame( 3, $result['undone'] );
		$this->assertSame( $entries, wp_list_pluck( $result['entries'], 'id' ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( 'One', get_post( $id )->post_title );
	}

	/* -------- conflicts -------- */

	public function test_a_later_human_edit_is_skipped_with_a_reason() {
		$id = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$this->run_ability(
			'update-post',
			array(
				'id'    => $id,
				'title' => 'By the agent',
			)
		);
		$entry = $this->entry_ids( 1 );

		// Someone edits the post in wp-admin afterwards.
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'By a person',
			)
		);

		$result = $this->run_ability( 'undo-changes', array( 'entries' => $entry ) );

		$this->assertSame( 0, $result['undone'] );
		$this->assertSame( 'skipped', $result['entries'][0]['status'] );
		$this->assertStringContainsString( 'changed since', implode( ' ', $result['entries'][0]['reasons'] ) );
		$this->assertSame( 'By a person', get_post( $id )->post_title, 'The later edit must survive.' );
	}

	/* -------- the gate -------- */

	public function test_preview_changes_nothing_and_a_token_works_once() {
		$id = self::factory()->post->create( array( 'post_title' => 'Before' ) );
		$this->run_ability(
			'update-post',
			array(
				'id'    => $id,
				'title' => 'After',
			)
		);
		$entry = $this->entry_ids( 1 );

		$preview = $this->run_ability( 'undo-changes', array( 'entries' => $entry ) );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertSame( 'ready', $preview['preview']['entries'][0]['status'] );
		$this->assertNotEmpty( $preview['preview']['entries'][0]['steps'] );
		$this->assertSame( 'After', get_post( $id )->post_title, 'A preview must not change anything.' );

		$input = array(
			'entries'       => $entry,
			'confirm_token' => $preview['confirm_token'],
		);
		$this->assertSame( 1, $this->run_ability( 'undo-changes', $input )['undone'] );
		$this->assertSame( 'Before', get_post( $id )->post_title );

		$this->assertWPError( $this->run_ability( 'undo-changes', $input ), 'A used token must be refused.' );
	}

	public function test_undo_is_refused_at_the_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$result = $this->run_ability( 'undo-changes', array( 'entries' => array( 1 ) ) );

		$this->assertWPError( $result );
	}

	/* -------- redo -------- */

	public function test_the_undo_is_logged_and_can_itself_be_undone() {
		$id = self::factory()->post->create( array( 'post_title' => 'Before' ) );
		$this->run_ability(
			'update-post',
			array(
				'id'    => $id,
				'title' => 'After',
			)
		);
		$original = $this->entry_ids( 1 );
		$this->undo( $original );

		$undo_entry = $this->entry_ids( 1 );
		$this->assertNotSame( $original, $undo_entry );
		$this->assertSame( 'undone', Saddle_Undo::availability( $original[0] ) );

		$this->undo( $undo_entry );

		$this->assertSame( 'After', get_post( $id )->post_title );
		$this->assertSame( 'available', Saddle_Undo::availability( $original[0] ), 'Redo clears the undone mark.' );
	}

	/* -------- per kind of change -------- */

	public function test_a_block_edit_is_restored() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Hand-written intro.</p><!-- /wp:paragraph -->',
			)
		);
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'    => 'core/heading',
						'content' => 'Agent headline',
					),
				),
			)
		);
		$this->assertStringContainsString( 'Agent headline', get_post( $id )->post_content );

		$this->undo( $this->entry_ids( 1 ) );

		$this->assertSame( '<!-- wp:paragraph --><p>Hand-written intro.</p><!-- /wp:paragraph -->', get_post( $id )->post_content );
	}

	public function test_media_alt_text_is_restored() {
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Old alt' );

		$this->run_ability(
			'update-media',
			array(
				'id'  => $id,
				'alt' => 'New alt',
			)
		);
		$this->assertSame( 'New alt', get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		$this->undo( $this->entry_ids( 1 ) );

		$this->assertSame( 'Old alt', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	public function test_post_meta_is_restored() {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'subtitle', 'Old' );

		$this->run_ability(
			'update-post',
			array(
				'id'   => $id,
				'meta' => array(
					'subtitle' => 'New',
					'added'    => 'x',
				),
			)
		);
		$this->assertSame( 'New', get_post_meta( $id, 'subtitle', true ) );

		$this->undo( $this->entry_ids( 1 ) );

		$this->assertSame( 'Old', get_post_meta( $id, 'subtitle', true ) );
		$this->assertSame( array(), get_post_meta( $id, 'added', false ), 'A field the change added is removed again.' );
	}

	public function test_categories_are_restored_and_a_created_tag_is_removed() {
		$old = self::factory()->category->create( array( 'name' => 'Old' ) );
		$new = self::factory()->category->create( array( 'name' => 'New' ) );
		$id  = self::factory()->post->create( array( 'post_category' => array( $old ) ) );

		$this->run_ability(
			'update-post',
			array(
				'id'           => $id,
				'category_ids' => array( $new ),
				'tags'         => array( 'brand-new-tag' ),
			)
		);
		$this->assertTrue( (bool) term_exists( 'brand-new-tag', 'post_tag' ) );

		$this->undo( $this->entry_ids( 1 ) );

		$this->assertSame( array( $old ), wp_get_post_categories( $id ) );
		$this->assertSame( array(), wp_get_post_tags( $id ) );
		$this->assertFalse( (bool) term_exists( 'brand-new-tag', 'post_tag' ), 'A tag the change created, now unused, is deleted.' );
	}

	public function test_an_option_needs_the_admin_tier_to_undo() {
		Saddle_Capabilities::set_tier( 'admin' );
		update_option( 'blogdescription', 'Old tagline' );
		$this->confirmed(
			'update-option',
			array(
				'name'  => 'blogdescription',
				'value' => 'New tagline',
			)
		);
		$entry = $this->entry_ids( 1 );

		Saddle_Capabilities::set_tier( 'write' );
		$skipped = $this->run_ability( 'undo-changes', array( 'entries' => $entry ) );
		$this->assertSame( 'skipped', $skipped['entries'][0]['status'] );
		$this->assertStringContainsString( 'admin level', implode( ' ', $skipped['entries'][0]['reasons'] ) );

		Saddle_Capabilities::set_tier( 'admin' );
		$this->undo( $entry );
		$this->assertSame( 'Old tagline', get_option( 'blogdescription' ) );
	}

	/* -------- what can't come back -------- */

	public function test_a_permanent_delete_is_skipped_with_a_reason() {
		$id = self::factory()->post->create( array( 'post_title' => 'Gone' ) );
		$this->confirmed(
			'delete-post',
			array(
				'id'    => $id,
				'force' => true,
			)
		);

		$result = $this->run_ability( 'undo-changes', array( 'entries' => $this->entry_ids( 1 ) ) );

		$this->assertSame( 'skipped', $result['entries'][0]['status'] );
		$this->assertStringContainsString( 'permanently deleted', implode( ' ', $result['entries'][0]['reasons'] ) );
	}

	public function test_an_entry_with_nothing_recorded_says_so() {
		Saddle_Log::record_action( 'update-plugin', 'akismet/akismet.php', 'Queued an update.' );
		Saddle_Log::record_action( 'flush-cache', 'object-cache', 'Flushed the cache.' );
		$entries = $this->entry_ids( 2 );

		$this->assertSame( 'not-recorded', Saddle_Undo::availability( $entries[0] ) );

		$result  = $this->run_ability( 'undo-changes', array( 'entries' => $entries ) );
		$reasons = wp_list_pluck( $result['entries'], 'reasons', 'action' );

		$this->assertStringContainsString( 'Nothing was recorded', $reasons['flush-cache'][0] );
		$this->assertStringContainsString( 'updates can’t be undone', $reasons['update-plugin'][0] );
	}

	/* -------- the journal itself -------- */

	public function test_a_write_outside_a_saddle_tool_is_never_journaled() {
		$id = self::factory()->post->create( array( 'post_title' => 'Quiet' ) );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Still quiet',
			)
		);

		$this->assertNull( Saddle_Journal::take() );
	}
}
