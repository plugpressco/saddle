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
		$this->restore_theme();

		foreach ( array( 'saddle-dummy-addon', 'saddle-dummy' ) as $slug ) {
			$file = $slug . '/' . $slug . '.php';
			if ( is_plugin_active( $file ) ) {
				deactivate_plugins( $file, true );
			}
			if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				unlink( WP_PLUGIN_DIR . '/' . $file );
			}
			if ( is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
				rmdir( WP_PLUGIN_DIR . '/' . $slug );
			}
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

	/* -------- plugins: activate / deactivate, gated (#320) -------- */

	/**
	 * Run a gated ability through preview and confirm, the way an agent does.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Input without the token.
	 * @return array|WP_Error The confirmed call's result.
	 */
	private function confirmed( $name, array $input ) {
		$preview = $this->ability( $name )->execute( $input );
		$this->assertIsArray( $preview );
		$this->assertTrue( $preview['requires_confirmation'], 'Expected a preview first.' );

		return $this->ability( $name )->execute( $input + array( 'confirm_token' => $preview['confirm_token'] ) );
	}

	/**
	 * A second header-only plugin whose "Requires Plugins" names saddle-dummy.
	 *
	 * @return string Its plugin file.
	 */
	private function make_addon() {
		wp_mkdir_p( WP_PLUGIN_DIR . '/saddle-dummy-addon' );
		file_put_contents(
			WP_PLUGIN_DIR . '/saddle-dummy-addon/saddle-dummy-addon.php',
			"<?php\n/**\n * Plugin Name: Saddle Dummy Addon\n * Version: 2.0.0\n * Requires Plugins: saddle-dummy\n */\n"
		);
		wp_cache_delete( 'plugins', 'plugins' );

		return 'saddle-dummy-addon/saddle-dummy-addon.php';
	}

	/** How many executed activity-log entries name this action. */
	private function logged( $action ) {
		$entries = Saddle_Log::query( 100, 1, 'executed' )['entries'];

		return count( wp_list_filter( $entries, array( 'action' => $action ) ) );
	}

	public function test_activate_then_deactivate_plugin_by_slug() {
		$activated = $this->confirmed( 'saddle/activate-plugin', array( 'plugin' => 'saddle-dummy' ) );
		$this->assertNotWPError( $activated );
		$this->assertSame(
			array(
				'activated' => true,
				'plugin'    => $this->dummy_file,
			),
			$activated,
			'The confirmed call keeps the one-step return shape.'
		);
		$this->assertTrue( is_plugin_active( $this->dummy_file ), 'The plugin must actually be active.' );

		$deactivated = $this->confirmed( 'saddle/deactivate-plugin', array( 'plugin' => $this->dummy_file ) );
		$this->assertNotWPError( $deactivated );
		$this->assertSame(
			array(
				'deactivated' => true,
				'plugin'      => $this->dummy_file,
			),
			$deactivated
		);
		$this->assertFalse( is_plugin_active( $this->dummy_file ), 'The plugin must actually be inactive.' );
	}

	public function test_plugin_tools_are_declared_destructive_admin_tools_with_a_token() {
		foreach ( array( 'saddle/activate-plugin', 'saddle/deactivate-plugin' ) as $name ) {
			$ability = $this->ability( $name );
			$meta    = $ability->get_meta();
			$this->assertTrue( $meta['annotations']['destructive'], "{$name} must be declared destructive." );
			$this->assertSame( 'admin', $meta['saddle']['tier'], "{$name} tier" );
			$this->assertArrayHasKey( 'confirm_token', $ability->get_input_schema()['properties'], "{$name} must accept the token." );
			$this->assertStringContainsString( 'confirm_token', $ability->get_description(), "{$name} must tell the agent about the two steps." );
		}
	}

	public function test_activate_plugin_without_a_token_previews_and_changes_nothing() {
		$before  = $this->logged( 'activate-plugin' );
		$preview = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'saddle-dummy' ) );

		$this->assertNotWPError( $preview );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertNotEmpty( $preview['confirm_token'] );
		$this->assertSame( 'activate-plugin', $preview['action'] );
		$this->assertSame( $this->dummy_file, $preview['preview']['plugin'] );
		$this->assertSame( 'Saddle Dummy', $preview['preview']['plugin_name'] );
		$this->assertSame( '1.0.0', $preview['preview']['version'] );
		$this->assertStringContainsString( 'Activate the plugin Saddle Dummy 1.0.0.', $preview['summary'] );

		$this->assertFalse( is_plugin_active( $this->dummy_file ), 'A preview must not activate anything.' );
		$this->assertSame( $before, $this->logged( 'activate-plugin' ), 'A preview is not a change and is not logged as one.' );
	}

	public function test_activate_plugin_token_runs_once_and_a_reused_token_is_refused() {
		$preview = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'saddle-dummy' ) );
		$input   = array(
			'plugin'        => 'saddle-dummy',
			'confirm_token' => $preview['confirm_token'],
		);

		$before = $this->logged( 'activate-plugin' );
		$done   = $this->ability( 'saddle/activate-plugin' )->execute( $input );
		$this->assertNotWPError( $done );
		$this->assertTrue( $done['activated'] );
		$this->assertTrue( is_plugin_active( $this->dummy_file ) );
		$this->assertSame( $before + 1, $this->logged( 'activate-plugin' ), 'The confirmed activation is logged exactly once.' );

		// The plugin is active now, so a no-op answer would also look harmless;
		// the used token must still be refused as used.
		$again = $this->ability( 'saddle/activate-plugin' )->execute( $input );
		$this->assertWPError( $again );
		$this->assertSame( 'saddle_invalid_token', $again->get_error_code() );
		$this->assertSame( $before + 1, $this->logged( 'activate-plugin' ), 'A refused token logs nothing.' );
	}

	public function test_deactivate_plugin_without_a_token_previews_and_changes_nothing() {
		activate_plugin( $this->dummy_file );

		$preview = $this->ability( 'saddle/deactivate-plugin' )->execute( array( 'plugin' => $this->dummy_file ) );

		$this->assertNotWPError( $preview );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertSame( 'deactivate-plugin', $preview['action'] );
		$this->assertSame( 'Saddle Dummy', $preview['preview']['plugin_name'] );
		$this->assertArrayNotHasKey( 'required_by', $preview['preview'], 'Nothing active requires it.' );
		$this->assertStringContainsString( 'Deactivate the plugin Saddle Dummy 1.0.0.', $preview['summary'] );
		$this->assertTrue( is_plugin_active( $this->dummy_file ), 'A preview must not deactivate anything.' );
	}

	public function test_deactivate_plugin_token_runs_once_and_a_reused_token_is_refused() {
		activate_plugin( $this->dummy_file );
		$preview = $this->ability( 'saddle/deactivate-plugin' )->execute( array( 'plugin' => $this->dummy_file ) );
		$input   = array(
			'plugin'        => $this->dummy_file,
			'confirm_token' => $preview['confirm_token'],
		);

		$done = $this->ability( 'saddle/deactivate-plugin' )->execute( $input );
		$this->assertNotWPError( $done );
		$this->assertTrue( $done['deactivated'] );
		$this->assertFalse( is_plugin_active( $this->dummy_file ) );

		$again = $this->ability( 'saddle/deactivate-plugin' )->execute( $input );
		$this->assertWPError( $again );
		$this->assertSame( 'saddle_invalid_token', $again->get_error_code() );
	}

	public function test_deactivate_preview_names_the_active_plugins_that_require_it() {
		$addon = $this->make_addon();
		update_option( 'active_plugins', array( $this->dummy_file, $addon ) );

		$preview = $this->ability( 'saddle/deactivate-plugin' )->execute( array( 'plugin' => 'saddle-dummy' ) );

		$this->assertSame( array( 'Saddle Dummy Addon' ), $preview['preview']['required_by'] );
		$this->assertStringContainsString( 'may stop working: Saddle Dummy Addon.', $preview['summary'] );
		$this->assertTrue( is_plugin_active( $this->dummy_file ) );
	}

	public function test_an_already_active_plugin_answers_at_once_without_a_token() {
		activate_plugin( $this->dummy_file );
		$pending = count( Saddle_Approval::pending() );

		$result = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'saddle-dummy' ) );

		$this->assertFalse( $result['activated'] );
		$this->assertArrayNotHasKey( 'confirm_token', $result, 'Nothing to change, so nothing to confirm.' );
		$this->assertCount( $pending, Saddle_Approval::pending(), 'No request waits on the owner for a no-op.' );
	}

	public function test_the_token_is_bound_to_the_plugin() {
		$addon   = $this->make_addon();
		$preview = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'saddle-dummy' ) );

		$swapped = $this->ability( 'saddle/activate-plugin' )->execute(
			array(
				'plugin'        => $addon,
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$this->assertWPError( $swapped );
		$this->assertSame( 'saddle_token_target_mismatch', $swapped->get_error_code() );
		$this->assertFalse( is_plugin_active( $addon ) );
		$this->assertFalse( is_plugin_active( $this->dummy_file ) );
	}

	public function test_the_token_is_bound_to_what_the_preview_showed() {
		$preview = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'saddle-dummy' ) );

		// The plugin changes under the preview: the owner was shown 1.0.0.
		file_put_contents(
			$this->dummy_path . '/saddle-dummy.php',
			"<?php\n/**\n * Plugin Name: Saddle Dummy\n * Version: 1.0.1\n */\n"
		);
		wp_cache_delete( 'plugins', 'plugins' );

		$result = $this->ability( 'saddle/activate-plugin' )->execute(
			array(
				'plugin'        => 'saddle-dummy',
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_bind_mismatch', $result->get_error_code() );
		$this->assertFalse( is_plugin_active( $this->dummy_file ) );
	}

	public function test_plugin_tools_are_refused_below_the_admin_tier() {
		activate_plugin( $this->dummy_file );
		Saddle_Capabilities::set_tier( 'write' );
		$pending = count( Saddle_Approval::pending() );

		$off = $this->ability( 'saddle/deactivate-plugin' )->execute( array( 'plugin' => $this->dummy_file ) );
		$this->assertWPError( $off );
		$this->assertSame( 'ability_invalid_permissions', $off->get_error_code() );
		$this->assertTrue( is_plugin_active( $this->dummy_file ) );

		deactivate_plugins( $this->dummy_file, true );
		$on = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => $this->dummy_file ) );
		$this->assertWPError( $on );
		$this->assertFalse( is_plugin_active( $this->dummy_file ) );

		$this->assertCount( $pending, Saddle_Approval::pending(), 'A refused call issues no token.' );
		$reason = Saddle_Capabilities::denial_reason( 'saddle/activate-plugin' );
		$this->assertSame( 'saddle_tier_denied', $reason['code'] );
	}

	public function test_a_plugin_preview_waits_under_needs_your_ok() {
		$this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'saddle-dummy' ) );

		$rows = wp_list_filter( Saddle_Approval::pending(), array( 'tool' => 'activate-plugin' ) );
		$this->assertCount( 1, $rows, 'Every gated preview is listed for the owner, with no list to join.' );
		$row = reset( $rows );
		$this->assertSame( $this->dummy_file, $row['target'] );
		$this->assertStringContainsString( 'Saddle Dummy 1.0.0', $row['summary'] );
	}

	public function test_a_confirmed_activation_can_be_undone() {
		$this->confirmed( 'saddle/activate-plugin', array( 'plugin' => 'saddle-dummy' ) );
		$this->assertTrue( is_plugin_active( $this->dummy_file ) );

		$changes = $this->ability( 'saddle/recall-changes' )->execute( array( 'limit' => 1 ) );
		$entry   = $changes['changes'][0];
		$this->assertSame( 'activate-plugin', $entry['action'] );
		$this->assertSame( 'available', $entry['undo'], 'The gate\'s log entry must carry the journal.' );

		$undone = $this->confirmed( 'saddle/undo-changes', array( 'entries' => array( $entry['id'] ) ) );
		$this->assertNotWPError( $undone );
		$this->assertSame( 1, $undone['undone'] );
		$this->assertFalse( is_plugin_active( $this->dummy_file ), 'Undo puts the plugin back to inactive.' );
	}

	public function test_activate_unknown_plugin_is_404() {
		$result = $this->ability( 'saddle/activate-plugin' )->execute( array( 'plugin' => 'no-such-plugin' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_plugin_not_found', $result->get_error_code() );
	}

	public function test_saddle_cannot_deactivate_itself() {
		$self = plugin_basename( SADDLE_FILE );

		// The harness loads Saddle from outside the plugins folder, so make the
		// resolver see it the way a real install does: as an installed plugin.
		$plugins          = Saddle_Context::get_plugins_quietly();
		$plugins[ $self ] = array(
			'Name'            => 'Saddle',
			'Version'         => SADDLE_VERSION,
			'RequiresPlugins' => '',
		);
		wp_cache_set( 'plugins', array( '' => $plugins ), 'plugins' );
		$pending = count( Saddle_Approval::pending() );

		$result = $this->ability( 'saddle/deactivate-plugin' )->execute( array( 'plugin' => $self ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_self_deactivate', $result->get_error_code() );

		// Refused before any preview: no token, nothing waiting on the owner,
		// and a token in the call changes nothing.
		$this->assertCount( $pending, Saddle_Approval::pending() );
		$forged = $this->ability( 'saddle/deactivate-plugin' )->execute(
			array(
				'plugin'        => $self,
				'confirm_token' => str_repeat( 'a', 32 ),
			)
		);
		$this->assertSame( 'saddle_self_deactivate', $forged->get_error_code() );
	}

	/* -------- themes -------- */

	public function test_activate_unknown_theme_is_404() {
		$result = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => 'no-such-theme' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_theme_not_found', $result->get_error_code() );
	}

	public function test_activate_current_theme_is_noop() {
		$pending = count( Saddle_Approval::pending() );

		$result = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => get_stylesheet() ) );
		$this->assertNotWPError( $result );
		$this->assertFalse( $result['activated'], 'Activating the already-active theme must be a no-op.' );
		$this->assertArrayNotHasKey( 'confirm_token', $result, 'Nothing to change, so nothing to confirm.' );
		$this->assertCount( $pending, Saddle_Approval::pending(), 'No request waits on the owner for a no-op.' );
	}

	/* -------- themes: activate, gated (#322) -------- */

	/** The theme active before a test switched it. */
	private $previous_theme = '';

	/**
	 * Make the fixture themes installable, so there is a second theme to
	 * switch to whatever the host site has.
	 *
	 * @return string The fixture's stylesheet.
	 */
	private function other_theme() {
		$this->previous_theme = get_stylesheet();
		register_theme_directory( __DIR__ . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		wp_clean_themes_cache();
		$this->assertTrue( wp_get_theme( 'saddle-classic-fixture' )->exists(), 'The fixture theme must be installed.' );

		return 'saddle-classic-fixture';
	}

	/** Put the host's theme back. */
	private function restore_theme() {
		if ( '' !== $this->previous_theme && get_stylesheet() !== $this->previous_theme ) {
			switch_theme( $this->previous_theme );
		}
	}

	public function test_activate_theme_is_a_destructive_admin_tool_with_a_token() {
		$ability = $this->ability( 'saddle/activate-theme' );
		$meta    = $ability->get_meta();

		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertSame( 'admin', $meta['saddle']['tier'] );
		$this->assertArrayHasKey( 'confirm_token', $ability->get_input_schema()['properties'] );
		$this->assertStringContainsString( 'confirm_token', $ability->get_description() );
	}

	/** Regression: activate-theme switched the whole site's theme in one call. */
	public function test_activate_theme_without_a_token_previews_and_changes_nothing() {
		$other   = $this->other_theme();
		$before  = $this->logged( 'activate-theme' );
		$current = wp_get_theme();

		$preview = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => $other ) );

		$this->assertNotWPError( $preview );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertNotEmpty( $preview['confirm_token'] );
		$this->assertSame( 'activate-theme', $preview['action'] );
		$this->assertSame( $other, $preview['preview']['stylesheet'] );
		$this->assertSame( 'Saddle Classic Fixture', $preview['preview']['theme_name'] );
		$this->assertSame( '1.0.0', $preview['preview']['version'] );
		$this->assertSame( trim( $current->get( 'Name' ) . ' ' . $current->get( 'Version' ) ), $preview['preview']['current_theme'] );
		$this->assertStringContainsString( 'to Saddle Classic Fixture 1.0.0. Every page on the site changes how it looks.', $preview['summary'] );

		$this->assertSame( $this->previous_theme, get_stylesheet(), 'A preview must not switch the theme.' );
		$this->assertSame( $before, $this->logged( 'activate-theme' ), 'A preview is not logged as a change.' );
	}

	public function test_activate_theme_token_runs_once_and_a_reused_token_is_refused() {
		$other   = $this->other_theme();
		$preview = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => $other ) );
		$input   = array(
			'stylesheet'    => $other,
			'confirm_token' => $preview['confirm_token'],
		);
		$before  = $this->logged( 'activate-theme' );

		$done = $this->ability( 'saddle/activate-theme' )->execute( $input );
		$this->assertNotWPError( $done );
		$this->assertSame(
			array(
				'activated'  => true,
				'stylesheet' => $other,
				'name'       => 'Saddle Classic Fixture',
			),
			$done,
			'The confirmed call keeps the one-step return shape.'
		);
		$this->assertSame( $other, get_stylesheet() );
		$this->assertSame( $before + 1, $this->logged( 'activate-theme' ), 'The switch is logged exactly once.' );
		$this->assertStringStartsWith( 'Switched the active theme from ', Saddle_Log::query( 1, 1 )['entries'][0]['summary'] );

		$again = $this->ability( 'saddle/activate-theme' )->execute( $input );
		$this->assertWPError( $again );
		$this->assertSame( 'saddle_invalid_token', $again->get_error_code() );
		$this->assertSame( $before + 1, $this->logged( 'activate-theme' ) );
	}

	public function test_the_theme_token_is_bound_to_the_theme() {
		$other   = $this->other_theme();
		$preview = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => $other ) );

		$swapped = $this->ability( 'saddle/activate-theme' )->execute(
			array(
				'stylesheet'    => 'saddle-block-fixture',
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$this->assertWPError( $swapped );
		$this->assertSame( 'saddle_token_target_mismatch', $swapped->get_error_code() );
		$this->assertSame( $this->previous_theme, get_stylesheet() );
	}

	public function test_the_theme_token_is_bound_to_the_theme_active_at_preview() {
		$other   = $this->other_theme();
		$preview = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => $other ) );

		// Someone switches theme between the preview and the confirm: the
		// owner was shown "from" a theme that is no longer active.
		switch_theme( 'saddle-block-fixture' );
		$result = $this->ability( 'saddle/activate-theme' )->execute(
			array(
				'stylesheet'    => $other,
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_bind_mismatch', $result->get_error_code() );
		$this->assertSame( 'saddle-block-fixture', get_stylesheet() );
	}

	public function test_activate_theme_is_refused_below_the_admin_tier() {
		$other = $this->other_theme();
		Saddle_Capabilities::set_tier( 'write' );
		$pending = count( Saddle_Approval::pending() );

		$result = $this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => $other ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( $this->previous_theme, get_stylesheet() );
		$this->assertCount( $pending, Saddle_Approval::pending(), 'A refused call issues no token.' );
	}

	public function test_a_theme_preview_waits_under_needs_your_ok() {
		$other = $this->other_theme();
		$this->ability( 'saddle/activate-theme' )->execute( array( 'stylesheet' => $other ) );

		$rows = wp_list_filter( Saddle_Approval::pending(), array( 'tool' => 'activate-theme' ) );
		$this->assertCount( 1, $rows );
		$row = reset( $rows );
		$this->assertSame( $other, $row['target'] );
		$this->assertStringContainsString( 'Saddle Classic Fixture 1.0.0', $row['summary'] );
	}

	public function test_a_confirmed_theme_switch_can_be_undone() {
		$other = $this->other_theme();
		$this->confirmed( 'saddle/activate-theme', array( 'stylesheet' => $other ) );
		$this->assertSame( $other, get_stylesheet() );

		$entry = $this->ability( 'saddle/recall-changes' )->execute( array( 'limit' => 1 ) )['changes'][0];
		$this->assertSame( 'activate-theme', $entry['action'] );
		$this->assertSame( 'available', $entry['undo'], 'The gate\'s log entry must carry the journal.' );

		$undone = $this->confirmed( 'saddle/undo-changes', array( 'entries' => array( $entry['id'] ) ) );
		$this->assertNotWPError( $undone );
		$this->assertSame( 1, $undone['undone'] );
		$this->assertSame( $this->previous_theme, get_stylesheet(), 'Undo puts the previous theme back.' );
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
