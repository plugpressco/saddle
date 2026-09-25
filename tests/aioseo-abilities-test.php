<?php
/**
 * saddle/aioseo-* — native AIOSEO integration.
 *
 * Coverage: tier enforcement, per-object capability, the robots
 * default-switch logic (the master switch only returns to default when no
 * flag remains; any flag forces custom robots), keyphrases focus handling
 * (additional keyphrases preserved), the twitter/og custom-source toggles,
 * length warnings, and the not-active error shape. Against the in-memory
 * Post-model stub in tests/stubs-aioseo-model.php — live behavior verified
 * separately with real AIOSEO on divi-dev.
 *
 * @package Saddle
 */

class Saddle_Aioseo_Abilities_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
		\AIOSEO\Plugin\Common\Models\Post::reset_store();
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		remove_filter( 'saddle_aioseo_active', '__return_false' );
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
			'saddle/aioseo-check-setup'   => 'read',
			'saddle/aioseo-get-post-seo'  => 'read',
			'saddle/aioseo-edit-post-seo' => 'write',
		);

		foreach ( $expect as $name => $tier ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "{$name} must be registered." );
			$this->assertSame( $tier, $ability->get_meta()['saddle']['tier'], "{$name} tier" );
			$this->assertFalse( $ability->get_meta()['annotations']['destructive'], "{$name} must not be destructive." );
		}
	}

	public function test_edit_denied_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertFalse( wp_get_ability( 'saddle/aioseo-edit-post-seo' )->check_permissions( array( 'post_id' => 1, 'title' => 'x' ) ) );
	}

	/* -------- check-setup + not-active -------- */

	public function test_check_setup_reports_active_and_version() {
		$result = wp_get_ability( 'saddle/aioseo-check-setup' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['aioseo_active'] );
		$this->assertSame( '99.0-stub', $result['aioseo_version'] );
	}

	public function test_abilities_report_not_active_when_aioseo_inactive() {
		add_filter( 'saddle_aioseo_active', '__return_false' );

		$setup = wp_get_ability( 'saddle/aioseo-check-setup' )->execute( array() );
		$this->assertFalse( $setup['aioseo_active'] );
		$this->assertNotEmpty( $setup['note'] );

		$result = wp_get_ability( 'saddle/aioseo-get-post-seo' )->execute( array( 'post_id' => $this->post_id() ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_no_aioseo', $result->get_error_code() );
	}

	/* -------- post SEO round-trip -------- */

	public function test_edit_and_get_post_seo_round_trip() {
		$post_id = $this->post_id();

		$edit = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute(
			array(
				'post_id'        => $post_id,
				'title'          => 'A short SEO title',
				'description'    => 'A short meta description.',
				'focus_keyword'  => 'saddle pro aioseo',
				'robots_index'   => 'noindex',
				'og_title'       => 'OG title',
				'twitter_title'  => 'TW title',
				'og_image'       => 'https://example.com/og.png',
				'pillar_content' => true,
			)
		);

		$this->assertNotWPError( $edit );
		$this->assertSame( $post_id, $edit['id'] );
		$this->assertSame( 'A short SEO title', $edit['changed']['title'] );
		$this->assertSame( 'noindex', $edit['changed']['robots_index'] );
		$this->assertSame( array(), $edit['warnings'] );

		$get = wp_get_ability( 'saddle/aioseo-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'A short SEO title', $get['title'] );
		$this->assertSame( 'saddle pro aioseo', $get['focus_keyword'] );
		$this->assertSame( 'noindex', $get['robots_index'] );
		$this->assertSame( 'OG title', $get['og_title'] );
		$this->assertSame( 'TW title', $get['twitter_title'] );
		$this->assertTrue( $get['pillar_content'] );

		// The renders-nothing traps: custom image needs its type switched,
		// custom twitter values need the OG mirror off.
		$model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$this->assertSame( 'custom', $model->og_image_type );
		$this->assertFalse( $model->twitter_use_og );
		$this->assertFalse( $model->robots_default, 'Any robots flag forces the master switch off.' );

		$actions = wp_list_pluck( Saddle_Log::query( 5, 1 )['entries'], 'action' );
		$this->assertContains( 'aioseo-edit-post-seo', $actions );
	}

	public function test_focus_keyword_preserves_additional_keyphrases() {
		$post_id = $this->post_id();
		$model   = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$model->keyphrases = json_decode(
			(string) wp_json_encode(
				array(
					'focus'      => array( 'keyphrase' => 'old focus', 'score' => 77 ),
					'additional' => array( array( 'keyphrase' => 'extra one', 'score' => 51 ) ),
				)
			)
		);
		$model->save();

		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'focus_keyword' => 'new focus' ) );

		$saved = json_decode( (string) wp_json_encode( \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id )->keyphrases ), true );
		$this->assertSame( 'new focus', $saved['focus']['keyphrase'] );
		$this->assertSame( 77, $saved['focus']['score'], 'Sibling focus keys survive.' );
		$this->assertSame( 'extra one', $saved['additional'][0]['keyphrase'], 'Additional keyphrases survive.' );
	}

	public function test_robots_default_only_returns_when_no_flag_remains() {
		$post_id = $this->post_id();

		// Custom state: noindex + nofollow.
		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_index' => 'noindex', 'robots_follow' => 'nofollow' ) );

		// Asking for default while nofollow remains: stays custom.
		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_index' => 'default' ) );
		$model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$this->assertFalse( $model->robots_default, 'nofollow still set — the master switch cannot return to default.' );
		$this->assertFalse( $model->robots_noindex );
		$this->assertTrue( $model->robots_nofollow );

		// Clearing the last flag too: back to the site default.
		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_index' => 'default', 'robots_follow' => 'follow' ) );
		$model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$this->assertTrue( $model->robots_default );

		$get = wp_get_ability( 'saddle/aioseo-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'default', $get['robots_index'] );
		$this->assertSame( 'follow', $get['robots_follow'] );
	}

	public function test_robots_advanced_round_trip_and_rejection() {
		$post_id = $this->post_id();

		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_advanced' => 'noarchive,noimageindex' ) );

		$get = wp_get_ability( 'saddle/aioseo-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'noarchive,noimageindex', $get['robots_advanced'] );

		$bad = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'robots_advanced' => 'bogus' ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'saddle_bad_robots_value', $bad->get_error_code() );
	}

	public function test_edit_post_seo_warns_on_length_without_blocking() {
		$post_id = $this->post_id();

		$edit = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute(
			array( 'post_id' => $post_id, 'title' => str_repeat( 'x', 61 ), 'description' => str_repeat( 'y', 161 ) )
		);

		$this->assertNotWPError( $edit );
		$this->assertCount( 2, $edit['warnings'] );
		$this->assertSame( str_repeat( 'x', 61 ), \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id )->title );
	}

	public function test_edit_post_seo_requires_edit_post_capability() {
		$author       = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id      = self::factory()->post->create( array( 'post_author' => $other_author ) );
		wp_set_current_user( $author );

		$result = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'title' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_forbidden', $result->get_error_code() );
	}

	public function test_edit_post_seo_requires_at_least_one_field() {
		$result = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $this->post_id() ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_empty', $result->get_error_code() );
	}
}
