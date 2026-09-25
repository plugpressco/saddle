<?php
/**
 * saddle/rank-math-* — native Rank Math integration.
 *
 * Coverage: tier enforcement, per-object capability, the robots
 * friendly<->array mapping (including read-merge-write preserving unrelated
 * and unknown flags), length warnings, empty-string-deletes semantics, and
 * the graceful "not active" error shape. Rank Math itself isn't a test
 * dependency: its storage is plain rank_math_* meta, so only the version
 * constant is stubbed (tests/bootstrap.php). Live behavior verified
 * separately on divi-dev with the real plugin.
 *
 * @package Saddle
 */

class Saddle_Rank_Math_Abilities_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		remove_filter( 'saddle_rankmath_active', '__return_false' );
		parent::tear_down();
	}

	private function post_id( array $overrides = array() ) {
		return self::factory()->post->create(
			array_merge( array( 'post_type' => 'page', 'post_title' => 'A page' ), $overrides )
		);
	}

	/* -------- registration -------- */

	public function test_registration_tiers() {
		$expect = array(
			'saddle/rank-math-check-setup'   => 'read',
			'saddle/rank-math-get-post-seo'  => 'read',
			'saddle/rank-math-edit-post-seo' => 'write',
			'saddle/rank-math-get-term-seo'  => 'read',
			'saddle/rank-math-edit-term-seo' => 'write',
		);

		foreach ( $expect as $name => $tier ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "{$name} must be registered." );
			$this->assertSame( $tier, $ability->get_meta()['saddle']['tier'], "{$name} tier" );
			$this->assertFalse( $ability->get_meta()['annotations']['destructive'], "{$name} must not be destructive." );
		}
	}

	public function test_write_abilities_denied_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertFalse( wp_get_ability( 'saddle/rank-math-edit-post-seo' )->check_permissions( array( 'post_id' => 1, 'title' => 'x' ) ) );
		$this->assertFalse( wp_get_ability( 'saddle/rank-math-edit-term-seo' )->check_permissions( array( 'term_id' => 1, 'title' => 'x' ) ) );
	}

	/* -------- check-setup + not-active -------- */

	public function test_check_setup_reports_active_and_version() {
		$result = wp_get_ability( 'saddle/rank-math-check-setup' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['rankmath_active'] );
		$this->assertSame( '99.0-stub', $result['rankmath_version'] );
	}

	public function test_abilities_report_not_active_when_rankmath_inactive() {
		add_filter( 'saddle_rankmath_active', '__return_false' );

		$setup = wp_get_ability( 'saddle/rank-math-check-setup' )->execute( array() );
		$this->assertFalse( $setup['rankmath_active'] );
		$this->assertNotEmpty( $setup['note'] );

		$result = wp_get_ability( 'saddle/rank-math-get-post-seo' )->execute( array( 'post_id' => $this->post_id() ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_no_rankmath', $result->get_error_code() );
	}

	/* -------- post SEO round-trip -------- */

	public function test_edit_and_get_post_seo_round_trip() {
		$post_id = $this->post_id();

		$edit = wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute(
			array(
				'post_id'        => $post_id,
				'title'          => 'A short SEO title',
				'description'    => 'A short meta description.',
				'focus_keyword'  => 'saddle pro, rank math',
				'robots_index'   => 'noindex',
				'robots_follow'  => 'nofollow',
				'og_title'       => 'OG title',
				'twitter_title'  => 'TW title',
				'pillar_content' => true,
			)
		);

		$this->assertNotWPError( $edit );
		$this->assertSame( $post_id, $edit['id'] );
		$this->assertSame( 'A short SEO title', $edit['changed']['title'] );
		$this->assertSame( 'noindex', $edit['changed']['robots_index'] );
		$this->assertSame( array(), $edit['warnings'] );

		$get = wp_get_ability( 'saddle/rank-math-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'A short SEO title', $get['title'] );
		$this->assertSame( 'saddle pro, rank math', $get['focus_keyword'] );
		$this->assertSame( 'noindex', $get['robots_index'] );
		$this->assertSame( 'nofollow', $get['robots_follow'] );
		$this->assertSame( 'OG title', $get['og_title'] );
		$this->assertSame( 'TW title', $get['twitter_title'] );
		$this->assertTrue( $get['pillar_content'] );

		// Writes landed under Rank Math's own key family.
		$this->assertSame( 'A short SEO title', get_post_meta( $post_id, 'rank_math_title', true ) );
		$this->assertSame( array( 'noindex', 'nofollow' ), array_values( get_post_meta( $post_id, 'rank_math_robots', true ) ) );
		// Twitter values only render off the "use Facebook" toggle — the write flips it.
		$this->assertSame( 'off', get_post_meta( $post_id, 'rank_math_twitter_use_facebook', true ) );

		$actions = wp_list_pluck( Saddle_Log::query( 5, 1 )['entries'], 'action' );
		$this->assertContains( 'rank-math-edit-post-seo', $actions );
	}

	public function test_robots_merge_preserves_unrelated_and_unknown_flags() {
		$post_id = $this->post_id();
		update_post_meta( $post_id, 'rank_math_robots', array( 'noindex', 'noarchive', 'future-flag' ) );

		// Change only the index directive; advanced + unknown flags must survive.
		$edit = wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_index' => 'index' ) );
		$this->assertNotWPError( $edit );

		$stored = get_post_meta( $post_id, 'rank_math_robots', true );
		$this->assertContains( 'index', $stored );
		$this->assertNotContains( 'noindex', $stored, 'index/noindex are mutually exclusive.' );
		$this->assertContains( 'noarchive', $stored, 'Untouched advanced flags survive.' );
		$this->assertContains( 'future-flag', $stored, 'Unknown flags survive a merge — never clobbered.' );
	}

	public function test_robots_default_everywhere_deletes_the_meta() {
		$post_id = $this->post_id();
		update_post_meta( $post_id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );

		wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute(
			array( 'post_id' => $post_id, 'robots_index' => 'default', 'robots_follow' => 'follow', 'robots_advanced' => '' )
		);

		$this->assertFalse( metadata_exists( 'post', $post_id, 'rank_math_robots' ), 'An empty robots array means inherit — the meta row goes away.' );

		$get = wp_get_ability( 'saddle/rank-math-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'default', $get['robots_index'] );
		$this->assertSame( 'follow', $get['robots_follow'] );
	}

	public function test_robots_advanced_round_trip_and_rejection() {
		$post_id = $this->post_id();

		$edit = wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_advanced' => 'noarchive,nosnippet' ) );
		$this->assertNotWPError( $edit );

		$get = wp_get_ability( 'saddle/rank-math-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'noarchive,nosnippet', $get['robots_advanced'] );

		$bad = wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_advanced' => 'bogus' ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'saddle_bad_robots_value', $bad->get_error_code() );
	}

	public function test_empty_string_resets_a_text_field() {
		$post_id = $this->post_id();
		update_post_meta( $post_id, 'rank_math_title', 'Old title' );

		wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'title' => '' ) );

		$this->assertFalse( metadata_exists( 'post', $post_id, 'rank_math_title' ), 'Empty string deletes the row so Rank Math falls back to its template.' );
	}

	public function test_edit_post_seo_warns_on_length_without_blocking() {
		$post_id = $this->post_id();

		$edit = wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute(
			array( 'post_id' => $post_id, 'title' => str_repeat( 'x', 61 ), 'description' => str_repeat( 'y', 161 ) )
		);

		$this->assertNotWPError( $edit );
		$this->assertCount( 2, $edit['warnings'] );
		$this->assertSame( str_repeat( 'x', 61 ), get_post_meta( $post_id, 'rank_math_title', true ) );
	}

	public function test_edit_post_seo_requires_edit_post_capability() {
		$author       = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id      = self::factory()->post->create( array( 'post_author' => $other_author ) );
		wp_set_current_user( $author );

		$result = wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'title' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_edit_post_seo_requires_at_least_one_field() {
		$result = wp_get_ability( 'saddle/rank-math-edit-post-seo' )->execute( array( 'post_id' => $this->post_id() ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_empty', $result->get_error_code() );
	}

	/* -------- term SEO round-trip -------- */

	public function test_edit_and_get_term_seo_round_trip() {
		$term_id = self::factory()->category->create( array( 'name' => 'Widgets' ) );

		$edit = wp_get_ability( 'saddle/rank-math-edit-term-seo' )->execute(
			array( 'term_id' => $term_id, 'title' => 'Widgets — Shop', 'description' => 'Browse our widgets.' )
		);
		$this->assertNotWPError( $edit );
		$this->assertSame( 'Widgets — Shop', $edit['changed']['title'] );

		$get = wp_get_ability( 'saddle/rank-math-get-term-seo' )->execute( array( 'term_id' => $term_id ) );
		$this->assertSame( 'Widgets — Shop', $get['title'] );
		$this->assertSame( 'Browse our widgets.', $get['description'] );
		$this->assertSame( 'Widgets — Shop', get_term_meta( $term_id, 'rank_math_title', true ) );
	}

	public function test_get_term_seo_rejects_unknown_term() {
		$result = wp_get_ability( 'saddle/rank-math-get-term-seo' )->execute( array( 'term_id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_found', $result->get_error_code() );
	}
}
