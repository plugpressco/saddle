<?php
/**
 * saddle/divi-find-module-examples: how this site already uses a module.
 *
 * @package Saddle
 */

class Saddle_Divi_Module_Examples_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function block( $type, array $attrs, $inner = '' ) {
		return sprintf( '<!-- wp:%1$s %2$s -->%3$s<!-- /wp:%1$s -->', $type, wp_json_encode( $attrs ), $inner );
	}

	/**
	 * A Divi page: placeholder > section > row (with a column layout) > column > modules.
	 */
	private function page( array $modules, array $args = array() ) {
		$row = array( 'module' => array( 'advanced' => array( 'flexColumnStructure' => array( 'desktop' => array( 'value' => 'equal-columns_3' ) ) ) ) );
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/section -->'
						. $this->block( 'divi/row', $row, '<!-- wp:divi/column -->' . implode( '', $modules ) . '<!-- /wp:divi/column -->' )
						. '<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
				),
				$args
			)
		);
	}

	private function flip( $title, $back = '#0B0E1F', array $extra = array() ) {
		return $this->block(
			'divitorque/flip-box',
			array_replace_recursive(
				array(
					'builderVersion' => '5.11.0',
					'frontTitle'     => array( 'innerContent' => array( 'desktop' => array( 'value' => $title ) ) ),
					'back'           => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => $back ) ) ) ) ),
					'module'         => array( 'advanced' => array( 'animationType' => array( 'desktop' => array( 'value' => 'flip' ) ) ) ),
				),
				$extra
			)
		);
	}

	private function find( array $input ) {
		return wp_get_ability( 'saddle/divi-find-module-examples' )->execute( $input );
	}

	/* -------- tests -------- */

	public function test_returns_distinct_configured_uses_with_page_address_and_row_layout() {
		$a = $this->page( array( $this->flip( 'One' ), $this->flip( 'Two' ) ) );
		$b = $this->page( array( $this->flip( 'Three', '#F4F1FB', array( 'module' => array( 'advanced' => array( 'duration' => array( 'desktop' => array( 'value' => '750ms' ) ) ) ) ) ) ) );

		$result = $this->find( array( 'type' => 'divitorque/flip-box' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 3, $result['uses'], 'Every use is counted, repeats included.' );
		$this->assertSame( 2, $result['pages'] );
		$this->assertCount( 2, $result['examples'], '"One" and "Two" differ only in text: one example.' );

		$first = $result['examples'][0];
		$this->assertSame( $b, $first['post_id'], 'The most configured use comes first.' );
		$this->assertSame( '0.0.0.0.0', $first['address'] );
		$this->assertSame( 'equal-columns_3', $first['row_columns'] );
		$this->assertSame( 'divitorque/flip-box', $first['node']['type'] );
		$this->assertSame( '750ms', $first['node']['attrs']['module']['advanced']['duration']['desktop']['value'] );
		$this->assertArrayNotHasKey( 'builderVersion', $first['node']['attrs'] );
		$this->assertSame( $a, $result['examples'][1]['post_id'] );
		$this->assertArrayHasKey( 'note', $result );
	}

	public function test_leaves_out_interactions_and_the_html_id() {
		$this->page(
			array(
				$this->flip(
					'Card',
					'#0B0E1F',
					array(
						'module' => array(
							'decoration' => array(
								'interactions'       => array( 'desktop' => array( 'value' => array( 'interactions' => array( array( 'id' => 'x' ) ) ) ) ),
								'interactionTrigger' => 'trigger-1',
							),
							'advanced'   => array(
								'htmlAttributes' => array(
									'desktop' => array(
										'value' => array(
											'id'    => 'hero-card',
											'class' => 'is-dark',
										),
									),
								),
							),
						),
					)
				),
			)
		);

		$node = $this->find( array( 'type' => 'divitorque/flip-box' ) )['examples'][0]['node'];

		$this->assertArrayNotHasKey( 'interactions', $node['attrs']['module']['decoration'] );
		$this->assertArrayNotHasKey( 'interactionTrigger', $node['attrs']['module']['decoration'] );
		$this->assertSame( array( 'class' => 'is-dark' ), $node['attrs']['module']['advanced']['htmlAttributes']['desktop']['value'] );
	}

	public function test_an_example_can_be_built_again_with_set_page() {
		$this->page( array( $this->flip( 'Original', '#0B0E1F', array( 'module' => array( 'advanced' => array( 'duration' => array( 'desktop' => array( 'value' => '750ms' ) ) ) ) ) ) ) );
		$node = $this->find( array( 'type' => 'divitorque/flip-box' ) )['examples'][0]['node'];

		Saddle_Capabilities::set_tier( 'write' );
		$target = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '',
			)
		);
		$built  = wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $target,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array(
							array(
								'type'     => 'divi/row',
								'children' => array(
									array(
										'type'     => 'divi/column',
										'children' => array( $node ),
									),
								),
							),
						),
					),
				),
			)
		);
		$this->assertNotWPError( $built );

		$stored = null;
		foreach ( Saddle_Divi_Tree::flatten( Saddle_Divi_Tree::parse( get_post( $target )->post_content ) ) as $flat ) {
			if ( 'divitorque/flip-box' === $flat['type'] ) {
				$stored = $flat['attrs'];
			}
		}
		$this->assertNotNull( $stored );
		unset( $stored['builderVersion'] );
		$this->assertSame( $node['attrs']['back'], $stored['back'] );
		$this->assertSame( $node['attrs']['frontTitle'], $stored['frontTitle'] );
		$this->assertSame( '750ms', $stored['module']['advanced']['duration']['desktop']['value'] );
	}

	public function test_a_container_keeps_its_children() {
		$item = $this->block( 'divi/accordion-item', array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Q' ) ) ) ) );
		$this->page( array( $this->block( 'divi/accordion', array( 'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'FAQ' ) ) ) ) ), $item . $item ) ) );

		$node = $this->find( array( 'type' => 'divi/accordion' ) )['examples'][0]['node'];

		$this->assertCount( 2, $node['children'] );
		$this->assertSame( 'divi/accordion-item', $node['children'][0]['type'] );
	}

	public function test_only_posts_the_account_may_read_are_used() {
		$this->page( array( $this->flip( 'Draft' ) ), array( 'post_status' => 'draft' ) );
		$this->page( array( $this->flip( 'Locked', '#111111' ) ), array( 'post_password' => 'secret' ) );
		$open = $this->page( array( $this->flip( 'Open', '#222222' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = $this->find( array( 'type' => 'divitorque/flip-box' ) );

		$this->assertSame( array( $open ), wp_list_pluck( $result['examples'], 'post_id' ), 'A draft and a password page never come back to a reader.' );
	}

	public function test_exclude_post_id_and_none_found() {
		$only = $this->page( array( $this->flip( 'Only' ) ) );

		$result = $this->find(
			array(
				'type'            => 'divitorque/flip-box',
				'exclude_post_id' => $only,
			)
		);
		$this->assertSame( array(), $result['examples'] );
		$this->assertSame( 0, $result['uses'] );
		$this->assertStringContainsString( 'divi-get-module-schema', $result['note'] );
	}

	public function test_a_type_must_name_its_namespace() {
		$this->assertWPError( $this->find( array( 'type' => 'flip-box' ) ) );
		$this->assertSame( 'saddle_bad_type', $this->find( array( 'type' => "divi/text' OR 1=1" ) )->get_error_code() );
	}

	public function test_a_similar_type_name_is_not_matched() {
		$this->page( array( $this->block( 'divi/text-x', array( 'module' => array() ) ) ) );
		$this->assertSame( 0, $this->find( array( 'type' => 'divi/text' ) )['uses'] );
	}

	public function test_size_caps_hold() {
		$long  = str_repeat( 'x', 2500 );
		$items = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$items .= $this->block( 'divi/accordion-item', array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => $long ) ) ) ) );
		}
		$this->page( array( $this->block( 'divi/accordion', array( 'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Long FAQ' ) ) ) ) ), $items ) ) );

		$result = $this->find( array( 'type' => 'divi/accordion' ) );
		$node   = $result['examples'][0]['node'];

		$this->assertSame( 10, $node['children_total'] );
		$this->assertLessThanOrEqual( 2, count( $node['children'] ) );
		$this->assertLessThanOrEqual( Saddle_Divi_Module_Examples::EXAMPLE_BYTES, strlen( wp_json_encode( $result['examples'][0] ) ) );
		$this->assertArrayHasKey( 'cut', $result );
	}

	public function test_divi_must_be_active_and_the_tool_needs_read_tier_only() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		$this->assertSame( 'saddle_no_divi', $this->find( array( 'type' => 'divi/blurb' ) )->get_error_code() );
		add_filter( 'saddle_divi_active', '__return_true' );

		Saddle_Capabilities::set_tier( 'read' );
		$this->assertTrue( wp_get_ability( 'saddle/divi-find-module-examples' )->check_permissions( array( 'type' => 'divi/blurb' ) ) );
	}
}
