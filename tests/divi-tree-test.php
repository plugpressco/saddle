<?php
/**
 * Saddle_Divi_Tree — the validated parse/address/mutate/serialize core.
 *
 * Pure functions over block markup, so these tests run on fixture strings and
 * need no Divi install (see https://github.com/plugpressco/saddle-pro/issues/5's testing decision).
 *
 * @package Saddle
 */

class Saddle_Divi_Tree_Test extends WP_UnitTestCase {

	/**
	 * A minimal valid page: one section > one row > two columns, text + button.
	 */
	private function valid_page() {
		return implode(
			"\n",
			array(
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:divi/text {"content":"Hello"} --><p>Hello</p><!-- /wp:divi/text -->',
				'<!-- /wp:divi/column -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:divi/button {"label":"Go"} --><!-- /wp:divi/button -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
			)
		);
	}

	/* -------- parse + flatten + addressing -------- */

	public function test_parse_strips_whitespace_noise_and_flatten_addresses_nodes() {
		$tree = Saddle_Divi_Tree::parse( $this->valid_page() );

		$this->assertCount( 1, $tree, 'One root section.' );

		$nodes     = Saddle_Divi_Tree::flatten( $tree );
		$addresses = wp_list_pluck( $nodes, 'address' );
		$types     = array_combine( $addresses, wp_list_pluck( $nodes, 'type' ) );

		$this->assertSame(
			array( '0', '0.0', '0.0.0', '0.0.0.0', '0.0.1', '0.0.1.0' ),
			$addresses,
			'Depth-first dot addresses.'
		);
		$this->assertSame( 'divi/section', $types['0'] );
		$this->assertSame( 'divi/column', $types['0.0.1'] );
		$this->assertSame( 'divi/button', $types['0.0.1.0'] );

		// The text module carries its excerpt and attrs.
		$text = $nodes[3];
		$this->assertSame( 'divi/text', $text['type'] );
		$this->assertSame( 'Hello', $text['text'] );
		$this->assertSame( array( 'content' => 'Hello' ), $text['attrs'] );
	}

	public function test_serialize_round_trips_a_parsed_tree() {
		$tree   = Saddle_Divi_Tree::parse( $this->valid_page() );
		$again  = Saddle_Divi_Tree::parse( Saddle_Divi_Tree::serialize( $tree ) );

		$this->assertSame(
			Saddle_Divi_Tree::flatten( $tree ),
			Saddle_Divi_Tree::flatten( $again ),
			'Serialize → parse must preserve the tree.'
		);
	}

	/* -------- validation -------- */

	public function test_valid_page_validates() {
		$this->assertTrue(
			Saddle_Divi_Tree::validate( Saddle_Divi_Tree::parse( $this->valid_page() ) )
		);
	}

	public function test_leaf_at_root_is_rejected() {
		$tree  = Saddle_Divi_Tree::parse( '<!-- wp:divi/text --><p>Loose</p><!-- /wp:divi/text -->' );
		$error = Saddle_Divi_Tree::validate( $tree );

		$this->assertWPError( $error );
		$this->assertSame( 'saddle_invalid_structure', $error->get_error_code() );
		$this->assertSame( '0', $error->get_error_data()['violations'][0]['address'] );
	}

