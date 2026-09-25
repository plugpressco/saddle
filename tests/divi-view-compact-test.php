<?php
/**
 * Context discipline: compact/diffed reads + changed-node writes.
 *
 * Pins saddle-pro#11: the agent's context is for reasoning, not for
 * warehousing attrs — a default page read is a skeleton measurably smaller
 * than the full tree, an unchanged page short-circuits on its version, a
 * focused subtree carries full attrs, and every write hands back exactly
 * what it touched so the follow-up re-read disappears.
 *
 * @package Saddle
 */

class Saddle_Divi_View_Compact_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'read' );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function styled_page() {
		$decoration = array(
			'module' => array(
				'decoration' => array(
					'background' => array( 'desktop' => array( 'value' => array( 'color' => '#0a2540' ) ) ),
					'spacing'    => array( 'desktop' => array( 'value' => array( 'padding' => array( 'top' => '96px', 'bottom' => '96px' ) ) ) ),
					'font'       => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => '#ffffff', 'size' => '18px' ) ) ) ),
				),
			),
		);
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section ' . wp_json_encode( $decoration ) . ' -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:divi/text ' . wp_json_encode( $decoration ) . ' --><p>Copy A.</p><!-- /wp:divi/text -->',
						'<!-- wp:divi/text ' . wp_json_encode( $decoration ) . ' --><p>Copy B.</p><!-- /wp:divi/text -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);
	}

	private function get_page( array $input ) {
		return wp_get_ability( 'saddle/divi-get-page' )->execute( $input );
	}

	/* -------- compact vs full -------- */

	public function test_compact_default_strips_attrs_and_is_measurably_smaller() {
		$id = $this->styled_page();

		$compact = $this->get_page( array( 'post_id' => $id ) );
		$full    = $this->get_page(
			array(
				'post_id' => $id,
				'mode'    => 'full',
			)
		);

		$this->assertSame( 'compact', $compact['mode'] );
		$this->assertArrayNotHasKey( 'attrs', $compact['nodes'][0] );
		$this->assertTrue( $compact['nodes'][0]['styled'], 'The styled flag says attrs exist without shipping them.' );
		$this->assertArrayHasKey( 'attrs', $full['nodes'][0] );

		$compact_size = strlen( (string) wp_json_encode( $compact['nodes'] ) );
		$full_size    = strlen( (string) wp_json_encode( $full['nodes'] ) );
		$this->assertLessThan( $full_size / 2, $compact_size, 'The skeleton must be at most half the full payload on a styled page.' );
	}

	public function test_address_focus_returns_the_subtree_with_full_attrs() {
		$id     = $this->styled_page();
		$result = $this->get_page(
			array(
				'post_id' => $id,
				'address' => '0.0.0.0',
			)
		);

		$this->assertCount( 1, $result['nodes'] );
		$this->assertSame( '0.0.0.0', $result['nodes'][0]['address'] );
		$this->assertArrayHasKey( 'attrs', $result['nodes'][0], 'A focused subtree carries full attrs even in compact mode.' );
	}

	public function test_depth_caps_the_walk() {
		$id     = $this->styled_page();
		$result = $this->get_page(
			array(
				'post_id' => $id,
				'depth'   => 2,
			)
		);

		$this->assertSame( array( '0', '0.0' ), wp_list_pluck( $result['nodes'], 'address' ) );
	}

	/* -------- diffed reads -------- */

	public function test_matching_version_short_circuits_and_changes_invalidate_it() {
		$id    = $this->styled_page();
		$first = $this->get_page( array( 'post_id' => $id ) );
		$this->assertNotEmpty( $first['version'] );

		$again = $this->get_page(
			array(
				'post_id' => $id,
				'version' => $first['version'],
			)
		);
		$this->assertTrue( $again['unchanged'] );
		$this->assertArrayNotHasKey( 'nodes', $again, 'An unchanged page is one line, not a tree.' );

		// A real edit invalidates the stamp.
		wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $id,
				'address' => '0.0.0.0',
				'fields'  => array( 'content' => 'Rewritten.' ),
			)
		);
		$after = $this->get_page(
			array(
				'post_id' => $id,
				'version' => $first['version'],
			)
		);
		$this->assertArrayNotHasKey( 'unchanged', $after );
		$this->assertNotSame( $first['version'], $after['version'] );
	}

	/* -------- writes hand back what they touched -------- */

	public function test_edit_returns_the_changed_node_and_fresh_version() {
		$id     = $this->styled_page();
		$result = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $id,
				'address' => '0.0.0.0',
				'fields'  => array( 'content' => 'Rewritten copy.' ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $result['version'] );
		$this->assertSame( '0.0.0.0', $result['changed'][0]['address'] );
		$this->assertSame( 'divi/text', $result['changed'][0]['type'] );
		// Content fields land in the attr envelope; the changed node carries
		// the full persisted attrs so no follow-up read is needed.
		$this->assertStringContainsString( 'Rewritten copy.', (string) wp_json_encode( $result['changed'][0]['attrs'] ) );

		// The returned version matches a fresh read's — the loop stays coherent.
		$read = $this->get_page( array( 'post_id' => $id ) );
		$this->assertSame( $read['version'], $result['version'] );
	}

	public function test_compact_text_reads_content_from_attr_envelopes() {
		// Real Divi 5 blocks carry EMPTY innerHTML — content lives in attrs.
		// The old text column was blank on every real page, so an agent
		// could not cheaply re-read what a page says (the rebuild incentive).
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Pricing that scales"}}},"builderVersion":"5.9.0"} --><!-- /wp:divi/heading -->',
						'<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Start Free Trial","linkUrl":"/trial"}}}},"builderVersion":"5.9.0"} --><!-- /wp:divi/button -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);

		$read  = $this->get_page( array( 'post_id' => $id ) );
		$texts = array_column( $read['nodes'], 'text', 'address' );

		$this->assertSame( 'Pricing that scales', $texts['0.0.0.0'] );
		$this->assertSame( 'Start Free Trial', $texts['0.0.0.1'], 'Composite content (button text) surfaces too.' );
	}

	public function test_compact_styled_flag_means_real_styling() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						// Content + builderVersion only: NOT styled.
						'<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"Plain copy"}}},"builderVersion":"5.9.0"} --><!-- /wp:divi/text -->',
						// Real decoration: styled.
						'<!-- wp:divi/text {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0a2540"}}}}},"builderVersion":"5.9.0"} --><!-- /wp:divi/text -->',
						// Preset binding: styled.
						'<!-- wp:divi/text {"modulePreset":["preset-abc"],"builderVersion":"5.9.0"} --><!-- /wp:divi/text -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);

		$read   = $this->get_page( array( 'post_id' => $id ) );
		$styled = array_column( $read['nodes'], 'styled', 'address' );

		$this->assertFalse( $styled['0.0.0.0'], 'builderVersion + content alone is not styling — the old flag was always true and carried no signal.' );
		$this->assertTrue( $styled['0.0.0.1'] );
		$this->assertTrue( $styled['0.0.0.2'], 'A preset binding counts as styling.' );
	}

	public function test_set_page_returns_the_compact_address_map() {
		$id     = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );
		$result = wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array(
							array(
								'type'     => 'divi/row',
								'children' => array(
									array(
										'type'     => 'divi/column',
										'children' => array(
											array( 'type' => 'divi/text', 'fields' => array( 'content' => 'Hello' ) ),
										),
									),
								),
							),
						),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		// The compact skeleton of the persisted tree rides the response —
		// the agent has the full address map without a follow-up read.
		$this->assertArrayHasKey( 'nodes', $result );
		$types = array_column( $result['nodes'], 'type', 'address' );
		$this->assertSame( 'divi/placeholder', $types['0'] );
		$this->assertSame( 'divi/section', $types['0.0'] );
		$this->assertSame( 'divi/text', $types['0.0.0.0.0'] );
		$this->assertArrayNotHasKey( 'attrs', $result['nodes'][0], 'The skeleton stays compact — no attr payloads.' );
	}

	public function test_add_returns_the_added_node() {
		$id     = $this->styled_page();
		$result = wp_get_ability( 'saddle/divi-add-module' )->execute(
			array(
				'post_id'        => $id,
				'parent_address' => '0.0.0',
				'node'           => array(
					'type'   => 'divi/text',
					'fields' => array( 'content' => 'Fresh module.' ),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $result['added'], $result['changed'][0]['address'] );
		$this->assertStringContainsString( 'Fresh module.', (string) wp_json_encode( $result['changed'][0] ) );
	}
}
