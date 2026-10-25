<?php
/**
 * What Saddle_Insight sends: the site block, the family's plugins and the
 * site's random id. See Saddle_Insight for when.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the bodies. Reads only; sends nothing.
 */
class Saddle_Insight_Payload {

	/**
	 * A random id for the site, shared by every PlugPress plugin's client so
	 * one site is one row. Never derived from the address.
	 */
	const SITE_ID_OPTION = 'plugpress_insight_site_id';

	/**
	 * The plugins Saddle speaks for, by folder name.
	 */
	const FAMILY = array( 'saddle', 'saddle-analytics', 'saddle-rank', 'saddle-blocks', 'saddle-pro' );

	/**
	 * At most this many plugins in the plugins list.
	 */
	const MAX_PLUGINS = 200;

	/**
	 * The family plugins installed here, with their versions.
	 *
	 * @return array[] Each { slug, version, active, file, name }.
	 */
	public static function products() {
		$products = array();
		foreach ( self::installed() as $file => $data ) {
			$slug = dirname( $file );
			if ( in_array( $slug, self::FAMILY, true ) ) {
				$products[] = array(
					'slug'    => $slug,
					'version' => (string) $data['Version'],
					'active'  => is_plugin_active( $file ),
					'file'    => $file,
					'name'    => (string) $data['Name'],
				);
			}
		}
		return $products;
	}

	/**
	 * One family plugin by its file, or null.
	 *
	 * @param string $plugin Plugin file.
	 * @return array|null
	 */
	public static function product( $plugin ) {
		foreach ( self::products() as $product ) {
			if ( $product['file'] === $plugin ) {
				return $product;
			}
		}
		return null;
	}

	/**
	 * The optin and update body.
	 *
	 * @param array $parts The parts left on.
	 * @return array
	 */
	public static function register_body( array $parts ) {
		return array(
			'site'     => self::site_block( $parts ),
			'products' => array_map(
				static function ( $product ) {
					return array_intersect_key( $product, array_flip( array( 'slug', 'version', 'active' ) ) );
				},
				self::products()
			),
		);
	}

	/**
	 * The site block: always the versions, language and timezone; the address,
	 * the plugins list and the admin's name and email only with their part on.
	 *
	 * @param array $parts The parts left on.
	 * @return array
	 */
	public static function site_block( array $parts ) {
		$site = array(
			'id'          => self::site_id(),
			'wp'          => (string) get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'language'    => get_locale(),
			'timezone'    => (string) ( get_option( 'timezone_string' ) ? get_option( 'timezone_string' ) : wp_timezone_string() ),
			'multisite'   => is_multisite(),
			'environment' => wp_get_environment_type(),
		);
		if ( ! empty( $parts['site'] ) ) {
			$site['url']   = home_url( '/' );
			$site['title'] = get_bloginfo( 'name' );
		}
		if ( ! empty( $parts['plugins'] ) ) {
			$theme           = wp_get_theme();
			$site['theme']   = array(
				'slug'    => $theme->get_stylesheet(),
				'version' => (string) $theme->get( 'Version' ),
			);
			$site['plugins'] = array();
			foreach ( array_slice( self::installed(), 0, self::MAX_PLUGINS, true ) as $file => $data ) {
				$site['plugins'][] = array(
					'slug'    => false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ),
					'version' => (string) $data['Version'],
					'active'  => is_plugin_active( $file ),
				);
			}
		}
		if ( ! empty( $parts['profile'] ) ) {
			$user          = wp_get_current_user();
			$site['admin'] = array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
			);
		}
		return $site;
	}

	/**
	 * The site's random id, made once.
	 *
	 * @return string
	 */
	public static function site_id() {
		$id = get_option( self::SITE_ID_OPTION, '' );
		if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) {
			$id = wp_generate_uuid4();
			update_option( self::SITE_ID_OPTION, $id, false );
		}
		return $id;
	}

	/**
	 * Every installed plugin, as get_plugins() lists them.
	 *
	 * @return array
	 */
	private static function installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins();
	}
}
