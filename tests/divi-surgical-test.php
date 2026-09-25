<?php
/**
 * The surgical write abilities + the module.json schema distiller.
 *
 * Pins: add/edit/move/remove operate on addressed nodes with validation
 * before every save (invalid result = nothing persisted), remove gates
 * subtree deletion behind the shared approval flow, and get-module-schema
 * distills a module.json fixture into the agent contract.
 *
 * @package Saddle
 */

class Saddle_Divi_Surgical_Test extends WP_UnitTestCase {

	private $admin;
	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		// A minimal module.json fixture mirroring the real contract, so the
		// schema distiller is testable without a Divi install.
		self::$fixture_dir = get_temp_dir() . 'saddle-pro-fixture-modules';
		$module_dir        = self::$fixture_dir . '/fancy-button';
		if ( ! is_dir( $module_dir ) ) {
			mkdir( $module_dir, 0755, true );
		}
		file_put_contents(
			$module_dir . '/module.json',
			wp_json_encode(
				array(
					'name'       => 'saddletest/fancy-button',
					'title'      => 'Fancy Button',
					'category'   => 'module',
					'attributes' => array(
						'module' => array( 'type' => 'object' ),
						'button' => array(
							'type'        => 'object',
							'elementType' => 'button',
							'settings'    => array(
								'innerContent' => array(
									'groups' => array(
										'text' => array( 'item' => array( 'subName' => 'text' ) ),
										'link' => array( 'item' => array( 'subName' => 'linkUrl' ) ),
									),
								),
								'decoration'   => array(
									'font'   => array(),
									'border' => array(),
									'sticky' => array(),
								),
							),
						),
						'title'  => array(
							'type'        => 'object',
							'elementType' => 'heading',
							'settings'    => array(
								'innerContent' => array( 'item' => array( 'label' => 'Heading' ) ),
							),
						),
					),
				)
			)
		);

