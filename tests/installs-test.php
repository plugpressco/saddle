<?php
/**
 * Directory and install ability tests.
 *
 * Drives the real wp_get_ability()->execute() path, core's real plugins_api()
 * and, for installs, the real Saddle_Update_Runner handing a package to core's
 * own Plugin_Upgrader. Nothing leaves the test: `pre_http_request` answers the
 * WordPress.org directory from answers recorded from the live API on
 * 2026-10-12 (`tests/fixtures/wporg/`), and refuses every other host. Those
 * recordings are what pin the directory's field names: if WordPress.org or
 * core renames one, re-record and this file goes red before a customer sees
 * an empty column. The install package is a local zip served through
 * `upgrader_pre_download`, the way the update tests serve theirs.
 *
 * What the install half pins: the admin tier; that a preview changes nothing
 * and a confirm never runs the installer inside the request (the hard line);
 * single-use tokens bound to the version shown; every refusal before a token;
 * a real install that arrives inactive; and the run-time re-check.
 *
 * @package Saddle
 */

class Saddle_Installs_Test extends WP_UnitTestCase {

	private $admin;

	/** Every directory URL plugins_api() asked for. */
	private $requests = array();

	/** plugin_information answers by slug; anything else is "not found". */
	private $info = array();

	/** Plugin folders a test created, removed in tear_down. */
	private $dirs = array();

	/** Calls into core's upgrader, counted by watch_upgrader(). */
	private $upgrader_calls = 0;

	/** The zip a test served as the package. */
	private $zip;

