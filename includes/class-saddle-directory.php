<?php
/**
 * The WordPress.org directory, read through core's own `plugins_api()`.
 *
 * Why a separate class: the install abilities ask the directory before they
 * issue a confirm token, and {@see Saddle_Install_Runner} asks it again on a
 * cron event with no ability loaded, so both must reach the same lookups and
 * the same refusals. One class also keeps the rule "only a package WordPress.org
 * names" in one place.
 *
 * Decided 2026-10-12 (#347): Saddle installs a plugin or theme only from the
 * WordPress.org directory, by slug. Every request here is the one Plugins →
 * Add New makes, and the package is the `download_link` that answer names,
 * on downloads.wordpress.org or refused.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Directory lookups, mapping and refusals.
 */
class Saddle_Directory {

	/**
	 * The plugin fields Saddle asks for, and the heavy ones it turns off.
	 * Pinned by a test against a recorded WordPress.org answer.
	 */
	const FIELDS = array(
		'active_installs'   => true,
		'last_updated'      => true,
		'tested'            => true,
		'requires'          => true,
		'requires_php'      => true,
		'requires_plugins'  => true,
		'rating'            => true,
		'num_ratings'       => true,
		'downloadlink'      => true,
		'short_description' => false,
		'description'       => false,
		'sections'          => false,
		'icons'             => false,
		'banners'           => false,
		'screenshots'       => false,
		'tags'              => false,
		'versions'          => false,
		'contributors'      => false,
		'ratings'           => false,
		'reviews'           => false,
		'donate_link'       => false,
		'homepage'          => false,
		'added'             => false,
	);

	/**
	 * Load the admin install APIs, which a REST or cron request lacks.
	 */
	public static function load_api() {
		Saddle_Update_Runner::load_update_api();
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
	}

	/**
	 * One plugins_api() call with Saddle's fields.
	 *
	 * @param string $action  'query_plugins' or 'plugin_information'.
	 * @param array  $request Request arguments, without fields.
	 * @return object|WP_Error
	 */
	public static function plugins( $action, array $request ) {
		self::load_api();
		$api = plugins_api( $action, $request + array( 'fields' => self::FIELDS ) );
		if ( ! is_wp_error( $api ) && ! is_object( $api ) ) {
			$api = new WP_Error( 'plugins_api_failed', __( 'WordPress.org sent an answer Saddle could not read.', 'saddle' ) );
		}
		return $api;
	}

	/**
	 * One plugin's directory entry, asked fresh, never cached: an install
	 * must see the version WordPress.org offers now.
	 *
	 * @param string $slug Directory slug.
	 * @return object|WP_Error The entry, or a named refusal.
	 */
	public static function plugin( $slug ) {
		$api = self::plugins( 'plugin_information', array( 'slug' => $slug ) );
		if ( is_wp_error( $api ) ) {
			return self::error( $api, $slug );
		}
		if ( empty( $api->slug ) || empty( $api->version ) ) {
			return self::error( new WP_Error( 'plugins_api_failed', 'Plugin not found.' ), $slug );
		}
		return $api;
	}

	/**
	 * One directory entry, as the agent sees it.
	 *
	 * @param object|array $api One plugin from plugins_api().
	 * @return array
	 */
	public static function row( $api ) {
		$api      = (array) $api;
		$text     = static function ( $key ) use ( $api ) {
			return empty( $api[ $key ] ) ? null : (string) $api[ $key ];
		};
		$readable = static function ( $value ) {
			return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );
		};
		$updated  = empty( $api['last_updated'] ) ? false : strtotime( (string) $api['last_updated'] );

