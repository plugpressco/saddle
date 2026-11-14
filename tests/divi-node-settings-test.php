<?php
/**
 * The "settings" key on a node, and the "@<breakpoint>" / ":<state>" key
 * suffixes in fields, settings and attrs (Saddle_Divi_Node_Attrs).
 *
 * Before this, a module option (post count, autoplay) had to be written as
 * a raw envelope in "attrs" ({"post.advanced.number.desktop.value": "6"}),
 * with nothing checking the value, and every tablet, phone or hover value
 * meant hand-building the envelope.
 *
 * @package Saddle
 */

class Saddle_Divi_Node_Settings_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-node-settings-fixture';
		if ( ! is_dir( self::$fixture_dir . '/feed' ) ) {
			mkdir( self::$fixture_dir . '/feed', 0755, true );
		}
		$item = static function ( array $extra ) {
			return array(
				'groupType' => 'group-item',
				'item'      => array_merge( array( 'groupSlug' => 'content' ), $extra ),
			);
		};
		$field = static function ( $name, array $props = array() ) {
			return array(
				'type'  => 'field',
				'name'  => $name,
				'props' => $props,
			);
		};
		$module = array(
			'name'       => 'saddletest/feed',
			'title'      => 'Feed',
			'category'   => 'module',
			'attributes' => array(
				'module' => array(
					'type'     => 'object',
					'settings' => array(
						'decoration' => array(
							'background' => array(),
							'spacing'    => array(),
						),
						'advanced'   => array(
							'auto' => $item(
								array(
									'attrName'  => 'module.advanced.auto',
									'label'     => 'Autoplay',
									'component' => $field( 'divi/toggle' ),
								)
							),
						),
					),
				),
				'post'   => array(
					'type'     => 'object',
					'settings' => array(
						'advanced' => array(
							'number' => $item(
								array(
									'attrName'  => 'post.advanced.number',
									'label'     => 'Post Count',
									'features'  => array(
										'responsive' => false,
										'hover'      => false,
										'sticky'     => false,
									),
									'component' => $field( 'divi/text' ),
								)
							),
							'order'  => $item(
								array(
									'label'     => 'Order',
									'component' => $field(
										'divi/select',
										array(
											'options' => array(
												'desc' => array( 'label' => 'Newest first' ),
												'asc'  => array( 'label' => 'Oldest first' ),
											),
										)
									),
								)
							),
							'speed'  => $item(
								array(
									'attrName'  => 'post.advanced.speed',
									'label'     => 'Speed',
									'component' => $field( 'divi/range' ),
								)
							),
						),
					),
				),
				'line'   => array(
					'type'     => 'object',
					'settings' => array(
						'advanced' => array(
							'line' => array(
								'groupType' => 'group-items',
								'items'     => array(
									'show'  => array(
										'attrName'  => 'line.advanced.line',
										'subName'   => 'show',
										'label'     => 'Show line',
										'component' => $field( 'divi/toggle' ),
									),
									'color' => array(
										'attrName'  => 'line.advanced.line',
										'subName'   => 'color',
										'label'     => 'Line color',
										'component' => $field( 'divi/color-picker' ),
									),
								),
							),
						),
					),
				),
			),
		);
		file_put_contents( self::$fixture_dir . '/feed/module.json', wp_json_encode( $module ) );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () {
				return array( self::$fixture_dir );
			}
		);
		Saddle_Divi_Schema::index( true );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( 'et_divi_builder_breakpoints' );
		Saddle_Divi_Schema::index( true );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function feed( array $node ) {
		return Saddle_Divi_Author::expand_node( array_merge( array( 'type' => 'saddletest/feed' ), $node ), '0.0.0.0' );
	}

	/* -------- settings -------- */

	public function test_settings_are_wrapped_in_the_envelope_divi_reads() {
		$block = $this->feed(
			array(
				'settings' => array(
					'post.advanced.number' => 6,
					'module.advanced.auto' => true,
					'post.advanced.order'  => 'asc',
					'line.advanced.line.show' => false,
				),
			)
		);

		$this->assertNotWPError( $block );
		$attrs = $block['attrs'];
		$this->assertSame( '6', $attrs['post']['advanced']['number']['desktop']['value'], 'A number on a text control is stored as the string Divi keeps.' );
		$this->assertSame( 'on', $attrs['module']['advanced']['auto']['desktop']['value'], 'true is "on".' );
		$this->assertSame( 'asc', $attrs['post']['advanced']['order']['desktop']['value'] );
		$this->assertSame( 'off', $attrs['line']['advanced']['line']['desktop']['value']['show'], 'A sub-named setting goes inside the value.' );
	}

	public function test_the_short_attr_key_form_resolves_when_unique() {
		$block = $this->feed( array( 'settings' => array( 'line.color' => '#ff0000' ) ) );

		$this->assertNotWPError( $block );
		$this->assertSame( '#ff0000', $block['attrs']['line']['advanced']['line']['desktop']['value']['color'] );
	}

	public function test_a_range_keeps_its_unit() {
		$block = $this->feed( array( 'settings' => array( 'post.advanced.speed' => '300ms' ) ) );
		$this->assertSame( '300ms', $block['attrs']['post']['advanced']['speed']['desktop']['value'] );

		$block = $this->feed( array( 'settings' => array( 'post.advanced.speed' => 12 ) ) );
		$this->assertSame( '12', $block['attrs']['post']['advanced']['speed']['desktop']['value'] );
	}

	public function test_an_unknown_setting_is_refused_with_the_three_closest() {
		$result = $this->feed( array( 'settings' => array( 'post.advanced.numbr' => 6 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unknown_setting', $result->get_error_code() );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'Node 0.0.0.0 (saddletest/feed)', $message );
		$this->assertStringContainsString( 'post.advanced.number', $message, 'The closest id is named.' );
		$this->assertSame( 3, substr_count( explode( 'Closest:', $message )[1], '.advanced.' ), 'Three suggestions.' );
	}

	public function test_a_select_value_outside_its_choices_is_refused_with_the_valid_ones() {
		$result = $this->feed( array( 'settings' => array( 'post.advanced.order' => 'newest' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_setting_value', $result->get_error_code() );
		$this->assertStringContainsString( '"desc" (Newest first)', $result->get_error_message() );
		$this->assertStringContainsString( '"asc" (Oldest first)', $result->get_error_message() );
		$this->assertStringContainsString( 'Node 0.0.0.0', $result->get_error_message() );
	}

	public function test_bad_toggle_and_number_values_are_refused() {
		$toggle = $this->feed( array( 'settings' => array( 'module.advanced.auto' => 'yes' ) ) );
		$this->assertWPError( $toggle );
		$this->assertStringContainsString( 'true, false, "on" or "off"', $toggle->get_error_message() );

		$number = $this->feed( array( 'settings' => array( 'post.advanced.speed' => 'fast' ) ) );
		$this->assertWPError( $number );
		$this->assertSame( 'saddle_bad_setting_value', $number->get_error_code() );
	}

	public function test_settings_on_a_module_with_no_module_json_are_refused() {
		$result = Saddle_Divi_Author::expand_node(
			array(
				'type'     => 'unknownpack/thing',
				'settings' => array( 'post.advanced.number' => 6 ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_no_settings', $result->get_error_code() );
	}

	public function test_a_refused_setting_saves_nothing_on_set_page() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );

		$result = wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $post_id,
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
												'type'     => 'saddletest/feed',
												'settings' => array( 'post.advanced.order' => 'sideways' ),
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

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Node 0.0.0.0.0 (saddletest/feed)', $result->get_error_message(), 'The persisted address is named.' );
		$this->assertSame( '', get_post( $post_id )->post_content, 'Nothing partial is saved.' );
	}

	/* -------- suffixes -------- */

	public function test_settings_take_a_breakpoint_and_state_suffix() {
		$block = $this->feed(
			array(
				'settings' => array(
					'module.advanced.auto@phone'  => 'off',
					'module.advanced.auto:hover'  => 'on',
					'post.advanced.speed@tablet'  => '200ms',
				),
			)
		);
		$this->assertNotWPError( $block );
		$this->assertSame( 'off', $block['attrs']['module']['advanced']['auto']['phone']['value'] );
		$this->assertSame( 'on', $block['attrs']['module']['advanced']['auto']['desktop']['hover'] );
		$this->assertSame( '200ms', $block['attrs']['post']['advanced']['speed']['tablet']['value'] );
	}

	public function test_a_setting_with_one_value_for_every_screen_refuses_a_breakpoint() {
		$result = $this->feed( array( 'settings' => array( 'post.advanced.number@phone' => 3 ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_responsive', $result->get_error_code() );

		$hover = $this->feed( array( 'settings' => array( 'post.advanced.number:hover' => 3 ) ) );
		$this->assertWPError( $hover );
		$this->assertSame( 'saddle_no_state', $hover->get_error_code() );
	}

	public function test_attrs_suffixes_place_screen_sizes_and_states() {
		$block = $this->feed(
			array(
				'attrs' => array(
					'module.decoration.spacing@phone'       => array( 'padding' => array( 'top' => '32px' ) ),
					'module.decoration.background:hover'    => array( 'color' => '#111111' ),
					'module.decoration.background@tablet:hover' => array( 'color' => '#222222' ),
					'module.decoration.background:sticky@phone' => array( 'color' => '#333333' ),
				),
			)
		);

		$this->assertNotWPError( $block );
		$decoration = $block['attrs']['module']['decoration'];
		$this->assertSame( '32px', $decoration['spacing']['phone']['value']['padding']['top'] );
		$this->assertSame( '#111111', $decoration['background']['desktop']['hover']['color'] );
		$this->assertSame( '#222222', $decoration['background']['tablet']['hover']['color'] );
		$this->assertSame( '#333333', $decoration['background']['phone']['sticky']['color'] );
	}

	public function test_a_field_takes_a_breakpoint_suffix() {
		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'   => 'divi/heading',
				'fields' => array(
					'title'       => 'A long desktop title',
					'title@phone' => 'Short',
				),
			)
		);
		$this->assertSame( 'A long desktop title', $block['attrs']['title']['innerContent']['desktop']['value'] );
		$this->assertSame( 'Short', $block['attrs']['title']['innerContent']['phone']['value'] );
	}

	public function test_a_field_suffix_on_a_deeper_style_path_lands_inside_the_value() {
		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'module.decoration.spacing.padding.top@phone' => '24px' ),
			)
		);
		$this->assertSame( '24px', $block['attrs']['module']['decoration']['spacing']['phone']['value']['padding']['top'] );
	}

	public function test_font_suffix_lands_on_font_font() {
		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/heading',
				'attrs' => array(
					'title.decoration.font@phone'     => array( 'size' => '32px' ),
					'title.decoration.font.size@tablet' => '40px',
				),
			)
		);
		$font = $block['attrs']['title']['decoration']['font']['font'];
		$this->assertSame( '32px', $font['phone']['value']['size'] );
		$this->assertSame( '40px', $font['tablet']['value']['size'] );
	}

	public function test_a_breakpoint_the_site_has_off_is_refused() {
		$result = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'module.decoration.spacing@phoneWide' => array( 'padding' => array( 'top' => '1px' ) ) ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_breakpoint_off', $result->get_error_code() );
		$this->assertStringContainsString( 'desktop, tablet, phone', $result->get_error_message() );
	}

	public function test_a_breakpoint_the_site_turned_on_is_accepted() {
		update_option(
			'et_divi_builder_breakpoints',
			array(
				'items' => array(
					'tablet'    => array( 'enable' => 'on' ),
					'phone'     => array( 'enable' => 'on' ),
					'phoneWide' => array( 'enable' => 'on' ),
				),
			)
		);
		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'module.decoration.spacing@phoneWide' => array( 'padding' => array( 'top' => '1px' ) ) ),
			)
		);
		$this->assertNotWPError( $block );
		$this->assertSame( '1px', $block['attrs']['module']['decoration']['spacing']['phoneWide']['value']['padding']['top'] );
	}

	public function test_a_suffix_on_a_full_breakpoint_path_is_refused_not_stored_as_garbage() {
		$result = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'module.decoration.background.desktop.value.color@phone' => '#000000' ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_two_breakpoints', $result->get_error_code() );
	}

	public function test_bad_suffixes_are_refused() {
		$state = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'module.decoration.background:focus' => array( 'color' => '#000' ) ),
			)
		);
		$this->assertWPError( $state );
		$this->assertSame( 'saddle_bad_state', $state->get_error_code() );

		$garbled = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'module.decoration.background@@phone' => array( 'color' => '#000' ) ),
			)
		);
		$this->assertWPError( $garbled );
		$this->assertSame( 'saddle_bad_key_suffix', $garbled->get_error_code() );

		$part = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'content.decoration.bodyFont@phone' => array( 'size' => '14px' ) ),
			)
		);
		$this->assertWPError( $part );
		$this->assertStringContainsString( 'bodyFont.body', $part->get_error_message() );

		$desktop_only = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'module.advanced.htmlAttributes@phone' => array( 'class' => 'x' ) ),
			)
		);
		$this->assertWPError( $desktop_only );
		$this->assertSame( 'saddle_desktop_only', $desktop_only->get_error_code() );
	}

	/* -------- desktop shorthand, raw paths unchanged -------- */

	public function test_a_group_path_with_no_breakpoint_is_the_desktop_value() {
		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array(
					'module.decoration.spacing'            => array( 'padding' => array( 'top' => '96px' ) ),
					'module.decoration.background.color'   => '#0f172a',
					'module'                               => array( 'decoration' => array( 'border' => array( 'radius' => array( 'topLeft' => '8px' ) ) ) ),
				),
			)
		);
		$decoration = $block['attrs']['module']['decoration'];
		$this->assertSame( '96px', $decoration['spacing']['desktop']['value']['padding']['top'] );
		$this->assertSame( '#0f172a', $decoration['background']['desktop']['value']['color'] );
		$this->assertSame( '8px', $decoration['border']['desktop']['value']['radius']['topLeft'], 'Nested form too.' );
	}

	public function test_a_state_with_no_breakpoint_is_refused() {
		$result = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array( 'module.decoration.background.value.color' => '#000' ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_state_without_breakpoint', $result->get_error_code() );
	}

	public function test_raw_canonical_paths_are_stored_exactly_as_before() {
		$raw = array(
			'module.decoration.background.desktop.value.color' => '#0f5fa0',
			'title'                                            => array(
				'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'headingLevel' => 'h2' ) ) ) ) ),
			),
			'modulePreset'                                     => array( 'abc' ),
			'builderVersion'                                   => '5.10.1',
			'css'                                              => array( 'desktop' => array( 'value' => array( 'mainElement' => 'color: red;' ) ) ),
		);
		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/heading',
				'attrs' => $raw,
			)
		);
		$this->assertSame( Saddle_Divi_Author::expand_dotted_keys( $raw ), $block['attrs'] );
	}

	/* -------- edit-module -------- */

	private function page() {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					'',
					array(
						'<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column -->',
						'<!-- wp:saddletest/feed {"post":{"advanced":{"number":{"desktop":{"value":"10"}}}}} /-->',
						'<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->',
					)
				),
			)
		);
	}

	public function test_edit_module_takes_settings_and_suffixes() {
		$post_id = $this->page();
		$result  = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id'  => $post_id,
				'address'  => '0.0.0.0',
				'settings' => array( 'post.advanced.number' => 3 ),
				'attrs'    => array( 'module.decoration.spacing@phone' => array( 'padding' => array( 'top' => '8px' ) ) ),
			)
		);
		$this->assertNotWPError( $result );

		$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $post_id )->post_content ), '0.0.0.0' );
		$this->assertSame( '3', $node['attrs']['post']['advanced']['number']['desktop']['value'] );
		$this->assertSame( '8px', $node['attrs']['module']['decoration']['spacing']['phone']['value']['padding']['top'] );
	}

	public function test_edit_module_refuses_a_bad_setting_and_saves_nothing() {
		$post_id = $this->page();
		$before  = get_post( $post_id )->post_content;
		$result  = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id'  => $post_id,
				'address'  => '0.0.0.0',
				'settings' => array( 'post.advanced.order' => 'up' ),
			)
		);
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Node 0.0.0.0', $result->get_error_message() );
		$this->assertSame( $before, get_post( $post_id )->post_content );
	}

	public function test_edit_module_schema_lists_settings_and_columns() {
		$schema = wp_get_ability( 'saddle/divi-edit-module' )->get_input_schema();
		$this->assertArrayHasKey( 'settings', $schema['properties'] );
		$this->assertArrayHasKey( 'columns', $schema['properties'] );
		$this->assertStringContainsString( '@phone', wp_get_ability( 'saddle/divi-set-page' )->get_description() );
		$this->assertStringContainsString( '"settings"', wp_get_ability( 'saddle/divi-add-module' )->get_description() );
	}

	/* -------- the echo reads the canonical writes -------- */

	public function test_the_echo_judges_suffixed_writes_at_their_real_paths() {
		$components = '/Users/fahim/Workspace/wp/divi-dev/wp-content/themes/Divi/includes/builder-5/visual-builder/packages/module-library/src/components';
		if ( ! is_dir( $components ) ) {
			$this->markTestSkipped( 'Needs the Divi 5 module.json files.' );
		}
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $components ) {
				return array( $components );
			}
		);
		Saddle_Divi_Schema::index( true );

		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );
		$heading = array(
			'type'   => 'divi/heading',
			'fields' => array(
				'title'       => 'Hello',
				'title@phone' => 'Hi',
			),
			'attrs'  => array(
				'module.decoration.spacing@phone'    => array( 'padding' => array( 'top' => '8px' ) ),
				'module.decoration.background:hover' => array( 'color' => '#111111' ),
				'title.decoration.font'              => array( 'size' => '40px' ),
			),
		);
		$result  = wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $post_id,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array(
							array(
								'type'     => 'divi/row',
								'children' => array(
									array(
										'type'     => 'divi/column',
										'children' => array( $heading ),
									),
								),
							),
						),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'warnings', $result, 'No false warning for a suffix or a desktop shorthand: ' . wp_json_encode( isset( $result['warnings'] ) ? $result['warnings'] : array() ) );

		$wrong = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $post_id,
				'address' => '0.0.0.0.0',
				'attrs'   => array( 'module.decoration.nosuchgroup@phone' => array( 'x' => '1' ) ),
			)
		);
		$this->assertNotWPError( $wrong );
		$this->assertNotEmpty( $wrong['warnings'], 'A wrong group is still flagged when written with a suffix.' );
	}
}
