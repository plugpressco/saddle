<?php
/**
 * The design checks added for Divi 5 pages (phase 7.4).
 *
 * Each rule fires on its bad case and stays silent on the clean one; the
 * last tests run every rule over the suite's clean pages and the built-in
 * Divi recipes, where the new rules must add nothing. Trees are built as
 * raw blocks here, so the test does not depend on the authoring format.
 *
 * @package Saddle
 */

class Saddle_Divi_Design_Lint_Test extends WP_UnitTestCase {

	const NEW_RULES = array( 'one-main-action', 'font-families', 'button-hover', 'vanishing-fill', 'emoji-icon', 'code-layout', 'nested-card', 'grid-phone-columns', 'collapsed-column', 'repeated-row-layout' );

	/* -------- helpers -------- */

	/**
	 * A raw Divi block; dotted attr keys are expanded.
	 */
	private function m( $type, array $attrs = array(), array $children = array() ) {
		$nested = array();
		foreach ( $attrs as $key => $value ) {
			$cursor =& $nested;
			foreach ( explode( '.', $key ) as $part ) {
				if ( ! isset( $cursor[ $part ] ) || ! is_array( $cursor[ $part ] ) ) {
					$cursor[ $part ] = array();
				}
				$cursor =& $cursor[ $part ];
			}
			$cursor = $value;
			unset( $cursor );
		}
		return Saddle_Divi_Tree::make_module( $type, $nested, '', $children );
	}

	private function page( array $sections ) {
		return array( $this->m( 'divi/placeholder', array(), $sections ) );
	}

	private function section( array $modules, array $attrs = array() ) {
		return $this->m( 'divi/section', $attrs, array( $this->m( 'divi/row', array(), array( $this->m( 'divi/column', array(), $modules ) ) ) ) );
	}

	private function lint( array $tree ) {
		return Saddle_Lint::run( $tree, new Saddle_Divi_Lint_Accessor() );
	}

	private function of( array $violations, $rule ) {
		$out = array();
		foreach ( $violations as $v ) {
			if ( $v['rule'] === $rule ) {
				$out[] = $v['address'] . ' ' . $v['severity'];
			}
		}
		return $out;
	}

	private function filled( $color = '#2563eb', array $more = array() ) {
		return $this->m(
			'divi/button',
			array_merge(
				array(
					'button.innerContent.desktop.value' => array( 'text' => 'Start a project' ),
					'button.decoration.button.desktop.value.enable' => 'on',
					'button.decoration.background.desktop.value.color' => $color,
					'button.decoration.font.font.desktop.value.color' => '#ffffff',
				),
				$more
			)
		);
	}

	private function heading( $text, array $more = array() ) {
		return $this->m( 'divi/heading', array_merge( array( 'title.innerContent.desktop.value' => $text ), $more ) );
	}

	/* -------- one-main-action -------- */

	public function test_one_main_action() {
		$plain = $this->m( 'divi/button', array( 'button.innerContent.desktop.value' => array( 'text' => 'See the work' ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->page( array( $this->section( array( $this->filled(), $plain ) ) ) ) ), 'one-main-action' ) );

		$two = $this->lint( $this->page( array( $this->section( array( $this->filled(), $this->filled( '#2563EB' ) ) ) ) ) );
		$this->assertSame( array( '0.0.0.0.1 note' ), $this->of( $two, 'one-main-action' ), 'The second equal button gets the note, normalized colors match.' );
		$finding = wp_list_filter( $two, array( 'rule' => 'one-main-action' ) );
		$this->assertStringContainsString( 'divi-edit-module', current( $finding )['fix_hint'] );

		$weights = $this->page( array( $this->section( array( $this->filled(), $this->filled( '#0f172a' ) ) ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $weights ), 'one-main-action' ), 'Different weights are a hierarchy.' );

		$apart = $this->page( array( $this->section( array( $this->filled() ) ), $this->section( array( $this->filled() ) ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $apart ), 'one-main-action' ), 'One per section is the rule.' );

		$loop = $this->page(
			array(
				$this->m(
					'divi/section',
					array(),
					array(
						$this->m(
							'divi/row',
							array(),
							array( $this->m( 'divi/column', array( 'module.advanced.loop.desktop.value' => array( 'enable' => 'on' ) ), array( $this->filled(), $this->filled() ) ) )
						),
					)
				),
			)
		);
		$this->assertSame( array(), $this->of( $this->lint( $loop ), 'one-main-action' ), 'A loop repeats the button per item.' );
	}

