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
			$dirs[] = $template . '/includes/builder-5/visual-builder/packages/module-library/src/components';
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
	 * The light catalog for divi-list-modules: every discovered module with
	 * its title and structural role.
	 *
	 * @return array[]
	 */
	public static function catalog() {
		$catalog = array();
		foreach ( self::index() as $type => $file ) {
			$json = json_decode( self::read_json_file( $file ), true );
			if ( ! is_array( $json ) ) {
				continue;
			}
			$catalog[] = array(
				'type'      => $type,
				'title'     => isset( $json['title'] ) ? (string) $json['title'] : $type,
				'container' => ! empty( $json['childrenName'] ),
				'child'     => isset( $json['category'] ) && 'child-module' === $json['category'],
			);
		}
		usort(
			$catalog,
			static function ( $a, $b ) {
				return strcmp( $a['type'], $b['type'] );
			}
		);
		return $catalog;
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

		$json = json_decode( self::read_json_file( $index[ $type ] ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'saddle_bad_module_json', sprintf( 'The module.json for "%s" could not be parsed.', $type ) );
		}

		$attributes = array();
		foreach ( ( isset( $json['attributes'] ) && is_array( $json['attributes'] ) ? $json['attributes'] : array() ) as $name => $attr ) {
			if ( ! is_array( $attr ) ) {
				continue;
			}

			$settings       = isset( $attr['settings'] ) && is_array( $attr['settings'] ) ? $attr['settings'] : array();
			$content_fields = isset( $settings['innerContent'] ) ? self::collect_subnames( $settings['innerContent'] ) : null;

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
				$entry['decorations'] = array_keys( $settings['decoration'] );
			}
			if ( isset( $settings['advanced'] ) && is_array( $settings['advanced'] ) ) {
				$entry['advanced_options'] = array_keys( $settings['advanced'] );
			}

			$attributes[] = $entry;
		}

		return array(
			'type'       => $type,
			'title'      => isset( $json['title'] ) ? (string) $json['title'] : $type,
			'container'  => ! empty( $json['childrenName'] ),
			'children'   => isset( $json['childrenName'] ) ? (array) $json['childrenName'] : array(),
			'child'      => isset( $json['category'] ) && 'child-module' === $json['category'],
			'attributes' => $attributes,
			'usage'      => __( 'Set content via "fields" as shown per attribute (Saddle wraps values in the correct envelope). Styling via raw "attrs" uses canonical paths like <attr>.decoration.<option> with {"desktop":{"value":…}} breakpoints — omit styling to inherit the site’s design.', 'saddle' ),
		);
	}

	/**
	 * Common settable value fields per Divi 5 decoration group. module.json
	 * names the groups a module supports but not the value sub-fields inside
	 * each (those live in Divi's decoration components), so this curated map
	 * gives agents the field names and the path to set them — the exact thing
	 * that makes "style this module" workable without guessing.
	 */
	const STYLE_FIELDS = array(
		'background'  => array( 'color', 'enableColor', 'gradient', 'image', 'mask', 'pattern' ),
		'border'      => array( 'radius', 'styles' ),
		'spacing'     => array( 'margin', 'padding' ),
		'sizing'      => array( 'width', 'maxWidth', 'height', 'minHeight', 'maxHeight', 'alignment', 'flexType' ),
		'boxShadow'   => array( 'style', 'horizontal', 'vertical', 'blur', 'spread', 'color' ),
		'filters'     => array( 'hueRotate', 'saturate', 'brightness', 'contrast', 'invert', 'sepia', 'opacity', 'blur' ),
		'transform'   => array( 'scale', 'translate', 'rotate', 'skew', 'origin' ),
		'font'        => array( 'family', 'weight', 'style', 'lineColor', 'color', 'size', 'letterSpacing', 'lineHeight', 'textAlign', 'headingLevel' ),
		'headingFont' => array( 'family', 'weight', 'style', 'color', 'size', 'letterSpacing', 'lineHeight', 'textAlign' ),
		'bodyFont'    => array( 'family', 'weight', 'style', 'color', 'size', 'letterSpacing', 'lineHeight' ),
		'position'    => array( 'mode', 'origin', 'offset' ),
		'overflow'    => array( 'x', 'y' ),
		'zIndex'      => array( 'value' ),
		'animation'   => array( 'style', 'direction', 'duration', 'delay', 'intensity' ),
		'transition'  => array( 'duration', 'delay', 'speedCurve' ),
		'layout'      => array( 'display', 'flexDirection', 'justifyContent', 'alignItems', 'gap', 'rowGap', 'columnGap', 'gridColumnCount', 'gridColumnWidths' ),
		// The fill toggle Divi renders ghost buttons without (live-verified);
		// icon fields mined from Divi's own default render-attributes.
		'button'      => array( 'enable', 'alignment', 'icon' ),
		'icon'        => array( 'color', 'unicode', 'type', 'weight', 'size', 'useSize', 'show' ),
	);

	/**
	 * Groups whose field list above is provably CLOSED (mined union across
	 * every Divi 5.9 render default equals the curated set). Only these are
	 * safe for the echo to validate leaf fields against — everywhere else
	 * the curated list documents, it doesn't judge.
	 */
	const CLOSED_FIELD_GROUPS = array( 'spacing' );

	/**
	 * Example VALUE SHAPES per decoration group — what a correct write looks
	 * like, not just which fields exist. This is what stops the two classic
	 * silent no-ops: guessing a scalar where Divi wants an object (border
	 * radius is {sync, topLeft, …}, spacing takes {top, bottom, …} objects)
	 * and omitting the button enable toggle. Curated deliberately — Divi's
	 * own render defaults are sparse and full of internal $variable(…)$
	 * tokens that would mis-teach agents; a real mined default is only the
	 * fallback for groups not listed here.
	 */
	const STYLE_EXAMPLES = array(
		'background'  => array( 'color' => '#0f172a' ),
		'spacing'     => array(
			'padding' => array(
				'top'    => '96px',
				'bottom' => '96px',
			),
		),
		'border'      => array(
			'radius' => array(
				'sync'        => 'on',
				'topLeft'     => '8px',
				'topRight'    => '8px',
				'bottomLeft'  => '8px',
				'bottomRight' => '8px',
			),
		),
		'sizing'      => array(
			'maxWidth'  => '640px',
			'alignment' => 'center',
		),
		'font'        => array(
			'color'     => '#ffffff',
			'size'      => '48px',
			'weight'    => '700',
			'textAlign' => 'center',
		),
		'headingFont' => array(
			'color'  => '#111111',
			'size'   => '32px',
			'weight' => '700',
		),
		'bodyFont'    => array(
			'color'      => '#333333',
			'size'       => '16px',
			'lineHeight' => '1.7em',
		),
		'boxShadow'   => array(
			'style' => 'preset1',
			'blur'  => '24px',
			'color' => 'rgba(0,0,0,0.12)',
		),
		'button'      => array( 'enable' => 'on' ),
		'layout'      => array(
			'display'       => 'flex',
			'flexDirection' => 'row',
			'gap'           => '16px',
		),
		'zIndex'      => array( 'value' => '10' ),
		'icon'        => array(
			'color'   => '#ffffff',
			'useSize' => 'on',
			'size'    => '24px',
		),
	);

	/**
	 * The styling schema for one module — the universal decoration groups and
	 * advanced options it supports, separate from its content schema (see
	 * describe()). Divi's universal styles are identical across ~100 modules,
	 * so keeping them out of every module's content schema and expanding them
	 * on demand here keeps both responses small.
	 *
	 * @param string $type  Module type, e.g. 'divi/text'.
	 * @param string $group Optional decoration group to expand (e.g. 'spacing').
	 * @return array|WP_Error
	 */
	public static function style( $type, $group = '' ) {
		$index = self::index();
		if ( ! isset( $index[ $type ] ) ) {
			return new WP_Error(
				'saddle_unknown_module',
				sprintf( 'No module.json found for "%s". Use divi-list-modules to see what exists on this site.', $type )
			);
		}

		$json = json_decode( self::read_json_file( $index[ $type ] ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'saddle_bad_module_json', sprintf( 'The module.json for "%s" could not be parsed.', $type ) );
		}

		// Divi's own default render-attributes encode the ACTUAL nesting where a
		// value lives — critically, the typography (font) groups nest their
		// value under an extra key (a heading's title colour/size/align sit at
		// title.decoration.font.font.desktop.value, not …font.desktop.value).
		// Distilling the real path from this file, rather than assuming a flat
		// <group>.desktop.value, is what stops a "correct-looking" style write
		// from silently doing nothing.
		$render_file = dirname( $index[ $type ] ) . '/module-default-render-attributes.json';
		$render      = file_exists( $render_file ) ? json_decode( self::read_json_file( $render_file ), true ) : array();
		$render      = is_array( $render ) ? $render : array();

		// Collect, per attribute, the decoration groups and advanced options,
		// with the canonical path an agent uses to set each.
		$style_groups = array();
		$advanced     = array();
		foreach ( ( isset( $json['attributes'] ) && is_array( $json['attributes'] ) ? $json['attributes'] : array() ) as $attr_name => $attr ) {
			if ( ! is_array( $attr ) ) {
				continue;
			}
			$settings = isset( $attr['settings'] ) && is_array( $attr['settings'] ) ? $attr['settings'] : array();

			if ( isset( $settings['decoration'] ) && is_array( $settings['decoration'] ) ) {
				foreach ( array_keys( $settings['decoration'] ) as $g ) {
					$base    = $attr_name . '.decoration.' . $g;
					$entry   = array(
						'group'  => $g,
						'attr'   => $attr_name,
						'path'   => self::distill_value_path( $render, array( $attr_name, 'decoration', $g ), $base ),
						'fields' => isset( self::STYLE_FIELDS[ $g ] ) ? self::STYLE_FIELDS[ $g ] : array(),
					);
					$example = self::group_example( $render, $attr_name, $g );
					if ( null !== $example ) {
						$entry['example'] = $example;
					}
					$style_groups[] = $entry;
				}
			}
			if ( isset( $settings['advanced'] ) && is_array( $settings['advanced'] ) ) {
				foreach ( array_keys( $settings['advanced'] ) as $a ) {
					$advanced[ $a ] = self::distill_value_path( $render, array( $attr_name, 'advanced', $a ), $attr_name . '.advanced.' . $a );
				}
			}
		}

		// A specific group requested: return just its fields + path.
		if ( '' !== $group ) {
			foreach ( $style_groups as $sg ) {
				if ( $sg['group'] === $group ) {
					$out = array(
						'type'   => $type,
						'group'  => $group,
						'path'   => $sg['path'],
						'fields' => $sg['fields'],
						'note'   => sprintf( 'Set with divi-edit-module by passing this dotted path as an attrs KEY: attrs = { "%s.<field>": <value> } (e.g. { "%s.color": "#ffffff" }). Saddle expands the dotted key into the nested object Divi reads. Omit to inherit the site design. "example" shows a correct value SHAPE — some fields take objects, not scalars.', $sg['path'], $sg['path'] ),
					);
					if ( isset( $sg['example'] ) ) {
						$out['example'] = $sg['example'];
					}
					return $out;
				}
			}
			return new WP_Error(
				'saddle_unknown_style_group',
				sprintf( 'Module "%s" has no "%s" style group. Call divi-get-style-schema without a group for the list.', $type, $group )
			);
		}

		return array(
			'type'         => $type,
			'style_groups' => $style_groups,
			'advanced'     => $advanced,
			'note'         => __( 'Universal styling. Call again with "group" for a group\'s fields. Style via divi-edit-module raw "attrs" at <path>.<field> with {"desktop":{"value":…}} breakpoints. A module\'s own paths never transfer to another module.', 'saddle' ),
		);
	}

	/**
	 * The real settable path for a decoration/advanced group, distilled from
	 * Divi's default render-attributes. Divi nests some groups' values under
	 * extra keys (typography under `.font`, a text body under `.body.font`);
	 * this walks the render-attributes subtree at $keys to the first `desktop`
	 * node and returns the path to its `.value`. Falls back to the flat
	 * `$base.desktop.value` when the group has no default (correct for the
	 * groups Divi does NOT nest — spacing, border, background, …).
	 *
	 * @param array    $render Full default render-attributes.
	 * @param string[] $keys   Path into it, e.g. [title, decoration, font].
	 * @param string   $base   Dotted base path for the fallback.
	 * @return string
	 */
	private static function distill_value_path( $render, $keys, $base ) {
		$node = $render;
		foreach ( $keys as $k ) {
			if ( ! is_array( $node ) || ! isset( $node[ $k ] ) ) {
				return $base . '.desktop.value';
			}
			$node = $node[ $k ];
		}
		$found = self::find_desktop_path( $node, $base );
		return $found ? $found . '.value' : $base . '.desktop.value';
	}

	/**
	 * Depth-first search for the first `desktop` breakpoint key, returning the
	 * dotted path up to and including it.
	 *
	 * @param mixed  $node Subtree.
	 * @param string $path Path so far.
	 * @return string|null
	 */
	private static function find_desktop_path( $node, $path ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		if ( isset( $node['desktop'] ) ) {
			return $path . '.desktop';
		}
		foreach ( $node as $k => $v ) {
			$r = self::find_desktop_path( $v, $path . '.' . $k );
			if ( $r ) {
				return $r;
			}
		}
		return null;
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
		if ( isset( $memo[ $type ] ) ) {
			return $memo[ $type ];
		}

		$map   = array();
		$style = self::style( $type );
		if ( ! is_wp_error( $style ) ) {
			foreach ( $style['style_groups'] as $sg ) {
				$map[ $sg['attr'] . '.' . $sg['group'] ] = $sg['path'];
			}
		}

		$memo[ $type ] = $map;
		return $map;
	}

	/**
	 * An example VALUE for a decoration group: the curated shape from
	 * STYLE_EXAMPLES, else the module's own default render value when one
	 * exists. Null when neither offers anything.
	 *
	 * @param array  $render    Full default render-attributes.
	 * @param string $attr_name Attribute the group sits on.
	 * @param string $group     Decoration group.
	 * @return mixed|null
	 */
	private static function group_example( array $render, $attr_name, $group ) {
		if ( isset( self::STYLE_EXAMPLES[ $group ] ) ) {
			return self::STYLE_EXAMPLES[ $group ];
		}

		$node = $render;
		foreach ( array( $attr_name, 'decoration', $group ) as $k ) {
			if ( ! is_array( $node ) || ! isset( $node[ $k ] ) ) {
				return null;
			}
			$node = $node[ $k ];
		}
		// The first desktop default under the group, if it carries a value.
		while ( is_array( $node ) && ! isset( $node['desktop'] ) ) {
			$first = reset( $node );
			if ( false === $first ) {
				return null;
			}
			$node = $first;
		}
		if ( is_array( $node ) && isset( $node['desktop']['value'] ) && ! in_array( $node['desktop']['value'], array( null, '', array() ), true ) ) {
			return $node['desktop']['value'];
		}
		return null;
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
