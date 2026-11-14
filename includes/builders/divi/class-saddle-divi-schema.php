<?php
/**
 * Module knowledge, distilled from module.json — the ecosystem's contract.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Discovers Divi 5 modules from their module.json files and distills each to
 * an agent-sized schema.
 *
 * module.json is the single source of truth shared by Divi core (109 files
 * inside the theme) and every ecosystem plugin (shipped as
 * `<plugin>/modules-json/<module>/module.json`, e.g. DiviTorque). Reading the
 * files from disk — rather than the block registry — matters because Divi
 * gates module registration behind wp_should_load_block_editor_scripts_and_styles(),
 * so the registry is typically EMPTY during MCP REST requests.
 *
 * A raw module.json is thousands of lines of settings-panel plumbing; agents
 * need one screenful: which content fields exist and their shape, what the
 * module may contain, and which design (decoration) options each element
 * supports.
 */
class Saddle_Divi_Schema {

	/**
	 * Transient holding the type → module.json path index.
	 */
	const INDEX_TRANSIENT = 'saddle_divi_module_index';

	/**
	 * Drop the cached module index so the next read rebuilds it.
	 */
	public static function flush_index() {
		delete_transient( self::INDEX_TRANSIENT );
	}

	/**
	 * Directories scanned for `<module>/module.json`, one level deep.
	 *
	 * @return string[]
	 */
	public static function scan_dirs() {
		$dirs = array();

		// Divi core ships its module.json files inside the theme.
		$template = get_template_directory();
		if ( $template ) {
			$components = $template . '/includes/builder-5/visual-builder/packages/module-library/src/components';
			$dirs[]     = $components;

			// Divi keeps its WooCommerce modules one level further down
			// (components/woocommerce/<module>/module.json) and registers them
			// only while WooCommerce is active. The one-level scan never found
			// them, so an agent on a Divi shop could not place a product grid.
			if ( class_exists( 'Saddle_WC' ) && Saddle_WC::is_active() ) {
				$dirs[] = $components . '/woocommerce';
			}
		}

		// Ecosystem plugins ship built copies at <plugin>/modules-json/ —
		// but only ACTIVE plugins register their modules with Divi, so an
		// inactive plugin's catalog entries would author modules the page
		// can't render. Filter the on-disk glob down to active plugin folders.
		$active_folders = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $basename ) {
			$active_folders[ strtok( (string) $basename, '/' ) ] = true;
		}
		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $basename ) {
				$active_folders[ strtok( (string) $basename, '/' ) ] = true;
			}
		}

		$plugin_dirs = glob( WP_PLUGIN_DIR . '/*/modules-json', GLOB_ONLYDIR );
		foreach ( is_array( $plugin_dirs ) ? $plugin_dirs : array() as $dir ) {
			if ( isset( $active_folders[ basename( dirname( $dir ) ) ] ) ) {
				$dirs[] = $dir;
			}
		}

		/**
		 * Filter the directories scanned for Divi module.json files.
		 *
		 * @param string[] $dirs Directories containing `<module>/module.json`.
		 */
		return (array) apply_filters( 'saddle_divi_module_json_dirs', $dirs );
	}

	/**
	 * Read one of Divi's bundled module.json files from disk.
	 *
	 * These are read-only package assets shipped inside the active theme/
	 * plugins (never uploads, never remote), so a direct filesystem read is
	 * correct and fast — WP_Filesystem/wp_remote_get exist for user content
	 * and network resources, neither of which applies here.
	 *
	 * @param string $file Absolute path to a bundled JSON file.
	 * @return string File contents, or '' if unreadable.
	 */
	private static function read_json_file( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a bundled read-only package asset (see docblock); a missing/renamed module.json across Divi versions falls back to ''.
		$contents = @file_get_contents( $file );
		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Map of module type (e.g. 'divi/text') → module.json path.
	 *
	 * Cached in a transient keyed by the Divi version AND the active plugin
	 * set — scan_dirs() only walks active plugins, so activating or
	 * deactivating a module pack must bust the cache too (a version-only key
	 * served stale catalogs until the TTL). A static cache serves repeat
	 * calls within a request.
	 *
	 * @param bool $rebuild Force a rescan.
	 * @return array<string,string>
	 */
	public static function index( $rebuild = false ) {
		static $memo = null;
		if ( null !== $memo && ! $rebuild ) {
			return $memo;
		}

		$version = (string) Saddle_Divi::version();
		$plugins = md5( implode( ',', (array) get_option( 'active_plugins', array() ) ) );
		$cached  = $rebuild ? false : get_transient( self::INDEX_TRANSIENT );
		if ( is_array( $cached )
			&& isset( $cached['v'], $cached['p'] )
			&& $cached['v'] === $version
			&& $cached['p'] === $plugins
			&& ! empty( $cached['map'] ) ) {
			$memo = $cached['map'];
			return $memo;
		}

		$map = array();
		foreach ( self::scan_dirs() as $dir ) {
			$files = glob( rtrim( $dir, '/' ) . '/*/module.json' );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				$json = json_decode( self::read_json_file( $file ), true );
				if ( is_array( $json ) && ! empty( $json['name'] ) && is_string( $json['name'] ) ) {
					$map[ $json['name'] ] = $file;
				}
			}
		}

		set_transient(
			self::INDEX_TRANSIENT,
			array(
				'v'   => $version,
				'p'   => $plugins,
				'map' => $map,
			),
			12 * HOUR_IN_SECONDS
		);
		$memo = $map;
		return $memo;
	}

	/**
	 * The catalog for divi-list-modules: every discovered module with its
	 * title, purpose, group and what it may hold (Saddle_Divi_Catalog).
	 *
	 * @return array[]
	 */
	public static function catalog() {
		return Saddle_Divi_Catalog::modules();
	}

	/**
	 * A module's parsed module.json, or null when it has none on this site.
	 * Memoized per file for the request.
	 *
	 * @param string $type Module type.
	 * @return array|null
	 */
	public static function module_json( $type ) {
		static $memo = array();
		$index       = self::index();
		if ( ! isset( $index[ $type ] ) ) {
			return null;
		}
		$file = (string) $index[ $type ];
		if ( ! array_key_exists( $file, $memo ) ) {
			$json          = json_decode( self::read_json_file( $file ), true );
			$memo[ $file ] = is_array( $json ) ? $json : null;
		}
		return $memo[ $file ];
	}

	/**
	 * Divi's default render attributes for a module (the
	 * module-default-render-attributes.json beside its module.json), or an
	 * empty array. Module packs usually ship none.
	 *
	 * @param string $type Module type.
	 * @return array
	 */
	public static function render_defaults( $type ) {
		static $memo = array();
		$index       = self::index();
		if ( ! isset( $index[ $type ] ) ) {
			return array();
		}
		$file = dirname( (string) $index[ $type ] ) . '/module-default-render-attributes.json';
		if ( ! array_key_exists( $file, $memo ) ) {
			$json          = file_exists( $file ) ? json_decode( self::read_json_file( $file ), true ) : null;
			$memo[ $file ] = is_array( $json ) ? $json : array();
		}
		return $memo[ $file ];
	}

	/**
	 * Which third-party module packs (plugins shipping Divi 5 modules) are
	 * installed, and how many modules each contributes — derived from the same
	 * discovery index. This lets the agent context tell an agent that rich,
	 * purpose-built modules exist (a carousel, a pricing table, an info box…)
	 * so it reaches for them instead of hand-composing from core primitives —
	 * the difference between a designed element and generic stacked defaults.
	 *
	 * @return array[] Each: slug, name, count. Highest count first.
	 */
	public static function module_packs() {
		$plugin_root = defined( 'WP_PLUGIN_DIR' ) ? wp_normalize_path( WP_PLUGIN_DIR ) : '';
		if ( '' === $plugin_root ) {
			return array();
		}

		$counts = array();
		foreach ( self::index() as $file ) {
			$file = wp_normalize_path( (string) $file );
			if ( 0 !== strpos( $file, $plugin_root . '/' ) ) {
				continue; // Theme (core Divi) modules, not a pack.
			}
			$slug = strtok( ltrim( substr( $file, strlen( $plugin_root ) ), '/' ), '/' );
			if ( $slug ) {
				$counts[ $slug ] = isset( $counts[ $slug ] ) ? $counts[ $slug ] + 1 : 1;
			}
		}

		$packs = array();
		foreach ( $counts as $slug => $count ) {
			$packs[] = array(
				'slug'  => $slug,
				'name'  => ucwords( str_replace( '-', ' ', $slug ) ),
				'count' => $count,
			);
		}
		usort(
			$packs,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		return $packs;
	}

	/**
	 * Distill one module's schema for an agent.
	 *
	 * @param string $type Module type, e.g. 'divi/button'.
	 * @return array|WP_Error
	 */
	public static function describe( $type ) {
		$index = self::index();
		if ( ! isset( $index[ $type ] ) ) {
			return new WP_Error(
				'saddle_unknown_module',
				sprintf( 'No module.json found for "%s". Use divi-list-modules to see what exists on this site.', $type )
			);
		}

		$json = self::module_json( $type );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'saddle_bad_module_json', sprintf( 'The module.json for "%s" could not be parsed.', $type ) );
		}
		$render = self::render_defaults( $type );

		$attributes = array();
		foreach ( ( isset( $json['attributes'] ) && is_array( $json['attributes'] ) ? $json['attributes'] : array() ) as $name => $attr ) {
			if ( ! is_array( $attr ) ) {
				continue;
			}

			$settings       = isset( $attr['settings'] ) && is_array( $attr['settings'] ) ? $attr['settings'] : array();
			$content_fields = isset( $settings['innerContent'] ) ? self::collect_subnames( $settings['innerContent'] ) : null;

			// No sub-names, yet Divi stores an object: an icon is
			// {unicode, type, weight}, a map {address, zoom, lat, lng}.
			// Calling those strings had agents write values Divi ignores.
			if ( array() === $content_fields ) {
				$default        = isset( $render[ $name ]['innerContent']['desktop']['value'] ) ? $render[ $name ]['innerContent']['desktop']['value'] : null;
				$content_fields = self::object_content_fields( $settings['innerContent'], $default );
			}

			// Some compound VB controls (e.g. the button link group) don't
			// declare plain subNames; fill in the known value shape from the
			// element type so agents aren't under-informed.
			if ( null !== $content_fields && isset( $attr['elementType'] ) && 'button' === $attr['elementType'] ) {
				$content_fields = array_values( array_unique( array_merge( $content_fields, array( 'text', 'linkUrl' ) ) ) );
			}

			// Same for images: module.json's subNames omit `alt` (it rides a
			// syncImageData prop instead), yet the a11y lint DEMANDS alt at
			// image.innerContent.desktop.value.alt — without this merge the
			// schema under-reports the exact field the linter requires and
			// an agent cannot discover how to satisfy the finding.
			if ( null !== $content_fields && isset( $attr['elementType'] ) && in_array( $attr['elementType'], array( 'image', 'imageLink' ), true ) ) {
				$content_fields = array_values( array_unique( array_merge( $content_fields, array( 'alt' ) ) ) );
			}
			$entry = array(
				'name'        => (string) $name,
				'elementType' => isset( $attr['elementType'] ) ? (string) $attr['elementType'] : null,
			);

			// Verified live: a styled button renders as ghost/outline until the
			// custom-button toggle is on. Surface it where agents will read it.
			if ( isset( $attr['elementType'] ) && 'button' === $attr['elementType'] ) {
				$entry['style_note'] = sprintf( 'To render a FILLED button, also set attrs { "%s.decoration.button.desktop.value.enable": "on" } — background/font styling alone produces a ghost/outline button.', $name );
			}

			if ( null !== $content_fields ) {
				$entry['content']       = true;
				$entry['content_shape'] = $content_fields ? 'object' : 'string';
				if ( $content_fields ) {
					$entry['content_fields'] = $content_fields;
					$entry['write_as']       = sprintf( 'fields.%s = { %s }', $name, implode( ', ', $content_fields ) );
				} else {
					$entry['write_as'] = sprintf( 'fields.%s = "…"', $name );
				}
			}

			if ( isset( $settings['decoration'] ) && is_array( $settings['decoration'] ) ) {
				// A group the element hides (the standalone button's spacing
				// and box shadow) is not offered, so the echo warns on it.
				$hidden               = Saddle_Divi_Style_Schema::hidden_groups( $settings['decoration'] );
				$entry['decorations'] = array_values( array_diff( array_map( 'strval', array_keys( $settings['decoration'] ) ), $hidden ) );
			}
			if ( isset( $settings['advanced'] ) && is_array( $settings['advanced'] ) ) {
				$entry['advanced_options'] = array_keys( $settings['advanced'] );
			}

			$attributes[] = $entry;
		}

		$children = Saddle_Divi_Catalog::children( $type, $json );
		return array(
			'type'       => $type,
			'title'      => isset( $json['title'] ) ? (string) $json['title'] : $type,
			'container'  => (bool) $children,
			'children'   => $children,
			'child'      => isset( $json['category'] ) && 'child-module' === $json['category'],
			'attributes' => $attributes,
			'usage'      => __( 'Set content via "fields" as shown per attribute (SaddleWP wraps values in the correct envelope). "fields" writes the desktop value; different content on a tablet or phone goes in raw "attrs", e.g. { "<attr>.innerContent.phone.value": … }. Styling via raw "attrs" uses canonical paths like <attr>.decoration.<option> with {"desktop":{"value":…}} breakpoints — omit styling to inherit the site’s design.', 'saddle' ),
		);
	}

	/**
	 * Top-level value keys per decoration group (Saddle_Divi_Style::FIELDS).
	 * Kept under this name for the echo.
	 */
	const STYLE_FIELDS = Saddle_Divi_Style::FIELDS;

	/**
	 * Groups whose field list is closed (Saddle_Divi_Style::CLOSED_GROUPS).
	 * Kept under this name for the echo.
	 */
	const CLOSED_FIELD_GROUPS = Saddle_Divi_Style::CLOSED_GROUPS;

	/**
	 * The styling schema for one module (Saddle_Divi_Style_Schema): the
	 * decoration groups and advanced options it supports, each with the
	 * exact path to set it. Kept apart from describe() so both stay small.
	 *
	 * @param string $type  Module type, e.g. 'divi/text'.
	 * @param string $group Optional group to expand: "spacing", or
	 *                      "title.font" for one attribute's group.
	 * @return array|WP_Error
	 */
	public static function style( $type, $group = '' ) {
		return Saddle_Divi_Style_Schema::build( (string) $type, (string) $group );
	}

	/**
	 * The canonical settable path per decoration group of a module, keyed
	 * "attr.group" (e.g. "title.font" → "title.decoration.font.font.desktop
	 * .value"). The echo reuses this already-derived truth to judge whether
	 * a written payload nests where Divi actually reads. Empty map when the
	 * module has no module.json. Memoized per request.
	 *
	 * @param string $type Module type.
	 * @return array<string,string>
	 */
	public static function group_paths( $type ) {
		static $memo = array();
		$index       = self::index();
		$key         = $type . '|' . ( isset( $index[ $type ] ) ? (string) $index[ $type ] : '' );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$map   = array();
		$style = self::style( $type );
		if ( ! is_wp_error( $style ) ) {
			foreach ( $style['style_groups'] as $sg ) {
				$map[ $sg['attr'] . '.' . $sg['group'] ] = $sg['path'];
			}
		}

		$memo[ $key ] = $map;
		return $map;
	}

	/**
	 * The object keys of a content value that module.json does not spell
	 * out with sub-names: the keys of Divi's own default value, plus what
	 * the content control itself stores (an icon picker {unicode, type,
	 * weight}, a map {address, zoom, lat, lng}, a link group {url, target}).
	 * Empty when the value is a plain string.
	 *
	 * @param mixed $node          settings.innerContent subtree.
	 * @param mixed $default_value The default render value of that content.
	 * @return string[]
	 */
	private static function object_content_fields( $node, $default_value ) {
		$fields = is_array( $default_value ) && $default_value && ! array_is_list( $default_value ) ? array_map( 'strval', array_keys( $default_value ) ) : array();
		$shapes = array(
			'divi/icon-picker' => array( 'unicode', 'type', 'weight' ),
			'divi/map'         => array( 'address', 'zoom', 'lat', 'lng' ),
		);
		$walk   = static function ( $n ) use ( &$walk, &$fields, $shapes ) {
			if ( ! is_array( $n ) ) {
				return;
			}
			$name = isset( $n['component']['name'] ) && is_string( $n['component']['name'] ) ? $n['component']['name'] : '';
			if ( isset( $shapes[ $name ] ) ) {
				$fields = array_merge( $fields, $shapes[ $name ] );
			} elseif ( 'divi/link' === $name && isset( $n['component']['type'] ) && 'group' === $n['component']['type'] ) {
				$link   = isset( $n['component']['props']['fields'] ) && is_array( $n['component']['props']['fields'] ) ? array_keys( $n['component']['props']['fields'] ) : array( 'url', 'target' );
				$fields = array_merge( $fields, array_map( 'strval', $link ) );
			}
			if ( isset( $n['item'] ) ) {
				$walk( $n['item'] );
			}
			foreach ( array( 'items', 'groups' ) as $key ) {
				foreach ( isset( $n[ $key ] ) && is_array( $n[ $key ] ) ? $n[ $key ] : array() as $child ) {
					$walk( $child );
				}
			}
		};
		$walk( $node );
		return array_values( array_unique( $fields ) );
	}

	/**
	 * Collect every innerContent subName declared in the settings block —
	 * these are the object keys of a composite content value (e.g. button:
	 * text, linkUrl). No subNames means the value is a plain string.
	 *
	 * @param mixed $node settings.innerContent subtree.
	 * @return string[]
	 */
	private static function collect_subnames( $node ) {
		$found = array();
		$walk  = static function ( $n ) use ( &$walk, &$found ) {
			if ( ! is_array( $n ) ) {
				return;
			}
			if ( isset( $n['subName'] ) && is_string( $n['subName'] ) ) {
				$found[] = $n['subName'];
			}
			foreach ( $n as $child ) {
				$walk( $child );
			}
		};
		$walk( $node );
		return array_values( array_unique( $found ) );
	}
}