	const SLUG = 'saddle-dummy-install';

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'admin' );

		$this->info = array( 'classic-editor' => $this->fixture( 'plugin-information' ) );
		$this->dummy( '1.0.0' );
		$this->dirs[] = WP_PLUGIN_DIR . '/' . self::SLUG;
		add_filter( 'pre_http_request', array( $this, 'serve_directory' ), 10, 3 );
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		// WordPress's test bootstrap disables background updates; the runner
		// rightly honours that, so lift it as the update tests do.
		add_filter( 'automatic_updater_disabled', '__return_false', 20 );
	}

	public function tear_down() {
		global $wpdb;
		remove_filter( 'pre_http_request', array( $this, 'serve_directory' ), 10 );
		remove_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		remove_filter( 'automatic_updater_disabled', '__return_false', 20 );
		remove_all_filters( 'plugins_api' );
		remove_all_filters( 'plugins_api_result' );
		remove_all_filters( 'upgrader_pre_download' );
		remove_all_filters( 'upgrader_pre_install' );
		remove_all_filters( 'file_mod_allowed' );
		remove_all_filters( 'map_meta_cap' );
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			unlink( ABSPATH . '.maintenance' );
		}
		if ( $this->zip && file_exists( $this->zip ) ) {
			unlink( $this->zip );
		}
		wp_clear_scheduled_hook( Saddle_Update_Runner::HOOK );
		delete_option( Saddle_Update_Runner::OPTION );
		delete_site_transient( 'update_plugins' );
		foreach ( $this->dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%saddle_plugin_search_%'" );
		wp_cache_flush();
		wp_cache_delete( 'plugins', 'plugins' );
		delete_option( 'active_plugins' );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	/* -------- fixtures -------- */

	private function fixture( $name ) {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/wporg/' . $name . '.json' ), true );
	}

	public function serve_directory( $pre, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		$parts = wp_parse_url( $url );
		if ( 'api.wordpress.org' !== $parts['host'] || '/plugins/info/1.2/' !== $parts['path'] ) {
			return new WP_Error( 'saddle_test_offline', 'No outbound HTTP in tests: ' . $url );
		}
		$this->requests[] = $url;
		parse_str( $parts['query'], $query );

		if ( 'query_plugins' === $query['action'] ) {
			$body = $this->fixture( 'query-plugins' );
		} else {
			$slug = $query['request']['slug'];
			$body = isset( $this->info[ $slug ] ) ? $this->info[ $slug ] : array( 'error' => 'Plugin not found.' );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** A directory entry for the throwaway plugin, built on the recorded answer. */
	private function dummy( $version, array $extra = array() ) {
		$this->info[ self::SLUG ] = array_merge(
			$this->fixture( 'plugin-information' ),
			array(
				'name'             => 'Saddle Dummy Install',
				'slug'             => self::SLUG,
				'version'          => $version,
				'tested'           => get_bloginfo( 'version' ),
				'requires_plugins' => array(),
				'last_updated'     => gmdate( 'Y-m-d g:ia', time() - DAY_IN_SECONDS ) . ' GMT',
				'download_link'    => 'https://downloads.wordpress.org/plugin/' . self::SLUG . '.' . $version . '.zip',
			),
			$extra
		);
	}

	/** A zip of the throwaway plugin, served in place of the download. */
	private function serve_package( $version ) {
		$this->zip = get_temp_dir() . self::SLUG . '-' . wp_generate_password( 6, false ) . '.zip';
		$zip       = new ZipArchive();
		$zip->open( $this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( self::SLUG . '/' . self::SLUG . '.php', "<?php\n/**\n * Plugin Name: Saddle Dummy Install\n * Version: {$version}\n */\n" );
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

	/** Count every call into core's upgrader from here on. */
	private function watch_upgrader() {
		$count = function ( $reply ) {
			++$this->upgrader_calls;
			return $reply;
		};
		add_filter( 'upgrader_pre_download', $count, 1 );
		add_filter( 'upgrader_pre_install', $count, 1 );
	}

	private function installed( $slug ) {
		wp_cache_delete( 'plugins', 'plugins' );
		return array_key_exists( "{$slug}/{$slug}.php", get_plugins() );
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

	private function write_plugin( $slug, $version ) {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		wp_mkdir_p( $dir );
		file_put_contents( "{$dir}/{$slug}.php", "<?php\n/**\n * Plugin Name: Test {$slug}\n * Version: {$version}\n */\n" );
		$this->dirs[] = $dir;
		wp_cache_delete( 'plugins', 'plugins' );
		return "{$slug}/{$slug}.php";
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

	private function search( array $input ) {
		return $this->ability( 'saddle/search-plugins' )->execute( $input );
	}

	private function install( array $input = array() ) {
		return $this->ability( 'saddle/install-plugin' )->execute( $input + array( 'slug' => self::SLUG ) );
	}

	private function preview() {
		$preview = $this->install();
		$this->assertNotWPError( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertNotEmpty( $preview['confirm_token'] );
		return $preview;
	}

	private function confirm( $token ) {
		return $this->install( array( 'confirm_token' => $token ) );
	}

	/** A refusal: an error, no token, nothing queued, nothing on disk. */
	private function assert_refused( $result, $code ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertFalse( wp_next_scheduled( Saddle_Update_Runner::HOOK ) );
		$this->assertSame( array(), Saddle_Update_Runner::runs() );
	}

	/* -------- search-plugins -------- */

	public function test_search_plugins_requires_admin_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->assertWPError( $this->search( array( 'search' => 'classic editor' ) ) );
		$this->assertSame( array(), $this->requests, 'A refused call asks the directory nothing.' );
	}

	public function test_search_returns_the_directory_rows() {
		$result = $this->search( array( 'search' => 'classic editor' ) );
		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$this->assertSame( array( 'results', 'total', 'source' ), array_keys( $result ) );
		$this->assertSame( 'wordpress.org', $result['source'] );
		$this->assertSame( 4111, $result['total'] );
		$this->assertCount( 3, $result['results'] );

		$row = $result['results'][0];
		$this->assertSame(
			array( 'slug', 'name', 'author', 'version', 'active_installs', 'rating', 'num_ratings', 'last_updated', 'tested', 'requires', 'requires_php', 'installed', 'active', 'plugin' ),
			array_keys( $row )
		);
		$this->assertSame( 'classic-editor', $row['slug'] );
		$this->assertSame( 'Classic Editor', $row['name'] );
		$this->assertSame( 'WordPress.org', $row['author'], 'The author link is reduced to its text.' );
		$this->assertSame( '1.7.0', $row['version'] );
		$this->assertSame( 8000000, $row['active_installs'] );
		$this->assertSame( 98, $row['rating'] );
		$this->assertSame( 1248, $row['num_ratings'] );
		$this->assertSame( '2026-05-28', $row['last_updated'] );
		$this->assertSame( '7.0.7', $row['tested'] );
		$this->assertSame( '4.9', $row['requires'] );
		$this->assertSame( '5.2.4', $row['requires_php'] );
		$this->assertFalse( $row['installed'] );
		$this->assertFalse( $row['active'] );
		$this->assertNull( $row['plugin'] );
	}

	public function test_the_search_asks_wordpress_org_for_saddles_fields() {
		$this->search(
			array(
				'search'   => 'classic editor',
				'per_page' => 3,
			)
		);
		$this->assertCount( 1, $this->requests );

		$parts = wp_parse_url( $this->requests[0] );
		parse_str( $parts['query'], $query );
		$this->assertSame( 'api.wordpress.org', $parts['host'] );
		$this->assertSame( 'query_plugins', $query['action'] );
		$this->assertSame( 'classic editor', $query['request']['search'] );
		$this->assertSame( '3', $query['request']['per_page'] );
		foreach ( Saddle_Directory::FIELDS as $field => $wanted ) {
			$this->assertSame( $wanted ? '1' : '0', $query['request']['fields'][ $field ], "Field {$field}" );
		}
	}

	public function test_the_recorded_directory_answers_carry_the_fields_saddle_reads() {
		// The names Saddle reads, against what WordPress.org really sent.
		$read   = array( 'slug', 'name', 'author', 'version', 'active_installs', 'rating', 'num_ratings', 'last_updated', 'tested', 'requires', 'requires_php', 'download_link' );
		$search = $this->fixture( 'query-plugins' );
		$info   = $this->fixture( 'plugin-information' );
		$this->assertArrayHasKey( 'results', $search['info'] );
		foreach ( $read as $field ) {
			$this->assertArrayHasKey( $field, $search['plugins'][0], "query_plugins sends {$field}" );
			$this->assertArrayHasKey( $field, $info, "plugin_information sends {$field}" );
		}
		$this->assertStringStartsWith( 'https://downloads.wordpress.org/plugin/', $info['download_link'] );
		$this->assertArrayNotHasKey( 'sections', $info, 'The heavy fields stay off.' );
	}

	public function test_a_repeated_search_is_answered_from_the_cache() {
		$this->search( array( 'search' => 'classic editor' ) );
		$this->search( array( 'search' => 'classic editor' ) );
		$this->assertCount( 1, $this->requests, 'The second search must not ask WordPress.org again.' );

		$this->search( array( 'search' => 'something else' ) );
		$this->assertCount( 2, $this->requests, 'A different search is a different cache entry.' );
	}

	public function test_installed_and_active_are_this_sites_and_never_cached() {
		$first = $this->search( array( 'slug' => 'classic-editor' ) );
		$this->assertFalse( $first['results'][0]['installed'] );

		$file = $this->write_plugin( 'classic-editor', '1.7.0' );
		activate_plugin( $file );

		$again = $this->search( array( 'slug' => 'classic-editor' ) );
		$this->assertCount( 1, $this->requests, 'The directory answer came from the cache.' );
		$this->assertTrue( $again['results'][0]['installed'] );
		$this->assertTrue( $again['results'][0]['active'] );
		$this->assertSame( $file, $again['results'][0]['plugin'] );
	}

	public function test_slug_looks_up_one_plugin() {
		$result = $this->search( array( 'slug' => 'classic-editor' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'classic-editor', $result['results'][0]['slug'] );
		$this->assertStringContainsString( 'action=plugin_information', $this->requests[0] );
	}

	public function test_unknown_and_closed_slugs_are_named() {
		$result = $this->search( array( 'slug' => 'no-such-plugin' ) );
		$this->assertSame( 'saddle_not_in_directory', $result->get_error_code() );
		$this->assertStringContainsString( 'no-such-plugin', $result->get_error_message() );

		// The directory's shape for a closed plugin: an "error" of "closed".
		$this->info['gone-plugin'] = array(
			'error'  => 'closed',
			'name'   => 'Gone Plugin',
			'slug'   => 'gone-plugin',
			'closed' => true,
		);
		$result = $this->search( array( 'slug' => 'gone-plugin' ) );
		$this->assertSame( 'saddle_plugin_closed', $result->get_error_code() );
	}

	public function test_an_unreachable_directory_is_a_502_with_the_reason() {
		add_filter(
			'plugins_api',
			static function () {
				return new WP_Error( 'plugins_api_failed', 'An unexpected error occurred.', 'cURL error 6: Could not resolve host: api.wordpress.org' );
			}
		);
		$result = $this->search( array( 'search' => 'classic editor' ) );
		$this->assertSame( 'saddle_directory_unreachable', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'Could not resolve host', $result->get_error_message() );
	}

	public function test_exactly_one_of_search_or_slug() {
		$this->assertSame( 'saddle_search_or_slug', $this->search( array() )->get_error_code() );
		$both = $this->search(
			array(
				'search' => 'editor',
				'slug'   => 'classic-editor',
			)
		);
		$this->assertSame( 'saddle_search_or_slug', $both->get_error_code() );
		$this->assertSame( 'saddle_invalid_slug', $this->search( array( 'slug' => 'Not A Slug!' ) )->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_more_than_ten_results_is_refused() {
		$result = $this->search(
			array(
				'search'   => 'editor',
				'per_page' => 11,
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( array(), $this->requests );
	}

	/* -------- install-plugin: the gate, and the hard line -------- */

	public function test_install_plugin_requires_admin_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->assertWPError( $this->install() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_preview_changes_nothing() {
		$preview = $this->preview();
		$shown   = $preview['preview'];

		$this->assertSame( 'Saddle Dummy Install', $shown['name'] );
		$this->assertSame( '1.0.0', $shown['version'] );
		$this->assertSame( 'WordPress.org', $shown['author'] );
		$this->assertSame( get_bloginfo( 'version' ), $shown['wordpress'] );
		$this->assertSame( PHP_VERSION, $shown['php'] );
		$this->assertSame( '5.2.4', $shown['requires_php'] );
		$this->assertTrue( $shown['arrives_inactive'] );
		$this->assertTrue( $shown['runs_in_background'] );
		$this->assertSame( array(), $shown['warnings'] );
		$this->assertSame( 'wordpress.org', $shown['source'] );

		$this->assertFalse( wp_next_scheduled( Saddle_Update_Runner::HOOK ), 'A preview must not queue a run.' );
		$this->assertSame( array(), Saddle_Update_Runner::runs(), 'A preview must not record a run.' );
		$this->assertFalse( file_exists( WP_PLUGIN_DIR . '/' . self::SLUG ) );
	}

	public function test_confirm_queues_one_run_and_never_runs_the_installer_in_the_request() {
		$preview = $this->preview();
		$this->watch_upgrader();
		$done = $this->confirm( $preview['confirm_token'] );

		$this->assertNotWPError( $done );
		$this->assertTrue( $done['queued'] );
		$this->assertSame( 'activate-plugin', $done['next'] );
		$this->assertNotFalse( wp_next_scheduled( Saddle_Update_Runner::HOOK, array( $done['run_id'] ) ) );

		$runs = Saddle_Update_Runner::runs();
		$this->assertCount( 1, $runs, 'Exactly one run is queued.' );
		$this->assertSame( 'install-plugin', $runs[0]['type'] );
		$this->assertSame( 'queued', $runs[0]['status'] );
		$this->assertSame( self::SLUG, $runs[0]['items'][0]['id'] );
		$this->assertSame( '1.0.0', $runs[0]['items'][0]['to'] );

		// The hard line: the request that confirmed did not touch the upgrader.
		$this->assertSame( 0, $this->upgrader_calls, 'Confirming must not run the installer synchronously.' );
		$this->assertFalse( file_exists( WP_PLUGIN_DIR . '/' . self::SLUG ) );
		$this->assertNotEmpty( $this->log_entries( 'install-plugin' ), 'Queueing is logged.' );
	}

	public function test_confirm_token_is_single_use() {
		$preview = $this->preview();
		$this->assertNotWPError( $this->confirm( $preview['confirm_token'] ) );
		$this->assertWPError( $this->confirm( $preview['confirm_token'] ), 'A used token must be refused.' );
		$this->assertCount( 1, Saddle_Update_Runner::runs() );
	}

	public function test_a_token_previewed_for_one_version_cannot_install_another() {
		$preview = $this->preview();
		$this->dummy( '1.1.0' ); // A new release arrived in between.
		$this->assertWPError( $this->confirm( $preview['confirm_token'] ), 'The token binds the version previewed.' );
		$this->assertSame( array(), Saddle_Update_Runner::runs() );
	}

	/* -------- install-plugin: the runner, with core's installer -------- */

	public function test_runner_installs_the_confirmed_version_inactive() {
		$done = $this->confirm( $this->preview()['confirm_token'] );
		$this->serve_package( '1.0.0' );

		Saddle_Update_Runner::run( $done['run_id'] );

		$run = Saddle_Update_Runner::get_run( $done['run_id'] );
		$this->assertSame( 'finished', $run['status'] );
		$this->assertSame( 'installed', $run['items'][0]['status'], $run['items'][0]['message'] );
		$this->assertTrue( $this->installed( self::SLUG ), 'Core installed the package.' );
		$this->assertFalse( is_plugin_active( self::SLUG . '/' . self::SLUG . '.php' ), 'A new install arrives inactive.' );
		$this->assertFalse( file_exists( ABSPATH . '.maintenance' ) );

		$lines = wp_list_pluck( $this->log_entries( 'install-plugin' ), 'post_title' );
		$this->assertNotEmpty( $lines );

		// list-updates returns these runs as they are, under "runs".
		$this->assertSame( 'installed', Saddle_Update_Runner::runs()[0]['items'][0]['status'] );
	}

	public function test_runner_skips_when_wordpress_org_moved_on() {
		$done = $this->confirm( $this->preview()['confirm_token'] );
		$this->dummy( '1.1.0' );
		$this->serve_package( '1.1.0' );

		Saddle_Update_Runner::run( $done['run_id'] );

		$item = Saddle_Update_Runner::get_run( $done['run_id'] )['items'][0];
		$this->assertSame( 'skipped', $item['status'] );
		$this->assertStringContainsString( '1.1.0', $item['message'] );
		$this->assertFalse( $this->installed( self::SLUG ), 'Only the confirmed version is ever installed.' );
	}

	public function test_runner_skips_a_plugin_installed_since_the_confirm() {
		$done = $this->confirm( $this->preview()['confirm_token'] );
		$this->write_plugin( self::SLUG, '0.9.0' );
		$this->serve_package( '1.0.0' );

		Saddle_Update_Runner::run( $done['run_id'] );

		$item = Saddle_Update_Runner::get_run( $done['run_id'] )['items'][0];
		$this->assertSame( 'skipped', $item['status'] );
		wp_cache_delete( 'plugins', 'plugins' );
		$this->assertSame( '0.9.0', get_plugins()[ self::SLUG . '/' . self::SLUG . '.php' ]['Version'], 'Nothing was overwritten.' );
	}

	/* -------- install-plugin: refusals, all before a token -------- */

	public function test_a_site_that_forbids_file_changes_is_refused_before_asking_the_directory() {
		add_filter( 'file_mod_allowed', '__return_false' );
		$result = $this->install();
		$this->assert_refused( $result, 'saddle_updates_unavailable' );
		$this->assertStringContainsString( 'DISALLOW_FILE_MODS', $result->get_error_message() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_account_that_cannot_install_plugins_is_refused() {
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				return 'install_plugins' === $cap ? array( 'do_not_allow' ) : $caps;
			},
			10,
			2
		);
		$this->assert_refused( $this->install(), 'saddle_cannot_install' );
	}

	public function test_an_installed_plugin_or_a_leftover_folder_is_refused() {
		$this->write_plugin( self::SLUG, '1.0.0' );
		$this->assert_refused( $this->install(), 'saddle_already_installed' );

		$this->remove_dir( WP_PLUGIN_DIR . '/' . self::SLUG );
		wp_mkdir_p( WP_PLUGIN_DIR . '/' . self::SLUG );
		file_put_contents( WP_PLUGIN_DIR . '/' . self::SLUG . '/readme.txt', 'Not a plugin.' );
		wp_cache_delete( 'plugins', 'plugins' );
		$this->assert_refused( $this->install(), 'saddle_already_installed' );
	}

	public function test_a_plugin_wordpress_org_knows_under_that_slug_in_another_folder_is_refused() {
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => time(),
				'response'     => array(),
				'no_update'    => array(
					'renamed-folder/main.php' => (object) array(
						'slug'   => self::SLUG,
						'plugin' => 'renamed-folder/main.php',
					),
				),
			)
		);
		$this->assert_refused( $this->install(), 'saddle_already_installed' );
		$this->assertSame( array(), array_filter( $this->requests, fn( $url ) => false !== strpos( $url, 'update-check' ) ), 'The check makes no update request.' );
	}

	public function test_unknown_and_invalid_slugs_are_refused() {
		$this->assert_refused( $this->install( array( 'slug' => 'no-such-plugin' ) ), 'saddle_not_in_directory' );
		$this->assert_refused( $this->install( array( 'slug' => '../hello' ) ), 'saddle_invalid_slug' );
	}

	public function test_a_plugin_needing_newer_php_or_wordpress_is_refused() {
		$this->dummy( '1.0.0', array( 'requires_php' => '99.0' ) );
		$this->assert_refused( $this->install(), 'saddle_php_too_old' );

		$this->dummy( '1.0.0', array( 'requires' => '99.0' ) );
		$this->assert_refused( $this->install(), 'saddle_wp_too_old' );
	}

	public function test_a_package_outside_wordpress_org_is_refused() {
		foreach ( array(
			'https://example.com/' . self::SLUG . '.zip',
			'http://downloads.wordpress.org/plugin/' . self::SLUG . '.1.0.0.zip',
			'https://downloads.wordpress.org/theme/' . self::SLUG . '.1.0.0.zip',
			'https://downloads.wordpress.org:8443/plugin/' . self::SLUG . '.1.0.0.zip',
		) as $link ) {
			$this->dummy( '1.0.0', array( 'download_link' => $link ) );
			$this->assert_refused( $this->install(), 'saddle_not_from_wporg' );
		}
	}

	public function test_preview_warns_untested_stale_and_needed_plugins() {
		$this->dummy(
			'1.0.0',
			array(
				'tested'           => '5.0',
				'last_updated'     => '2020-01-01 1:00pm GMT',
				'requires_plugins' => array( 'woocommerce' ),
			)
		);
		$warnings = implode( ' ', $this->preview()['preview']['warnings'] );
		$this->assertStringContainsString( 'Tested up to WordPress 5.0', $warnings );
		$this->assertStringContainsString( '2020-01-01', $warnings );
		$this->assertStringContainsString( 'woocommerce', $warnings );
	}
}
