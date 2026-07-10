<?php
/**
 * The Saddle_Lint_Style_Accessor companion interface + Gutenberg impl.
 *
 * Pins the closed-loop foundation (https://github.com/plugpressco/saddle/issues/23):
 * every companion getter both ways — the populated case resolves and the
 * unknown/not-applicable case returns null (rules skip, never guess) — plus
 * the versioning guarantee the companion design exists for: an accessor that
 * implements ONLY the base interface still runs through the engine with zero
 * fatals.
 *
 * @package Saddle
 */

class Saddle_Style_Accessor_Test extends WP_UnitTestCase {

	/**
	 * The accessor under test.
	 *
	 * @var Saddle_Lint_Gutenberg_Accessor
	 */
	private $accessor;

	public function set_up() {
		parent::set_up();
		$this->accessor = new Saddle_Lint_Gutenberg_Accessor();
	}

	/* -------- helpers -------- */

	private function node( $markup ) {
		$nodes = Saddle_Lint::nodes( Saddle_Tree::parse( $markup ) );
		$this->assertNotEmpty( $nodes, 'Fixture markup must parse to at least one node.' );
		return $nodes[0]['block'];
	}

	private function group_node( array $attrs ) {
		$json = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		return $this->node( '<!-- wp:group' . $json . ' --><div class="wp-block-group"></div><!-- /wp:group -->' );
	}

	/* -------- the companion contract -------- */

	public function test_gutenberg_accessor_implements_both_interfaces() {
		$this->assertInstanceOf( 'Saddle_Lint_Accessor', $this->accessor );
		$this->assertInstanceOf( 'Saddle_Lint_Style_Accessor', $this->accessor );
	}

	public function test_engine_runs_with_a_base_only_accessor_without_fatal() {
		// The whole point of the companion split: an accessor that has not
		// caught up (an older Pro) must keep working against a newer engine.
		$legacy = new Saddle_Test_Legacy_Accessor();
		$this->assertNotInstanceOf( 'Saddle_Lint_Style_Accessor', $legacy );

		$tree       = Saddle_Tree::parse( '<!-- wp:heading --><h2 class="wp-block-heading">Hi</h2><!-- /wp:heading -->' );
		$violations = Saddle_Lint::run( $tree, $legacy );
		$this->assertIsArray( $violations, 'A base-only accessor must run clean through the engine.' );
	}

	/* -------- border_radius -------- */

	public function test_border_radius_string_form() {
		$node = $this->group_node( array( 'style' => array( 'border' => array( 'radius' => '8px' ) ) ) );
		$this->assertSame( '8px', $this->accessor->border_radius( $node ) );
	}

	public function test_border_radius_corner_form_serializes_clockwise() {
		$node = $this->group_node(
			array(
				'style' => array(
					'border' => array(
						'radius' => array(
							'topLeft'     => '8px',
							'topRight'    => '8px',
							'bottomRight' => '0px',
							'bottomLeft'  => '0px',
						),
					),
				),
			)
		);
		$this->assertSame( '8px 8px 0px 0px', $this->accessor->border_radius( $node ) );
	}

	public function test_border_radius_null_when_unset() {
		$this->assertNull( $this->accessor->border_radius( $this->group_node( array() ) ) );
	}

	/* -------- gap -------- */

	public function test_gap_string_form() {
		$node = $this->group_node( array( 'style' => array( 'spacing' => array( 'blockGap' => '24px' ) ) ) );
		$this->assertSame( '24px', $this->accessor->gap( $node ) );
	}

	public function test_gap_axis_form_joins_when_different_collapses_when_equal() {
		$differs = $this->group_node(
			array(
				'style' => array(
					'spacing' => array(
						'blockGap' => array(
							'top'  => '16px',
							'left' => '32px',
						),
					),
				),
			)
		);
		$this->assertSame( '16px 32px', $this->accessor->gap( $differs ) );

		$same = $this->group_node(
			array(
				'style' => array(
					'spacing' => array(
						'blockGap' => array(
							'top'  => '16px',
							'left' => '16px',
						),
					),
				),
			)
		);
		$this->assertSame( '16px', $this->accessor->gap( $same ) );
	}

	public function test_gap_null_when_unset() {
		$this->assertNull( $this->accessor->gap( $this->group_node( array() ) ) );
	}

	/* -------- font_size -------- */

	public function test_font_size_raw_value() {
		$node = $this->node( '<!-- wp:paragraph {"style":{"typography":{"fontSize":"18px"}}} --><p>x</p><!-- /wp:paragraph -->' );
		$this->assertSame( '18px', $this->accessor->font_size( $node ) );
	}

