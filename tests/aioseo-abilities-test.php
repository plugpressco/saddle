<?php
/**
 * saddle/aioseo-* — native AIOSEO integration.
 *
 * Coverage: tier enforcement, per-object capability, the robots
 * default-switch logic (the master switch only returns to default when no
 * flag remains; any flag forces custom robots), the focus and additional
 * keyphrases in both of AIOSEO's storage shapes (the old `keyphrases`
 * structure and AIOSEO 5's keyword columns), the twitter/og custom-source toggles,
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
		remove_filter( 'saddle_aioseo_keyword_columns', '__return_true' );
		remove_filter( 'saddle_aioseo_pro', '__return_true' );
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

	/* -------- additional keyphrases (#466) -------- */

	private function keyphrases_of( $post_id ) {
		return json_decode( (string) wp_json_encode( \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id )->keyphrases ), true );
	}

	private function seed_keyphrases( $post_id, array $keyphrases ) {
		$model             = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$model->keyphrases = json_decode( (string) wp_json_encode( $keyphrases ) );
		$model->save();
		return $model;
	}

	public function test_additional_keywords_round_trip() {
		$post_id = $this->post_id();

		$edit = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute(
			array(
				'post_id'             => $post_id,
				'additional_keywords' => array( 'divi modules', '  Flip Box ', 'DIVI MODULES', '', '<b>pricing table</b>' ),
			)
		);

		$this->assertNotWPError( $edit );
		$this->assertSame(
			array( 'divi modules', 'Flip Box', 'pricing table' ),
			$edit['changed']['additional_keywords'],
			'Trimmed, tags stripped, empty and repeated keyphrases dropped.'
		);

		$get = wp_get_ability( 'saddle/aioseo-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( array( 'divi modules', 'Flip Box', 'pricing table' ), $get['additional_keywords'] );
		$this->assertSame( '', $get['focus_keyword'] );

		$saved = $this->keyphrases_of( $post_id );
		$this->assertSame(
			array(
				'keyphrase' => 'divi modules',
				'score'     => 0,
			),
			$saved['additional'][0],
			'A new entry has the shape AIOSEO\'s own editor writes.'
		);
		$this->assertSame( '', $saved['focus']['keyphrase'], 'An empty focus still saves as an object.' );
	}

	public function test_additional_keywords_replace_the_list_and_keep_known_scores() {
		$post_id = $this->post_id();
		$this->seed_keyphrases(
			$post_id,
			array(
				'focus'      => array( 'keyphrase' => 'the focus', 'score' => 77 ),
				'additional' => array(
					array( 'keyphrase' => 'extra one', 'score' => 51, 'analysis' => array( 'keyphraseInTitle' => array( 'score' => 9 ) ) ),
					array( 'keyphrase' => 'dropped', 'score' => 12 ),
				),
			)
		);

		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute(
			array( 'post_id' => $post_id, 'additional_keywords' => array( 'Extra One', 'brand new' ) )
		);

		$saved = $this->keyphrases_of( $post_id );
		$this->assertSame( array( 'Extra One', 'brand new' ), wp_list_pluck( $saved['additional'], 'keyphrase' ), 'The list is replaced.' );
		$this->assertSame( 51, $saved['additional'][0]['score'], 'A keyphrase already on the post keeps its score.' );
		$this->assertSame( 9, $saved['additional'][0]['analysis']['keyphraseInTitle']['score'], 'And its analysis.' );
		$this->assertSame( 0, $saved['additional'][1]['score'] );
		$this->assertSame( 'the focus', $saved['focus']['keyphrase'], 'The focus keyphrase is untouched.' );
		$this->assertSame( 77, $saved['focus']['score'] );
	}

	public function test_empty_additional_keywords_clears_the_list() {
		$post_id = $this->post_id();
		$this->seed_keyphrases(
			$post_id,
			array(
				'focus'      => array( 'keyphrase' => 'the focus', 'score' => 77 ),
				'additional' => array( array( 'keyphrase' => 'extra one', 'score' => 51 ) ),
			)
		);

		$edit = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'additional_keywords' => array() ) );

		$this->assertNotWPError( $edit );
		$this->assertSame( array(), $edit['changed']['additional_keywords'] );
		$this->assertSame( array(), $edit['warnings'], 'Nothing to warn about when the list is empty.' );
		$this->assertSame( array(), $this->keyphrases_of( $post_id )['additional'] );
	}

	public function test_additional_keywords_warn_without_aioseo_pro() {
		$post_id = $this->post_id();
		$input   = array( 'post_id' => $post_id, 'additional_keywords' => array( 'one' ) );

		$edit = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( $input );
		$this->assertCount( 1, $edit['warnings'] );
		$this->assertStringContainsString( 'AIOSEO Pro', $edit['warnings'][0] );

		add_filter( 'saddle_aioseo_pro', '__return_true' );
		$edit = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( $input );
		$this->assertSame( array(), $edit['warnings'] );
	}

	/* -------- AIOSEO 5's keyword columns (#466) -------- */

	public function test_aioseo_5_columns_are_written_with_the_old_structure() {
		add_filter( 'saddle_aioseo_keyword_columns', '__return_true' );
		$post_id = $this->post_id();
		// A post that already has a focus keyphrase: AIOSEO 5 ignores a
		// write that only reaches `keyphrases` (the bug #466 names).
		$model                = $this->seed_keyphrases( $post_id, array( 'focus' => array( 'keyphrase' => 'old focus', 'score' => 60 ), 'additional' => array() ) );
		$model->focus_keyword = 'old focus';
		$model->save();

		$edit = wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute(
			array(
				'post_id'             => $post_id,
				'focus_keyword'       => 'new focus',
				'additional_keywords' => array( 'first extra', 'second extra' ),
			)
		);

		$this->assertNotWPError( $edit );
		$this->assertSame( 'new focus', $edit['changed']['focus_keyword'] );
		$this->assertSame( array( 'first extra', 'second extra' ), $edit['changed']['additional_keywords'] );

		$model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$this->assertSame( 'new focus', $model->focus_keyword, 'The column AIOSEO 5 reads.' );
		$this->assertSame(
			array(
				array( 'word' => 'first extra', 'score' => 0 ),
				array( 'word' => 'second extra', 'score' => 0 ),
			),
			json_decode( (string) wp_json_encode( $model->additional_keywords ), true ),
			'AIOSEO 5 keeps additional keyphrases as { word, score }.'
		);

		$saved = $this->keyphrases_of( $post_id );
		$this->assertSame( 'new focus', $saved['focus']['keyphrase'], 'The old structure stays in step.' );
		$this->assertSame( array( 'first extra', 'second extra' ), wp_list_pluck( $saved['additional'], 'keyphrase' ) );
	}

	public function test_aioseo_5_read_prefers_the_columns_and_falls_back() {
		add_filter( 'saddle_aioseo_keyword_columns', '__return_true' );
		$post_id = $this->post_id();
		$model   = $this->seed_keyphrases(
			$post_id,
			array(
				'focus'      => array( 'keyphrase' => 'stale focus' ),
				'additional' => array( array( 'keyphrase' => 'old extra', 'score' => 30 ) ),
			)
		);

		$get = wp_get_ability( 'saddle/aioseo-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'stale focus', $get['focus_keyword'], 'Empty columns: the old structure answers, as in AIOSEO.' );
		$this->assertSame( array( 'old extra' ), $get['additional_keywords'] );

		$model->focus_keyword       = 'column focus';
		$model->additional_keywords = json_decode( '[{"word":"column extra","score":40}]' );
		$model->save();

		$get = wp_get_ability( 'saddle/aioseo-get-post-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'column focus', $get['focus_keyword'] );
		$this->assertSame( array( 'column extra' ), $get['additional_keywords'] );
	}

	public function test_aioseo_5_columns_keep_scores_and_clear_to_null() {
		add_filter( 'saddle_aioseo_keyword_columns', '__return_true' );
		$post_id                    = $this->post_id();
		$model                      = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$model->focus_keyword       = 'the focus';
		$model->additional_keywords = json_decode( '[{"word":"kept","score":40},{"word":"dropped","score":10}]' );
		$model->save();

		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'additional_keywords' => array( 'kept', 'added' ) ) );

		$model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$this->assertSame(
			array(
				array( 'word' => 'kept', 'score' => 40 ),
				array( 'word' => 'added', 'score' => 0 ),
			),
			json_decode( (string) wp_json_encode( $model->additional_keywords ), true )
		);
		$this->assertSame( 'the focus', $model->focus_keyword, 'Additional keyphrases alone leave the focus column alone.' );
		$this->assertSame( 40, $this->keyphrases_of( $post_id )['additional'][0]['score'], 'The old structure carries the column\'s score.' );

		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute( array( 'post_id' => $post_id, 'focus_keyword' => '', 'additional_keywords' => array() ) );

		$model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$this->assertNull( $model->focus_keyword, 'AIOSEO stores no keyphrase as NULL.' );
		$this->assertNull( $model->additional_keywords );
	}

	public function test_older_aioseo_gets_no_keyword_columns() {
		$post_id = $this->post_id();

		wp_get_ability( 'saddle/aioseo-edit-post-seo' )->execute(
			array( 'post_id' => $post_id, 'focus_keyword' => 'a focus', 'additional_keywords' => array( 'an extra' ) )
		);

		$model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$this->assertNull( $model->focus_keyword, 'Before 5.0.0 the columns do not exist, so nothing is set on them.' );
		$this->assertNull( $model->additional_keywords );
		$this->assertSame( 'a focus', $this->keyphrases_of( $post_id )['focus']['keyphrase'] );
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
