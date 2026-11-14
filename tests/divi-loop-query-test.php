<?php
/**
 * Loop query tests: the loop value Divi reads, every loop key, the loop id.
 *
 * The shapes asserted here were read from Divi 5.10.1's loop reader
 * (LoopUtils::get_query_args_from_attrs() and its helpers) and from what
 * its Visual Builder writes. No page is rendered in this suite.
 *
 * @package Saddle
 */

class Saddle_Divi_Loop_Query_Test extends WP_UnitTestCase {

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
	 * A page with one blurb in a column; $loop is raw loop attrs on the blurb.
	 *
	 * @param array|null $loop Raw loop value to store, or null for none.
	 * @return int
	 */
	private function page( $loop = null ) {
		$attrs = null === $loop ? '' : ' ' . wp_json_encode( array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => $loop ) ) ) ) ) );
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:divi/blurb' . $attrs . ' --><!-- /wp:divi/blurb -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);
	}

	private function loop( $post_id, $address = '0.0.0.0' ) {
		$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $post_id )->post_content ), $address );
		return Saddle_Divi_Loop::value( $node );
	}

	private function run_ability( $name, array $input ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	/* -------- B1: sub types -------- */

	public function test_latest_posts_loop_names_its_post_type_the_way_divi_reads_it() {
		$id = $this->page();
		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'sub_types' => array( 'post', 'page' ) ) );

		$this->assertSame(
			array(
				array(
					'value' => 'post',
					'label' => get_post_type_object( 'post' )->labels->name,
				),
				array(
					'value' => 'page',
					'label' => get_post_type_object( 'page' )->labels->name,
				),
			),
			$this->loop( $id )['subTypes'],
			'Divi drops plain strings and then queries every post type.'
		);
	}

	public function test_post_loop_without_sub_types_queries_posts_not_every_post_type() {
		$id = $this->page();
		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0' ) );

		$this->assertSame( 'post', $this->loop( $id )['subTypes'][0]['value'] );
		$this->assertSame( 'date', $this->loop( $id )['orderBy'] );
	}

	public function test_unknown_sub_type_is_refused_and_nothing_is_written() {
		$id     = $this->page();
		$result = $this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'sub_types' => array( 'no_such_type' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_sub_type', $result->get_error_code() );
		$this->assertSame( array(), $this->loop( $id ) );
	}

	public function test_menu_loop_takes_a_menu_slug_and_stores_its_id() {
		$menu_id = wp_create_nav_menu( 'Footer links' );
		$id      = $this->page();
		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'menus', 'sub_types' => array( 'footer-links' ) ) );

		$loop = $this->loop( $id );
		$this->assertSame( 'menus', $loop['queryType'] );
		$this->assertSame( array( array( 'value' => (string) $menu_id, 'label' => 'Footer links' ) ), $loop['subTypes'] );
		$this->assertSame( 'menu_order', $loop['orderBy'] );

		$no_menu = $this->run_ability( 'divi-enable-loop', array( 'post_id' => $this->page(), 'address' => '0.0.0.0', 'query_type' => 'menus' ) );
		$this->assertWPError( $no_menu );
	}

	public function test_older_query_type_names_are_stored_as_divi_writes_them() {
		$id = $this->page();
		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'users' ) );
		$this->assertSame( 'user_roles', $this->loop( $id )['queryType'], 'Divi prints nothing per item for a loop stored as "users".' );
		$this->assertSame( 'display_name', $this->loop( $id )['orderBy'] );

		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'terms', 'sub_types' => array( 'post_tag' ) ) );
		$this->assertSame( 'post_taxonomies', $this->loop( $id )['queryType'] );
		$this->assertSame( 'post_tag', $this->loop( $id )['subTypes'][0]['value'] );
	}

	public function test_current_page_loop_follows_the_page_query() {
		$id = $this->page();
		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'current_page' ) );

		$loop = $this->loop( $id );
		$this->assertSame( 'current_page', $loop['queryType'] );
		$this->assertSame( 'relevance', $loop['orderBy'] );
		$this->assertArrayNotHasKey( 'subTypes', $loop );
	}

	public function test_acf_repeater_loop_needs_a_field_and_acf() {
		$id      = $this->page();
		$missing = $this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'acf_repeater' ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'saddle_bad_repeater', $missing->get_error_code() );

		$no_acf = $this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'acf_repeater', 'repeater_field' => 'team' ) );
		$this->assertWPError( $no_acf );
		$this->assertSame( 'saddle_no_acf', $no_acf->get_error_code() );
	}

	/* -------- B2: order -------- */

	public function test_newest_first_is_stored_as_descending() {
		$id = $this->page();
		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0' ) );
		$this->assertSame( 'descending', $this->loop( $id )['order'], 'Divi sorts newest first only for "descending" or empty.' );

		foreach ( array( 'desc' => 'descending', 'asc' => 'ascending', 'ascending' => 'ascending', 'descending' => 'descending' ) as $given => $stored ) {
			$this->run_ability( 'divi-edit-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'order' => $given ) );
			$this->assertSame( $stored, $this->loop( $id )['order'], $given );
		}
	}

	/* -------- old saved form -------- */

	public function test_edit_loop_rewrites_a_loop_saved_in_the_older_form() {
		$id = $this->page(
			array(
				'enable'      => 'on',
				'queryType'   => 'post_types',
				'subTypes'    => array( 'post' ),
				'order'       => 'desc',
				'postPerPage' => 10,
				'loopId'      => 'loop-old123',
			)
		);

		$result = $this->run_ability( 'divi-edit-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'per_page' => 4 ) );
		$this->assertNotWPError( $result );

		$loop = $this->loop( $id );
		$this->assertSame( array( array( 'value' => 'post', 'label' => 'post' ) ), $loop['subTypes'] );
		$this->assertSame( 'descending', $loop['order'] );
		$this->assertSame( 4, $loop['postPerPage'] );
		$this->assertSame( 'loop-old123', $loop['loopId'] );
		$this->assertSame( 'post', $result['loop']['subTypes'][0]['value'], 'The response shows the rewritten loop.' );
	}

	public function test_changing_query_type_on_edit_resets_what_belonged_to_the_old_type() {
		$id = $this->page();
		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'order_by' => 'title' ) );
		$this->run_ability( 'divi-edit-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'post_taxonomies' ) );

		$loop = $this->loop( $id );
		$this->assertSame( 'category', $loop['subTypes'][0]['value'] );
		$this->assertSame( 'name', $loop['orderBy'] );
	}

	/* -------- loop id -------- */

	public function test_enable_loop_creates_a_loop_id_and_every_loop_tool_returns_it() {
		$id      = $this->page();
		$enabled = $this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0' ) );

		$this->assertMatchesRegularExpression( '/^loop-[a-z0-9]+$/', $enabled['loop_id'] );
		$this->assertSame( $enabled['loop_id'], $this->loop( $id )['loopId'] );
		$this->assertStringContainsString( 'divi/post-nav', $enabled['note'] );
		$this->assertStringContainsString( 'module.advanced.targetLoop', $enabled['note'] );

		$edited   = $this->run_ability( 'divi-edit-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'per_page' => 3 ) );
		$disabled = $this->run_ability( 'divi-disable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0' ) );
		$this->assertSame( $enabled['loop_id'], $edited['loop_id'] );
		$this->assertSame( $enabled['loop_id'], $disabled['loop_id'] );
		$this->assertSame( $enabled['loop_id'], $this->loop( $id )['loopId'] );
		$this->assertStringContainsString( 'divi/post-filter', $disabled['note'] );
	}

	public function test_enable_loop_keeps_the_loop_id_a_module_already_has() {
		$id = $this->page( array( 'enable' => 'off', 'loopId' => 'loop-pagination1' ) );
		$this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'per_page' => 3 ) );

		$this->assertSame( 'loop-pagination1', $this->loop( $id )['loopId'], 'A pagination module points at this id; a fresh config must not drop it.' );
	}

	public function test_a_given_loop_id_is_checked() {
		$id = $this->page();
		$ok = $this->run_ability( 'divi-enable-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'loop_id' => 'loop-news' ) );
		$this->assertSame( 'loop-news', $ok['loop_id'] );

		$bad = $this->run_ability( 'divi-edit-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'loop_id' => 'news posts' ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'saddle_bad_loop_id', $bad->get_error_code() );

		$tree = Saddle_Divi_Tree::parse( get_post( $id )->post_content );
		$this->assertSame( array( 'loop-news' ), Saddle_Divi_Loop::loop_ids( $tree ) );
		$taken = Saddle_Divi_Loop::build( array(), array( 'loop_id' => 'loop-news' ), true, $tree, '0.0.0' );
		$this->assertWPError( $taken, 'Two loops on one page cannot share an id.' );
		$this->assertSame( 'saddle_loop_id_taken', $taken->get_error_code() );
	}

	/* -------- filters -------- */

	public function test_enable_loop_writes_every_filter_key_divi_reads() {
		$post = self::factory()->post->create( array( 'post_title' => 'Hello there' ) );
		$skip = self::factory()->post->create( array( 'post_title' => 'Skip me' ) );
		$news = self::factory()->category->create( array( 'name' => 'News', 'slug' => 'news' ) );
		$id   = $this->page();

		$result = $this->run_ability(
			'divi-enable-loop',
			array(
				'post_id'              => $id,
				'address'              => '0.0.0.0',
				'exclude_current_post' => true,
				'ignore_sticky_posts'  => true,
				'include_posts'        => array( $post ),
				'exclude_posts'        => array( $skip ),
				'include_terms'        => array( 'category' => array( 'news' ) ),
				'exclude_terms'        => array( 'category' => array( 1 ) ),
				'meta_query'           => array(
					array(
						'key'     => 'price',
						'value'   => '10',
						'compare' => '>=',
						'type'    => 'numeric',
					),
				),
				'order_by'             => 'id',
			)
		);
		$this->assertNotWPError( $result );

		$loop = $this->loop( $id );
		$this->assertSame( 'on', $loop['excludeCurrentPost'] );
		$this->assertSame( 'on', $loop['ignoreStickysPost'] );
		$this->assertSame( array( array( 'value' => (string) $post, 'label' => 'Hello there' ) ), $loop['includeSpecificPosts'] );
		$this->assertSame( (string) $skip, $loop['excludeSpecificPosts'][0]['value'] );
		$this->assertSame( 'category', $loop['includePostWithSpecificTerms'][0]['categoryId'] );
		$this->assertSame( array( array( 'value' => (string) $news, 'label' => 'News' ) ), $loop['includePostWithSpecificTerms'][0]['selectedOptions'] );
		$this->assertSame( '1', $loop['excludePostWithSpecificTerms'][0]['selectedOptions'][0]['value'] );
		$this->assertSame(
			array(
				array(
					'metaKey'   => 'price',
					'metaValue' => '10',
					'compare'   => '>=',
					'type'      => 'NUMERIC',
				),
			),
			$loop['metaQuery']
		);
		$this->assertSame( 'ID', $loop['orderBy'], 'A known order-by value is stored as Divi lists it.' );
		$this->assertSame( array(), $this->filter_warnings( $result ) );

		$this->run_ability( 'divi-edit-loop', array( 'post_id' => $id, 'address' => '0.0.0.0', 'include_posts' => array() ) );
		$this->assertSame( array(), $this->loop( $id )['includeSpecificPosts'], 'An empty list clears the filter.' );
	}

	public function test_bad_filters_are_refused() {
		$id = $this->page();
		$cases = array(
			array( 'include_posts' => array( 999999 ) ),
			array( 'include_terms' => array( 'no_such_tax' => array( 1 ) ) ),
			array( 'include_terms' => array( 'category' => array( 'no-such-term' ) ) ),
			array( 'meta_query' => array( array( 'key' => 'price', 'value' => '1', 'compare' => 'NEAR' ) ) ),
			array( 'meta_query' => array( array( 'key' => 'price' ) ) ),
		);
		foreach ( $cases as $case ) {
			$result = $this->run_ability( 'divi-enable-loop', array_merge( array( 'post_id' => $id, 'address' => '0.0.0.0' ), $case ) );
			$this->assertWPError( $result, wp_json_encode( $case ) );
		}
		$this->assertSame( array(), $this->loop( $id ) );
	}

	public function test_unknown_order_by_and_ignored_filters_warn() {
		$id     = $this->page();
		$result = $this->run_ability(
			'divi-enable-loop',
			array(
				'post_id'             => $id,
				'address'             => '0.0.0.0',
				'query_type'          => 'user_roles',
				'order_by'            => 'favourite_colour',
				'ignore_sticky_posts' => true,
			)
		);
		$this->assertNotWPError( $result );
		$this->assertCount( 2, $this->filter_warnings( $result ) );
	}

	/**
	 * Warnings about order_by and filters (this suite has no module.json,
	 * so enable-loop also warns that it could not check loop support).
	 *
	 * @param array $result Ability result.
	 * @return string[]
	 */
	private function filter_warnings( array $result ) {
		$warnings = isset( $result['warnings'] ) ? $result['warnings'] : array();
		return array_values(
			array_filter(
				$warnings,
				static function ( $warning ) {
					return false === strpos( $warning, 'module.json' );
				}
			)
		);
	}

	/* -------- reference -------- */

	public function test_loop_reference_lists_types_orders_and_filters() {
		$out = $this->run_ability( 'divi-list-loop-query-types', array() );

		$types = wp_list_pluck( $out['query_types'], 'type' );
		foreach ( array( 'post_types', 'post_taxonomies', 'user_roles', 'menus', 'current_page', 'acf_repeater' ) as $type ) {
			$this->assertContains( $type, $types );
		}
		$post_types = $out['query_types'][ array_search( 'post_types', $types, true ) ];
		$this->assertContains( 'post', $post_types['sub_types'] );
		$this->assertContains( 'date', $post_types['order_by'] );
		$this->assertSame( array( 'desc', 'asc' ), array_keys( $out['order'] ) );
		$this->assertArrayHasKey( 'meta_query', $out['filters'] );
		$this->assertArrayHasKey( 'loop_id', $out );
	}
}
