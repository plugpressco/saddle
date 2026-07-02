<?php
/**
 * Builder-agnostic block-tree operations.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse, address, mutate, and serialize a page's block tree.
 *
 * WordPress pages — native Gutenberg and block-based builders like Divi 5 —
 * store structure as block markup, so one engine serves every structured
 * design surface Saddle offers. This class is the generic core: pure
 * functions over the core block parser with no opinion about which blocks
 * are valid where. Builder-specific structure rules live in validation
 * profiles built on top (Saddle Pro's Divi profile extends this class; a
 * native Gutenberg profile follows the same pattern).
 *
 * Node addressing: dot-separated child indexes from the root, as strings —
 * "0" is the first root block, "0.1" its second child, "0.1.0.2" the third
 * block two levels below. Addresses are positional and only valid against
 * the tree revision they were read from.
 */
class Saddle_Tree {

	/* ---------------------------------------------------------------------
	 * Parse / serialize
	 * ------------------------------------------------------------------- */

	/**
	 * Parse page content into a block tree, dropping the whitespace-only
	 * freeform blocks the core parser emits between real blocks.
	 *
	 * @param string $content Raw post_content.
	 * @return array[] Block arrays (blockName/attrs/innerBlocks/innerHTML/innerContent).
	 */
	public static function parse( $content ) {
		$blocks = parse_blocks( (string) $content );
		return self::strip_noise( $blocks );
	}

	/**
	 * Serialize a block tree back to post_content markup.
	 *
	 * @param array[] $tree Block tree.
	 * @return string
	 */
	public static function serialize( array $tree ) {
		return serialize_blocks( $tree );
	}

