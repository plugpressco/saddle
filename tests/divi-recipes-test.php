<?php
/**
 * Section recipes + the divi-build-page skill body.
 *
 * The recipes must be exemplary — they are the blueprints agents copy: every
 * body expands and validates, purpose-built modules replace hand-stacks
 * (accordion for FAQ, pricing-tables for pricing, divi/cta for the band),
 * the hero models width-cap-AND-center, exactly one pricing plan is
 * featured, and buttons use the real {text, linkUrl} contract. The skill
 * body must teach that same contract (the shipped example used to teach a
 * broken one) and embed free's design numbers verbatim (single source).
 *
 * @package Saddle
 */

class Saddle_Divi_Recipes_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		parent::tear_down();
	}

	private const RECIPES = array( 'hero', 'features', 'pricing', 'testimonials', 'cta', 'faq' );

	public function test_every_recipe_expands_and_validates() {
		foreach ( self::RECIPES as $name ) {
			$nodes = Saddle_Divi_Recipes::tree( $name );
			$this->assertIsArray( $nodes, "Recipe {$name} must exist." );

			$tree = Saddle_Divi_Author::expand( $nodes );
			$this->assertNotWPError( $tree, "Recipe {$name} must expand." );
			$this->assertTrue( Saddle_Divi_Tree::validate( $tree ), "Recipe {$name} must validate." );
		}
	}

	public function test_recipes_use_purpose_built_modules() {
		$flat = static function ( $name ) {
			return wp_json_encode( Saddle_Divi_Recipes::tree( $name ), JSON_UNESCAPED_SLASHES );
		};

		$this->assertStringContainsString( 'divi/cta', $flat( 'cta' ), 'The CTA band is the purpose-built module, not a hand-stack.' );
		$this->assertStringContainsString( 'divi/accordion-item', $flat( 'faq' ), 'FAQ entries are real accordion items, editable as such.' );
		$this->assertStringContainsString( 'divi/pricing-table', $flat( 'pricing' ) );
		$this->assertStringContainsString( 'divi/testimonial', $flat( 'testimonials' ) );
		$this->assertStringContainsString( 'divi/blurb', $flat( 'features' ) );
		$this->assertStringNotContainsString( '<strong>$', $flat( 'pricing' ), 'No fake HTML price headings.' );
	}

	public function test_hero_models_the_centered_width_cap_and_button_contract() {
		$json = wp_json_encode( Saddle_Divi_Recipes::tree( 'hero' ), JSON_UNESCAPED_SLASHES );

		// The pinned-left regression: a capped column is ALSO centered.
		$this->assertStringContainsString( '"maxWidth":"640px"', $json );
		$this->assertStringContainsString( '"alignment":"center"', $json );

		// Primary filled (enable on, token background), secondary quiet.
		$this->assertStringContainsString( '"button.decoration.button.desktop.value.enable":"on"', $json );
		$this->assertStringContainsString( 'var(--gcid-primary-color)', $json );

		// The real button content contract.
		$this->assertStringContainsString( '"linkUrl"', $json );
		$this->assertStringNotContainsString( '"url"', $json );

		// The catalog promise: primary + secondary call to action.
		$this->assertSame( 2, substr_count( $json, 'divi/button' ) );
	}

	public function test_exactly_one_pricing_plan_is_featured() {
		$json = wp_json_encode( Saddle_Divi_Recipes::tree( 'pricing' ) );
		$this->assertSame( 1, substr_count( $json, 'featured' ), 'Feature ONE plan — never three identical cards.' );
	}

	public function test_sections_carry_the_shared_rhythm() {
		foreach ( self::RECIPES as $name ) {
			$json = wp_json_encode( Saddle_Divi_Recipes::tree( $name ) );
			$this->assertStringContainsString( '"top":"96px"', $json, "Recipe {$name} must carry the 96px section rhythm." );
		}
	}

	/* -------- the skill body -------- */

	private function skill_body() {
		$skills = apply_filters( 'saddle_builtin_skills', array() );
		foreach ( $skills as $skill ) {
			if ( 'divi-build-page' === $skill['name'] ) {
				return $skill['body'];
			}
		}
		$this->fail( 'The divi-build-page skill must be bundled while Divi is active.' );
	}

	public function test_skill_teaches_the_real_contracts() {
		$body = $this->skill_body();

		// The worked example used to teach fields {text, url} — a shape that
		// renders an empty button. It must teach the real one.
		$this->assertStringContainsString( '"button": { "text": "Shop Now", "linkUrl": "/shop" }', $body );
		$this->assertStringNotContainsString( '"url": "/shop"', $body );

		// The prefix is NOT optional.
		$this->assertStringNotContainsString( 'prefix optional', $body );
		$this->assertStringContainsString( 'never bare "text"', $body );

		// Placeholders survive to the agent (the wp_kses regression lives in
		// free; this pins the round-trip end to end).
		$this->assertStringContainsString( 'type=<module>', $body );
		$this->assertStringContainsString( 'post_id=<id>', $body );
	}

	public function test_skill_teaches_composition_and_the_stop_rebuilding_rule() {
		$body = $this->skill_body();

		$this->assertStringContainsString( '## Composition', $body );
		$this->assertStringContainsString( 'pinned-max-width', $body );
		$this->assertStringContainsString( 'single-column-flow', $body );
		$this->assertStringContainsString( 'NEVER set theme CSS classes', $body );
		$this->assertStringContainsString( 'FIRST build only', $body );
	}

	public function test_skill_embeds_frees_design_numbers_verbatim() {
		$body = $this->skill_body();

		// One source of truth: free's design_numbers() lines appear verbatim
		// (free ships the centering clause; drift between the two design
		// bars is what this pins against).
		$found = 0;
		foreach ( Saddle_Context::design_numbers() as $line ) {
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( false !== strpos( $body, $line ) ) {
				++$found;
			}
		}
		$this->assertGreaterThanOrEqual( 4, $found, 'The shared design bar must ride the skill verbatim.' );
		$this->assertStringContainsString( 'CENTER a width-capped text column', $body );
	}
}
