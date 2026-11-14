<?php
/**
 * divi-edit-module: a list in the patch replaces the stored list, a key can
 * be removed with "unset", and a null in the patch is never stored.
 *
 * @package Saddle
 */

class Saddle_Divi_Edit_List_Test extends WP_UnitTestCase {

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

	/**
	 * A text module with a three-stop gradient, two custom attributes, a
	 * background colour and a title.
	 *
	 * @return int
	 */
	private function page() {
		$attrs = array(
			'module'  => array(
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
					'attributes' => array(
						'desktop' => array(
							'value' => array(
								'attributes' => array(
									array( 'name' => 'data-a', 'value' => '1' ),
									array( 'name' => 'data-b', 'value' => '2' ),
								),
							),
						),
					),
				),
			),
			'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Hi</p>' ) ) ),
		);
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:divi/text ' . wp_json_encode( $attrs ) . ' --><!-- /wp:divi/text -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);
	}

	private function edit( array $input ) {
		return wp_get_ability( 'saddle/divi-edit-module' )->execute( array_merge( array( 'address' => '0.0.0.0' ), $input ) );
	}

	private function attrs( $post_id ) {
		$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $post_id )->post_content ), '0.0.0.0' );
		return $node['attrs'];
	}

	private function background( $post_id ) {
		return $this->attrs( $post_id )['module']['decoration']['background']['desktop']['value'];
	}

	public function test_a_shorter_gradient_stop_list_replaces_the_stored_list() {
		$id     = $this->page();
		$stops  = array(
			array( 'position' => '0', 'color' => '#111111' ),
			array( 'position' => '100', 'color' => '#222222' ),
		);
		$result = $this->edit( array( 'post_id' => $id, 'attrs' => array( 'module.decoration.background.desktop.value.gradient.stops' => $stops ) ) );
		$this->assertNotWPError( $result );

		$this->assertSame( $stops, $this->background( $id )['gradient']['stops'], 'Before, the third stored stop survived the merge.' );
		$this->assertSame( 'on', $this->background( $id )['gradient']['enabled'] );
		$this->assertSame( '#ffffff', $this->background( $id )['color'] );
	}

	public function test_a_custom_attributes_list_can_shrink() {
		$id = $this->page();
		$this->edit(
			array(
				'post_id' => $id,
				'attrs'   => array(
					'module' => array( 'decoration' => array( 'attributes' => array( 'desktop' => array( 'value' => array( 'attributes' => array( array( 'name' => 'data-c', 'value' => '3' ) ) ) ) ) ) ),
				),
			)
		);
		$this->assertSame(
			array( array( 'name' => 'data-c', 'value' => '3' ) ),
			$this->attrs( $id )['module']['decoration']['attributes']['desktop']['value']['attributes']
		);
	}

	public function test_objects_still_merge_key_by_key_and_an_index_path_edits_one_item() {
		$id = $this->page();
		$this->edit( array( 'post_id' => $id, 'attrs' => array( 'module.decoration.background.desktop.value.gradient.stops.1.color' => '#abcdef' ) ) );

		$stops = $this->background( $id )['gradient']['stops'];
		$this->assertCount( 3, $stops );
		$this->assertSame( '#abcdef', $stops[1]['color'] );
		$this->assertSame( '50', $stops[1]['position'] );

		$this->edit( array( 'post_id' => $id, 'attrs' => array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'image' => array( 'url' => 'a.jpg' ) ) ) ) ) ) ) ) );
		$this->assertSame( '#ffffff', $this->background( $id )['color'], 'An object in the patch merges into the stored object.' );
		$this->assertSame( 'a.jpg', $this->background( $id )['image']['url'] );
	}

	public function test_null_in_the_patch_is_never_stored() {
		$id = $this->page();
		$this->edit(
			array(
				'post_id' => $id,
				'attrs'   => array(
					'module.decoration.background.desktop.value.color'    => null,
					'module.decoration.background.desktop.value.position' => null,
				),
			)
		);

		$background = $this->background( $id );
		$this->assertSame( '#ffffff', $background['color'], 'A null changes nothing.' );
		$this->assertArrayNotHasKey( 'position', $background );
		$this->assertStringNotContainsString( 'null', get_post( $id )->post_content );
	}

	public function test_unset_removes_keys_and_empty_parents() {
		$id     = $this->page();
		$result = $this->edit(
			array(
				'post_id' => $id,
				'unset'   => array(
					'module.decoration.background.desktop.value.gradient',
					'module.decoration.attributes.desktop.value.attributes',
					'module.decoration.sticky',
				),
			)
		);
		$this->assertNotWPError( $result );

		$attrs = $this->attrs( $id );
		$this->assertArrayNotHasKey( 'gradient', $attrs['module']['decoration']['background']['desktop']['value'] );
		$this->assertArrayNotHasKey( 'attributes', $attrs['module']['decoration'], 'A group left empty is removed with it.' );
		$this->assertSame( array( 'module.decoration.background.desktop.value.gradient', 'module.decoration.attributes.desktop.value.attributes' ), $result['removed'] );
		$this->assertSame( array( 'module.decoration.sticky' ), $result['not_found'] );
	}

	public function test_unset_runs_before_the_patch() {
		$id = $this->page();
		$this->edit(
			array(
				'post_id' => $id,
				'unset'   => array( 'module.decoration.background.desktop.value.gradient' ),
				'attrs'   => array( 'module.decoration.background.desktop.value.gradient.type' => 'radial' ),
			)
		);
		$this->assertSame( array( 'type' => 'radial' ), $this->background( $id )['gradient'] );

		$this->edit( array( 'post_id' => $id, 'unset' => array( 'module.decoration.background.desktop.value.gradient.type' ) ) );
		$this->assertArrayNotHasKey( 'gradient', $this->background( $id ), 'An object left empty is removed.' );
	}

	public function test_an_unset_list_item_leaves_a_list() {
		$id = $this->page();
		$this->edit( array( 'post_id' => $id, 'unset' => array( 'module.decoration.background.desktop.value.gradient.stops.0' ) ) );

		$stops = $this->background( $id )['gradient']['stops'];
		$this->assertSame( array( 0, 1 ), array_keys( $stops ) );
		$this->assertSame( '50', $stops[0]['position'] );
	}

	public function test_bad_or_empty_edits_are_refused() {
		$id = $this->page();

		$empty = $this->edit( array( 'post_id' => $id ) );
		$this->assertWPError( $empty );

		$bad = $this->edit( array( 'post_id' => $id, 'unset' => array( 'module..decoration' ) ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'saddle_bad_unset', $bad->get_error_code() );
	}

	public function test_edit_module_is_refused_at_the_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );
		$this->assertFalse( wp_get_ability( 'saddle/divi-edit-module' )->check_permissions( array( 'post_id' => $this->page(), 'address' => '0.0.0.0', 'unset' => array( 'module' ) ) ) );
	}
}
