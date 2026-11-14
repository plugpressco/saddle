<?php
/**
 * The Divi accessors past desktop.value: the Visual Builder's color token,
 * hover, tablet and phone values, the CSS class Divi renders, and the hover
 * contrast rule built on them.
 *
 * Divi's inheritance (a state falls back to value at the same breakpoint, a
 * smaller screen to the next larger one) and its token shape were read from
 * Divi 5.10.1's source, not rendered.
 *
 * @package Saddle
 */

class Saddle_Divi_States_Accessor_Test extends WP_UnitTestCase {

	const TOKEN = '$variable({"type":"color","value":{"name":"%s","settings":%s}})$';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
		add_filter(
			'saddle_divi_global_colors',
			static function () {
				return array(
					'gcid-primary-color' => '#2ea3f2',
					'gcid-ink'           => '#111111',
					'gcid-ink-2'         => '#222222',
					'gcid-alias'         => 'var(--gcid-ink)',
					'gcid-nested'        => sprintf( self::TOKEN, 'gcid-ink', '{}' ),
				);
			}
		);
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_global_colors' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		parent::tear_down();
	}

	private function token( $id, $settings = '{}' ) {
		return sprintf( self::TOKEN, $id, $settings );
	}

	private function text_node( $color ) {
		return Saddle_Divi_Tree::make_module(
			'divi/text',
			array( 'module' => array( 'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => $color ) ) ) ) ) ) )
		);
	}

	/* -------- colors in the Visual Builder's token form -------- */

	public function test_the_visual_builders_color_token_resolves() {
		$accessor = new Saddle_Divi_Lint_Accessor();

		$this->assertSame( '#2ea3f2', $accessor->text_color( $this->text_node( $this->token( 'gcid-primary-color' ) ) ) );
		$this->assertSame( 'rgba(46, 163, 242, 0.4)', $accessor->text_color( $this->text_node( $this->token( 'gcid-primary-color', '{"opacity":40}' ) ) ) );
		$this->assertSame( '#111111', $accessor->text_color( $this->text_node( 'var(--gcid-ink)' ) ) );

		// A color that points at another resolves through the chain.
		$this->assertSame( '#111111', $accessor->text_color( $this->text_node( 'var(--gcid-alias)' ) ) );
		$this->assertSame( '#111111', $accessor->text_color( $this->text_node( $this->token( 'gcid-nested' ) ) ) );

		// Hue or lightness changes, and unknown ids, stay as written: rules skip.
		$shifted = $this->token( 'gcid-ink', '{"lightness":20}' );
		$this->assertSame( $shifted, $accessor->text_color( $this->text_node( $shifted ) ) );
		$this->assertSame( 'var(--gcid-missing)', $accessor->text_color( $this->text_node( 'var(--gcid-missing)' ) ) );
	}

	public function test_text_contrast_no_longer_skips_token_colors() {
		$attrs  = array(
			'module' => array(
				'decoration' => array(
					'background' => array( 'desktop' => array( 'value' => array( 'color' => $this->token( 'gcid-ink' ) ) ) ),
					'font'       => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => $this->token( 'gcid-ink-2' ) ) ) ) ),
				),
			),
		);
		$markup = '<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column -->'
			. sprintf( '<!-- wp:divi/text %s --><p>Copy.</p><!-- /wp:divi/text -->', wp_json_encode( $attrs ) )
			. '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';

		$violations = Saddle_Lint::run( Saddle_Tree::parse( $markup ), new Saddle_Divi_Lint_Accessor() );
		$rules      = wp_list_pluck( $violations, 'rule' );
		$this->assertContains( 'text-contrast', $rules, '#222222 on #111111, both written as tokens, is judged.' );
	}

	/* -------- the CSS class Divi renders -------- */

	public function test_css_class_is_read_where_divi_renders_it() {
		$accessor = new Saddle_Divi_Lint_Accessor();

		$rendered = Saddle_Divi_Tree::make_module( 'divi/text', array( 'module' => array( 'advanced' => array( 'htmlAttributes' => array( 'desktop' => array( 'value' => array( 'class' => ' hero-band ' ) ) ) ) ) ) );
		$this->assertSame( 'hero-band', $accessor->css_class( $rendered ) );

		$never = Saddle_Divi_Tree::make_module( 'divi/text', array( 'module' => array( 'advanced' => array( 'html' => array( 'desktop' => array( 'value' => array( 'class' => 'hero-band' ) ) ) ) ) ) );
		$this->assertNull( $accessor->css_class( $never ) );
	}

	/* -------- states and breakpoints, inherited like Divi -------- */

	public function test_state_and_breakpoint_reads_inherit_the_way_divi_does() {
		$accessor = new Saddle_Divi_Lint_Accessor();
		$node     = Saddle_Divi_Tree::make_module(
			'divi/text',
			array(
				'module' => array(
					'decoration' => array(
						'background' => array(
							'desktop' => array(
								'value' => array( 'color' => '#ffffff' ),
								'hover' => array( 'color' => '#f1f5f9' ),
							),
							'tablet'  => array( 'value' => array( 'color' => '#eeeeee' ) ),
						),
						'font'       => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => '#111111' ) ) ) ),
					),
				),
			)
		);

		$this->assertSame( '#ffffff', $accessor->background_color_at( $node ) );
		$this->assertSame( '#f1f5f9', $accessor->background_color_at( $node, 'desktop', 'hover' ) );
		$this->assertSame( '#eeeeee', $accessor->background_color_at( $node, 'tablet' ) );
		$this->assertSame( '#eeeeee', $accessor->background_color_at( $node, 'phone' ), 'Phone shows the tablet value.' );
		$this->assertSame( '#111111', $accessor->text_color_at( $node, 'desktop', 'hover' ), 'Hover with no color of its own shows the value.' );
		$this->assertSame( '#111111', $accessor->text_color_at( $node, 'phone' ) );

		$this->assertTrue( $accessor->sets_state_color( $node, 'hover' ) );
		$this->assertFalse( $accessor->sets_state_color( $node, 'sticky' ) );
	}

	public function test_render_styles_list_what_changes_on_tablet_phone_and_hover() {
		$accessor = new Saddle_Divi_Render_Accessor();
		$node     = Saddle_Divi_Tree::make_module(
			'divi/text',
			array(
				'module' => array(
					'decoration' => array(
						'background' => array(
							'desktop' => array(
								'value' => array( 'color' => '#2563eb' ),
								'hover' => array( 'color' => '#1e4fd0' ),
							),
						),
						'font'       => array(
							'font' => array(
								'desktop' => array( 'value' => array( 'size' => '48px' ) ),
								'tablet'  => array( 'value' => array( 'size' => '36px' ) ),
							),
						),
						'spacing'    => array(
							'desktop' => array(
								'value' => array(
									'padding' => array(
										'top'    => '96px',
										'bottom' => '96px',
									),
								),
							),
							'phone'   => array( 'value' => array( 'padding' => array( 'top' => '48px' ) ) ),
						),
					),
				),
			)
		);

		$styles = $accessor->effective_styles( $node );

		// Desktop stays at the top level, as before.
		$this->assertSame( '48px', $styles['fontSize'] );
		$this->assertSame(
			array(
				'top'    => '96px',
				'bottom' => '96px',
			),
			$styles['padding']
		);

		$this->assertSame( array( 'fontSize' => '36px' ), $styles['tablet'] );
		$this->assertSame(
			array(
				'padding'  => array(
					'top'    => '48px',
					'bottom' => '96px',
				),
				'fontSize' => '36px',
			),
			$styles['phone'],
			'Phone merges its own top padding over the desktop padding and inherits the tablet size.'
		);
		$this->assertSame( array( 'background' => '#1e4fd0' ), $styles['hover'] );

		// A node that styles only desktop lists nothing else.
		$flat = $accessor->effective_styles( $this->text_node( '#111111' ) );
		$this->assertSame( array( 'color' => '#111111' ), $flat );
	}

	/* -------- hover contrast -------- */

	private function hover_violations( array $modules ) {
		$markup = '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}}}}} --><!-- wp:divi/row --><!-- wp:divi/column -->';
		foreach ( $modules as $type => $attrs ) {
			$type    = preg_replace( '/#\d+$/', '', $type );
			$markup .= sprintf( '<!-- wp:%1$s %2$s --><!-- /wp:%1$s -->', $type, wp_json_encode( $attrs ) );
		}
		$markup .= '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';

		$rule = new Saddle_Divi_Lint_Rule_Hover_Contrast();
		return $rule->check( Saddle_Lint::nodes( Saddle_Tree::parse( $markup ) ), new Saddle_Divi_Lint_Accessor() );
	}

	private function button( array $hover_background ) {
		return array(
			'button' => array(
				'decoration' => array(
					'button'     => array( 'desktop' => array( 'value' => array( 'enable' => 'on' ) ) ),
					'background' => array( 'desktop' => array_merge( array( 'value' => array( 'color' => '#2563eb' ) ), $hover_background ) ),
					'font'       => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => '#ffffff' ) ) ) ),
				),
			),
		);
	}

	public function test_hover_contrast_judges_the_hover_pair() {
		$bad = $this->hover_violations( array( 'divi/button' => $this->button( array( 'hover' => array( 'color' => '#dbeafe' ) ) ) ) );
		$this->assertCount( 1, $bad );
		$this->assertSame( 'hover-contrast', ( new Saddle_Divi_Lint_Rule_Hover_Contrast() )->id() );
		$this->assertSame( 'error', $bad[0]['severity'] );
		$this->assertSame( '0.0.0.0', $bad[0]['address'] );
		$this->assertStringContainsString( 'On hover, text #ffffff on background #dbeafe', $bad[0]['message'] );

		// A dark hover keeps AA; no hover, or a hover equal to rest, is not this rule's business.
		$this->assertSame( array(), $this->hover_violations( array( 'divi/button' => $this->button( array( 'hover' => array( 'color' => '#1e40af' ) ) ) ) ) );
		$this->assertSame( array(), $this->hover_violations( array( 'divi/button' => $this->button( array() ) ) ) );
		$this->assertSame( array(), $this->hover_violations( array( 'divi/button' => $this->button( array( 'hover' => array( 'color' => '#2563eb' ) ) ) ) ) );
	}

	public function test_hover_contrast_uses_the_large_text_minimum_for_headings() {
		$heading = static function ( $hover ) {
			return array(
				'title' => array(
					'innerContent' => array( 'desktop' => array( 'value' => 'Plans' ) ),
					'decoration'   => array(
						'font' => array(
							'font' => array(
								'desktop' => array(
									'value' => array( 'color' => '#111111' ),
									'hover' => array( 'color' => $hover ),
								),
							),
						),
					),
				),
			);
		};

		// #8a8a8a on white is about 3.5:1: below 4.5, above the 3:1 for large text.
		$this->assertSame( array(), $this->hover_violations( array( 'divi/heading' => $heading( '#8a8a8a' ) ) ) );
		$this->assertCount( 1, $this->hover_violations( array( 'divi/heading' => $heading( '#cccccc' ) ) ) );
	}
}
