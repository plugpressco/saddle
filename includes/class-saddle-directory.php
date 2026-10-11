<?php
/**
 * The WordPress.org directory, read through core's own `plugins_api()` and
 * `themes_api()`.
 *
 * Why a separate class: the install abilities ask the directory before they
 * issue a confirm token, and {@see Saddle_Install_Runner} asks it again on a
 * cron event with no ability loaded, so both must reach the same lookups. One
 * class also keeps the rule "only a package WordPress.org names" in one place.
 *
 * Decided 2026-10-12 (#347): Saddle installs a plugin or theme only from the
 * WordPress.org directory, by slug. Every request here is the one Plugins →
 * Add New or Appearance → Themes → Add New makes, and the package is the
 * `download_link` that answer names, on downloads.wordpress.org or refused.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Directory lookups and mapping.
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
	 * The theme fields Saddle asks for. `parent` and `template` name the parent
	 * of a child theme, which core's installer would otherwise fetch unasked.
	 */
	const THEME_FIELDS = array(
		'active_installs'    => true,
		'last_updated'       => true,
		'requires'           => true,
		'requires_php'       => true,
		'rating'             => true,
		'num_ratings'        => true,
		'downloadlink'       => true,
		'parent'             => true,
		'template'           => true,
		'sections'           => false,
		'description'        => false,
		'tags'               => false,
		'screenshot_url'     => false,
		'preview_url'        => false,
		'homepage'           => false,
		'reviews_url'        => false,
		'theme_url'          => false,
		'extended_author'    => false,
		'photon_screenshots' => false,
		'downloaded'         => false,
		'creation_time'      => false,
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
	 * One plugin's or theme's directory entry, asked fresh, never cached: an
	 * install must see the version WordPress.org offers now.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param string $slug Directory slug.
	 * @return object|WP_Error The entry, or a named refusal.
	 */
	public static function entry( $type, $slug ) {
		self::load_api();
		$api = 'theme' === $type
			? themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => self::THEME_FIELDS,
				)
			)
			: self::plugins( 'plugin_information', array( 'slug' => $slug ) );

		if ( ! is_wp_error( $api ) && ( ! is_object( $api ) || empty( $api->slug ) || empty( $api->version ) ) ) {
			$api = new WP_Error( 'directory_not_found', 'not found' );
		}
		return is_wp_error( $api ) ? self::error( $api, $slug, $type ) : $api;
	}

	/**
	 * One directory entry, as the agent sees it.
	 *
	 * @param object|array $api One plugin or theme from the directory.
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
			'author'          => $readable( isset( $api['author'] ) && is_scalar( $api['author'] ) ? $api['author'] : '' ),
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
	 * Core reports both a refused request and the directory's own "not found"
	 * or "closed" as `plugins_api_failed` / `themes_api_failed`. Only a failed
	 * request carries the transport's message as error data.
	 *
	 * @param WP_Error $error From plugins_api() or themes_api().
	 * @param string   $slug  The slug asked for, if any.
	 * @param string   $type  'plugin' or 'theme'.
	 * @return WP_Error
	 */
	public static function error( WP_Error $error, $slug = '', $type = 'plugin' ) {
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
			'theme' === $type
				/* translators: %s: theme slug. */
				? sprintf( __( 'The WordPress.org directory has no theme with the slug "%s". Check the slug in its address, wordpress.org/themes/<slug>/.', 'saddle' ), $slug )
				/* translators: %s: plugin slug. */
				: sprintf( __( 'The WordPress.org directory has no plugin with the slug "%s". Use search-plugins with "search" to find the right slug.', 'saddle' ), $slug ),
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
			return new WP_Error( 'saddle_invalid_slug', __( 'A directory slug has only lowercase letters, digits and hyphens, as in wordpress.org/plugins/<slug>/ or wordpress.org/themes/<slug>/.', 'saddle' ), array( 'status' => 400 ) );
		}
		return $slug;
	}
}