	/**
	 * Remove parser noise (null-named whitespace blocks), recursively.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[]
	 */
	private static function strip_noise( array $blocks ) {
		$clean = array();
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' === trim( implode( '', array_filter( $block['innerContent'], 'is_string' ) ) ) ) {
				continue;
			}
			$block['innerBlocks'] = self::strip_noise( $block['innerBlocks'] );
			$clean[]              = $block;
		}
		return $clean;
	}

	/* ---------------------------------------------------------------------
	 * Addressing + node operations
	 * ------------------------------------------------------------------- */

	/**
	 * Flatten a tree into an addressable node list.
	 *
	 * @param array[] $tree Block tree.
	 * @return array[] Nodes: address, type, attrs, children, text (plain-text excerpt).
	 */
	public static function flatten( array $tree ) {
		$nodes = array();
		self::walk( $tree, '', $nodes );
		return $nodes;
	}

	/**
	 * Depth-first walk building flat nodes.
	 *
	 * @param array[] $blocks Sibling blocks.
	 * @param string  $prefix Parent address ('' at root).
	 * @param array   $nodes  Accumulator (by reference).
	 */
	private static function walk( array $blocks, $prefix, array &$nodes ) {
		foreach ( $blocks as $i => $block ) {
			$address = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$text    = trim( wp_strip_all_tags( (string) $block['innerHTML'] ) );
			$nodes[] = array(
				'address'  => $address,
				'type'     => (string) $block['blockName'],
				'attrs'    => is_array( $block['attrs'] ) ? $block['attrs'] : array(),
				'children' => count( $block['innerBlocks'] ),
				'text'     => mb_substr( $text, 0, 120 ),
			);
			self::walk( $block['innerBlocks'], $address, $nodes );
		}
	}

	/**
	 * Get the node at an address.
	 *
	 * @param array[] $tree    Block tree.
	 * @param string  $address Dot address.
	 * @return array|null Block array, or null when the address doesn't resolve.
	 */
	public static function get( array $tree, $address ) {
		$node = null;
		$list = $tree;
		foreach ( self::path( $address ) as $index ) {
			if ( ! isset( $list[ $index ] ) ) {
				return null;
			}
			$node = $list[ $index ];
			$list = $node['innerBlocks'];
		}
		return $node;
	}

	/**
	 * Insert a block as a child of $parent_address at $position.
	 *
	 * @param array[] $tree           Block tree.
	 * @param string  $parent_address Container address, or '' for the root list.
	 * @param int     $position       Index among the parent's children (clamped).
	 * @param array   $block          Block array to insert.
	 * @return array[]|WP_Error The new tree, or an error if the parent doesn't resolve.
	 */
	public static function insert( array $tree, $parent_address, $position, array $block ) {
		return self::mutate_list(
			$tree,
			$parent_address,
			static function ( array $list ) use ( $position, $block ) {
				$at = max( 0, min( count( $list ), (int) $position ) );
				array_splice( $list, $at, 0, array( $block ) );
				return $list;
			}
		);
	}

	/**
	 * Remove the node at an address.
	 *
	 * @param array[] $tree    Block tree.
	 * @param string  $address Dot address.
	 * @return array[]|WP_Error The new tree, or an error if the address doesn't resolve.
	 */
	public static function remove( array $tree, $address ) {
		$path  = self::path( $address );
		$index = array_pop( $path );

		return self::mutate_list(
			$tree,
			implode( '.', $path ),
			static function ( array $list ) use ( $index, $address ) {
				if ( ! isset( $list[ $index ] ) ) {
					return new WP_Error(
						'saddle_bad_address',
						sprintf( 'No block at address %s.', $address )
					);
				}
				array_splice( $list, (int) $index, 1 );
				return $list;
			}
		);
	}

	/**
	 * Replace the node at an address.
	 *
	 * @param array[] $tree    Block tree.
	 * @param string  $address Dot address.
	 * @param array   $block   Replacement block array.
	 * @return array[]|WP_Error
	 */
	public static function replace( array $tree, $address, array $block ) {
		$path  = self::path( $address );
		$index = array_pop( $path );

		return self::mutate_list(
			$tree,
			implode( '.', $path ),
			static function ( array $list ) use ( $index, $block, $address ) {
				if ( ! isset( $list[ $index ] ) ) {
					return new WP_Error(
						'saddle_bad_address',
						sprintf( 'No block at address %s.', $address )
					);
				}
				$list[ (int) $index ] = $block;
				return $list;
			}
		);
	}

	/**
	 * Build a well-formed block array.
	 *
	 * Handles the innerContent bookkeeping the block serializer needs: one
	 * null placeholder per child block, or the HTML chunk for a leaf.
	 *
	 * @param string  $type       Block type (e.g. 'divi/text', 'core/group').
	 * @param array   $attrs      Block attributes.
	 * @param string  $inner_html Leaf HTML content ('' for containers).
	 * @param array[] $children   Child block arrays.
	 * @return array
	 */
	public static function make_module( $type, array $attrs = array(), $inner_html = '', array $children = array() ) {
		$inner_content = $children
			? array_fill( 0, count( $children ), null )
			: ( '' !== $inner_html ? array( $inner_html ) : array() );

		return array(
			'blockName'    => $type,
			'attrs'        => $attrs,
			'innerBlocks'  => array_values( $children ),
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Parse a dot address into an index path.
	 *
	 * @param string $address Dot address ('' allowed → empty path).
	 * @return int[]
	 */
	protected static function path( $address ) {
		$address = trim( (string) $address );
		if ( '' === $address ) {
			return array();
		}
		return array_map( 'intval', explode( '.', $address ) );
	}

	/**
	 * Apply a mutation to the child list at $parent_address and rebuild the
	 * tree immutably along the path.
	 *
	 * @param array[]  $tree           Block tree.
	 * @param string   $parent_address Address of the container ('' = root list).
	 * @param callable $fn             array $list → array|WP_Error.
	 * @return array[]|WP_Error
	 */
	protected static function mutate_list( array $tree, $parent_address, callable $fn ) {
		$path = self::path( $parent_address );

		if ( ! $path ) {
			$result = $fn( $tree );
			return is_wp_error( $result ) ? $result : array_values( $result );
		}

		$index = $path[0];
		if ( ! isset( $tree[ $index ] ) ) {
			return new WP_Error(
				'saddle_bad_address',
				sprintf( 'No block at address %s.', $parent_address )
			);
		}

		$rest  = implode( '.', array_slice( $path, 1 ) );
		$child = self::mutate_list( $tree[ $index ]['innerBlocks'], $rest, $fn );
		if ( is_wp_error( $child ) ) {
			return $child;
		}

		$tree[ $index ]['innerBlocks'] = $child;
		// Keep serializer bookkeeping consistent with the new child count.
		$tree[ $index ]['innerContent'] = array_fill( 0, count( $child ), null );
		return $tree;
	}
}
