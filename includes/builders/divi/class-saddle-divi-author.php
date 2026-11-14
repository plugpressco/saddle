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
 *   { "type": "divi/blog",    "settings": { "post.advanced.number": 6 } }
 *   { "type": "divi/row",     "columns": "2/3,1/3", "children": [ … ] }
 *   { "type": "divi/section", "children": [ … ] }
 *
 * and this layer produces what Divi would have saved itself. The mapping is
 * mechanical because Divi 5's content model is perfectly regular (verified
 * against the theme's own module.json files): every content attribute is
 * `<attrName>.innerContent.desktop.value`, a string for text-like attrs and
 * an object for composite ones. `fields` is wrapped into that envelope;
 * `settings` are checked against the module's settings list and wrapped the
 * same way; `attrs` is a raw canonical-attrs passthrough deep-merged on top
 * for the power cases (decoration/styling). Keys in all three may end in
 * "@<breakpoint>" and, for attrs and settings, ":hover" or ":sticky"
 * (Saddle_Divi_Node_Attrs). `columns` on a row sets its column widths
 * (Saddle_Divi_Columns).
 *
 * No guessing, no repair: unknown shapes pass through untouched and the
 * structural validator decides. builderVersion is stamped the way the
 * Visual Builder stamps it.
 */
class Saddle_Divi_Author {

	/**
	 * The node keys an agent may write.
	 */
	const NODE_KEYS = array( 'type', 'fields', 'settings', 'attrs', 'columns', 'children' );

	/**
	 * Notes from the last expand() or expand_node() call: what SaddleWP
	 * added on its own (a row's missing column widths) and why.
	 *
	 * @var string[]
	 */
	private static $notes = array();

	/**
	 * Expand a list of authoring nodes into a canonical, placeholder-wrapped
	 * Divi block tree.
	 *
	 * @param array $nodes Authoring nodes (see class doc).
	 * @return array[]|WP_Error Block tree ready for validate()/serialize().
	 */
	public static function expand( array $nodes ) {
		self::$notes = array();

		// Real Divi 5 pages wrap their sections in a single divi/placeholder
		// root. Wrap unless the author already supplied one; the addresses in
		// messages are the ones the saved page will have.
		$nodes           = array_values( $nodes );
		$already_wrapped = 1 === count( $nodes ) && is_array( $nodes[0] ) && isset( $nodes[0]['type'] ) && 'divi/placeholder' === $nodes[0]['type'];

		$tree = array();
		foreach ( $nodes as $i => $node ) {
			$block = self::build_node( $node, $already_wrapped ? (string) $i : '0.' . $i );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
			$tree[] = $block;
		}

		if ( ! $already_wrapped ) {
			$tree = array( Saddle_Divi_Tree::make_module( 'divi/placeholder', array(), '', $tree ) );
		}

		return $tree;
	}

	/**
	 * Expand one authoring node (recursively).
	 *
	 * @param array  $node    Authoring node.
	 * @param string $address The address the node will have once saved, for
	 *                        messages ('' = "0").
	 * @return array|WP_Error Block array.
	 */
	public static function expand_node( $node, $address = '' ) {
		self::$notes = array();
		return self::build_node( $node, (string) $address );
	}

	/**
	 * Notes from the last expansion (a row given equal columns, …).
	 *
	 * @return string[]
	 */
	public static function notes() {
		return self::$notes;
	}

	/**
	 * The nodes as the applied-vs-ignored echo should read them: "fields"
	 * holding the plain desktop content only, and "attrs" holding every
	 * attrs write (and suffixed field) at its canonical path. Settings are
	 * left out: they were checked against module.json already. A node that
	 * does not build is passed through as written.
	 *
	 * @param array  $nodes   Authoring nodes.
	 * @param string $prefix  Address prefix ('' at root).
	 * @return array[]
	 */
	public static function echo_nodes( array $nodes, $prefix = '' ) {
		$out = array();
		foreach ( array_values( $nodes ) as $i => $node ) {
			$out[] = self::echo_node( $node, '' === $prefix ? (string) $i : $prefix . '.' . $i );
		}
		return $out;
	}

