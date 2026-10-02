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
 * Core has five pages: Dashboard, AI apps, Services, Context and Settings. A sibling plugin
 * (Analytics, SEO, CRM) joins through the `saddle_modules` filter under the
 * same key it uses for `saddle_integrations`, and gets a submenu page at
 * `admin.php?page=saddle-{key}` with Core's frame around its content. A
 * sibling detects Core with `class_exists( 'Saddle_Modules' )`, never with a
 * version number, and keeps its own top-level menu when Core is absent.
 *
 * A module ships no tokens, no accent file and no chrome; Core paints it.
 * Use the `ui` prop, never a copy of the kit. Pages outside the frame call
 * `enqueue_palette()`. (The family rules: admin/DESIGN-ALIGNMENT.md, "The
 * family".)
 *
 * A descriptor may carry three callables: `status`, `setup` and `settings`.
 * They are resolved here, server-side, and the browser never receives one. A
 * callable that throws, or returns the wrong shape, is treated as absent, so
 * a sibling's bug never breaks a page or a tool.
 */
class Saddle_Modules {

	/**
	 * Keys a module may not take: Core's own pages.
	 */
	const RESERVED = array( 'home', 'connections', 'services', 'context', 'settings' );

	/**
	 * Core's pages, in menu order around the modules.
	 *
	 * Named by Fahim on 2026-10-01 (#285): Dashboard, AI apps, Context and
	 * Settings, one job each. On 2026-10-02 (#309) Dashboard became Home, and
	 * its Activity tab became Home's own feed, so no core page has tabs; an
	 * old `&tab=activity` address lands on Home. The area keys and slugs
	 * are older than the names (`home`, `connections`) and stay, because agent
	 * tools and browser hooks carry them. Access is chosen per app on the AI
	 * apps page, so there is no Permissions page or tab any more.
	 *
	 * @return array<string,array>
	 */
	public static function core_areas() {
		return array(
			'home'        => array(
				'slug'  => 'saddle',
				'title' => __( 'Home', 'saddle' ),
				'tabs'  => array(
					'overview' => __( 'Home', 'saddle' ),
				),
			),
			'connections' => array(
				'slug'  => 'saddle-connections',
				'title' => __( 'AI apps', 'saddle' ),
				'tabs'  => array(
					'apps' => __( 'AI apps', 'saddle' ),
				),
			),
			'services'    => array(
				'slug'  => 'saddle-services',
				'title' => __( 'Services', 'saddle' ),
				'tabs'  => array(
					'overview' => __( 'Services', 'saddle' ),
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

			$tabs      = $tabs ? $tabs : array( 'overview' => __( 'Overview', 'saddle' ) );
			$callables = array();
			foreach ( array( 'status', 'setup', 'settings' ) as $name ) {
				if ( isset( $module[ $name ] ) && is_callable( $module[ $name ] ) ) {
					$callables[ $name ] = $module[ $name ];
				}
			}

			// A module whose schema has fields gets a Settings tab, unless it
			// draws one of its own.
			if ( isset( $callables['settings'] ) && ! isset( $tabs['settings'] ) ) {
				$called = Saddle_Settings_Registry::safely( $callables['settings'] );
				if ( $called['ok'] && Saddle_Settings_Registry::normalize( $called['value'] ) ) {
					$tabs['settings'] = __( 'Settings', 'saddle' );
				}
			}

			$modules[ $key ] = $callables + array(
				'slug'       => 'saddle-' . $key,
				'title'      => (string) $module['title'],
				'product'    => isset( $module['product'] ) ? (string) $module['product'] : '',
				'version'    => isset( $module['version'] ) ? (string) $module['version'] : '',
				'summary'    => isset( $module['summary'] ) ? (string) $module['summary'] : '',
				'order'      => isset( $module['order'] ) ? (int) $module['order'] : 50,
				'nav'        => ! isset( $module['nav'] ) || (bool) $module['nav'],
				'capability' => isset( $module['capability'] ) && is_string( $module['capability'] ) && '' !== $module['capability'] ? $module['capability'] : 'manage_options',
				'tabs'       => $tabs,
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
	 * Every page, in menu order: Dashboard, the modules, AI apps, Services,
	 * Context, Settings.
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
		$areas['services']    = $core['services'];
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
	 * Whether the body-class filter for the palette is already hooked.
	 *
	 * @var bool
	 */
	private static $palette_classes = false;

	/**
	 * Register the tokens-only palette stylesheet (`saddle-palette`).
	 *
	 * Hooked on `admin_enqueue_scripts` at priority 1, so it exists on every
	 * admin screen and a sibling can enqueue it at any later point. It only
	 * registers: nothing is sent to a page until `enqueue_palette()` is called.
	 *
	 * @return bool True when the stylesheet is registered; false when the
	 *              build is missing.
	 */
	public static function register_palette() {
		if ( wp_style_is( 'saddle-palette', 'registered' ) ) {
			return true;
		}

		$file = SADDLE_DIR . 'admin/build/palette.css';
		if ( ! file_exists( $file ) ) {
			return false;
		}

		wp_register_style(
			'saddle-palette',
			SADDLE_URL . 'admin/build/palette.css',
			array(),
			SADDLE_VERSION . '.' . filemtime( $file )
		);
		return true;
	}

	/**
	 * Give a page outside the Saddle frame Saddle's palette: every `--pp-*`,
	 * `--saddle-brand*`, `--s-*` and `--saddle-chart-*` token, and nothing
	 * else (no component, frame or wp-admin rules). The tokens sit under
	 * `body.saddle-palette` alone and inherit from the body; the page gets no
	 * `pp-scope` or `pp-app` class, so another plugin's kit widgets on the
	 * same screen keep their own palette.
	 *
	 * Safe to call on `admin_enqueue_scripts` on any admin screen. A sibling
	 * probes it with `method_exists( 'Saddle_Modules', 'enqueue_palette' )`.
	 *
	 * @return bool False when the build is missing, so a caller can fall back
	 *              to its own plain colours.
	 */
	public static function enqueue_palette() {
		if ( ! self::register_palette() ) {
			return false;
		}

		wp_enqueue_style( 'saddle-palette' );

		if ( ! self::$palette_classes ) {
			self::$palette_classes = true;
			add_filter(
				'admin_body_class',
				static function ( $classes ) {
					return $classes . ' saddle-palette';
				}
			);
		}
		return true;
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

	/**
	 * A module's status: its state and one line, or null when it has none or
	 * the callable misbehaves.
	 *
	 * @param string $key Module key.
	 * @return array{state:string,line:string}|null
	 */
	public static function status( $key ) {
		$modules = self::modules();
		if ( ! isset( $modules[ $key ]['status'] ) ) {
			return null;
		}

		$called = Saddle_Settings_Registry::safely( $modules[ $key ]['status'] );
		$raw    = $called['value'];
		$states = array( 'ready', 'needs-setup', 'attention', 'off' );
		if ( ! $called['ok'] || ! is_array( $raw ) || ! isset( $raw['state'] ) || ! in_array( $raw['state'], $states, true ) ) {
			return null;
		}

		return array(
			'state' => $raw['state'],
			'line'  => isset( $raw['line'] ) && is_string( $raw['line'] ) ? wp_strip_all_tags( $raw['line'] ) : '',
		);
	}

	/**
	 * A module's setup tasks, each cleaned and its `tab` resolved to a URL.
	 * Null when there are none or the callable misbehaves.
	 *
	 * @param string $key Module key.
	 * @return array[]|null
	 */
	public static function setup( $key ) {
		$modules = self::modules();
		if ( ! isset( $modules[ $key ]['setup'] ) ) {
			return null;
		}

		$called = Saddle_Settings_Registry::safely( $modules[ $key ]['setup'] );
		if ( ! $called['ok'] || ! is_array( $called['value'] ) ) {
			return null;
		}

		$tasks = array();
		foreach ( $called['value'] as $task ) {
			if ( ! is_array( $task ) || empty( $task['id'] ) || ! is_scalar( $task['id'] ) || empty( $task['title'] ) || ! is_string( $task['title'] ) ) {
				continue;
			}

			$action = null;
			$raw    = isset( $task['action'] ) && is_array( $task['action'] ) ? $task['action'] : array();
			if ( ! empty( $raw['label'] ) && is_string( $raw['label'] ) ) {
				$url = '';
				if ( ! empty( $raw['url'] ) && is_string( $raw['url'] ) ) {
					$url = esc_url_raw( $raw['url'] );
				} elseif ( ! empty( $raw['tab'] ) && is_string( $raw['tab'] ) ) {
					$url = esc_url_raw( self::url( $key, sanitize_key( $raw['tab'] ) ) );
				}
				if ( '' !== $url ) {
					$action = array(
						'label'    => wp_strip_all_tags( $raw['label'] ),
						'url'      => $url,
						'external' => ! empty( $raw['external'] ),
					);
				}
			}

			$tasks[] = array(
				'id'      => sanitize_key( (string) $task['id'] ),
				'title'   => wp_strip_all_tags( $task['title'] ),
				'done'    => ! empty( $task['done'] ),
				'line'    => isset( $task['line'] ) && is_string( $task['line'] ) ? wp_strip_all_tags( $task['line'] ) : '',
				'waiting' => ! empty( $task['waiting'] ),
				'after'   => isset( $task['after'] ) && is_scalar( $task['after'] ) ? sanitize_key( (string) $task['after'] ) : '',
				'action'  => $action,
			);
		}

		return $tasks ? $tasks : null;
	}
}
