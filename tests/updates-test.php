<?php
/**
 * Update and health ability tests.
 *
 * Drives the real wp_get_ability()->execute() path and the real
 * Saddle_Update_Runner, with core's own Plugin_Upgrader doing the file work
 * on a throwaway plugin in WP_PLUGIN_DIR. Outbound HTTP is refused for the
 * whole test so the fake update offer survives wp_update_plugins(), the
 * package comes from a local zip through `upgrader_pre_download`, and the
 * loopback fatal check fails the way it does on a site the updater cannot
 * reach, which is what makes the rollback test deterministic.
 *
 * What this pins, in order: the admin tier; the list-updates contract; that a
 * preview schedules nothing and a confirm never runs the upgrader inside the
 * request (the hard line); single-use tokens; the cap and the no-offer
 * refusal; the refusal before any preview on a site that forbids file
 * changes; a real applied update; a real rollback of an active plugin; the
 * auto-update switch; Site Health.
 *
 * @package Saddle
 */

class Saddle_Updates_Test extends WP_UnitTestCase {

	private $admin;
	private $slug = 'saddle-dummy-up';
	private $file = 'saddle-dummy-up/saddle-dummy-up.php';
	private $path;
	private $zip;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'admin' );

		// Nothing leaves the test: update checks fail (the fake offer stays),
		// cron spawning is a no-op, and the loopback fatal check fails.
		add_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10, 3 );
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		// WordPress's own test bootstrap disables background updates
		// (`automatic_updater_disabled` → true), and the runner rightly honours
		// that. Lift it here so the real updater can run against the dummy.
		add_filter( 'automatic_updater_disabled', '__return_false', 20 );

		$this->path = WP_PLUGIN_DIR . '/' . $this->slug;
		$this->write_dummy( '1.0.0' );
		$this->offer( '1.1.0' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10 );
		remove_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		remove_filter( 'automatic_updater_disabled', '__return_false', 20 );
		remove_all_filters( 'upgrader_pre_download' );
		remove_all_filters( 'file_mod_allowed' );

		if ( is_plugin_active( $this->file ) ) {
			deactivate_plugins( $this->file, true );
		}
		// A test that dies inside core's updater would leave the test site in
		// maintenance mode, which blocks every later WordPress load there.
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			unlink( ABSPATH . '.maintenance' );
		}
		$this->remove_dir( $this->path );
		$this->remove_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $this->slug );
		if ( $this->zip && file_exists( $this->zip ) ) {
			unlink( $this->zip );
		}
		wp_cache_delete( 'plugins', 'plugins' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		delete_site_option( 'auto_update_plugins' );
		wp_clear_scheduled_hook( Saddle_Update_Runner::HOOK );
		delete_option( Saddle_Update_Runner::OPTION );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		parent::tear_down();
	}

	/* -------- fixtures -------- */

	public function refuse_http( $pre, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		return new WP_Error( 'saddle_test_offline', 'No outbound HTTP in tests: ' . $url );
	}

	private function write_dummy( $version ) {
		wp_mkdir_p( $this->path );
		file_put_contents(
			$this->path . '/saddle-dummy-up.php',
			"<?php\n/**\n * Plugin Name: Saddle Dummy Up\n * Version: {$version}\n */\n"
		);
		wp_cache_delete( 'plugins', 'plugins' );
	}

	private function offer( $new_version ) {
		$checked = array();
		foreach ( get_plugins() as $file => $data ) {
			$checked[ $file ] = $data['Version'];
		}
		$transient = (object) array(
			'last_checked' => time(),
			'checked'      => $checked,
			'response'     => array(
				$this->file => (object) array(
					'id'          => 'w.org/plugins/' . $this->slug,
					'slug'        => $this->slug,
					'plugin'      => $this->file,
					'new_version' => $new_version,
					'url'         => 'https://wordpress.org/plugins/' . $this->slug . '/',
					'package'     => 'https://downloads.wordpress.org/plugin/' . $this->slug . '.' . $new_version . '.zip',
					'tested'      => '7.1',
				),
			),
			'no_update'    => array(),
		);
		set_site_transient( 'update_plugins', $transient );

		// A fresh, complete theme check too, so wp_update_themes() returns
		// early instead of asking wordpress.org (refused here, and the harness
		// turns core's failure notice into an exception).
		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[ $stylesheet ] = (string) $theme->get( 'Version' );
		}
		set_site_transient(
			'update_themes',
			(object) array(
				'last_checked' => time(),
				'checked'      => $themes,
				'response'     => array(),
				'no_update'    => array(),
			)
		);
	}

	/** A zip of the dummy plugin at $version, served in place of the download. */
	private function serve_package( $version ) {
		$this->zip = get_temp_dir() . 'saddle-dummy-up-' . $version . '-' . wp_generate_password( 6, false ) . '.zip';
		$zip       = new ZipArchive();
		$zip->open( $this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( $this->slug . '/saddle-dummy-up.php', "<?php\n/**\n * Plugin Name: Saddle Dummy Up\n * Version: {$version}\n */\n" );
		$zip->close();
		$path = $this->zip;
		add_filter(
			'upgrader_pre_download',
			static function () use ( $path ) {
				return $path;
			},
			10,
			0
		);
	}

	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$p = $dir . '/' . $entry;
			is_dir( $p ) ? $this->remove_dir( $p ) : unlink( $p );
		}
		rmdir( $dir );
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	private function installed_version() {
		wp_cache_delete( 'plugins', 'plugins' );
		$all = get_plugins();
		return isset( $all[ $this->file ] ) ? $all[ $this->file ]['Version'] : null;
	}

	private function log_entries( $action ) {
		return get_posts(
			array(
				'post_type'      => 'saddle_log',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'meta_key'       => '_saddle_action', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $action, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	private function preview() {
		$preview = $this->ability( 'saddle/update-plugin' )->execute( array( 'plugins' => array( $this->slug ) ) );
		$this->assertNotWPError( $preview );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertNotEmpty( $preview['confirm_token'] );
		return $preview;
	}

	private function confirm( $token ) {
		return $this->ability( 'saddle/update-plugin' )->execute(
			array(
				'plugins'       => array( $this->slug ),
				'confirm_token' => $token,
			)
		);
	}

	/* -------- tier -------- */

	public function test_update_abilities_require_admin_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		foreach ( array( 'saddle/list-updates', 'saddle/update-plugin', 'saddle/update-theme', 'saddle/set-auto-update', 'saddle/get-site-health' ) as $name ) {
			$this->assertWPError( $this->ability( $name )->execute( array() ), "{$name} must be refused below admin." );
		}
	}

	/* -------- list-updates contract -------- */

	public function test_list_updates_reports_the_offer() {
		$result = $this->ability( 'saddle/list-updates' )->execute( array() );
		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		foreach ( array( 'plugins', 'themes', 'core', 'translations', 'blocked_by', 'runs', 'checked' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		$mine = array_values( array_filter( $result['plugins'], fn( $p ) => $p['id'] === $this->file ) );
		$this->assertCount( 1, $mine, 'The offered plugin must be listed once.' );
		$this->assertSame( '1.0.0', $mine[0]['from'] );
		$this->assertSame( '1.1.0', $mine[0]['to'] );
		$this->assertFalse( $mine[0]['active'] );
		$this->assertFalse( $mine[0]['auto_update'] );
		$this->assertStringEndsWith( '#developers', $mine[0]['changelog_url'] );
		$this->assertSame( array(), $result['blocked_by'] );
		$this->assertSame( get_bloginfo( 'version' ), $result['core']['version'] );
	}

	/* -------- the gate, and the hard line -------- */

	public function test_preview_schedules_nothing_and_changes_nothing() {
		$preview = $this->preview();
		$this->assertSame( '1.0.0', $preview['preview']['items'][0]['from'] );
		$this->assertSame( '1.1.0', $preview['preview']['items'][0]['to'] );
		$this->assertTrue( $preview['preview']['runs_in_background'] );
		$this->assertFalse( wp_next_scheduled( Saddle_Update_Runner::HOOK ), 'A preview must not queue a run.' );
		$this->assertSame( array(), Saddle_Update_Runner::runs(), 'A preview must not record a run.' );
		$this->assertSame( '1.0.0', $this->installed_version() );
	}

	public function test_confirm_queues_a_run_and_never_runs_the_upgrader_in_the_request() {
		$preview = $this->preview();
		$done    = $this->confirm( $preview['confirm_token'] );

		$this->assertNotWPError( $done );
		$this->assertTrue( $done['queued'] );
		$this->assertNotEmpty( $done['run_id'] );
		$this->assertNotFalse( wp_next_scheduled( Saddle_Update_Runner::HOOK, array( $done['run_id'] ) ), 'The run must be scheduled.' );

		$run = Saddle_Update_Runner::get_run( $done['run_id'] );
		$this->assertSame( 'queued', $run['status'] );
		$this->assertSame( $this->file, $run['items'][0]['id'] );

		// The hard line: the request that confirmed did not touch a file.
		$this->assertSame( '1.0.0', $this->installed_version(), 'Confirming must not run the upgrader synchronously.' );
		$this->assertNotEmpty( $this->log_entries( 'update-plugin' ), 'Queueing is logged.' );
	}

	public function test_confirm_token_is_single_use() {
		$preview = $this->preview();
		$this->assertNotWPError( $this->confirm( $preview['confirm_token'] ) );
		$this->assertWPError( $this->confirm( $preview['confirm_token'] ), 'A used token must be refused.' );
	}

	public function test_a_token_previewed_for_one_version_cannot_apply_another() {
		$preview = $this->preview();
		$this->offer( '1.2.0' ); // A newer offer arrived in between.
		$this->assertWPError( $this->confirm( $preview['confirm_token'] ), 'The token binds the exact versions previewed.' );
	}

	/* -------- refusals -------- */

	public function test_more_than_ten_items_is_refused_never_truncated() {
		$files = array_keys( get_plugins() );
		if ( count( $files ) < 11 ) {
			$this->markTestSkipped( 'Needs at least 11 installed plugins.' );
		}
		$result = $this->ability( 'saddle/update-plugin' )->execute( array( 'plugins' => array_slice( $files, 0, 11 ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_too_many_items', $result->get_error_code() );
	}

	public function test_empty_request_is_refused() {
		$result = $this->ability( 'saddle/update-plugin' )->execute( array( 'plugins' => array() ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_missing_items', $result->get_error_code() );
	}

	public function test_item_without_an_offer_is_refused() {
		$other = array_values( array_diff( array_keys( get_plugins() ), array( $this->file ) ) );
		$this->assertNotEmpty( $other );
		$result = $this->ability( 'saddle/update-plugin' )->execute( array( 'plugins' => array( $other[0] ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_no_update_offered', $result->get_error_code() );
	}

	public function test_a_site_that_forbids_file_changes_is_refused_before_any_preview() {
		add_filter( 'file_mod_allowed', '__return_false' );

		$result = $this->ability( 'saddle/update-plugin' )->execute( array( 'plugins' => array( $this->slug ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_updates_unavailable', $result->get_error_code() );
		$this->assertStringContainsString( 'DISALLOW_FILE_MODS', $result->get_error_message() );

		$list = $this->ability( 'saddle/list-updates' )->execute( array() );
		$this->assertNotEmpty( $list['blocked_by'] );
	}

	public function test_update_theme_refuses_unknown_and_unoffered_themes() {
		$result = $this->ability( 'saddle/update-theme' )->execute( array( 'themes' => array( 'no-such-theme' ) ) );
		$this->assertSame( 'saddle_theme_not_found', $result->get_error_code() );

		$result = $this->ability( 'saddle/update-theme' )->execute( array( 'themes' => array( get_stylesheet() ) ) );
		$this->assertSame( 'saddle_no_update_offered', $result->get_error_code() );
	}

	/* -------- the runner, with core's upgrader -------- */

	public function test_runner_applies_an_offered_update_through_core() {
		$this->serve_package( '1.1.0' );
		$done = $this->confirm( $this->preview()['confirm_token'] );

		Saddle_Update_Runner::run( $done['run_id'] );

		$run = Saddle_Update_Runner::get_run( $done['run_id'] );
		$this->assertSame( 'finished', $run['status'] );
		$this->assertSame( 'applied', $run['items'][0]['status'], $run['items'][0]['message'] );
		$this->assertSame( '1.1.0', $this->installed_version(), 'Core applied the offered version.' );
		$this->assertNotEmpty( $this->log_entries( 'update-plugin' ) );
		$this->assertFalse( file_exists( ABSPATH . '.maintenance' ), 'Maintenance mode is off again.' );
	}

	public function test_runner_rolls_back_an_active_plugin_whose_loopback_fails() {
		activate_plugin( $this->file );
		$this->assertTrue( is_plugin_active( $this->file ) );
		$this->serve_package( '1.1.0' );
		$done = $this->confirm( $this->preview()['confirm_token'] );

		// The loopback is refused (see refuse_http), which core treats as a
		// fatal, so it restores the temporary backup: the 6.6 rollback path.
		Saddle_Update_Runner::run( $done['run_id'] );

		$run = Saddle_Update_Runner::get_run( $done['run_id'] );
		$this->assertSame( 'finished', $run['status'] );
		$this->assertSame( 'rolled_back', $run['items'][0]['status'], $run['items'][0]['message'] );
		$this->assertSame( '1.0.0', $this->installed_version(), 'The previous version is back.' );
		$this->assertFalse( file_exists( ABSPATH . '.maintenance' ) );
	}

	public function test_runner_ignores_a_run_that_is_not_queued() {
		Saddle_Update_Runner::run( 'no-such-run' );
		$this->assertSame( array(), Saddle_Update_Runner::runs() );
	}

	/* -------- the auto-update switch -------- */

	public function test_set_auto_update_previews_then_applies_and_reverses() {
		$args    = array( 'type' => 'plugin', 'item' => $this->slug, 'enabled' => true );
		$preview = $this->ability( 'saddle/set-auto-update' )->execute( $args );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertNotContains( $this->file, (array) get_site_option( 'auto_update_plugins', array() ) );

		$done = $this->ability( 'saddle/set-auto-update' )->execute( $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertNotWPError( $done );
		$this->assertContains( $this->file, (array) get_site_option( 'auto_update_plugins', array() ) );

		$off     = array( 'type' => 'plugin', 'item' => $this->file, 'enabled' => false );
		$preview = $this->ability( 'saddle/set-auto-update' )->execute( $off );
		$this->ability( 'saddle/set-auto-update' )->execute( $off + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertNotContains( $this->file, (array) get_site_option( 'auto_update_plugins', array() ) );
	}

	/* -------- site health -------- */

	public function test_get_site_health_returns_every_direct_check_with_a_status() {
		$result = $this->ability( 'saddle/get-site-health' )->execute( array() );
		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertNotEmpty( $result['tests'] );
		foreach ( $result['tests'] as $test ) {
			$this->assertArrayHasKey( 'status', $test );
			$this->assertNotSame( '', $test['label'] );
		}
		$this->assertIsArray( $result['not_run'] );
	}
}
