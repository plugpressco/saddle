<?php
/**
 * Runs confirmed updates and installs out of band: plugin, theme and core
 * updates WordPress already offers, and installs from the WordPress.org
 * directory.
 *
 * Why a separate class: the abilities answer a request and return; this runs
 * later on a one-shot cron event with no request, no ability context and no
 * agent to answer. Keeping the two apart is also what makes the hard line
 * testable: nothing here is reachable from an ability's execute path except
 * {@see self::queue()}.
 *
 * This class schedules, guards, locks and stores runs. The work itself is
 * one of two paths: {@see Saddle_Offer_Runner} hands an offer core already
 * holds to {@see WP_Automatic_Updater::update()}, and
 * {@see Saddle_Install_Runner} installs from the directory through core's
 * installer. Neither writes a file or picks a package URL, and both report an
 * item core declines as skipped, never forced.
 *
 * Core's own refusals stand. {@see self::blocked_by()} names
 * `DISALLOW_FILE_MODS`, `AUTOMATIC_UPDATER_DISABLED`, a VCS checkout and a
 * filesystem that needs credentials before any token is issued, and again
 * when the run starts.
 *
 * Results live in a bounded option so `list-updates` can report what a
 * confirmed run did, including a rollback.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The out-of-band update runner.
 */
class Saddle_Update_Runner {

	/**
	 * Cron hook the queued run fires on.
	 */
	const HOOK = 'saddle_apply_updates';

	/**
	 * Option holding recent runs, newest first. Bounded to MAX_RUNS.
	 */
	const OPTION = 'saddle_update_runs';

	/**
	 * How many runs to keep.
	 */
	const MAX_RUNS = 10;

	/**
	 * Most items one run may carry. Over the cap is refused, never truncated.
	 */
	const MAX_ITEMS = 10;

	/**
	 * Run types that install from the directory instead of updating; the
	 * runner hands them to {@see Saddle_Install_Runner}.
	 */
	const INSTALL_TYPES = array( 'install-plugin', 'install-theme' );

