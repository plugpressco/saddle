<?php
/**
 * Per-object authorization on the read surface.
 *
 * The read tier's capability is `read`, which every logged-in user holds —
 * including a Subscriber, who can mint a core Application Password and reach
 * the MCP endpoint. So the permission callback proves the caller may read
 * *something*, never that they may read *this*. Every id-taking read ability
 * has to re-check its target, and every list path has to filter what it
 * enumerates.
 *
 * Each test drives the real wp_get_ability()->execute() path an MCP client
 * hits. The administrator cases are the load-bearing half: the normal Saddle
 * setup is an owner's administrator Application Password, and nothing about it
 * may change.
 *
 * @package Saddle
 */

class Saddle_Read_Authorization_Test extends WP_UnitTestCase {

	private $admin;
	private $subscriber;
	private $author;
	private $other_author;

	public function set_up() {
		parent::set_up();
		$this->admin        = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->author       = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->admin );
		// Read-tier abilities on a fresh install's default tier — the real
		// configuration, not an escalated one.
		Saddle_Capabilities::set_tier( 'read' );
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

	/**
	 * A post owned by $this->author — nobody the subscriber or the other
	 * author has any claim on.
	 *
	 * @param string $status Post status.
	 * @param string $type   Post type.
	 * @param array  $extra  Extra wp_insert_post args.
	 * @return int
	 */
	private function other_post( $status, $type = 'post', array $extra = array() ) {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => $type,
					'post_status'  => $status,
					'post_author'  => $this->author,
					'post_title'   => 'Confidential ' . $status . ' ' . $type,
					'post_content' => 'Secret body for the ' . $status . ' ' . $type . '.',
				),
				$extra
			)
		);
	}

	/**
	 * An attachment hung off a given parent, owned by the other author.
	 *
	 * Built through the post factory rather than the attachment factory so the
	 * status is explicit: `inherit` is the whole mechanism under test.
	 *
	 * @param int $parent Parent post ID, or 0 for an unattached upload.
	 * @return int
	 */
	private function attachment_on( $parent ) {
		return self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $parent,
				'post_author'    => $this->author,
				'post_title'     => 'Confidential upload',
				'post_mime_type' => 'image/jpeg',
			)
		);
	}

	private function as_subscriber() {
		wp_set_current_user( $this->subscriber );
		$this->assertTrue( current_user_can( 'read' ), 'Precondition: a subscriber holds the read-tier capability.' );
		$this->assertFalse( current_user_can( 'edit_posts' ), 'Precondition: a subscriber cannot edit posts.' );
	}

	private function ids( array $result ) {
		return wp_list_pluck( $result['items'], 'id' );
	}

	/* -------- get-media — the ability the reviewer cited -------- */

	public function test_subscriber_cannot_read_media_attached_to_another_authors_draft() {
		$attachment = $this->attachment_on( $this->other_post( 'draft' ) );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-media' )->execute( array( 'id' => $attachment ) );

		$this->assertWPError( $result, 'get-media must not hand an unpublished attachment to a subscriber.' );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_subscriber_cannot_read_media_attached_to_another_authors_private_post() {
		$attachment = $this->attachment_on( $this->other_post( 'private' ) );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-media' )->execute( array( 'id' => $attachment ) );

		$this->assertWPError( $result, 'get-media must not hand a private post\'s attachment to a subscriber.' );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_subscriber_can_still_read_published_media() {
		$attachment = $this->attachment_on( $this->other_post( 'publish' ) );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-media' )->execute( array( 'id' => $attachment ) );

		$this->assertIsArray( $result, 'A published attachment stays readable — this is public content.' );
		$this->assertSame( $attachment, $result['id'] );
	}

	public function test_subscriber_can_still_read_an_unattached_upload() {
		$attachment = $this->attachment_on( 0 );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-media' )->execute( array( 'id' => $attachment ) );

		$this->assertIsArray( $result, 'core treats a parentless inherit attachment as published; so must we.' );
	}

	public function test_administrator_reads_any_media() {
		$attachment = $this->attachment_on( $this->other_post( 'draft' ) );

		$result = $this->ability( 'saddle/get-media' )->execute( array( 'id' => $attachment ) );

		$this->assertIsArray( $result, 'The owner\'s administrator credential must be unaffected.' );
		$this->assertSame( $attachment, $result['id'] );
	}

	/**
	 * Pins the core behaviour get-media leans on: read_post against an
	 * attachment resolves its status through get_post_status(), which follows
	 * post_parent for `inherit`. If a core release changes this, it should
	 * surface here rather than in a support email.
	 */
	public function test_core_maps_read_post_on_an_attachment_through_its_parent() {
		$on_draft     = $this->attachment_on( $this->other_post( 'draft' ) );
		$on_private   = $this->attachment_on( $this->other_post( 'private' ) );
		$on_published = $this->attachment_on( $this->other_post( 'publish' ) );
		$unattached   = $this->attachment_on( 0 );

		wp_set_current_user( $this->subscriber );

		$this->assertFalse( current_user_can( 'read_post', $on_draft ), 'A draft parent must make its attachment unreadable.' );
		$this->assertFalse( current_user_can( 'read_post', $on_private ), 'A private parent must make its attachment unreadable.' );
		$this->assertTrue( current_user_can( 'read_post', $on_published ), 'A published parent leaves its attachment readable.' );
		$this->assertTrue( current_user_can( 'read_post', $unattached ), 'A parentless inherit attachment is assumed published.' );
	}

	/* -------- get-post / get-page -------- */

	public function test_subscriber_cannot_read_another_authors_draft_post() {
		$id = $this->other_post( 'draft' );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-post' )->execute( array( 'id' => $id ) );

		$this->assertWPError( $result, 'get-post must not return an unpublished post to a subscriber.' );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_subscriber_cannot_read_another_authors_private_post() {
		$id = $this->other_post( 'private' );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-post' )->execute( array( 'id' => $id ) );

		$this->assertWPError( $result, 'get-post must not return a private post to a subscriber.' );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_subscriber_cannot_read_another_authors_private_page() {
		$id = $this->other_post( 'private', 'page' );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-page' )->execute( array( 'id' => $id ) );

		$this->assertWPError( $result, 'get-page must not return a private page to a subscriber.' );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_subscriber_can_still_read_a_published_post() {
		$id = $this->other_post( 'publish' );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-post' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result, 'Published content stays readable at the read tier.' );
		$this->assertSame( $id, $result['id'] );
	}

	public function test_an_author_can_still_read_their_own_draft() {
		$id = $this->other_post( 'draft' );
		wp_set_current_user( $this->author );

		$result = $this->ability( 'saddle/get-post' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result, 'The fix must not lock an author out of their own work.' );
		$this->assertSame( $id, $result['id'] );
	}

	public function test_administrator_reads_any_post() {
		$id = $this->other_post( 'draft' );

		$result = $this->ability( 'saddle/get-post' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result, 'The owner\'s administrator credential must be unaffected.' );
		$this->assertSame( $id, $result['id'] );
	}

	/* -------- password-protected content -------- */

	public function test_subscriber_cannot_read_a_password_protected_post() {
		// read_post is TRUE here — core's map_meta_cap never consults
		// post_password — so this is exactly the case a bare read_post check
		// would wave through.
		$id = $this->other_post( 'publish', 'post', array( 'post_password' => 'hunter2' ) );
		$this->as_subscriber();
		$this->assertTrue( current_user_can( 'read_post', $id ), 'Precondition: core says read_post is allowed regardless of the password.' );

		$result = $this->ability( 'saddle/get-post' )->execute( array( 'id' => $id ) );

		$this->assertWPError( $result, 'A password-protected post must not be handed over in full.' );
		$this->assertSame(
			'saddle_password_protected',
			$result->get_error_code(),
			'Its own code, not the generic 403 — the agent has to be able to tell these apart.'
		);
	}

	public function test_administrator_reads_a_password_protected_post() {
		$id = $this->other_post( 'publish', 'post', array( 'post_password' => 'hunter2' ) );

		$result = $this->ability( 'saddle/get-post' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result, 'Someone who can edit the post can read behind its password.' );
		$this->assertSame( $id, $result['id'] );
	}

	/* -------- list-post-revisions -------- */

	public function test_subscriber_cannot_list_revisions_of_another_authors_post() {
		$id = $this->other_post( 'publish' );
		wp_update_post( array( 'ID' => $id, 'post_content' => 'A second draft of the body.' ) );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/list-post-revisions' )->execute( array( 'id' => $id ) );

		$this->assertWPError( $result, 'Revisions are edit-context data; core requires edit_post on the parent.' );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_the_posts_own_author_can_list_its_revisions() {
		$id = $this->other_post( 'publish' );
		wp_update_post( array( 'ID' => $id, 'post_content' => 'A second draft of the body.' ) );
		wp_set_current_user( $this->author );

		$result = $this->ability( 'saddle/list-post-revisions' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result, 'The author of the post can edit it, so they can see its history.' );
		$this->assertSame( $id, $result['post_id'] );
	}

	/* -------- list-posts / list-pages -------- */

	public function test_subscriber_listing_posts_sees_only_published_ones() {
		$draft     = $this->other_post( 'draft' );
		$private   = $this->other_post( 'private' );
		$published = $this->other_post( 'publish' );
		$this->as_subscriber();

		$ids = $this->ids( $this->ability( 'saddle/list-posts' )->execute( array() ) );

		$this->assertContains( $published, $ids, 'Published posts stay listable.' );
		$this->assertNotContains( $draft, $ids, 'A subscriber must not enumerate another author\'s draft.' );
		$this->assertNotContains( $private, $ids, 'A subscriber must not enumerate a private post.' );
	}

	public function test_subscriber_asking_for_drafts_is_refused_with_a_reason() {
		$this->other_post( 'draft' );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/list-posts' )->execute( array( 'status' => 'draft' ) );

		$this->assertWPError( $result, 'An explicit forbidden status is refused, not silently emptied.' );
		$this->assertSame( 'saddle_forbidden_status', $result->get_error_code() );
	}

	public function test_subscriber_listing_pages_sees_only_published_ones() {
		$draft     = $this->other_post( 'draft', 'page' );
		$published = $this->other_post( 'publish', 'page' );
		$this->as_subscriber();

		$ids = $this->ids( $this->ability( 'saddle/list-pages' )->execute( array() ) );

		$this->assertContains( $published, $ids );
		$this->assertNotContains( $draft, $ids, 'A subscriber must not enumerate another author\'s draft page.' );
	}

	/**
	 * The case that proves BOTH controls are needed, and the reason the status
	 * gate alone is not enough. An Author holds edit_posts, so they legitimately
	 * pass the status gate and may ask for drafts — and must still not receive
	 * someone else's.
	 */
	public function test_an_author_may_query_drafts_but_not_receive_another_authors() {
		$mine   = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->other_author,
				'post_title'  => 'My own draft',
			)
		);
		$theirs = $this->other_post( 'draft' );
		wp_set_current_user( $this->other_author );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'Precondition: an author passes the status gate.' );

		$result = $this->ability( 'saddle/list-posts' )->execute( array( 'status' => 'draft' ) );

		$this->assertNotWPError( $result, 'An author is allowed to ask for drafts.' );
		$ids = $this->ids( $result );
		$this->assertContains( $mine, $ids, 'Their own draft comes back.' );
		$this->assertNotContains( $theirs, $ids, 'Another author\'s does not — the status gate cannot catch this one.' );
	}

	public function test_administrator_still_lists_every_status() {
		$draft   = $this->other_post( 'draft' );
		$private = $this->other_post( 'private' );

		$ids = $this->ids( $this->ability( 'saddle/list-posts' )->execute( array() ) );

		$this->assertContains( $draft, $ids, 'The owner\'s credential must still see everything.' );
		$this->assertContains( $private, $ids );
	}

	public function test_administrator_can_still_filter_by_draft() {
		$draft = $this->other_post( 'draft' );
		$this->other_post( 'publish' );

		$ids = $this->ids( $this->ability( 'saddle/list-posts' )->execute( array( 'status' => 'draft' ) ) );

		$this->assertSame( array( $draft ), $ids, 'The status filter must keep working for someone allowed to use it.' );
	}

	/* -------- search-content -------- */

	public function test_subscriber_search_does_not_surface_drafts() {
		$draft     = $this->other_post( 'draft', 'post', array( 'post_title' => 'Zebra memorandum' ) );
		$published = $this->other_post( 'publish', 'post', array( 'post_title' => 'Zebra announcement' ) );
		$this->as_subscriber();

		$ids = $this->ids( $this->ability( 'saddle/search-content' )->execute( array( 'query' => 'Zebra' ) ) );

		$this->assertContains( $published, $ids );
		$this->assertNotContains( $draft, $ids, 'Search is an enumeration path like any other.' );
	}

	public function test_administrator_search_still_surfaces_drafts() {
		$draft = $this->other_post( 'draft', 'post', array( 'post_title' => 'Zebra memorandum' ) );

		$ids = $this->ids( $this->ability( 'saddle/search-content' )->execute( array( 'query' => 'Zebra' ) ) );

		$this->assertContains( $draft, $ids, 'Finding your own unfinished work is the point of the tool.' );
	}

	/* -------- list-media -------- */

	public function test_subscriber_listing_media_does_not_surface_unpublished_attachments() {
		$hidden  = $this->attachment_on( $this->other_post( 'private' ) );
		$visible = $this->attachment_on( $this->other_post( 'publish' ) );
		$this->as_subscriber();

		$ids = $this->ids( $this->ability( 'saddle/list-media' )->execute( array() ) );

		$this->assertContains( $visible, $ids );
		$this->assertNotContains( $hidden, $ids, 'An attachment inherits its parent\'s privacy.' );
	}

	/**
	 * Pins the one place this deliberately diverges from a naive expectation.
	 *
	 * Rows are dropped after the query, so `total` still counts them — the same
	 * inconsistency core lives with in WP_REST_Posts_Controller::get_items().
	 * Recounting would need a second unbounded query. What Saddle adds is the
	 * `note`, because an agent handed a short page with no explanation retries
	 * it. If someone later "fixes" total, this test says why not to.
	 */
	public function test_hidden_rows_are_counted_in_total_and_narrated() {
		$this->attachment_on( $this->other_post( 'private' ) );
		$this->attachment_on( $this->other_post( 'publish' ) );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/list-media' )->execute( array() );

		$this->assertCount( 1, $result['items'], 'Only the readable attachment comes back.' );
		$this->assertSame( 2, $result['total'], 'total stays the query\'s count, as core does.' );
		$this->assertArrayHasKey( 'note', $result, 'A short page must say why it is short.' );
	}

	public function test_a_full_page_carries_no_note() {
		$this->attachment_on( $this->other_post( 'publish' ) );

		$result = $this->ability( 'saddle/list-media' )->execute( array() );

		$this->assertArrayNotHasKey(
			'note',
			$result,
			'An administrator drops no rows, so the response shape is byte-identical to before this change.'
		);
	}

	/* -------- the shared guard, at every caller -------- */

	/**
	 * The contract test. Every read path that reaches a specific post refuses
	 * the same unreadable post with the same code — which is what stops a
	 * fourth hand-inlined copy of this check appearing later and drifting.
	 */
	public function test_every_single_object_read_path_refuses_the_same_post_alike() {
		$id = $this->other_post( 'draft' );
		$this->as_subscriber();

		$cases = array(
			'saddle/get-post'    => array( 'id' => $id ),
			'saddle/get-blocks'  => array( 'post_id' => $id ),
			'saddle/lint-page'   => array( 'post_id' => $id ),
			'saddle/render-node' => array( 'post_id' => $id, 'address' => '0' ),
			'saddle/verify-page' => array( 'post_id' => $id ),
		);

		foreach ( $cases as $name => $args ) {
			$result = $this->ability( $name )->execute( $args );
			$this->assertWPError( $result, "{$name} must refuse a post the caller cannot read." );
			$this->assertSame( 'saddle_forbidden', $result->get_error_code(), "{$name} must refuse it for the same reason." );
		}
	}

	public function test_get_preview_url_still_needs_edit_access_for_a_draft() {
		$id = $this->other_post( 'draft' );
		$this->as_subscriber();

		$result = $this->ability( 'saddle/get-preview-url' )->execute( array( 'post_id' => $id ) );

		$this->assertWPError( $result, 'A preview of unpublished content stays behind edit_post.' );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_get_preview_url_still_works_for_the_owner() {
		$id = $this->other_post( 'draft' );

		$result = $this->ability( 'saddle/get-preview-url' )->execute( array( 'post_id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['url'] );
	}

	/* -------- the tier itself must not have moved -------- */

	/**
	 * Guards against the fix being "achieved" by promoting these abilities out
	 * of the read tier, which would break every read-only connection instead of
	 * authorizing it.
	 */
	public function test_the_repaired_abilities_are_still_read_tier_and_readonly() {
		$names = array(
			'saddle/get-post',
			'saddle/get-page',
			'saddle/get-media',
			'saddle/list-posts',
			'saddle/list-pages',
			'saddle/list-media',
			'saddle/search-content',
			'saddle/list-post-revisions',
		);

		foreach ( $names as $name ) {
			$meta = $this->ability( $name )->get_meta();
			$this->assertSame( 'read', $meta['saddle']['tier'], "{$name} must stay a read-tier tool." );
			$this->assertTrue( $meta['annotations']['readonly'], "{$name} must stay annotated read-only." );
		}
	}
}
