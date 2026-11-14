<?php
/**
 * The echo's value-shape traps and the false warnings it no longer gives.
 *
 * Every Divi fact pinned here was read from Divi 5.10.1's source (the echo
 * classes name the file beside each check); none was rendered. Each check is
 * tested both ways: the trap warns, the correct value stays silent.
 *
 * @package Saddle
 */

class Saddle_Divi_Echo_Traps_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$fixture_dir = get_temp_dir() . 'saddle-echo-traps-fixture-modules';
		$body_font         = array(
			'item' => array(
				'component' => array(
					'name'  => 'divi/font-body',
					'type'  => 'group',
					'props' => array( 'groups' => array_fill_keys( array( 'body', 'link' ), array() ) ),
				),
			),
		);
		$modules           = array(
			'trap-text'    => array(
				'name'            => 'saddletest/trap-text',
				'title'           => 'Trap Text',
				'category'        => 'module',
				'customCssFields' => array(
					'title' => array(
						'subName'        => 'title',
						'selectorSuffix' => ' h2',
					),
				),
				'attributes'      => array(
					'module'  => array(
						'type'     => 'object',
						'settings' => array(
							'decoration' => array_fill_keys( array( 'background', 'layout', 'zIndex', 'boxShadow', 'position', 'animation', 'interactions', 'scroll', 'spacing', 'disabledOn' ), array() ),
							'advanced'   => array_fill_keys( array( 'html', 'link', 'text' ), array() ),
						),
					),
					'title'   => array(
						'type'        => 'object',
						'elementType' => 'heading',
						'settings'    => array(
							'innerContent' => array( 'item' => array( 'label' => 'Title' ) ),
							'decoration'   => array( 'font' => array() ),
						),
					),
					'content' => array(
						'type'        => 'object',
						'elementType' => 'content',
						'settings'    => array(
							'innerContent' => array( 'item' => array( 'label' => 'Body' ) ),
							'decoration'   => array( 'bodyFont' => $body_font ),
						),
					),
				),
			),
			// Named like a Divi core module: the flat-group check judges only those.
			'core-scroll'  => array(
				'name'       => 'divi/saddletest-scroll',
				'title'      => 'Core Scroll',
				'category'   => 'module',
				'attributes' => array(
					'module' => array(
						'type'     => 'object',
						'settings' => array( 'decoration' => array_fill_keys( array( 'scroll', 'border' ), array() ) ),
					),
				),
			),
			'trap-section' => array(
				'name'       => 'saddletest/trap-section',
				'title'      => 'Trap Section',
				'category'   => 'module',
				'attributes' => array(
					'module' => array(
						'type'     => 'object',
						'settings' => array(
							'advanced'   => array_fill_keys( array( 'html', 'dividers' ), array() ),
							'decoration' => array( 'background' => array() ),
						),
					),
				),
			),
			'trap-button'  => array(
				'name'       => 'saddletest/trap-button',
				'title'      => 'Trap Button',
				'category'   => 'module',
				'attributes' => array(
					'module' => array( 'type' => 'object' ),
					'button' => array(
						'type'        => 'object',
						'elementType' => 'button',
						'settings'    => array(
							'decoration' => array(
								'button'     => array(
									'component' => array(
										'props' => array(
											'fields' => array(
												'alignment'      => array( 'render' => false ),
												'spacingGroup'   => array( 'render' => false ),
												'boxShadowGroup' => array( 'render' => false ),
												'fontGroup'      => array(),
											),
										),
									),
								),
								'background' => array(),
								'spacing'    => array(),
								'boxShadow'  => array(),
							),
						),
					),
				),
			),
			'trap-form'    => array(
				'name'       => 'saddletest/trap-form',
				'title'      => 'Trap Form',
				'category'   => 'module',
				'attributes' => array(
					'module'      => array( 'type' => 'object' ),
					// Divi's contact form: "advanced": {} on the field.
					'field'       => array(
						'type'        => 'object',
						'elementType' => 'field',
						'settings'    => array(
							'decoration' => array( 'background' => array() ),
							'advanced'   => array(),
						),
					),
					// The cart notice: a field that declares other advanced groups.
					'noticeField' => array(
						'type'        => 'object',
						'elementType' => 'field',
						'settings'    => array( 'advanced' => array( 'width' => array() ) ),
					),
				),
			),
		);
		foreach ( $modules as $folder => $json ) {
			$dir = self::$fixture_dir . '/' . $folder;
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			file_put_contents( $dir . '/module.json', wp_json_encode( $json ) );
		}
		// Typography nests under an extra `font` key (Divi's own defaults).
		file_put_contents(
			self::$fixture_dir . '/trap-text/module-default-render-attributes.json',
			wp_json_encode( array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'headingLevel' => 'h2' ) ) ) ) ) ) ) )
		);
	}

	public function set_up() {
		parent::set_up();
		add_filter( 'saddle_divi_active', '__return_true' );
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
		remove_all_filters( 'saddle_divi_global_colors' );
		remove_all_filters( 'saddle_divi_variable_ids' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( 'et_divi_builder_breakpoints' );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	private function echo_text( array $attrs, array $options = array() ) {
		return Saddle_Divi_Echo::check( 'saddletest/trap-text', array(), $attrs, '0', $options );
	}

	private function assert_one( array $warnings, $needle ) {
		$this->assertCount( 1, $warnings, implode( "\n", $warnings ) );
		$this->assertStringContainsString( $needle, $warnings[0] );
	}

	/* -------- false warnings that are gone -------- */

	public function test_custom_css_is_read_and_its_fields_are_checked() {
		// Divi reads the top-level css attr on every module (each module's
		// CssStyle call); it was called "store and ignore".
		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'css' => array(
						'desktop' => array(
							'value' => array(
								'mainElement' => 'letter-spacing: 2px;',
								'freeForm'    => 'selector p { margin: 0; }',
								'title'       => 'color: red;',
							),
							'hover' => array( 'mainElement' => 'opacity: .8;' ),
						),
					),
				)
			)
		);

		// A field the module has no selector for is skipped by Divi.
		$this->assert_one( $this->echo_text( array( 'css.desktop.value.headingContainer' => 'color: red;' ) ), '"headingContainer"' );
		$this->assertStringContainsString( 'mainElement, before, after, freeForm, title', $this->echo_text( array( 'css.desktop.value.headingContainer' => 'x' ) )[0] );

		// Declarations written straight under css, with no breakpoint.
		$this->assert_one( $this->echo_text( array( 'css' => array( 'mainElement' => 'color: red;' ) ) ), 'css.desktop.value.mainElement' );

		// The wrong place stays caught.
		$this->assert_one( $this->echo_text( array( 'module.advanced.css.desktop.value.mainElement' => 'x' ) ), 'No "css" advanced group' );
	}

	public function test_verify_does_not_score_valid_custom_css_as_an_error() {
		$markup = '<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column -->'
			. sprintf( '<!-- wp:saddletest/trap-text %s --><!-- /wp:saddletest/trap-text -->', wp_json_encode( array( 'css' => array( 'desktop' => array( 'value' => array( 'mainElement' => 'letter-spacing: 2px;' ) ) ) ) ) )
			. '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
		$id     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $markup,
			)
		);

		$findings = wp_list_filter( Saddle_Divi_Verify::findings( Saddle_Tree::parse( get_post( $id )->post_content ) ), array( 'source' => 'echo' ) );
		$this->assertSame( array(), array_values( $findings ) );
	}

	public function test_css_id_and_class_live_in_html_attributes() {
		// Divi reads module.advanced.htmlAttributes.desktop.value.{id,class}
		// on every module, though no module.json declares it.
		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'module.advanced.htmlAttributes.desktop.value' => array(
						'id'    => 'hero',
						'class' => 'hero-band',
					),
				)
			)
		);

		// Beside the breakpoint, or beside value: nothing renders.
		$this->assert_one( $this->echo_text( array( 'module.advanced.htmlAttributes.desktop.class' => 'x' ) ), 'beside the breakpoint' );
		$this->assert_one( $this->echo_text( array( 'module.advanced.htmlAttributes.id.desktop.value' => 'x' ) ), 'module.advanced.htmlAttributes.desktop.value.id' );

		// Only desktop is read.
		$this->assert_one( $this->echo_text( array( 'module.advanced.htmlAttributes.phone.value.class' => 'x' ) ), 'only from desktop' );

		// The html group holds the element type and before/after HTML.
		$this->assertSame( array(), $this->echo_text( array( 'module.advanced.html.desktop.value.elementType' => 'section' ) ) );
		$html = $this->echo_text( array( 'module.advanced.html.desktop.value.class' => 'hero-band' ) );
		$this->assert_one( $html, 'module.advanced.htmlAttributes.desktop.value.class' );
	}

	public function test_form_field_focus_states_are_not_called_ignored() {
		$warnings = Saddle_Divi_Echo::check(
			'saddletest/trap-form',
			array(),
			array(
				// "advanced": {} declares nothing; it is not a closed list.
				'field.advanced.focusUseBorder.desktop.value'          => 'on',
				'field.advanced.focus.border.desktop.value.styles.all' => array( 'color' => '#2563eb' ),
				'field.advanced.focus.background.desktop.value.color'  => '#ffffff',
				// A field that declares other groups still takes focus styling.
				'noticeField.advanced.focus.background.desktop.value.color' => '#ffffff',
				// The states Divi has beyond value, hover and sticky.
				'field.decoration.background.desktop.focus.color'      => '#f8fafc',
				'field.decoration.background.desktop.checked.color'    => '#e2e8f0',
				'field.decoration.background.desktop.active.color'     => '#cbd5e1',
			),
			'0'
		);
		$this->assertSame( array(), $warnings );

		// A typo on a field that declares its groups is still caught.
		$typo = Saddle_Divi_Echo::check( 'saddletest/trap-form', array(), array( 'noticeField.advanced.focsu.desktop.value' => 'on' ), '0' );
		$this->assert_one( $typo, '"focsu"' );
	}

	/* -------- breakpoints and states -------- */

	public function test_a_breakpoint_this_site_has_off_warns_and_names_the_ones_on() {
		$warnings = $this->echo_text( array( 'module.decoration.spacing.phoneWide.value.padding' => array( 'top' => '24px' ) ) );
		$this->assert_one( $warnings, 'Breakpoint "phoneWide" is not turned on' );
		$this->assertStringContainsString( 'Breakpoints on: desktop, tablet, phone', $warnings[0] );

		$this->assertSame( array(), $this->echo_text( array( 'module.decoration.spacing.phone.value.padding' => array( 'top' => '24px' ) ) ) );

		// An owner who turned phoneWide on in Divi gets no warning.
		update_option(
			'et_divi_builder_breakpoints',
			array(
				'items' => array(
					'phone'     => array( 'enable' => true ),
					'phoneWide' => array( 'enable' => true ),
					'tablet'    => array( 'enable' => true ),
				),
			)
		);
		$this->assertSame( array(), $this->echo_text( array( 'module.decoration.spacing.phoneWide.value.padding' => array( 'top' => '24px' ) ) ) );
	}

	public function test_disabled_on_takes_its_own_breakpoint_names() {
		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'module.decoration.disabledOn' => array(
						'phone'        => array( 'value' => 'on' ),
						'desktopAbove' => array( 'value' => 'on' ),
						'tabletOnly'   => array( 'value' => 'on' ),
					),
				)
			)
		);
		$this->assert_one( $this->echo_text( array( 'module.decoration.disabledOn.phoneWide.value' => 'on' ) ), 'not turned on' );
	}

	public function test_a_state_wrapped_in_value_warns() {
		$this->assert_one(
			$this->echo_text( array( 'module.decoration.background.desktop.hover.value.color' => '#111111' ) ),
			'wraps its settings in "value"'
		);
		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'module.decoration.background.desktop' => array(
						'value' => array( 'color' => '#2563eb' ),
						'hover' => array( 'color' => '#1e4fd0' ),
					),
				)
			)
		);
		// A state written where the breakpoint belongs.
		$this->assert_one( $this->echo_text( array( 'module.decoration.background.hover.value.color' => '#111111' ) ), '"hover" is a state, not a breakpoint' );
	}

	/* -------- value shapes -------- */

	public function test_box_shadow_without_style_warns_unless_a_style_or_preset_gives_one() {
		$this->assert_one( $this->echo_text( array( 'module.decoration.boxShadow.desktop.value' => array( 'blur' => '24px', 'color' => '#000000' ) ) ), '"style": "preset1"' );

		$this->assertSame( array(), $this->echo_text( array( 'module.decoration.boxShadow.desktop.value' => array( 'style' => 'preset1', 'blur' => '24px' ) ) ) );
		// A hover tweak inherits the value's style.
		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'module.decoration.boxShadow.desktop' => array(
						'value' => array( 'style' => 'preset2' ),
						'hover' => array( 'color' => '#111111' ),
					),
				)
			)
		);
		// A preset can carry the style.
		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'modulePreset' => array( 'card' ),
					'module.decoration.boxShadow.desktop.value.color' => '#111111',
				)
			)
		);
	}

	public function test_text_case_belongs_in_capitalization() {
		$this->assert_one( $this->echo_text( array( 'title.decoration.font.font.desktop.value.textTransform' => 'uppercase' ) ), '"textTransform" is not a Divi font setting' );
		$this->assert_one( $this->echo_text( array( 'title.decoration.font.font.desktop.value.style' => array( 'italic', 'uppercase' ) ) ), '"capitalization": "uppercase"' );

		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'title.decoration.font.font.desktop.value' => array(
						'capitalization' => 'uppercase',
						'style'          => array( 'italic', 'underline' ),
					),
				)
			)
		);
	}

	public function test_layout_gap_and_object_z_index_warn() {
		$this->assert_one( $this->echo_text( array( 'module.decoration.layout.desktop.value' => array( 'display' => 'flex', 'gap' => '16px' ) ) ), '"columnGap"' );
		$this->assertSame( array(), $this->echo_text( array( 'module.decoration.layout.desktop.value' => array( 'display' => 'flex', 'columnGap' => '16px', 'rowGap' => '16px' ) ) ) );

		$this->assert_one( $this->echo_text( array( 'module.decoration.zIndex.desktop.value' => array( 'value' => '10' ) ) ), 'one plain value' );
		$this->assertSame( array(), $this->echo_text( array( 'module.decoration.zIndex.desktop.value' => '10' ) ) );
	}

	public function test_drop_cap_on_the_body_text_warns_with_the_right_path() {
		$warnings = $this->echo_text( array( 'content.decoration.bodyFont.body.font.desktop.value.dropCapLineSize' => '3' ) );
		$this->assert_one( $warnings, 'whole paragraph' );
		$this->assertStringContainsString( 'content.decoration.bodyFont.dropCap.font.desktop.value.dropCapLineSize', $warnings[0] );

		$this->assertSame( array(), $this->echo_text( array( 'content.decoration.bodyFont.dropCap.font.desktop.value.dropCapLineSize' => '3' ) ) );
	}

	public function test_a_centred_relative_origin_is_an_advisory_note() {
		$centred = array(
			'module.decoration.position.desktop.value' => array(
				'mode'   => 'relative',
				'origin' => array( 'relative' => 'center center' ),
			),
		);
		$this->assert_one( $this->echo_text( $centred ), 'half its own size' );
		$this->assertSame( array(), $this->echo_text( $centred, array( 'advisory' => false ) ), 'verify-page scores only certain findings.' );

		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'module.decoration.position.desktop.value' => array(
						'mode'   => 'relative',
						'origin' => array( 'relative' => 'top left' ),
						'offset' => array(
							'vertical'   => '0px',
							'horizontal' => '0px',
						),
					),
				)
			)
		);
		// Centring an absolute child is what the centre origin is for.
		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'module.decoration.position.desktop.value' => array(
						'mode'   => 'absolute',
						'origin' => array( 'absolute' => 'center center' ),
					),
				)
			)
		);
	}

	public function test_background_image_size_and_gradient_type() {
		$this->assert_one( $this->echo_text( array( 'module.decoration.background.desktop.value.image' => array( 'url' => 'https://example.test/a.jpg', 'size' => '1920px 800px' ) ) ), 'actual size' );
		$this->assert_one( $this->echo_text( array( 'module.decoration.background.desktop.value.gradient' => array( 'enabled' => 'on', 'type' => 'radial' ) ) ), 'linear gradient' );

		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'module.decoration.background.desktop.value' => array(
						'image'    => array(
							'url'  => 'https://example.test/a.jpg',
							'size' => 'cover',
						),
						'gradient' => array(
							'enabled' => 'on',
							'type'    => 'circular',
						),
					),
				)
			)
		);
	}

	public function test_animation_style_and_direction() {
		$joined = $this->echo_text( array( 'module.decoration.animation.desktop.value' => array( 'style' => 'fadeBottom' ) ) );
		$this->assert_one( $joined, '"style": "fade" and "direction": "bottom"' );

		$this->assert_one( $this->echo_text( array( 'module.decoration.animation.desktop.value.style' => 'wobble' ) ), 'nothing animates' );
		$this->assert_one( $this->echo_text( array( 'module.decoration.animation.desktop.value' => array( 'style' => 'slide', 'direction' => 'up' ) ) ), 'from the center' );

		$this->assertSame(
			array(),
			$this->echo_text(
				array(
					'module.decoration.animation.desktop.value' => array(
						'style'     => 'slide',
						'direction' => 'bottom',
						'duration'  => '600ms',
						'intensity' => array( 'slide' => '20%' ),
					),
				)
			)
		);
	}

	public function test_scroll_effects_sit_inside_the_breakpoint() {
		$core     = static function ( array $attrs ) {
			return Saddle_Divi_Echo::check( 'divi/saddletest-scroll', array(), $attrs, '0' );
		};
		$warnings = $core( array( 'module.decoration.scroll.verticalMotion.desktop.value.enable' => 'on' ) );
		$this->assert_one( $warnings, 'module.decoration.scroll.desktop.value.verticalMotion' );

		// A module pack may store its own shape: one ships
		// border.radius.desktop.value as its default. Not provable there.
		$this->assertSame( array(), $this->echo_text( array( 'module.decoration.scroll.verticalMotion.desktop.value.enable' => 'on' ) ) );

		$this->assertSame(
			array(),
			$core(
				array(
					'module.decoration.scroll.desktop.value' => array(
						'motionTriggerStart' => 'middle',
						'verticalMotion'     => array(
							'enable' => 'on',
							'offset' => array(
								'start' => '4',
								'mid'   => '0',
								'end'   => '-4',
							),
						),
					),
				)
			)
		);
	}

	public function test_interaction_classes_need_divis_prefixes() {
		$item = static function ( $trigger, $target, $type = 'module' ) {
			return array(
				'module.decoration.interactions.desktop.value.interactions' => array(
					array(
						'id'           => 'sw1',
						'triggerClass' => $trigger,
						'trigger'      => 'click',
						'effect'       => 'toggleVisibility',
						'target'       => array(
							'targetClass' => $target,
							'targetType'  => $type,
						),
					),
				),
			);
		};

		$this->assertSame( array(), $this->echo_text( $item( 'et-interaction-trigger-billing', 'et-interaction-target-annualrow' ) ) );
		$this->assertSame( array(), $this->echo_text( array( 'module.decoration.interactionTarget' => 'annualrow' ) ), 'The target id Module.php reads is not a foreign group.' );
		$this->assertSame( array(), $this->echo_text( $item( 'et-interaction-trigger-billing', 'parent-box', 'parentContainer' ) ) );

		$this->assert_one( $this->echo_text( $item( 'my-btn', 'et-interaction-target-annualrow' ) ), 'et-interaction-trigger-' );
		$this->assert_one( $this->echo_text( $item( 'et-interaction-trigger-my-btn', 'et-interaction-target-annualrow' ) ), 'letters and digits only' );
		$this->assert_one( $this->echo_text( $item( 'et-interaction-trigger-billing', 'annualrow' ) ), 'et-interaction-target-' );
	}

	public function test_section_dividers_need_a_side_and_a_flip_list() {
		$section = static function ( array $attrs ) {
			return Saddle_Divi_Echo::check( 'saddletest/trap-section', array(), $attrs, '0' );
		};

		$this->assertSame(
			array(),
			$section(
				array(
					'module.advanced.dividers.bottom.desktop.value' => array(
						'style'  => 'slant',
						'flip'   => array( 'horizontal' ),
						'color'  => '#f4efe4',
						'height' => '58px',
					),
				)
			)
		);
		$this->assert_one( $section( array( 'module.advanced.dividers.desktop.value.style' => 'slant' ) ), '"top" or "bottom"' );
		$this->assert_one( $section( array( 'module.advanced.dividers.top.desktop.value' => array( 'style' => 'wave', 'flip' => 'horizontal' ) ) ), 'must be a list' );
	}

	public function test_a_font_family_written_as_var_is_an_advisory_note() {
		$warnings = $this->echo_text( array( 'title.decoration.font.font.desktop.value.family' => 'var(--gvid-brand-font)' ) );
		$this->assert_one( $warnings, '$variable({"type":"content","value":{"name":"gvid-brand-font","settings":{}}})$' );
		$this->assertSame( array(), $this->echo_text( array( 'title.decoration.font.font.desktop.value.family' => 'var(--gvid-brand-font)' ), array( 'advisory' => false ) ) );

		$this->assertSame( array(), $this->echo_text( array( 'title.decoration.font.font.desktop.value.family' => 'Inter' ) ) );
		$this->assertSame( array(), $this->echo_text( array( 'title.decoration.font.font.desktop.value.family' => Saddle_Divi_Tokens::font_token( 'gvid-brand-font' ) ) ) );
	}

	public function test_token_ids_that_do_not_exist_warn_in_both_forms() {
		$attrs = array(
			'module.decoration.background.desktop.value.color' => 'var(--gcid-nope)',
			'title.decoration.font.font.desktop.value'         => array(
				'color'  => '$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"opacity":60}}})$',
				'size'   => 'var(--gvid-space)',
				'family' => '$variable({"type":"content","value":{"name":"gvid-gone","settings":{}}})$',
			),
		);

		// Without Divi's data the site's tokens are unknown: skip, never guess.
		$this->assertSame( array(), $this->echo_text( $attrs ) );

		add_filter(
			'saddle_divi_global_colors',
			static function () {
				return array( 'gcid-primary-color' => '#2ea3f2' );
			}
		);
		add_filter(
			'saddle_divi_variable_ids',
			static function () {
				return array( 'gvid-space' );
			}
		);
		$warnings = $this->echo_text( $attrs );
		$this->assertCount( 2, $warnings, implode( "\n", $warnings ) );
		$this->assertStringContainsString( '"gcid-nope"', $warnings[0] );
		$this->assertStringContainsString( 'divi-list-global-colors', $warnings[0] );
		$this->assertStringContainsString( '"gvid-gone"', $warnings[1] );
		$this->assertStringContainsString( 'divi-list-variables', $warnings[1] );
	}

	public function test_button_groups_divi_drops_from_the_button_element() {
		$button = static function ( array $attrs ) {
			return Saddle_Divi_Echo::check( 'saddletest/trap-button', array(), $attrs, '0' );
		};

		$this->assert_one( $button( array( 'button.decoration.spacing.desktop.value.padding' => array( 'top' => '12px' ) ) ), 'module.decoration.spacing' );
		$this->assert_one( $button( array( 'button.decoration.boxShadow.desktop.value' => array( 'style' => 'preset1' ) ) ), 'module.decoration.boxShadow' );

		$this->assertSame( array(), $button( array( 'module.decoration.spacing.desktop.value.padding' => array( 'top' => '12px' ) ) ) );
		$this->assertSame( array(), $button( array( 'button.decoration.background.desktop.value.color' => '#2563eb' ) ) );
	}

	/* -------- the Visual Builder's own output stays silent -------- */

	/**
	 * Divi's own default attributes for every core module, the shapes the
	 * Visual Builder starts from, must trip none of the checks added here.
	 * Read from a local Divi 5 theme when one is there (SADDLE_DIVI_THEME_DIR,
	 * else the workspace's divi-dev site); skipped otherwise.
	 */
	public function test_divis_own_default_attributes_trip_no_new_check() {
		$theme = getenv( 'SADDLE_DIVI_THEME_DIR' );
		$theme = $theme ? $theme : dirname( __DIR__, 3 ) . '/wp/divi-dev/wp-content/themes/Divi';

		$components = rtrim( $theme, '/' ) . '/includes/builder-5/visual-builder/packages/module-library/src/components';
		if ( ! is_dir( $components ) ) {
			$this->markTestSkipped( 'No Divi 5 source on this machine.' );
		}
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $components ) {
				return array( $components, $components . '/woocommerce' );
			}
		);
		Saddle_Divi_Schema::index( true );

		$new_checks = '/keeps every setting|is a state, not|not turned on|wraps its settings|box shadow|z-index|"gap"|textTransform|capitalization|dropCap|font family|image size|Gradient type|Animation (style|direction)|interaction (trigger|target)|relative position|Custom CSS|beside the breakpoint|only from desktop|Section dividers|Divider "flip"|drops the|global color "|design variable "|not an HTML setting/';
		$checked    = 0;
		foreach ( Saddle_Divi_Schema::index() as $type => $file ) {
			foreach ( array( 'module-default-printed-style-attributes.json', 'module-default-render-attributes.json' ) as $defaults ) {
				$path  = dirname( $file ) . '/' . $defaults;
				$attrs = file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
				if ( ! is_array( $attrs ) ) {
					continue;
				}
				++$checked;
				foreach ( Saddle_Divi_Echo::check( $type, array(), $attrs, '0' ) as $warning ) {
					$this->assertDoesNotMatchRegularExpression( $new_checks, $warning, "{$type} {$defaults}" );
				}
			}
		}
		$this->assertGreaterThan( 100, $checked );

		// A module pack's own defaults (module.json "default" per attribute),
		// when a pack is on this machine (SADDLE_DIVI_PACK_DIR, else the
		// divi-dev site's divi-torque-pro).
		$pack = getenv( 'SADDLE_DIVI_PACK_DIR' );
		$pack = $pack ? $pack : dirname( rtrim( $theme, '/' ), 2 ) . '/plugins/divi-torque-pro/modules-json';
		if ( ! is_dir( $pack ) ) {
			return;
		}
		add_filter(
			'saddle_divi_module_json_dirs',
			static function ( $dirs ) use ( $pack ) {
				return array_merge( (array) $dirs, array( $pack ) );
			},
			20
		);
		Saddle_Divi_Schema::index( true );
		$pack_checked = 0;
		foreach ( Saddle_Divi_Schema::index() as $type => $file ) {
			if ( 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( $pack ) ) ) {
				continue;
			}
			++$pack_checked;
			$json  = json_decode( file_get_contents( $file ), true );
			$attrs = array();
			foreach ( ( isset( $json['attributes'] ) && is_array( $json['attributes'] ) ? $json['attributes'] : array() ) as $name => $attr ) {
				if ( isset( $attr['default'] ) && is_array( $attr['default'] ) ) {
					$attrs[ $name ] = $attr['default'];
				}
			}
			foreach ( $attrs ? Saddle_Divi_Echo::check( $type, array(), $attrs, '0' ) : array() as $warning ) {
				$this->assertDoesNotMatchRegularExpression( $new_checks, $warning, "{$type} module.json defaults" );
			}
		}
		$this->assertGreaterThan( 10, $pack_checked );
	}
}
