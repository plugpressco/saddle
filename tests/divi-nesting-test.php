<?php
/**
 * Divi 5's nesting rule in the validator (Saddle_Divi_Nesting), Components
 * (Saddle_Divi_Components) and the post types the Divi tools open.
 *
 * Before this, a row inside a column or a group was refused although Divi 5
 * builds exactly that, a child module (an accordion item) could sit in any
 * module, and the tools opened posts and pages only.
 *
 * @package Saddle
 */

class Saddle_Divi_Nesting_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-nesting-fixture';
		$modules           = array(
			'parent'     => array(
				'name'         => 'fix/parent',
				'category'     => 'module',
				'childrenName' => array( 'fix/item' ),
			),
			'parent-all' => array(
				'name'             => 'fix/parent-all',
				'category'         => 'module',
				'childrenName'     => array( 'fix/item' ),
				'allowAllElements' => true,
			),
			'item'       => array(
				'name'         => 'fix/item',
				'category'     => 'child-module',
				'childrenName' => array(),
			),
			'leaf'       => array(
				'name'     => 'fix/leaf',
				'category' => 'module',
			),
			'box'        => array(
				'name'         => 'fix/box',
				'category'     => 'module',
				'childrenName' => array(),
			),
		);
		foreach ( $modules as $dir => $json ) {
			if ( ! is_dir( self::$fixture_dir . '/' . $dir ) ) {
				mkdir( self::$fixture_dir . '/' . $dir, 0755, true );
			}
			file_put_contents( self::$fixture_dir . '/' . $dir . '/module.json', wp_json_encode( $json + array( 'attributes' => array() ) ) );
		}
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
		register_post_type( 'et_pb_layout', array( 'public' => false ) );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_all_filters( 'saddle_divi_editable_post_types' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		unregister_post_type( 'et_pb_layout' );
		if ( post_type_exists( 'project' ) ) {
			unregister_post_type( 'project' );
		}
		Saddle_Divi_Schema::index( true );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	/** A block from [type, attrs, children]. */
	private function b( $type, array $children = array(), array $attrs = array() ) {
		return Saddle_Divi_Tree::make_module( $type, $attrs, '', $children );
	}

	/** Validate a page whose one section holds one row > column > $inside. */
	private function in_column( array $inside ) {
		return Saddle_Divi_Tree::validate(
			array( $this->b( 'divi/section', array( $this->b( 'divi/row', array( $this->b( 'divi/column', $inside ) ) ) ) ) )
		);
	}

	private function problem( $result ) {
		$this->assertWPError( $result );
		return $result->get_error_data()['violations'][0]['problem'];
	}

	/* -------- structure -------- */

	public function test_a_nested_row_sits_in_a_column_and_in_a_group() {
		$nested = $this->b( 'divi/row', array( $this->b( 'divi/column', array( $this->b( 'divi/text' ) ) ) ) );
		$this->assertTrue( $this->in_column( array( $nested ) ) );
		$this->assertTrue( $this->in_column( array( $this->b( 'divi/group', array( $nested, $this->b( 'divi/group', array( $this->b( 'divi/text' ) ) ) ) ) ) ) );
	}

	public function test_a_group_carousel_holds_groups_only() {
		$this->assertTrue( $this->in_column( array( $this->b( 'divi/group-carousel', array( $this->b( 'divi/group' ), $this->b( 'divi/group' ) ) ) ) ) );

		$problem = $this->problem( $this->in_column( array( $this->b( 'divi/group-carousel', array( $this->b( 'divi/text' ) ) ) ) ) );
		$this->assertStringContainsString( 'divi/group-carousel holds: divi/group', $problem );
	}

	public function test_page_structure_still_cannot_go_where_divi_refuses_it() {
		$this->assertWPError( $this->in_column( array( $this->b( 'divi/column' ) ) ) );
		$this->assertWPError( $this->in_column( array( $this->b( 'divi/section' ) ) ) );
		$this->assertWPError(
			Saddle_Divi_Tree::validate( array( $this->b( 'divi/section', array( $this->b( 'divi/column' ) ) ) ) ),
			'A regular section holds rows.'
		);
		$problem = $this->problem( $this->in_column( array( $this->b( 'divi/column-inner', array( $this->b( 'divi/row' ) ) ) ) ) );
		$this->assertStringContainsString( 'divi/column-inner', $problem );
	}

	public function test_specialty_and_fullwidth_sections() {
		$specialty = $this->b(
			'divi/section',
			array(
				$this->b(
					'divi/column',
					array( $this->b( 'divi/row-inner', array( $this->b( 'divi/column-inner', array( $this->b( 'divi/text' ) ) ) ) ) ),
					array( 'module' => array( 'advanced' => array( 'specialtyColumns' => array( 'desktop' => array( 'value' => '2' ) ) ) ) )
				),
			),
			array( 'module' => array( 'advanced' => array( 'type' => array( 'desktop' => array( 'value' => 'specialty' ) ) ) ) )
		);
		$this->assertTrue( Saddle_Divi_Tree::validate( array( $specialty ) ) );

		$fullwidth = array( 'module' => array( 'advanced' => array( 'type' => array( 'desktop' => array( 'value' => 'fullwidth' ) ) ) ) );
		$this->assertTrue( Saddle_Divi_Tree::validate( array( $this->b( 'divi/section', array( $this->b( 'fix/leaf' ) ), $fullwidth ) ) ) );
		$this->assertWPError( Saddle_Divi_Tree::validate( array( $this->b( 'divi/section', array( $this->b( 'divi/row' ) ), $fullwidth ) ) ) );
	}

	/* -------- module.json-driven parents -------- */

	public function test_a_parent_holds_only_its_declared_child() {
		$this->assertTrue( $this->in_column( array( $this->b( 'fix/parent', array( $this->b( 'fix/item' ) ) ) ) ) );

		$problem = $this->problem( $this->in_column( array( $this->b( 'fix/parent', array( $this->b( 'fix/leaf' ) ) ) ) ) );
		$this->assertStringContainsString( 'fix/leaf cannot be placed inside fix/parent. fix/parent holds: fix/item', $problem );

		$this->assertTrue( $this->in_column( array( $this->b( 'fix/parent-all', array( $this->b( 'fix/item' ), $this->b( 'fix/leaf' ) ) ) ) ), 'allowAllElements adds any module.' );
	}

	public function test_a_child_module_outside_its_parent_is_refused() {
		$problem = $this->problem( $this->in_column( array( $this->b( 'fix/item' ) ) ) );
		$this->assertStringContainsString( 'fix/item cannot be placed inside divi/column', $problem );
		$this->assertStringContainsString( 'not a child module', $problem );

		$this->assertWPError( $this->in_column( array( $this->b( 'fix/box', array( $this->b( 'fix/item' ) ) ) ) ) );
	}

	public function test_nested_modules_follow_an_empty_children_list() {
		$this->assertTrue( $this->in_column( array( $this->b( 'fix/box', array( $this->b( 'fix/leaf' ), $this->b( 'divi/group' ) ) ) ) ) );

		$problem = $this->problem( $this->in_column( array( $this->b( 'fix/leaf', array( $this->b( 'fix/leaf' ) ) ) ) ) );
		$this->assertStringContainsString( 'takes no child modules', $problem );
	}

	public function test_a_module_with_no_module_json_is_judged_leniently() {
		$this->assertTrue( $this->in_column( array( $this->b( 'otherpack/slider', array( $this->b( 'otherpack/slide' ) ) ) ) ) );
		$this->assertWPError( $this->in_column( array( $this->b( 'otherpack/slider', array( $this->b( 'divi/row' ) ) ) ) ) );
	}

	public function test_the_real_divi_accordion_takes_its_items() {
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

		$this->assertTrue( $this->in_column( array( $this->b( 'divi/accordion', array( $this->b( 'divi/accordion-item' ) ) ) ) ) );
		$this->assertWPError( $this->in_column( array( $this->b( 'divi/accordion-item' ) ) ) );
		$this->assertWPError( $this->in_column( array( $this->b( 'divi/tabs', array( $this->b( 'divi/accordion-item' ) ) ) ) ) );
		$this->assertTrue( $this->in_column( array( $this->b( 'divi/text', array( $this->b( 'divi/button' ) ) ) ) ), 'Divi 5 nested modules.' );
		$this->assertTrue( $this->in_column( array( $this->b( 'divi/group', array( $this->b( 'divi/group-carousel', array( $this->b( 'divi/group' ) ) ) ) ) ) ) );

		$fullwidth = array( 'module' => array( 'advanced' => array( 'type' => array( 'desktop' => array( 'value' => 'fullwidth' ) ) ) ) );
		$this->assertTrue( Saddle_Divi_Tree::validate( array( $this->b( 'divi/section', array( $this->b( 'divi/fullwidth-header' ) ), $fullwidth ) ) ) );
		$this->assertWPError( Saddle_Divi_Tree::validate( array( $this->b( 'divi/section', array( $this->b( 'divi/group' ) ), $fullwidth ) ) ), 'A full-width section takes modules, not groups.' );
	}

	/* -------- Components -------- */

	private function library( $content, $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'et_pb_layout',
				'post_status'  => $status,
				'post_content' => $content,
			)
		);
	}

	public function test_a_component_is_placed_as_the_block_it_stands_for() {
		$section = $this->b( 'divi/global-layout', array(), array( 'globalModule' => '7', 'blockName' => 'divi/section' ) );
		$row     = $this->b( 'divi/global-layout', array(), array( 'globalModule' => '8', 'blockName' => 'divi/row' ) );

		$this->assertTrue( Saddle_Divi_Tree::validate( array( $this->b( 'divi/placeholder', array( $section, $this->b( 'divi/section', array( $row ) ) ) ) ) ) );
		$this->assertWPError( Saddle_Divi_Tree::validate( array( $this->b( 'divi/section', array( $section ) ) ) ), 'A section Component inside a section nests sections.' );

		// What the Visual Builder keeps inside a Component is not judged.
		$snapshot = $this->b( 'divi/global-layout', array( $this->b( 'divi/row' ) ), array( 'globalModule' => '9', 'blockName' => 'divi/text' ) );
		$this->assertTrue( $this->in_column( array( $snapshot ) ) );
	}

	public function test_an_agent_component_is_checked_and_completed() {
		$id = $this->library( '<!-- wp:divi/placeholder --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/global-slot {"slotId":"s-1"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/placeholder -->' );

		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/global-layout',
				'attrs' => array( 'globalModule' => $id ),
			)
		);
		$this->assertNotWPError( $block );
		$this->assertSame( (string) $id, $block['attrs']['globalModule'], 'Stored as the quoted number Divi\'s parser wants.' );
		$this->assertSame( 'divi/row', $block['attrs']['blockName'] );

		$filled = Saddle_Divi_Author::expand_node(
			array(
				'type'     => 'divi/global-layout',
				'attrs'    => array( 'globalModule' => (string) $id ),
				'children' => array(
					array(
						'type'     => 'divi/global-slot',
						'attrs'    => array( 'slotId' => 's-1' ),
						'children' => array( array( 'type' => 'divi/button' ) ),
					),
				),
			)
		);
		$this->assertNotWPError( $filled );
	}

	public function test_bad_components_are_refused() {
		$id    = $this->library( '<!-- wp:divi/placeholder --><!-- wp:divi/blurb /--><!-- /wp:divi/placeholder -->' );
		$draft = $this->library( '<!-- wp:divi/text /-->', 'draft' );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );

		foreach ( array( '999999', (string) $draft, (string) $page, 'abc' ) as $bad ) {
			$result = Saddle_Divi_Author::expand_node(
				array(
					'type'  => 'divi/global-layout',
					'attrs' => array( 'globalModule' => $bad ),
				),
				'0.0.0.0'
			);
			$this->assertWPError( $result, "Library id $bad must be refused." );
			$this->assertStringContainsString( 'Node 0.0.0.0 (divi/global-layout)', $result->get_error_message() );
		}

		$mismatch = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/global-layout',
				'attrs' => array(
					'globalModule' => (string) $id,
					'blockName'    => 'divi/section',
				),
			)
		);
		$this->assertWPError( $mismatch );
		$this->assertStringContainsString( 'divi/blurb', $mismatch->get_error_message() );

		$not_slot = Saddle_Divi_Author::expand_node(
			array(
				'type'     => 'divi/global-layout',
				'attrs'    => array( 'globalModule' => (string) $id ),
				'children' => array( array( 'type' => 'divi/text' ) ),
			)
		);
		$this->assertWPError( $not_slot );

		$wrong_slot = Saddle_Divi_Author::expand_node(
			array(
				'type'     => 'divi/global-layout',
				'attrs'    => array( 'globalModule' => (string) $id ),
				'children' => array(
					array(
						'type'  => 'divi/global-slot',
						'attrs' => array( 'slotId' => 'nope' ),
					),
				),
			)
		);
		$this->assertWPError( $wrong_slot );
		$this->assertStringContainsString( 'no Slot "nope"', $wrong_slot->get_error_message() );
	}

	/* -------- post types -------- */

	public function test_the_divi_tools_open_public_custom_post_types_but_not_the_library() {
		register_post_type( 'project', array( 'public' => true ) );

		$types = Saddle_Divi::editable_post_types();
		$this->assertContains( 'page', $types );
		$this->assertContains( 'post', $types );
		$this->assertContains( 'project', $types );
		$this->assertNotContains( 'et_pb_layout', $types );
		$this->assertNotContains( 'attachment', $types );

		$project = self::factory()->post->create( array( 'post_type' => 'project', 'post_content' => '' ) );
		$result  = wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $project,
				'nodes'   => array( array( 'type' => 'divi/section' ) ),
			)
		);
		$this->assertNotWPError( $result );

		$edit = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $project,
				'address' => '0.0',
				'attrs'   => array( 'module.decoration.background.color' => '#000000' ),
			)
		);
		$this->assertNotWPError( $edit );
	}

	public function test_the_post_type_list_is_a_filter_and_the_library_stays_closed_by_default() {
		$library = $this->library( '<!-- wp:divi/placeholder --><!-- wp:divi/section /--><!-- /wp:divi/placeholder -->' );

		$refused = Saddle_Divi::editable_divi5_post( $library );
		$this->assertWPError( $refused );
		$this->assertSame( 'saddle_not_found', $refused->get_error_code() );
		$this->assertStringContainsString( 'They edit these post types', $refused->get_error_message() );

		add_filter(
			'saddle_divi_editable_post_types',
			static function ( $types ) {
				$types[] = 'et_pb_layout';
				return $types;
			}
		);
		$this->assertInstanceOf( 'WP_Post', Saddle_Divi::editable_divi5_post( $library ) );
	}

	public function test_check_setup_reports_breakpoints_and_post_types() {
		$result = wp_get_ability( 'saddle/divi-check-setup' )->execute( array() );
		$this->assertSame( array( 'desktop', 'tablet', 'phone' ), $result['breakpoints'] );
		$this->assertContains( 'page', $result['post_types'] );
	}
}