		return array(
			'slug'            => (string) $api['slug'],
			'name'            => $readable( isset( $api['name'] ) ? $api['name'] : $api['slug'] ),
			'author'          => $readable( isset( $api['author'] ) ? $api['author'] : '' ),
			'version'         => (string) $text( 'version' ),
			'active_installs' => isset( $api['active_installs'] ) ? (int) $api['active_installs'] : 0,
			'rating'          => isset( $api['rating'] ) ? (int) $api['rating'] : 0,
			'num_ratings'     => isset( $api['num_ratings'] ) ? (int) $api['num_ratings'] : 0,
			'last_updated'    => $updated ? gmdate( 'Y-m-d', $updated ) : null,
			'tested'          => $text( 'tested' ),
			'requires'        => $text( 'requires' ),
			'requires_php'    => $text( 'requires_php' ),
		);
	}

	/**
	 * Whether a plugin slug is installed here, and its plugin file when it is.
	 *
	 * Core's rule on Plugins → Add New (`install_plugin_install_status()`):
	 * a folder of that name, or a known plugin WordPress.org lists under that
	 * slug. Saddle reads the same two things without calling that function,
	 * because it forces a fresh update check, a request to WordPress.org with
	 * every installed plugin, whenever its cached answer looks old.
	 *
	 * @param string $slug Directory slug.
	 * @return array {installed: bool, file: string|null}
	 */
	public static function plugin_status( $slug ) {
		self::load_api();
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

		if ( ! is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
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
	 * so a token is never handed out for an install that cannot happen and
	 * a change in between is caught.
	 *
	 * @param object $api A plugin entry from plugin().
	 * @return WP_Error|null
	 */
	public static function refusal( $api ) {
		$slug   = (string) $api->slug;
		$status = self::plugin_status( $slug );
		if ( $status['installed'] || file_exists( WP_PLUGIN_DIR . '/' . $slug ) ) {
			return new WP_Error(
				'saddle_already_installed',
				sprintf(
					/* translators: %s: plugin slug. */
					__( 'A plugin with the slug "%s" is already installed, or a folder with that name exists. Use activate-plugin to turn it on, or update-plugin if list-updates offers a newer version.', 'saddle' ),
					$slug
				),
				array( 'status' => 409 )
			);
		}
		if ( ! empty( $api->requires_php ) && ! is_php_version_compatible( $api->requires_php ) ) {
			return new WP_Error(
				'saddle_php_too_old',
				sprintf(
					/* translators: 1: plugin name, 2: required PHP version, 3: this site's PHP version. */
					__( '%1$s needs PHP %2$s or newer; this site runs PHP %3$s. The host has to update PHP first.', 'saddle' ),
					wp_strip_all_tags( (string) $api->name ),
					$api->requires_php,
					PHP_VERSION
				),
				array( 'status' => 409 )
			);
		}
		if ( ! empty( $api->requires ) && ! is_wp_version_compatible( $api->requires ) ) {
			return new WP_Error(
				'saddle_wp_too_old',
				sprintf(
					/* translators: 1: plugin name, 2: required WordPress version, 3: this site's WordPress version. */
					__( '%1$s needs WordPress %2$s or newer; this site runs WordPress %3$s.', 'saddle' ),
					wp_strip_all_tags( (string) $api->name ),
					$api->requires,
					get_bloginfo( 'version' )
				),
				array( 'status' => 409 )
			);
		}
		if ( ! self::is_wporg_package( isset( $api->download_link ) ? $api->download_link : '', 'plugin' ) ) {
			return new WP_Error(
				'saddle_not_from_wporg',
				__( 'The directory answer on this site names a package outside downloads.wordpress.org, so another plugin is probably changing it. Saddle installs only packages WordPress.org itself offers. Install this one in wp-admin if you trust its source.', 'saddle' ),
				array( 'status' => 409 )
			);
		}
		return null;
	}

	/**
	 * Whether a package URL is one WordPress.org serves for that type.
	 *
	 * @param string $url  Package URL from the directory answer.
	 * @param string $type 'plugin' or 'theme'.
	 * @return bool
	 */
	public static function is_wporg_package( $url, $type ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return false;
		}
		return isset( $parts['scheme'], $parts['host'], $parts['path'] )
			&& 'https' === $parts['scheme']
			&& 'downloads.wordpress.org' === $parts['host']
			&& 0 === strpos( $parts['path'], '/' . $type . '/' )
			&& '.zip' === substr( $parts['path'], -4 );
	}

	/**
	 * Name what went wrong with a directory request.
	 *
	 * plugins_api() reports both a refused request and the directory's own
	 * "Plugin not found." / "closed" as `plugins_api_failed`. Only a failed
	 * request carries the transport's message as error data.
	 *
	 * @param WP_Error $error From plugins_api().
	 * @param string   $slug  The slug asked for, if any.
	 * @return WP_Error
	 */
	public static function error( WP_Error $error, $slug = '' ) {
		if ( $error->get_error_data() || '' === $slug ) {
			return new WP_Error(
				'saddle_directory_unreachable',
				sprintf(
					/* translators: %s: the error WordPress reported. */
					__( 'WordPress could not get an answer from the WordPress.org directory: %s', 'saddle' ),
					wp_strip_all_tags( (string) ( $error->get_error_data() ? $error->get_error_data() : $error->get_error_message() ) )
				),
				array( 'status' => 502 )
			);
		}
		if ( 'closed' === $error->get_error_message() ) {
			return new WP_Error(
				'saddle_plugin_closed',
				/* translators: %s: plugin slug. */
				sprintf( __( 'WordPress.org has closed the plugin "%s", so it cannot be installed.', 'saddle' ), $slug ),
				array( 'status' => 404 )
			);
		}
		return new WP_Error(
			'saddle_not_in_directory',
			/* translators: %s: plugin slug. */
			sprintf( __( 'The WordPress.org directory has no plugin with the slug "%s". Use search-plugins with "search" to find the right slug.', 'saddle' ), $slug ),
			array( 'status' => 404 )
		);
	}

	/**
	 * A directory slug: lowercase letters, digits and hyphens.
	 *
	 * @param string $raw User input.
	 * @return string|WP_Error
	 */
	public static function clean_slug( $raw ) {
		$slug = strtolower( trim( (string) $raw, " /\t\n" ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
			return new WP_Error( 'saddle_invalid_slug', __( 'A directory slug has only lowercase letters, digits and hyphens, as in wordpress.org/plugins/<slug>/.', 'saddle' ), array( 'status' => 400 ) );
		}
		return $slug;
	}
}