	/* -------- font-families -------- */

	public function test_font_families() {
		$two = $this->page(
			array(
				$this->section(
					array(
						$this->heading( 'Hi', array( 'title.decoration.font.font.desktop.value.family' => 'Fraunces' ) ),
						$this->m( 'divi/text', array( 'content.decoration.bodyFont.body.font.desktop.value.family' => 'Inter, sans-serif' ) ),
						$this->m( 'divi/text', array( 'content.decoration.bodyFont.body.font.desktop.value.family' => '"Inter"' ) ),
						$this->heading( 'Token', array( 'title.decoration.font.font.desktop.value.family' => '$variable({"type":"content","value":{"name":"gvid-abc","settings":{}}})$' ) ),
						$this->m( 'divi/icon', array( 'icon.decoration.font.font.desktop.value.family' => 'ETmodules' ) ),
					)
				),
			)
		);
		$this->assertSame( array(), $this->of( $this->lint( $two ), 'font-families' ), 'Two families, a token and an icon font are fine.' );

		$three = $this->lint(
			$this->page(
				array(
					$this->section(
						array(
							$this->heading( 'Hi', array( 'title.decoration.font.font.desktop.value.family' => 'Fraunces' ) ),
							$this->m( 'divi/text', array( 'content.decoration.bodyFont.body.font.desktop.value.family' => 'Inter' ) ),
							$this->heading( 'Third', array( 'title.decoration.font.font.desktop.value.family' => 'Lobster' ) ),
						)
					),
				)
			)
		);
		$this->assertSame( array( '0.0.0.0.2 note' ), $this->of( $three, 'font-families' ) );
		$finding = current( wp_list_filter( $three, array( 'rule' => 'font-families' ) ) );
		$this->assertStringContainsString( 'Lobster', $finding['message'] );
		$this->assertStringContainsString( 'title.decoration.font.font.desktop.value.family', $finding['fix_hint'] );
	}

	/* -------- button-hover -------- */

	public function test_button_hover() {
		$icon_off = array( 'button.decoration.button.desktop.value.icon' => array( 'enable' => 'off' ) );

		$this->assertSame( array(), $this->of( $this->lint( $this->page( array( $this->section( array( $this->filled() ) ) ) ) ), 'button-hover' ), 'Divi\'s default icon slides in on hover.' );

		$bare = $this->lint( $this->page( array( $this->section( array( $this->filled( '#2563eb', $icon_off ) ) ) ) ) );
		$this->assertSame( array( '0.0.0.0.0 note' ), $this->of( $bare, 'button-hover' ) );

		$always = $this->filled( '#2563eb', array( 'button.decoration.button.desktop.value.icon' => array( 'onHover' => 'off' ) ) );
		$this->assertSame( array( '0.0.0.0.0 note' ), $this->of( $this->lint( $this->page( array( $this->section( array( $always ) ) ) ) ), 'button-hover' ), 'An icon always shown does not change on hover.' );

		$hover = $this->filled( '#2563eb', array_merge( $icon_off, array( 'button.decoration.background.desktop.hover.color' => '#1e4fd0' ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->page( array( $this->section( array( $hover ) ) ) ) ), 'button-hover' ) );

		$preset          = $this->filled( '#2563eb', $icon_off );
		$preset['attrs'] = array_merge( $preset['attrs'], array( 'modulePreset' => array( 'p1' ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->page( array( $this->section( array( $preset ) ) ) ) ), 'button-hover' ), 'A preset may carry the hover.' );
	}