	/**
	 * Hook the cron handler. Called once at plugin load.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Load the admin-side update and upgrader APIs, which a REST or cron
	 * request does not have.
	 */
	public static function load_update_api() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}
	}

	/**
	 * Why applying updates would be refused on this site right now, in the
	 * owner's language. Empty when core would run them.
	 *
	 * Checked before a preview is issued, so a confirm token is never handed
	 * out for a run that cannot happen, and reported by list-updates.
	 *
	 * @param string $type A run type: 'plugin', 'theme', 'core', 'translation',
	 *                     'install-plugin' or 'install-theme'.
	 * @return string[] Reasons; empty when clear.
	 */
	public static function blocked_by( $type = 'plugin' ) {
		self::load_update_api();
		$reasons = array();

		if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			$reasons[] = __( 'This site does not allow file changes (DISALLOW_FILE_MODS, or a host setting). Updates have to be applied by the host.', 'saddle' );
		}
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			$reasons[] = __( 'Background updates are switched off (AUTOMATIC_UPDATER_DISABLED). Saddle applies updates through the background updater, so it is off too.', 'saddle' );
		}
		$updater = new WP_Automatic_Updater();
		if ( empty( $reasons ) && $updater->is_disabled() ) {
			$reasons[] = __( 'A plugin or a host setting has disabled background updates on this site.', 'saddle' );
		}

		if ( 'core' === $type ) {
			$context = ABSPATH;
		} elseif ( 'translation' === $type ) {
			$context = WP_CONTENT_DIR; // As core's own updater checks language packs.
		} else {
			$context = in_array( $type, array( 'theme', 'install-theme' ), true ) ? get_theme_root() : WP_PLUGIN_DIR;
		}
		if ( $updater->is_vcs_checkout( $context ) ) {
			$reasons[] = __( 'This site is a version-control checkout (git, svn or similar). WordPress refuses to update files that a repository owns.', 'saddle' );
		}
		if ( 'direct' !== get_filesystem_method( array(), $context ) ) {
			$reasons[] = __( 'WordPress cannot write to this site without FTP or SSH credentials, so it cannot apply updates on its own.', 'saddle' );
		}

		return $reasons;
	}

	/**
	 * Queue a confirmed set of items for a background run and start cron.
	 *
	 * @param string $type  A run type: 'plugin', 'theme', 'core', 'translation',
	 *                      'install-plugin' or 'install-theme'.
	 * @param array  $items Items, each {id, name, from, to}.
	 * @return string Run id.
	 */
	public static function queue( $type, array $items ) {
		$run = array(
			'id'           => substr( wp_hash( uniqid( $type, true ) ), 0, 12 ),
			'type'         => $type,
			'status'       => 'queued',
			'requested_by' => get_current_user_id(),
			'created'      => time(),
			'started'      => 0,
			'finished'     => 0,
			'items'        => array(),
		);
		foreach ( $items as $item ) {
			$run['items'][] = array(
				'id'      => $item['id'],
				'name'    => $item['name'],
				'from'    => $item['from'],
				'to'      => $item['to'],
				'status'  => 'queued',
				'message' => '',
			);
		}
		self::save_run( $run );

		wp_schedule_single_event( time(), self::HOOK, array( $run['id'] ) );
		// Ask WordPress to start cron now rather than on the next visit, so a
		// confirmed run begins within seconds on a site using default cron.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return $run['id'];
	}

	/**
	 * The cron handler: apply every item of one run through core's updater.
	 *
	 * @param string $run_id Run id from queue().
	 */
	public static function run( $run_id ) {
		$run = self::get_run( $run_id );
		if ( ! $run || 'queued' !== $run['status'] ) {
			return;
		}

		self::load_update_api();
		$type    = $run['type'];
		$updater = new WP_Automatic_Updater();

		$blocked = self::blocked_by( $type );
		if ( ! empty( $blocked ) || $updater->is_disabled() ) {
			$reason = $blocked ? implode( ' ', $blocked ) : __( 'Background updates are disabled on this site.', 'saddle' );
			foreach ( $run['items'] as &$item ) {
				$item['status']  = 'failed';
				$item['message'] = $reason;
			}
			unset( $item );
			$run['status']   = 'finished';
			$run['finished'] = time();
			self::save_run( $run );
			return;
		}

		if ( ! WP_Upgrader::create_lock( 'auto_updater' ) ) {
			// Core's own background run holds the lock; try again shortly.
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $run_id ) );
			return;
		}

		$run['status']  = 'running';
		$run['started'] = time();
		self::save_run( $run );

		// As core's own WP_Automatic_Updater::run() does: the per-item
		// completion hooks would re-check for updates and start language-pack
		// upgrades after every item. The caches are refreshed once, below.
		$completion_hooks = array(
			array( array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 ),
			array( 'wp_version_check', 10 ),
			array( 'wp_update_plugins', 10 ),
			array( 'wp_update_themes', 10 ),
		);
		foreach ( $completion_hooks as $hook ) {
			remove_action( 'upgrader_process_complete', $hook[0], $hook[1] );
		}

		if ( in_array( $type, self::INSTALL_TYPES, true ) ) {
			Saddle_Install_Runner::apply( $run );
		} else {
			Saddle_Offer_Runner::apply( $run, $updater );
		}

		foreach ( $completion_hooks as $hook ) {
			add_action( 'upgrader_process_complete', $hook[0], $hook[1] );
		}
		WP_Upgrader::release_lock( 'auto_updater' );

		if ( in_array( $type, array( 'theme', 'install-theme' ), true ) ) {
			wp_clean_themes_cache( true );
		} else {
			wp_clean_plugins_cache( true );
		}

		$run['status']   = 'finished';
		$run['finished'] = time();
		self::save_run( $run );
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function runs( $limit = 5 ) {
		$runs = get_option( self::OPTION, array() );
		$runs = is_array( $runs ) ? array_values( $runs ) : array();
		return array_slice( $runs, 0, max( 1, (int) $limit ) );
	}

	/**
	 * One run by id.
	 *
	 * @param string $run_id Run id.
	 * @return array|null
	 */
	public static function get_run( $run_id ) {
		foreach ( self::runs( self::MAX_RUNS ) as $run ) {
			if ( isset( $run['id'] ) && $run['id'] === $run_id ) {
				return $run;
			}
		}
		return null;
	}

	/**
	 * Insert or replace a run, keeping the newest MAX_RUNS.
	 *
	 * @param array $run Run.
	 */
	private static function save_run( array $run ) {
		$runs = array_filter(
			self::runs( self::MAX_RUNS ),
			static function ( $existing ) use ( $run ) {
				return ! isset( $existing['id'] ) || $existing['id'] !== $run['id'];
			}
		);
		array_unshift( $runs, $run );
		$runs = array_slice( array_values( $runs ), 0, self::MAX_RUNS );
		update_option( self::OPTION, $runs, false );
	}

	/**
	 * Remove the scheduled event and the stored runs. Deactivation and
	 * uninstall call this.
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::OPTION );
	}
}
