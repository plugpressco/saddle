<?php
/**
 * The preview transport + the saddle/get-preview-url ability.
 *
 * Pins the no-custody screenshot path (https://github.com/plugpressco/saddle/issues/25):
 * HMAC tokens are post-bound and expire, rotation never breaks a live token,
 * a valid token lets the site's own front end serve a draft to an anonymous
 * visitor, an invalid one changes nothing, and minting demands the right to
 * see the content first.
 *
 * @package Saddle
 */

class Saddle_Preview_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down() {
		delete_option( Saddle_Preview::OPTION );
		$_GET = array();
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function draft_page( $title = 'Secret draft' ) {
		return get_post(
			self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_content' => '<!-- wp:paragraph --><p>Hidden copy.</p><!-- /wp:paragraph -->',
				)
			)
		);
	}

	private function token_from( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return isset( $query[ Saddle_Preview::QUERY_ARG ] ) ? $query[ Saddle_Preview::QUERY_ARG ] : '';
	}

	/* -------- mint + verify -------- */

	public function test_mint_produces_a_bound_expiring_url() {
		$post   = $this->draft_page();
		$minted = Saddle_Preview::mint( $post );

		$this->assertSame( Saddle_Preview::TTL, $minted['expires_in'] );
		$this->assertStringContainsString( 'preview=1', $minted['url'] );
		$this->assertStringContainsString( 'page_id=' . $post->ID, $minted['url'], 'Hierarchical types resolve by page_id.' );

		$token = $this->token_from( $minted['url'] );
		$this->assertTrue( Saddle_Preview::verify( $token, $post->ID ) );
	}

	public function test_token_is_post_bound_and_garbage_fails() {
		$post  = $this->draft_page();
		$other = $this->draft_page( 'Another' );
		$token = $this->token_from( Saddle_Preview::mint( $post )['url'] );

		$this->assertFalse( Saddle_Preview::verify( $token, $other->ID ), 'A token opens ONLY the post it signs.' );
		$this->assertFalse( Saddle_Preview::verify( 'garbage', $post->ID ) );
		$this->assertFalse( Saddle_Preview::verify( '', $post->ID ) );
	}

	public function test_expired_token_fails() {
		$post  = $this->draft_page();
		$token = $this->token_from( Saddle_Preview::mint( $post )['url'] );

		// Split and rebuild with a past expiry — the signature no longer matches.
		list( , $signature ) = explode( '.', $token, 2 );
		$this->assertFalse( Saddle_Preview::verify( ( time() - 10 ) . '.' . $signature, $post->ID ) );
	}

	public function test_rotation_keeps_the_previous_secret_valid() {
		$post  = $this->draft_page();
		$token = $this->token_from( Saddle_Preview::mint( $post )['url'] );

		// Force a rotation by backdating the stored timestamp past the window.
		$stored            = get_option( Saddle_Preview::OPTION );
		$stored['rotated'] = time() - Saddle_Preview::ROTATE_AFTER - 10;
		update_option( Saddle_Preview::OPTION, $stored, false );
		Saddle_Preview::mint( $post ); // Triggers the lazy rotation.

		$this->assertNotSame( $stored['secret'], get_option( Saddle_Preview::OPTION )['secret'], 'The secret rotated.' );
		$this->assertTrue( Saddle_Preview::verify( $token, $post->ID ), 'A live token survives one rotation.' );
	}

	/* -------- serving: the site's own front end -------- */

	public function test_valid_token_serves_a_draft_to_an_anonymous_visitor() {
		$post = $this->draft_page();
		$url  = Saddle_Preview::mint( $post )['url'];

		wp_set_current_user( 0 );
		$this->go_to( $url );

		$this->assertTrue( is_singular(), 'The preview resolves to the single post.' );
		$this->assertSame( $post->ID, get_queried_object_id() );
		$this->assertSame( 'publish', get_queried_object()->post_status, 'The in-memory flip lets the front end render the draft.' );
	}

	public function test_bad_token_serves_nothing_anonymous() {
		$post = $this->draft_page();
		$url  = Saddle_Preview::mint( $post )['url'];
		$url  = preg_replace( '/saddle_preview=[^&]+/', 'saddle_preview=' . rawurlencode( ( time() + 100 ) . '.forged' ), $url );

		wp_set_current_user( 0 );
		$this->go_to( $url );

		$this->assertNotSame( $post->ID, get_queried_object_id(), 'A forged token opens nothing.' );
	}

	/* -------- the ability -------- */

	private function run_ability( array $input ) {
		$ability = wp_get_ability( 'saddle/get-preview-url' );
		$this->assertNotNull( $ability, 'saddle/get-preview-url must be registered.' );
		return $ability->execute( $input );
	}

	public function test_ability_mints_for_an_editor() {
		$post   = $this->draft_page();
		$result = $this->run_ability( array( 'post_id' => $post->ID ) );

		$this->assertNotWPError( $result );
		$this->assertSame( $post->ID, $result['id'] );
		$this->assertSame( Saddle_Preview::TTL, $result['expires_in'] );
		$this->assertTrue( Saddle_Preview::verify( $this->token_from( $result['url'] ), $post->ID ) );
	}

	public function test_ability_refuses_drafts_to_callers_without_edit_rights() {
		$post       = $this->draft_page();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$result = $this->run_ability( array( 'post_id' => $post->ID ) );
		$this->assertWPError( $result, 'A read-only viewer must not mint a window into a draft.' );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_ability_allows_published_posts_to_readers() {
		$id         = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$result = $this->run_ability( array( 'post_id' => $id ) );
		$this->assertNotWPError( $result, 'Published content is already public — minting is fine.' );
	}

	public function test_ability_is_read_tier_and_readonly() {
		$meta = wp_get_ability( 'saddle/get-preview-url' )->get_meta();
		$this->assertSame( 'read', $meta['saddle']['tier'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
	}
}