		// The default render-attributes that encode the real value nesting —
		// the font group nests under an extra `.font`, border does not. This is
		// what the style distiller reads to produce correct paths.
		file_put_contents(
			$module_dir . '/module-default-render-attributes.json',
			wp_json_encode(
				array(
					'button' => array(
						'decoration' => array(
							'font'   => array( 'font' => array( 'desktop' => array( 'value' => array( 'headingLevel' => 'h1' ) ) ) ),
							'border' => array( 'desktop' => array( 'value' => (object) array() ) ),
							'sticky' => array( 'desktop' => array( 'value' => array( 'position' => 'none' ) ) ),
						),
					),
				)
			)
		);
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'write' );

		$dir = self::$fixture_dir;
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'read' );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	/** A built page: section > row > [column(heading, text), column(button)]. */
	private function make_page() {
		$markup = implode(
			"\n",
			array(
				'<!-- wp:divi/placeholder -->',
				'<!-- wp:divi/section -->',
				'<!-- wp:divi/row -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Old title"}}}} --><!-- /wp:divi/heading -->',
				'<!-- wp:divi/text --><p>Body</p><!-- /wp:divi/text -->',
				'<!-- /wp:divi/column -->',
				'<!-- wp:divi/column -->',
				'<!-- wp:divi/button --><!-- /wp:divi/button -->',
				'<!-- /wp:divi/column -->',
				'<!-- /wp:divi/row -->',
				'<!-- /wp:divi/section -->',
				'<!-- /wp:divi/placeholder -->',
			)
		);
		return self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => $markup ) );
	}

	private function run_ability( $name, $input ) {
		return wp_get_ability( "saddle/{$name}" )->execute( $input );
	}

	/* -------- discovery -------- */

	/**
	 * Regression: scan_dirs() used to glob every <plugin>/modules-json on
	 * disk, so an INACTIVE plugin's modules were cataloged — and an agent
	 * could author a module the page can't render (found in the P0 live
	 * round: inactive carousel-pro's dcp/card was listed as available).
	 * Only active plugins' module dirs may be scanned.
	 */
	public function test_scan_dirs_skips_inactive_plugins() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );

		$active_dir   = WP_PLUGIN_DIR . '/saddletest-active-pack/modules-json';
		$inactive_dir = WP_PLUGIN_DIR . '/saddletest-inactive-pack/modules-json';
		mkdir( $active_dir, 0777, true );
		mkdir( $inactive_dir, 0777, true );

		update_option( 'active_plugins', array( 'saddletest-active-pack/saddletest-active-pack.php' ) );

		$dirs = Saddle_Divi_Schema::scan_dirs();

		// Cleanup before asserting so a failure doesn't leak fixtures.
		rmdir( $active_dir );
		rmdir( dirname( $active_dir ) );
		rmdir( $inactive_dir );
		rmdir( dirname( $inactive_dir ) );
		delete_option( 'active_plugins' );

		$this->assertContains( $active_dir, $dirs, 'Active plugin module dirs must be scanned.' );
		$this->assertNotContains( $inactive_dir, $dirs, 'Inactive plugin module dirs must NOT be scanned.' );
	}

	/* -------- schema -------- */

	public function test_schema_distills_content_fields_and_decorations() {
		// Default is composition-only: content fields yes, style inventory no
		// (context discipline — that detail lives behind verbose/style-schema).
		$schema = $this->run_ability( 'divi-get-module-schema', array( 'type' => 'saddletest/fancy-button' ) );

		$this->assertNotWPError( $schema );
		$this->assertSame( 'Fancy Button', $schema['title'] );

		$by_name = array_column( $schema['attributes'], null, 'name' );

		$this->assertSame( 'object', $by_name['button']['content_shape'] );
		$this->assertEqualSets( array( 'text', 'linkUrl' ), $by_name['button']['content_fields'] );
		$this->assertArrayNotHasKey( 'decorations', $by_name['button'] );

		$this->assertSame( 'string', $by_name['title']['content_shape'] );
		$this->assertStringContainsString( 'fields.title', $by_name['title']['write_as'] );

		// verbose=true restores the full inventory.
		$verbose = $this->run_ability(
			'divi-get-module-schema',
			array(
				'type'    => 'saddletest/fancy-button',
				'verbose' => true,
			)
		);
		$by_name = array_column( $verbose['attributes'], null, 'name' );
		$this->assertEqualSets( array( 'font', 'border', 'sticky' ), $by_name['button']['decorations'] );
	}

	public function test_schema_unknown_type_errors_helpfully() {
		$result = $this->run_ability( 'divi-get-module-schema', array( 'type' => 'divi/no-such-module' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unknown_module', $result->get_error_code() );
	}

	public function test_style_schema_lists_groups_then_expands_one() {
		$style = $this->run_ability( 'divi-get-style-schema', array( 'type' => 'saddletest/fancy-button' ) );
		$this->assertNotWPError( $style );

		$groups = array_column( $style['style_groups'], 'group' );
		$this->assertContains( 'font', $groups, 'The fixture button has a font decoration group.' );
		$this->assertContains( 'border', $groups );

		// Border does NOT nest — flat path.
		$border = array_values( array_filter( $style['style_groups'], static function ( $g ) {
			return 'border' === $g['group'];
		} ) )[0];
		$this->assertSame( 'button.decoration.border.desktop.value', $border['path'] );

		// Regression (the live bug): the font group DOES nest under an extra
		// `.font`, so the path must be distilled from render-attributes as
		// button.decoration.font.font.desktop.value — not the flat form that
		// silently no-ops.
		$font = array_values( array_filter( $style['style_groups'], static function ( $g ) {
			return 'font' === $g['group'] && 'button' === $g['attr'];
		} ) )[0];
		$this->assertSame( 'button.decoration.font.font.desktop.value', $font['path'], 'Typography path must include the nested .font Divi actually reads.' );

		// Expanding one group returns its settable fields.
		$font = $this->run_ability( 'divi-get-style-schema', array( 'type' => 'saddletest/fancy-button', 'group' => 'font' ) );
		$this->assertSame( 'font', $font['group'] );
		$this->assertContains( 'color', $font['fields'] );
		$this->assertContains( 'size', $font['fields'] );
		$this->assertContains( 'headingLevel', $font['fields'], 'The lint accessor reads headingLevel — the schema must advertise it.' );
	}

	public function test_style_schema_serves_value_shape_examples() {
		$style = $this->run_ability( 'divi-get-style-schema', array( 'type' => 'saddletest/fancy-button' ) );
		$this->assertNotWPError( $style );

		// Curated example shapes ride every listed group that has one — the
		// thing that stops scalar-where-Divi-wants-an-object silent no-ops.
		$border = array_values( array_filter( $style['style_groups'], static function ( $g ) {
			return 'border' === $g['group'];
		} ) )[0];
		$this->assertArrayHasKey( 'example', $border );
		$this->assertIsArray( $border['example']['radius'], 'Border radius is an OBJECT {sync, topLeft, …}, not a scalar.' );
		$this->assertSame( 'on', $border['example']['radius']['sync'] );

		// The single-group expansion carries the example too.
		$font = $this->run_ability( 'divi-get-style-schema', array( 'type' => 'saddletest/fancy-button', 'group' => 'font' ) );
		$this->assertArrayHasKey( 'example', $font );
		$this->assertArrayHasKey( 'size', $font['example'] );

		// A group with no curated entry mines the module's own render default
		// (the fixture's sticky group ships one), and a group with neither
		// simply carries no example.
		$sticky = $this->run_ability( 'divi-get-style-schema', array( 'type' => 'saddletest/fancy-button', 'group' => 'sticky' ) );
		$this->assertNotWPError( $sticky );
		$this->assertSame( array( 'position' => 'none' ), $sticky['example'], 'Non-curated groups mine the module\'s own render default.' );
	}

	public function test_style_schema_unknown_group_errors() {
		$result = $this->run_ability( 'divi-get-style-schema', array( 'type' => 'saddletest/fancy-button', 'group' => 'nope' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unknown_style_group', $result->get_error_code() );
	}

	public function test_list_modules_uses_discovered_catalog() {
		$result = $this->run_ability( 'divi-list-modules', array() );

		$types = wp_list_pluck( $result['modules'], 'type' );
		$this->assertContains( 'saddletest/fancy-button', $types );
	}

	/* -------- add -------- */

	public function test_add_module_appends_into_a_column_and_addresses_it() {
		$page = $this->make_page();

		$result = $this->run_ability(
			'divi-add-module',
			array(
				'post_id'        => $page,
				'parent_address' => '0.0.0.1',
				'node'           => array( 'type' => 'divi/divider' ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '0.0.0.1.1', $result['added'], 'Appended after the button.' );

		$tree = Saddle_Divi_Tree::parse( get_post( $page )->post_content );
		$this->assertSame( 'divi/divider', Saddle_Divi_Tree::get( $tree, '0.0.0.1.1' )['blockName'] );
	}

	public function test_add_module_rejects_invalid_placement_and_saves_nothing() {
		$page   = $this->make_page();
		$before = get_post( $page )->post_content;

		$result = $this->run_ability(
			'divi-add-module',
			array(
				'post_id'        => $page,
				'parent_address' => '0.0.0.0', // a column…
				'node'           => array( 'type' => 'divi/row' ), // …can't hold a row.
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_invalid_structure', $result->get_error_code() );
		$this->assertSame( $before, get_post( $page )->post_content );
	}

	/* -------- edit -------- */

	public function test_edit_module_patches_fields_and_merges_attrs() {
		$page = $this->make_page();

		$result = $this->run_ability(
			'divi-edit-module',
			array(
				'post_id' => $page,
				'address' => '0.0.0.0.0',
				'fields'  => array( 'title' => 'New title' ),
				'attrs'   => array(
					'title' => array(
						'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'headingLevel' => 'h2' ) ) ) ) ),
					),
				),
			)
		);

		$this->assertNotWPError( $result );

		$node = Saddle_Divi_Tree::get(
			Saddle_Divi_Tree::parse( get_post( $page )->post_content ),
			'0.0.0.0.0'
		);
		$this->assertSame( 'New title', $node['attrs']['title']['innerContent']['desktop']['value'] );
		$this->assertSame( 'h2', $node['attrs']['title']['decoration']['font']['font']['desktop']['value']['headingLevel'] );
	}

	public function test_edit_module_bad_address_is_a_clean_error() {
		$page   = $this->make_page();
		$result = $this->run_ability(
			'divi-edit-module',
			array( 'post_id' => $page, 'address' => '0.9.9', 'fields' => array( 'title' => 'x' ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_address', $result->get_error_code() );
	}

	/* -------- move -------- */

	public function test_move_module_across_columns_with_snapshot_addresses() {
		$page = $this->make_page();

		// Move the text module (0.0.0.0.1) into the second column (0.0.0.1).
		$result = $this->run_ability(
			'divi-move-module',
			array(
				'post_id'           => $page,
				'from_address'      => '0.0.0.0.1',
				'to_parent_address' => '0.0.0.1',
				'position'          => 0,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '0.0.0.1.0', $result['moved'] );

		$tree = Saddle_Divi_Tree::parse( get_post( $page )->post_content );
		$this->assertSame( 'divi/text', Saddle_Divi_Tree::get( $tree, '0.0.0.1.0' )['blockName'] );
		$this->assertSame( 'divi/button', Saddle_Divi_Tree::get( $tree, '0.0.0.1.1' )['blockName'] );
		$this->assertCount( 1, Saddle_Divi_Tree::get( $tree, '0.0.0.0' )['innerBlocks'], 'Source column keeps only the heading.' );
	}

	public function test_move_adjusts_for_sibling_shift_when_target_follows_source() {
		$page = $this->make_page();

		// Move column 0 (0.0.0.0) into… wait — columns move between rows; here:
		// move the heading (0.0.0.0.0) to the END of its own column, addressed
		// pre-removal as parent 0.0.0.0 — position append lands it after text.
		$result = $this->run_ability(
			'divi-move-module',
			array(
				'post_id'           => $page,
				'from_address'      => '0.0.0.0.0',
				'to_parent_address' => '0.0.0.0',
			)
		);

		$this->assertNotWPError( $result );
		$tree  = Saddle_Divi_Tree::parse( get_post( $page )->post_content );
		$types = array_map(
			static function ( $b ) {
				return $b['blockName'];
			},
			Saddle_Divi_Tree::get( $tree, '0.0.0.0' )['innerBlocks']
		);
		$this->assertSame( array( 'divi/text', 'divi/heading' ), $types );
	}

	public function test_move_into_own_subtree_is_refused() {
		$page   = $this->make_page();
		$result = $this->run_ability(
			'divi-move-module',
			array(
				'post_id'           => $page,
				'from_address'      => '0.0',
				'to_parent_address' => '0.0.0',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_move', $result->get_error_code() );
	}

	/* -------- remove -------- */

	public function test_remove_leaf_is_immediate_and_logged() {
		$page = $this->make_page();

		$result = $this->run_ability(
			'divi-remove-module',
			array( 'post_id' => $page, 'address' => '0.0.0.0.1' )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '0.0.0.0.1', $result['removed'] );

		$entries = Saddle_Log::query( 5, 1 )['entries'];
		$this->assertContains( 'divi-remove-module', wp_list_pluck( $entries, 'action' ) );
	}

	public function test_remove_subtree_previews_then_executes_with_token() {
		$page   = $this->make_page();
		$before = get_post( $page )->post_content;

		// First call: preview only, nothing changed.
		$preview = $this->run_ability(
			'divi-remove-module',
			array( 'post_id' => $page, 'address' => '0.0.0.1' ) // column with the button.
		);
		$this->assertNotWPError( $preview );
		$this->assertArrayHasKey( 'confirm_token', $preview );
		$this->assertSame( $before, get_post( $page )->post_content, 'Preview must not mutate.' );

		// Second call with the token: executes exactly once.
		$done = $this->run_ability(
			'divi-remove-module',
			array(
				'post_id'       => $page,
				'address'       => '0.0.0.1',
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertNotWPError( $done );
		$this->assertSame( '0.0.0.1', $done['removed'] );

		$tree = Saddle_Divi_Tree::parse( get_post( $page )->post_content );
		$this->assertCount( 1, Saddle_Divi_Tree::get( $tree, '0.0.0' )['innerBlocks'], 'One column left.' );

		// Token is single-use.
		$again = $this->run_ability(
			'divi-remove-module',
			array(
				'post_id'       => $page,
				'address'       => '0.0.0.0',
				'confirm_token' => $preview['confirm_token'],
			)
		);
		$this->assertWPError( $again );
	}

	public function test_remove_token_is_refused_after_the_tree_shifts() {
		$page = $this->make_page();

		// Preview the subtree removal, then EDIT the page — addresses are
		// positional, so the previewed address may now name a different node.
		$preview = $this->run_ability(
			'divi-remove-module',
			array( 'post_id' => $page, 'address' => '0.0.0.1' )
		);
		$this->assertArrayHasKey( 'confirm_token', $preview );

		$this->run_ability(
			'divi-edit-module',
			array(
				'post_id' => $page,
				'address' => '0.0.0.0.0',
				'fields'  => array( 'title' => 'Changed between preview and confirm' ),
			)
		);

		$stale = $this->run_ability(
			'divi-remove-module',
			array(
				'post_id'       => $page,
				'address'       => '0.0.0.1',
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$this->assertWPError( $stale, 'A token previewed before an intervening edit must be refused — not remove whatever now sits at the shifted address.' );
		$this->assertStringContainsString( 'Changed between preview and confirm', get_post( $page )->post_content, 'Nothing may be removed on a stale confirm.' );
	}

	public function test_schema_index_transient_clears_on_upgrades_and_cache_flush() {
		// The named callback is hooked to both invalidation events (firing
		// the real upgrader hook would trip unrelated core listeners the
		// harness doesn't load).
		$this->assertNotFalse( has_action( 'upgrader_process_complete', array( 'Saddle_Divi_Schema', 'flush_index' ) ), 'An in-place pack update must invalidate the schema index.' );
		$this->assertNotFalse( has_action( 'saddle_flush_cache', array( 'Saddle_Divi_Schema', 'flush_index' ) ), 'The owner cache flush must invalidate the schema index.' );
		$this->assertNotFalse( has_action( 'saddle_flush_cache', array( 'Saddle_Divi_Bundle', 'flush' ) ), 'The owner cache flush must also drop the context bundle.' );

		set_transient( Saddle_Divi_Schema::INDEX_TRANSIENT, array( 'v' => 'x', 'p' => 'y', 'map' => array( 'a' => 'b' ) ), 100 );
		Saddle_Divi_Schema::flush_index();
		$this->assertFalse( get_transient( Saddle_Divi_Schema::INDEX_TRANSIENT ), 'The callback must clear the transient.' );
	}

	public function test_writes_refuse_non_divi5_posts() {
		$plain = self::factory()->post->create( array( 'post_content' => '<p>plain</p>' ) );

		$result = $this->run_ability(
			'divi-add-module',
			array( 'post_id' => $plain, 'node' => array( 'type' => 'divi/divider' ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_divi5', $result->get_error_code() );
	}

	/* -------- move edge cases (#26) -------- */

	/**
	 * A row with 11 columns, each holding one button — so addresses reach
	 * double digits (0.0.0.10) and the self-subtree guard's dot-boundary
	 * matching is actually exercised (0.0.0.1 must not look like a prefix of
	 * 0.0.0.10).
	 */
	private function make_wide_page() {
		$cols = '';
		for ( $i = 0; $i < 11; $i++ ) {
			$cols .= "<!-- wp:divi/column -->\n<!-- wp:divi/button --><!-- /wp:divi/button -->\n<!-- /wp:divi/column -->\n";
		}
		$markup = "<!-- wp:divi/placeholder -->\n<!-- wp:divi/section -->\n<!-- wp:divi/row -->\n{$cols}<!-- /wp:divi/row -->\n<!-- /wp:divi/section -->\n<!-- /wp:divi/placeholder -->";
		return self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => $markup ) );
	}

	/**
	 * The dot-boundary guard must NOT treat 0.0.0.1 as inside 0.0.0.10: moving
	 * the button out of column 1 into column 10 is a legitimate move. A naive
	 * strpos without the trailing dot would false-reject this as a self-subtree
	 * move.
	 */
	public function test_move_between_prefix_lookalike_columns_is_allowed() {
		$page = $this->make_wide_page();

		$result = $this->run_ability(
			'divi-move-module',
			array(
				'post_id'           => $page,
				'from_address'      => '0.0.0.1.0',
				'to_parent_address' => '0.0.0.10',
			)
		);

		$this->assertNotWPError( $result, 'A move into a numeric-prefix-lookalike sibling must not be rejected as a self-subtree move.' );

		$tree = Saddle_Divi_Tree::parse( get_post( $page )->post_content );
		// Column 1 is now empty; column 10 gained the button (appended → index 1).
		$this->assertCount( 0, Saddle_Divi_Tree::get( $tree, '0.0.0.1' )['innerBlocks'], 'Source column is emptied.' );
		$this->assertSame( 'divi/button', Saddle_Divi_Tree::get( $tree, '0.0.0.10.1' )['blockName'], 'Button landed in column 10.' );
	}

	/**
	 * Moving a container into one of its own deep descendants is refused, not
	 * just the immediate-child case.
	 */
	public function test_move_into_deep_descendant_is_refused() {
		$page   = $this->make_page();
		$result = $this->run_ability(
			'divi-move-module',
			array(
				'post_id'           => $page,
				'from_address'      => '0.0',
				'to_parent_address' => '0.0.0.0',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_move', $result->get_error_code() );
	}

	/**
	 * Same-parent move where the destination index sits AFTER the source: the
	 * snapshot address must be decremented for the removal shift. Move the
	 * button from column 0 to the end of the wide row, then confirm the columns
	 * before and after it stayed intact (i.e. the shift math didn't corrupt
	 * neighbouring addresses).
	 */
	public function test_move_across_columns_with_double_digit_shift() {
		$page = $this->make_wide_page();

		// Move column 2's button into column 10 (both double-digit-adjacent).
		$result = $this->run_ability(
			'divi-move-module',
			array(
				'post_id'           => $page,
				'from_address'      => '0.0.0.2.0',
				'to_parent_address' => '0.0.0.10',
				'position'          => 0,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '0.0.0.10.0', $result['moved'] );

		$tree = Saddle_Divi_Tree::parse( get_post( $page )->post_content );
		$this->assertCount( 0, Saddle_Divi_Tree::get( $tree, '0.0.0.2' )['innerBlocks'], 'Source column emptied.' );
		$this->assertSame( 'divi/button', Saddle_Divi_Tree::get( $tree, '0.0.0.10.0' )['blockName'], 'Button inserted at position 0 of column 10.' );
		// The original button plus the moved one → column 10 has two.
		$this->assertCount( 2, Saddle_Divi_Tree::get( $tree, '0.0.0.10' )['innerBlocks'] );
	}
}
