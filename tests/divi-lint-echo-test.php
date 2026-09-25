<?php
/**
 * The Divi lint accessor + the Divi applied-vs-ignored echo.
 *
 * Pins: the accessor reads design facts off canonical Divi 5 attr shapes
 * (including the nested typography path and the live-verified ghost-button
 * enable switch); free Saddle's saddle/lint-page lints Divi 5 pages through
 * the driver's accessor with the SAME rule set; the echo warns — on the
 * write response, without blocking the write — about fields and style paths
 * the module's own module.json proves Divi will ignore.
 *
 * @package Saddle
 */

class Saddle_Divi_Lint_Echo_Test extends WP_UnitTestCase {

	private $admin;
	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		// The same minimal module.json contract fixture the surgical suite
		// uses: one composite content attr ("button", decorations font+border),
		// one plain content attr ("title"), one settings-less "module" attr.
		self::$fixture_dir = get_temp_dir() . 'saddle-pro-lint-fixture-modules';
		$module_dir        = self::$fixture_dir . '/fancy-button';
		if ( ! is_dir( $module_dir ) ) {
			mkdir( $module_dir, 0755, true );
		}
		file_put_contents(
			$module_dir . '/module.json',
			wp_json_encode(
				array(
					'name'       => 'saddletest/fancy-button',
					'title'      => 'Fancy Button',
					'category'   => 'module',
					'attributes' => array(
						'module' => array( 'type' => 'object' ),
						'button' => array(
							'type'        => 'object',
							'elementType' => 'button',
							'settings'    => array(
								'innerContent' => array(
									'groups' => array(
										'text' => array( 'item' => array( 'subName' => 'text' ) ),
										'link' => array( 'item' => array( 'subName' => 'linkUrl' ) ),
									),
								),
								'decoration'   => array(
									'font'   => array(),
									'border' => array(),
								),
							),
						),
						'title'  => array(
							'type'        => 'object',
							'elementType' => 'heading',
							'settings'    => array(
								'innerContent' => array( 'item' => array( 'label' => 'Heading' ) ),
							),
						),
					),
				)
			)
		);

		// The render defaults that encode the real value nesting (typography
		// nests under an extra `font`) — same shape the surgical fixture
		// ships, so the per-process schema memos agree across suites.
		file_put_contents(
			$module_dir . '/module-default-render-attributes.json',
			wp_json_encode(
				array(
					'button' => array(
						'decoration' => array(
							'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'headingLevel' => 'h1' ) ) ) ),
						),
					),
				)
			)
		);

		// Real sites always ship module.json for the core content modules the
		// lint pages use — without these, the unknown-module rule would fire
		// on every legit divi/* module in the fixtures.
		foreach ( array( 'heading' => 'divi/heading', 'text' => 'divi/text', 'button' => 'divi/button' ) as $dir_name => $type ) {
			$core_dir = self::$fixture_dir . '/core-' . $dir_name;
			if ( ! is_dir( $core_dir ) ) {
				mkdir( $core_dir, 0755, true );
			}
			file_put_contents(
				$core_dir . '/module.json',
				wp_json_encode(
					array(
						'name'       => $type,
						'title'      => ucfirst( $dir_name ),
						'category'   => 'module',
						'attributes' => array( 'module' => array( 'type' => 'object' ) ),
					)
				)
			);
		}

