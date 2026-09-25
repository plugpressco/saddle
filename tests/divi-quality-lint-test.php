<?php
/**
 * The quality judgments + the design brief (saddle-pro#10).
 *
 * Pins the paid depth of the closed loop: the Divi accessor's companion
 * facts read real canonical shapes, the brief round-trips through its
 * abilities with validation, and each judgment rule fires on its bad
 * fixture while staying SILENT on the clean one — the false-positive guard
 * is the whole point (a lint that cries wolf gets ignored). Briefless pages
 * get zero brief findings, one lonely tell never triggers monotony, and a
 * preset without overrides is never entanglement.
 *
 * @package Saddle
 */

class Saddle_Divi_Quality_Lint_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'read' );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function module_markup( $type, array $attrs, $inner_html = '' ) {
		return sprintf( '<!-- wp:%1$s %2$s -->%3$s<!-- /wp:%1$s -->', $type, wp_json_encode( $attrs ), $inner_html );
	}

	private function divi_page( array $column_modules ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array_merge(
						array( '<!-- wp:divi/section -->', '<!-- wp:divi/row -->', '<!-- wp:divi/column -->' ),
						$column_modules,
						array( '<!-- /wp:divi/column -->', '<!-- /wp:divi/row -->', '<!-- /wp:divi/section -->' )
					)
				),
			)
		);
	}

	private function text_module( $bg, array $extra_decoration = array() ) {
		return $this->module_markup(
			'divi/text',
			array(
				'module' => array(
					'decoration' => array_merge(
						array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => $bg ) ) ) ),
						$extra_decoration
					),
				),
			),
			'<p>Copy.</p>'
		);
	}

	private function lint( $post_id ) {
		return wp_get_ability( 'saddle/lint-page' )->execute( array( 'post_id' => $post_id ) );
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

	/* -------- companion facts off canonical shapes -------- */

	public function test_companion_facts_read_canonical_divi_shapes() {
		$accessor = new Saddle_Divi_Lint_Accessor();

		$node = Saddle_Divi_Tree::make_module(
			'divi/blurb',
			array(
				'modulePreset' => array( 'preset-abc' ),
				'module'       => array(
					'decoration' => array(
						'border' => array(
							'desktop' => array(
								'value' => array(
									'radius' => array(
										'topLeft'     => '8px',
										'topRight'    => '8px',
										'bottomRight' => '8px',
										'bottomLeft'  => '8px',
									),
								),
							),
						),
						'font'   => array(
							'font' => array(
								'desktop' => array(
									'value' => array(
										'size'  => '18px',
										'color' => 'var(--gcid-primary)',
									),
								),
							),
						),
					),
				),
			)
		);

		$this->assertSame( '8px 8px 8px 8px', $accessor->border_radius( $node ) );
		$this->assertSame( '18px', $accessor->font_size( $node ) );
		$this->assertSame( 'preset-abc', $accessor->global_preset_ref( $node ) );
		$this->assertContains( '--gcid-primary', $accessor->variable_refs( $node ) );

		$heading = Saddle_Divi_Tree::make_module(
			'divi/heading',
			array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'headingLevel' => 'h4' ) ) ) ) ) ) )
		);
		$this->assertSame( 4, $accessor->heading_level( $heading ) );
		$this->assertSame( 2, $accessor->heading_level( Saddle_Divi_Tree::make_module( 'divi/heading' ) ), 'divi/heading defaults to h2 like the builder.' );

		$image = Saddle_Divi_Tree::make_module(
			'divi/image',
			array( 'image' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'src' => 'x.jpg' ) ) ) ) )
		);
		$this->assertSame( '', $accessor->image_alt( $image ), 'A placed image without alt is the lint.' );
		$this->assertNull( $accessor->image_alt( Saddle_Divi_Tree::make_module( 'divi/image' ) ), 'No image selected — nothing to judge.' );
	}

	/* -------- the brief: abilities + validation -------- */

	/* -------- design-brief-conformance -------- */

	/* -------- sibling-monotony -------- */

	private function card( $radius = '8px', $padding = '24px', $align = 'center' ) {
		return $this->module_markup(
			'divi/blurb',
			array(
				'module' => array(
					'decoration' => array(
						'border'  => array( 'desktop' => array( 'value' => array( 'radius' => array( 'topLeft' => $radius ) ) ) ),
						'spacing' => array( 'desktop' => array( 'value' => array( 'padding' => array( 'top' => $padding ) ) ) ),
						'sizing'  => array( 'desktop' => array( 'value' => array( 'alignment' => $align ) ) ),
					),
				),
			)
		);
	}

	public function test_monotony_fires_once_on_three_identical_centered_cards() {
		$id     = $this->divi_page( array( $this->card(), $this->card(), $this->card() ) );
		$result = $this->lint( $id );

		$findings = $this->by_rule( $result['violations'], 'sibling-monotony' );
		$this->assertCount( 1, $findings, 'One advisory per group, not one per card.' );
		$this->assertSame( '0.0.0', $findings[0]['address'], 'The finding lands on the container.' );
		$this->assertSame( 'warn', $findings[0]['severity'] );
	}

	public function test_monotony_silent_when_any_tell_breaks() {
		// One card differs in radius — sameness is broken, design intent shown.
		$varied = $this->divi_page( array( $this->card(), $this->card( '0px' ), $this->card() ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $varied )['violations'], 'sibling-monotony' ) );

		// Left-aligned copy — the center-everything reflex is absent.
		$aligned = $this->divi_page( array( $this->card( '8px', '24px', 'left' ), $this->card( '8px', '24px', 'left' ), $this->card( '8px', '24px', 'left' ) ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $aligned )['violations'], 'sibling-monotony' ) );

		// Only two cards — no row to judge.
		$pair = $this->divi_page( array( $this->card(), $this->card() ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $pair )['violations'], 'sibling-monotony' ) );

		// Unstyled trio — the theme is styling them; identical-null is not a tell.
		$unstyled = $this->divi_page(
			array(
				$this->module_markup( 'divi/text', array(), '<p>a</p>' ),
				$this->module_markup( 'divi/text', array(), '<p>b</p>' ),
				$this->module_markup( 'divi/text', array(), '<p>c</p>' ),
			)
		);
		$this->assertSame( array(), $this->by_rule( $this->lint( $unstyled )['violations'], 'sibling-monotony' ) );
	}

	/* -------- preset-coupling -------- */

	public function test_preset_coupling_fires_on_preset_plus_local_override() {
		$id = $this->divi_page(
			array(
				$this->module_markup(
					'divi/text',
					array(
						'modulePreset' => array( 'preset-xyz' ),
						'module'       => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#112233' ) ) ) ) ),
					),
					'<p>Welded.</p>'
				),
			)
		);

		$findings = $this->by_rule( $this->lint( $id )['violations'], 'preset-coupling' );
		$this->assertCount( 1, $findings );
		$this->assertStringContainsString( 'preset-xyz', $findings[0]['message'] );
	}

	public function test_preset_coupling_silent_on_clean_bindings() {
		// Preset with no local overrides — the design system working as intended.
		$preset_only = $this->divi_page(
			array( $this->module_markup( 'divi/text', array( 'modulePreset' => array( 'preset-xyz' ) ), '<p>Clean.</p>' ) )
		);
		$this->assertSame( array(), $this->by_rule( $this->lint( $preset_only )['violations'], 'preset-coupling' ) );

		// Local styling with no preset — honestly standalone.
		$standalone = $this->divi_page( array( $this->text_module( '#112233' ) ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $standalone )['violations'], 'preset-coupling' ) );
	}

	/* -------- the loop: a11y rules now see Divi -------- */

	public function test_a11y_rules_fire_on_divi_through_the_companion_accessor() {
		$id = $this->divi_page(
			array(
				$this->module_markup(
					'divi/image',
					array( 'image' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'src' => 'x.jpg' ) ) ) ) )
				),
			)
		);

		$findings = $this->by_rule( $this->lint( $id )['violations'], 'missing-alt-text' );
		$this->assertCount( 1, $findings, 'The free a11y rules judge Divi now that the accessor implements the companion.' );
	}

	/* -------- pinned-max-width -------- */

	private function sized_text( array $sizing, array $spacing = array() ) {
		$decoration = array( 'sizing' => array( 'desktop' => array( 'value' => $sizing ) ) );
		if ( $spacing ) {
			$decoration['spacing'] = array( 'desktop' => array( 'value' => $spacing ) );
		}
		return $this->module_markup(
			'divi/text',
			array( 'module' => array( 'decoration' => $decoration ) ),
			'<p>Running copy capped for line length.</p>'
		);
	}

	public function test_pinned_max_width_fires_on_uncentered_cap() {
		$id       = $this->divi_page( array( $this->sized_text( array( 'maxWidth' => '640px' ) ) ) );
		$findings = $this->by_rule( $this->lint( $id )['violations'], 'pinned-max-width' );

		$this->assertCount( 1, $findings );
		$this->assertSame( '0.0.0.0', $findings[0]['address'] );
		$this->assertSame( 'warn', $findings[0]['severity'] );
		$this->assertStringContainsString( 'alignment', $findings[0]['fix_hint'], 'The fix hint must carry the exact attr.' );
	}

	public function test_pinned_max_width_silent_on_centered_deliberate_or_uncapped() {
		// Centered.
		$centered = $this->divi_page( array( $this->sized_text( array( 'maxWidth' => '640px', 'alignment' => 'center' ) ) ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $centered )['violations'], 'pinned-max-width' ) );

		// Explicit left = a deliberate choice.
		$left = $this->divi_page( array( $this->sized_text( array( 'maxWidth' => '640px', 'alignment' => 'left' ) ) ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $left )['violations'], 'pinned-max-width' ) );

		// Auto side margins center without alignment.
		$auto = $this->divi_page(
			array( $this->sized_text( array( 'maxWidth' => '640px' ), array( 'margin' => array( 'left' => 'auto', 'right' => 'auto' ) ) ) )
		);
		$this->assertSame( array(), $this->by_rule( $this->lint( $auto )['violations'], 'pinned-max-width' ) );

		// No cap, no finding.
		$uncapped = $this->divi_page( array( $this->text_module( '#ffffff' ) ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $uncapped )['violations'], 'pinned-max-width' ) );
	}

	/* -------- theme-css-class -------- */

	public function test_theme_css_class_fires_on_delegated_styling() {
		$id = $this->divi_page(
			array(
				$this->module_markup(
					'divi/text',
					array( 'module' => array( 'advanced' => array( 'html' => array( 'desktop' => array( 'value' => array( 'class' => 'mesh sky dt-mp-demo' ) ) ) ) ) ),
					'<p>Copy.</p>'
				),
			)
		);

		$findings = $this->by_rule( $this->lint( $id )['violations'], 'theme-css-class' );
		$this->assertCount( 1, $findings );
		$this->assertStringContainsString( 'mesh sky dt-mp-demo', $findings[0]['message'] );
		$this->assertStringContainsString( 'Unless the user named', $findings[0]['fix_hint'] );
	}

	public function test_theme_css_class_silent_without_classes() {
		$id = $this->divi_page( array( $this->text_module( '#ffffff' ) ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $id )['violations'], 'theme-css-class' ) );
	}

	/* -------- unknown-module -------- */

	public function test_unknown_module_fires_only_with_a_populated_index() {
		// With NO schema index (this suite seeds none), the rule must stay
		// silent — an empty index proves nothing.
		$page = $this->divi_page( array( '<!-- wp:divi/definitely-not-real --><!-- /wp:divi/definitely-not-real -->' ) );
		$this->assertSame( array(), $this->by_rule( $this->lint( $page )['violations'], 'unknown-module' ) );

		// Seed an index containing one known type: the typo'd type becomes
		// an error, the known type and the structural chrome stay silent.
		$dir        = get_temp_dir() . 'saddle-pro-quality-fixture-modules';
		$module_dir = $dir . '/known-widget';
		if ( ! is_dir( $module_dir ) ) {
			mkdir( $module_dir, 0755, true );
		}
		file_put_contents(
			$module_dir . '/module.json',
			wp_json_encode(
				array(
					'name'       => 'saddletest/known-widget',
					'title'      => 'Known Widget',
					'category'   => 'module',
					'attributes' => array( 'module' => array( 'type' => 'object' ) ),
				)
			)
		);
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );

		$mixed    = $this->divi_page(
			array(
				'<!-- wp:saddletest/known-widget --><!-- /wp:saddletest/known-widget -->',
				'<!-- wp:divi/definitely-not-real --><!-- /wp:divi/definitely-not-real -->',
			)
		);
		$findings = $this->by_rule( $this->lint( $mixed )['violations'], 'unknown-module' );

		remove_all_filters( 'saddle_divi_module_json_dirs' );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );

		$this->assertCount( 1, $findings, 'Only the unknown type fires — known modules and structural chrome stay silent.' );
		$this->assertSame( 'error', $findings[0]['severity'] );
		$this->assertStringContainsString( 'divi/definitely-not-real', $findings[0]['message'] );
		$this->assertStringContainsString( 'divi-list-modules', $findings[0]['fix_hint'] );
	}

	/* -------- cross-repo composition pairing -------- */

	public function test_single_column_flow_fires_on_a_divi_doc_syndrome_page() {
		// The exact failure shape observed live: one section, a long run of
		// single-column rows each holding one module. Free Saddle's
		// builder-agnostic rule must fire through the Divi accessor pairing.
		$rows = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$rows[] = '<!-- wp:divi/row --><!-- wp:divi/column -->'
				. '<!-- wp:divi/text --><p>Paragraph ' . $i . '.</p><!-- /wp:divi/text -->'
				. '<!-- /wp:divi/column --><!-- /wp:divi/row -->';
		}
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/section -->' . implode( "\n", $rows ) . '<!-- /wp:divi/section -->',
			)
		);

		$findings = $this->by_rule( $this->lint( $id )['violations'], 'single-column-flow' );
		$this->assertCount( 1, $findings, 'One advisory per run at the section, not one per row.' );
		$this->assertSame( '0', $findings[0]['address'] );
		$this->assertStringContainsString( '12 consecutive', $findings[0]['message'] );
	}
}
