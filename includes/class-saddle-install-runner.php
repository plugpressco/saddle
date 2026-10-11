<?php
/**
 * Installs a confirmed plugin or theme from the WordPress.org directory,
 * through WordPress's own installer, out of band, and decides whether one may
 * be installed here at all.
 *
 * Why a separate class from {@see Saddle_Update_Runner}: an update is an offer
 * core already holds in a transient and applies through
 * {@see WP_Automatic_Updater::update()}; an install asks the directory and
 * goes through {@see Plugin_Upgrader::install()} or
 * {@see Theme_Upgrader::install()}, with different refusals. The two share the
 * queue, the cron hook, the lock and the stored results, which stay in the
 * update runner. The refusals live here, like `blocked_by()` lives there,
 * because the install abilities ask them before a token and the run asks
 * them again.
 *
 * How the hard line keeps its meaning (decided 2026-10-12, #347):
 *
 * - The directory is asked again when the run starts, and the install goes
 *   ahead only for the exact version the owner confirmed, still not
 *   installed, from a package on downloads.wordpress.org. Anything else is
 *   reported as skipped, never forced.
 * - The package is the `download_link` that answer names, handed to core's
 *   installer exactly as the Add New screens do. Saddle stores no URL and
 *   writes no file.
 * - Core's installer never overwrites a folder that exists and never
 *   activates: a new install arrives inactive. It would fetch a child
 *   theme's missing parent on its own; a child theme without its parent is
 *   refused, and during the run the directory answers only the confirmed slug.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The out-of-band installer and its refusals.
 */
class Saddle_Install_Runner {

