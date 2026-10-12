<?php
/**
 * Core update ability tests.
 *
 * Drives the real wp_get_ability()->execute() path and the real
 * Saddle_Update_Runner handing the offer to core's WP_Automatic_Updater. The
 * offers are built from a version-check answer recorded from WordPress.org on
 * 2026-10-12 (`tests/fixtures/wporg/core-version-check.json`), which pins the
 * fields Saddle reads: response, current, locale, php_version, mysql_version
 * and packages.
 *
 * WordPress itself is never updated here. The runner test answers core's
 * package download with an error, through `upgrader_pre_download`, before
 * anything is unpacked, and checks which package core asked for.
 *
 * What this pins: the admin tier; that a preview changes nothing and a
 * confirm never runs the upgrader inside the request (the hard line);
 * single-use tokens bound to the release; minor before major; a major only
 * behind WordPress's own switch for all new versions; every refusal; and the
 * runner handing exactly the confirmed release to core.
 *
 * @package Saddle
 */

class Saddle_Core_Updates_Test extends WP_UnitTestCase {

	private $admin;
	private $version;
	private $upgrader_calls = 0;
	private $plugin_dir;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		Saddle_Update_Runner::load_update_api();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'admin' );
		$this->version = $GLOBALS['wp_version'];

		add_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10, 3 );
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		add_filter( 'automatic_updater_disabled', '__return_false', 20 );
		add_filter( 'send_core_update_notification_email', '__return_false' );
		add_filter( 'auto_core_update_send_email', '__return_false' );
	}

	public function tear_down() {
		$GLOBALS['wp_version'] = $this->version;
		remove_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10 );
		remove_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		remove_filter( 'automatic_updater_disabled', '__return_false', 20 );
		remove_filter( 'send_core_update_notification_email', '__return_false' );
		remove_filter( 'auto_core_update_send_email', '__return_false' );
		remove_all_filters( 'upgrader_pre_download' );
		remove_all_filters( 'upgrader_pre_install' );
		remove_all_filters( 'file_mod_allowed' );
		remove_all_filters( 'map_meta_cap' );
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			unlink( ABSPATH . '.maintenance' );
		}
		if ( $this->plugin_dir && is_dir( $this->plugin_dir ) ) {
			unlink( $this->plugin_dir . '/old-plugin.php' );
			rmdir( $this->plugin_dir );
		}
		delete_option( 'active_plugins' );
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		delete_site_option( 'auto_update_core_major' );
		delete_site_option( 'auto_core_update_failed' );
		WP_Upgrader::release_lock( 'core_updater' );
		wp_clear_scheduled_hook( Saddle_Update_Runner::HOOK );
		delete_option( Saddle_Update_Runner::OPTION );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	/* -------- fixtures -------- */

	public function refuse_http( $pre, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		return new WP_Error( 'saddle_test_offline', 'No outbound HTTP in tests: ' . $url );
	}

	/** The next minor and major release after the installed one. */
	private function next( $kind ) {
		$parts = array_map( 'intval', explode( '.', preg_replace( '/-.*/', '', $this->version ) ) ) + array( 0, 0, 0 );
		return 'minor' === $kind ? "{$parts[0]}.{$parts[1]}." . ( $parts[2] + 1 ) : "{$parts[0]}." . ( $parts[1] + 1 );
	}

	/**
	 * Offer releases as WordPress.org does: an "upgrade" entry for the newest
	 * and an "autoupdate" entry per release, built on the recorded answer.
	 *
	 * @param string[] $versions Releases to offer.
	 * @param array    $extra    Fields to change on every offer.
	 */
	private function offer( array $versions, array $extra = array() ) {
		$recorded = json_decode( file_get_contents( __DIR__ . '/fixtures/wporg/core-version-check.json' ), true );
		$template = null;
		foreach ( $recorded['offers'] as $offer ) {
			if ( 'autoupdate' === $offer['response'] ) {
				$template = $offer;
				break;
			}
		}
		$this->assertNotNull( $template, 'WordPress.org sends autoupdate offers.' );
		foreach ( array( 'response', 'current', 'locale', 'php_version', 'mysql_version', 'packages' ) as $field ) {
			$this->assertArrayHasKey( $field, $template, "Saddle reads {$field}." );
		}

		$updates = array();
		foreach ( $versions as $i => $version ) {
			$offer = array_merge(
				$template,
				array(
					'current'         => $version,
					'version'         => $version,
					'partial_version' => false,
					'packages'        => (object) array(
						'full'        => "https://downloads.wordpress.org/release/wordpress-{$version}.zip",
						'no_content'  => "https://downloads.wordpress.org/release/wordpress-{$version}-no-content.zip",
						'new_bundled' => "https://downloads.wordpress.org/release/wordpress-{$version}-new-bundled.zip",
						'partial'     => false,
						'rollback'    => false,
					),
				),
				$extra
			);
			if ( 0 === $i ) {
				$updates[] = (object) array_merge( $offer, array( 'response' => 'upgrade' ) );
			}
			$updates[] = (object) $offer;
		}
		set_site_transient(
			'update_core',
			(object) array(
				'updates'         => $updates,
				'last_checked'    => time(),
				'version_checked' => $this->version,
				'translations'    => array(),
			)
		);
	}

	private function watch_upgrader() {
		$count = function ( $reply ) {
			++$this->upgrader_calls;
			return $reply;
		};
		add_filter( 'upgrader_pre_download', $count, 1 );
		add_filter( 'upgrader_pre_install', $count, 1 );
	}

	private function call( array $input = array() ) {
		$ability = wp_get_ability( 'saddle/update-core' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	private function preview() {
		$preview = $this->call();
		$this->assertNotWPError( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$this->assertTrue( $preview['requires_confirmation'] );
		return $preview;
	}

	private function confirm( $token ) {
		return $this->call( array( 'confirm_token' => $token ) );
	}

	private function assert_refused( $result, $code ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( array(), Saddle_Update_Runner::runs() );
	}

	/* -------- the gate, and the hard line -------- */

	public function test_update_core_requires_admin_tier() {
		$this->offer( array( $this->next( 'minor' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		$this->assertWPError( $this->call() );
	}

	public function test_a_minor_preview_changes_nothing() {
		$this->offer( array( $this->next( 'minor' ) ) );
		$shown = $this->preview()['preview'];

		$this->assertSame( $this->version, $shown['from'] );
		$this->assertSame( $this->next( 'minor' ), $shown['to'] );
		$this->assertSame( 'minor', $shown['kind'] );
		$this->assertSame( 'en_US', $shown['locale'] );
		$this->assertSame( '7.4', $shown['requires_php'] );
		$this->assertSame( array(), $shown['cannot_be_undone'] );
		$this->assertSame( array(), Saddle_Update_Runner::runs() );
		$this->assertFalse( wp_next_scheduled( Saddle_Update_Runner::HOOK ) );
	}

	public function test_confirm_queues_one_run_and_never_runs_the_upgrader_in_the_request() {
		$this->offer( array( $this->next( 'minor' ) ) );
		$preview = $this->preview();
		$this->watch_upgrader();
		$done = $this->confirm( $preview['confirm_token'] );

		$this->assertNotWPError( $done );
		$runs = Saddle_Update_Runner::runs();
		$this->assertCount( 1, $runs );
		$this->assertSame( 'core', $runs[0]['type'] );
		$this->assertSame( $this->next( 'minor' ), $runs[0]['items'][0]['to'] );
		$this->assertSame( 0, $this->upgrader_calls, 'Confirming must not run the upgrader synchronously.' );
		$this->assertSame( $this->version, get_bloginfo( 'version' ) );
		$this->assertWPError( $this->confirm( $preview['confirm_token'] ), 'A used token must be refused.' );
	}

	public function test_a_token_for_one_release_cannot_apply_another() {
		$this->offer( array( $this->next( 'minor' ) ) );
		$preview = $this->preview();
		$parts   = explode( '.', $this->next( 'minor' ) );
		$this->offer( array( $parts[0] . '.' . $parts[1] . '.' . ( (int) $parts[2] + 1 ) ) );
		$this->assertWPError( $this->confirm( $preview['confirm_token'] ) );
		$this->assertSame( array(), Saddle_Update_Runner::runs() );
	}

	/* -------- minor and major -------- */

	public function test_the_minor_release_comes_before_a_major_one() {
		$this->offer( array( $this->next( 'major' ), $this->next( 'minor' ) ) );
		$this->assertSame( 'minor', $this->preview()['preview']['kind'] );
	}

	public function test_a_new_install_lets_wordpress_apply_major_releases() {
		// Core's default since WordPress 5.6 (schema.php): a new install
		// updates itself to every new version; a site upgraded from before
		// 5.6 has "unset", which means minor releases only. Saddle follows
		// whichever this site has, so this default decides most sites.
		$this->assertSame( 'enabled', get_site_option( 'auto_update_core_major' ) );
	}

	public function test_a_major_release_is_refused_when_wordpress_updates_itself_only_for_minor_releases() {
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			$this->markTestSkipped( 'WP_AUTO_UPDATE_CORE decides majors on this install.' );
		}
		// What Dashboard → Updates' "maintenance and security releases only"
		// link stores. A site upgraded from before 5.6 has "unset", which
		// core reads the same way.
		update_site_option( 'auto_update_core_major', 'disabled' );
		$this->offer( array( $this->next( 'major' ) ) );
		$result = $this->call();
		$this->assert_refused( $result, 'saddle_major_core_update' );
		$this->assertStringContainsString( 'Dashboard → Updates', $result->get_error_message() );
	}

	public function test_a_major_release_wordpress_would_apply_lists_what_can_break() {
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			$this->markTestSkipped( 'WP_AUTO_UPDATE_CORE decides majors on this install.' );
		}
		// An active plugin WordPress.org says was tested only up to this X.Y.
		$this->plugin_dir = WP_PLUGIN_DIR . '/old-plugin';
		wp_mkdir_p( $this->plugin_dir );
		file_put_contents( $this->plugin_dir . '/old-plugin.php', "<?php\n/**\n * Plugin Name: Old Plugin\n * Version: 1.0\n */\n" );
		wp_cache_delete( 'plugins', 'plugins' );
		update_option( 'active_plugins', array( 'old-plugin/old-plugin.php' ) );
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => time(),
				'response'     => array(),
				'no_update'    => array(
					'old-plugin/old-plugin.php' => (object) array(
						'slug'   => 'old-plugin',
						'tested' => implode( '.', array_slice( explode( '.', $this->version ), 0, 2 ) ),
					),
				),
			)
		);

		update_site_option( 'auto_update_core_major', 'enabled' );
		$this->offer( array( $this->next( 'major' ) ) );
		$shown = $this->preview()['preview'];

		$this->assertSame( 'major', $shown['kind'] );
		$this->assertCount( 3, $shown['cannot_be_undone'] );
		$this->assertSame( array( 'old-plugin/old-plugin.php' ), wp_list_pluck( $shown['untested_plugins'], 'plugin' ) );
		$this->assertSame( 'Old Plugin', $shown['untested_plugins'][0]['name'] );
	}

	/* -------- refusals, all before a token -------- */

	public function test_refusals_come_before_any_token() {
		$this->offer( array() );
		$this->assert_refused( $this->call(), 'saddle_no_update_offered' );

		// Only the "upgrade" entry, which WordPress would not apply on its own.
		$this->offer( array( $this->next( 'minor' ) ) );
		$transient          = get_site_transient( 'update_core' );
		$transient->updates = array( $transient->updates[0] );
		set_site_transient( 'update_core', $transient );
		$this->assert_refused( $this->call(), 'saddle_no_update_offered' );

		$this->offer( array( $this->next( 'minor' ) ), array( 'php_version' => '99.0' ) );
		$this->assert_refused( $this->call(), 'saddle_php_too_old' );

		$this->offer( array( $this->next( 'minor' ) ) );
		update_site_option( 'auto_core_update_failed', array( 'critical' => true ) );
		$this->assert_refused( $this->call(), 'saddle_core_update_failed_before' );
		delete_site_option( 'auto_core_update_failed' );

		$GLOBALS['wp_version'] = $this->next( 'minor' ) . '-beta1';
		$this->assert_refused( $this->call(), 'saddle_core_development' );
		$GLOBALS['wp_version'] = $this->version;
	}

	public function test_a_site_that_forbids_file_changes_is_refused_before_any_preview() {
		$this->offer( array( $this->next( 'minor' ) ) );
		add_filter( 'file_mod_allowed', '__return_false' );
		$result = $this->call();
		$this->assert_refused( $result, 'saddle_updates_unavailable' );
		$this->assertStringContainsString( 'DISALLOW_FILE_MODS', $result->get_error_message() );
	}

	public function test_an_account_that_cannot_update_core_is_refused() {
		$this->offer( array( $this->next( 'minor' ) ) );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				return 'update_core' === $cap ? array( 'do_not_allow' ) : $caps;
			},
			10,
			2
		);
		$this->assert_refused( $this->call(), 'saddle_cannot_update_core' );
	}

	/* -------- the runner, with core's updater -------- */

	public function test_runner_hands_exactly_the_confirmed_release_to_core() {
		$this->offer( array( $this->next( 'minor' ) ) );
		$done = $this->confirm( $this->preview()['confirm_token'] );

		// Answer core's download with an error before anything is unpacked.
		$asked = array();
		add_filter(
			'upgrader_pre_download',
			static function ( $reply, $package ) use ( &$asked ) {
				$asked[] = $package;
				return new WP_Error( 'saddle_test_no_download', 'Not in tests.' );
			},
			10,
			2
		);

		Saddle_Update_Runner::run( $done['run_id'] );

		$item = Saddle_Update_Runner::get_run( $done['run_id'] )['items'][0];
		$this->assertSame( 'failed', $item['status'] );
		$this->assertStringContainsString( 'Not in tests.', $item['message'] );
		$this->assertNotEmpty( $asked, 'Core asked for the package.' );
		$this->assertStringContainsString( 'wordpress-' . $this->next( 'minor' ), $asked[0] );
		$this->assertSame( $this->version, wp_get_wp_version(), 'WordPress was not changed.' );
		$this->assertFalse( file_exists( ABSPATH . '.maintenance' ) );
	}

	public function test_runner_reports_a_release_no_longer_offered() {
		$this->offer( array( $this->next( 'minor' ) ) );
		$done = $this->confirm( $this->preview()['confirm_token'] );
		$this->offer( array() );

		Saddle_Update_Runner::run( $done['run_id'] );

		$item = Saddle_Update_Runner::get_run( $done['run_id'] )['items'][0];
		$this->assertSame( 'up_to_date', $item['status'] );
	}
}
