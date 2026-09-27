<?php
/**
 * The builder memory: the context bundle + its cache discipline.
 *
 * Pins saddle-pro#12: one call replaces the session-start discovery burst,
 * a compact summary rides the auto-served context (zero calls to start
 * oriented), the cache signature self-corrects on plugin churn, the module
 * index is no longer blind to pack (de)activation, and everything degrades
 * honestly when the Divi design runtime is absent.
 *
 * @package Saddle
 */

class Saddle_Divi_Context_Bundle_Test extends WP_UnitTestCase {

	private $admin;
	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$fixture_dir = get_temp_dir() . 'saddle-pro-bundle-fixture-modules';
		$module_dir        = self::$fixture_dir . '/plain-box';
		if ( ! is_dir( $module_dir ) ) {
			mkdir( $module_dir, 0755, true );
		}
		file_put_contents(
			$module_dir . '/module.json',
			wp_json_encode(
				array(
					'name'       => 'saddletest/plain-box',
					'title'      => 'Plain Box',
					'category'   => 'module',
					'attributes' => array( 'module' => array( 'type' => 'object' ) ),
				)
			)
		);
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		add_filter( 'saddle_divi_active', '__return_true' );

		$dir = self::$fixture_dir;
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		Saddle_Divi_Bundle::flush();
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		Saddle_Divi_Bundle::flush();
		parent::tear_down();
	}

	/* -------- the one-call bundle -------- */

	public function test_bundle_ability_returns_catalog_conventions_and_version() {
		$result = wp_get_ability( 'saddle/divi-context-bundle' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $result['version'] );
		$this->assertNotEmpty( $result['conventions'] );
		$this->assertSame( 'saddletest/plain-box', $result['modules'][0]['type'] );
		$this->assertArrayHasKey( 'brand', $result );

		// No Divi design runtime in the harness: the bundle says so honestly
		// instead of serving empty palettes as truth.
		$this->assertArrayNotHasKey( 'design_system', $result );
		$this->assertArrayHasKey( 'note', $result );
	}

	public function test_bundle_is_cached_and_signature_self_corrects_on_plugin_churn() {
		$first = Saddle_Divi_Bundle::get();
		$again = Saddle_Divi_Bundle::get();
		$this->assertSame( $first['version'], $again['version'], 'Repeat reads serve the cache.' );

		// Plugin churn changes the signature — the stale cache is bypassed
		// even though no flush hook ran.
		$plugins = (array) get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( $plugins, array( 'imaginary-pack/imaginary-pack.php' ) ) );
		$after = Saddle_Divi_Bundle::get();
		update_option( 'active_plugins', $plugins );

		$this->assertNotSame( $first['version'], $after['version'], 'The signature covers the active plugin set.' );
	}

	public function test_lifecycle_hooks_flush_the_bundle() {
		Saddle_Divi_Bundle::get();
		$this->assertNotFalse( get_transient( Saddle_Divi_Bundle::TRANSIENT ) );

		do_action( 'activated_plugin', 'some-pack/some-pack.php' );
		$this->assertFalse( get_transient( Saddle_Divi_Bundle::TRANSIENT ), 'Activating any plugin drops the cached bundle.' );
	}

	/* -------- the fonts slice (#214) -------- */

	/**
	 * Regression: design_system() read `$fonts['fonts']`, a key
	 * get_global_fonts() never returns, so the bundle's fonts slice was
	 * always empty and "global fonts: …" never reached the summary. The
	 * harness has no Divi runtime, so this feeds fonts_slice() the exact shape
	 * Saddle_Divi_Design::get_global_fonts() returns on a live site.
	 */
	public function test_fonts_slice_carries_heading_and_body_fonts() {
		$live = array(
			'heading_font' => 'Playfair Display',
			'body_font'    => 'Inter',
		);

		$this->assertSame( $live, Saddle_Divi_Bundle::fonts_slice( $live ) );
	}

	public function test_fonts_slice_is_empty_when_divi_is_unavailable() {
		$this->assertSame( array(), Saddle_Divi_Bundle::fonts_slice( Saddle_Divi_Design::get_global_fonts() ), 'No Divi runtime in the harness: the tool errors and the slice stays empty.' );
		$this->assertSame(
			array( 'body_font' => 'Inter' ),
			Saddle_Divi_Bundle::fonts_slice(
				array(
					'heading_font' => '',
					'body_font'    => 'Inter',
				)
			),
			'An unset font is left out, not served as an empty string.'
		);
	}

	public function test_fonts_slice_leaves_out_divis_none() {
		// Found live on divi-dev (Divi 5, 2026-09-27): a site whose owner never
		// chose fonts returns "none" for both, which the summary then printed
		// as "global fonts: none/none".
		$this->assertSame(
			array(),
			Saddle_Divi_Bundle::fonts_slice(
				array(
					'heading_font' => 'none',
					'body_font'    => 'None',
				)
			)
		);
	}

	/* -------- the index cache-key fix -------- */

	public function test_module_index_cache_is_keyed_on_the_active_plugin_set() {
		Saddle_Divi_Schema::index( true );
		$cached = get_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );

		$this->assertIsArray( $cached );
		$this->assertArrayHasKey( 'p', $cached, 'The index transient carries the active-plugins hash.' );
		$this->assertSame(
			md5( implode( ',', (array) get_option( 'active_plugins', array() ) ) ),
			$cached['p'],
			'…and it matches the current plugin set, so pack (de)activation busts the cache.'
		);
	}

	/* -------- the auto-served summary -------- */

	public function test_summary_rides_the_system_context() {
		$context = apply_filters( 'saddle_system_context', '', 'write' );

		$this->assertStringContainsString( 'Site design memory', $context );
		$this->assertStringContainsString( 'divi-context-bundle', $context, 'The summary points at the full bundle.' );
	}

	public function test_summary_stays_inside_its_budget() {
		$lines = Saddle_Divi_Bundle::summary_lines();
		$this->assertNotEmpty( $lines );
		$this->assertLessThanOrEqual( Saddle_Divi_Bundle::SUMMARY_BUDGET, strlen( $lines[0] ) );
	}

	/* -------- the skill teaches the loop -------- */

	public function test_divi_build_page_skill_teaches_the_closed_loop() {
		$skills = apply_filters( 'saddle_builtin_skills', array() );
		$bodies = wp_list_pluck( $skills, 'body', 'name' );
		$this->assertArrayHasKey( 'divi-build-page', $bodies );

		$body = $bodies['divi-build-page'];
		// The loop, by concrete tool name, in workflow order.
		foreach ( array( 'divi-context-bundle', 'verify-page', 'render-node', 'get-preview-url' ) as $tool ) {
			$this->assertStringContainsString( $tool, $body, "The skill must teach {$tool}." );
		}
		// Every tool the skill names ships in this plugin.
		foreach ( array( 'commit-brief', 'get-brief', 'compose-page', 'clone-page', 'swap-image', 'bulk-apply-preset', 'undo-batch', 'create-global', 'create-library-item', 'set-theme-builder-conditions' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $body, "The skill must not name {$absent}." );
		}
		$this->assertStringContainsString( 'CLOSED-LOOP', $body );
		$this->assertStringContainsString( '`changed`', $body, 'The skill must teach that writes return their changed nodes.' );
	}
}