	/**
	 * One node for the echo (see echo_nodes()).
	 *
	 * @param mixed  $node    Authoring node.
	 * @param string $address Its address.
	 * @return mixed
	 */
	public static function echo_node( $node, $address ) {
		if ( ! is_array( $node ) || empty( $node['type'] ) || ! is_string( $node['type'] ) ) {
			return $node;
		}
		$built = Saddle_Divi_Node_Attrs::build( trim( $node['type'] ), $node, $address );
		$out   = array( 'type' => $node['type'] );
		if ( ! is_wp_error( $built ) ) {
			$out['fields'] = $built['echo_fields'];
			$out['attrs']  = $built['echo_attrs'];
		} else {
			$out['fields'] = isset( $node['fields'] ) ? $node['fields'] : array();
			$out['attrs']  = isset( $node['attrs'] ) ? $node['attrs'] : array();
		}
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			$out['children'] = self::echo_nodes( $node['children'], $address );
		}
		return $out;
	}

	/**
	 * Build one node and its children.
	 *
	 * @param mixed  $node    Authoring node.
	 * @param string $address Address for messages.
	 * @return array|WP_Error Block array.
	 */
	private static function build_node( $node, $address ) {
		$shown = '' === $address ? '0' : $address;
		if ( ! is_array( $node ) || empty( $node['type'] ) || ! is_string( $node['type'] ) ) {
			return new WP_Error(
				'saddle_bad_node',
				sprintf( 'Node at position %s must be an object with a "type" (e.g. "divi/text").', $shown )
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
				sprintf( 'Node at %1$s has an invalid module type "%2$s". A type must look like "divi/text" — a lowercase namespace and name.', $shown, $type )
			);
		}

		// Reject an unknown top-level key rather than silently dropping it: an
		// agent that writes `content` (instead of `fields`) would otherwise get a
		// success response and an empty module — a silent-data-loss footgun. The
		// vocabulary is small and fixed, so an unexpected key is a mistake worth
		// naming (Saddle rejects, never silently repairs).
		$unknown = array_diff( array_keys( $node ), self::NODE_KEYS );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'saddle_unknown_node_key',
				sprintf(
					/* translators: 1: node address, 2: the unknown key(s), 3: the valid keys. */
					__( 'Node at %1$s has an unrecognized key: %2$s. Module content goes in "fields" (e.g. {"type":"divi/heading","fields":{"title":"…"}}); valid node keys are %3$s.', 'saddle' ),
					$shown,
					implode( ', ', array_map( static fn( $k ) => '"' . $k . '"', $unknown ) ),
					implode( ', ', self::NODE_KEYS )
				),
				array( 'status' => 400 )
			);
		}
		if ( isset( $node['columns'] ) && 'divi/row' !== $type ) {
			return new WP_Error(
				'saddle_bad_columns',
				sprintf(
					/* translators: 1: node address, 2: module type. */
					__( 'Node %1$s (%2$s): "columns" goes on a divi/row only. Nothing was saved.', 'saddle' ),
					$shown,
					$type
				),
				array( 'status' => 400 )
			);
		}
		if ( 'divi/global-slot' === $type ) {
			$ok = Saddle_Divi_Components::slot( $shown );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}

		// fields → the innerContent envelope; settings → checked and placed;
		// attrs → raw canonical paths, which win.
		$built = Saddle_Divi_Node_Attrs::build( $type, $node, $shown );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$attrs = Saddle_Divi_Node_Attrs::apply( array(), $built['writes'] );

		// Stamp the builder version like the Visual Builder does.
		if ( empty( $attrs['builderVersion'] ) && Saddle_Divi::version() ) {
			$attrs['builderVersion'] = Saddle_Divi::version();
		}

		$children = array();
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( array_values( $node['children'] ) as $i => $child ) {
				$block = self::build_node( $child, ( '' === $address ? '0' : $address ) . '.' . $i );
				if ( is_wp_error( $block ) ) {
					return $block;
				}
				$children[] = $block;
			}
		}

		if ( 'divi/row' === $type ) {
			$laid = Saddle_Divi_Row_Layout::apply( $attrs, $children, isset( $node['columns'] ) ? $node['columns'] : null, $shown, false );
			if ( is_wp_error( $laid ) ) {
				return $laid;
			}
			list( $attrs, $children, $note ) = $laid;
			if ( '' !== $note ) {
				self::$notes[] = $note;
			}
		}
		if ( 'divi/global-layout' === $type ) {
			$attrs = Saddle_Divi_Components::component( $attrs, $children, $shown );
			if ( is_wp_error( $attrs ) ) {
				return $attrs;
			}
		}

		return Saddle_Divi_Tree::make_module( $type, Saddle_Divi_Key_Order::apply( $attrs ), '', $children );
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
