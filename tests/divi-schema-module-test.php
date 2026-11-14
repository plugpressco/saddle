<?php
/**
 * divi-get-module-schema and divi-list-modules: settings, notes, object
 * content, containers and the catalog.
 *
 * @package Saddle
 */

class Saddle_Divi_Schema_Module_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	private static function divi_components() {
		$dir = getenv( 'SADDLE_TEST_DIVI_COMPONENTS' ) ?: '/Users/fahim/Workspace/wp/divi-dev/wp-content/themes/Divi/includes/builder-5/visual-builder/packages/module-library/src/components';
		return is_dir( $dir ) ? $dir : '';
	}

	private static function write_module( $folder, array $json, array $render = array() ) {
		$dir = self::$fixture_dir . '/' . $folder;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $dir . '/module.json', wp_json_encode( $json ) );
		if ( $render ) {
			file_put_contents( $dir . '/module-default-render-attributes.json', wp_json_encode( $render ) );
		}
	}

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-schema-module-fixtures';

		$toggle = static function ( $attr_name, $label ) {
			return array(
				'groupType' => 'group-item',
				'item'      => array(
					'attrName'  => $attr_name,
					'label'     => $label,
					'groupSlug' => 'content',
					'component' => array(
						'type' => 'field',
						'name' => 'divi/toggle',
					),
				),
			);
		};

		self::write_module(
			'menu',
			array(
				'name'       => 'divi/menu',
				'title'      => 'Menu',
				'category'   => 'module',
				'attributes' => array(
					'menu' => array(
						'type'     => 'object',
						'settings' => array(
							'advanced' => array(
								'menuId' => array(
									'groupType' => 'group-item',
									'item'      => array(
										'label'     => 'Menu',
										'component' => array(
											'type'  => 'field',
											'name'  => 'divi/select-menu',
											'props' => array( 'options' => array() ),
										),
									),
								),
								'search' => $toggle( 'menu.advanced.search', 'Show search' ),
							),
						),
					),
				),
			)
		);
		self::write_module(
			'icon',
			array(
				'name'       => 'saddletest/icon',
				'title'      => 'Icon',
				'category'   => 'module',
				'attributes' => array(
					'icon' => array(
						'type'     => 'object',
						'settings' => array(
							'innerContent' => array(
								'groupType' => 'group-item',
								'item'      => array(
									'attrName'  => 'icon.innerContent',
									'component' => array(
										'type' => 'field',
										'name' => 'divi/icon-picker',
									),
								),
							),
						),
					),
				),
			),
			array( 'icon' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'unicode' => '&#x21;', 'type' => 'divi', 'weight' => '400' ) ) ) ) )
		);
		self::write_module(
			'map',
			array(
				'name'         => 'saddletest/map',
				'title'        => 'Map',
				'category'     => 'module',
				'childrenName' => array( 'saddletest/map-pin' ),
				'attributes'   => array(
					'map' => array(
						'type'     => 'object',
						'settings' => array(
							'innerContent' => array(
								'groupType' => 'group-item',
								'item'      => array(
									'attrName'  => 'map.innerContent',
									'component' => array(
										'type' => 'field',
										'name' => 'divi/map',
									),
								),
							),
						),
					),
				),
			)
		);
		self::write_module(
			'map-pin',
			array(
				'name'       => 'saddletest/map-pin',
				'title'      => 'Map Pin',
				'category'   => 'child-module',
				'attributes' => array(),
			)
		);
		self::write_module(
			'section',
			array(
				'name'         => 'divi/section',
				'title'        => 'Section',
				'category'     => 'structure',
				'childrenName' => array( 'divi/row' ),
				'attributes'   => array(),
			)
		);
		self::write_module(
			'row',
			array(
				'name'            => 'divi/row',
				'title'           => 'Row',
				'category'        => 'structure',
				'childModuleName' => 'divi/column',
				'attributes'      => array(),
			)
		);
		self::write_module(
			'column',
			array(
				'name'         => 'divi/column',
				'title'        => 'Column',
				'category'     => 'structure',
				'childrenName' => array(),
				'attributes'   => array(),
			)
		);
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
		$this->use_dirs( array( self::$fixture_dir ) );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_all_filters( 'saddle_divi_module_notes' );
		remove_filter( 'saddle_divi_active', '__return_true' );
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
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
	}

	private function schema( array $input ) {
		return wp_get_ability( 'saddle/divi-get-module-schema' )->execute( $input );
	}

	/* -------- settings in the schema -------- */

	public function test_the_schema_lists_settings_with_path_control_choices_and_how_to_write_them() {
		$menu_id = wp_create_nav_menu( 'Footer links' );
		$schema  = $this->schema( array( 'type' => 'divi/menu' ) );

		$rows = array_column( $schema['settings'], null, 'path' );
		$this->assertSame( 'select', $rows['menu.advanced.menuId']['control'] );
		$this->assertSame( 'menus', $rows['menu.advanced.menuId']['options_from'] );
		$this->assertSame( 'Footer links', ( (array) $rows['menu.advanced.menuId']['options'] )[ (string) $menu_id ], 'The live menus ride the compact answer.' );
		$this->assertSame( array( 'on', 'off' ), $rows['menu.advanced.search']['options'], 'Each toggle says its values are on and off.' );
		$this->assertArrayNotHasKey( 'label', $rows['menu.advanced.search'], 'Labels wait for detail=full.' );
		$this->assertStringContainsString( '"post.advanced.number.desktop.value": "6"', $schema['settings_write_as'] );
		$this->assertStringContainsString( '"on"', $schema['settings_note'] );
	}

	public function test_detail_full_adds_labels_descriptions_and_flags() {
		$full = $this->schema(
			array(
				'type'   => 'divi/menu',
				'detail' => 'full',
			)
		);
		$rows = array_column( $full['settings'], null, 'id' );

		$this->assertSame( 'Show search', $rows['menu.advanced.search']['label'] );
		$this->assertArrayHasKey( 'responsive', $rows['menu.advanced.search'] );
		$this->assertArrayHasKey( 'description', $rows['menu.advanced.search'] );
	}

	public function test_verbose_still_adds_the_decoration_inventory() {
		$plain   = $this->schema( array( 'type' => 'divi/menu' ) );
		$verbose = $this->schema(
			array(
				'type'    => 'divi/menu',
				'verbose' => true,
			)
		);
		$this->assertArrayNotHasKey( 'advanced_options', $plain['attributes'][0] );
		$this->assertSame( array( 'menuId', 'search' ), $verbose['attributes'][0]['advanced_options'] );
		$this->assertArrayHasKey( 'settings', $verbose, 'verbose keeps the settings list.' );
	}

	/* -------- notes -------- */

	public function test_module_notes_ride_the_schema_and_plugins_can_add_their_own() {
		$schema = $this->schema( array( 'type' => 'divi/menu' ) );
		$this->assertStringContainsString( 'primary-menu location', implode( ' ', $schema['notes'] ), 'The no-menu fallback is a known trap (MenuUtils.php).' );

		add_filter(
			'saddle_divi_module_notes',
			static function ( $notes, $type ) {
				if ( 'saddletest/icon' === $type ) {
					$notes[] = 'Pick an icon from the pack.';
				}
				return $notes;
			},
			10,
			2
		);
		$this->assertSame( array( 'Pick an icon from the pack.' ), $this->schema( array( 'type' => 'saddletest/icon' ) )['notes'] );
	}

	/* -------- B15: object content -------- */

	/**
	 * Bug B15: module.json gives an icon's or a map's content no sub-names,
	 * so Saddle told agents to write a string. Divi stores an object.
	 */
	public function test_icon_and_map_content_is_an_object_with_its_fields() {
		$icon = array_column( $this->schema( array( 'type' => 'saddletest/icon' ) )['attributes'], null, 'name' )['icon'];
		$this->assertSame( 'object', $icon['content_shape'] );
		$this->assertSame( array( 'unicode', 'type', 'weight' ), $icon['content_fields'] );

		$map = array_column( $this->schema( array( 'type' => 'saddletest/map' ) )['attributes'], null, 'name' )['map'];
		$this->assertSame( 'object', $map['content_shape'], 'Read from the map control even with no default file.' );
		$this->assertSame( array( 'address', 'zoom', 'lat', 'lng' ), $map['content_fields'] );
	}

	public function test_divis_own_icon_map_and_pin_are_objects() {
		$dir = self::divi_components();
		if ( '' === $dir ) {
			$this->markTestSkipped( 'Divi 5 module.json files are not on this machine.' );
		}
		$this->use_dirs( array( $dir ) );

		foreach ( array(
			'divi/icon'           => array( 'icon', 'unicode' ),
			'divi/icon-list-item' => array( 'icon', 'unicode' ),
			'divi/map'            => array( 'map', 'address' ),
			'divi/fullwidth-map'  => array( 'map', 'address' ),
			'divi/map-pin'        => array( 'pin', 'lat' ),
		) as $type => $want ) {
			$attr = array_column( Saddle_Divi_Schema::describe( $type )['attributes'], null, 'name' )[ $want[0] ];
			$this->assertSame( 'object', $attr['content_shape'], $type );
			$this->assertContains( $want[1], $attr['content_fields'], $type );
		}

		// The default answer stays small enough to read.
		foreach ( array( 'divi/blog', 'divi/post-slider', 'divi/menu', 'divi/slider' ) as $type ) {
			$this->assertLessThan( 6144, strlen( (string) wp_json_encode( $this->schema( array( 'type' => $type ) ) ) ), $type );
		}
	}

	/* -------- B14: containers and the catalog -------- */

	/**
	 * Bug B14: the catalog read childrenName only, so section, row and
	 * column were "not containers".
	 */
	public function test_structure_modules_and_parents_are_containers_with_their_child_types() {
		$catalog = array_column( Saddle_Divi_Catalog::modules(), null, 'type' );

		$this->assertTrue( $catalog['divi/section']['container'] );
		$this->assertSame( array( 'divi/row' ), $catalog['divi/section']['children'] );
		$this->assertSame( array( 'divi/column' ), $catalog['divi/row']['children'], 'A row names its column in childModuleName.' );
		$this->assertSame( array( '*' ), $catalog['divi/column']['children'] );
		$this->assertSame( array( 'saddletest/map-pin' ), $catalog['saddletest/map']['children'] );
		$this->assertFalse( $catalog['saddletest/icon']['container'] );
		$this->assertTrue( $catalog['saddletest/map-pin']['child'] );

		$this->assertTrue( Saddle_Divi_Schema::describe( 'divi/row' )['container'] );
	}

	public function test_list_modules_gives_purpose_and_group_and_searches() {
		$all = wp_get_ability( 'saddle/divi-list-modules' )->execute( array() );
		$row = array_column( $all['modules'], null, 'type' )['divi/section'];
		$this->assertSame( 'layout', $row['group'] );
		$this->assertNotSame( '', $row['purpose'] );
		$this->assertSame( 'navigation', array_column( $all['modules'], null, 'type' )['divi/menu']['group'] );
		$this->assertSame( 'Icon', array_column( $all['modules'], null, 'type' )['saddletest/icon']['purpose'], 'A module with no description falls back to its title.' );
		$this->assertSame( 'pack: saddletest', array_column( $all['modules'], null, 'type' )['saddletest/icon']['group'], 'A module outside Divi\'s namespace is a pack.' );

		$found = wp_get_ability( 'saddle/divi-list-modules' )->execute( array( 'search' => 'band' ) );
		$this->assertSame( array( 'divi/section' ), wp_list_pluck( $found['modules'], 'type' ), 'Search reads the purpose line.' );
		$this->assertSame( array(), wp_get_ability( 'saddle/divi-list-modules' )->execute( array( 'search' => 'zzz-nothing' ) )['modules'] );
	}

	public function test_a_pack_module_is_grouped_by_its_plugin() {
		$pack = WP_PLUGIN_DIR . '/saddletest-pricing-pack/modules-json/cards';
		if ( ! is_dir( $pack ) ) {
			mkdir( $pack, 0777, true );
		}
		file_put_contents(
			$pack . '/module.json',
			wp_json_encode(
				array(
					'name'        => 'saddletest-pack/cards',
					'title'       => 'Pricing Cards',
					'description' => 'Plan cards with a price and a button.',
					'category'    => 'module',
					'attributes'  => array(),
				)
			)
		);
		$this->use_dirs( array( dirname( $pack ) ) );

		$found = wp_get_ability( 'saddle/divi-list-modules' )->execute( array( 'search' => 'pricing' ) );

		unlink( $pack . '/module.json' );
		rmdir( $pack );
		rmdir( dirname( $pack ) );
		rmdir( dirname( $pack, 2 ) );

		$this->assertCount( 1, $found['modules'] );
		$this->assertSame( 'pack: saddletest-pricing-pack', $found['modules'][0]['group'] );
		$this->assertSame( 'Plan cards with a price and a button.', $found['modules'][0]['purpose'], 'A pack module\'s own description is its purpose.' );
	}
}
