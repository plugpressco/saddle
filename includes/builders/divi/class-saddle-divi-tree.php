<?php
/**
 * The Divi 5 validation profile on Saddle's generic block-tree engine.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Divi 5 structure rules over free Saddle's builder-agnostic Saddle_Tree.
 *
 * All generic operations — parse, serialize, dot-addressing, flatten,
 * get/insert/remove/replace, make_module — are inherited from the free
 * plugin's engine (one engine serves Gutenberg and every builder). What
 * lives here is only what makes a tree DIVI-valid:
 *
 *   [divi/placeholder >] divi/section > divi/row > divi/column > modules
 *
 * plus what Divi 5 also allows: a row or a group inside a column, a group
 * inside a group, groups in a group carousel, modules inside a module that
 * takes them, specialty and full-width sections, and Components placed as
 * the block they stand for (Saddle_Divi_Nesting holds Divi's own rule).
 * Invalid structure is REJECTED, never repaired — the page must stay
 * Visual-Builder-editable at every point in time, and an agent that
 * produced bad structure needs the error, not a silent fix.
 */
class Saddle_Divi_Tree extends Saddle_Tree {

	/**
	 * Resolve the insert index for a new child under $parent in $tree, clamping a
	 * requested $position (negative = append) to the parent's current child
	 * count. The one place add-module / move-module / apply-library-item share
	 * their "where does the new node go" logic.
	 *
	 * @param array      $tree           Block tree to insert into.
	 * @param string     $parent_address Parent address ('' = the root list).
	 * @param int|string $position       Requested position; negative appends.
	 * @return int|WP_Error Clamped insert index, or WP_Error if the parent address doesn't exist.
	 */
	public static function resolve_insert_position( array $tree, $parent_address, $position ) {
		if ( '' === $parent_address ) {
			$count = count( $tree );
		} else {
			$node = self::get( $tree, $parent_address );
			if ( ! $node ) {
				return new WP_Error( 'saddle_bad_address', sprintf( /* translators: %s: node address. */ __( 'No module at address %s.', 'saddle' ), $parent_address ) );
			}
			$count = count( $node['innerBlocks'] );
		}
		return ( (int) $position < 0 ) ? $count : min( (int) $position, $count );
	}

	/**
	 * Block types allowed at the tree root.
	 *
	 * @return string[]
	 */
	public static function root_types() {
		/**
		 * Filter the Divi block types Saddle accepts at the page root.
		 *
		 * divi/placeholder is what Divi 5 stores on a builder-enabled page with
		 * no content yet — a legitimate empty state agents build on top of.
		 *
		 * @param string[] $types Block type names.
		 */
		return (array) apply_filters( 'saddle_divi_root_types', array( 'divi/section', 'divi/fullwidth-section', 'divi/placeholder' ) );
	}

	/**
	 * Structural containment rules: container type => allowed child types,
	 * with '*' meaning "any module that is not page structure (section, row,
	 * column), not a group and not a child module (an accordion item, a
	 * slide)". Other modules follow their module.json (Saddle_Divi_Nesting).
	 *
	 * @return array<string, string[]>
	 */
	public static function structure() {
		/**
		 * Filter the Divi containment rules: [placeholder >] section > row >
		 * column > module, a nested row or a group in a column or group,
		 * groups in a group carousel (Divi 5.10.1's Visual Builder rule).
		 * A section's own type (full width, specialty) and a specialty
		 * column are judged in code, after this map.
		 *
		 * @param array $rules Map of container block type => allowed child types.
		 */
		return (array) apply_filters(
			'saddle_divi_structure',
			array(
				'divi/placeholder'       => array( 'divi/section', 'divi/fullwidth-section' ),
				'divi/section'           => array( 'divi/row' ),
				'divi/fullwidth-section' => array( '*' ),
				'divi/row'               => array( 'divi/column' ),
				'divi/row-inner'         => array( 'divi/column-inner' ),
				'divi/column'            => array( 'divi/row', 'divi/group', '*' ),
				'divi/column-inner'      => array( 'divi/group', '*' ),
				'divi/group'             => array( 'divi/row', 'divi/group', '*' ),
				'divi/group-carousel'    => array( 'divi/group' ),
				'divi/global-slot'       => array( 'divi/row', 'divi/group', '*' ),
			)
		);
	}

