<?php
/**
 * The verify engine + the saddle/verify-page ability.
 *
 * Pins the closed loop (https://github.com/plugpressco/saddle/issues/26):
 * findings from all three passes land at real dot addresses, the score is
 * deterministic arithmetic, fixing the flagged addresses raises it back to
 * 100 (the loop actually closes), payloads stay bounded, and builder pages
 * resolve through the two filters exactly like lint.
 *
 * @package Saddle
 */

class Saddle_Verify_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_verify_builder_findings' );
		remove_all_filters( 'saddle_lint_accessor' );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function page( $content ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
	}

	private function verify( $post_id ) {
		$ability = wp_get_ability( 'saddle/verify-page' );
		$this->assertNotNull( $ability, 'saddle/verify-page must be registered.' );
		return $ability->execute( array( 'post_id' => $post_id ) );
	}

	private function bad_markup() {
		// One echo problem (unknown style group -> silently no CSS) and one
		// lint problem (button below WCAG AA).
		return '<!-- wp:paragraph {"style":{"nonsense":{"x":1}}} --><p>Styled into the void.</p><!-- /wp:paragraph -->'
			. '<!-- wp:button {"style":{"color":{"background":"#f5f5f5","text":"#ffffff"}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Go</a></div><!-- /wp:button -->';
	}

	private function clean_markup() {
		return '<!-- wp:paragraph --><p>Honest copy.</p><!-- /wp:paragraph -->'
			. '<!-- wp:button {"style":{"color":{"background":"#0a6b3d","text":"#ffffff"}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Go</a></div><!-- /wp:button -->';
	}

	/* -------- the clean case -------- */

	public function test_clean_page_scores_100() {
		$result = $this->verify( $this->page( $this->clean_markup() ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 'A', $result['grade'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( array(), $result['skipped'] );
	}

	/* -------- findings from every pass, deterministic score -------- */

	public function test_echo_and_lint_findings_land_at_addresses_with_arithmetic_score() {
		$result = $this->verify( $this->page( $this->bad_markup() ) );

		$this->assertNotWPError( $result );
		// 100 - 10 (echo) - 8 (lint error) = 82.
		$this->assertSame( 82, $result['score'] );
		$this->assertSame( 'B', $result['grade'] );
		$this->assertSame( 1, $result['counts']['ignored'] );
		$this->assertSame( 1, $result['counts']['errors'] );

		// Echo outranks lint — that styling never took effect at all.
		$this->assertSame( 'echo', $result['findings'][0]['source'] );
		$this->assertSame( '0', $result['findings'][0]['address'] );
		$this->assertSame( 'lint', $result['findings'][1]['source'] );
		$this->assertSame( '1', $result['findings'][1]['address'] );
		$this->assertSame( 'button-contrast', $result['findings'][1]['rule'] );
		$this->assertNotSame( '', $result['findings'][1]['fix_hint'] );
	}

	public function test_the_loop_closes_fixing_flagged_addresses_raises_the_score() {
		$id     = $this->page( $this->bad_markup() );
		$before = $this->verify( $id );
		$this->assertSame( 82, $before['score'] );

		// Fix exactly what was flagged, the way an agent would.
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->clean_markup(),
			)
		);

		$after = $this->verify( $id );
		$this->assertSame( 100, $after['score'], 'Verify re-reads persisted state — the fix must be visible immediately.' );
		$this->assertSame( array(), $after['findings'] );
	}

	/* -------- structural -------- */

	public function test_raw_html_content_is_a_structural_finding() {
		$result = $this->verify( $this->page( '<div class="hand-rolled"><p>Not blocks at all.</p></div>' ) );

		$this->assertSame( 1, $result['counts']['structural'] );
		$this->assertSame( 'structural', $result['findings'][0]['source'] );
		$this->assertSame( 75, $result['score'] ); // 100 - 25.
	}

	/* -------- bounded payload -------- */

	public function test_findings_are_capped_with_an_overflow_count() {
		$markup = '';
		for ( $i = 0; $i < 45; $i++ ) {
			$markup .= '<!-- wp:paragraph {"style":{"nonsense":{"x":1}}} --><p>N' . $i . '</p><!-- /wp:paragraph -->';
		}
		$result = $this->verify( $this->page( $markup ) );

		$this->assertCount( Saddle_Verify::FINDINGS_CAP, $result['findings'] );
		$this->assertSame( 5, $result['overflow'] );
		$this->assertSame( 0, $result['score'], 'The score reflects ALL findings, not just the shown page.' );
	}

	/* -------- builder resolution -------- */

	public function test_builder_page_with_no_verifier_is_refused() {
		$id     = $this->page( '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' );
		$result = $this->verify( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_verify_unsupported', $result->get_error_code() );
	}

	public function test_builder_findings_and_accessor_arrive_through_the_filters() {
		add_filter(
			'saddle_verify_builder_findings',
			static function ( $findings, $tree, $builder ) {
				if ( 'Divi 5' !== $builder ) {
					return $findings;
				}
				$finding = array(
					'address'  => '0.0',
					'source'   => 'echo',
					'severity' => 'error',
					'message'  => 'decoration.padding is not a group.',
					'fix_hint' => 'Use decoration.spacing.',
				);
				// Returned twice on purpose — the engine must dedupe.
				return array( $finding, $finding );
			},
			10,
			3
		);
		add_filter(
			'saddle_lint_accessor',
			static function ( $accessor, $builder ) {
				return 'Divi 5' === $builder ? new Saddle_Lint_Gutenberg_Accessor() : $accessor;
			},
			10,
			2
		);

		$id     = $this->page( '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' );
		$result = $this->verify( $id );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Divi 5', $result['builder'] );
		$this->assertSame( array(), $result['skipped'] );
		$this->assertCount( 1, $result['findings'], 'Identical builder findings dedupe to one.' );
		$this->assertSame( 90, $result['score'] ); // 100 - 10, deduped.
	}

	/* -------- registration -------- */

	public function test_verify_page_is_read_tier_and_readonly() {
		$meta = wp_get_ability( 'saddle/verify-page' )->get_meta();
		$this->assertSame( 'read', $meta['saddle']['tier'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
	}

	public function test_missing_post_is_not_found() {
		$result = $this->verify( 999999 );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_found', $result->get_error_code() );
	}
}