		// A second module with a UNIQUE name (no cross-suite memo sharing)
		// declaring the spacing group — the closed-field-set leaf checks.
		$widget_dir = self::$fixture_dir . '/deep-widget';
		if ( ! is_dir( $widget_dir ) ) {
			mkdir( $widget_dir, 0755, true );
		}
		file_put_contents(
			$widget_dir . '/module.json',
			wp_json_encode(
				array(
					'name'       => 'saddletest/deep-widget',
					'title'      => 'Deep Widget',
					'category'   => 'module',
					'attributes' => array(
						'module' => array(
							'type'     => 'object',
							'settings' => array(
								'decoration' => array( 'spacing' => array() ),
							),
						),
						'image'  => array(
							'type'        => 'object',
							'elementType' => 'image',
							'settings'    => array(
								'innerContent' => array(
									'groups' => array(
										'src'  => array( 'item' => array( 'subName' => 'src' ) ),
										'link' => array( 'item' => array( 'subName' => 'linkUrl' ) ),
									),
								),
							),
						),
					),
				)
			)
		);
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'write' );

		$dir = self::$fixture_dir;
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'read' );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( "saddle/{$name}" )->execute( $input );
	}

	/* -------- the accessor reads canonical Divi shapes -------- */

	private function module( $type, array $attrs = array() ) {
		return Saddle_Divi_Tree::make_module( $type, $attrs );
	}

	public function test_accessor_reads_background_text_alignment_padding() {
		$accessor = new Saddle_Divi_Lint_Accessor();

		$node = $this->module(
			'divi/text',
			array(
				'module' => array(
					'decoration' => array(
						'background' => array( 'desktop' => array( 'value' => array( 'color' => '#112233' ) ) ),
						// Typography nests its value under an extra `font` key.
						'font'       => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => '#ffffff' ) ) ) ),
						'sizing'     => array( 'desktop' => array( 'value' => array( 'alignment' => 'center' ) ) ),
						'spacing'    => array( 'desktop' => array( 'value' => array( 'padding' => array( 'top' => '96px', 'bottom' => '96px' ) ) ) ),
					),
				),
			)
		);

		$this->assertSame( '#112233', $accessor->background_color( $node ) );
		$this->assertSame( '#ffffff', $accessor->text_color( $node ) );
		$this->assertSame( 'center', $accessor->alignment( $node ) );
		$this->assertSame( array( 'top' => '96px', 'bottom' => '96px' ), $accessor->padding( $node ) );

		// Facts a node doesn't set come back null, never guessed.
		$bare = $this->module( 'divi/text' );
		$this->assertNull( $accessor->background_color( $bare ) );
		$this->assertNull( $accessor->padding( $bare ) );
	}

	public function test_accessor_button_fill_encodes_the_enable_switch() {
		$accessor = new Saddle_Divi_Lint_Accessor();

		$styled_no_enable = $this->module(
			'divi/button',
			array( 'button' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#2271b1' ) ) ) ) ) )
		);
		$styled_enabled   = $this->module(
			'divi/button',
			array(
				'button' => array(
					'decoration' => array(
						'background' => array( 'desktop' => array( 'value' => array( 'color' => '#2271b1' ) ) ),
						'button'     => array( 'desktop' => array( 'value' => array( 'enable' => 'on' ) ) ),
					),
				),
			)
		);
		$unstyled         = $this->module( 'divi/button' );

		$this->assertTrue( $accessor->is_button( $styled_no_enable ) );
		$this->assertFalse( $accessor->button_is_filled( $styled_no_enable ), 'Styled without enable=on renders ghost (live-verified).' );
		$this->assertTrue( $accessor->button_is_filled( $styled_enabled ) );
		$this->assertTrue( $accessor->button_is_filled( $unstyled ), 'An unstyled button inherits its preset — not the lint\'s business.' );
	}

	public function test_accessor_title_semantics() {
		$accessor = new Saddle_Divi_Lint_Accessor();

		$titled = $this->module( 'divi/heading', array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Hello' ) ) ) ) );
		$this->assertSame( 'Hello', $accessor->title_text( $titled ) );

		// A heading with no title attr at all IS the empty-title case…
		$this->assertSame( '', $accessor->title_text( $this->module( 'divi/heading' ) ) );
		// …while a module that simply has no title isn't title-like.
		$this->assertNull( $accessor->title_text( $this->module( 'divi/button' ) ) );
	}

	/* -------- lint-page runs on Divi pages through the driver -------- */

	public function test_lint_page_lints_a_divi_page_with_the_shared_rules() {
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/placeholder -->',
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				// Empty heading (error) + styled-but-ghost button (warn).
				'<!-- wp:divi/heading --><!-- /wp:divi/heading -->',
				'<!-- wp:divi/button {"button":{"decoration":{"background":{"desktop":{"value":{"color":"#2271b1"}}}}}} --><!-- /wp:divi/button -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
				'<!-- /wp:divi/placeholder -->',
			)
		);
		$id     = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => $markup ) );

		$result = $this->run_ability( 'lint-page', array( 'post_id' => $id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'Divi 5', $result['builder'] );

		$rules = wp_list_pluck( $result['violations'], 'rule', 'address' );
		$this->assertSame( 'empty-title', $rules['0.0.0.0.0'] );
		$this->assertSame( 'ghost-button', $rules['0.0.0.0.1'] );
		$this->assertSame( 1, $result['errors'] );
	}

	public function test_lint_page_clean_divi_page_produces_zero_violations() {
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/placeholder -->',
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Welcome"}}}} --><!-- /wp:divi/heading -->',
				'<!-- wp:divi/text --><p>Body</p><!-- /wp:divi/text -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
				'<!-- /wp:divi/placeholder -->',
			)
		);
		$id     = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => $markup ) );

		$result = $this->run_ability( 'lint-page', array( 'post_id' => $id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( array(), $result['violations'] );
	}

	/* -------- the echo: module.json-proven no-ops warn on the write -------- */

	public function test_echo_flags_unknown_content_field_and_non_content_attribute() {
		$warnings = Saddle_Divi_Echo::check(
			'saddletest/fancy-button',
			array(
				'button' => array( 'text' => 'Go' ), // Real content field — clean.
				'nope'   => 'x',                     // Not an attribute at all.
				'module' => 'x',                     // An attribute, but not content.
			),
			array(),
			'0.0.0.0'
		);

		$joined = implode( "\n", $warnings );
		$this->assertCount( 2, $warnings );
		$this->assertStringContainsString( '"nope"', $joined );
		$this->assertStringContainsString( 'does not exist', $joined );
		$this->assertStringContainsString( '"module"', $joined );
		$this->assertStringContainsString( 'NOT a content field', $joined );
	}

	public function test_echo_flags_unknown_attribute_and_foreign_decoration_group() {
		// "glowEffect" is foreign to Divi's universal vocabulary AND the
		// fixture's declared groups; "spacing" (universal) renders via
		// ElementStyle regardless of declaration and must stay silent.
		$warnings = Saddle_Divi_Echo::check(
			'saddletest/fancy-button',
			array(),
			array(
				'button.decoration.spacing.desktop.value.padding.top' => '1px',
				'button.decoration.glowEffect.desktop.value.color'    => '#fff',
				'ghost.decoration.font.font.desktop.value.color'      => '#fff',
				'builderVersion' => '5.8', // Canonical plumbing — never flagged.
			),
			'1'
		);

		$joined = implode( "\n", $warnings );
		$this->assertCount( 2, $warnings );
		$this->assertStringContainsString( '"glowEffect"', $joined );
		$this->assertStringContainsString( 'font, border', $joined );
		$this->assertStringContainsString( '"ghost"', $joined );
		$this->assertStringNotContainsString( '"spacing"', $joined );
	}

	public function test_echo_checks_nested_attrs_and_stays_silent_on_valid_paths() {
		// Valid dotted path + valid nested payload → no warnings.
		$this->assertSame(
			array(),
			Saddle_Divi_Echo::check(
				'saddletest/fancy-button',
				array( 'title' => 'Hi' ),
				array(
					'button.decoration.border.desktop.value.radius' => '8px',
					'button' => array( 'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => '#fff' ) ) ) ) ) ),
				),
				'0'
			)
		);

		// An undeclared-but-UNIVERSAL group stays silent: ElementStyle
		// renders any standard decoration group present in the attrs, so
		// declaration is a VB-UI fact, not a render fact (proven live on a
		// pack button element).
		$this->assertSame(
			array(),
			Saddle_Divi_Echo::check(
				'saddletest/fancy-button',
				array(),
				array( 'button' => array( 'decoration' => array( 'boxShadow' => array( 'desktop' => array( 'value' => array( 'blur' => '8px' ) ) ) ) ) ),
				'0'
			)
		);

		// A genuinely FOREIGN group name is still caught.
		$warnings = Saddle_Divi_Echo::check(
			'saddletest/fancy-button',
			array(),
			array( 'button' => array( 'decoration' => array( 'glowEffect' => array() ) ) ),
			'0'
		);
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( '"glowEffect"', $warnings[0] );
	}

	/* -------- deep value-shape checks (the silent no-op catalogue) -------- */

	public function test_echo_catches_missed_intermediate_nesting() {
		// The classic trap: title.decoration.font.desktop.value LOOKS right,
		// but Divi reads …font.FONT.desktop.value — the write saves and
		// renders nothing. The echo now quotes the exact correct path.
		$warnings = Saddle_Divi_Echo::check(
			'saddletest/fancy-button',
			array(),
			array( 'button.decoration.font.desktop.value.color' => '#ffffff' ),
			'0'
		);

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'button.decoration.font.font.desktop.value', $warnings[0], 'The warning must quote the canonical path.' );
		$this->assertStringContainsString( 'silently not render', $warnings[0] );
	}

	public function test_echo_catches_typoed_breakpoints_and_states() {
		$dektop = Saddle_Divi_Echo::check(
			'saddletest/fancy-button',
			array(),
			array( 'button.decoration.border.dektop.value.radius' => '8px' ),
			'0'
		);
		$this->assertCount( 1, $dektop );
		$this->assertStringContainsString( '"dektop" is not a Divi breakpoint', $dektop[0] );
		$this->assertStringContainsString( 'desktop, tablet, phone', $dektop[0] );

		$vlaue = Saddle_Divi_Echo::check(
			'saddletest/fancy-button',
			array(),
			array( 'button.decoration.border.desktop.vlaue.radius' => '8px' ),
			'0'
		);
		$this->assertCount( 1, $vlaue );
		$this->assertStringContainsString( '"vlaue" is not a value state', $vlaue[0] );

		// Legit non-desktop breakpoints and hover states pass untouched.
		$this->assertSame(
			array(),
			Saddle_Divi_Echo::check(
				'saddletest/fancy-button',
				array(),
				array(
					'button.decoration.border.tablet.value.radius' => '4px',
					'button.decoration.border.desktop.hover.radius' => '12px',
				),
				'0'
			)
		);
	}

	public function test_echo_catches_unknown_leaf_fields_in_closed_groups_only() {
		// spacing's field set is provably closed (margin, padding) — the
		// padd1ng typo is caught with the valid fields named.
		$warnings = Saddle_Divi_Echo::check(
			'saddletest/deep-widget',
			array(),
			array( 'module.decoration.spacing.desktop.value.padd1ng' => array( 'top' => '4px' ) ),
			'0'
		);
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( '"padd1ng" is not a settable field', $warnings[0] );
		$this->assertStringContainsString( 'margin, padding', $warnings[0] );

		// The correct field stays silent.
		$this->assertSame(
			array(),
			Saddle_Divi_Echo::check(
				'saddletest/deep-widget',
				array(),
				array( 'module.decoration.spacing.desktop.value.padding' => array( 'top' => '96px', 'bottom' => '96px' ) ),
				'0'
			)
		);

		// An OPEN group never leaf-warns: a bogus font field passes (the
		// curated list documents there, it doesn't judge).
		$this->assertSame(
			array(),
			Saddle_Divi_Echo::check(
				'saddletest/fancy-button',
				array(),
				array( 'button.decoration.font.font.desktop.value.colr' => '#fff' ),
				'0'
			)
		);
	}

	public function test_echo_catches_unknown_composite_content_subkeys() {
		// fields.button = {label} saves and renders an empty button — the
		// module.json proves the sub-keys are text/linkUrl.
		$warnings = Saddle_Divi_Echo::check(
			'saddletest/fancy-button',
			array( 'button' => array( 'label' => 'Go', 'url' => '/x' ) ),
			array(),
			'0'
		);

		$this->assertCount( 2, $warnings );
		$this->assertStringContainsString( '"label" is not a sub-field of "button"', $warnings[0] );
		$this->assertStringContainsString( 'text, linkUrl', $warnings[0] );

		// The correct shape stays silent.
		$this->assertSame(
			array(),
			Saddle_Divi_Echo::check(
				'saddletest/fancy-button',
				array( 'button' => array( 'text' => 'Go', 'linkUrl' => '/x' ) ),
				array(),
				'0'
			)
		);
	}

	public function test_image_schema_carries_alt_and_echo_accepts_it() {
		// module.json subNames omit alt, but the a11y lint demands it at
		// image.innerContent.desktop.value.alt — the schema must advertise
		// the field the linter requires, and the echo must accept it.
		$schema = Saddle_Divi_Schema::describe( 'saddletest/deep-widget' );
		$this->assertNotWPError( $schema );
		$image = array_column( $schema['attributes'], null, 'name' )['image'];
		$this->assertContains( 'alt', $image['content_fields'] );
		$this->assertStringContainsString( 'alt', $image['write_as'] );

		$this->assertSame(
			array(),
			Saddle_Divi_Echo::check(
				'saddletest/deep-widget',
				array( 'image' => array( 'src' => 'https://example.test/a.jpg', 'alt' => 'A product photo' ) ),
				array(),
				'0'
			),
			'fields.image = {src, alt} is the taught shape — it must never warn.'
		);
	}

	public function test_echo_warns_once_about_modules_without_a_module_json() {
		// Silence here hid the worst failure: a typo'd type saves fine and
		// renders nothing. The echo now says what the site cannot prove.
		$warnings = Saddle_Divi_Echo::check( 'thirdparty/runtime-module', array( 'whatever' => 'x' ), array( 'also' => 'x' ), '0' );

		$this->assertCount( 1, $warnings, 'One cannot-verify warning, not one per key.' );
		$this->assertStringContainsString( 'thirdparty/runtime-module', $warnings[0] );
		$this->assertStringContainsString( 'divi-list-modules', $warnings[0] );
	}

	public function test_echo_manifest_warning_exempts_structural_vocabulary() {
		// Structural containment is enforced by the tree validator — a bare
		// section/row/column (or the placeholder chrome) with no module.json
		// on this site must not trip the cannot-verify warning.
		foreach ( array( 'divi/section', 'divi/row', 'divi/column', 'divi/placeholder', 'divi/fullwidth-section' ) as $type ) {
			$this->assertSame(
				array(),
				Saddle_Divi_Echo::check( $type, array(), array(), '0' ),
				"{$type} must stay exempt from the manifest warning."
			);
		}
	}

	/* -------- warnings ride the Divi write responses -------- */

	private function make_page() {
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/placeholder -->',
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:saddletest/fancy-button --><!-- /wp:saddletest/fancy-button -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
				'<!-- /wp:divi/placeholder -->',
			)
		);
		return self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => $markup ) );
	}

	public function test_edit_module_echoes_ignored_paths_but_still_writes() {
		$id = $this->make_page();

		$result = $this->run_ability(
			'divi-edit-module',
			array(
				'post_id' => $id,
				'address' => '0.0.0.0.0',
				'attrs'   => array( 'button.decoration.glowEffect.desktop.value.color' => '#fff' ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertStringContainsString( '"glowEffect"', $result['warnings'][0] );
		// The write landed anyway — echo warns, it never blocks.
		$this->assertStringContainsString( 'glowEffect', get_post( $id )->post_content );
	}

	public function test_edit_module_fully_applied_write_carries_no_warnings() {
		$id = $this->make_page();

		$result = $this->run_ability(
			'divi-edit-module',
			array(
				'post_id' => $id,
				'address' => '0.0.0.0.0',
				'fields'  => array( 'button' => array( 'text' => 'Buy now', 'linkUrl' => '/pricing' ) ),
				'attrs'   => array( 'button.decoration.border.desktop.value.radius' => '8px' ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'warnings', $result );
		$this->assertStringContainsString( 'Buy now', get_post( $id )->post_content );
	}

	public function test_set_page_echoes_ignored_paths_with_node_addresses() {
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );

		$result = $this->run_ability(
			'divi-set-page',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array(
							array(
								'type'     => 'divi/row',
								'children' => array(
									array(
										'type'     => 'divi/column',
										'children' => array(
											array(
												'type'   => 'saddletest/fancy-button',
												'fields' => array( 'bogus' => 'x' ),
											),
										),
									),
								),
							),
						),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'warnings', $result );
		// The author wraps the input in a divi/placeholder root, so the
		// persisted module lives one level deeper than the authored one.
		// The warning must carry the PERSISTED address.
		$this->assertStringContainsString( 'node 0.0.0.0.0', $result['warnings'][0] );
		$this->assertStringContainsString( '"bogus"', $result['warnings'][0] );

		// Parity proof: the flagged address resolves to the offending module
		// on the saved page — an agent can paste it into divi-edit-module.
		$page = $this->run_ability( 'divi-get-page', array( 'post_id' => $id, 'address' => '0.0.0.0.0' ) );
		$this->assertNotWPError( $page );
		$this->assertSame( 'saddletest/fancy-button', $page['nodes'][0]['type'] );
	}

	public function test_set_page_prewrapped_placeholder_keeps_addresses() {
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );

		$result = $this->run_ability(
			'divi-set-page',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'divi/placeholder',
						'children' => array(
							array(
								'type'     => 'divi/section',
								'children' => array(
									array(
										'type'     => 'divi/row',
										'children' => array(
											array(
												'type'     => 'divi/column',
												'children' => array(
													array(
														'type'   => 'saddletest/fancy-button',
														'fields' => array( 'bogus' => 'x' ),
													),
												),
											),
										),
									),
								),
							),
						),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'node 0.0.0.0.0', $result['warnings'][0], 'Pre-wrapped input gets no extra prefix.' );
	}

	public function test_apply_dynamic_content_rejects_provably_wrong_field() {
		$id = $this->make_page();

		// The fixture module.json proves the attributes are module/button/
		// title — a typo'd first segment binds nothing and is refused.
		$typo = $this->run_ability(
			'divi-apply-dynamic-content',
			array(
				'post_id' => $id,
				'address' => '0.0.0.0.0',
				'field'   => 'titel.innerContent',
				'source'  => 'post_title',
			)
		);
		$this->assertWPError( $typo );
		$this->assertSame( 'saddle_bad_field', $typo->get_error_code() );
		$this->assertStringContainsString( 'title', $typo->get_error_message(), 'The error must list the real attributes.' );

		// The real attribute binds fine.
		$ok = $this->run_ability(
			'divi-apply-dynamic-content',
			array(
				'post_id' => $id,
				'address' => '0.0.0.0.0',
				'field'   => 'title.innerContent',
				'source'  => 'post_title',
			)
		);
		$this->assertNotWPError( $ok );
	}

	public function test_add_module_echo_covers_the_whole_inserted_subtree() {
		$id = $this->make_page();

		$result = $this->run_ability(
			'divi-add-module',
			array(
				'post_id'        => $id,
				'parent_address' => '0.0',
				'node'           => array(
					'type'     => 'divi/row',
					'children' => array(
						array(
							'type'     => 'divi/column',
							'children' => array(
								array(
									'type'   => 'saddletest/fancy-button',
									'fields' => array( 'bogus' => 'x' ),
								),
							),
						),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'warnings', $result, 'A grandchild\'s bad field must surface — the echo covers the whole inserted subtree.' );
		$this->assertStringContainsString( '"bogus"', $result['warnings'][0] );
		$this->assertStringContainsString( 'node 0.0.1.0.0', $result['warnings'][0], 'Subtree warnings carry full persisted addresses.' );
	}
}
