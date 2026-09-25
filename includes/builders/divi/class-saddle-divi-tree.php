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
 * observed on real Divi 5.8 pages. Content modules may nest child modules
 * (Divi's own slider/accordion and third-party parent/child pairs like
 * divitorque/accordion > accordion-item); what they may never contain is
 * structural chrome or a foreign (raw editor) block. Invalid structure is
 * REJECTED, never repaired — the page must stay Visual-Builder-editable at
 * every point in time, and an agent that produced bad structure needs the
 * error, not a silent fix.
 */
class Saddle_Divi_Tree extends Saddle_Tree {

	/**
	 * Resolve the insert index for a new child under $parent in $tree, clamping a
	 * requested $position (negative = append) to the parent's current child
	 * count. The one place add-module / move-module / apply-library-item share
	 * their "where does the new node go" logic.
	 *
	 * @param array      $tree     Block tree to insert into.
	 * @param string     $parent   Parent address ('' = the root list).
	 * @param int|string $position Requested position; negative appends.
	 * @return int|WP_Error Clamped insert index, or WP_Error if the parent address doesn't exist.
	 */
	public static function resolve_insert_position( array $tree, $parent, $position ) {
		if ( '' === $parent ) {
			$count = count( $tree );
		} else {
			$node = self::get( $tree, $parent );
			if ( ! $node ) {
				return new WP_Error( 'saddle_bad_address', sprintf( /* translators: %s: node address. */ __( 'No module at address %s.', 'saddle' ), $parent ) );
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
	 * with '*' meaning "any non-structural module".
	 *
	 * @return array<string, string[]>
	 */
	public static function structure() {
		/**
		 * Filter the Divi containment rules (v0.1: [placeholder >] section >
		 * row > column > leaf — the placeholder wrapper observed on real
		 * Divi 5.8 pages).
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
				'divi/column'            => array( '*' ),
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
			if ( ! in_array( (string) $block['blockName'], $roots, true ) ) {
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
	 * Recursively validate a block's children.
	 *
	 * Two regimes: STRUCTURAL blocks (placeholder/section/row/column) follow
	 * the containment map exactly. Everything else is a content module, and
	 * modules MAY nest child modules — what a module may never contain is
	 * structural chrome or a foreign (raw editor) block.
	 *
	 * @param array  $block      Parent block.
	 * @param string $address    Its address.
	 * @param array  $violations Accumulator (by reference).
	 */
	private static function validate_children( array $block, $address, array &$violations ) {
		$rules      = self::structure();
		$name       = (string) $block['blockName'];
		$allowed    = isset( $rules[ $name ] ) ? $rules[ $name ] : null;
		$structural = array_unique( array_merge( array_keys( $rules ), self::root_types() ) );

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

			if ( null === $allowed ) {
				// $name is a content module: child modules are fine, the
				// structural chrome is not.
				if ( in_array( $child_name, $structural, true ) ) {
					$violations[] = array(
						'address' => $child_address,
						'type'    => $child_name,
						'problem' => sprintf( '%1$s cannot be placed inside the module %2$s.', $child_name, $name ),
					);
					continue;
				}
			} else {
				$is_structural = in_array( $child_name, $structural, true );
				$fits          = in_array( $child_name, $allowed, true )
					|| ( in_array( '*', $allowed, true ) && ! $is_structural );

				if ( ! $fits ) {
					$violations[] = array(
						'address' => $child_address,
						'type'    => $child_name,
						'problem' => sprintf( '%1$s cannot be placed inside %2$s.', $child_name, $name ),
					);
					continue;
				}
			}

			self::validate_children( $child, $child_address, $violations );
		}
	}
}
