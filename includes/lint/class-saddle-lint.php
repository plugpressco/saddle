<?php
/**
 * The builder-agnostic design lint engine.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs design-quality rules over a parsed block tree (DESIGN-PLAN.md §2.1).
 *
 * The engine is generic: it flattens the tree into an addressable node list,
 * hands that list plus a builder accessor to each rule, and collects
 * violations. Everything builder-specific — how to read a node's colors,
 * buttons, alignment — lives behind Saddle_Lint_Accessor; everything
 * judgmental lives in the one-class-per-rule files under lint/rules/.
 *
 * Violations diagnose, they never block: lint-page is a read tool an agent
 * runs after building, then fixes what it agrees with. A rule that cannot
 * establish a fact (unresolvable color, unknown padding) stays silent —
 * a lint that cries wolf gets ignored.
 */
class Saddle_Lint {

	/**
	 * Lint a block tree.
	 *
	 * @param array[]              $tree     Parsed block tree (Saddle_Tree::parse()).
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[] Violations: { address, rule, severity(error|warn),
	 *                 message, fix_hint }, in document order.
	 */
	public static function run( array $tree, Saddle_Lint_Accessor $accessor ) {
		$nodes = self::nodes( $tree );

		/**
		 * Filter the lint rule set.
		 *
		 * The single registration point for rules: each entry is a
		 * Saddle_Lint_Rule instance. Add, remove, or replace rules here.
		 *
		 * @param Saddle_Lint_Rule[]   $rules    Rule instances.
		 * @param Saddle_Lint_Accessor $accessor The accessor about to be used.
		 */
		$rules = apply_filters( 'saddle_lint_rules', self::default_rules(), $accessor );

		$violations = array();
		foreach ( $rules as $rule ) {
			if ( ! $rule instanceof Saddle_Lint_Rule ) {
				continue;
			}
			foreach ( (array) $rule->check( $nodes, $accessor ) as $violation ) {
				$violations[] = array(
					'address'  => isset( $violation['address'] ) ? (string) $violation['address'] : '',
					'rule'     => $rule->id(),
					'severity' => isset( $violation['severity'] ) && Saddle_Lint_Rule::SEVERITY_ERROR === $violation['severity']
						? Saddle_Lint_Rule::SEVERITY_ERROR
						: Saddle_Lint_Rule::SEVERITY_WARN,
					'message'  => isset( $violation['message'] ) ? (string) $violation['message'] : '',
					'fix_hint' => isset( $violation['fix_hint'] ) ? (string) $violation['fix_hint'] : '',
				);
			}
		}

		usort( $violations, array( __CLASS__, 'compare_addresses' ) );
		return $violations;
	}

	/**
	 * The built-in rule set.
	 *
	 * @return Saddle_Lint_Rule[]
	 */
	private static function default_rules() {
		return array(
			new Saddle_Lint_Rule_Empty_Title(),
			new Saddle_Lint_Rule_Button_Contrast(),
			new Saddle_Lint_Rule_Ghost_Button(),
			new Saddle_Lint_Rule_Double_Background(),
			new Saddle_Lint_Rule_Mixed_Accents(),
			new Saddle_Lint_Rule_Unaligned_Buttons(),
			new Saddle_Lint_Rule_Section_Padding(),
			new Saddle_Lint_Rule_Featured_Plan(),
		);
	}

	/**
	 * Flatten a tree into the node list rules work on.
	 *
	 * @param array[] $tree Block tree.
	 * @return array[] Each: address, type, block, parent, depth. Document order.
	 */
	public static function nodes( array $tree ) {
		$nodes = array();
		self::walk( $tree, null, 0, $nodes );
		return $nodes;
	}

	/**
	 * Depth-first walk.
	 *
	 * @param array[]     $blocks Sibling blocks.
	 * @param string|null $parent_address Parent address.
	 * @param int         $depth  Depth from root.
	 * @param array       $nodes  Accumulator (by reference).
	 */
	private static function walk( array $blocks, $parent_address, $depth, array &$nodes ) {
		foreach ( array_values( $blocks ) as $i => $block ) {
			$address = null === $parent_address ? (string) $i : $parent_address . '.' . $i;
			$nodes[] = array(
				'address' => $address,
				'type'    => (string) $block['blockName'],
				'block'   => $block,
				'parent'  => $parent_address,
				'depth'   => $depth,
			);
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $address, $depth + 1, $nodes );
			}
		}
	}

	/**
	 * Document-order comparison of two violations by dot address.
	 *
	 * @param array $a First violation.
	 * @param array $b Second violation.
	 * @return int
	 */
	private static function compare_addresses( array $a, array $b ) {
		$pa = '' === $a['address'] ? array() : array_map( 'intval', explode( '.', $a['address'] ) );
		$pb = '' === $b['address'] ? array() : array_map( 'intval', explode( '.', $b['address'] ) );

		$len = max( count( $pa ), count( $pb ) );
		for ( $i = 0; $i < $len; $i++ ) {
			$va = isset( $pa[ $i ] ) ? $pa[ $i ] : -1;
			$vb = isset( $pb[ $i ] ) ? $pb[ $i ] : -1;
			if ( $va !== $vb ) {
				return $va < $vb ? -1 : 1;
			}
		}
		return strcmp( $a['rule'], $b['rule'] );
	}
}