	/**
	 * Whether a slug is installed here, and its plugin file or theme folder.
	 *
	 * Plugins follow core's rule on Plugins → Add New
	 * (`install_plugin_install_status()`): a folder of that name, or a plugin
	 * WordPress.org lists under that slug. Saddle reads the same two things
	 * without calling it, because that function forces a fresh update check,
	 * a WordPress.org request with every installed plugin, whenever its
	 * cached answer looks old.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param string $slug Directory slug.
	 * @return array {installed: bool, file: string|null}
	 */
	public static function installed( $type, $slug ) {
		Saddle_Directory::load_api();
		if ( 'theme' === $type ) {
			$here = wp_get_theme( $slug )->exists() || file_exists( get_theme_root() . '/' . $slug );
			return array(
				'installed' => $here,
				'file'      => $here ? $slug : null,
			);
		}

		$known = get_site_transient( 'update_plugins' );
		foreach ( array( 'response', 'no_update' ) as $list ) {
			foreach ( isset( $known->$list ) ? (array) $known->$list : array() as $file => $plugin ) {
				$plugin = (object) $plugin;
				if ( isset( $plugin->slug ) && $plugin->slug === $slug ) {
					return array(
						'installed' => true,
						'file'      => (string) $file,
					);
				}
			}
		}
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $slug ) ) {
			return array(
				'installed' => false,
				'file'      => null,
			);
		}
		$found = get_plugins( '/' . $slug );
		return array(
			'installed' => true,
			'file'      => $found ? $slug . '/' . key( $found ) : null,
		);
	}

	/**
	 * Why this directory entry must not be installed here, or null.
	 *
	 * Asked before a confirm token is issued and again when the run starts,
	 * so a token is never handed out for an install that cannot happen and a
	 * change in between is caught.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param object $api  An entry from Saddle_Directory::entry().
	 * @return WP_Error|null
	 */
	public static function refusal( $type, $api ) {
		$slug = (string) $api->slug;
		$name = wp_strip_all_tags( (string) $api->name );
		$fail = static function ( $code, $message ) {
			return new WP_Error( $code, $message, array( 'status' => 409 ) );
		};

		if ( self::installed( $type, $slug )['installed'] ) {
			return $fail(
				'saddle_already_installed',
				'theme' === $type
					/* translators: %s: theme slug. */
					? sprintf( __( 'A theme with the slug "%s" is already installed, or a folder with that name exists. Use activate-theme to switch to it, or update-theme if list-updates offers a newer version.', 'saddle' ), $slug )
					/* translators: %s: plugin slug. */
					: sprintf( __( 'A plugin with the slug "%s" is already installed, or a folder with that name exists. Use activate-plugin to turn it on, or update-plugin if list-updates offers a newer version.', 'saddle' ), $slug )
			);
		}
		if ( ! empty( $api->requires_php ) && ! is_php_version_compatible( $api->requires_php ) ) {
			/* translators: 1: plugin or theme name, 2: required PHP version, 3: this site's PHP version. */
			return $fail( 'saddle_php_too_old', sprintf( __( '%1$s needs PHP %2$s or newer; this site runs PHP %3$s. The host has to update PHP first.', 'saddle' ), $name, $api->requires_php, PHP_VERSION ) );
		}
		if ( ! empty( $api->requires ) && ! is_wp_version_compatible( $api->requires ) ) {
			/* translators: 1: plugin or theme name, 2: required WordPress version, 3: this site's WordPress version. */
			return $fail( 'saddle_wp_too_old', sprintf( __( '%1$s needs WordPress %2$s or newer; this site runs WordPress %3$s.', 'saddle' ), $name, $api->requires, get_bloginfo( 'version' ) ) );
		}
		$parent = self::parent_slug( $api );
		// WP_Theme can answer from the object cache for up to 30 minutes after
		// a theme is deleted, so its folder must still be there too.
		$found = $parent ? wp_get_theme( $parent ) : null;
		if ( $found && ! ( $found->exists() && file_exists( $found->get_stylesheet_directory() ) ) ) {
			return $fail(
				'saddle_parent_theme_missing',
				/* translators: 1: child theme name, 2: parent theme slug. */
				sprintf( __( '%1$s is a child theme of "%2$s", which is not installed. Install "%2$s" first with install-theme, then this one.', 'saddle' ), $name, $parent )
			);
		}
		if ( ! Saddle_Directory::is_wporg_package( isset( $api->download_link ) ? $api->download_link : '', $type ) ) {
			return $fail( 'saddle_not_from_wporg', __( 'The directory answer on this site names a package outside downloads.wordpress.org, so another plugin is probably changing it. Saddle installs only packages WordPress.org itself offers. Install this one in wp-admin if you trust its source.', 'saddle' ) );
		}
		return null;
	}

	/**
	 * The parent theme a directory entry names, or ''.
	 *
	 * @param object $api A directory entry.
	 * @return string
	 */
	public static function parent_slug( $api ) {
		$parent = isset( $api->template ) ? (string) $api->template : '';
		return ( '' !== $parent && $parent !== $api->slug ) ? $parent : '';
	}

	/**
	 * Install every item of one run. Called by the update runner with its lock
	 * held and the completion hooks lifted.
	 *
	 * @param array $run Run, by reference; items get their status.
	 */
	public static function apply( array &$run ) {
		Saddle_Directory::load_api();
		$type = 'install-theme' === $run['type'] ? 'theme' : 'plugin';
		foreach ( $run['items'] as &$item ) {
			self::install( $type, $item );
			Saddle_Log::record_action( $run['type'], $item['id'], self::summary( $type, $item ) );
		}
		unset( $item );
	}

	/**
	 * Install one plugin or theme, or say why not.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param array  $item Run item {id: slug, name, to: version}, by reference.
	 */
	private static function install( $type, array &$item ) {
		$api = Saddle_Directory::entry( $type, $item['id'] );
		if ( is_wp_error( $api ) ) {
			$item['status']  = 'failed';
			$item['message'] = $api->get_error_message();
			return;
		}
		if ( (string) $api->version !== $item['to'] ) {
			$item['status']  = 'skipped';
			$item['message'] = sprintf(
				/* translators: 1: version offered now, 2: version confirmed. */
				__( 'WordPress.org now offers version %1$s, not the %2$s that was confirmed, so nothing was installed. Preview it again.', 'saddle' ),
				$api->version,
				$item['to']
			);
			return;
		}
		$refusal = self::refusal( $type, $api );
		if ( $refusal ) {
			$item['status']  = 'skipped';
			$item['message'] = $refusal->get_error_message();
			return;
		}

		$skin = new Automatic_Upgrader_Skin();
		// Core's theme installer fetches a missing parent theme on its own.
		// For this run the directory answers only the slug that was confirmed.
		$slug  = $item['id'];
		$guard = static function ( $result, $action, $args ) use ( $slug ) {
			$asked = is_object( $args ) && isset( $args->slug ) ? $args->slug : '';
			return ( 'theme_information' === $action && $asked !== $slug )
				? new WP_Error( 'saddle_unconfirmed_theme', __( 'Saddle installs only the theme that was confirmed.', 'saddle' ) )
				: $result;
		};
		add_filter( 'themes_api', $guard, PHP_INT_MAX, 3 );
		try {
			$upgrader = 'theme' === $type ? new Theme_Upgrader( $skin ) : new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $api->download_link );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'saddle_install_exception', $e->getMessage() );
		}
		remove_filter( 'themes_api', $guard, PHP_INT_MAX );

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
	 * @param string $type 'plugin' or 'theme'.
	 * @param array  $item Run item with its status.
	 * @return string
	 */
	private static function summary( $type, array $item ) {
		switch ( $item['status'] ) {
			case 'installed':
				return 'theme' === $type
					/* translators: 1: theme name, 2: version. */
					? sprintf( __( 'Installed the theme %1$s %2$s from WordPress.org. It is not active.', 'saddle' ), $item['name'], $item['to'] )
					/* translators: 1: plugin name, 2: version. */
					: sprintf( __( 'Installed %1$s %2$s from WordPress.org. It is inactive.', 'saddle' ), $item['name'], $item['to'] );
			case 'skipped':
				/* translators: 1: plugin or theme name, 2: version, 3: reason. */
				return sprintf( __( 'Install of %1$s %2$s was skipped: %3$s', 'saddle' ), $item['name'], $item['to'], $item['message'] );
			default:
				/* translators: 1: plugin or theme name, 2: version, 3: error message. */
				return sprintf( __( 'Install of %1$s %2$s failed: %3$s', 'saddle' ), $item['name'], $item['to'], $item['message'] );
		}
	}
}