	/* -------- vanishing-fill -------- */

	public function test_vanishing_fill_buttons() {
		$band = array( 'module.decoration.background.desktop.value.color' => '#2563eb' );

		$same = $this->lint( $this->page( array( $this->section( array( $this->filled( '#2563eb' ) ), $band ) ) ) );
		$this->assertSame( array( '0.0.0.0.0 warn' ), $this->of( $same, 'vanishing-fill' ) );

		$apart = $this->page( array( $this->section( array( $this->filled( '#ffffff', array( 'button.decoration.font.font.desktop.value.color' => '#2563eb' ) ) ), $band ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $apart ), 'vanishing-fill' ) );

		$bordered = $this->filled( '#3b82f6', array( 'button.decoration.border.desktop.value.styles.all' => array( 'width' => '2px', 'style' => 'solid', 'color' => '#ffffff' ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->page( array( $this->section( array( $bordered ), $band ) ) ) ), 'vanishing-fill' ), 'A border that shows is the edge.' );

		$image = array( 'module.decoration.background.desktop.value' => array( 'color' => '#2563eb', 'image' => array( 'url' => 'https://example.com/a.jpg' ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->page( array( $this->section( array( $this->filled( '#2563eb' ) ), $image ) ) ) ), 'vanishing-fill' ), 'An image surface is unknown.' );

		$this->assertSame( array(), $this->of( $this->lint( $this->page( array( $this->section( array( $this->filled( '#2563eb' ) ) ) ) ) ), 'vanishing-fill' ), 'No known surface, no finding.' );
	}

	private function card_page( array $card, array $section = array() ) {
		$attrs = array_merge(
			array(
				'module.decoration.background.desktop.value.color' => '#ffffff',
				'module.decoration.border.desktop.value.radius'    => array( 'topLeft' => '12px', 'topRight' => '12px', 'bottomRight' => '12px', 'bottomLeft' => '12px' ),
			),
			$card
		);
		return $this->page(
			array(
				$this->m(
					'divi/section',
					$section,
					array( $this->m( 'divi/row', array(), array( $this->m( 'divi/column', $attrs, array( $this->m( 'divi/text' ) ) ) ) ) )
				),
			)
		);
	}

