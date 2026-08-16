<?php
/**
 * The context bundle and the built-in playbook.
 *
 * What must hold: one call returns everything a session needs to orient; the
 * cache self-corrects when the site changes rather than serving a stale answer
 * for twelve hours; and the payload stays bounded, because the whole point is
 * to cost less context than the five calls it replaces, not more.
 *
 * @package Saddle
 */

class Saddle_Context_Bundle_Test extends WP_UnitTestCase {

	private $admin;

	private $previous_theme;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'read' );

		$this->previous_theme = get_stylesheet();
		register_theme_directory( __DIR__ . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		wp_clean_themes_cache();
		if ( wp_get_theme( 'saddle-block-fixture' )->exists() ) {
			switch_theme( 'saddle-block-fixture' );
		}

		Saddle_Context_Bundle::flush();
	}

	public function tear_down() {
		Saddle_Context_Bundle::flush();
		if ( $this->previous_theme && get_stylesheet() !== $this->previous_theme ) {
			switch_theme( $this->previous_theme );
		}
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	public function test_one_call_returns_everything_a_session_needs() {
		$bundle = $this->run_ability( 'context-bundle' );
		$this->assertNotWPError( $bundle );

		foreach ( array( 'design_system', 'blocks', 'patterns', 'site', 'brand', 'recipes', 'conventions', 'version' ) as $key ) {
			$this->assertArrayHasKey( $key, $bundle, "The bundle must carry {$key}." );
		}

		// The five calls it replaces must each be answerable from it.
		$this->assertArrayHasKey( 'colors', $bundle['design_system'] );
		$this->assertNotEmpty( $bundle['blocks']['common'] );
		$this->assertArrayHasKey( 'theme', $bundle['patterns'] );
		$this->assertNotEmpty( $bundle['recipes'] );
		$this->assertTrue( $bundle['site']['block_theme'] );
		$this->assertNotEmpty( $bundle['site']['parts'], 'A block theme must report its template parts.' );
	}

	public function test_the_payload_stays_bounded() {
		$bundle = $this->run_ability( 'context-bundle' );

		// The catalog is unbounded on a real site (WooCommerce alone adds
		// dozens of block types), so the bundle must ship the curated set and
		// a count, never the whole registry.
		$this->assertLessThanOrEqual(
			20,
			count( $bundle['blocks']['common'] ),
			'The bundle must not embed the full block catalog.'
		);
		$this->assertArrayHasKey( 'total', $bundle['blocks'] );
		$this->assertGreaterThanOrEqual( count( $bundle['blocks']['common'] ), $bundle['blocks']['total'] );

		// Each common entry is a summary, not a schema.
		$this->assertSame(
			array( 'name', 'title', 'authoring' ),
			array_keys( $bundle['blocks']['common'][0] )
		);

		// Patterns are unbounded too. This assertion exists because the first
		// version of this test only checked the blocks slice and passed, while
		// the real payload on Twenty Twenty-Five was 28KB: that theme ships 109
		// patterns and every one of them was being embedded.
		$this->assertArrayHasKey( 'theme_total', $bundle['patterns'] );
		$this->assertLessThanOrEqual( 12, count( $bundle['patterns']['theme'] ) );

		// The real guard: the WHOLE payload, not one slice of it. The bundle
		// has to cost less context than the five calls it replaces.
		$this->assertLessThan(
			12000,
			strlen( wp_json_encode( $bundle ) ),
			'The bundle must stay small enough to be worth calling once per session.'
		);
	}

	public function test_the_cache_self_corrects_when_the_site_changes() {
		$first = Saddle_Context_Bundle::get();
		$this->assertSame( $first, Saddle_Context_Bundle::get(), 'A second read must come from cache unchanged.' );

		// Change the owner's global styles the way the Site Editor would, with
		// no Saddle hook firing. The signature must notice anyway.
		$record  = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$post_id = (int) $record['ID'];
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_json_encode(
					array(
						'version'  => 3,
						'settings' => array(
							'color' => array(
								'palette' => array(
									'custom' => array(
										array(
											'slug'  => 'signature-probe',
											'name'  => 'Probe',
											'color' => '#123456',
										),
									),
								),
							),
						),
					)
				),
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();

		$second = Saddle_Context_Bundle::get();
		$this->assertNotSame(
			$first['version'],
			$second['version'],
			'An out-of-band design change must invalidate the bundle without waiting for the TTL.'
		);
	}

	public function test_flush_empties_the_cache() {
		Saddle_Context_Bundle::get();
		$this->assertNotFalse( get_transient( 'saddle_context_bundle' ) );

		Saddle_Context_Bundle::flush();
		$this->assertFalse( get_transient( 'saddle_context_bundle' ) );
	}

	public function test_the_bundle_is_filterable_so_a_builder_can_swap_it() {
		add_filter(
			'saddle_context_bundle',
			static function ( $bundle ) {
				$bundle['builder_claimed'] = true;
				return $bundle;
			}
		);
		Saddle_Context_Bundle::flush();

		$bundle = Saddle_Context_Bundle::get();
		$this->assertArrayHasKey( 'builder_claimed', $bundle );

		remove_all_filters( 'saddle_context_bundle' );
	}

	/* -------- the built-in playbook -------- */

	public function test_the_build_page_playbook_ships_and_reads_back_whole() {
		$index = $this->run_ability( 'list-skills' );
		$this->assertNotWPError( $index );
		$this->assertContains( 'build-page', wp_list_pluck( $index['skills'], 'name' ) );

		$skill = $this->run_ability( 'get-skill', array( 'name' => 'build-page' ) );
		$this->assertNotWPError( $skill );

		// It must name the tools for each step, or it is prose rather than a
		// workflow.
		foreach ( array( 'saddle/context-bundle', 'saddle/get-template', 'saddle/get-block-schema', 'saddle/verify-page', 'saddle/get-preview-url' ) as $tool ) {
			$this->assertStringContainsString( $tool, $skill['body'], "The playbook must name {$tool}." );
		}

		// The design bar is embedded from its single source, never restated.
		$numbers = Saddle_Context::design_numbers();
		$bullet  = '';
		foreach ( $numbers as $line ) {
			if ( '' !== $line && 0 !== strpos( $line, '#' ) ) {
				$bullet = $line;
				break;
			}
		}
		$this->assertNotSame( '', $bullet );
		$this->assertStringContainsString( $bullet, $skill['body'], 'The design bar must be embedded verbatim so it cannot drift.' );
	}

	public function test_a_bundled_skill_is_marked_so_the_ui_cannot_offer_a_404() {
		$skills = Saddle_Skills::all( false );
		$found  = null;
		foreach ( $skills as $skill ) {
			if ( 'build-page' === $skill['name'] ) {
				$found = $skill;
				break;
			}
		}

		$this->assertNotNull( $found, 'The bundled playbook must appear in the skills list.' );
		$this->assertTrue( $found['builtin'], 'A plugin-bundled skill must be flagged, because set_enabled() and delete() cannot act on it.' );

		// And the server really does refuse, which is why the flag exists.
		$this->assertFalse( Saddle_Skills::set_enabled( 'build-page', false ) );
	}
}