	/**
	 * Block namespaces that are NOT Divi modules and therefore invalid inside
	 * a Divi tree. Third-party Divi 5 modules ship their own namespaces (e.g.
	 * divi-instagram-feed/insta-feed, divitorque/accordion), so validation
	 * rejects known-foreign namespaces rather than allowlisting `divi/` only.
	 *
	 * @return string[]
	 */
	public static function foreign_namespaces() {
		/**
		 * Filter the block namespaces rejected inside a Divi tree.
		 *
		 * @param string[] $namespaces Namespace prefixes (before the slash).
		 */
		return (array) apply_filters( 'saddle_divi_foreign_namespaces', array( 'core', 'core-embed' ) );
	}

	/**
	 * Validate a whole tree against the containment rules.
	 *
	 * @param array[] $tree Block tree.
	 * @return true|WP_Error True when valid; otherwise one error whose data
	 *                       lists every violation with its address.
	 */
	public static function validate( array $tree ) {
		$violations = array();
		$roots      = self::root_types();

		foreach ( $tree as $i => $block ) {
			$type = Saddle_Divi_Nesting::effective_type( $block );
			if ( null !== $type && ! in_array( $type, $roots, true ) ) {
				$violations[] = array(
					'address' => (string) $i,
					'type'    => (string) $block['blockName'],
					'problem' => sprintf( 'Only %s allowed at the page root.', implode( '/', $roots ) ),
				);
				continue; // Children of an invalid root are noise, skip them.
			}
			self::validate_children( $block, (string) $i, $violations );
		}

		if ( $violations ) {
			return new WP_Error(
				'saddle_invalid_structure',
				__( 'The Divi module tree is structurally invalid.', 'saddle' ),
				array( 'violations' => $violations )
			);
		}
		return true;
	}

	/**
	 * Recursively validate a block's children against Divi's nesting rule
	 * (Saddle_Divi_Nesting). A Component's own children are not judged:
	 * Divi renders the Library copy, and the Visual Builder stores a copy
	 * of the Component's tree inside it.
	 *
	 * @param array  $block      Parent block.
	 * @param string $address    Its address.
	 * @param array  $violations Accumulator (by reference).
	 */
	private static function validate_children( array $block, $address, array &$violations ) {
		$name = (string) $block['blockName'];
		if ( 'divi/global-layout' === $name ) {
			return;
		}

		foreach ( $block['innerBlocks'] as $i => $child ) {
			$child_name    = (string) $child['blockName'];
			$child_address = $address . '.' . $i;
			$child_ns      = strtok( $child_name, '/' );

			if ( in_array( $child_ns, self::foreign_namespaces(), true ) ) {
				$violations[] = array(
					'address' => $child_address,
					'type'    => $child_name,
					'problem' => 'Not a Divi module. Divi pages must contain builder modules only, never raw editor blocks.',
				);
				continue;
			}

			$verdict = Saddle_Divi_Nesting::accepts( $block, $child );
			if ( true !== $verdict ) {
				$violations[] = array(
					'address' => $child_address,
					'type'    => $child_name,
					'problem' => sprintf(
						/* translators: 1: child module type, 2: parent module type, 3: what the parent holds. */
						__( '%1$s cannot be placed inside %2$s. %2$s holds: %3$s.', 'saddle' ),
						$child_name,
						$name,
						$verdict
					),
				);
				continue;
			}

			self::validate_children( $child, $child_address, $violations );
		}
	}
}
