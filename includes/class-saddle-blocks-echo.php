<?php
/**
 * The applied-vs-ignored echo for Gutenberg writes.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects the silent no-op: an attribute the agent wrote that the editor
 * will never act on (https://github.com/plugpressco/saddle/issues/10 §2.2). Three checks, all against what
 * THIS site actually registers:
 *
 *  1. Unknown attribute keys — not in the block type's registered attributes
 *     and not enabled by its supports (only when the type IS server-
 *     registered; JS-only blocks can't be checked and are skipped).
 *  2. Unknown preset slugs — backgroundColor/textColor/gradient/fontSize/
 *     fontFamily values that match no theme.json preset resolve to a class
 *     with no CSS behind it.
 *  3. Unknown style groups — top-level keys in the `style` object outside
 *     the style engine's vocabulary produce no CSS at all.
 *
 * The echo WARNS, it never rejects: a warning rides back on the write's
 * response so the agent can immediately correct, but a write that is valid
 * structure always lands. False certainty is the enemy — anything the site
 * can't be asked about is skipped, not guessed.
 */
class Saddle_Blocks_Echo {

	/**
	 * Attribute keys valid on (nearly) every block regardless of schema —
	 * editor plumbing plus the keys Saddle's own authoring layer consumes.
	 */
	const UNIVERSAL_KEYS = array( 'className', 'style', 'lock', 'metadata', 'anchor', 'align' );

	/**
	 * Support-derived attribute keys: feature => attribute keys it enables.
	 */
	const SUPPORT_KEYS = array(
		'color'      => array( 'backgroundColor', 'textColor', 'gradient' ),
		'typography' => array( 'fontSize', 'fontFamily' ),
		'layout'     => array( 'layout' ),
	);

	/**
	 * Style-engine top-level groups (wp_style_engine_get_styles vocabulary).
	 */
	const STYLE_GROUPS = array( 'border', 'color', 'spacing', 'typography', 'dimensions', 'shadow', 'background', 'outline', 'position', 'css', 'elements' );

	/**
	 * Preset-slug attributes → the design-token list that must contain the slug.
	 */
	const PRESET_ATTRS = array(
		'backgroundColor' => array( 'color', 'palette' ),
		'textColor'       => array( 'color', 'palette' ),
		'gradient'        => array( 'color', 'gradients' ),
		'fontSize'        => array( 'typography', 'fontSizes' ),
		'fontFamily'      => array( 'typography', 'fontFamilies' ),
	);

	/**
	 * Check a list of authored nodes (recursively), as passed to set-blocks.
	 *
	 * @param array[] $nodes Authored nodes {type, content, attrs, children}.
	 * @param string  $prefix Address prefix ('' at root).
	 * @return string[] Warnings, agent-readable.
	 */
	public static function check_nodes( array $nodes, $prefix = '' ) {
		$warnings = array();
		foreach ( array_values( $nodes ) as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$address = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$type    = isset( $node['type'] ) && is_string( $node['type'] ) ? trim( $node['type'] ) : '';
			$attrs   = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
			if ( '' !== $type && $attrs ) {
				$warnings = array_merge( $warnings, self::check_attrs( $type, $attrs, $address ) );
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$warnings = array_merge( $warnings, self::check_nodes( $node['children'], $address ) );
			}
		}
		return $warnings;
	}

