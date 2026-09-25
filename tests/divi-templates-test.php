<?php
/**
 * Divi Library + Theme Builder abilities.
 *
 * Library list/apply/delete operate on the et_pb_layout CPT and the tree
 * engine, so they're exercised end to end here (a library item is a real post).
 * create-library-item and the Theme Builder writers defer to Divi's own
 * runtime functions, absent in this harness — those assert registration/tier
 * and the graceful "Divi unavailable" path; the write round-trip is a divi-dev
 * done-gate.
 *
 * @package Saddle
 */

class Saddle_Divi_Templates_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'admin' );
		add_filter( 'saddle_divi_active', '__return_true' );

		// Divi registers these CPTs on a live site; register them here so the
		// per-object delete_post meta-cap checks map cleanly (matching production).
		foreach ( array( 'et_pb_layout', 'et_template' ) as $cpt ) {
			if ( ! post_type_exists( $cpt ) ) {
				register_post_type( $cpt, array( 'public' => false, 'map_meta_cap' => true, 'capability_type' => 'post' ) );
			}
		}

		// Divi registers the scope taxonomy (global | not_global) on a live
		// site; update-library-item re-scopes through it.
		if ( ! taxonomy_exists( 'scope' ) ) {
			register_taxonomy( 'scope', 'et_pb_layout', array( 'public' => false ) );
		}
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		parent::tear_down();
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	private function library_item() {
		return self::factory()->post->create(
			array(
				'post_type'    => 'et_pb_layout',
				'post_status'  => 'publish',
				'post_title'   => 'Saved Hero',
				'post_content' => "<!-- wp:divi/section -->\n<!-- wp:divi/row -->\n<!-- wp:divi/column -->\n<!-- wp:divi/text --><!-- /wp:divi/text -->\n<!-- /wp:divi/column -->\n<!-- /wp:divi/row -->\n<!-- /wp:divi/section -->",
			)
		);
	}

	private function divi_page() {
		return self::factory()->post->create(
			array(
				'post_content' => "<!-- wp:divi/section -->\n<!-- wp:divi/row -->\n<!-- wp:divi/column -->\n<!-- wp:divi/text --><!-- /wp:divi/text -->\n<!-- /wp:divi/column -->\n<!-- /wp:divi/row -->\n<!-- /wp:divi/section -->",
			)
		);
	}

	/* -------- registration + tiers -------- */

	public function test_template_read_abilities_register_read_only() {
		$reads = array( 'saddle/divi-list-library-items', 'saddle/divi-list-theme-builder-templates', 'saddle/divi-list-theme-builder-conditions' );

		foreach ( $reads as $name ) {
			$this->assertSame( 'read', $this->ability( $name )->get_meta()['saddle']['tier'] );
		}
	}

	/* -------- library (harness-testable) -------- */

	public function test_list_library_items_returns_the_cpt() {
		$id = $this->library_item();
		$out = $this->ability( 'saddle/divi-list-library-items' )->execute( array() );
		$this->assertContains( $id, wp_list_pluck( $out['items'], 'id' ) );
	}

	/* -------- theme builder (Divi-runtime) -------- */

	public function test_theme_builder_reads_report_unavailable_without_divi() {
		$this->assertSame( 'saddle_divi_api_unavailable', $this->ability( 'saddle/divi-list-theme-builder-templates' )->execute( array() )->get_error_code() );
		$this->assertSame( 'saddle_divi_api_unavailable', $this->ability( 'saddle/divi-list-theme-builder-conditions' )->execute( array() )->get_error_code() );
	}

	/* -------- create-library-item response parsing (#56) -------- */

	/* -------- update-library-item -------- */

}
