<?php
/**
 * The dynamic content source list: page and loop families, the context each
 * resolves in, and the settings each takes.
 *
 * The built-in list and the registry mapping were read from Divi 5.10.1's
 * DynamicContentOption* classes and LoopUtils; Divi is not loaded here, so
 * the registry path is driven through from_options() with a registry-shaped
 * array.
 *
 * @package Saddle
 */

class Saddle_Divi_Dynamic_Sources_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'read' );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_wc_active' );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	private function listing() {
		return wp_get_ability( 'saddle/divi-list-dynamic-sources' )->execute( array() );
	}

	private static function names( array $rows ) {
		return wp_list_pluck( $rows, 'name' );
	}

	public function test_page_sources_cover_post_author_site_term_and_custom_fields() {
		add_filter( 'saddle_wc_active', '__return_false' );
		$out = $this->listing();

		$this->assertSame( 'builtin', $out['from'] );
		foreach ( array( 'post_title', 'post_excerpt', 'post_link_url', 'any_post_link_url', 'post_featured_image', 'post_featured_image_alt_text', 'post_link_url_page' ) as $name ) {
			$this->assertContains( $name, self::names( $out['page']['post'] ), $name );
		}
		foreach ( array( 'post_author', 'post_author_bio', 'post_author_url', 'post_author_profile_picture' ) as $name ) {
			$this->assertContains( $name, self::names( $out['page']['author'] ), $name );
		}
		foreach ( array( 'site_title', 'site_tagline', 'site_logo', 'home_url', 'current_date' ) as $name ) {
			$this->assertContains( $name, self::names( $out['page']['site'] ), $name );
		}
		$this->assertContains( 'term_description', self::names( $out['page']['term'] ) );
		$this->assertContains( 'post_meta_key', self::names( $out['page']['custom_fields'] ) );
		$this->assertArrayNotHasKey( 'product', $out['page'], 'Product sources need WooCommerce.' );
	}

	public function test_loop_families_say_which_loop_they_print_and_take_loop_settings() {
		$out = $this->listing();

		$expect = array(
			'post' => array( 'loop_post_title', 'loop_post_featured_image', 'loop_post_link', 'loop_post_meta_key_manual_custom_field' ),
			'term' => array( 'loop_term_name', 'loop_term_permalink' ),
			'user' => array( 'loop_user_name', 'loop_user_avatar' ),
			'menu' => array( 'loop_menu_text', 'loop_menu_link' ),
		);
		foreach ( $expect as $family => $names ) {
			$this->assertNotEmpty( $out['loop'][ $family ]['loop_query_types'], $family );
			foreach ( $names as $name ) {
				$this->assertContains( $name, self::names( $out['loop'][ $family ]['sources'] ), $name );
			}
		}
		$this->assertContains( 'post_types', $out['loop']['post']['loop_query_types'] );
		$this->assertContains( 'user_roles', $out['loop']['user']['loop_query_types'] );

		$title = $out['loop']['post']['sources'][ array_search( 'loop_post_title', self::names( $out['loop']['post']['sources'] ), true ) ];
		$this->assertSame( array( 'before', 'after', 'loop_position' ), $title['settings'] );
		$this->assertStringContainsString( 'loop_', $out['note'] );
		$this->assertArrayHasKey( 'loop_position', $out['settings'] );
	}

	public function test_product_sources_appear_with_woocommerce() {
		add_filter( 'saddle_wc_active', '__return_true' );
		$out = $this->listing();

		$this->assertContains( 'product_price', self::names( $out['page']['product'] ) );
		$this->assertContains( 'loop_product_title', self::names( $out['loop']['product']['sources'] ) );
	}

	public function test_divi_registry_options_are_mapped_without_select_lists() {
		$entries = Saddle_Divi_Dynamic_Sources::from_options(
			array(
				'post_title'                             => array(
					'id'     => 'post_title',
					'label'  => 'Page Title',
					'type'   => 'text',
					'group'  => 'Default',
					'fields' => array(
						'before' => array( 'type' => 'text' ),
						'after'  => array( 'type' => 'text' ),
					),
				),
				'loop_post_meta_key_manual_custom_field' => array(
					'label'  => 'Loop Post Custom Field',
					'type'   => 'any',
					'fields' => array(
						'select_loop_meta_key' => array(
							'type'    => 'select',
							'options' => array( 'loop_post_meta_key__edit_lock' => array( 'label' => '_edit_lock' ) ),
						),
						'loop_meta_key'        => array( 'type' => 'text' ),
					),
				),
				'gvid-123'                               => array( 'type' => 'text' ),
				'loop_'                                  => array( 'type' => 'text' ),
			)
		);

		$this->assertSame( array( 'post_title', 'loop_post_meta_key_manual_custom_field' ), array_keys( $entries ) );
		$this->assertSame( array( 'before', 'after' ), $entries['post_title']['settings'] );
		$this->assertSame( 'Page Title', $entries['post_title']['label'] );
		$this->assertStringNotContainsString( '_edit_lock', wp_json_encode( $entries ), 'The site\'s meta keys are not listed.' );
	}

	public function test_list_is_readable_at_the_read_tier() {
		$this->assertTrue( wp_get_ability( 'saddle/divi-list-dynamic-sources' )->check_permissions( array() ) );
	}
}
