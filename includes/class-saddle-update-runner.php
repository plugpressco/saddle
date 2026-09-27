<?php
/**
 * Applies plugin and theme updates that WordPress already offers, through
 * core's own automatic updater, out of band.
 *
 * Why a separate class: the abilities in `abilities/updates.php` answer a
 * request and return; this runs later on a one-shot cron event with no
 * request, no ability context and no agent to answer. Keeping the two apart
 * is also what makes the hard line testable: nothing here is reachable from
 * an ability's execute path except {@see self::queue()}.
 *
 * How the hard line keeps its meaning (decided 2026-09-27):
 *
 * - Saddle never writes a file and never picks a package URL. Each item is an
 *   offer core already lists in the `update_plugins` / `update_themes`
 *   transient, and {@see WP_Automatic_Updater::update()} downloads and
 *   installs it exactly as an auto-update would: maintenance mode on, the
 *   temporary backup (WordPress 6.3), the loopback fatal check and rollback
 *   for an active plugin (WordPress 6.6), maintenance mode off.
 * - Core's own refusals stand. {@see WP_Automatic_Updater::is_disabled()}
 *   covers `DISALLOW_FILE_MODS` and `AUTOMATIC_UPDATER_DISABLED`;
 *   {@see WP_Automatic_Updater::should_update()} covers a VCS checkout and a
 *   filesystem that needs credentials. An item core declines is reported as
 *   skipped, never forced.
 * - The only thing Saddle adds is a one-shot `auto_update_{type}` filter that
 *   says yes for the items the owner confirmed, so `should_update()` treats
 *   them like an auto-update the owner had switched on for this run only.
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
	 * @param string $type 'plugin' or 'theme'.
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

		$context = 'theme' === $type ? get_theme_root() : WP_PLUGIN_DIR;
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
	 * @param string $type  'plugin' or 'theme'.
	 * @param array  $items Items, each {file|stylesheet, name, from, to}.
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

		// Fresh offers, and the caches that would otherwise report old versions.
		if ( 'theme' === $type ) {
			wp_update_themes();
			$offers = get_site_transient( 'update_themes' );
		} else {
			wp_update_plugins();
			$offers = get_site_transient( 'update_plugins' );
		}
		$offers = ( is_object( $offers ) && ! empty( $offers->response ) ) ? $offers->response : array();

		// Say yes, for this run only, to exactly the items the owner confirmed.
		$wanted = wp_list_pluck( $run['items'], 'id' );
		$force  = static function ( $update, $offer ) use ( $wanted, $type ) {
			$key = 'theme' === $type ? 'theme' : 'plugin';
			$id  = is_object( $offer ) ? ( isset( $offer->$key ) ? $offer->$key : '' ) : ( isset( $offer[ $key ] ) ? $offer[ $key ] : '' );
			return in_array( $id, $wanted, true ) ? true : $update;
		};
		add_filter( "auto_update_{$type}", $force, PHP_INT_MAX, 2 );

		foreach ( $run['items'] as &$item ) {
			$offer = isset( $offers[ $item['id'] ] ) ? $offers[ $item['id'] ] : null;
			if ( ! $offer ) {
				$item['status']  = 'up_to_date';
				$item['message'] = __( 'No update was offered any more when the run started.', 'saddle' );
				continue;
			}
			$offer  = is_array( $offer ) ? (object) $offer : $offer;
			$result = $updater->update( $type, $offer );
			self::record_item( $type, $item, $result );
		}
		unset( $item );

		remove_filter( "auto_update_{$type}", $force, PHP_INT_MAX );
		WP_Upgrader::release_lock( 'auto_updater' );

		if ( 'theme' === $type ) {
			wp_clean_themes_cache( true );
		} else {
			wp_clean_plugins_cache( true );
		}

		$run['status']   = 'finished';
		$run['finished'] = time();
		self::save_run( $run );
	}

	/**
	 * Translate core's result for one item into a status, and log it.
	 *
	 * @param string $type   'plugin' or 'theme'.
	 * @param array  $item   Run item, by reference.
	 * @param mixed  $result What WP_Automatic_Updater::update() returned.
	 */
	private static function record_item( $type, array &$item, $result ) {
		if ( false === $result ) {
			$item['status']  = 'skipped';
			$item['message'] = __( 'WordPress declined to update this item on its own (a version-control checkout, missing filesystem access, or a plugin telling it not to).', 'saddle' );
		} elseif ( is_wp_error( $result ) ) {
			$codes = $result->get_error_codes();
			if ( in_array( 'plugin_update_fatal_error_rollback_successful', $codes, true ) ) {
				$item['status'] = 'rolled_back';
			} else {
				$item['status'] = 'failed';
			}
			$item['message'] = implode( ' ', $result->get_error_messages() );
		} else {
			$item['status']  = 'applied';
			$item['message'] = '';
		}

		$action = 'theme' === $type ? 'update-theme' : 'update-plugin';
		switch ( $item['status'] ) {
			case 'applied':
				/* translators: 1: item name, 2: old version, 3: new version. */
				$summary = sprintf( __( 'Updated %1$s from %2$s to %3$s.', 'saddle' ), $item['name'], $item['from'], $item['to'] );
				break;
			case 'rolled_back':
				/* translators: 1: item name, 2: new version, 3: old version. */
				$summary = sprintf( __( 'Update of %1$s to %2$s caused a fatal error; WordPress restored %3$s.', 'saddle' ), $item['name'], $item['to'], $item['from'] );
				break;
			case 'skipped':
				/* translators: 1: item name, 2: new version. */
				$summary = sprintf( __( 'Update of %1$s to %2$s was skipped by WordPress.', 'saddle' ), $item['name'], $item['to'] );
				break;
			default:
				/* translators: 1: item name, 2: new version, 3: error message. */
				$summary = sprintf( __( 'Update of %1$s to %2$s failed: %3$s', 'saddle' ), $item['name'], $item['to'], $item['message'] );
		}
		Saddle_Log::record_action( $action, $item['id'], $summary );
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
