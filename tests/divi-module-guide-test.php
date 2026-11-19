<?php
/**
 * saddle_divi_module_guide: the guide a module pack gives for its own
 * modules, shown in divi-list-modules and divi-get-module-schema.
 *
 * @package Saddle
 */

require_once __DIR__ . '/divi-test-paths.php';

class Saddle_Divi_Module_Guide_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	/**
	 * How often the guide filter ran, by module type.
	 *
	 * @var array<string,int>
	 */
	private $asked = array();

	private static function write_module( $folder, array $json ) {
		$dir = self::$fixture_dir . '/' . $folder;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $dir . '/module.json', wp_json_encode( $json ) );
	}

	private static function item( $attr_name, $component, array $props = array() ) {
		return array(
			'groupType' => 'group-item',
			'item'      => array(
				'attrName'  => $attr_name,
				'label'     => $attr_name,
				'groupSlug' => 'content',
				'component' => array(
					'type'  => 'field',
					'name'  => $component,
					'props' => $props,
				),
			),
		);
	}

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-module-guide-fixtures';

		$advanced = array(
			'animation' => self::item(
				'module.advanced.animation',
				'divi/select',
				array(
					'options' => array(
						'flip'  => array( 'label' => 'Flip' ),
						'slide' => array( 'label' => 'Slide' ),
					),
				)
			),
		);
		foreach ( array( 'direction', 'speed', 'trigger', 'height', 'click' ) as $key ) {
			$advanced[ $key ] = self::item( 'module.advanced.' . $key, 'divi/toggle' );
		}
		$flip = array(
			'name'       => 'saddletest-pack/flip',
			'title'      => 'Flip Card',
			'category'   => 'module',
			'attributes' => array(
				'module'     => array(
					'type'     => 'object',
					'settings' => array(
						'decoration' => array(
							'background' => array(),
							'spacing'    => array(),
						),
						'advanced'   => $advanced,
					),
				),
				'frontTitle' => array(
					'type'        => 'object',
					'elementType' => 'heading',
					'settings'    => array(
						'innerContent' => self::item( 'frontTitle.innerContent', 'divi/text' ),
					),
				),
			),
		);
		self::write_module( 'flip', $flip );
		self::write_module(
			'plain',
			array_merge(
				$flip,
				array(
					'name'  => 'saddletest-pack/plain',
					'title' => 'Plain Card',
				)
			)
		);
		self::write_module(
			'flips',
			array(
				'name'         => 'saddletest-pack/flips',
				'title'        => 'Flip Cards',
				'category'     => 'module',
				'childrenName' => array( 'saddletest-pack/flips-item' ),
				'attributes'   => array(),
			)
		);
		self::write_module(
			'flips-item',
			array(
				'name'       => 'saddletest-pack/flips-item',
				'title'      => 'Flip Cards Item',
				'category'   => 'child-module',
				'parentName' => 'saddletest-pack/flips',
				'attributes' => array(
					'frontTitle' => array(
						'type'     => 'object',
						'settings' => array(
							'innerContent' => self::item( 'frontTitle.innerContent', 'divi/text' ),
						),
					),
				),
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
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () {
				return array( self::$fixture_dir );
			}
		);
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		Saddle_Divi_Module_Guide::flush();
		$this->asked = array();
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_all_filters( 'saddle_divi_module_guide' );
		remove_all_filters( 'saddle_divi_module_notes' );
		remove_all_filters( 'saddle_divi_structure' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Divi_Schema::index( true );
		Saddle_Divi_Module_Guide::flush();
		parent::tear_down();
	}

	/**
	 * Give one module type a guide, the way a pack does: its own type only,
	 * every other type unchanged.
	 *
	 * @param string $type  Module type.
	 * @param mixed  $guide The guide.
	 */
	private function guide_for( $type, $guide ) {
		add_filter(
			'saddle_divi_module_guide',
			function ( $current, $asked ) use ( $type, $guide ) {
				if ( $asked !== $type ) {
					return $current;
				}
				$this->asked[ $type ] = isset( $this->asked[ $type ] ) ? $this->asked[ $type ] + 1 : 1;
				return $guide;
			},
			10,
			2
		);
	}

	private function example_node() {
		return array(
			'type'     => 'saddletest-pack/flip',
			'fields'   => array( 'frontTitle' => 'Hover me' ),
			'settings' => array( 'module.advanced.animation' => 'slide' ),
			'attrs'    => array( 'module.decoration.background' => array( 'color' => 'var(--gcid-primary-color)' ) ),
		);
	}

	private function flip_guide() {
		return array(
			'purpose'      => 'A card that turns over on hover to show more.',
			'use_when'     => 'A short teaser with details on the back. Not for long text.',
			'key_settings' => array( 'module.advanced.animation', 'module.advanced.direction' ),
			'example'      => $this->example_node(),
			'notes'        => array( 'Keep the front title short.' ),
		);
	}

	private function listed( array $input = array() ) {
		return array_column( wp_get_ability( 'saddle/divi-list-modules' )->execute( $input )['modules'], null, 'type' );
	}

	private function schema( $type, $detail = 'compact' ) {
		return wp_get_ability( 'saddle/divi-get-module-schema' )->execute(
			array(
				'type'   => $type,
				'detail' => $detail,
			)
		);
	}

	/* -------- where the guide shows -------- */

	public function test_a_pack_guide_shows_in_list_modules_and_the_schema() {
		$this->guide_for( 'saddletest-pack/flip', $this->flip_guide() );

		$entry = $this->listed()['saddletest-pack/flip'];
		$this->assertSame( 'A card that turns over on hover to show more.', $entry['purpose'], 'The guide\'s purpose replaces the title.' );
		$this->assertSame( 'A short teaser with details on the back. Not for long text.', $entry['use_when'] );
		$this->assertSame( array( 'saddletest-pack/flip' ), array_keys( $this->listed( array( 'search' => 'teaser' ) ) ), 'Search reads use_when.' );

		$compact = $this->schema( 'saddletest-pack/flip' );
		$this->assertSame(
			array(
				'purpose'      => 'A card that turns over on hover to show more.',
				'use_when'     => 'A short teaser with details on the back. Not for long text.',
				'key_settings' => array( 'module.advanced.animation', 'module.advanced.direction' ),
			),
			$compact['guide'],
			'The compact schema has no example.'
		);
		$this->assertSame( array( 'type', 'title', 'guide' ), array_slice( array_keys( $compact ), 0, 3 ), 'The guide sits beside the title.' );
		$this->assertSame( array( 'Keep the front title short.' ), $compact['notes'] );

		$full = $this->schema( 'saddletest-pack/flip', 'full' );
		$this->assertSame( $this->example_node(), $full['guide']['example'], 'detail=full adds the example as the pack wrote it.' );

		$this->assertSame( 1, $this->asked['saddletest-pack/flip'], 'The guide is read once per request.' );
	}

	public function test_the_bundle_catalog_stays_as_it_is() {
		$this->guide_for( 'saddletest-pack/flip', $this->flip_guide() );

		$row = array_column( Saddle_Divi_Catalog::compact(), null, 'type' )['saddletest-pack/flip'];
		$this->assertSame( array( 'type', 'title', 'group' ), array_keys( $row ) );
		$this->assertSame( 'Flip Card', $row['title'] );
	}

	/* -------- cleaning -------- */

	public function test_strings_are_trimmed_to_one_line_and_capped() {
		$this->setExpectedIncorrectUsage( 'saddle_divi_module_guide' );
		$this->guide_for(
			'saddletest-pack/flip',
			array(
				'purpose'      => "  A card\n that <b>turns</b> over.  " . str_repeat( 'x', 200 ),
				'use_when'     => str_repeat( 'y', 300 ),
				'key_settings' => array(
					'module.advanced.animation',
					'module.advanced.animation',
					'not a path',
					42,
					'module.advanced.nope',
					'module.advanced.direction',
					'module.advanced.speed',
					'module.advanced.trigger',
					'module.advanced.height',
					'module.advanced.click',
				),
				'notes'        => array( 'One.', 'Two.', array( 'not text' ), 'Three.', 'Four.', 'Five.', 'Six.' ),
			)
		);

		$schema = $this->schema( 'saddletest-pack/flip' );
		$guide  = $schema['guide'];
		$this->assertSame( 140, mb_strlen( $guide['purpose'] ) );
		$this->assertStringStartsWith( 'A card that turns over. xxx', $guide['purpose'], 'Tags and line breaks are gone.' );
		$this->assertStringEndsWith( '…', $guide['purpose'] );
		$this->assertSame( 160, mb_strlen( $guide['use_when'] ) );
		$this->assertSame(
			array( 'module.advanced.animation', 'module.advanced.direction', 'module.advanced.speed', 'module.advanced.trigger', 'module.advanced.height' ),
			$guide['key_settings'],
			'At most 5, unique, path-shaped and known to the module, in the pack\'s order.'
		);
		$this->assertSame( array( 'One.', 'Two.', 'Three.', 'Four.' ), $schema['notes'], 'At most 4 notes; a note that is not text is dropped.' );
	}

	public function test_notes_merge_with_the_notes_filter_without_duplicates() {
		add_filter(
			'saddle_divi_module_notes',
			static function ( $notes, $type ) {
				if ( 'saddletest-pack/flip' === $type ) {
					$notes[] = 'Set the back text too.';
				}
				return $notes;
			},
			10,
			2
		);
		$this->guide_for(
			'saddletest-pack/flip',
			array( 'notes' => array( 'Set the back text too.', 'Keep the front short.', ' Keep the front short. ' ) )
		);

		$schema = $this->schema( 'saddletest-pack/flip' );
		$this->assertSame( array( 'Set the back text too.', 'Keep the front short.' ), $schema['notes'] );
		$this->assertArrayNotHasKey( 'guide', $schema, 'A guide with only notes adds no guide block.' );
	}

	/* -------- the example -------- */

	public function test_a_valid_example_is_kept_and_checked_once_per_request() {
		$this->guide_for( 'saddletest-pack/flip', $this->flip_guide() );
		$checks = 0;
		add_filter(
			'saddle_divi_structure',
			static function ( $rules ) use ( &$checks ) {
				++$checks;
				return $rules;
			}
		);

		$this->assertSame( $this->example_node(), Saddle_Divi_Module_Guide::example( 'saddletest-pack/flip' ) );
		$this->assertGreaterThan( 0, $checks, 'The example went through the structure check.' );
		$after_first = $checks;
		$this->schema( 'saddletest-pack/flip', 'full' );
		$this->schema( 'saddletest-pack/flip', 'full' );
		$this->assertSame( $after_first, $checks, 'Checked once per request.' );
	}

	public function test_a_child_module_example_is_checked_inside_its_parent() {
		$node = array(
			'type'   => 'saddletest-pack/flips-item',
			'fields' => array( 'frontTitle' => 'One' ),
		);
		$this->guide_for( 'saddletest-pack/flips-item', array( 'example' => $node ) );

		$this->assertSame( $node, Saddle_Divi_Module_Guide::example( 'saddletest-pack/flips-item' ) );
	}

	public function test_an_example_for_a_real_pack_module_passes() {
		$pack = Saddle_Test_Divi_Paths::pack_or_skip();
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $pack ) {
				return array( self::$fixture_dir, $pack );
			}
		);
		Saddle_Divi_Schema::index( true );
		$node = array(
			'type'     => 'divitorque-pro/flip-box',
			'fields'   => array(
				'frontTitle'      => 'Hover me',
				'backTitle'       => 'More inside',
				'backDescription' => '<p>Short text.</p>',
			),
			'settings' => array( 'module.advanced.animationType' => 'flip' ),
			'attrs'    => array(
				'front.decoration.background' => array( 'color' => 'var(--gcid-primary-color)' ),
				'back.decoration.background'  => array( 'color' => '#ffffff' ),
			),
		);
		$this->guide_for(
			'divitorque-pro/flip-box',
			array(
				'key_settings' => array( 'module.advanced.animationType' ),
				'example'      => $node,
			)
		);

		$guide = $this->schema( 'divitorque-pro/flip-box', 'full' )['guide'];
		$this->assertSame( array( 'module.advanced.animationType' ), $guide['key_settings'] );
		$this->assertSame( $node, $guide['example'] );
	}

	/**
	 * @dataProvider broken_examples
	 */
	public function test_a_broken_example_is_dropped_with_a_debug_note( $example ) {
		$this->setExpectedIncorrectUsage( 'saddle_divi_module_guide' );
		$guide            = $this->flip_guide();
		$guide['example'] = $example;
		$this->guide_for( 'saddletest-pack/flip', $guide );

		$full = $this->schema( 'saddletest-pack/flip', 'full' );
		$this->assertArrayNotHasKey( 'example', $full['guide'] );
		$this->assertSame( 'A card that turns over on hover to show more.', $full['guide']['purpose'], 'The rest of the guide stays.' );
	}

	public function broken_examples() {
		$node = array(
			'type'   => 'saddletest-pack/flip',
			'fields' => array( 'frontTitle' => 'Hover me' ),
		);
		return array(
			'not a node'         => array( 'divi/text' ),
			'an unknown key'     => array( $node + array( 'content' => 'Hover me' ) ),
			'an unknown setting' => array( $node + array( 'settings' => array( 'module.advanced.nope' => 'on' ) ) ),
			'a bad choice'       => array( $node + array( 'settings' => array( 'module.advanced.animation' => 'spin' ) ) ),
			'a field Divi skips' => array( array_merge( $node, array( 'fields' => array( 'backTitle' => 'Ignored' ) ) ) ),
			'a wrong nesting'    => array(
				array(
					'type'     => 'divi/row',
					'children' => array( $node ),
				),
			),
			'over 2 KB'          => array( array_merge( $node, array( 'fields' => array( 'frontTitle' => str_repeat( 'a', 2100 ) ) ) ) ),
		);
	}

	/* -------- no guide -------- */

	public function test_a_guide_that_is_not_an_array_is_ignored() {
		$before = array(
			'list'   => $this->listed()['saddletest-pack/flip'],
			'schema' => $this->schema( 'saddletest-pack/flip', 'full' ),
		);
		Saddle_Divi_Module_Guide::flush();
		$this->guide_for( 'saddletest-pack/flip', 'A card that flips.' );

		$this->assertSame( $before['list'], $this->listed()['saddletest-pack/flip'] );
		$this->assertEquals( $before['schema'], $this->schema( 'saddletest-pack/flip', 'full' ), 'Same keys and values; the choices are objects, so not the same instances.' );
	}

	public function test_a_module_with_no_guide_keeps_todays_output() {
		$this->guide_for( 'saddletest-pack/flip', $this->flip_guide() );

		$entry = $this->listed()['saddletest-pack/plain'];
		$this->assertSame( array( 'type', 'title', 'purpose', 'group', 'container', 'child' ), array_keys( $entry ) );
		$this->assertSame( 'Plain Card', $entry['purpose'], 'The title stays the purpose.' );

		foreach ( array( 'compact', 'full' ) as $detail ) {
			$schema = $this->schema( 'saddletest-pack/plain', $detail );
			$this->assertSame( array( 'type', 'title', 'container', 'children', 'child', 'attributes', 'usage', 'settings', 'settings_write_as', 'settings_note' ), array_slice( array_keys( $schema ), 0, 10 ), $detail );
			$this->assertArrayNotHasKey( 'guide', $schema, $detail );
			$this->assertArrayNotHasKey( 'notes', $schema, $detail );
		}
		$this->assertSame( array(), Saddle_Divi_Module_Notes::for_module( 'divi/text' ) );
		$this->assertCount( 1, Saddle_Divi_Module_Notes::for_module( 'divi/menu' ), 'Hand-kept notes are unchanged.' );
	}
}
