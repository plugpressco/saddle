<?php
/**
 * Saddle_Divi_Settings: every non-text setting of a module, from its
 * module.json, in the shape other code builds on.
 *
 * @package Saddle
 */

/**
 * Before this, divi-get-module-schema kept only attributes with
 * settings.innerContent, so an agent saw bare attribute names for the Blog,
 * the Slider, the Menu and every module pack, and had to guess where "post
 * count" or "which menu" lived.
 */
class Saddle_Divi_Settings_Contract_Test extends WP_UnitTestCase {

	const KEYS = array( 'path', 'attr', 'key', 'sub_name', 'id', 'source', 'label', 'description', 'control', 'options', 'options_source', 'default', 'responsive', 'hover', 'sticky', 'dynamic', 'group', 'value_shape' );

	const CONTROLS = array( 'toggle', 'select', 'text', 'number', 'range', 'color', 'icon', 'upload', 'list', 'link', 'other' );

	private static $fixture_dir;

	private static function divi_components() {
		$dir = getenv( 'SADDLE_TEST_DIVI_COMPONENTS' ) ?: '/Users/fahim/Workspace/wp/divi-dev/wp-content/themes/Divi/includes/builder-5/visual-builder/packages/module-library/src/components';
		return is_dir( $dir ) ? $dir : '';
	}

