<?php
/**
 * The native Gutenberg validation profile on Saddle's generic block-tree engine.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg structure rules over the builder-agnostic Saddle_Tree.
 *
 * All generic operations — parse, serialize, dot-addressing, flatten,
 * get/insert/remove/replace — are inherited from the engine. What lives here
 * is only what makes a tree valid NATIVE editor content:
 *
 *  - No page-builder blocks (a `divi/…` module inside a native page is a
 *    mistake in both directions; builder pages are guarded separately).
 *  - Registered block types must satisfy their own placement contracts from
 *    WP_Block_Type_Registry: `parent` (allowed direct parents), `ancestor`
 *    (required somewhere up the chain), and the container's `allowedBlocks`.
 *  - Block types the server does not know (JS-only registrations, classic
 *    freeform HTML) are tolerated when already present — rejecting them would
 *    make pages containing them uneditable — but the authoring layer refuses
 *    to CREATE them (Saddle_Blocks_Author).
 *
 * Invalid structure is REJECTED, never repaired — the page must stay
 * editor-editable at every point in time, and an agent that produced bad
 * structure needs the error, not a silent fix.
 */
class Saddle_Blocks_Tree extends Saddle_Tree {

	/**
	 * Block namespaces that belong to page builders and are therefore invalid
	 * in a native editor tree.
	 *
	 * @return string[]
	 */
	public static function builder_namespaces() {
		/**
		 * Filter the block namespaces rejected inside a native Gutenberg tree.
		 *
		 * @param string[] $namespaces Namespace prefixes (before the slash).
		 */
		return (array) apply_filters( 'saddle_blocks_builder_namespaces', array( 'divi' ) );
	}

	/**
	 * Validate a whole tree against the editor's placement contracts.
	 *
	 * @param array[] $tree Block tree.
	 * @return true|WP_Error True when valid; otherwise one error whose data
	 *                       lists every violation with its address.
	 */
	public static function validate( array $tree ) {
		$violations = array();
		foreach ( $tree as $i => $block ) {
			self::validate_block( $block, (string) $i, array(), $violations );
		}

		if ( $violations ) {
			return new WP_Error(
				'saddle_invalid_structure',
				__( 'The block tree is structurally invalid.', 'saddle' ),
				array( 'violations' => $violations )
			);
		}
		return true;
	}

	/**
	 * Recursively validate one block and its children.
	 *
	 * @param array    $block      Block array.
	 * @param string   $address    Its address.
	 * @param string[] $ancestors  Block names from root to the direct parent.
	 * @param array    $violations Accumulator (by reference).
	 */
	private static function validate_block( array $block, $address, array $ancestors, array &$violations ) {
		$name = (string) $block['blockName'];

		// Classic/freeform HTML chunks have no name; nothing to check.
		if ( '' === $name ) {
			return;
		}

		if ( in_array( strtok( $name, '/' ), self::builder_namespaces(), true ) ) {
			$violations[] = array(
				'address' => $address,
				'type'    => $name,
				'problem' => __( 'This is a page-builder module, not an editor block. Builder layouts are edited with their builder\'s own tools, never mixed into native content.', 'saddle' ),
			);
			return; // Children of a builder module are noise, skip them.
		}

		$type   = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$parent = $ancestors ? $ancestors[ count( $ancestors ) - 1 ] : null;

		if ( $type ) {
			if ( ! empty( $type->parent ) && ! in_array( $parent, (array) $type->parent, true ) ) {
				$violations[] = array(
					'address' => $address,
					'type'    => $name,
					'problem' => sprintf(
						/* translators: 1: block type, 2: allowed parent list. */
						__( '%1$s may only be placed directly inside %2$s.', 'saddle' ),
						$name,
						implode( ', ', (array) $type->parent )
					),
				);
			}

			if ( ! empty( $type->ancestor ) && ! array_intersect( (array) $type->ancestor, $ancestors ) ) {
				$violations[] = array(
					'address' => $address,
					'type'    => $name,
					'problem' => sprintf(
						/* translators: 1: block type, 2: required ancestor list. */
						__( '%1$s may only be used somewhere inside %2$s.', 'saddle' ),
						$name,
						implode( ', ', (array) $type->ancestor )
					),
				);
			}
		}

		if ( $parent ) {
			$parent_type = WP_Block_Type_Registry::get_instance()->get_registered( $parent );
			if ( $parent_type && isset( $parent_type->allowed_blocks ) && is_array( $parent_type->allowed_blocks )
				&& $parent_type->allowed_blocks && ! in_array( $name, $parent_type->allowed_blocks, true ) ) {
				$violations[] = array(
					'address' => $address,
					'type'    => $name,
					'problem' => sprintf(
						/* translators: 1: parent block type, 2: block type. */
						__( '%1$s does not allow %2$s among its children.', 'saddle' ),
						$parent,
						$name
					),
				);
			}
		}

		$chain = array_merge( $ancestors, array( $name ) );
		foreach ( $block['innerBlocks'] as $i => $child ) {
			self::validate_block( $child, $address . '.' . $i, $chain, $violations );
		}
	}
}