	public function test_font_size_preset_slug_resolves_and_unknown_is_null() {
		// "large" ships in core's default theme.json scale, on every install.
		$known = $this->node( '<!-- wp:paragraph {"fontSize":"large"} --><p>x</p><!-- /wp:paragraph -->' );
		$size  = $this->accessor->font_size( $known );
		$this->assertIsString( $size );
		$this->assertNotSame( '', $size );

		$unknown = $this->node( '<!-- wp:paragraph {"fontSize":"no-such-size"} --><p>x</p><!-- /wp:paragraph -->' );
		$this->assertNull( $this->accessor->font_size( $unknown ) );
	}

	public function test_font_size_null_when_unset() {
		$node = $this->node( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$this->assertNull( $this->accessor->font_size( $node ) );
	}

	/* -------- image_alt -------- */

	public function test_image_alt_returns_text_when_present() {
		$node = $this->node( '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="x.jpg" alt="A brown dog"/></figure><!-- /wp:image -->' );
		$this->assertSame( 'A brown dog', $this->accessor->image_alt( $node ) );
	}

	public function test_image_alt_empty_string_when_missing_or_blank() {
		$missing = $this->node( '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->' );
		$this->assertSame( '', $this->accessor->image_alt( $missing ) );

		$blank = $this->node( '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="x.jpg" alt=""/></figure><!-- /wp:image -->' );
		$this->assertSame( '', $this->accessor->image_alt( $blank ) );
	}

	public function test_image_alt_null_for_non_images_and_empty_image_blocks() {
		$paragraph = $this->node( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$this->assertNull( $this->accessor->image_alt( $paragraph ) );

		// Covers are background media — decorative by convention, never nagged.
		$cover = $this->node( '<!-- wp:cover {"url":"x.jpg"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" src="x.jpg"/></div><!-- /wp:cover -->' );
		$this->assertNull( $this->accessor->image_alt( $cover ) );

		// An image block with no image selected yet has nothing to judge.
		$placeholder = $this->node( '<!-- wp:image --><figure class="wp-block-image"></figure><!-- /wp:image -->' );
		$this->assertNull( $this->accessor->image_alt( $placeholder ) );
	}

	/* -------- heading_level -------- */

	public function test_heading_level_default_and_explicit() {
		$h2 = $this->node( '<!-- wp:heading --><h2 class="wp-block-heading">x</h2><!-- /wp:heading -->' );
		$this->assertSame( 2, $this->accessor->heading_level( $h2 ) );

		$h4 = $this->node( '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">x</h4><!-- /wp:heading -->' );
		$this->assertSame( 4, $this->accessor->heading_level( $h4 ) );
	}

	public function test_heading_level_null_for_non_headings() {
		$node = $this->node( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$this->assertNull( $this->accessor->heading_level( $node ) );
	}

	/* -------- variable_refs -------- */

	public function test_variable_refs_finds_css_vars_and_internal_preset_form() {
		$node = $this->group_node(
			array(
				'style' => array(
					'color'   => array( 'background' => 'var(--wp--preset--color--primary)' ),
					'spacing' => array( 'padding' => array( 'top' => 'var:preset|spacing|50' ) ),
				),
			)
		);
		$refs = $this->accessor->variable_refs( $node );
		$this->assertContains( '--wp--preset--color--primary', $refs );
		$this->assertContains( '--wp--preset--spacing--50', $refs );
	}

	public function test_variable_refs_empty_when_none() {
		$this->assertSame( array(), $this->accessor->variable_refs( $this->group_node( array() ) ) );
	}

	/* -------- the free nulls: preset ref, brief, computed style -------- */

	public function test_free_accessor_null_facts() {
		$node = $this->group_node( array() );
		$this->assertNull( $this->accessor->global_preset_ref( $node ), 'Gutenberg has no user-editable global preset entity.' );
		$this->assertNull( $this->accessor->design_brief(), 'Free pages have no committed brief store.' );
		$this->assertNull( $this->accessor->computed_style( $node ), 'Tree-only accessor: render fills this seam later.' );
	}
}

/**
 * A base-only accessor, as an older Pro build would ship: implements
 * Saddle_Lint_Accessor but NOT the companion. Used to prove the engine keeps
 * running against it.
 */
class Saddle_Test_Legacy_Accessor implements Saddle_Lint_Accessor {

	public function background_color( array $node ) {
		return null;
	}

	public function text_color( array $node ) {
		return null;
	}

	public function is_button( array $node ) {
		return false;
	}

	public function button_is_filled( array $node ) {
		return true;
	}

	public function alignment( array $node ) {
		return null;
	}

	public function padding( array $node ) {
		return null;
	}

	public function title_text( array $node ) {
		return null;
	}
}
