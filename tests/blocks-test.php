<?php
/**
 * Native block design abilities — authoring, validation, and the tree writes.
 *
 * What must hold: agent nodes expand to markup the editor accepts as its own
 * save output (preset classes included); every write validates before it
 * saves; container wrapper markup survives nested mutations (the engine
 * regression Gutenberg exposes); builder pages are refused; removing a
 * subtree needs the two-step confirm.
 *
 * @package Saddle
 */

class Saddle_Blocks_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	private function page( $content = '' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
	}

	/* -------- authoring: nodes → editor-valid markup -------- */

	public function test_set_blocks_composes_editor_valid_markup_with_preset_classes() {
		$id = $this->page();

		$result = $this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'core/group',
						'attrs'    => array( 'backgroundColor' => 'accent' ),
						'children' => array(
							array( 'type' => 'core/heading', 'content' => 'Hello', 'attrs' => array( 'level' => 2 ) ),
							array( 'type' => 'core/paragraph', 'content' => 'World' ),
						),
					),
					array( 'type' => 'core/list', 'content' => array( 'One', 'Two' ) ),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 6, $result['blocks'] );

		$content = get_post( $id )->post_content;
		$this->assertStringContainsString( 'has-accent-background-color', $content );
		$this->assertStringContainsString( 'has-background', $content );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Hello</h2>', $content );
		$this->assertStringContainsString( '<ul class="wp-block-list">', $content );
		$this->assertStringContainsString( '<li>One</li>', $content );

		// The round trip must reparse to the same structure the editor sees.
		$tree = Saddle_Blocks_Tree::parse( $content );
		$this->assertCount( 2, $tree );
		$this->assertSame( 'core/group', $tree[0]['blockName'] );
		$this->assertCount( 2, $tree[0]['innerBlocks'] );
		$this->assertSame( 'core/list-item', $tree[1]['innerBlocks'][0]['blockName'] );
	}

	public function test_unknown_block_types_are_refused_at_authoring_time() {
		$id     = $this->page();
		$result = $this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array( array( 'type' => 'acme/imaginary', 'content' => 'x' ) ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unknown_block', $result->get_error_code() );
		$this->assertSame( '', get_post( $id )->post_content, 'Nothing partial may be saved.' );
	}

	public function test_button_href_and_image_src_live_in_markup_not_attrs_json() {
		$id = $this->page();

		$result = $this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'core/buttons',
						'children' => array(
							array( 'type' => 'core/button', 'content' => 'Go', 'attrs' => array( 'url' => 'https://example.com/x' ) ),
						),
					),
					array(
						'type'    => 'core/image',
						'content' => array( 'src' => 'https://example.com/a.jpg', 'alt' => 'A' ),
					),
				),
			)
		);
		$this->assertNotWPError( $result );

		$content = get_post( $id )->post_content;
		$this->assertStringContainsString( 'href="https://example.com/x"', $content );
		$this->assertStringContainsString( 'wp-block-button__link', $content );
		$this->assertStringContainsString( '<img src="https://example.com/a.jpg" alt="A"/>', $content );
		$this->assertStringNotContainsString( '"url"', $content, 'Markup-sourced attrs must not leak into the comment JSON.' );
	}

	/* -------- validation: placement contracts -------- */

	public function test_validate_rejects_builder_modules_and_misplaced_children() {
		$divi = Saddle_Blocks_Tree::parse( '<!-- wp:divi/section --><!-- /wp:divi/section -->' );
		$bad  = Saddle_Blocks_Tree::validate( $divi );
		$this->assertWPError( $bad );

		// core/list-item requires a list parent; at the root it must fail.
		$orphan = Saddle_Blocks_Tree::parse( '<!-- wp:list-item --><li>x</li><!-- /wp:list-item -->' );
		$result = Saddle_Blocks_Tree::validate( $orphan );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_invalid_structure', $result->get_error_code() );
	}

	public function test_add_block_rejects_placement_that_breaks_parent_rules() {
		$id = $this->page( "<!-- wp:paragraph -->\n<p>Keep me</p>\n<!-- /wp:paragraph -->" );

		$result = $this->run_ability(
			'add-block',
			array(
				'post_id' => $id,
				'node'    => array( 'type' => 'core/list-item', 'content' => 'orphan' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Keep me', get_post( $id )->post_content );
	}

	/* -------- the engine fix: wrappers survive nested mutations -------- */

	public function test_group_wrapper_markup_survives_inserting_a_child() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'core/group',
						'children' => array( array( 'type' => 'core/paragraph', 'content' => 'First' ) ),
					),
				),
			)
		);

		$result = $this->run_ability(
			'add-block',
			array(
				'post_id'        => $id,
				'parent_address' => '0',
				'node'           => array( 'type' => 'core/paragraph', 'content' => 'Second' ),
			)
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '0.1', $result['added'] );

		$content = get_post( $id )->post_content;
		$this->assertStringContainsString( '<div class="wp-block-group">', $content, 'The container wrapper must survive the nested insert.' );
		$this->assertStringContainsString( '<p>Second</p>', $content );
		$this->assertSame( 1, substr_count( $content, '</div>' ) );
	}

	/* -------- surgical writes -------- */

	public function test_edit_block_changes_content_and_attrs_only_edits_keep_markup() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array( array( 'type' => 'core/paragraph', 'content' => 'Before' ) ),
			)
		);

		$result = $this->run_ability(
			'edit-block',
			array(
				'post_id' => $id,
				'address' => '0',
				'content' => 'After',
			)
		);
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '<p>After</p>', get_post( $id )->post_content );

		// attrs-only edit on a leaf: content must not be lost.
		$result = $this->run_ability(
			'edit-block',
			array(
				'post_id' => $id,
				'address' => '0',
				'attrs'   => array( 'dropCap' => true ),
			)
		);
		$this->assertNotWPError( $result );
		$content = get_post( $id )->post_content;
		$this->assertStringContainsString( 'After', $content );
		$this->assertStringContainsString( '"dropCap":true', $content );
	}

	public function test_move_block_reorders_and_refuses_own_subtree() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array( 'type' => 'core/paragraph', 'content' => 'A' ),
					array(
						'type'     => 'core/group',
						'children' => array( array( 'type' => 'core/paragraph', 'content' => 'B' ) ),
					),
				),
			)
		);

		$own_subtree = $this->run_ability(
			'move-block',
			array( 'post_id' => $id, 'from_address' => '1', 'to_parent_address' => '1.0' )
		);
		$this->assertWPError( $own_subtree );

		$result = $this->run_ability(
			'move-block',
			array( 'post_id' => $id, 'from_address' => '0', 'to_parent_address' => '1', 'position' => 0 )
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '0.0', $result['moved'], 'The destination address must account for the removal shift.' );

		$tree = Saddle_Blocks_Tree::parse( get_post( $id )->post_content );
		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/group', $tree[0]['blockName'] );
		$this->assertCount( 2, $tree[0]['innerBlocks'] );
	}

	public function test_remove_block_leaf_is_immediate_but_subtree_needs_the_two_step_confirm() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array( 'type' => 'core/paragraph', 'content' => 'Leaf' ),
					array(
						'type'     => 'core/group',
						'children' => array( array( 'type' => 'core/paragraph', 'content' => 'Inside' ) ),
					),
				),
			)
		);

		// Leaf: gone in one call.
		$result = $this->run_ability( 'remove-block', array( 'post_id' => $id, 'address' => '0' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( '0', $result['removed'] );

		// Subtree: first call previews, nothing changes.
		$before  = get_post( $id )->post_content;
		$preview = $this->run_ability( 'remove-block', array( 'post_id' => $id, 'address' => '0' ) );
		$this->assertNotWPError( $preview );
		$this->assertNotEmpty( $preview['confirm_token'] );
		$this->assertSame( $before, get_post( $id )->post_content );

		// Second call with the token executes.
		$done = $this->run_ability(
			'remove-block',
			array( 'post_id' => $id, 'address' => '0', 'confirm_token' => $preview['confirm_token'] )
		);
		$this->assertNotWPError( $done );
		$this->assertSame( '', trim( get_post( $id )->post_content ) );
	}

	/* -------- guards -------- */

	public function test_block_writes_refuse_builder_built_posts() {
		$id = $this->page( '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' );

		$result = $this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array( array( 'type' => 'core/paragraph', 'content' => 'x' ) ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_builder_content', $result->get_error_code() );
	}

	public function test_read_tier_blocks_the_writes_but_not_the_reads() {
		Saddle_Capabilities::set_tier( 'read' );
		$id = $this->page( "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->" );

		$this->assertFalse( wp_get_ability( 'saddle/set-blocks' )->check_permissions( array( 'post_id' => $id, 'nodes' => array() ) ) );

		$read = $this->run_ability( 'get-blocks', array( 'post_id' => $id ) );
		$this->assertNotWPError( $read );
		$this->assertTrue( $read['tree_valid'] );
		$this->assertSame( 'core/paragraph', $read['nodes'][0]['type'] );
	}

	public function test_curated_types_stay_authorable_without_server_registration() {
		// Live WP builds exist where core/heading and core/image register only
		// in the editor's JS (seen on Studio's WP 7.0 runtime). Saddle owns the
		// curated markup contracts, so authoring must not depend on the registry.
		$registry = WP_Block_Type_Registry::get_instance();
		$saved    = $registry->unregister( 'core/heading' );

		try {
			$block = Saddle_Blocks_Author::expand_node( array( 'type' => 'core/heading', 'content' => 'Still works' ) );
			$this->assertNotWPError( $block );
			$this->assertStringContainsString( '<h2 class="wp-block-heading">Still works</h2>', $block['innerHTML'] );

			$schema = Saddle_Blocks_Schema::describe( 'core/heading' );
			$this->assertNotWPError( $schema );
			$this->assertSame( 'content', $schema['authoring']['mode'] );

			$names = wp_list_pluck( Saddle_Blocks_Schema::catalog( 'heading' ), 'name' );
			$this->assertContains( 'core/heading', $names, 'The catalog must surface curated types missing from the registry.' );

			$unknown = Saddle_Blocks_Author::expand_node( array( 'type' => 'acme/imaginary', 'content' => 'x' ) );
			$this->assertWPError( $unknown, 'Non-curated unregistered types must still be refused.' );
		} finally {
			register_block_type( $saved );
		}
	}

	/* -------- design vocabulary reads -------- */

	public function test_vocabulary_reads_return_catalog_schema_and_tokens() {
		$catalog = $this->run_ability( 'list-block-types', array( 'search' => 'paragraph' ) );
		$this->assertNotWPError( $catalog );
		$names = wp_list_pluck( $catalog['block_types'], 'name' );
		$this->assertContains( 'core/paragraph', $names );

		$schema = $this->run_ability( 'get-block-schema', array( 'type' => 'core/heading' ) );
		$this->assertNotWPError( $schema );
		$this->assertSame( 'content', $schema['authoring']['mode'] );
		$this->assertArrayHasKey( 'level', $schema['attributes'] );
		$this->assertArrayHasKey( 'example', $schema['authoring'] );

		$dynamic_or_raw = $this->run_ability( 'get-block-schema', array( 'type' => 'core/latest-posts' ) );
		$this->assertNotWPError( $dynamic_or_raw );
		$this->assertSame( 'attrs-only', $dynamic_or_raw['authoring']['mode'] );

		$tokens = $this->run_ability( 'get-design-tokens' );
		$this->assertNotWPError( $tokens );
		$this->assertArrayHasKey( 'colors', $tokens );
		$this->assertArrayHasKey( 'usage', $tokens );

		$patterns = $this->run_ability( 'list-block-patterns' );
		$this->assertNotWPError( $patterns );
		$this->assertArrayHasKey( 'patterns', $patterns );
	}

	public function test_get_design_system_returns_unified_shape() {
		$ds = $this->run_ability( 'get-design-system' );
		$this->assertNotWPError( $ds );
		foreach ( array( 'builder', 'colors', 'fonts', 'font_sizes', 'spacing', 'variables', 'presets', 'usage' ) as $key ) {
			$this->assertArrayHasKey( $key, $ds, "get-design-system must expose {$key}" );
		}
	}

	public function test_design_system_filter_lets_a_builder_override() {
		add_filter(
			'saddle_design_system',
			static function ( $shape ) {
				$shape['builder'] = 'divi';
				$shape['colors']  = array( array( 'id' => 'gcid-x', 'value' => '#123456' ) );
				return $shape;
			}
		);
		$ds = $this->run_ability( 'get-design-system' );
		remove_all_filters( 'saddle_design_system' );

		$this->assertSame( 'divi', $ds['builder'] );
		$this->assertSame( 'gcid-x', $ds['colors'][0]['id'] );
	}

	public function test_section_recipes_list_and_apply_cleanly() {
		$list = $this->run_ability( 'list-section-recipes' );
		$this->assertNotWPError( $list );
		$names = wp_list_pluck( $list['recipes'], 'name' );
		$this->assertSame(
			array( 'hero', 'features', 'pricing', 'testimonials', 'cta', 'faq' ),
			$names
		);

		// Every recipe's node tree must apply through set-blocks without warnings.
		foreach ( $names as $name ) {
			$recipe = $this->run_ability( 'get-section-recipe', array( 'name' => $name ) );
			$this->assertNotWPError( $recipe, "get-section-recipe {$name}" );
			$this->assertNotEmpty( $recipe['nodes'], "{$name} has nodes" );

			$id  = $this->page( '<!-- wp:paragraph --><p>seed</p><!-- /wp:paragraph -->' );
			$set = $this->run_ability( 'set-blocks', array( 'post_id' => $id, 'nodes' => $recipe['nodes'] ) );
			$this->assertNotWPError( $set, "set-blocks {$name}" );
			$this->assertArrayNotHasKey( 'warnings', $set, "{$name} inserts without applied-vs-ignored warnings" );
		}
	}

	public function test_unknown_section_recipe_errors() {
		$r = $this->run_ability( 'get-section-recipe', array( 'name' => 'nope' ) );
		$this->assertWPError( $r );
		$this->assertSame( 'saddle_unknown_recipe', $r->get_error_code() );
	}

	public function test_section_recipe_filter_lets_a_builder_override() {
		add_filter(
			'saddle_section_recipe',
			static function () {
				return array( 'builder' => 'divi', 'nodes' => array( array( 'type' => 'divi/section' ) ) );
			}
		);
		$r = $this->run_ability( 'get-section-recipe', array( 'name' => 'hero' ) );
		remove_all_filters( 'saddle_section_recipe' );

		$this->assertSame( 'divi', $r['builder'] );
		$this->assertSame( 'divi/section', $r['nodes'][0]['type'] );
	}

	public function test_bootstrap_design_system_gates_before_writing() {
		Saddle_Capabilities::set_tier( 'admin' );

		// Preview: a fresh call returns a confirm_token and does not apply.
		$preview = $this->run_ability( 'bootstrap-design-system', array( 'force' => true ) );
		$this->assertNotWPError( $preview );
		$this->assertArrayHasKey( 'confirm_token', $preview );
		$this->assertNotEmpty( $preview['preview']['colors'] );

		// Without a builder to handle the seed, applying reports applied=false
		// rather than half-writing a theme.json.
		$applied = $this->run_ability(
			'bootstrap-design-system',
			array( 'force' => true, 'confirm_token' => $preview['confirm_token'] )
		);
		$this->assertNotWPError( $applied );
		$this->assertFalse( $applied['applied'] );
	}
}
