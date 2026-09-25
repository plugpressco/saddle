<?php
/**
 * saddle/yoast-* — native Yoast SEO integration.
 *
 * Coverage: tier enforcement, per-object capability (mirrors
 * Saddle_Abilities::authorize_write), the robots friendly<->tri-state
 * round-trip, non-blocking length warnings, and the graceful "Yoast not
 * active" error shape. Against the thin WPSEO_Meta/WPSEO_Taxonomy_Meta/
 * WPSEO_Primary_Term stubs in tests/bootstrap.php — field-mapping details
 * against a real Yoast install are verified separately on divi-dev.
 *
 * @package Saddle
 */

class Saddle_Yoast_Abilities_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		remove_filter( 'saddle_yoast_active', '__return_false' );
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
			'saddle/yoast-check-setup'     => array( 'read', false ),
			'saddle/yoast-get-post-seo'    => array( 'read', false ),
			'saddle/yoast-edit-post-seo'   => array( 'write', false ),
			'saddle/yoast-get-term-seo'    => array( 'read', false ),
			'saddle/yoast-edit-term-seo'   => array( 'write', false ),
			'saddle/yoast-get-post-schema' => array( 'read', false ),
			'saddle/yoast-edit-post-schema' => array( 'write', false ),
		);

		foreach ( $expect as $name => list( $tier, $destructive ) ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "{$name} must be registered." );
			$this->assertSame( $tier, $ability->get_meta()['saddle']['tier'], "{$name} tier" );
			$this->assertSame( $destructive, $ability->get_meta()['annotations']['destructive'], "{$name} destructive flag" );
		}
	}

	public function test_write_abilities_denied_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertFalse( wp_get_ability( 'saddle/yoast-edit-post-seo' )->check_permissions( array( 'post_id' => 1, 'title' => 'x' ) ) );
		$this->assertFalse( wp_get_ability( 'saddle/yoast-edit-term-seo' )->check_permissions( array( 'term_id' => 1, 'title' => 'x' ) ) );
		$this->assertFalse( wp_get_ability( 'saddle/yoast-edit-post-schema' )->check_permissions( array( 'post_id' => 1, 'page_type' => 'Article' ) ) );
	}

	/* -------- check-setup -------- */

	public function test_check_setup_reports_active_and_version() {
		$result = wp_get_ability( 'saddle/yoast-check-setup' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['yoast_active'] );
		$this->assertSame( '99.0-stub', $result['yoast_version'] );
	}

	public function test_check_setup_reports_readable_post() {
		$post_id = $this->post_id( array( 'post_title' => 'Hello' ) );

		$result = wp_get_ability( 'saddle/yoast-check-setup' )->execute( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( $post_id, $result['post']['id'] );
		$this->assertSame( 'Hello', $result['post']['title'] );
	}

	public function test_abilities_report_not_active_when_yoast_inactive() {
		add_filter( 'saddle_yoast_active', '__return_false' );

		$setup = wp_get_ability( 'saddle/yoast-check-setup' )->execute( array() );
		$this->assertFalse( $setup['yoast_active'] );
		$this->assertNotEmpty( $setup['note'] );

		$post_id = $this->post_id();
		$result  = wp_get_ability( 'saddle/yoast-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_no_yoast', $result->get_error_code() );
	}

	/* -------- post SEO round-trip -------- */

	public function test_edit_and_get_post_seo_round_trip() {
		$post_id = $this->post_id();

		$edit = wp_get_ability( 'saddle/yoast-edit-post-seo' )->execute(
			array(
				'post_id'       => $post_id,
				'title'         => 'A short SEO title',
				'description'   => 'A short meta description.',
				'focus_keyword' => 'saddle pro',
				'robots_index'  => 'noindex',
				'robots_follow' => 'nofollow',
				'og_title'      => 'OG title',
			)
		);

		$this->assertNotWPError( $edit );
		$this->assertSame( $post_id, $edit['id'] );
		$this->assertSame( 'A short SEO title', $edit['changed']['title'] );
		$this->assertSame( 'noindex', $edit['changed']['robots_index'] );
		$this->assertSame( array(), $edit['warnings'], 'Short title/description must not warn.' );

		$get = wp_get_ability( 'saddle/yoast-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'A short SEO title', $get['title'] );
		$this->assertSame( 'saddle pro', $get['focus_keyword'] );
		$this->assertSame( 'noindex', $get['robots_index'], 'Friendly value must round-trip through the tri-state encoding.' );
		$this->assertSame( 'nofollow', $get['robots_follow'] );
		$this->assertSame( 'OG title', $get['og_title'] );

		$actions = wp_list_pluck( Saddle_Log::query( 5, 1 )['entries'], 'action' );
		$this->assertContains( 'yoast-edit-post-seo', $actions );
	}

	public function test_robots_default_round_trips_to_default_not_absent() {
		$post_id = $this->post_id();

		wp_get_ability( 'saddle/yoast-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_index' => 'index' ) );
		wp_get_ability( 'saddle/yoast-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_index' => 'default' ) );

		$get = wp_get_ability( 'saddle/yoast-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'default', $get['robots_index'] );
	}

	public function test_edit_post_seo_rejects_an_invalid_robots_value() {
		// The input_schema's enum is the live guard rail for this — the
		// ability's own to_tri_state() check is defense-in-depth for any
		// caller that reaches execute() without going through schema
		// validation.
		$post_id = $this->post_id();

		$result = wp_get_ability( 'saddle/yoast-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_index' => 'bogus' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_to_tri_state_rejects_an_invalid_robots_value_directly() {
		$maps = Saddle_Yoast::noindex_maps();

		$result = Saddle_Yoast::to_tri_state( 'bogus', $maps['to'] );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_robots_value', $result->get_error_code() );
	}

	public function test_edit_post_seo_warns_on_length_without_blocking() {
		$post_id = $this->post_id();

		$long_title       = str_repeat( 'x', 61 );
		$long_description = str_repeat( 'y', 161 );

		$edit = wp_get_ability( 'saddle/yoast-edit-post-seo' )->execute(
			array( 'post_id' => $post_id, 'title' => $long_title, 'description' => $long_description )
		);

		$this->assertNotWPError( $edit, 'A long title/description still saves.' );
		$this->assertCount( 2, $edit['warnings'] );
		$this->assertSame( $long_title, get_post_meta( $post_id, '_yoast_wpseo_title', true ) );
	}

	public function test_edit_post_seo_requires_edit_post_capability() {
		$author       = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id      = self::factory()->post->create( array( 'post_author' => $other_author ) );
		wp_set_current_user( $author );

		$result = wp_get_ability( 'saddle/yoast-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'title' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_edit_post_seo_requires_at_least_one_field() {
		$post_id = $this->post_id();

		$result = wp_get_ability( 'saddle/yoast-edit-post-seo' )->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_empty', $result->get_error_code() );
	}

	/* -------- term SEO round-trip -------- */

	public function test_edit_and_get_term_seo_round_trip() {
		$term_id = self::factory()->category->create( array( 'name' => 'Widgets' ) );

		$edit = wp_get_ability( 'saddle/yoast-edit-term-seo' )->execute(
			array( 'term_id' => $term_id, 'title' => 'Widgets — Shop', 'description' => 'Browse our widgets.' )
		);
		$this->assertNotWPError( $edit );
		$this->assertSame( 'Widgets — Shop', $edit['changed']['title'] );

		$get = wp_get_ability( 'saddle/yoast-get-term-seo' )->execute( array( 'term_id' => $term_id ) );
		$this->assertSame( 'Widgets — Shop', $get['title'] );
		$this->assertSame( 'Browse our widgets.', $get['description'] );
	}

	public function test_get_term_seo_rejects_unknown_term() {
		$result = wp_get_ability( 'saddle/yoast-get-term-seo' )->execute( array( 'term_id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_found', $result->get_error_code() );
	}

	/* -------- post schema round-trip -------- */

	public function test_edit_and_get_post_schema_round_trip() {
		$post_id = $this->post_id();

		$edit = wp_get_ability( 'saddle/yoast-edit-post-schema' )->execute(
			array( 'post_id' => $post_id, 'page_type' => 'Article', 'article_type' => 'BlogPosting' )
		);
		$this->assertNotWPError( $edit );

		$get = wp_get_ability( 'saddle/yoast-get-post-schema' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'Article', $get['page_type'] );
		$this->assertSame( 'BlogPosting', $get['article_type'] );
	}
}
