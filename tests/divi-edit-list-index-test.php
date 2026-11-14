<?php
/**
 * divi-edit-module: a number in a dotted attrs key names one list item.
 *
 * Round 1 made a list in the patch replace the stored list. A dotted key
 * ending in index 0 ("…stops.0") was wrapped into array( 0 => item ),
 * which is a one-item list, so it wiped every other stored item, while
 * "…stops.1" edited one item. These pin the fixed behaviour.
 *
 * @package Saddle
 */

class Saddle_Divi_Edit_List_Index_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	private function page() {
		$attrs = array(
			'module' => array(
				'decoration' => array(
					'background' => array(
						'desktop' => array(
							'value' => array(
								'color'    => '#ffffff',
								'gradient' => array(
									'enabled' => 'on',
									'stops'   => array(
										array( 'position' => '0', 'color' => '#000000' ),
										array( 'position' => '50', 'color' => '#777777' ),
										array( 'position' => '100', 'color' => '#ffffff' ),
									),
								),
							),
						),
					),
				),
			),
		);
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/text ' . wp_json_encode( $attrs ) . ' --><!-- /wp:divi/text --><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->',
			)
		);
	}

	private function edit( $id, array $attrs ) {
		$result = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $id,
				'address' => '0.0.0.0',
				'attrs'   => $attrs,
			)
		);
		$this->assertNotWPError( $result );
		return $result;
	}

	private function stops( $id ) {
		$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $id )->post_content ), '0.0.0.0' );
		return $node['attrs']['module']['decoration']['background']['desktop']['value']['gradient']['stops'];
	}

	public function test_index_zero_edits_the_first_item_only() {
		$id = $this->page();
		$this->edit( $id, array( 'module.decoration.background.desktop.value.gradient.stops.0' => array( 'color' => '#ff0000' ) ) );

		$stops = $this->stops( $id );
		$this->assertCount( 3, $stops, 'Index 0 used to replace the whole list with one item.' );
		$this->assertSame( array( 'position' => '0', 'color' => '#ff0000' ), $stops[0], 'The item merges key by key.' );
		$this->assertSame( '#777777', $stops[1]['color'] );

		$this->edit( $id, array( 'module.decoration.background.desktop.value.gradient.stops.0.position' => '10' ) );
		$this->assertSame( '10', $this->stops( $id )[0]['position'] );
		$this->assertCount( 3, $this->stops( $id ) );
	}

	public function test_a_middle_index_edits_that_item() {
		$id = $this->page();
		$this->edit( $id, array( 'module.decoration.background.desktop.value.gradient.stops.1' => array( 'color' => '#00ff00' ) ) );

		$stops = $this->stops( $id );
		$this->assertCount( 3, $stops );
		$this->assertSame( array( 'position' => '50', 'color' => '#00ff00' ), $stops[1] );
		$this->assertSame( '#000000', $stops[0]['color'] );
	}

	public function test_the_index_one_past_the_end_appends() {
		$id   = $this->page();
		$item = array( 'position' => '75', 'color' => '#123456' );
		$this->edit( $id, array( 'module.decoration.background.desktop.value.gradient.stops.3' => $item ) );

		$stops = $this->stops( $id );
		$this->assertSame( array( 0, 1, 2, 3 ), array_keys( $stops ) );
		$this->assertSame( $item, $stops[3] );

		// Further past the end still appends, with no gap in the list.
		$this->edit( $id, array( 'module.decoration.background.desktop.value.gradient.stops.9' => $item ) );
		$this->assertSame( array( 0, 1, 2, 3, 4 ), array_keys( $this->stops( $id ) ) );
		$this->assertStringNotContainsString( '"9"', get_post( $id )->post_content, 'The list must stay a JSON array.' );
	}

	public function test_a_full_array_still_replaces_the_list() {
		$id    = $this->page();
		$stops = array( array( 'position' => '0', 'color' => '#111111' ) );
		$this->edit( $id, array( 'module.decoration.background.desktop.value.gradient.stops' => $stops ) );
		$this->assertSame( $stops, $this->stops( $id ) );

		$nested = array(
			array( 'position' => '0', 'color' => '#222222' ),
			array( 'position' => '100', 'color' => '#333333' ),
		);
		$this->edit( $id, array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'gradient' => array( 'stops' => $nested ) ) ) ) ) ) ) );
		$this->assertSame( $nested, $this->stops( $id ) );
	}
}
