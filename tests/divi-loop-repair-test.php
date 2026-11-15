<?php
/**
 * Loops saved in an older shape: found, rewritten on any write, reported by
 * verify-page, and read the way their author meant when Divi renders them.
 *
 * What Divi reads was found in Divi 5.10.1's source (LoopUtils and its block
 * parser); Divi is not loaded here, so its loop reader is a stub that follows
 * the same rules for sub types and order.
 *
 * @package Saddle
 */

class Saddle_Divi_Loop_Repair_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/fixtures/divi/loop-utils-stub.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		remove_all_filters( 'divi_loop_data_before_execution' );
		remove_all_filters( 'divi_loop_data_after_execution' );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	/**
	 * Loop values in the older shapes Divi reads differently.
	 *
	 * @return array[]
	 */
	private static function old_loops() {
		return array(
			'strings'       => array(
				'enable'    => 'on',
				'queryType' => 'post_types',
				'subTypes'  => array( 'post' ),
				'order'     => 'desc',
			),
			'no sub types'  => array(
				'enable'    => 'on',
				'queryType' => 'post_types',
				'order'     => 'desc',
			),
			'empty list'    => array(
				'enable'    => 'on',
				'queryType' => 'post_types',
				'subTypes'  => array(),
			),
			'terms, empty'  => array(
				'enable'    => 'on',
				'queryType' => 'terms',
				'subTypes'  => '',
			),
			'users by name' => array(
				'enable'    => 'on',
				'queryType' => 'users',
				'subTypes'  => array(
					array(
						'value' => 'author',
						'label' => 'Author',
					),
				),
			),
		);
	}

	/**
	 * A loop value in the shape Divi reads.
	 *
	 * @return array
	 */
	private static function current_loop() {
		return array(
			'enable'    => 'on',
			'queryType' => 'post_types',
			'subTypes'  => array(
				array(
					'value' => 'post',
					'label' => 'Posts',
				),
			),
			'order'     => 'descending',
			'loopId'    => 'loop-current',
		);
	}

	private static function block( array $loop, array $inner = array(), $name = 'divi/column' ) {
		return array(
			'blockName'    => $name,
			'attrs'        => array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => $loop ) ) ) ) ),
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inner ), null ),
		);
	}

	private static function plain( $name, array $inner = array() ) {
		return array(
			'blockName'    => $name,
			'attrs'        => array(),
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inner ), null ),
		);
	}

	private function page( array $column_loop ) {
		$tree = array(
			self::plain(
				'divi/section',
				array(
					self::plain(
						'divi/row',
						array(
							self::block( $column_loop, array( self::plain( 'divi/blurb' ), self::plain( 'divi/blurb' ) ) ),
						)
					),
				)
			),
		);
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => Saddle_Divi_Tree::serialize( $tree ),
			)
		);
	}

	private function stored_loop( $post_id, $address = '0.0.0' ) {
		$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $post_id )->post_content ), $address );
		return Saddle_Divi_Loop::value( $node );
	}

	/* -------- what is wrong, and the one shape -------- */

	public function test_each_older_shape_is_found_and_rewritten_to_what_divi_reads() {
		foreach ( self::old_loops() as $label => $loop ) {
			$this->assertNotEmpty( Saddle_Divi_Loop_Repair::problems( $loop ), $label );

			$fixed = Saddle_Divi_Loop::normalize( $loop );
			$this->assertSame( array(), Saddle_Divi_Loop_Repair::problems( $fixed ), $label );
			foreach ( $fixed['subTypes'] as $item ) {
				$this->assertIsString( $item['value'], $label );
			}
		}

		$fixed = Saddle_Divi_Loop::normalize( self::old_loops()['no sub types'] );
		$this->assertSame( 'post', $fixed['subTypes'][0]['value'], 'With no sub type Divi queries every post type.' );
		$this->assertSame( 'descending', $fixed['order'], 'Divi sorts "desc" oldest first.' );
		$this->assertSame( 'category', Saddle_Divi_Loop::normalize( self::old_loops()['terms, empty'] )['subTypes'][0]['value'] );
		$this->assertSame( 'user_roles', Saddle_Divi_Loop::normalize( self::old_loops()['users by name'] )['queryType'] );
		$this->assertSame( array(), Saddle_Divi_Loop_Repair::problems( self::current_loop() ) );
	}

	public function test_the_repair_applied_at_render_and_on_writes_changes_only_two_shapes() {
		$fixed = Saddle_Divi_Loop_Repair::repair( self::old_loops()['strings'] );
		$this->assertSame( array( array( 'value' => 'post', 'label' => 'Posts' ) ), $fixed['subTypes'], 'A plain-text sub type becomes an item labelled like divi-enable-loop labels it.' );
		$this->assertSame( 'descending', $fixed['order'] );

		$this->assertSame( 'ascending', Saddle_Divi_Loop_Repair::repair( array( 'order' => 'asc' ) )['order'] );

		// What the author asked for stays: these are the loop tools' to change.
		$no_types = Saddle_Divi_Loop_Repair::repair( self::old_loops()['no sub types'] );
		$this->assertArrayNotHasKey( 'subTypes', $no_types, 'A missing sub type list is not filled in.' );
		$this->assertSame( array(), Saddle_Divi_Loop_Repair::repair( self::old_loops()['empty list'] )['subTypes'] );
		$this->assertSame( 'users', Saddle_Divi_Loop_Repair::repair( self::old_loops()['users by name'] )['queryType'] );
		$this->assertSame( self::old_loops()['terms, empty'], Saddle_Divi_Loop_Repair::repair( self::old_loops()['terms, empty'] ) );
		$this->assertSame( self::current_loop(), Saddle_Divi_Loop_Repair::repair( self::current_loop() ) );
	}

	public function test_the_repair_keeps_a_plain_text_sub_type_a_loop_may_not_name() {
		register_post_type( 'saddle_hidden_type', array( 'public' => false ) );
		$loop = array(
			'enable'    => 'on',
			'queryType' => 'post_types',
			'subTypes'  => array( 'saddle_hidden_type', 'attachment' ),
		);

		$this->assertSame( $loop, Saddle_Divi_Loop_Repair::repair( $loop ), 'Divi keeps skipping it, as it did before.' );

		$mixed = Saddle_Divi_Loop_Repair::repair( array_merge( $loop, array( 'subTypes' => array( 'page', 'saddle_hidden_type' ) ) ) );
		$this->assertSame( array( array( 'value' => 'page', 'label' => 'Pages' ), 'saddle_hidden_type' ), $mixed['subTypes'] );
		unregister_post_type( 'saddle_hidden_type' );
	}

	public function test_repair_tree_rewrites_every_old_loop_at_any_depth_and_nothing_else() {
		$old     = self::old_loops()['strings'];
		$current = self::current_loop();
		$tree    = array(
			self::plain(
				'divi/section',
				array(
					self::plain(
						'divi/row',
						array(
							self::block( $current, array( self::block( $old, array(), 'divi/group' ) ) ),
							self::block( self::old_loops()['no sub types'] ),
						)
					),
				)
			),
		);

		list( $fixed, $count ) = Saddle_Divi_Loop::repair_tree( $tree );
		$this->assertSame( 2, $count );
		$this->assertSame( $current, Saddle_Divi_Loop::value( Saddle_Divi_Tree::get( $fixed, '0.0.0' ) ), 'A loop already in shape is left as it is.' );
		$this->assertSame( array(), Saddle_Divi_Loop_Repair::problems( Saddle_Divi_Loop::value( Saddle_Divi_Tree::get( $fixed, '0.0.0.0' ) ) ) );
		// This used to fill in "post": a loop with no sub type is left as
		// stored, and only its order is rewritten.
		$no_types = Saddle_Divi_Loop::value( Saddle_Divi_Tree::get( $fixed, '0.0.1' ) );
		$this->assertArrayNotHasKey( 'subTypes', $no_types );
		$this->assertSame( 'descending', $no_types['order'] );

		list( , $again ) = Saddle_Divi_Loop::repair_tree( $fixed );
		$this->assertSame( 0, $again, 'A repaired tree needs nothing more.' );
	}

	public function test_needs_repair_is_a_cheap_test_on_raw_content() {
		$this->assertFalse( Saddle_Divi_Loop::needs_repair( '<!-- wp:divi/text {"content":{}} /-->' ), 'No loop at all.' );
		$this->assertTrue( Saddle_Divi_Loop::needs_repair( get_post( $this->page( self::old_loops()['strings'] ) )->post_content ) );
		$this->assertTrue( Saddle_Divi_Loop::needs_repair( get_post( $this->page( self::old_loops()['no sub types'] ) )->post_content ) );
		$this->assertTrue( Saddle_Divi_Loop::needs_repair( get_post( $this->page( self::old_loops()['users by name'] ) )->post_content ) );
		$this->assertFalse( Saddle_Divi_Loop::needs_repair( get_post( $this->page( self::current_loop() ) )->post_content ) );
	}

	/* -------- every write saves loops in the current shape -------- */

	public function test_any_divi_write_saves_the_pages_loops_in_the_current_shape() {
		$id = $this->page( self::old_loops()['strings'] );

		$result = wp_get_ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array(
				'post_id' => $id,
				'address' => '0.0.0.1',
				'field'   => 'title.innerContent',
				'source'  => 'loop_post_title',
			)
		);
		$this->assertNotWPError( $result );

		$loop = $this->stored_loop( $id );
		// The label used to be the slug ("post"); it is the post type's
		// label, as divi-enable-loop writes it.
		$this->assertSame( array( array( 'value' => 'post', 'label' => 'Posts' ) ), $loop['subTypes'] );
		$this->assertSame( 'descending', $loop['order'] );
	}

	public function test_another_write_keeps_an_old_user_loop_and_an_empty_sub_type_list_as_stored() {
		foreach ( array( 'users by name', 'no sub types' ) as $label ) {
			$id = $this->page( self::old_loops()[ $label ] );

			$result = wp_get_ability( 'saddle/divi-edit-module' )->execute(
				array(
					'post_id' => $id,
					'address' => '0.0.0.1',
					'fields'  => array( 'title' => 'Hello' ),
				)
			);
			$this->assertNotWPError( $result, $label );

			$loop = $this->stored_loop( $id );
			$this->assertSame( self::old_loops()[ $label ]['queryType'], $loop['queryType'], $label );
			$this->assertSame( isset( self::old_loops()[ $label ]['subTypes'] ), isset( $loop['subTypes'] ), $label );
		}
	}

	public function test_edit_loop_with_no_changes_saves_an_old_loop_in_the_current_shape() {
		$id     = $this->page( self::old_loops()['no sub types'] );
		$result = wp_get_ability( 'saddle/divi-edit-loop' )->execute( array( 'post_id' => $id, 'address' => '0.0.0' ) );
		$this->assertNotWPError( $result );

		$loop = $this->stored_loop( $id );
		$this->assertSame( 'post', $loop['subTypes'][0]['value'] );
		$this->assertSame( 'descending', $loop['order'] );
	}

	/* -------- verify-page -------- */

	public function test_verify_reports_a_loop_that_is_on_and_saved_in_an_older_shape() {
		$tree     = Saddle_Divi_Tree::parse( get_post( $this->page( self::old_loops()['strings'] ) )->post_content );
		$findings = array_values(
			array_filter(
				Saddle_Divi_Verify::findings( $tree ),
				static function ( $finding ) {
					return false !== strpos( $finding['fix_hint'], 'divi-edit-loop' );
				}
			)
		);
		$this->assertCount( 1, $findings );
		$this->assertSame( '0.0.0', $findings[0]['address'] );
		$this->assertSame( 'error', $findings[0]['severity'] );
		// The finding says what a visitor gets: Divi skips the plain-text
		// sub type, so the loop lists every post type, oldest first.
		$this->assertSame( 'The loop on divi/column (node 0.0.0) names no post type that Divi can read and stores its order as "desc", so it lists every post type, including pages and media, oldest first.', $findings[0]['message'] );
		$this->assertSame( 'Run divi-edit-loop on this address (no changes needed) to save it in the current format.', $findings[0]['fix_hint'] );

		$off = self::old_loops()['strings'];
		$off['enable'] = 'off';
		foreach ( array( $off, self::current_loop() ) as $loop ) {
			$quiet = Saddle_Divi_Tree::parse( get_post( $this->page( $loop ) )->post_content );
			foreach ( Saddle_Divi_Verify::findings( $quiet ) as $finding ) {
				$this->assertStringNotContainsString( 'divi-edit-loop', $finding['fix_hint'], 'A loop that is off or in shape is not reported.' );
			}
		}
	}

	/* -------- render time -------- */

	public function test_the_render_repair_hooks_divis_loop_filters_only_with_divi_5() {
		remove_all_filters( 'divi_loop_data_before_execution' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		add_filter( 'saddle_divi_active', '__return_false' );
		Saddle_Divi_Loop_Repair::hook();
		$this->assertFalse( has_filter( 'divi_loop_data_before_execution' ) );
		remove_filter( 'saddle_divi_active', '__return_false' );

		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Divi_Loop_Repair::hook();
		$this->assertSame( 1, has_filter( 'divi_loop_data_before_execution', array( 'Saddle_Divi_Loop_Repair', 'repair_loop_data' ) ) );
		$this->assertSame( 1, has_filter( 'divi_loop_data_after_execution', array( 'Saddle_Divi_Loop_Repair', 'repair_loop_data' ) ) );
	}

	public function test_an_old_loop_is_queried_as_its_author_meant_when_divi_renders_it() {
		Saddle_Divi_Loop_Repair::hook();
		$utils = 'ET\\Builder\\Packages\\Module\\Options\\Loop\\LoopUtils';

		$attrs = self::block( self::old_loops()['strings'] )['attrs'];
		$read  = $utils::get_query_args_from_attrs( $attrs );
		$this->assertSame( array( 'any' ), $read['query_args']['post_type'], 'Divi alone skips the plain-text sub type.' );
		$data = apply_filters( 'divi_loop_data_before_execution', $read, $attrs, array( 'attrs' => $attrs ) );
		$this->assertSame( array( 'post' ), $data['query_args']['post_type'] );
		$this->assertSame( 'publish', $data['query_args']['post_status'] );

		// This used to fill in "post" at render too. A loop that names no
		// sub type is left as Divi reads it; verify-page reports it and
		// divi-edit-loop sets it.
		foreach ( array( 'no sub types', 'empty list' ) as $label ) {
			$attrs = self::block( self::old_loops()[ $label ] )['attrs'];
			$read  = $utils::get_query_args_from_attrs( $attrs );
			$data  = apply_filters( 'divi_loop_data_before_execution', $read, $attrs, array( 'attrs' => $attrs ) );
			$this->assertSame( $read['query_args']['post_type'], $data['query_args']['post_type'], $label );
		}

		$attrs = self::block( self::old_loops()['strings'] )['attrs'];
		$data  = apply_filters( 'divi_loop_data_before_execution', $utils::get_query_args_from_attrs( $attrs ), $attrs, array() );
		$this->assertSame( 'DESC', $data['query_args']['order'], '"desc" sorts newest first again.' );
	}

	public function test_the_render_repair_leaves_current_loops_other_blocks_and_changed_data_alone() {
		$utils = 'ET\\Builder\\Packages\\Module\\Options\\Loop\\LoopUtils';

		$utils::$calls = 0;
		$plain         = array( 'query_args' => array( 'post_type' => array( 'page' ) ) );
		$this->assertSame( $plain, Saddle_Divi_Loop_Repair::repair_loop_data( $plain, array( 'module' => array() ) ) );
		$current = self::block( self::current_loop() )['attrs'];
		$this->assertSame( $plain, Saddle_Divi_Loop_Repair::repair_loop_data( $plain, $current ) );
		$this->assertSame( 0, $utils::$calls, 'A block without an old loop costs no reader call.' );

		// Data something else already changed (a post filter, another plugin)
		// is not rebuilt.
		$attrs                              = self::block( self::old_loops()['strings'] )['attrs'];
		$changed                            = $utils::get_query_args_from_attrs( $attrs );
		$changed['query_args']['post_type'] = array( 'product' );
		$this->assertSame( $changed, Saddle_Divi_Loop_Repair::repair_loop_data( $changed, $attrs ) );

		$this->assertSame( 'not loop data', Saddle_Divi_Loop_Repair::repair_loop_data( 'not loop data', $attrs ) );
	}
}
