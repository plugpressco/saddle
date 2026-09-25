<?php
/**
 * Divi findings for free Saddle's verify engine.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Divi half of saddle/verify-page's first two passes, in the free
 * engine's finding shape:
 *
 *  - structural: the persisted tree against the containment contract
 *    (Saddle_Divi_Tree::validate — the same validator every write runs,
 *    now re-proving what is actually in the database);
 *  - echo: every persisted node's attrs against its module.json
 *    (Saddle_Divi_Echo::check) — attr paths Divi will silently ignore
 *    are designs that never took effect.
 *
 * Judgment (pass three) needs no glue here: the lint accessor already
 * plugs in through saddle_lint_accessor.
 */
class Saddle_Divi_Verify {

	/**
	 * Structural + echo findings for a persisted Divi tree.
	 *
	 * @param array[] $tree Parsed persisted tree.
	 * @return array[] Findings: { address, source, severity, message, fix_hint }.
	 */
	public static function findings( array $tree ) {
		return array_merge( self::structural( $tree ), self::echoed( $tree ) );
	}

	/**
	 * The containment contract over the persisted tree.
	 *
	 * @param array[] $tree Parsed tree.
	 * @return array[]
	 */
	private static function structural( array $tree ) {
		$valid = Saddle_Divi_Tree::validate( $tree );
		if ( ! is_wp_error( $valid ) ) {
			return array();
		}

		$findings   = array();
		$violations = $valid->get_error_data();
		$violations = isset( $violations['violations'] ) && is_array( $violations['violations'] ) ? $violations['violations'] : array();
		foreach ( $violations as $violation ) {
			$findings[] = array(
				'address'  => isset( $violation['address'] ) ? (string) $violation['address'] : '',
				'source'   => 'structural',
				'severity' => 'error',
				'message'  => sprintf(
					/* translators: 1: module type, 2: the structural problem. */
					__( '%1$s: %2$s', 'saddle' ),
					isset( $violation['type'] ) ? (string) $violation['type'] : __( '(unknown module)', 'saddle' ),
					isset( $violation['problem'] ) ? (string) $violation['problem'] : __( 'breaks the Divi containment contract.', 'saddle' )
				),
				'fix_hint' => __( 'Restore the section > row > column > module skeleton at this address — the Visual Builder cannot edit a broken tree.', 'saddle' ),
			);
		}
		return $findings;
	}

	/**
	 * Applied-vs-ignored on every persisted node: what module.json proves
	 * Divi will drop.
	 *
	 * @param array[] $tree Parsed tree.
	 * @return array[]
	 */
	private static function echoed( array $tree ) {
		$findings = array();
		foreach ( Saddle_Lint::nodes( $tree ) as $node ) {
			$attrs = isset( $node['block']['attrs'] ) && is_array( $node['block']['attrs'] ) ? $node['block']['attrs'] : array();
			if ( ! $attrs || '' === $node['type'] ) {
				continue;
			}
			// unknown_module=false: the unknown-module LINT rule owns that
			// signal in verify, with its own severity and fix hint — the
			// echo pass here judges only proven-ignored attr paths.
			foreach ( Saddle_Divi_Echo::check( $node['type'], array(), $attrs, $node['address'], array( 'unknown_module' => false ) ) as $warning ) {
				$findings[] = array(
					'address'  => $node['address'],
					'source'   => 'echo',
					'severity' => 'error',
					'message'  => (string) $warning,
					'fix_hint' => __( 'This persisted attribute does nothing — rewrite it on a path from divi-get-style-schema, or remove it.', 'saddle' ),
				);
			}
		}
		return $findings;
	}
}
