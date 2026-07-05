<?php
/**
 * Site-management ability tests — the WP-CLI-equivalent surface.
 *
 * Drives the real wp_get_ability()->execute() path so admin-tier enforcement,
 * the option allowlist/blocklist, the update-option approval gate, and the
 * plugin/theme dispatch are proven end to end. Uses a throwaway plugin created
 * in WP_PLUGIN_DIR for the activate/deactivate round trip.
 *
 * @package Saddle
 */

class Saddle_Site_Test extends WP_UnitTestCase {

	private $admin;
	private $dummy_file; // Plugin file relative path, e.g. "saddle-dummy/saddle-dummy.php".
	private $dummy_path; // Absolute path to the plugin directory.

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'admin' );

		// A minimal, header-only plugin so activate/deactivate have a real target.
		$this->dummy_path = WP_PLUGIN_DIR . '/saddle-dummy';
		$this->dummy_file = 'saddle-dummy/saddle-dummy.php';
		wp_mkdir_p( $this->dummy_path );
		file_put_contents(
			$this->dummy_path . '/saddle-dummy.php',
			"<?php\n/**\n * Plugin Name: Saddle Dummy\n * Version: 1.0.0\n */\n"
		);
		wp_cache_delete( 'plugins', 'plugins' );
	}

	public function tear_down() {
		if ( is_plugin_active( $this->dummy_file ) ) {
			deactivate_plugins( $this->dummy_file, true );
		}
		if ( file_exists( $this->dummy_path . '/saddle-dummy.php' ) ) {
			unlink( $this->dummy_path . '/saddle-dummy.php' );
		}
		if ( is_dir( $this->dummy_path ) ) {
			rmdir( $this->dummy_path );
		}
		wp_cache_delete( 'plugins', 'plugins' );

		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		parent::tear_down();
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	/* -------- tier enforcement -------- */

	public function test_site_abilities_require_admin_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		$result = $this->ability( 'saddle/list-plugins' )->execute( array() );
		$this->assertWPError( $result, 'Site management must be denied below the admin tier.' );
	}

	public function test_list_plugins_at_admin_tier() {
		$result = $this->ability( 'saddle/list-plugins' )->execute( array() );
		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'plugins', $result );
		$files = wp_list_pluck( $result['plugins'], 'plugin' );
		$this->assertContains( $this->dummy_file, $files, 'The dummy plugin must appear in the listing.' );
	}

	/* -------- plugins: activate / deactivate round trip -------- */

	public function test_activate_then_deactivate_plugin_by_slug() {
		$activated = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'saddle-dummy' ) );
		$this->assertNotWPError( $activated );
		$this->assertTrue( $activated['activated'] );
		$this->assertTrue( is_plugin_active( $this->dummy_file ), 'The plugin must actually be active.' );

		$deactivated = $this->ability( 'saddle/deactivate-plugin' )->execute( array( 'plugin' => $this->dummy_file ) );
		$this->assertNotWPError( $deactivated );
		$this->assertTrue( $deactivated['deactivated'] );
		$this->assertFalse( is_plugin_active( $this->dummy_file ), 'The plugin must actually be inactive.' );
	}

	public function test_activate_unknown_plugin_is_404() {
		$result = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'no-such-plugin' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_plugin_not_found', $result->get_error_code() );
	}

	public function test_saddle_cannot_deactivate_itself() {
		$self   = plugin_basename( SADDLE_FILE );
		$result = $this->ability( 'saddle/deactivate-plugin' )->execute( array( 'plugin' => $self ) );

		// Either way Saddle is refused: if it resolves as an installed plugin
		// (production), the self-guard fires; if the harness doesn't expose it as
		// a plugin file, the resolver 404s first. Both safely decline.
		$this->assertWPError( $result );
		if ( 'saddle_plugin_not_found' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Saddle is not a resolvable installed plugin file in this harness; self-guard unexercisable.' );
		}
		$this->assertSame( 'saddle_self_deactivate', $result->get_error_code() );
	}

	/* -------- themes -------- */

	public function test_activate_unknown_theme_is_404() {
		$result = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => 'no-such-theme' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_theme_not_found', $result->get_error_code() );
	}

	public function test_activate_current_theme_is_noop() {
		$result = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => get_stylesheet() ) );
		$this->assertNotWPError( $result );
		$this->assertFalse( $result['activated'], 'Activating the already-active theme must be a no-op.' );
	}

	/* -------- options: allowlist + blocklist -------- */

	public function test_get_allowlisted_option() {
		update_option( 'blogname', 'Saddle Test Site' );
		$result = $this->ability( 'saddle/get-option' )->execute( array( 'name' => 'blogname' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'Saddle Test Site', $result['value'] );
	}

	public function test_get_blocked_option_is_refused() {
		foreach ( array( 'siteurl', 'home', 'admin_email', 'active_plugins', 'auth_key' ) as $key ) {
			$result = $this->ability( 'saddle/get-option' )->execute( array( 'name' => $key ) );
			$this->assertWPError( $result, "Reading {$key} must be refused." );
			$this->assertSame( 'saddle_option_not_allowed', $result->get_error_code() );
		}
	}

	public function test_blocklist_wins_over_allowlist_filter() {
		// Even if a filter tries to allow a sensitive key, the blocklist rejects it.
		$filter = static function ( $keys ) {
			$keys[] = 'siteurl';
			return $keys;
		};
		add_filter( 'saddle_option_allowlist', $filter );
		$this->assertNotContains( 'siteurl', Saddle_Site_Abilities::allowlist() );
		$result = $this->ability( 'saddle/get-option' )->execute( array( 'name' => 'siteurl' ) );
		remove_filter( 'saddle_option_allowlist', $filter );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_option_not_allowed', $result->get_error_code() );
	}

	/* -------- options: gated update -------- */

	public function test_update_option_previews_then_confirms() {
		update_option( 'blogname', 'Before' );

		$preview = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'blogname', 'value' => 'After' )
		);
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertNotEmpty( $preview['confirm_token'] );
		$this->assertSame( 'Before', get_option( 'blogname' ), 'A preview must not change the option.' );

		$done = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'blogname', 'value' => 'After', 'confirm_token' => $preview['confirm_token'] )
		);
		$this->assertNotWPError( $done );
		$this->assertTrue( $done['updated'] );
		$this->assertSame( 'After', get_option( 'blogname' ) );
	}

	public function test_update_option_confirm_with_changed_value_is_rejected() {
		update_option( 'blogname', 'Before' );

		$preview = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'blogname', 'value' => 'Previewed' )
		);
		// Confirm the token but swap the value — the value is bound to the token.
		$result = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'blogname', 'value' => 'Swapped', 'confirm_token' => $preview['confirm_token'] )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_bind_mismatch', $result->get_error_code() );
		$this->assertSame( 'Before', get_option( 'blogname' ), 'A value-swapped confirm must not write.' );
	}

	public function test_update_blocked_option_is_refused() {
		$result = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'default_role', 'value' => 'administrator' )
		);
		$this->assertWPError( $result, 'Privilege-escalation keys must never be writable.' );
		$this->assertSame( 'saddle_option_not_allowed', $result->get_error_code() );
	}

	/* -------- settings pages -------- */

	public function test_list_options_groups_by_settings_page_and_filters() {
		$all = $this->ability( 'saddle/list-options' )->execute( array() );
		$this->assertNotWPError( $all );
		$names = wp_list_pluck( $all['options'], 'name' );
		// The WP Settings-page coverage the user asked for.
		foreach ( array( 'blogname', 'permalink_structure', 'posts_per_page', 'show_on_front', 'default_post_format' ) as $key ) {
			$this->assertContains( $key, $names, "{$key} must be editable." );
		}
		$this->assertContains( 'reading', $all['pages'] );

		$reading = $this->ability( 'saddle/list-options' )->execute( array( 'page' => 'reading' ) );
		$pages   = array_unique( wp_list_pluck( $reading['options'], 'page' ) );
		$this->assertSame( array( 'reading' ), $pages, 'The page filter must return only that page.' );
	}

	public function test_edit_site_title_and_tagline() {
		foreach ( array( 'blogname' => 'My Great Site', 'blogdescription' => 'Now with AI' ) as $key => $val ) {
			$preview = $this->ability( 'saddle/update-option' )->execute( array( 'name' => $key, 'value' => $val ) );
			$done    = $this->ability( 'saddle/update-option' )->execute(
				array( 'name' => $key, 'value' => $val, 'confirm_token' => $preview['confirm_token'] )
			);
			$this->assertNotWPError( $done );
			$this->assertSame( $val, get_option( $key ) );
		}
	}

	public function test_changing_permalink_structure_flushes_rewrite_rules() {
		global $wp_rewrite;
		update_option( 'permalink_structure', '' );
		$wp_rewrite->init();

		$preview = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'permalink_structure', 'value' => '/%postname%/' )
		);
		$done = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'permalink_structure', 'value' => '/%postname%/', 'confirm_token' => $preview['confirm_token'] )
		);

		$this->assertNotWPError( $done );
		$this->assertTrue( $done['rewrite_flushed'], 'A permalink change must rebuild rewrite rules.' );
		$this->assertSame( '/%postname%/', get_option( 'permalink_structure' ) );
		$this->assertNotEmpty( get_option( 'rewrite_rules' ), 'Rewrite rules must be regenerated so the change takes effect.' );
	}

	public function test_constrained_settings_are_validated() {
		// show_on_front is an enum; a garbage value would break the homepage.
		$bad = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'show_on_front', 'value' => 'nonsense' )
		);
		$this->assertWPError( $bad );
		$this->assertSame( 'saddle_bad_setting_value', $bad->get_error_code() );

		$bad_int = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'posts_per_page', 'value' => 0 )
		);
		$this->assertWPError( $bad_int );

		// A valid enum value (no interdependency) previews fine.
		$ok = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'default_comment_status', 'value' => 'closed' )
		);
		$this->assertTrue( $ok['requires_confirmation'] );
	}

	public function test_front_page_referential_integrity() {
		$draft = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$post  = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$page  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		// A draft page (would expose unpublished content as the homepage) — refused.
		$bad_draft = $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'page_on_front', 'value' => $draft ) );
		$this->assertWPError( $bad_draft );
		$this->assertSame( 'saddle_bad_setting_value', $bad_draft->get_error_code() );

		// A 'post', not a 'page' — refused.
		$this->assertWPError( $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'page_on_front', 'value' => $post ) ) );
		// A non-existent id — refused.
		$this->assertWPError( $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'page_on_front', 'value' => 999999 ) ) );

		// A real published page — accepted (previews).
		$ok = $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'page_on_front', 'value' => $page ) );
		$this->assertTrue( $ok['requires_confirmation'] );
	}

	public function test_static_front_page_interdependency_enforced() {
		update_option( 'page_on_front', 0 );

		// show_on_front=page with no valid front page assigned — refused (would
		// blank the homepage) rather than allowed into a broken state.
		$broken = $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'show_on_front', 'value' => 'page' ) );
		$this->assertWPError( $broken );
		$this->assertSame( 'saddle_setting_dependency', $broken->get_error_code() );

		// Assign a valid page first, then it's allowed.
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'page_on_front', $page );
		$ok = $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'show_on_front', 'value' => 'page' ) );
		$this->assertTrue( $ok['requires_confirmation'], 'With a valid front page assigned, the switch is allowed.' );
	}

	public function test_default_category_and_timezone_validated() {
		$this->assertWPError( $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'default_category', 'value' => 999999 ) ) );
		$this->assertWPError( $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'timezone_string', 'value' => 'Mars/Olympus_Mons' ) ) );

		$ok = $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'timezone_string', 'value' => 'Europe/London' ) );
		$this->assertTrue( $ok['requires_confirmation'] );
	}

	public function test_integer_settings_reject_non_whole_numbers() {
		// "3.5 posts per page" is nonsense; it must be refused, not coerced to 3.
		$result = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'posts_per_page', 'value' => 3.5 )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_setting_value', $result->get_error_code() );

		// A whole number is fine.
		$ok = $this->ability( 'saddle/update-option' )->execute(
			array( 'name' => 'posts_per_page', 'value' => 12 )
		);
		$this->assertTrue( $ok['requires_confirmation'] );
	}

	public function test_update_summary_records_old_and_new_for_audit() {
		update_option( 'blogname', 'Old Name' );
		$preview = $this->ability( 'saddle/update-option' )->execute( array( 'name' => 'blogname', 'value' => 'New Name' ) );
		$this->assertStringContainsString( 'Old Name', $preview['summary'] );
		$this->assertStringContainsString( 'New Name', $preview['summary'] );
	}

	/* -------- maintenance -------- */

	public function test_flush_cache() {
		$result = $this->ability( 'saddle/flush-cache' )->execute( array() );
		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'flushed', $result );
	}
}
