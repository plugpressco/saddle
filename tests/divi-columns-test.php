<?php
/**
 * A row's "columns" value and the equal columns SaddleWP adds to a row that
 * has none (Saddle_Divi_Columns).
 *
 * The classic failure: a row of three columns written with no widths. Each
 * Divi 5 column falls back to flexType 24_24, so all three stacked at full
 * width on every screen, and nothing said why.
 *
 * @package Saddle
 */

class Saddle_Divi_Columns_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( 'et_divi_builder_breakpoints' );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function row( $count, array $extra = array(), array $column_attrs = array() ) {
		$children = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$column = array(
				'type'     => 'divi/column',
				'children' => array(
					array(
						'type'   => 'divi/text',
						'fields' => array( 'content' => 'x' ),
					),
				),
			);
			if ( isset( $column_attrs[ $i ] ) ) {
				$column['attrs'] = $column_attrs[ $i ];
			}
			$children[] = $column;
		}
		return array_merge(
			array(
				'type'     => 'divi/row',
				'children' => $children,
			),
			$extra
		);
	}

	private function widths( array $row, $bp ) {
		$out = array();
		foreach ( $row['innerBlocks'] as $column ) {
			$out[] = isset( $column['attrs']['module']['decoration']['sizing'][ $bp ]['value']['flexType'] ) ? $column['attrs']['module']['decoration']['sizing'][ $bp ]['value']['flexType'] : null;
		}
		return $out;
	}

	/* -------- reading the value -------- */

	public function test_columns_values_map_to_divis_layouts() {
		$this->assertSame( 'equal-columns_3', Saddle_Divi_Columns::layout( 3 )['name'] );
		$this->assertSame( 'equal-columns_4', Saddle_Divi_Columns::layout( '4' )['name'] );
		$this->assertSame( 'equal-columns_2', Saddle_Divi_Columns::layout( '1/2,1/2' )['name'] );
		$this->assertSame( 'offset-columns_5', Saddle_Divi_Columns::layout( '2/3,1/3' )['name'] );
		$this->assertSame( 'offset-columns_5', Saddle_Divi_Columns::layout( '16_24, 8_24' )['name'] );
		$this->assertSame( 'offset-columns_1', Saddle_Divi_Columns::layout( '3/5,2/5' )['name'] );
		$this->assertSame( 'offset-columns_11', Saddle_Divi_Columns::layout( 'offset-columns_11' )['name'] );
		$this->assertSame( 'equal-columns_4', Saddle_Divi_Columns::layout( '1/4,1/4,1/4,1/4' )['name'] );

		// A split with no layout of its own keeps its widths.
		$custom = Saddle_Divi_Columns::layout( '5/12,7/12' );
		$this->assertSame( 'equal-columns_2', $custom['name'] );
		$this->assertSame( array( '10_24', '14_24' ), array_column( $custom['cols'], 0 ) );
	}

	public function test_bad_columns_values_are_refused() {
		$this->assertWPError( Saddle_Divi_Columns::layout( 9 ) );
		$this->assertWPError( Saddle_Divi_Columns::layout( '1/2,1/3' ), 'Widths must fill the row.' );
		$this->assertStringContainsString( '0.833', Saddle_Divi_Columns::layout( '1/2,1/3' )->get_error_message() );
		$this->assertWPError( Saddle_Divi_Columns::layout( '3/7,4/7' ), 'Divi ships no 3/7 width.' );
		$this->assertWPError( Saddle_Divi_Columns::layout( 'wide' ) );
		$this->assertWPError( Saddle_Divi_Columns::layout( array( 1 ) ) );
	}

	/* -------- what the row and columns get -------- */

	public function test_a_three_column_row_gets_what_the_visual_builder_writes() {
		$row = Saddle_Divi_Author::expand_node( $this->row( 3, array( 'columns' => 3 ) ) );

		$this->assertNotWPError( $row );
		$this->assertSame( 'equal-columns_3', $row['attrs']['module']['advanced']['flexColumnStructure']['desktop']['value'] );
		$layout = $row['attrs']['module']['decoration']['layout'];
		$this->assertSame( array( 'display' => 'flex', 'flexWrap' => 'nowrap' ), $layout['desktop']['value'] );
		$this->assertSame( array( 'display' => 'flex' ), $layout['tablet']['value'], 'Tablet keeps the desktop wrap.' );
		$this->assertSame( array( 'display' => 'flex', 'flexWrap' => 'wrap' ), $layout['phone']['value'] );
		$this->assertArrayNotHasKey( 'phoneWide', $layout, 'Only breakpoints the site has on.' );

		$this->assertSame( array( '8_24', '8_24', '8_24' ), $this->widths( $row, 'desktop' ) );
		$this->assertSame( array( '8_24', '8_24', '8_24' ), $this->widths( $row, 'tablet' ), 'Tablet inherits desktop.' );
		$this->assertSame( array( '24_24', '24_24', '24_24' ), $this->widths( $row, 'phone' ), 'Phones stack.' );
	}

	public function test_four_columns_go_two_per_line_on_tablets() {
		$row = Saddle_Divi_Author::expand_node( $this->row( 4, array( 'columns' => '1/4,1/4,1/4,1/4' ) ) );
		$this->assertSame( array( '12_24', '12_24', '12_24', '12_24' ), $this->widths( $row, 'tablet' ) );
		$this->assertSame( 'wrap', $row['attrs']['module']['decoration']['layout']['tablet']['value']['flexWrap'] );
	}

	public function test_an_uneven_split_follows_divis_offset_layout() {
		$row = Saddle_Divi_Author::expand_node( $this->row( 2, array( 'columns' => '2/3,1/3' ) ) );
		$this->assertSame( 'offset-columns_5', $row['attrs']['module']['advanced']['flexColumnStructure']['desktop']['value'] );
		$this->assertSame( array( '16_24', '8_24' ), $this->widths( $row, 'desktop' ) );
		$this->assertSame( array( '24_24', '24_24' ), $this->widths( $row, 'phone' ) );
	}

	public function test_the_agents_own_widths_win_over_the_layout() {
		$row = Saddle_Divi_Author::expand_node(
			$this->row(
				2,
				array( 'columns' => '1/2,1/2' ),
				array( array( 'module.decoration.sizing@phone' => array( 'flexType' => '12_24' ) ) )
			)
		);
		$this->assertSame( array( '12_24', '24_24' ), $this->widths( $row, 'phone' ) );
	}

	public function test_wider_breakpoints_get_values_when_the_site_has_them_on() {
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
		$row = Saddle_Divi_Author::expand_node( $this->row( 2, array( 'columns' => 2 ) ) );
		$this->assertSame( array( '24_24', '24_24' ), $this->widths( $row, 'phoneWide' ), 'phoneWide takes the phone layout.' );
		$this->assertSame( 'wrap', $row['attrs']['module']['decoration']['layout']['phoneWide']['value']['flexWrap'] );
	}

	/* -------- refusals -------- */

	public function test_a_count_that_does_not_match_the_columns_is_refused_with_both_numbers() {
		$result = Saddle_Divi_Author::expand_node( $this->row( 3, array( 'columns' => '1/2,1/2' ) ), '0.0' );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_columns', $result->get_error_code() );
		$this->assertStringContainsString( 'Row 0.0', $result->get_error_message() );
		$this->assertStringContainsString( 'makes 2 columns, but the row holds 3', $result->get_error_message() );
	}

	public function test_columns_on_anything_but_a_row_and_on_a_grid_row_are_refused() {
		$column = Saddle_Divi_Author::expand_node(
			array(
				'type'    => 'divi/column',
				'columns' => 2,
			)
		);
		$this->assertWPError( $column );
		$this->assertSame( 'saddle_bad_columns', $column->get_error_code() );

		$grid = Saddle_Divi_Author::expand_node(
			$this->row(
				2,
				array(
					'columns' => 2,
					'attrs'   => array( 'module.decoration.layout' => array( 'display' => 'grid' ) ),
				)
			)
		);
		$this->assertWPError( $grid );
		$this->assertStringContainsString( 'display "grid"', $grid->get_error_message() );
	}

	/* -------- rows with no widths -------- */

	public function test_a_row_of_columns_with_no_widths_gets_equal_columns_and_a_note() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );
		$result  = wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $post_id,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array( $this->row( 3 ) ),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['notes'] );
		$this->assertStringContainsString( 'Row 0.0.0 holds 3 columns', $result['notes'][0] );
		$this->assertStringContainsString( '3 per line on desktop, 3 on tablets, 1 on phones', $result['notes'][0] );

		$row = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $post_id )->post_content ), '0.0.0' );
		$this->assertSame( 'equal-columns_3', $row['attrs']['module']['advanced']['flexColumnStructure']['desktop']['value'] );
		$this->assertSame( array( '8_24', '8_24', '8_24' ), $this->widths( $row, 'desktop' ) );
	}

	public function test_rows_that_already_have_widths_or_one_column_are_left_alone() {
		$one = Saddle_Divi_Author::expand_node( $this->row( 1 ) );
		$this->assertArrayNotHasKey( 'module', $one['attrs'] );
		$this->assertSame( array(), Saddle_Divi_Author::notes() );

		$set = Saddle_Divi_Author::expand_node(
			$this->row( 2, array(), array( array( 'module.decoration.sizing' => array( 'flexType' => '16_24' ) ) ) )
		);
		$this->assertSame( array( '16_24', null ), $this->widths( $set, 'desktop' ), 'A width the agent set is never changed.' );
		$this->assertSame( array(), Saddle_Divi_Author::notes() );

		$structured = Saddle_Divi_Author::expand_node(
			$this->row( 2, array( 'attrs' => array( 'module.advanced.flexColumnStructure' => 'equal-columns_2' ) ) )
		);
		$this->assertSame( array( null, null ), $this->widths( $structured, 'desktop' ) );
	}

	/* -------- edit-module -------- */

	public function test_edit_module_columns_replaces_the_stored_widths() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );
		wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $post_id,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array( $this->row( 2, array( 'columns' => 2 ) ) ),
					),
				),
			)
		);

		$result = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $post_id,
				'address' => '0.0.0',
				'columns' => '3/4,1/4',
			)
		);
		$this->assertNotWPError( $result );
		$row = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $post_id )->post_content ), '0.0.0' );
		$this->assertSame( 'offset-columns_9', $row['attrs']['module']['advanced']['flexColumnStructure']['desktop']['value'] );
		$this->assertSame( array( '18_24', '6_24' ), $this->widths( $row, 'desktop' ) );
		$this->assertSame( array( '18_24', '6_24' ), $this->widths( $row, 'tablet' ) );

		$bad = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $post_id,
				'address' => '0.0.0',
				'columns' => 3,
			)
		);
		$this->assertWPError( $bad );
		$this->assertStringContainsString( 'makes 3 columns, but the row holds 2', $bad->get_error_message() );

		$text = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $post_id,
				'address' => '0.0.0.0.0',
				'columns' => 2,
			)
		);
		$this->assertWPError( $text );
		$this->assertStringContainsString( 'divi/row only', $text->get_error_message() );
	}

	public function test_adding_or_removing_a_column_says_the_widths_no_longer_fit() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );
		wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $post_id,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array( $this->row( 2, array( 'columns' => 2 ) ) ),
					),
				),
			)
		);

		$added = wp_get_ability( 'saddle/divi-add-module' )->execute(
			array(
				'post_id'        => $post_id,
				'parent_address' => '0.0.0',
				'node'           => array( 'type' => 'divi/column' ),
			)
		);
		$this->assertNotWPError( $added );
		$this->assertStringContainsString( 'Row 0.0.0 now holds 3 columns, but its widths are set for 2', $added['notes'][0] );

		$removed = wp_get_ability( 'saddle/divi-remove-module' )->execute(
			array(
				'post_id' => $post_id,
				'address' => '0.0.0.2',
			)
		);
		$this->assertNotWPError( $removed );
		$this->assertArrayNotHasKey( 'notes', $removed, 'Back to two columns: the widths fit again.' );
	}
}