	private static function pack_modules() {
		$dir = getenv( 'SADDLE_TEST_DIVI_PACK' ) ?: '/Users/fahim/Workspace/wp/divi-dev/wp-content/plugins/divi-torque-pro/modules-json';
		return is_dir( $dir ) ? $dir : '';
	}

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-settings-fixture-modules';
		if ( ! is_dir( self::$fixture_dir . '/feed' ) ) {
			mkdir( self::$fixture_dir . '/feed', 0755, true );
		}
		$item = static function ( array $extra ) {
			return array(
				'groupType' => 'group-item',
				'item'      => array_merge(
					array(
						'groupSlug' => 'content',
						'render'    => true,
					),
					$extra
				),
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
						'advanced' => array(
							'html'     => array(),
							'elements' => array(
								'groupType' => 'group-item',
								'item'      => array( 'component' => array( 'type' => 'group', 'name' => 'divi/elements' ) ),
							),
						),
					),
				),
				'post'   => array(
					'type'     => 'object',
					'settings' => array(
						'advanced' => array(
							'number'     => $item(
								array(
									'attrName'    => 'post.advanced.number',
									'label'       => 'Post Count',
									'description' => 'How many posts.',
									'features'    => array(
										'responsive' => false,
										'hover'      => false,
										'sticky'     => false,
									),
									'component'   => array(
										'type' => 'field',
										'name' => 'divi/text',
									),
								)
							),
							'categories' => $item(
								array(
									'attrName'  => 'post.advanced.categories',
									'label'     => 'Included Categories',
									'component' => array(
										'type'  => 'field',
										'name'  => 'divi/checkboxes',
										'props' => array( 'options' => array() ),
									),
								)
							),
							'order'      => $item(
								array(
									'label'     => 'Order',
									'component' => array(
										'type'  => 'field',
										'name'  => 'divi/select',
										'props' => array(
											'defaultValue' => 'desc',
											'options'      => array(
												'desc' => array( 'label' => 'Newest first' ),
												'asc'  => array( 'label' => 'Oldest first' ),
											),
										),
									),
								)
							),
							'hidden'     => $item(
								array(
									'attrName'  => 'post.advanced.hidden',
									'render'    => false,
									'component' => array(
										'type' => 'field',
										'name' => 'divi/toggle',
									),
								)
							),
							'notice'     => $item(
								array(
									'component' => array(
										'type' => 'field',
										'name' => 'divi/warning',
									),
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
										'component' => array(
											'type' => 'field',
											'name' => 'divi/toggle',
										),
									),
									'color' => array(
										'attrName'  => 'line.advanced.line',
										'subName'   => 'color',
										'label'     => 'Line color',
										'features'  => array( 'dynamicContent' => array( 'type' => 'color' ) ),
										'component' => array(
											'type' => 'field',
											'name' => 'divi/color-picker',
										),
									),
								),
							),
						),
					),
				),
				'menu'   => array(
					'type'     => 'object',
					'settings' => array(
						'advanced' => array(
							'menuId' => $item(
								array(
									'label'     => 'Menu',
									'component' => array(
										'type'  => 'field',
										'name'  => 'divi/select-menu',
										'props' => array( 'options' => array() ),
									),
								)
							),
							'ring'   => array(
								'groupType' => 'group',
								'component' => array(
									'name'  => 'divi/composite',
									'props' => array(
										'fields' => array(
											'ringColor' => array(
												'attrName'  => 'menu.advanced.ringColor',
												'label'     => 'Ring color',
												'component' => array(
													'type' => 'field',
													'name' => 'divi/color-picker',
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
		);
		file_put_contents( self::$fixture_dir . '/feed/module.json', wp_json_encode( $module ) );
		file_put_contents(
			self::$fixture_dir . '/feed/module-default-render-attributes.json',
			wp_json_encode(
				array(
					'post' => array( 'advanced' => array( 'number' => array( 'desktop' => array( 'value' => '10' ) ) ) ),
					'line' => array(
						'advanced' => array(
							'line' => array(
								'desktop' => array(
									'value' => array(
										'show'  => 'on',
										'color' => '$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$',
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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->use_dirs( array( self::$fixture_dir ) );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	private function use_dirs( array $dirs ) {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dirs ) {
				return $dirs;
			}
		);
		Saddle_Divi_Schema::index( true );
	}

	private function assert_well_formed( array $entry, $type ) {
		foreach ( self::KEYS as $key ) {
			$this->assertArrayHasKey( $key, $entry, "$type: an entry lacks $key." );
		}
		$this->assertNotSame( '', $entry['path'], "$type: empty path." );
		$this->assertStringNotContainsString( '.desktop', $entry['path'], "$type {$entry['id']}: the path must stop before the breakpoint." );
		$this->assertContains( $entry['control'], self::CONTROLS, "$type {$entry['id']}: unknown control." );
		$this->assertContains( $entry['value_shape'], array( 'scalar', 'object', 'list' ) );
		if ( 'object' === $entry['value_shape'] ) {
			$this->assertNotEmpty( $entry['sub_fields'], "$type {$entry['id']}: an object names its fields." );
		}
		foreach ( array( 'responsive', 'hover', 'sticky', 'dynamic' ) as $flag ) {
			$this->assertIsBool( $entry[ $flag ] );
		}
		if ( 'toggle' === $entry['control'] ) {
			$this->assertSame( array( 'on', 'off' ), array_keys( $entry['options'] ), "$type {$entry['id']}: toggles are the strings on and off." );
		}
	}

	private function by_id( $type ) {
		return array_column( Saddle_Divi_Settings::for_module( $type ), null, 'id' );
	}

	/* -------- reading module.json -------- */

	public function test_paths_come_from_attr_name_or_fall_back_to_the_group_key() {
		$settings = $this->by_id( 'saddletest/feed' );

		$this->assertSame( 'post.advanced.number', $settings['post.advanced.number']['path'] );
		$this->assertSame( 'post', $settings['post.advanced.number']['attr'] );
		$this->assertSame( 'number', $settings['post.advanced.number']['key'] );
		// No attrName: Divi stores it at <attr>.advanced.<group key>, as it
		// does for the Menu module's menuId.
		$this->assertArrayHasKey( 'post.advanced.order', $settings );
		$this->assertArrayHasKey( 'menu.advanced.menuId', $settings );
	}

	public function test_sub_named_items_share_a_path_and_keep_their_own_id() {
		$settings = $this->by_id( 'saddletest/feed' );

		$show = $settings['line.advanced.line.show'];
		$this->assertSame( 'line.advanced.line', $show['path'] );
		$this->assertSame( 'show', $show['sub_name'] );
		$this->assertSame( 'show', $show['key'] );
		$this->assertSame( 'on', $show['default'] );
		$this->assertSame( 'line.advanced.line', $settings['line.advanced.line.color']['path'] );
	}

	public function test_labels_controls_flags_defaults_and_choices() {
		$settings = $this->by_id( 'saddletest/feed' );

		$number = $settings['post.advanced.number'];
		$this->assertSame( 'Post Count', $number['label'] );
		$this->assertSame( 'How many posts.', $number['description'] );
		$this->assertSame( 'text', $number['control'] );
		$this->assertSame( '10', $number['default'], 'From the default render attributes.' );
		$this->assertFalse( $number['responsive'] );
		$this->assertFalse( $number['hover'] );

		$order = $settings['post.advanced.order'];
		$this->assertSame(
			array(
				'desc' => 'Newest first',
				'asc'  => 'Oldest first',
			),
			$order['options']
		);
		$this->assertSame( 'desc', $order['default'], 'From the control\'s defaultValue.' );
		$this->assertTrue( $order['responsive'], 'Divi\'s field defaults are responsive, hover and sticky on.' );
		$this->assertFalse( $order['dynamic'], '…and dynamic content off.' );

		$color = $settings['line.advanced.line.color'];
		$this->assertTrue( $color['dynamic'] );
		$this->assertSame( 'var(--gcid-primary-color)', $color['default'], 'Divi\'s colour token is shown in the form Saddle teaches.' );
	}

	public function test_plumbing_hidden_items_and_groups_are_skipped_but_group_fields_are_read() {
		$ids = array_keys( $this->by_id( 'saddletest/feed' ) );

		$this->assertNotContains( 'post.advanced.hidden', $ids, 'render:false is not a setting.' );
		$this->assertNotContains( 'post.advanced.notice', $ids, 'A warning holds no value.' );
		$this->assertNotContains( 'module.advanced.elements', $ids );
		$this->assertNotContains( 'module.advanced.html', $ids, 'A shared empty group is documented by the style schema.' );
		$this->assertContains( 'menu.advanced.ringColor', $ids, 'A field inside a composite group is a setting of its own.' );
	}

	public function test_selects_divi_fills_at_run_time_name_their_source_and_read_wordpress() {
		$settings = $this->by_id( 'saddletest/feed' );
		$menu_id  = wp_create_nav_menu( 'Main menu' );
		$cat      = self::factory()->category->create( array( 'name' => 'News' ) );

		$menu = $settings['menu.advanced.menuId'];
		$this->assertSame( 'menus', $menu['options_source'] );
		$this->assertSame( 'Main menu', Saddle_Divi_Settings::options( $menu )[ (string) $menu_id ] );

		$categories = Saddle_Divi_Settings::options( $settings['post.advanced.categories'] );
		$this->assertSame( 'categories', $settings['post.advanced.categories']['options_source'] );
		$this->assertSame( 'News', $categories[ (string) $cat ] );
		$this->assertArrayHasKey( 'all', $categories, 'Divi reads "all" and "current" as special values.' );

		$this->assertSame( $settings['post.advanced.order']['options'], Saddle_Divi_Settings::options( $settings['post.advanced.order'] ), 'Static choices come back as they are.' );
	}

	public function test_live_choices_need_an_account_that_may_edit_posts() {
		wp_create_nav_menu( 'Main menu' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array(), Saddle_Divi_Settings::options( $this->by_id( 'saddletest/feed' )['menu.advanced.menuId'] ) );
	}

	public function test_live_choices_for_post_types_widget_areas_and_forms() {
		register_sidebar(
			array(
				'id'   => 'saddle-test-area',
				'name' => 'Test area',
			)
		);
		$this->assertSame( 'Test area', Saddle_Divi_Setting_Values::live_options( 'widget_areas' )['saddle-test-area'] );
		$types = Saddle_Divi_Setting_Values::live_options( 'post_types' );
		$this->assertArrayHasKey( 'post', $types );
		$this->assertArrayNotHasKey( 'attachment', $types );
		$this->assertSame( array(), Saddle_Divi_Setting_Values::live_options( 'cf7_forms' ), 'No Contact Form 7, no forms.' );
		unregister_sidebar( 'saddle-test-area' );
	}

	public function test_find_by_id_path_or_short_name() {
		$this->assertSame( 'post.advanced.number', Saddle_Divi_Settings::find( 'saddletest/feed', 'post.advanced.number' )['id'] );
		$this->assertSame( 'line.advanced.line.color', Saddle_Divi_Settings::find( 'saddletest/feed', 'line.advanced.line.color' )['id'] );
		$this->assertSame( 'post.advanced.number', Saddle_Divi_Settings::find( 'saddletest/feed', 'post.number' )['id'], 'Short "<attr>.<key>" when unique.' );
		$this->assertNull( Saddle_Divi_Settings::find( 'saddletest/feed', 'line.advanced.line' ), 'Two settings share that path, so it names neither.' );
		$this->assertNull( Saddle_Divi_Settings::find( 'saddletest/feed', 'post.advanced.nope' ) );
	}

	public function test_an_unknown_module_has_no_settings_and_no_error() {
		$this->assertSame( array(), Saddle_Divi_Settings::for_module( 'divi/no-such-module' ) );
		$this->assertNull( Saddle_Divi_Settings::find( 'divi/no-such-module', 'post.advanced.number' ) );
	}

	/* -------- real module.json files -------- */

	public function test_every_core_module_yields_well_formed_settings() {
		$dir = self::divi_components();
		if ( '' === $dir ) {
			$this->markTestSkipped( 'Divi 5 module.json files are not on this machine.' );
		}
		$this->use_dirs( array( $dir ) );

		$types = array_keys( Saddle_Divi_Schema::index() );
		$total = 0;
		foreach ( $types as $type ) {
			foreach ( Saddle_Divi_Settings::for_module( $type ) as $entry ) {
				$this->assert_well_formed( $entry, $type );
				++$total;
			}
		}

		$this->assertGreaterThanOrEqual( 85, count( $types ) );
		// 388 on Divi 5.10.1's 85 modules (2026-11-14).
		$this->assertGreaterThan( 350, $total, "Settings found on the core modules: $total." );

		$blog = array_column( Saddle_Divi_Settings::for_module( 'divi/blog' ), null, 'id' );
		$this->assertSame( '10', $blog['post.advanced.number']['default'] );
		$this->assertSame( 'post_types', $blog['post.advanced.type']['options_source'] );
		$menu = array_column( Saddle_Divi_Settings::for_module( 'divi/menu' ), null, 'id' );
		$this->assertSame( 'menus', $menu['menu.advanced.menuId']['options_source'] );
		$this->assertSame( 'left_aligned', $menu['menu.advanced.style']['default'] );
		$sidebar = array_column( Saddle_Divi_Settings::for_module( 'divi/sidebar' ), null, 'id' );
		$this->assertSame( 'widget_areas', $sidebar['sidebar.innerContent.area']['options_source'], 'A choice inside content is a setting too.' );
		$this->assertSame( 'content', $sidebar['sidebar.innerContent.area']['source'] );
		$map = array_column( Saddle_Divi_Settings::for_module( 'divi/map' ), null, 'id' );
		$this->assertArrayNotHasKey( 'map.advanced.googleAPIKey', $map, 'The site-wide Maps key is not a module setting.' );
	}

	public function test_a_module_pack_works_unchanged() {
		$dir = self::pack_modules();
		if ( '' === $dir || ! is_file( $dir . '/pricing-cards/module.json' ) ) {
			$this->markTestSkipped( 'The module pack used for this test is not on this machine.' );
		}
		$this->use_dirs( array( $dir ) );

		$cards = array_column( Saddle_Divi_Settings::for_module( 'divitorque-pro/pricing-cards' ), null, 'id' );

		$this->assertArrayHasKey( 'module.advanced.price', $cards );
		$this->assertSame( '49', $cards['module.advanced.price']['default'], 'A pack writes defaults in the attribute\'s own "default" block.' );
		$style = $cards['module.advanced.pricingStyle'];
		$this->assertSame( 'select', $style['control'] );
		$this->assertCount( 4, $style['options'] );
		$this->assertSame( 'default-style', $style['default'] );
		foreach ( $cards as $entry ) {
			$this->assert_well_formed( $entry, 'divitorque-pro/pricing-cards' );
		}
	}
}
