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
		if ( $this->previous_theme && get_stylesheet() !== $this->previous_theme ) {
			switch_theme( $this->previous_theme );
		}
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private $previous_theme;

	/**
	 * Activate the minimal block-theme fixture. The host WordPress this suite
	 * runs against ships no block theme, so without this the global-styles
	 * tests skip and the suite is green while proving nothing.
	 */
	private function use_block_theme() {
		$this->previous_theme = get_stylesheet();
		register_theme_directory( __DIR__ . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		wp_clean_themes_cache();

		if ( wp_get_theme( 'saddle-block-fixture' )->exists() ) {
			switch_theme( 'saddle-block-fixture' );
		}
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
							array(
								'type'    => 'core/heading',
								'content' => 'Hello',
								'attrs'   => array( 'level' => 2 ),
							),
							array(
								'type'    => 'core/paragraph',
								'content' => 'World',
							),
						),
					),
					array(
						'type'    => 'core/list',
						'content' => array( 'One', 'Two' ),
					),
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
				'nodes'   => array(
					array(
						'type'    => 'acme/imaginary',
						'content' => 'x',
					),
				),
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
							array(
								'type'    => 'core/button',
								'content' => 'Go',
								'attrs'   => array( 'url' => 'https://example.com/x' ),
							),
						),
					),
					array(
						'type'    => 'core/image',
						'content' => array(
							'src' => 'https://example.com/a.jpg',
							'alt' => 'A',
						),
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
				'node'    => array(
					'type'    => 'core/list-item',
					'content' => 'orphan',
				),
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
						'children' => array(
							array(
								'type'    => 'core/paragraph',
								'content' => 'First',
							),
						),
					),
				),
			)
		);

		$result = $this->run_ability(
			'add-block',
			array(
				'post_id'        => $id,
				'parent_address' => '0',
				'node'           => array(
					'type'    => 'core/paragraph',
					'content' => 'Second',
				),
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
				'nodes'   => array(
					array(
						'type'    => 'core/paragraph',
						'content' => 'Before',
					),
				),
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

	/**
	 * Issue #250: edit-block with a content array on a core/list left an empty
	 * <ul></ul> followed by the OLD items as orphaned <li> siblings. For a
	 * list the content is the children, so the new items replace the old
	 * ones inside the wrapper.
	 */
	public function test_edit_block_list_content_replaces_the_items_inside_the_wrapper() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'    => 'core/list',
						'content' => array( 'Old one', 'Old two', 'Old three' ),
					),
				),
			)
		);

		$result = $this->run_ability(
			'edit-block',
			array(
				'post_id' => $id,
				'address' => '0',
				'content' => array( 'New one', 'New two' ),
			)
		);
		$this->assertNotWPError( $result );

		$content = get_post( $id )->post_content;
		$this->assertStringNotContainsString( '<ul class="wp-block-list"></ul>', $content, 'The wrapper must not close before its items.' );
		$this->assertStringNotContainsString( 'Old one', $content, 'The old items must be replaced, not orphaned.' );
		$this->assertMatchesRegularExpression( '#<ul class="wp-block-list"><!-- wp:list-item --><li>New one</li>.*<li>New two</li><!-- /wp:list-item --></ul>#s', $content );

		$blocks = parse_blocks( $content );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );
	}

	public function test_edit_block_attrs_only_on_a_list_keeps_the_items_inside_the_wrapper() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'    => 'core/list',
						'content' => array( 'First', 'Second' ),
					),
				),
			)
		);

		$result = $this->run_ability(
			'edit-block',
			array(
				'post_id' => $id,
				'address' => '0',
				'attrs'   => array( 'ordered' => true ),
			)
		);
		$this->assertNotWPError( $result );

		$content = get_post( $id )->post_content;
		$this->assertMatchesRegularExpression( '#<ol class="wp-block-list"><!-- wp:list-item --><li>First</li>.*<li>Second</li><!-- /wp:list-item --></ol>#s', $content );
	}

	public function test_move_block_reorders_and_refuses_own_subtree() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'    => 'core/paragraph',
						'content' => 'A',
					),
					array(
						'type'     => 'core/group',
						'children' => array(
							array(
								'type'    => 'core/paragraph',
								'content' => 'B',
							),
						),
					),
				),
			)
		);

		$own_subtree = $this->run_ability(
			'move-block',
			array(
				'post_id'           => $id,
				'from_address'      => '1',
				'to_parent_address' => '1.0',
			)
		);
		$this->assertWPError( $own_subtree );

		$result = $this->run_ability(
			'move-block',
			array(
				'post_id'           => $id,
				'from_address'      => '0',
				'to_parent_address' => '1',
				'position'          => 0,
			)
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '0.0', $result['moved'], 'The destination address must account for the removal shift.' );

		$tree = Saddle_Blocks_Tree::parse( get_post( $id )->post_content );
		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/group', $tree[0]['blockName'] );
		$this->assertCount( 2, $tree[0]['innerBlocks'] );
	}

	/** Three top-level paragraphs, for the move tests. */
	private function three_paragraphs() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'    => 'core/paragraph',
						'content' => 'A',
					),
					array(
						'type'    => 'core/paragraph',
						'content' => 'B',
					),
					array(
						'type'    => 'core/paragraph',
						'content' => 'C',
					),
				),
			)
		);

		return $id;
	}

	/** How many move-block entries the log holds. */
	private function moves_logged() {
		return count( wp_list_filter( Saddle_Log::query( 100, 1 )['entries'], array( 'action' => 'move-block' ) ) );
	}

	/**
	 * A move past the end lands last, and moving the last block there changes
	 * nothing. Regression: it was saved and logged as "Moved … from 2 to 2".
	 */
	public function test_a_move_that_lands_where_it_started_is_refused() {
		$id     = $this->three_paragraphs();
		$before = get_post( $id )->post_content;
		$logged = $this->moves_logged();

		$clamped = $this->run_ability(
			'move-block',
			array(
				'post_id'           => $id,
				'from_address'      => '2',
				'to_parent_address' => '',
				'position'          => 9,
			)
		);
		$this->assertWPError( $clamped );
		$this->assertSame( 'saddle_move_noop', $clamped->get_error_code() );
		$this->assertSame( 'Nothing moved. Position 9 is past the end of that container, so the block would go last, and the block at 2 is already last there.', $clamped->get_error_message() );

		$same = $this->run_ability(
			'move-block',
			array(
				'post_id'           => $id,
				'from_address'      => '1',
				'to_parent_address' => '',
				'position'          => 1,
			)
		);
		$this->assertSame( 'saddle_move_noop', $same->get_error_code() );
		$this->assertSame( 'Nothing moved. The block at 1 is already in that place.', $same->get_error_message() );

		$this->assertSame( $before, get_post( $id )->post_content, 'Nothing was saved.' );
		$this->assertSame( $logged, $this->moves_logged(), 'Nothing was logged.' );
	}

	/** A clamped move that does move says where the block really went. */
	public function test_a_clamped_move_says_where_the_block_went() {
		$id = $this->three_paragraphs();

		$result = $this->run_ability(
			'move-block',
			array(
				'post_id'           => $id,
				'from_address'      => '0',
				'to_parent_address' => '',
				'position'          => 9,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '2', $result['moved'] );
		$this->assertStringStartsWith( 'Position 9 is past the end of that container, so the block went last, to 2.', $result['note'] );
		$this->assertSame( sprintf( 'Moved core/paragraph from 0 to 2 on post #%d.', $id ), Saddle_Log::query( 1, 1 )['entries'][0]['summary'] );

		$tree = Saddle_Blocks_Tree::parse( get_post( $id )->post_content );
		$this->assertStringContainsString( 'A', $tree[2]['innerHTML'] );
	}

	public function test_remove_block_leaf_is_immediate_but_subtree_needs_the_two_step_confirm() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'    => 'core/paragraph',
						'content' => 'Leaf',
					),
					array(
						'type'     => 'core/group',
						'children' => array(
							array(
								'type'    => 'core/paragraph',
								'content' => 'Inside',
							),
						),
					),
				),
			)
		);

		// Leaf: gone in one call.
		$result = $this->run_ability(
			'remove-block',
			array(
				'post_id' => $id,
				'address' => '0',
			)
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '0', $result['removed'] );

		// Subtree: first call previews, nothing changes.
		$before  = get_post( $id )->post_content;
		$preview = $this->run_ability(
			'remove-block',
			array(
				'post_id' => $id,
				'address' => '0',
			)
		);
		$this->assertNotWPError( $preview );
		$this->assertNotEmpty( $preview['confirm_token'] );
		$this->assertSame( $before, get_post( $id )->post_content );

		// Second call with the token executes.
		$done = $this->run_ability(
			'remove-block',
			array(
				'post_id'       => $id,
				'address'       => '0',
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertNotWPError( $done );
		$this->assertSame( '', trim( get_post( $id )->post_content ) );
	}

	/**
	 * Found in the 1.5.0 release QA: a leaf was removed at once even when the
	 * call carried a confirm_token, so a retried confirm (a used token) removed
	 * whichever block had shifted into the address.
	 */
	public function test_a_reused_remove_token_never_removes_the_block_that_shifted_in() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'core/group',
						'children' => array(
							array(
								'type'    => 'core/paragraph',
								'content' => 'Inside',
							),
						),
					),
					array(
						'type'    => 'core/paragraph',
						'content' => 'Shifts up',
					),
				),
			)
		);

		$input   = array(
			'post_id' => $id,
			'address' => '0',
		);
		$preview = $this->run_ability( 'remove-block', $input );
		$this->assertNotWPError( $this->run_ability( 'remove-block', $input + array( 'confirm_token' => $preview['confirm_token'] ) ) );
		$this->assertStringContainsString( 'Shifts up', get_post( $id )->post_content );

		$replay = $this->run_ability( 'remove-block', $input + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertWPError( $replay, 'A used token must be refused, even when the address now holds a leaf.' );
		$this->assertStringContainsString( 'Shifts up', get_post( $id )->post_content );
	}

	public function test_a_remove_token_is_refused_after_the_page_changes() {
		$id = $this->page();
		$this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'core/group',
						'children' => array(
							array(
								'type'    => 'core/paragraph',
								'content' => 'Inside',
							),
						),
					),
				),
			)
		);

		$preview = $this->run_ability(
			'remove-block',
			array(
				'post_id' => $id,
				'address' => '0',
			)
		);
		$this->run_ability(
			'add-block',
			array(
				'post_id'        => $id,
				'parent_address' => '0',
				'node'           => array(
					'type'    => 'core/paragraph',
					'content' => 'Added after the preview',
				),
			)
		);

		$stale = $this->run_ability(
			'remove-block',
			array(
				'post_id'       => $id,
				'address'       => '0',
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertWPError( $stale, 'The preview no longer describes the page.' );
		$this->assertStringContainsString( 'Added after the preview', get_post( $id )->post_content );
	}

	/* -------- guards -------- */

	public function test_block_writes_refuse_builder_built_posts() {
		$id = $this->page( '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' );

		$result = $this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'    => 'core/paragraph',
						'content' => 'x',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_builder_content', $result->get_error_code() );
	}

	public function test_read_tier_blocks_the_writes_but_not_the_reads() {
		Saddle_Capabilities::set_tier( 'read' );
		$id = $this->page( "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->" );

		$this->assertFalse(
			wp_get_ability( 'saddle/set-blocks' )->check_permissions(
				array(
					'post_id' => $id,
					'nodes'   => array(),
				)
			)
		);

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
			$block = Saddle_Blocks_Author::expand_node(
				array(
					'type'    => 'core/heading',
					'content' => 'Still works',
				)
			);
			$this->assertNotWPError( $block );
			$this->assertStringContainsString( '<h2 class="wp-block-heading">Still works</h2>', $block['innerHTML'] );

			$schema = Saddle_Blocks_Schema::describe( 'core/heading' );
			$this->assertNotWPError( $schema );
			$this->assertSame( 'content', $schema['authoring']['mode'] );

			$names = wp_list_pluck( Saddle_Blocks_Schema::catalog( 'heading' ), 'name' );
			$this->assertContains( 'core/heading', $names, 'The catalog must surface curated types missing from the registry.' );

			$unknown = Saddle_Blocks_Author::expand_node(
				array(
					'type'    => 'acme/imaginary',
					'content' => 'x',
				)
			);
			$this->assertWPError( $unknown, 'Non-curated unregistered types must still be refused.' );
		} finally {
			register_block_type( $saved );
		}
	}

	public function test_dynamic_blocks_take_children_and_report_container_mode() {
		// A plugin's section block: dynamic, saves only its inner blocks, and
		// declares what may go inside (the Saddle Blocks shape).
		register_block_type(
			'saddle-test/wrap',
			array(
				'render_callback' => static function ( $attrs, $content ) {
					return '<section class="wrap">' . $content . '</section>';
				},
				'allowed_blocks'  => array( 'core/heading', 'core/paragraph' ),
			)
		);
		// And one that declares nothing about its insides.
		register_block_type(
			'saddle-test/plain',
			array(
				'render_callback' => static function ( $attrs, $content ) {
					return '<div class="plain">' . $content . '</div>';
				},
			)
		);

		try {
			$node = array(
				'type'     => 'saddle-test/wrap',
				'attrs'    => array( 'kind' => 'steps' ),
				'children' => array(
					array(
						'type'    => 'core/heading',
						'content' => 'Inside',
						'attrs'   => array( 'level' => 2 ),
					),
					array(
						'type'    => 'core/paragraph',
						'content' => 'A line.',
					),
				),
			);

			$block = Saddle_Blocks_Author::expand_node( $node );
			$this->assertNotWPError( $block );
			$this->assertCount( 2, $block['innerBlocks'] );
			$this->assertSame( array( null, null ), $block['innerContent'], 'A dynamic wrapper saves only its children: one null per child, no markup of its own.' );
			$this->assertSame( '', $block['innerHTML'] );

			// The editor's own parser sees the same two children, and the
			// render callback wraps them at view time.
			$parsed = parse_blocks( serialize_block( $block ) );
			$this->assertSame( 'saddle-test/wrap', $parsed[0]['blockName'] );
			$this->assertCount( 2, $parsed[0]['innerBlocks'] );
			$rendered = render_block( $parsed[0] );
			$this->assertStringContainsString( '<section class="wrap"><h2 class="wp-block-heading">Inside</h2>', $rendered );

			// No children is still fine (attrs-only as before).
			$empty = Saddle_Blocks_Author::expand_node( array( 'type' => 'saddle-test/wrap' ) );
			$this->assertNotWPError( $empty );
			$this->assertSame( array(), $empty['innerContent'] );

			// "content" has nowhere to go on a dynamic block.
			$bad = Saddle_Blocks_Author::expand_node(
				array(
					'type'    => 'saddle-test/wrap',
					'content' => 'nope',
				)
			);
			$this->assertWPError( $bad );
			$this->assertSame( 'saddle_bad_node', $bad->get_error_code() );

			// A dynamic block that declares nothing about its insides still takes children.
			$plain = Saddle_Blocks_Author::expand_node(
				array(
					'type'     => 'saddle-test/plain',
					'children' => array(
						array(
							'type'    => 'core/paragraph',
							'content' => 'x',
						),
					),
				)
			);
			$this->assertNotWPError( $plain );
			$this->assertCount( 1, $plain['innerBlocks'] );

			// The schema says which is which.
			$wrap_schema = Saddle_Blocks_Schema::describe( 'saddle-test/wrap' );
			$this->assertSame( 'container', $wrap_schema['authoring']['mode'] );
			$this->assertSame( array( 'core/heading', 'core/paragraph' ), $wrap_schema['allowed_children'] );
			$plain_schema = Saddle_Blocks_Schema::describe( 'saddle-test/plain' );
			$this->assertSame( 'attrs-only', $plain_schema['authoring']['mode'] );

			// End to end through the ability: saved, and readable back as a tree.
			$id     = $this->page();
			$result = $this->run_ability(
				'set-blocks',
				array(
					'post_id' => $id,
					'nodes'   => array( $node ),
				)
			);
			$this->assertNotWPError( $result );
			$this->assertSame( 3, $result['blocks'] );
			$content = get_post( $id )->post_content;
			$this->assertStringContainsString( '<!-- wp:saddle-test/wrap {"kind":"steps"} -->', $content );
			$this->assertStringContainsString( '<!-- /wp:saddle-test/wrap -->', $content );
			$tree = $this->run_ability( 'get-blocks', array( 'post_id' => $id ) );
			$this->assertNotWPError( $tree );
			$this->assertSame( 'saddle-test/wrap', $tree['nodes'][0]['type'] );
			$this->assertSame( 2, $tree['nodes'][0]['children'] );
		} finally {
			unregister_block_type( 'saddle-test/wrap' );
			unregister_block_type( 'saddle-test/plain' );
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
				$shape['colors']  = array(
					array(
						'id'    => 'gcid-x',
						'value' => '#123456',
					),
				);
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
			$set = $this->run_ability(
				'set-blocks',
				array(
					'post_id' => $id,
					'nodes'   => $recipe['nodes'],
				)
			);
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
				return array(
					'builder' => 'divi',
					'nodes'   => array( array( 'type' => 'divi/section' ) ),
				);
			}
		);
		$r = $this->run_ability( 'get-section-recipe', array( 'name' => 'hero' ) );
		remove_all_filters( 'saddle_section_recipe' );

		$this->assertSame( 'divi', $r['builder'] );
		$this->assertSame( 'divi/section', $r['nodes'][0]['type'] );
	}

	public function test_bootstrap_design_system_gates_before_writing() {
		Saddle_Capabilities::set_tier( 'admin' );
		$this->use_block_theme();

		if ( ! wp_is_block_theme() ) {
			$this->markTestSkipped( 'The block-theme fixture did not activate.' );
		}

		// Preview: a fresh call returns a confirm_token and does not apply.
		$preview = $this->run_ability( 'bootstrap-design-system', array( 'force' => true ) );
		$this->assertNotWPError( $preview );
		$this->assertArrayHasKey( 'confirm_token', $preview );
		$this->assertNotEmpty( $preview['preview']['spec']['colors'] );
		$this->assertSame( 'global-styles', $preview['preview']['store'] );

		// The preview must not have written anything yet.
		$before = wp_get_global_settings();
		$this->assertEmpty(
			wp_list_filter( (array) ( $before['color']['palette']['custom'] ?? array() ), array( 'slug' => 'brand-accent' ) ),
			'The preview call must not write a palette.'
		);

		// Applying now really lands in the site's global styles, where the Site
		// Editor reads from. This used to report applied=false and tell the
		// owner to go do it by hand — after they had spent the confirm token.
		$applied = $this->run_ability(
			'bootstrap-design-system',
			array(
				'force'         => true,
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertNotWPError( $applied );
		$this->assertTrue( $applied['applied'] );
		$this->assertSame( 'global-styles', $applied['store'] );
		$this->assertSame( 6, $applied['added']['colors'] );

		$palette = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data()['settings']['color']['palette']['custom'] ?? array();
		$this->assertNotEmpty( wp_list_filter( $palette, array( 'slug' => 'brand-accent' ) ), 'The accent must be readable back out of user global styles.' );
	}

	public function test_bootstrap_design_system_merges_and_never_overwrites() {
		Saddle_Capabilities::set_tier( 'admin' );
		$this->use_block_theme();

		if ( ! wp_is_block_theme() ) {
			$this->markTestSkipped( 'The block-theme fixture did not activate.' );
		}

		// Seed once, then seed again with a different accent. The gate's own
		// summary promises "Existing tokens are not removed", so the second run
		// must leave the first accent alone rather than overwriting it.
		$first = $this->run_ability(
			'bootstrap-design-system',
			array(
				'force'  => true,
				'accent' => '#ff0000',
			)
		);
		$this->run_ability(
			'bootstrap-design-system',
			array(
				'force'         => true,
				'accent'        => '#ff0000',
				'confirm_token' => $first['confirm_token'],
			)
		);

		$second  = $this->run_ability(
			'bootstrap-design-system',
			array(
				'force'  => true,
				'accent' => '#00ff00',
			)
		);
		$applied = $this->run_ability(
			'bootstrap-design-system',
			array(
				'force'         => true,
				'accent'        => '#00ff00',
				'confirm_token' => $second['confirm_token'],
			)
		);

		$this->assertNotWPError( $applied );
		$this->assertSame( 0, $applied['added']['colors'], 'A second seed must add nothing — every slug is already taken.' );

		$palette = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data()['settings']['color']['palette']['custom'] ?? array();
		$accent  = wp_list_filter( $palette, array( 'slug' => 'brand-accent' ) );
		$accent  = reset( $accent );
		$this->assertSame( '#ff0000', $accent['color'], 'The original accent must survive a second seed.' );
	}
}
