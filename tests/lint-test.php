<?php
/**
 * The design lint engine + the saddle/lint-page ability.
 *
 * Every rule is pinned BOTH ways — the violation fires on the bad fixture
 * and stays silent on the clean one (a lint that cries wolf gets ignored,
 * https://github.com/plugpressco/saddle/issues/10 §2.1). Plus the engine contract: rules registered through
 * the one filter, violations shaped {address, rule, severity, message,
 * fix_hint}, builder pages resolved through the accessor filter.
 *
 * @package Saddle
 */

class Saddle_Lint_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_lint_rules' );
		remove_all_filters( 'saddle_lint_accessor' );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function lint( $markup ) {
		return Saddle_Lint::run( Saddle_Tree::parse( $markup ), new Saddle_Lint_Gutenberg_Accessor() );
	}

	private function by_rule( array $violations, $rule ) {
		return array_values(
			array_filter(
				$violations,
				static function ( $v ) use ( $rule ) {
					return $v['rule'] === $rule;
				}
			)
		);
	}

	private function button( $attrs = array(), $label = 'Go' ) {
		$json = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		return '<!-- wp:button' . $json . ' --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">' . $label . '</a></div><!-- /wp:button -->';
	}

	private function group( $attrs, $inner ) {
		$json = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		return '<!-- wp:group' . $json . ' --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
	}

	/* -------- empty-title -------- */

	public function test_empty_title_fires_on_blank_heading() {
		$violations = $this->by_rule(
			$this->lint( '<!-- wp:heading --><h2 class="wp-block-heading"></h2><!-- /wp:heading -->' ),
			'empty-title'
		);
		$this->assertCount( 1, $violations );
		$this->assertSame( '0', $violations[0]['address'] );
		$this->assertSame( 'error', $violations[0]['severity'] );
		$this->assertNotSame( '', $violations[0]['fix_hint'] );
	}

	public function test_empty_title_silent_on_real_heading_and_non_titles() {
		$violations = $this->lint(
			'<!-- wp:heading --><h2 class="wp-block-heading">Pricing</h2><!-- /wp:heading -->' .
			'<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->'
		);
		$this->assertSame( array(), $this->by_rule( $violations, 'empty-title' ) );
	}

	/* -------- button-contrast -------- */

	public function test_button_contrast_fires_below_aa() {
		$violations = $this->by_rule(
			$this->lint( $this->button( array( 'style' => array( 'color' => array( 'background' => '#f5f5f5', 'text' => '#ffffff' ) ) ) ) ),
			'button-contrast'
		);
		$this->assertCount( 1, $violations );
		$this->assertSame( 'error', $violations[0]['severity'] );
	}

	public function test_button_contrast_silent_on_aa_pair_and_unresolvable_colors() {
		$violations = $this->lint(
			$this->button( array( 'style' => array( 'color' => array( 'background' => '#1a2b3c', 'text' => '#ffffff' ) ) ) ) .
			// No colors at all (theme-styled) — nothing to judge, never guessed.
			$this->button()
		);
		$this->assertSame( array(), $this->by_rule( $violations, 'button-contrast' ) );
	}

	/* -------- ghost-button -------- */

	public function test_ghost_button_fires_on_outline_style() {
		$violations = $this->by_rule(
			$this->lint( $this->button( array( 'className' => 'is-style-outline' ) ) ),
			'ghost-button'
		);
		$this->assertCount( 1, $violations );
		$this->assertSame( 'warn', $violations[0]['severity'] );
	}

	public function test_ghost_button_silent_on_default_fill() {
		$violations = $this->lint( $this->button() );
		$this->assertSame( array(), $this->by_rule( $violations, 'ghost-button' ) );
	}

	/* -------- double-background -------- */

	public function test_double_background_fires_on_repeated_ancestor_color() {
		$markup = $this->group(
			array( 'style' => array( 'color' => array( 'background' => '#112233' ) ) ),
			// One unpainted level between — the rule walks UP to the nearest painted ancestor.
			$this->group(
				array(),
				$this->group( array( 'style' => array( 'color' => array( 'background' => '#112233' ) ) ), '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' )
			)
		);
		$violations = $this->by_rule( $this->lint( $markup ), 'double-background' );
		$this->assertCount( 1, $violations );
		$this->assertSame( '0.0.0', $violations[0]['address'] );
	}

	public function test_double_background_silent_on_distinct_band() {
		$markup     = $this->group(
			array( 'style' => array( 'color' => array( 'background' => '#112233' ) ) ),
			$this->group( array( 'style' => array( 'color' => array( 'background' => '#ffffff' ) ) ), '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' )
		);
		$violations = $this->lint( $markup );
		$this->assertSame( array(), $this->by_rule( $violations, 'double-background' ) );
	}

	/* -------- mixed-accents -------- */

	public function test_mixed_accents_fires_across_hue_families() {
		$red  = $this->button( array( 'style' => array( 'color' => array( 'background' => '#d63638' ) ) ) );
		$blue = $this->button( array( 'style' => array( 'color' => array( 'background' => '#2271b1' ) ) ) );

		$violations = $this->by_rule( $this->lint( $red . $red . $blue ), 'mixed-accents' );
		$this->assertCount( 1, $violations );
		$this->assertSame( '2', $violations[0]['address'] ); // The minority accent, not the dominant ones.
	}

	public function test_mixed_accents_silent_on_one_accent_plus_neutrals() {
		$violations = $this->lint(
			$this->button( array( 'style' => array( 'color' => array( 'background' => '#d63638' ) ) ) ) .
			$this->button( array( 'style' => array( 'color' => array( 'background' => '#666666' ) ) ) ) .
			$this->button( array( 'style' => array( 'color' => array( 'background' => '#111111' ) ) ) )
		);
		$this->assertSame( array(), $this->by_rule( $violations, 'mixed-accents' ) );
	}

	/* -------- unaligned-buttons -------- */

	public function test_unaligned_buttons_fires_on_disagreeing_siblings() {
		$markup     = $this->group(
			array(),
			$this->button( array( 'textAlign' => 'center' ) ) . $this->button( array( 'textAlign' => 'left' ) )
		);
		$violations = $this->by_rule( $this->lint( $markup ), 'unaligned-buttons' );
		$this->assertCount( 1, $violations );
		$this->assertSame( '0.1', $violations[0]['address'] );
	}

	public function test_unaligned_buttons_silent_on_agreement_or_unset() {
		$violations = $this->lint(
			$this->group( array(), $this->button( array( 'textAlign' => 'center' ) ) . $this->button( array( 'textAlign' => 'center' ) ) ) .
			$this->group( array(), $this->button() . $this->button() )
		);
		$this->assertSame( array(), $this->by_rule( $violations, 'unaligned-buttons' ) );
	}

	/* -------- section-padding -------- */

	private function section( $vertical ) {
		return $this->group(
			array( 'style' => array( 'spacing' => array( 'padding' => array( 'top' => $vertical, 'bottom' => $vertical ) ) ) ),
			'<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'
		);
	}

	public function test_section_padding_fires_on_the_rhythm_breaker() {
		$violations = $this->by_rule(
			$this->lint( $this->section( '96px' ) . $this->section( '96px' ) . $this->section( '12px' ) ),
			'section-padding'
		);
		$this->assertCount( 1, $violations );
		$this->assertSame( '2', $violations[0]['address'] );
	}

	public function test_section_padding_silent_on_uniform_rhythm_and_no_majority() {
		$uniform = $this->lint( $this->section( '96px' ) . $this->section( '96px' ) . $this->section( '96px' ) );
		$this->assertSame( array(), $this->by_rule( $uniform, 'section-padding' ) );

		// All different: no rhythm exists, nothing is "the odd one out".
		$scattered = $this->lint( $this->section( '96px' ) . $this->section( '48px' ) . $this->section( '12px' ) );
		$this->assertSame( array(), $this->by_rule( $scattered, 'section-padding' ) );
	}

	/* -------- no-featured-plan -------- */

	private function plan_card( $attrs = array() ) {
		return '<!-- wp:column' . ( $attrs ? ' ' . wp_json_encode( $attrs ) : '' ) . ' --><div class="wp-block-column">'
			. '<!-- wp:buttons --><div class="wp-block-buttons">' . $this->button() . '</div><!-- /wp:buttons -->'
			. '</div><!-- /wp:column -->';
	}

	public function test_featured_plan_fires_on_equal_card_row() {
		$markup     = '<!-- wp:columns --><div class="wp-block-columns">'
			. $this->plan_card() . $this->plan_card() . $this->plan_card()
			. '</div><!-- /wp:columns -->';
		$violations = $this->by_rule( $this->lint( $markup ), 'no-featured-plan' );
		$this->assertCount( 1, $violations );
		$this->assertSame( '0', $violations[0]['address'] ); // The row container, once.
	}

	public function test_featured_plan_silent_when_one_card_is_emphasized() {
		$markup     = '<!-- wp:columns --><div class="wp-block-columns">'
			. $this->plan_card()
			. $this->plan_card( array( 'style' => array( 'color' => array( 'background' => '#112233' ) ) ) )
			. $this->plan_card()
			. '</div><!-- /wp:columns -->';
		$violations = $this->lint( $markup );
		$this->assertSame( array(), $this->by_rule( $violations, 'no-featured-plan' ) );
	}

	/* -------- text-contrast -------- */

	private function paragraph( $attrs = array(), $text = 'Copy.' ) {
		$json = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		return '<!-- wp:paragraph' . $json . ' --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	private function heading( $attrs = array(), $text = 'Title' ) {
		$json = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		return '<!-- wp:heading' . $json . ' --><h2 class="wp-block-heading">' . $text . '</h2><!-- /wp:heading -->';
	}

	public function test_text_contrast_fires_against_nearest_ancestor_background() {
		$markup     = $this->group(
			array( 'style' => array( 'color' => array( 'background' => '#888888' ) ) ),
			// One unpainted level between — the rule walks UP like a browser paints.
			$this->group(
				array(),
				$this->paragraph( array( 'style' => array( 'color' => array( 'text' => '#777777' ) ) ) )
			)
		);
		$violations = $this->by_rule( $this->lint( $markup ), 'text-contrast' );
		$this->assertCount( 1, $violations );
		$this->assertSame( '0.0.0', $violations[0]['address'] );
		$this->assertSame( 'error', $violations[0]['severity'] );
	}

	public function test_text_contrast_large_text_gets_the_3_to_1_threshold() {
		// #8a8a8a on #ffffff ≈ 3.45:1 — passes large text, fails normal text.
		$markup     = $this->group(
			array( 'style' => array( 'color' => array( 'background' => '#ffffff' ) ) ),
			$this->heading( array( 'style' => array( 'color' => array( 'text' => '#8a8a8a' ) ) ) ) .
			$this->paragraph( array( 'style' => array( 'color' => array( 'text' => '#8a8a8a' ) ) ) )
		);
		$violations = $this->by_rule( $this->lint( $markup ), 'text-contrast' );
		$this->assertCount( 1, $violations, 'Only the paragraph fails; the heading is large text.' );
		$this->assertSame( '0.1', $violations[0]['address'] );
	}

	public function test_text_contrast_silent_on_clean_pairs_unknown_backgrounds_and_buttons() {
		$violations = $this->lint(
			// AA pair.
			$this->group(
				array( 'style' => array( 'color' => array( 'background' => '#1a2b3c' ) ) ),
				$this->paragraph( array( 'style' => array( 'color' => array( 'text' => '#ffffff' ) ) ) )
			) .
			// Text color set but NO background anywhere up the chain — never guessed.
			$this->paragraph( array( 'style' => array( 'color' => array( 'text' => '#777777' ) ) ) ) .
			// A bad button is button-contrast's finding, not a duplicate here.
			$this->button( array( 'style' => array( 'color' => array( 'background' => '#f5f5f5', 'text' => '#ffffff' ) ) ) )
		);
		$this->assertSame( array(), $this->by_rule( $violations, 'text-contrast' ) );
		$this->assertCount( 1, $this->by_rule( $violations, 'button-contrast' ) );
	}

	/* -------- missing-alt-text -------- */

	public function test_missing_alt_fires_on_bare_content_image() {
		$violations = $this->by_rule(
			$this->lint( '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->' ),
			'missing-alt-text'
		);
		$this->assertCount( 1, $violations );
		$this->assertSame( 'warn', $violations[0]['severity'] );
	}

	public function test_missing_alt_silent_on_described_images_and_decorative_media() {
		$violations = $this->lint(
			'<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="x.jpg" alt="A brown dog"/></figure><!-- /wp:image -->' .
			// Covers are background media — decorative by convention.
			'<!-- wp:cover {"url":"x.jpg"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" src="x.jpg"/></div><!-- /wp:cover -->' .
			$this->paragraph()
		);
		$this->assertSame( array(), $this->by_rule( $violations, 'missing-alt-text' ) );
	}

	/* -------- heading-order -------- */

	public function test_heading_order_fires_on_skipped_level_and_second_h1() {
		$markup     = $this->heading( array( 'level' => 1 ), 'Page' ) .
			$this->heading( array(), 'Section' ) .          // h2 — fine.
			$this->heading( array( 'level' => 4 ), 'Deep' ) . // h2 → h4 skips h3.
			$this->heading( array( 'level' => 1 ), 'Again' ); // second h1.
		$violations = $this->by_rule( $this->lint( $markup ), 'heading-order' );
		$this->assertCount( 2, $violations );
		$this->assertSame( '2', $violations[0]['address'] );
		$this->assertSame( '3', $violations[1]['address'] );
	}

	public function test_heading_order_silent_on_clean_outline_and_upward_moves() {
		$violations = $this->lint(
			$this->heading( array( 'level' => 3 ), 'Starts deep' ) . // First heading is never judged.
			$this->heading( array( 'level' => 4 ), 'Child' ) .
			$this->heading( array(), 'Back up' )                     // h4 → h2 is a new section, fine.
		);
		$this->assertSame( array(), $this->by_rule( $violations, 'heading-order' ) );
	}

	public function test_a11y_rules_skip_on_a_base_only_accessor() {
		// The companion feature-detect: a legacy accessor gets zero a11y
		// findings and zero fatals.
		$tree       = Saddle_Tree::parse(
			$this->heading( array( 'level' => 4 ) ) .
			'<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->'
		);
		$violations = Saddle_Lint::run( $tree, new Saddle_Test_Legacy_Accessor() );
		foreach ( array( 'text-contrast', 'missing-alt-text', 'heading-order' ) as $rule ) {
			$this->assertSame( array(), $this->by_rule( $violations, $rule ) );
		}
	}

	/* -------- engine contract -------- */

	public function test_rules_register_through_the_single_filter() {
		add_filter(
			'saddle_lint_rules',
			static function ( $rules ) {
				$rules[] = new class() extends Saddle_Lint_Rule {
					public function id() {
						return 'always-fires';
					}
					public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
						return array( $this->violation( '0', self::SEVERITY_WARN, 'custom', 'fix' ) );
					}
				};
				return $rules;
			}
		);

		$violations = $this->lint( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$this->assertCount( 1, $this->by_rule( $violations, 'always-fires' ) );
	}

	public function test_clean_designed_page_produces_zero_violations() {
		$markup = $this->group(
			array( 'style' => array( 'color' => array( 'background' => '#0a2540' ) ), 'layout' => array( 'type' => 'constrained' ) ),
			'<!-- wp:heading --><h2 class="wp-block-heading">Ship faster</h2><!-- /wp:heading -->' .
			'<!-- wp:paragraph --><p>Copy.</p><!-- /wp:paragraph -->' .
			'<!-- wp:buttons --><div class="wp-block-buttons">'
				. $this->button( array( 'style' => array( 'color' => array( 'background' => '#0a6b3d', 'text' => '#ffffff' ) ) ) )
			. '</div><!-- /wp:buttons -->'
		);
		$this->assertSame( array(), $this->lint( $markup ) );
	}

	/* -------- the ability -------- */

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	public function test_lint_page_ability_reports_violations_on_a_native_page() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading"></h2><!-- /wp:heading -->',
			)
		);

		$result = $this->run_ability( 'lint-page', array( 'post_id' => $id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'native', $result['builder'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( 'empty-title', $result['violations'][0]['rule'] );
	}

	public function test_lint_page_refuses_builder_pages_without_an_accessor() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
			)
		);

		$result = $this->run_ability( 'lint-page', array( 'post_id' => $id ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_lint_unsupported', $result->get_error_code() );
	}

	public function test_lint_page_uses_the_accessor_a_builder_integration_provides() {
		add_filter(
			'saddle_lint_accessor',
			static function ( $accessor, $builder ) {
				return 'Divi 5' === $builder ? new Saddle_Lint_Gutenberg_Accessor() : $accessor;
			},
			10,
			2
		);

		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
			)
		);

		$result = $this->run_ability( 'lint-page', array( 'post_id' => $id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'Divi 5', $result['builder'] );
	}
}
