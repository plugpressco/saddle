<?php
/**
 * The pages of the Saddle admin: Core's three, and the modules that join them.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One list of every Saddle admin page, so one code path draws them all.
 *
 * Core has four pages: Home, Connections, Context and Settings. A sibling plugin
 * (Analytics, SEO, CRM) joins through the `saddle_modules` filter under the
 * same key it uses for `saddle_integrations`, and gets a submenu page at
 * `admin.php?page=saddle-{key}` with Core's frame around its content. A
 * sibling detects Core with `class_exists( 'Saddle_Modules' )`, never with a
 * version number, and keeps its own top-level menu when Core is absent.
 *
 * The descriptor is data only here. The callables the plan adds later
 * (status, setup, settings) are resolved server-side when they arrive, so the
 * browser never receives one.
 */
class Saddle_Modules {

	/**
	 * Keys a module may not take: Core's own pages.
	 */
	const RESERVED = array( 'home', 'connections', 'context', 'settings' );

	/**
	 * Core's pages, in menu order around the modules.
	 *
	 * Context was Settings → Guidance until Fahim's 2026-09-30 review ("guidance
	 * page make it context page"): what every connected app knows about the
	 * site is a page of its own, not a tab of the rarely-used Settings.
	 *
	 * @return array<string,array>
	 */
	public static function core_areas() {
		return array(
			'home'        => array(
				'slug'  => 'saddle',
				'title' => __( 'Home', 'saddle' ),
				'tabs'  => array(
					'overview' => __( 'Overview', 'saddle' ),
					'activity' => __( 'Activity', 'saddle' ),
				),
			),
			'connections' => array(
				'slug'  => 'saddle-connections',
				'title' => __( 'Connections', 'saddle' ),
				'tabs'  => array(
					'apps'        => __( 'Apps', 'saddle' ),
					'permissions' => __( 'Permissions', 'saddle' ),
				),
			),
			'context'     => array(
				'slug'  => 'saddle-context',
				'title' => __( 'Context', 'saddle' ),
				'tabs'  => array(
					'overview' => __( 'Context', 'saddle' ),
				),
			),
			'settings'    => array(
				'slug'  => 'saddle-settings',
				'title' => __( 'Settings', 'saddle' ),
				'tabs'  => array(
					'general' => __( 'General', 'saddle' ),
				),
			),
		);
	}

	/**
	 * Every registered module, validated and ordered.
	 *
	 * @return array<string,array>
	 */
	public static function modules() {
		/**
		 * Register a module: a sibling plugin with a page under Saddle.
		 *
		 * @param array $modules Descriptors keyed by the module's integration key.
		 */
		$raw     = apply_filters( 'saddle_modules', array() );
		$modules = array();

		foreach ( is_array( $raw ) ? $raw : array() as $key => $module ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || in_array( $key, self::RESERVED, true ) || ! is_array( $module ) || empty( $module['title'] ) ) {
				continue;
			}

			$tabs = array();
			foreach ( isset( $module['tabs'] ) && is_array( $module['tabs'] ) ? $module['tabs'] : array() as $tab => $label ) {
				$tab = sanitize_key( (string) $tab );
				if ( '' !== $tab && is_string( $label ) && '' !== $label ) {
					$tabs[ $tab ] = $label;
				}
			}

			$modules[ $key ] = array(
				'slug'       => 'saddle-' . $key,
				'title'      => (string) $module['title'],
				'product'    => isset( $module['product'] ) ? (string) $module['product'] : '',
				'version'    => isset( $module['version'] ) ? (string) $module['version'] : '',
				'summary'    => isset( $module['summary'] ) ? (string) $module['summary'] : '',
				'order'      => isset( $module['order'] ) ? (int) $module['order'] : 50,
				'nav'        => ! isset( $module['nav'] ) || (bool) $module['nav'],
				'capability' => isset( $module['capability'] ) && is_string( $module['capability'] ) && '' !== $module['capability'] ? $module['capability'] : 'manage_options',
				'tabs'       => $tabs ? $tabs : array( 'overview' => __( 'Overview', 'saddle' ) ),
				'script'     => isset( $module['script'] ) ? sanitize_key( (string) $module['script'] ) : '',
				'content'    => isset( $module['content'] ) && 'mount' === $module['content'] ? 'mount' : 'screens',
			);
		}

		uksort(
			$modules,
			static function ( $a, $b ) use ( $modules ) {
				return $modules[ $a ]['order'] === $modules[ $b ]['order'] ? strcmp( $a, $b ) : $modules[ $a ]['order'] - $modules[ $b ]['order'];
			}
		);

		return $modules;
	}

	/**
	 * Every page, in menu order: Home, the modules, Connections, Context,
	 * Settings.
	 *
	 * @return array<string,array> Keyed by area; each has `slug`, `title`,
	 *                             `tabs`, `nav`, `capability`, `module`.
	 */
	public static function areas() {
		$core  = self::core_areas();
		$areas = array( 'home' => $core['home'] );

		foreach ( self::modules() as $key => $module ) {
			$areas[ $key ] = $module;
		}

		$areas['connections'] = $core['connections'];
		$areas['context']     = $core['context'];
		$areas['settings']    = $core['settings'];

		foreach ( $areas as $key => $area ) {
			$areas[ $key ] = array_merge(
				array(
					'nav'        => true,
					'capability' => 'manage_options',
					'module'     => ! isset( $core[ $key ] ),
				),
				$area
			);
		}

		return $areas;
	}

	/**
	 * The area a page slug belongs to, or '' when it is not a Saddle page.
	 *
	 * @param string $page Page slug from `admin.php?page=`.
	 * @return string
	 */
	public static function area_for_page( $page ) {
		foreach ( self::areas() as $key => $area ) {
			if ( $area['slug'] === $page ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * A tab of an area. An unknown tab falls back to the first one, never an
	 * error, so an old or mistyped link still lands somewhere.
	 *
	 * @param string $area Area key.
	 * @param string $tab  Requested tab.
	 * @return string
	 */
	public static function resolve_tab( $area, $tab ) {
		$areas = self::areas();
		if ( ! isset( $areas[ $area ] ) ) {
			return '';
		}

		$tabs = $areas[ $area ]['tabs'];

		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	/**
	 * The admin URL of an area, and of a tab in it. The first tab has no
	 * `&tab=`, so each page has one canonical address.
	 *
	 * @param string $area Area key.
	 * @param string $tab  Tab key.
	 * @return string
	 */
	public static function url( $area, $tab = '' ) {
		$areas = self::areas();
		if ( ! isset( $areas[ $area ] ) ) {
			return admin_url( 'admin.php?page=saddle' );
		}

		$args = array( 'page' => $areas[ $area ]['slug'] );
		if ( '' !== $tab && array_key_first( $areas[ $area ]['tabs'] ) !== $tab && isset( $areas[ $area ]['tabs'][ $tab ] ) ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
