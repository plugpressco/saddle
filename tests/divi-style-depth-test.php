<?php
/**
 * divi-get-style-schema: every group's fields and example, states and
 * breakpoints, advanced options, custom CSS, and the bugs that taught
 * shapes Divi does not read.
 *
 * @package Saddle
 */

class Saddle_Divi_Style_Depth_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-style-depth-fixtures';
		if ( ! is_dir( self::$fixture_dir . '/styled' ) ) {
			mkdir( self::$fixture_dir . '/styled', 0755, true );
		}
		$groups = array_fill_keys( array( 'zIndex', 'layout', 'sticky', 'scroll', 'interactions', 'attributes', 'conditions', 'disabledOn', 'order', 'position', 'transform', 'animation', 'background', 'boxShadow', 'sizing', 'filters', 'spacing' ), array() );
		file_put_contents(
			self::$fixture_dir . '/styled/module.json',
			wp_json_encode(
				array(
					'name'            => 'saddletest/styled',
					'title'           => 'Styled',
					'category'        => 'module',
					'customCssFields' => array( 'title' => array( 'selectorSuffix' => ' h2' ) ),
					'attributes'      => array(
						'module' => array(
							'type'     => 'object',
							'settings' => array(
								'decoration' => $groups,
								'advanced'   => array(
									'dividers' => array(
										'groupType' => 'group',
										'component' => array( 'name' => 'divi/dividers' ),
									),
									'html'     => array(),
									'link'     => array(),
									'text'     => array(),
								),
							),
						),
						'title'  => array(
							'type'     => 'object',
							'settings' => array( 'decoration' => array( 'font' => array() ) ),
						),
						'meta'   => array(
							'type'     => 'object',
							'settings' => array( 'decoration' => array( 'font' => array() ) ),
						),
						'image'  => array(
							'type'     => 'object',
							'settings' => array( 'decoration' => array( 'fit' => array() ) ),
						),
						'button' => array(
							'type'        => 'object',
							'elementType' => 'button',
							'settings'    => array(
								'decoration' => array(
									'spacing'   => array(),
									'boxShadow' => array(),
									'font'      => array(),
									'button'    => array(
										'component' => array(
											'props' => array(
												'fields' => array(
													'spacingGroup'   => array( 'render' => false ),
													'boxShadowGroup' => array( 'render' => false ),
												),
											),
										),
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
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( 'et_divi_builder_breakpoints' );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	private function group( $group ) {
		$out = Saddle_Divi_Schema::style( 'saddletest/styled', $group );
		$this->assertNotWPError( $out, $group );
		return $out;
	}

	/* -------- B8, B9: shapes Divi reads -------- */

	/**
	 * Bug B8: z-index was taught as { "value": "10" }, which Divi does not
	 * read (ZIndex.php adds the value as it is).
	 */
	public function test_z_index_is_one_plain_value() {
		$z = $this->group( 'zIndex' );

		$this->assertSame( '10', $z['example'] );
		$this->assertSame( array(), $z['fields'] );
		$this->assertStringContainsString( '"module.decoration.zIndex.desktop.value": "10"', $z['note'] );
		$this->assertStringNotContainsString( 'desktop.value.value', $z['note'] );
	}

	/**
	 * Bug B8: the layout example used "gap"; Divi reads columnGap and rowGap
	 * only (Layout.php).
	 */
	public function test_layout_gaps_are_column_gap_and_row_gap() {
		$layout = $this->group( 'layout' );

		$this->assertNotContains( 'gap', $layout['fields'] );
		$this->assertContains( 'columnGap', $layout['fields'] );
		$this->assertContains( 'rowGap', $layout['fields'] );
		$this->assertArrayNotHasKey( 'gap', $layout['example'] );
		$this->assertArrayHasKey( 'columnGap', $layout['example'] );
	}

	/**
	 * Bug B9: every group's note showed { "<path>.color": "#ffffff" }, wrong
	 * for layout, sticky and the rest. The sample now comes from the
	 * group's own example.
	 */
	public function test_the_write_note_uses_the_groups_own_example() {
		$this->assertStringContainsString( 'module.decoration.sticky.desktop.value.position": "top"', $this->group( 'sticky' )['note'] );
		$this->assertStringContainsString( 'module.decoration.layout.desktop.value.display": "flex"', $this->group( 'layout' )['note'] );
		$this->assertStringNotContainsString( '.color"', $this->group( 'sticky' )['note'] );
	}

	/* -------- every group, with fields and an example -------- */

	public function test_the_groups_that_returned_nothing_now_have_fields_and_an_example() {
		foreach ( array( 'sticky', 'scroll', 'interactions', 'attributes', 'order' ) as $name ) {
			$group = $this->group( $name );
			$this->assertNotEmpty( $group['fields'], $name );
			$this->assertNotEmpty( $group['example'], $name );
		}
		$this->assertSame( 'on', $this->group( 'disabledOn' )['example'] );
		$this->assertStringContainsString( 'disabledOn.phone.value', $this->group( 'disabledOn' )['group_note'] );
		$this->assertStringStartsWith( 'et-interaction-trigger-', $this->group( 'interactions' )['example']['interactions'][0]['triggerClass'] );
		$this->assertSame( array( 'order' => '2' ), $this->group( 'order' )['example'] );

		$conditions = $this->group( 'conditions' );
		$this->assertStringContainsString( 'divi-set-display-conditions', $conditions['note'] );
	}

	public function test_deeper_groups_show_their_real_keys() {
		$background = $this->group( 'background' );
		$this->assertContains( 'gradient.stops', $background['sub_fields'] );
		$this->assertContains( 'image.size', $background['sub_fields'] );
		$this->assertSame( 'on', $background['example_2']['gradient']['enabled'] );
		$this->assertNotContains( 'enableColor', $background['fields'], 'A Divi 4 leftover Divi 5 does not read.' );

		$this->assertContains( 'position', $this->group( 'boxShadow' )['fields'] );
		$this->assertArrayHasKey( 'style', $this->group( 'boxShadow' )['example'], 'A shadow without style renders nothing.' );

		$sizing = $this->group( 'sizing' );
		foreach ( array( 'aspectRatio', 'gridColumnSpan', 'flexGrow', 'alignSelf', 'minWidth' ) as $field ) {
			$this->assertContains( $field, $sizing['fields'] );
		}

		$position = $this->group( 'position' );
		$this->assertSame( 'bottom left', $position['example']['origin']['absolute'] );
		$this->assertSame( 'relative', $position['example_2']['mode'] );

		$this->assertSame( array( 'x' => '0px', 'y' => '-8px' ), $this->group( 'transform' )['example']['translate'] );
		$animation = $this->group( 'animation' );
		$this->assertSame( 'style', array_key_first( $animation['example'] ), 'Divi finds an animation only when "style" comes first.' );
		$this->assertContains( 'startingOpacity', $animation['fields'] );
		$this->assertSame( 'cover', $this->group( 'fit' )['example']['objectFit'] );
	}

	public function test_typography_lists_capitalization_columns_and_its_siblings() {
		$font = $this->group( 'title.font' );

		foreach ( array( 'capitalization', 'columnCount', 'variationSettings', 'textWrap' ) as $field ) {
			$this->assertContains( $field, $font['fields'] );
		}
		$this->assertSame( 'title.decoration.font.font.desktop.value', $font['path'] );
		$this->assertSame( 'title.decoration.font.textShadow.desktop.value', $font['siblings']['textShadow']['path'] );
		$this->assertSame( 'title.decoration.font.textEffects.desktop.value', $font['siblings']['textEffects']['path'] );
	}

	public function test_a_group_on_several_elements_names_every_path() {
		$font = $this->group( 'font' );

		$this->assertSame( 'title.decoration.font.font.desktop.value', $font['paths']['title'] );
		$this->assertSame( 'meta.decoration.font.font.desktop.value', $font['paths']['meta'] );
		$this->assertSame( 'meta.decoration.font.font.desktop.value', $this->group( 'meta.font' )['path'] );
		$this->assertWPError( Saddle_Divi_Schema::style( 'saddletest/styled', 'nope.font' ) );
	}

	public function test_backdrop_filters_are_offered_only_from_divi_5_11() {
		$this->assertNotContains( 'backdropBlur', Saddle_Divi_Style::fields( 'filters', '5.10.1' ) );
		$this->assertNotContains( 'backdropBlur', Saddle_Divi_Style::fields( 'filters', null ) );
		$this->assertContains( 'backdropBlur', Saddle_Divi_Style::fields( 'filters', '5.11.0' ) );
		$this->assertContains( 'backdropBlur', Saddle_Divi_Style::fields( 'filters', '5.19.0' ) );
		$this->assertContains( 'blendMode', Saddle_Divi_Style::fields( 'filters', '5.10.1' ) );
	}

	/* -------- C10: groups an element hides -------- */

	/**
	 * Row C10: Divi strips spacing and box shadow from the standalone
	 * button's element ("<group>Group": {"render": false}); they belong on
	 * module.decoration.
	 */
	public function test_groups_the_button_element_hides_are_not_offered() {
		$style = Saddle_Divi_Schema::style( 'saddletest/styled' );
		$on    = array();
		foreach ( $style['style_groups'] as $sg ) {
			$on[] = $sg['attr'] . '.' . $sg['group'];
		}
		$this->assertNotContains( 'button.spacing', $on );
		$this->assertNotContains( 'button.boxShadow', $on );
		$this->assertContains( 'button.font', $on );
		$this->assertContains( 'module.spacing', $on );

		$button = array_column( Saddle_Divi_Schema::describe( 'saddletest/styled' )['attributes'], null, 'name' )['button'];
		$this->assertNotContains( 'spacing', $button['decorations'], 'So the echo warns on a write there.' );
		$warnings = Saddle_Divi_Echo::check_nodes(
			array(
				array(
					'type'  => 'saddletest/styled',
					'attrs' => array( 'button.decoration.spacing.desktop.value' => array( 'padding' => array( 'top' => '10px' ) ) ),
				),
			)
		);
		$this->assertNotEmpty( $warnings );
	}

	/* -------- advanced options and custom CSS -------- */

	public function test_dividers_id_and_class_and_custom_css_have_their_real_paths() {
		$style = Saddle_Divi_Schema::style( 'saddletest/styled' );

		$this->assertSame( 'module.advanced.dividers.top.desktop.value', $style['advanced']['dividers']['paths']['top'] );
		$this->assertSame( 'module.advanced.dividers.bottom.desktop.value', $style['advanced']['dividers']['paths']['bottom'] );
		$this->assertSame( array( 'horizontal' ), $style['advanced']['dividers']['example']['flip'], 'flip is a list.' );
		$this->assertSame( 'module.advanced.htmlAttributes.desktop.value', $style['advanced']['htmlAttributes']['path'] );
		$this->assertSame( array( 'id', 'class' ), $style['advanced']['htmlAttributes']['fields'] );
		$this->assertSame( array( 'elementType', 'htmlBefore', 'htmlAfter' ), $style['advanced']['html']['fields'] );
		$this->assertSame( 'module.advanced.text.text.desktop.value', $style['advanced']['text']['path'] );

		$this->assertSame( 'css.desktop.value', $style['css']['path'], 'Custom CSS is the top-level "css" attribute.' );
		$this->assertContains( 'freeForm', $style['css']['fields'] );
		$this->assertContains( 'title', $style['css']['fields'], 'The module\'s own customCssFields.' );
	}

	/* -------- states and breakpoints -------- */

	public function test_the_schema_says_how_states_are_written_and_which_breakpoints_are_on() {
		$style = Saddle_Divi_Schema::style( 'saddletest/styled' );
		$this->assertSame( array( 'desktop', 'tablet', 'phone' ), $style['breakpoints'], 'Divi\'s defaults when the site saved none.' );
		$this->assertStringContainsString( '"hover"', $style['states'] );
		$this->assertStringContainsString( 'desktop, tablet, phone', $style['states'] );

		update_option(
			'et_divi_builder_breakpoints',
			array(
				'items' => array(
					'phone'     => array( 'enable' => true ),
					'phoneWide' => array( 'enable' => true ),
					'tablet'    => array( 'enable' => false ),
					'desktop'   => array( 'enable' => true ),
				),
			)
		);
		$this->assertSame( array( 'desktop', 'phone', 'phoneWide' ), Saddle_Divi_Style::breakpoints(), 'Only what the site switched on.' );
	}

	/* -------- the echo's view of the paths -------- */

	public function test_group_paths_keep_their_shape_for_the_echo() {
		$paths = Saddle_Divi_Schema::group_paths( 'saddletest/styled' );

		$this->assertSame( 'title.decoration.font.font.desktop.value', $paths['title.font'] );
		$this->assertSame( 'module.decoration.spacing.desktop.value', $paths['module.spacing'] );
		$this->assertArrayNotHasKey( 'button.spacing', $paths );
		$this->assertSame( array( 'margin', 'padding' ), Saddle_Divi_Schema::STYLE_FIELDS['spacing'] );
		$this->assertSame( array( 'spacing' ), Saddle_Divi_Schema::CLOSED_FIELD_GROUPS );
	}
}
