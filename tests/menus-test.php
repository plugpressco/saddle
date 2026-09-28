<?php
/**
 * Navigation menu abilities (#244).
 *
 * What must hold: menus and locations list; a menu reads in display order
 * with parent, position and depth; items add as custom links, posts or terms
 * at a position; an update changes only the fields passed; a move carries the
 * subtree and refuses a cycle; removing is gated and lifts the children one
 * level; locations assign; reads work at the read tier, writes don't.
 *
 * @package Saddle
 */

class Saddle_Menus_Test extends WP_UnitTestCase {

	private $menu;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		register_nav_menus( array( 'primary' => 'Primary menu' ) );
		$this->menu = wp_create_nav_menu( 'Main' );
	}

	public function tear_down() {
		unregister_nav_menu( 'primary' );
		remove_theme_mod( 'nav_menu_locations' );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	private function add_custom( $title, array $extra = array() ) {
		$result = $this->run_ability(
			'add-menu-item',
			array(
				'menu'  => $this->menu,
				'type'  => 'custom',
				'url'   => 'https://example.com/' . sanitize_title( $title ),
				'title' => $title,
			) + $extra
		);
		$this->assertNotWPError( $result );
		return $result['added'];
	}

	/** Titles in display order, with depth. */
	private function outline() {
		$menu = $this->run_ability( 'get-menu', array( 'menu' => $this->menu ) );
		return array_map(
			static function ( $item ) {
				return str_repeat( '-', $item['depth'] ) . $item['title'];
			},
			$menu['items']
		);
	}

	public function test_items_add_at_a_position_and_read_in_order() {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		$cat  = self::factory()->category->create( array( 'name' => 'News' ) );

		$this->add_custom( 'Home' );
		$this->run_ability(
			'add-menu-item',
			array(
				'menu'      => $this->menu,
				'type'      => 'post',
				'object_id' => $page,
			)
		);
		$this->run_ability(
			'add-menu-item',
			array(
				'menu'      => 'main',
				'type'      => 'term',
				'object_id' => $cat,
				'position'  => 1,
			)
		);

		$this->assertSame( array( 'Home', 'News', 'About' ), $this->outline() );

		$menu = $this->run_ability( 'get-menu', array( 'menu' => $this->menu ) );
		$this->assertSame( 'taxonomy', $menu['items'][1]['type'] );
		$this->assertSame( 'category', $menu['items'][1]['object'] );
		$this->assertSame( $page, $menu['items'][2]['object_id'] );
	}

	public function test_an_update_changes_only_the_fields_passed() {
		$id = $this->add_custom( 'Docs', array( 'target' => '_blank' ) );

		$this->run_ability(
			'update-menu-item',
			array(
				'id'    => $id,
				'title' => 'Documentation',
			)
		);

		$item = wp_setup_nav_menu_item( get_post( $id ) );
		$this->assertSame( 'Documentation', $item->title );
		$this->assertSame( 'https://example.com/docs', $item->url );
		$this->assertSame( '_blank', $item->target, 'Fields not passed must survive.' );
	}

	public function test_a_move_carries_the_subtree_and_refuses_a_cycle() {
		$a  = $this->add_custom( 'A' );
		$a1 = $this->add_custom( 'A1', array( 'parent' => $a ) );
		$b  = $this->add_custom( 'B' );

		$this->run_ability(
			'move-menu-item',
			array(
				'id'       => $a,
				'parent'   => $b,
				'position' => 0,
			)
		);
		$this->assertSame( array( 'B', '-A', '--A1' ), $this->outline() );

		$cycle = $this->run_ability(
			'move-menu-item',
			array(
				'id'     => $b,
				'parent' => $a1,
			)
		);
		$this->assertWPError( $cycle );
		$this->assertSame( array( 'B', '-A', '--A1' ), $this->outline() );
	}

	public function test_removing_is_gated_and_lifts_the_children() {
		$a  = $this->add_custom( 'A' );
		$a1 = $this->add_custom( 'A1', array( 'parent' => $a ) );

		$preview = $this->run_ability( 'remove-menu-item', array( 'id' => $a ) );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertSame( 1, $preview['preview']['children_move_up'] );
		$this->assertNotNull( get_post( $a ), 'A preview must not remove anything.' );

		$input = array(
			'id'            => $a,
			'confirm_token' => $preview['confirm_token'],
		);
		$this->assertNotWPError( $this->run_ability( 'remove-menu-item', $input ) );
		$this->assertNull( get_post( $a ) );
		$this->assertSame( array( 'A1' ), $this->outline() );

		$this->assertWPError( $this->run_ability( 'remove-menu-item', $input ), 'A used token must be refused.' );
		$this->assertNotNull( get_post( $a1 ) );
	}

	public function test_locations_list_and_assign() {
		$result = $this->run_ability(
			'set-menu-location',
			array(
				'location' => 'primary',
				'menu'     => 'Main',
			)
		);
		$this->assertSame( 0, $result['previous'] );
		$this->assertSame( $this->menu, get_nav_menu_locations()['primary'] );

		$list = $this->run_ability( 'list-menus' );
		$this->assertSame( array( 'primary' ), $list['menus'][0]['locations'] );
		$this->assertSame( $this->menu, $list['locations'][0]['menu'] );

		$this->assertWPError(
			$this->run_ability(
				'set-menu-location',
				array(
					'location' => 'nowhere',
					'menu'     => 'Main',
				)
			)
		);
	}

	public function test_reads_work_at_the_read_tier_and_writes_do_not() {
		$this->add_custom( 'Home' );
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertNotWPError( $this->run_ability( 'get-menu', array( 'menu' => $this->menu ) ) );
		$this->assertWPError(
			$this->run_ability(
				'add-menu-item',
				array(
					'menu'  => $this->menu,
					'type'  => 'custom',
					'url'   => 'https://example.com',
					'title' => 'Nope',
				)
			)
		);
	}

	public function test_an_editor_without_theme_options_cannot_edit_menus() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = $this->run_ability(
			'add-menu-item',
			array(
				'menu'  => $this->menu,
				'type'  => 'custom',
				'url'   => 'https://example.com',
				'title' => 'Nope',
			)
		);

		$this->assertWPError( $result );
	}
}
