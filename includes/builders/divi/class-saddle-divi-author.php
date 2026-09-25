<?php
/**
 * The authoring layer — simplified agent input → canonical Divi 5 blocks.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expands the hybrid authoring format into canonical Divi 5 block trees.
 *
 * Agents describe a page as plain nodes:
 *
 *   { "type": "divi/heading", "fields": { "title": "Welcome" } }
 *   { "type": "divi/button",  "fields": { "button": { "text": "Go", "linkUrl": "/pricing" } } }
 *   { "type": "divi/section", "children": [ … ] }
 *
 * and this layer produces what Divi would have saved itself. The mapping is
 * mechanical because Divi 5's content model is perfectly regular (verified
 * against the theme's own module.json files): every content attribute is
 * `<attrName>.innerContent.desktop.value`, a string for text-like attrs and
 * an object for composite ones. `fields` is wrapped into that envelope;
 * `attrs` is a raw canonical-attrs passthrough deep-merged on top for the
 * power cases (decoration/styling) the simplified keys don't cover.
 *
 * No guessing, no repair: unknown shapes pass through untouched and the
 * structural validator decides. builderVersion is stamped the way the
 * Visual Builder stamps it.
 */
class Saddle_Divi_Author {

	/**
	 * Expand a list of authoring nodes into a canonical, placeholder-wrapped
	 * Divi block tree.
	 *
	 * @param array $nodes Authoring nodes (see class doc).
	 * @return array[]|WP_Error Block tree ready for validate()/serialize().
	 */
	public static function expand( array $nodes ) {
		$tree = array();
		foreach ( array_values( $nodes ) as $i => $node ) {
			$block = self::expand_node( $node, (string) $i );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
			$tree[] = $block;
		}

		// Real Divi 5 pages wrap their sections in a single divi/placeholder
		// root. Wrap unless the author already supplied one.
		$already_wrapped = 1 === count( $tree ) && 'divi/placeholder' === $tree[0]['blockName'];
		if ( ! $already_wrapped ) {
			$tree = array( Saddle_Divi_Tree::make_module( 'divi/placeholder', array(), '', $tree ) );
		}

		return $tree;
	}

	/**
	 * Expand one authoring node (recursively).
	 *
	 * @param array  $node    Authoring node.
	 * @param string $address Position (for error messages only).
	 * @return array|WP_Error Block array.
	 */
	public static function expand_node( $node, $address = '' ) {
		if ( ! is_array( $node ) || empty( $node['type'] ) || ! is_string( $node['type'] ) ) {
			return new WP_Error(
				'saddle_bad_node',
				sprintf( 'Node at position %s must be an object with a "type" (e.g. "divi/text").', '' === $address ? '0' : $address )
			);
		}

		$type = trim( $node['type'] );

		// A block name is interpolated raw into `<!-- wp:NAME -->` on serialize,
		// so validate its shape before it reaches the tree: a lowercase
		// namespace/name only. This rejects a malformed name and, critically, a
		// `-->` comment breakout, so a garbage or hostile type can never reach
		// the saved markup. (Existence — is this a REAL Divi module — is left to
		// the agent + divi-list-modules: on-disk module.json discovery is
		// incomplete for runtime-registered modules, so a hard existence gate
		// here would false-reject valid third-party modules on live pages).
		if ( ! preg_match( '#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $type ) ) {
			return new WP_Error(
				'saddle_bad_type',
				sprintf( 'Node at %1$s has an invalid module type "%2$s". A type must look like "divi/text" — a lowercase namespace and name.', '' === $address ? '0' : $address, $type )
			);
		}

		// Reject an unknown top-level key rather than silently dropping it: an
		// agent that writes `content` (instead of `fields`) would otherwise get a
		// success response and an empty module — a silent-data-loss footgun. The
		// vocabulary is small and fixed, so an unexpected key is a mistake worth
		// naming (Saddle rejects, never silently repairs).
		$known   = array( 'type', 'fields', 'attrs', 'children' );
		$unknown = array_diff( array_keys( $node ), $known );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'saddle_unknown_node_key',
				sprintf(
					/* translators: 1: node address, 2: the unknown key(s), 3: the valid keys. */
					__( 'Node at %1$s has an unrecognized key: %2$s. Module content goes in "fields" (e.g. {"type":"divi/heading","fields":{"title":"…"}}); valid node keys are %3$s.', 'saddle' ),
					'' === $address ? '0' : $address,
					implode( ', ', array_map( static fn( $k ) => '"' . $k . '"', $unknown ) ),
					implode( ', ', $known )
				),
				array( 'status' => 400 )
			);
		}

		$attrs = array();

		// fields: { attrName: string|object } → the innerContent envelope.
		if ( ! empty( $node['fields'] ) && is_array( $node['fields'] ) ) {
			foreach ( $node['fields'] as $attr_name => $value ) {
				$attrs[ $attr_name ]['innerContent'] = array(
					'desktop' => array( 'value' => $value ),
				);
			}
		}

		// attrs: raw canonical passthrough — wins over the fields expansion.
		// Dotted keys ("module.decoration.background.desktop.value.color") are
		// expanded into the nested object Divi actually reads, so the paths
		// get-style-schema documents work when written verbatim.
		if ( ! empty( $node['attrs'] ) && is_array( $node['attrs'] ) ) {
			$attrs = array_replace_recursive( $attrs, self::expand_dotted_keys( $node['attrs'] ) );
		}

		// Stamp the builder version like the Visual Builder does.
		if ( empty( $attrs['builderVersion'] ) && Saddle_Divi::version() ) {
			$attrs['builderVersion'] = Saddle_Divi::version();
		}

		$children = array();
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( array_values( $node['children'] ) as $i => $child ) {
				$child_address = ( '' === $address ? '' : $address . '.' ) . $i;
				$block         = self::expand_node( $child, $child_address );
				if ( is_wp_error( $block ) ) {
					return $block;
				}
				$children[] = $block;
			}
		}

		return Saddle_Divi_Tree::make_module( $type, $attrs, '', $children );
	}

	/**
	 * Expand any dotted attribute keys into the nested structure Divi reads.
	 *
	 * Agents naturally write the paths get-style-schema documents verbatim, e.g.
	 * { "module.decoration.background.desktop.value.color": "#0f5fa0" }. Stored
	 * as-is, that is a single literal key Divi ignores — a silent styling no-op.
	 * This turns each dotted key into
	 * { "module": { "decoration": { "background": { "desktop": { "value":
	 * { "color": "#0f5fa0" } } } } } } and deep-merges, so multiple paths under a
	 * shared prefix combine correctly. Non-dotted keys pass through unchanged;
	 * nested arrays are expanded recursively too (a mixed payload is fine).
	 *
	 * Divi attribute segments are camelCase and never contain a dot, so any dot
	 * in a key is unambiguously a path separator.
	 *
	 * @param array $attrs Raw attrs, possibly with dotted keys.
	 * @return array
	 */
	public static function expand_dotted_keys( array $attrs ) {
		$out = array();
		foreach ( $attrs as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::expand_dotted_keys( $value );
			}

			if ( is_string( $key ) && false !== strpos( $key, '.' ) ) {
				$nested = $value;
				$path   = explode( '.', $key );
				for ( $i = count( $path ) - 1; $i >= 0; $i-- ) {
					$nested = array( $path[ $i ] => $nested );
				}
				$out = array_replace_recursive( $out, $nested );
			} elseif ( isset( $out[ $key ] ) && is_array( $out[ $key ] ) && is_array( $value ) ) {
				$out[ $key ] = array_replace_recursive( $out[ $key ], $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