	public function test_column_directly_in_section_is_rejected() {
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/column --><!-- /wp:divi/column -->',
				'<!-- /wp:divi/section -->',
			)
		);
		$error = Saddle_Divi_Tree::validate( Saddle_Divi_Tree::parse( $markup ) );

		$this->assertWPError( $error );
		$violation = $error->get_error_data()['violations'][0];
		$this->assertSame( '0.0', $violation['address'] );
		$this->assertSame( 'divi/column', $violation['type'] );
	}

	public function test_non_divi_block_inside_tree_is_rejected() {
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:paragraph --><p>Gutenberg</p><!-- /wp:paragraph -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
			)
		);
		$error = Saddle_Divi_Tree::validate( Saddle_Divi_Tree::parse( $markup ) );

		$this->assertWPError( $error );
		$this->assertSame( 'core/paragraph', $error->get_error_data()['violations'][0]['type'] );
	}

	public function test_all_violations_are_reported_at_once() {
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/row --><!-- /wp:divi/row -->',
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/text --><p>x</p><!-- /wp:divi/text -->',
				'<!-- /wp:divi/section -->',
			)
		);
		$error = Saddle_Divi_Tree::validate( Saddle_Divi_Tree::parse( $markup ) );

		$this->assertWPError( $error );
		$this->assertCount( 2, $error->get_error_data()['violations'], 'Row at root AND text in section.' );
	}

	public function test_real_world_page_shape_validates() {
		// Mirrors an actual Divi 5.8 page from the divi-dev site: a
		// divi/placeholder root wrapper holding the sections, and a
		// third-party module (own block namespace) as the leaf.
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/placeholder -->',
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:divi-instagram-feed/insta-feed {"feedId":"3"} --><!-- /wp:divi-instagram-feed/insta-feed -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
				'<!-- /wp:divi/placeholder -->',
			)
		);

		$tree = Saddle_Divi_Tree::parse( $markup );
		$this->assertTrue( Saddle_Divi_Tree::validate( $tree ) );

		$nodes = Saddle_Divi_Tree::flatten( $tree );
		$this->assertSame( 'divi/placeholder', $nodes[0]['type'] );
		$this->assertSame( 'divi-instagram-feed/insta-feed', end( $nodes )['type'] );
	}

	public function test_parent_child_module_nesting_validates() {
		// DiviTorque-style parent/child modules (accordion > accordion-item)
		// store children as innerBlocks — content modules may nest modules.
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:divitorque/accordion -->',
				'<!-- wp:divitorque/accordion-item {"title":"One"} --><!-- /wp:divitorque/accordion-item -->',
				'<!-- wp:divitorque/accordion-item {"title":"Two"} --><!-- /wp:divitorque/accordion-item -->',
				'<!-- /wp:divitorque/accordion -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
			)
		);

		$this->assertTrue(
			Saddle_Divi_Tree::validate( Saddle_Divi_Tree::parse( $markup ) )
		);
	}

	public function test_structural_block_inside_a_module_is_rejected() {
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:divitorque/accordion -->',
				'<!-- wp:divi/section --><!-- /wp:divi/section -->',
				'<!-- /wp:divitorque/accordion -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
			)
		);
		$error = Saddle_Divi_Tree::validate( Saddle_Divi_Tree::parse( $markup ) );

		$this->assertWPError( $error );
		$violation = $error->get_error_data()['violations'][0];
		$this->assertSame( '0.0.0.0.0', $violation['address'] );
		$this->assertSame( 'divi/section', $violation['type'] );
	}

	public function test_empty_placeholder_page_validates() {
		$tree = Saddle_Divi_Tree::parse( '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' );
		$this->assertTrue( Saddle_Divi_Tree::validate( $tree ) );
	}

	/* -------- node operations -------- */

	public function test_get_resolves_addresses_and_misses_cleanly() {
		$tree = Saddle_Divi_Tree::parse( $this->valid_page() );

		$this->assertSame( 'divi/button', Saddle_Divi_Tree::get( $tree, '0.0.1.0' )['blockName'] );
		$this->assertNull( Saddle_Divi_Tree::get( $tree, '0.0.9' ) );
		$this->assertNull( Saddle_Divi_Tree::get( $tree, '4' ) );
	}

	public function test_insert_adds_a_module_and_keeps_the_tree_valid_and_serializable() {
		$tree   = Saddle_Divi_Tree::parse( $this->valid_page() );
		$module = Saddle_Divi_Tree::make_module( 'divi/divider', array( 'style' => 'solid' ) );

		$result = Saddle_Divi_Tree::insert( $tree, '0.0.0', 1, $module );

		$this->assertNotWPError( $result );
		$this->assertTrue( Saddle_Divi_Tree::validate( $result ) );
		$this->assertSame( 'divi/divider', Saddle_Divi_Tree::get( $result, '0.0.0.1' )['blockName'] );

		// The mutated tree must survive a serialize → parse round trip intact.
		$reparsed = Saddle_Divi_Tree::parse( Saddle_Divi_Tree::serialize( $result ) );
		$this->assertSame( 'divi/divider', Saddle_Divi_Tree::get( $reparsed, '0.0.0.1' )['blockName'] );
	}

	public function test_insert_into_missing_parent_errors() {
		$tree   = Saddle_Divi_Tree::parse( $this->valid_page() );
		$module = Saddle_Divi_Tree::make_module( 'divi/divider' );

		$result = Saddle_Divi_Tree::insert( $tree, '0.7', 0, $module );

		$this->assertWPError( $result );
		// Inherited from free's Saddle_Tree engine, which keeps its own code
		// (Pro's OWN addressing errors are saddle_bad_address).
		$this->assertSame( 'saddle_bad_address', $result->get_error_code() );
	}

	public function test_remove_deletes_the_addressed_node() {
		$tree   = Saddle_Divi_Tree::parse( $this->valid_page() );
		$result = Saddle_Divi_Tree::remove( $tree, '0.0.1' );

		$this->assertNotWPError( $result );
		$this->assertTrue( Saddle_Divi_Tree::validate( $result ) );
		$this->assertNull( Saddle_Divi_Tree::get( $result, '0.0.1' ), 'Second column gone.' );
		$this->assertCount( 1, Saddle_Divi_Tree::get( $result, '0.0' )['innerBlocks'] );
	}

	public function test_replace_swaps_the_addressed_node() {
		$tree   = Saddle_Divi_Tree::parse( $this->valid_page() );
		$module = Saddle_Divi_Tree::make_module( 'divi/heading', array( 'title' => 'New' ) );

		$result = Saddle_Divi_Tree::replace( $tree, '0.0.0.0', $module );

		$this->assertNotWPError( $result );
		$node = Saddle_Divi_Tree::get( $result, '0.0.0.0' );
		$this->assertSame( 'divi/heading', $node['blockName'] );
		$this->assertSame( array( 'title' => 'New' ), $node['attrs'] );
	}
}
