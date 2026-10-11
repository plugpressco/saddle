<?php
/**
 * Installs a confirmed plugin from the WordPress.org directory, through
 * WordPress's own installer, out of band.
 *
 * Why a separate class from {@see Saddle_Update_Runner}: an update is an offer
 * core already holds in a transient and applies through
 * {@see WP_Automatic_Updater::update()}; an install asks the directory and
 * goes through {@see Plugin_Upgrader::install()}, with different refusals.
 * The two share the queue, the cron hook, the lock and the stored results,
 * which stay in the update runner.
 *
 * How the hard line keeps its meaning (decided 2026-10-12, #347):
 *
 * - The directory is asked again when the run starts, and the install goes
 *   ahead only for the exact version the owner confirmed, still not
 *   installed, from a package on downloads.wordpress.org. Anything else is
 *   reported as skipped, never forced.
 * - The package is the `download_link` that answer names, handed to core's
 *   installer exactly as Plugins → Add New does. Saddle stores no URL and
 *   writes no file.
 * - Core's installer never overwrites a folder that exists, and never
 *   activates: a new install arrives inactive.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The out-of-band installer.
 */
class Saddle_Install_Runner {

	/**
	 * Install every item of one run. Called by the update runner with its lock
	 * held and the completion hooks lifted.
	 *
	 * @param array $run Run, by reference; items get their status.
	 */
	public static function apply( array &$run ) {
		Saddle_Directory::load_api();
		foreach ( $run['items'] as &$item ) {
			self::install( $item );
			Saddle_Log::record_action( 'install-plugin', $item['id'], self::summary( $item ) );
		}
		unset( $item );
	}

	/**
	 * Install one plugin, or say why not.
	 *
	 * @param array $item Run item {id: slug, name, to: version}, by reference.
	 */
	private static function install( array &$item ) {
		$api = Saddle_Directory::plugin( $item['id'] );
		if ( is_wp_error( $api ) ) {
			$item['status']  = 'failed';
			$item['message'] = $api->get_error_message();
			return;
		}
		if ( (string) $api->version !== $item['to'] ) {
			$item['status']  = 'skipped';
			$item['message'] = sprintf(
				/* translators: 1: version offered now, 2: version confirmed. */
				__( 'WordPress.org now offers version %1$s, not the %2$s that was confirmed, so nothing was installed. Preview it again with install-plugin.', 'saddle' ),
				$api->version,
				$item['to']
			);
			return;
		}
		$refusal = Saddle_Directory::refusal( $api );
		if ( $refusal ) {
			$item['status']  = 'skipped';
			$item['message'] = $refusal->get_error_message();
			return;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		try {
			$result = $upgrader->install( $api->download_link );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'saddle_install_exception', $e->getMessage() );
		}

		if ( true === $result ) {
			$item['status']  = 'installed';
			$item['message'] = '';
			return;
		}
		$item['status']  = 'failed';
		$item['message'] = is_wp_error( $result )
			? implode( ' ', $result->get_error_messages() )
			: wp_strip_all_tags( implode( ' ', $skin->get_upgrade_messages() ) );
		if ( '' === trim( $item['message'] ) ) {
			$item['message'] = __( 'WordPress could not install the package.', 'saddle' );
		}
	}

	/**
	 * The activity log line for one item.
	 *
	 * @param array $item Run item with its status.
	 * @return string
	 */
	private static function summary( array $item ) {
		switch ( $item['status'] ) {
			case 'installed':
				/* translators: 1: plugin name, 2: version. */
				return sprintf( __( 'Installed %1$s %2$s from WordPress.org. It is inactive.', 'saddle' ), $item['name'], $item['to'] );
			case 'skipped':
				/* translators: 1: plugin name, 2: version, 3: reason. */
				return sprintf( __( 'Install of %1$s %2$s was skipped: %3$s', 'saddle' ), $item['name'], $item['to'], $item['message'] );
			default:
				/* translators: 1: plugin name, 2: version, 3: error message. */
				return sprintf( __( 'Install of %1$s %2$s failed: %3$s', 'saddle' ), $item['name'], $item['to'], $item['message'] );
		}
	}
}