	public function test_vanishing_fill_cards() {
		$white = array( 'module.decoration.background.desktop.value.color' => '#ffffff' );
		$this->assertSame( array( '0.0.0.0 warn' ), $this->of( $this->lint( $this->card_page( array(), $white ) ), 'vanishing-fill' ) );

		$tinted = array( 'module.decoration.background.desktop.value.color' => '#eef2f7' );
		$this->assertSame( array(), $this->of( $this->lint( $this->card_page( array(), $tinted ) ), 'vanishing-fill' ), 'A soft card on a tinted section is a choice.' );

		$shadow = array( 'module.decoration.boxShadow.desktop.value.style' => 'preset1' );
		$this->assertSame( array(), $this->of( $this->lint( $this->card_page( $shadow, $white ) ), 'vanishing-fill' ) );

		$border = array( 'module.decoration.border.desktop.value.styles.all' => array( 'width' => '1px', 'color' => '#e2e8f0', 'style' => 'solid' ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->card_page( $border, $white ) ), 'vanishing-fill' ) );
	}

	/* -------- emoji-icon -------- */

	public function test_emoji_icon() {
		$bad = $this->page(
			array(
				$this->section(
					array(
						$this->heading( '🚀 Launch fast' ),
						$this->m( 'divi/button', array( 'button.innerContent.desktop.value' => array( 'text' => 'Book a call 👉' ) ) ),
						$this->m( 'divi/text', array( 'content.innerContent.desktop.value' => '<h3>✨ Magic</h3><p>Body 🙂 text.</p>' ) ),
						$this->m( 'divi/blurb', array( 'title.innerContent.desktop.value' => '❤️ Loved' ) ),
					)
				),
			)
		);
		$this->assertSame( array( '0.0.0.0.0 note', '0.0.0.0.1 note', '0.0.0.0.2 note', '0.0.0.0.3 note' ), $this->of( $this->lint( $bad ), 'emoji-icon' ) );

		$clean = $this->page(
			array(
				$this->section(
					array(
						$this->heading( '★★★★★ from 200 clients' ),
						$this->m( 'divi/blurb', array( 'title.innerContent.desktop.value' => '✓ Included' ) ),
						$this->m( 'divi/text', array( 'content.innerContent.desktop.value' => '<p>We love it 🙂 a lot.</p>' ) ),
						$this->heading( 'Plain words' ),
					)
				),
			)
		);
		$this->assertSame( array(), $this->of( $this->lint( $clean ), 'emoji-icon' ) );
	}

	/* -------- code-layout -------- */

	public function test_code_layout() {
		$layout = '<style>.hero{display:flex;gap:24px}.hero h2{font-size:48px}</style><section class="hero"><div class="left"><h2>Welcome to the studio</h2><p>Copy that should be a text module.</p></div><div class="right"><img src="a.jpg" alt=""></div></section>';
		$bad    = $this->page( array( $this->section( array( $this->m( 'divi/code', array( 'content.innerContent.desktop.value' => $layout ) ) ) ) ) );
		$this->assertSame( array( '0.0.0.0.0 note' ), $this->of( $this->lint( $bad ), 'code-layout' ) );

		$embed = '<div class="calendly-inline-widget" data-url="https://calendly.com/studio/intro" style="min-width:320px;height:700px;"></div><script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js" async></script>';
		$small = '<div class="badge">New</div>';
		foreach ( array( $embed, $small, '<iframe src="https://www.google.com/maps/embed?pb=1" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>' ) as $html ) {
			$page = $this->page( array( $this->section( array( $this->m( 'divi/code', array( 'content.innerContent.desktop.value' => $html ) ) ) ) ) );
			$this->assertSame( array(), $this->of( $this->lint( $page ), 'code-layout' ), $html );
		}
	}

	/* -------- nested-card -------- */

	public function test_nested_card() {
		$card  = array(
			'module.decoration.background.desktop.value.color' => '#ffffff',
			'module.decoration.boxShadow.desktop.value.style'  => 'preset1',
		);
		$inner = $this->m( 'divi/group', array_merge( $card, array( 'module.decoration.background.desktop.value.color' => '#f8fafc' ) ), array( $this->m( 'divi/text' ) ) );
		$bad   = $this->page( array( $this->m( 'divi/section', array(), array( $this->m( 'divi/row', array(), array( $this->m( 'divi/column', $card, array( $inner ) ) ) ) ) ) ) );
		$this->assertSame( array( '0.0.0.0.0 note' ), $this->of( $this->lint( $bad ), 'nested-card' ) );

		$plain = $this->m( 'divi/group', array( 'module.decoration.background.desktop.value.color' => '#f8fafc' ), array( $this->m( 'divi/text' ) ) );
		$ok    = $this->page( array( $this->m( 'divi/section', array(), array( $this->m( 'divi/row', array(), array( $this->m( 'divi/column', $card, array( $plain ) ) ) ) ) ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $ok ), 'nested-card' ), 'A tinted panel with no radius or shadow is not a card.' );
	}

	/* -------- grid-phone-columns -------- */

	private function grid_page( array $layout, $type = 'divi/group' ) {
		$attrs = array();
		foreach ( $layout as $key => $value ) {
			$attrs[ 'module.decoration.layout.' . $key ] = $value;
		}
		return $this->page( array( $this->section( array( $this->m( $type, $attrs, array( $this->m( 'divi/text' ), $this->m( 'divi/text' ), $this->m( 'divi/text' ) ) ) ) ) ) );
	}

	public function test_grid_phone_columns() {
		$three = $this->lint( $this->grid_page( array( 'desktop.value' => array( 'display' => 'grid', 'gridColumnCount' => '3' ) ) ) );
		$this->assertSame( array( '0.0.0.0.0 warn' ), $this->of( $three, 'grid-phone-columns' ) );
		$this->assertStringContainsString( 'phone.value.gridColumnCount', current( wp_list_filter( $three, array( 'rule' => 'grid-phone-columns' ) ) )['fix_hint'] );

		$manual = $this->grid_page( array( 'desktop.value' => array( 'display' => 'grid', 'gridColumnWidths' => 'manual', 'gridTemplateColumns' => 'minmax(0, 2fr) 1fr' ) ) );
		$this->assertSame( array( '0.0.0.0.0 warn' ), $this->of( $this->lint( $manual ), 'grid-phone-columns' ) );

		$silent = array(
			'phone set'    => array(
				'desktop.value' => array( 'display' => 'grid', 'gridColumnCount' => '3' ),
				'phone.value'   => array( 'gridColumnCount' => '1' ),
			),
			'tablet set'   => array(
				'desktop.value' => array( 'display' => 'grid', 'gridColumnCount' => '3' ),
				'tablet.value'  => array( 'gridColumnCount' => '2' ),
			),
			'self-sizing'  => array( 'desktop.value' => array( 'display' => 'grid', 'gridColumnCount' => '3', 'gridColumnWidths' => 'equalMinimum' ) ),
			'auto-fit'     => array( 'desktop.value' => array( 'display' => 'grid', 'gridColumnWidths' => 'manual', 'gridTemplateColumns' => 'repeat(auto-fit, minmax(240px, 1fr))' ) ),
			'one column'   => array( 'desktop.value' => array( 'display' => 'grid', 'gridColumnCount' => '1' ) ),
			'flex'         => array( 'desktop.value' => array( 'display' => 'flex', 'gridColumnCount' => '3' ) ),
			'group no cnt' => array( 'desktop.value' => array( 'display' => 'grid' ) ),
		);
		foreach ( $silent as $label => $layout ) {
			$this->assertSame( array(), $this->of( $this->lint( $this->grid_page( $layout ) ), 'grid-phone-columns' ), $label );
		}

		$row = $this->page(
			array(
				$this->m(
					'divi/section',
					array(),
					array( $this->m( 'divi/row', array( 'module.decoration.layout.desktop.value.display' => 'grid' ), array( $this->m( 'divi/column' ), $this->m( 'divi/column' ) ) ) )
				),
			)
		);
		$this->assertSame( array( '0.0.0 warn' ), $this->of( $this->lint( $row ), 'grid-phone-columns' ), 'A grid row with no count is three columns.' );
	}

	/* -------- collapsed-column -------- */

	private function split( array $image_column, array $text_column = array(), array $row = array() ) {
		$image = $this->m( 'divi/column', array_merge( array( 'module.decoration.background.desktop.value.image.url' => 'https://example.com/a.jpg' ), $image_column ) );
		$text  = $this->m( 'divi/column', $text_column, array( $this->m( 'divi/text', array( 'content.innerContent.desktop.value' => '<p>Copy</p>' ) ) ) );
		return $this->page( array( $this->m( 'divi/section', array(), array( $this->m( 'divi/row', $row, array( $image, $text ) ) ) ) ) );
	}

	public function test_collapsed_column() {
		$half = array( 'module.decoration.sizing.desktop.value.flexType' => '12_24' );

		$this->assertSame( array(), $this->of( $this->lint( $this->split( $half, $half ) ), 'collapsed-column' ), 'Side by side everywhere, the row stretches it.' );

		$stacked = $this->lint( $this->split( array_merge( $half, array( 'module.decoration.sizing.phone.value.flexType' => '24_24' ) ), $half ) );
		$this->assertSame( array( '0.0.0.0 warn' ), $this->of( $stacked, 'collapsed-column' ) );
		$this->assertStringContainsString( 'On phones', current( wp_list_filter( $stacked, array( 'rule' => 'collapsed-column' ) ) )['message'] );

		$unset = $this->lint( $this->split( array(), array() ) );
		$this->assertStringContainsString( 'On desktop', current( wp_list_filter( $unset, array( 'rule' => 'collapsed-column' ) ) )['message'], 'No flexType is full width.' );

		$centered = $this->split( $half, $half, array( 'module.decoration.layout.desktop.value.alignItems' => 'center' ) );
		$this->assertSame( array( '0.0.0.0 warn' ), $this->of( $this->lint( $centered ), 'collapsed-column' ) );

		$height = array_merge( $half, array( 'module.decoration.sizing.phone.value.flexType' => '24_24', 'module.decoration.sizing.desktop.value.minHeight' => '420px' ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->split( $height, $half ) ), 'collapsed-column' ) );

		$padded = array_merge( $half, array( 'module.decoration.sizing.phone.value.flexType' => '24_24', 'module.decoration.spacing.desktop.value.padding' => array( 'top' => '160px' ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->split( $padded, $half ) ), 'collapsed-column' ) );

		$alone = $this->page(
			array(
				$this->m( 'divi/section', array(), array( $this->m( 'divi/row', array(), array( $this->m( 'divi/column', array( 'module.decoration.background.desktop.value.image.url' => 'https://example.com/a.jpg' ) ) ) ) ) ),
			)
		);
		$this->assertSame( array( '0.0.0.0 warn' ), $this->of( $this->lint( $alone ), 'collapsed-column' ) );
	}

	/* -------- repeated-row-layout -------- */

	private function three_col_row() {
		return $this->m( 'divi/row', array(), array( $this->m( 'divi/column', array(), array( $this->m( 'divi/text' ) ) ), $this->m( 'divi/column', array(), array( $this->m( 'divi/text' ) ) ), $this->m( 'divi/column', array(), array( $this->m( 'divi/text' ) ) ) ) );
	}

	public function test_repeated_row_layout() {
		$five = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$five[] = $this->m( 'divi/section', array(), array( $this->three_col_row() ) );
		}
		$this->assertSame( array( '0.0.0 note' ), $this->of( $this->lint( $this->page( $five ) ), 'repeated-row-layout' ), 'One note at the run\'s first row, across sections.' );

		$this->assertSame( array(), $this->of( $this->lint( $this->page( array_slice( $five, 0, 4 ) ) ), 'repeated-row-layout' ) );

		$broken = $five;
		array_splice( $broken, 2, 0, array( $this->section( array( $this->m( 'divi/text' ) ) ) ) );
		$this->assertSame( array(), $this->of( $this->lint( $this->page( $broken ) ), 'repeated-row-layout' ), 'A single-column moment breaks the run.' );
	}

	/* -------- link-text: weak labels -------- */

	public function test_submit_is_a_weak_label() {
		$markup     = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Submit</a></div><!-- /wp:button -->'
			. '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Send your brief</a></div><!-- /wp:button -->';
		$violations = Saddle_Lint::run( Saddle_Tree::parse( $markup ), new Saddle_Lint_Gutenberg_Accessor() );
		$this->assertSame( array( '0 warn' ), $this->of( $violations, 'link-text' ) );
	}

	/* -------- severities -------- */

	public function test_taste_rules_are_notes_and_layout_problems_warnings() {
		$this->assertSame( 'note', Saddle_Lint::SEVERITY_NOTE );
		$violations = $this->lint( $this->page( array( $this->section( array( $this->heading( '🚀 Go' ) ) ) ) ) );
		$this->assertSame( array( '0.0.0.0.0 note' ), $this->of( $violations, 'emoji-icon' ), 'The engine keeps a note a note.' );
	}

	/* -------- no new finding on clean pages -------- */

	public function test_new_rules_add_nothing_to_the_builtin_divi_recipes() {
		foreach ( array( 'hero', 'features', 'pricing', 'testimonials', 'cta', 'faq' ) as $name ) {
			$tree = Saddle_Divi_Author::expand( Saddle_Divi_Recipes::tree( $name ) );
			$this->assertNotWPError( $tree );
			foreach ( $this->lint( $tree ) as $violation ) {
				$this->assertNotContains( $violation['rule'], self::NEW_RULES, "{$name}: {$violation['rule']} at {$violation['address']}: {$violation['message']}" );
			}
		}
		// All six together form one page with mixed rows.
		$all = array();
		foreach ( array( 'hero', 'features', 'pricing', 'testimonials', 'cta', 'faq' ) as $name ) {
			$all = array_merge( $all, Saddle_Divi_Recipes::tree( $name ) );
		}
		foreach ( $this->lint( Saddle_Divi_Author::expand( $all ) ) as $violation ) {
			$this->assertNotContains( $violation['rule'], self::NEW_RULES, "page: {$violation['rule']} at {$violation['address']}" );
		}
	}

	public function test_new_rules_add_nothing_to_the_suites_clean_pages() {
		// The Divi page verify-page scores 100 (divi-render-verify-test).
		$canonical = Saddle_Tree::parse(
			'<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column -->'
			. '<!-- wp:saddletest/fancy-button {"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}}} --><!-- /wp:saddletest/fancy-button -->'
			. '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->'
		);
		$this->assertSame( array(), $this->lint( $canonical ) );

		// A designed hero: left heading, capped lede, one filled button with a
		// hover, a secondary outline, a card on a tinted band, a stacked split.
		$hero = $this->page(
			array(
				$this->m(
					'divi/section',
					array( 'module.decoration.background.desktop.value.color' => '#0f172a' ),
					array(
						$this->m(
							'divi/row',
							array(),
							array(
								$this->m(
									'divi/column',
									array( 'module.decoration.sizing.desktop.value.flexType' => '12_24', 'module.decoration.sizing.phone.value.flexType' => '24_24' ),
									array(
										$this->heading( 'Interiors that feel like home', array( 'title.decoration.font.font.desktop.value' => array( 'color' => '#ffffff', 'family' => 'Fraunces' ) ) ),
										$this->m( 'divi/text', array( 'content.decoration.bodyFont.body.font.desktop.value' => array( 'color' => '#e2e8f0', 'family' => 'Inter' ), 'module.decoration.sizing.desktop.value.maxWidth' => '560px' ) ),
										$this->filled( '#f59e0b', array( 'button.decoration.font.font.desktop.value.color' => '#0f172a' ) ),
										$this->m(
											'divi/button',
											array(
												'button.innerContent.desktop.value' => array( 'text' => 'See our projects' ),
												'button.decoration.button.desktop.value.enable' => 'on',
												'button.decoration.background.desktop.value.color' => 'transparent',
												'button.decoration.border.desktop.value.styles.all' => array( 'width' => '2px', 'style' => 'solid', 'color' => '#ffffff' ),
												'button.decoration.font.font.desktop.value.color' => '#ffffff',
											)
										),
									)
								),
								$this->m(
									'divi/column',
									array(
										'module.decoration.sizing.desktop.value.flexType' => '12_24',
										'module.decoration.sizing.phone.value.flexType' => '24_24',
										'module.decoration.background.desktop.value.image.url' => 'https://example.com/room.jpg',
										'module.decoration.sizing.phone.value.minHeight' => '280px',
									)
								),
							)
						),
					)
				),
			)
		);
		$this->assertSame( array(), array_values( array_filter( wp_list_pluck( $this->lint( $hero ), 'rule' ), array( $this, 'is_new_rule' ) ) ) );
	}

	public function is_new_rule( $rule ) {
		return in_array( $rule, self::NEW_RULES, true );
	}
}
