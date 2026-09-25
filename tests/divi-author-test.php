<?php
/**
 * Saddle_Divi_Author + saddle/divi-set-page — the bulk-build path.
 *
 * Pins the authoring contract (fields → innerContent envelope, raw attrs
 * passthrough, auto placeholder wrap) and the ability's guarantees: builds
 * only on Divi 5 / empty posts, rejects invalid structure without saving,
 * marks the page builder-enabled, keeps a revision, logs the mutation.
 *
 * @package Saddle
 */

class Saddle_Divi_Author_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		// The test theme isn't Divi; the write path requires Divi 5 presence.
		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	/** A small landing hero in the authoring format. */
	private function hero_nodes() {
		return array(
			array(
				'type'     => 'divi/section',
				'children' => array(
					array(
						'type'     => 'divi/row',
						'children' => array(
							array(
								'type'     => 'divi/column',
								'children' => array(
									array(
										'type'   => 'divi/heading',
										'fields' => array( 'title' => 'Welcome to Saddle' ),
									),
									array(
										'type'   => 'divi/text',
										'fields' => array( 'content' => '<p>Your AI can build this.</p>' ),
									),
									array(
										'type'   => 'divi/button',
										'fields' => array(
											'button' => array(
												'text'    => 'Get started',
												'linkUrl' => '/pricing',
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/* -------- expansion -------- */

	public function test_fields_expand_into_the_innercontent_envelope() {
		$tree = Saddle_Divi_Author::expand( $this->hero_nodes() );

		$this->assertNotWPError( $tree );
		// Wrapped in the placeholder root.
		$this->assertSame( 'divi/placeholder', $tree[0]['blockName'] );

		$heading = Saddle_Divi_Tree::get( $tree, '0.0.0.0.0' );
		$this->assertSame( 'divi/heading', $heading['blockName'] );
		$this->assertSame(
			'Welcome to Saddle',
			$heading['attrs']['title']['innerContent']['desktop']['value']
		);

		$button = Saddle_Divi_Tree::get( $tree, '0.0.0.0.2' );
		$this->assertSame(
			array( 'text' => 'Get started', 'linkUrl' => '/pricing' ),
			$button['attrs']['button']['innerContent']['desktop']['value']
		);
	}

	public function test_raw_attrs_pass_through_and_win_over_fields() {
		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'   => 'divi/heading',
				'fields' => array( 'title' => 'From fields' ),
				'attrs'  => array(
					'title' => array(
						'innerContent' => array( 'desktop' => array( 'value' => 'From raw attrs' ) ),
						'decoration'   => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'headingLevel' => 'h2' ) ) ) ) ),
					),
				),
			)
		);

		$this->assertNotWPError( $block );
		$this->assertSame( 'From raw attrs', $block['attrs']['title']['innerContent']['desktop']['value'] );
		$this->assertSame( 'h2', $block['attrs']['title']['decoration']['font']['font']['desktop']['value']['headingLevel'] );
	}

	public function test_explicit_placeholder_is_not_double_wrapped() {
		$tree = Saddle_Divi_Author::expand(
			array( array( 'type' => 'divi/placeholder', 'children' => array() ) )
		);

		$this->assertNotWPError( $tree );
		$this->assertCount( 1, $tree );
		$this->assertSame( 'divi/placeholder', $tree[0]['blockName'] );
		$this->assertCount( 0, $tree[0]['innerBlocks'] );
	}

	public function test_node_without_type_errors_with_its_position() {
		$result = Saddle_Divi_Author::expand(
			array( array( 'type' => 'divi/section', 'children' => array( array( 'fields' => array() ) ) ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_node', $result->get_error_code() );
	}

	public function test_malformed_module_type_is_rejected() {
		// A block name is interpolated raw into `<!-- wp:NAME -->`; the author
		// must reject a comment-breakout or a garbage name before it is saved.
		foreach ( array( 'divi/x-->', 'divi/hero card', 'Divi/Text', 'notanamespace', 'divi/', '../evil' ) as $bad ) {
			$result = Saddle_Divi_Author::expand_node( array( 'type' => $bad ) );
			$this->assertWPError( $result, "Type '{$bad}' must be rejected." );
			$this->assertSame( 'saddle_bad_type', $result->get_error_code() );
		}
	}

	public function test_wellformed_type_expands() {
		$block = Saddle_Divi_Author::expand_node( array( 'type' => 'divi/blurb' ) );
		$this->assertNotWPError( $block );
		$this->assertSame( 'divi/blurb', $block['blockName'] );
	}

	public function test_unknown_node_key_is_rejected_not_silently_dropped() {
		// Live-testing footgun: an agent writing `content` (instead of `fields`)
		// used to get a success response and an empty module. It must error.
		$result = Saddle_Divi_Author::expand_node(
			array( 'type' => 'divi/heading', 'content' => array( 'title' => 'Hi' ) )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unknown_node_key', $result->get_error_code() );
		$this->assertStringContainsString( 'content', $result->get_error_message() );

		// The valid keys still expand cleanly.
		$ok = Saddle_Divi_Author::expand_node(
			array( 'type' => 'divi/heading', 'fields' => array( 'title' => 'Hi' ), 'attrs' => array(), 'children' => array() )
		);
		$this->assertNotWPError( $ok );
	}

	public function test_dotted_style_paths_expand_into_nested_attrs() {
		// The live bug: a dotted style path must NOT survive as a flat key.
		$block = Saddle_Divi_Author::expand_node(
			array(
				'type'  => 'divi/text',
				'attrs' => array(
					'module.decoration.background.desktop.value.color' => '#0f5fa0',
					'title.decoration.font.font.desktop.value.textAlign' => 'center',
				),
			)
		);
		$this->assertNotWPError( $block );

		$this->assertArrayNotHasKey( 'module.decoration.background.desktop.value.color', $block['attrs'], 'A dotted key must never be stored verbatim.' );
		$this->assertSame( '#0f5fa0', $block['attrs']['module']['decoration']['background']['desktop']['value']['color'] );
		$this->assertSame( 'center', $block['attrs']['title']['decoration']['font']['font']['desktop']['value']['textAlign'] );
	}

	public function test_dotted_keys_deep_merge_under_a_shared_prefix() {
		// Two paths under the same prefix must combine, not overwrite.
		$out = Saddle_Divi_Author::expand_dotted_keys(
			array(
				'module.decoration.background.desktop.value.color'    => '#111',
				'module.decoration.background.desktop.value.gradient' => 'on',
				'module.decoration.spacing.desktop.value.padding'     => '20px',
			)
		);
		$bg = $out['module']['decoration']['background']['desktop']['value'];
		$this->assertSame( '#111', $bg['color'] );
		$this->assertSame( 'on', $bg['gradient'] );
		$this->assertSame( '20px', $out['module']['decoration']['spacing']['desktop']['value']['padding'] );
	}

	public function test_edit_module_expands_dotted_paths_end_to_end() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:divi/text --><!-- /wp:divi/text -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);

		wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $post_id,
				'address' => '0.0.0.0',
				'attrs'   => array( 'module.decoration.background.desktop.value.color' => '#0f5fa0' ),
			)
		);

		$tree = Saddle_Divi_Tree::parse( get_post( $post_id )->post_content );
		$node = Saddle_Divi_Tree::get( $tree, '0.0.0.0' );
		$this->assertArrayNotHasKey( 'module.decoration.background.desktop.value.color', $node['attrs'], 'The flat dotted key must not reach the database.' );
		$this->assertSame( '#0f5fa0', $node['attrs']['module']['decoration']['background']['desktop']['value']['color'] );
	}

	/* -------- the set-page ability -------- */

	private function run_set_page( $post_id, $nodes ) {
		return wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $post_id,
				'nodes'   => $nodes,
			)
		);
	}

	public function test_set_page_builds_an_empty_page_end_to_end() {
		$post_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_content' => '' )
		);

		$result = $this->run_set_page( $post_id, $this->hero_nodes() );

		$this->assertNotWPError( $result );
		$this->assertSame( 7, $result['modules'], 'placeholder + section + row + column + heading + text + button.' );
	}

	public function test_set_page_marks_builder_meta_and_content_reparses_valid() {
		$post_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_content' => '' )
		);

		$result = $this->run_set_page( $post_id, $this->hero_nodes() );
		$this->assertNotWPError( $result );

		$this->assertSame( 'on', get_post_meta( $post_id, '_et_pb_use_builder', true ) );
		$this->assertSame( 'on', get_post_meta( $post_id, '_et_pb_use_divi_5', true ) );

		// The saved content must survive the DB round trip and re-validate.
		$saved = get_post( $post_id );
		$tree  = Saddle_Divi_Tree::parse( $saved->post_content );
		$this->assertTrue( Saddle_Divi_Tree::validate( $tree ) );

		$heading = Saddle_Divi_Tree::get( $tree, '0.0.0.0.0' );
		$this->assertSame( 'Welcome to Saddle', $heading['attrs']['title']['innerContent']['desktop']['value'] );

		// Old layout recoverable: a revision exists.
		$this->assertNotEmpty( wp_get_post_revisions( $post_id ) );

		// And the mutation is in the activity log.
		$entries = Saddle_Log::query( 5, 1 )['entries'];
		$actions = wp_list_pluck( $entries, 'action' );
		$this->assertContains( 'divi-set-page', $actions );
	}

	public function test_set_page_rebuilds_an_existing_divi5_page() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->',
			)
		);

		$result = $this->run_set_page( $post_id, $this->hero_nodes() );
		$this->assertNotWPError( $result );
	}

	public function test_set_page_refuses_divi4_and_nonempty_other_content() {
		$d4 = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_content' => '[et_pb_section fb_built="1"][/et_pb_section]' )
		);
		$result = $this->run_set_page( $d4, $this->hero_nodes() );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_divi5', $result->get_error_code() );

		$other = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_content' => '<p>Hand-written page.</p>' )
		);
		$result = $this->run_set_page( $other, $this->hero_nodes() );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_has_content', $result->get_error_code() );
	}

	public function test_set_page_rejects_invalid_structure_and_saves_nothing() {
		$post_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_content' => '' )
		);

		// A row at the section level is invalid: column inside section.
		$result = $this->run_set_page(
			$post_id,
			array(
				array(
					'type'     => 'divi/section',
					'children' => array( array( 'type' => 'divi/column' ) ),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_invalid_structure', $result->get_error_code() );
		$this->assertSame( '', get_post( $post_id )->post_content, 'Nothing partial may be saved.' );
	}

	public function test_set_page_preserves_publish_status_and_reports_state() {
		$post_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' )
		);

		$result = $this->run_set_page(
			$post_id,
			array(
				array(
					'type'     => 'divi/section',
					'children' => array(
						array(
							'type'     => 'divi/row',
							'children' => array(
								array(
									'type'     => 'divi/column',
									'children' => array( array( 'type' => 'divi/text', 'fields' => array( 'content' => 'Hello' ) ) ),
								),
							),
						),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'set-page must never change a published page back to draft.' );
		$this->assertSame( 'publish', $result['status'], 'The response must report the real status.' );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertNull( $result['note'], 'No publish warning for an already-published page.' );
	}

	public function test_set_page_is_denied_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$ability = wp_get_ability( 'saddle/divi-set-page' );
		$this->assertFalse(
			$ability->check_permissions( array( 'post_id' => 1, 'nodes' => array() ) ),
			'A write ability must be denied at the read tier.'
		);
	}
}