	/**
	 * Check one authored node (and its children) at a known final address,
	 * as passed to add-block.
	 *
	 * @param array  $node    Authored node.
	 * @param string $address The address the node lands at.
	 * @return string[] Warnings.
	 */
	public static function check_node( array $node, $address ) {
		$warnings = array();
		$type     = isset( $node['type'] ) && is_string( $node['type'] ) ? trim( $node['type'] ) : '';
		$attrs    = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		if ( '' !== $type && $attrs ) {
			$warnings = self::check_attrs( $type, $attrs, $address );
		}
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			$warnings = array_merge( $warnings, self::check_nodes( $node['children'], $address ) );
		}
		return $warnings;
	}

	/**
	 * Check one block's authored attrs against what the site will apply.
	 *
	 * @param string $type    Block type.
	 * @param array  $attrs   Authored attributes.
	 * @param string $address Node address for the message.
	 * @return string[] Warnings.
	 */
	public static function check_attrs( $type, array $attrs, $address ) {
		$warnings = array();
		$location = '' === $address ? $type : sprintf( '%1$s (node %2$s)', $type, $address );

		// 1. Unknown attribute keys — only judgeable for server-registered types.
		$known = self::known_keys( $type );
		if ( null !== $known ) {
			foreach ( array_keys( $attrs ) as $key ) {
				if ( ! in_array( (string) $key, $known, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: attribute key, 2: block type + address, 3: block type. */
						__( 'Attribute "%1$s" on %2$s is not an attribute of %3$s — it will be ignored. Check saddle/get-block-schema.', 'saddle' ),
						$key,
						$location,
						$type
					);
				}
			}
		}

		// 2. Preset slugs that exist on this site.
		foreach ( self::PRESET_ATTRS as $attr => $path ) {
			if ( empty( $attrs[ $attr ] ) || ! is_string( $attrs[ $attr ] ) ) {
				continue;
			}
			$slugs = self::preset_slugs( $path );
			if ( null !== $slugs && ! in_array( $attrs[ $attr ], $slugs, true ) ) {
				$warnings[] = sprintf(
					/* translators: 1: attribute key, 2: slug, 3: block type + address. */
					__( '%1$s "%2$s" on %3$s matches no preset on this site — it will render as if unset. Use a slug from saddle/get-design-tokens.', 'saddle' ),
					$attr,
					$attrs[ $attr ],
					$location
				);
			}
		}

		// 3. Style groups the style engine understands.
		if ( ! empty( $attrs['style'] ) && is_array( $attrs['style'] ) ) {
			foreach ( array_keys( $attrs['style'] ) as $group ) {
				if ( ! in_array( (string) $group, self::STYLE_GROUPS, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: style group, 2: block type + address, 3: known groups. */
						__( 'Style group "%1$s" on %2$s is not part of the block style vocabulary (%3$s) — it will produce no CSS.', 'saddle' ),
						$group,
						$location,
						implode( ', ', self::STYLE_GROUPS )
					);
				}
			}
		}

		return $warnings;
	}

	/**
	 * The attribute keys a server-registered block type will act on, or null
	 * when the type isn't server-registered (JS-only — not judgeable).
	 *
	 * @param string $type Block type.
	 * @return string[]|null
	 */
	private static function known_keys( $type ) {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $type );
		if ( ! $block_type ) {
			return null;
		}

		$keys = array_merge( array_keys( (array) $block_type->attributes ), self::UNIVERSAL_KEYS );

		$supports = is_array( $block_type->supports ) ? $block_type->supports : array();
		foreach ( self::SUPPORT_KEYS as $feature => $enabled_keys ) {
			if ( ! empty( $supports[ $feature ] ) ) {
				$keys = array_merge( $keys, $enabled_keys );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * The preset slugs at a theme.json settings path, or null when the site
	 * exposes none there (can't be checked without false positives).
	 *
	 * @param string[] $path Two-segment settings path, e.g. ['color','palette'].
	 * @return string[]|null
	 */
	private static function preset_slugs( array $path ) {
		static $memo = array();
		$key         = implode( '.', $path );
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$memo[ $key ] = null;
			return null;
		}

		$settings = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
		$node     = isset( $settings[ $path[0] ][ $path[1] ] ) ? $settings[ $path[0] ][ $path[1] ] : array();
		if ( ! is_array( $node ) || ! $node ) {
			$memo[ $key ] = null;
			return null;
		}

		$groups = isset( $node[0] ) ? array( $node ) : array_values( array_filter( $node, 'is_array' ) );
		$slugs  = array();
		foreach ( $groups as $group ) {
			foreach ( $group as $preset ) {
				if ( is_array( $preset ) && isset( $preset['slug'] ) ) {
					$slugs[] = (string) $preset['slug'];
				}
			}
		}

		$memo[ $key ] = $slugs ? $slugs : null;
		return $memo[ $key ];
	}
}
