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
 *    are designs that never took effect; and every loop that is on but
 *    saved in a shape Divi reads differently (Saddle_Divi_Loop_Repair).
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
		return array_merge( self::structural( $tree ), self::echoed( $tree ), self::loops( $tree ) );
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
					_x( '%1$s: %2$s', 'Divi structural finding', 'saddle' ),
					isset( $violation['type'] ) ? (string) $violation['type'] : __( '(unknown module)', 'saddle' ),
					isset( $violation['problem'] ) ? (string) $violation['problem'] : __( 'breaks the Divi containment contract.', 'saddle' )
				),
				'fix_hint' => __( 'Restore the section > row > column > module skeleton at this address. The Visual Builder cannot edit a broken tree.', 'saddle' ),
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
		$nodes    = Saddle_Lint::nodes( $tree );
		$versions = array();
		foreach ( $nodes as $node ) {
			if ( isset( $node['block']['attrs']['builderVersion'] ) && is_string( $node['block']['attrs']['builderVersion'] ) ) {
				$versions[] = $node['block']['attrs']['builderVersion'];
			}
		}
		foreach ( $nodes as $node ) {
			$attrs = isset( $node['block']['attrs'] ) && is_array( $node['block']['attrs'] ) ? $node['block']['attrs'] : array();
			if ( ! $attrs || '' === $node['type'] ) {
				continue;
			}
			// Judge text case as the page renders: Divi still converts it on
			// a node saved before 5.8.1, unless the page holds a newer one.
			if ( Saddle_Divi_Echo_Traps::text_case_migrates( isset( $attrs['builderVersion'] ) ? $attrs['builderVersion'] : null, $versions ) ) {
				$attrs = Saddle_Divi_Echo_Traps::text_case_as_rendered( $attrs );
			}
			// unknown_module=false: the unknown-module LINT rule owns that
			// signal in verify, with its own severity and fix hint — the
			// echo pass here judges only proven-ignored attr paths.
			// advisory=false: only what Divi provably drops or misreads is
			// scored as an error; a value that renders differently than
			// meant stays a write-time note.
			$options = array(
				'unknown_module' => false,
				'advisory'       => false,
			);
			foreach ( Saddle_Divi_Echo::check( $node['type'], array(), $attrs, $node['address'], $options ) as $warning ) {
				$findings[] = array(
					'address'  => $node['address'],
					'source'   => 'echo',
					'severity' => 'error',
					'message'  => (string) $warning,
					'fix_hint' => __( 'Divi ignores this saved attribute or value. Rewrite it the way the message says, using the paths from divi-get-style-schema, or remove it.', 'saddle' ),
				);
			}
		}
		return $findings;
	}

	/**
	 * Loops turned on and saved in a shape Divi reads differently from what
	 * was meant (Saddle_Divi_Loop_Repair::problems()).
	 *
	 * @param array[] $tree Parsed tree.
	 * @return array[]
	 */
	private static function loops( array $tree ) {
		$findings = array();
		foreach ( Saddle_Lint::nodes( $tree ) as $node ) {
			$value = isset( $node['block'] ) && is_array( $node['block'] ) ? Saddle_Divi_Loop::value( $node['block'] ) : array();
			if ( ! $value || ! isset( $value['enable'] ) || 'on' !== $value['enable'] ) {
				continue;
			}
			$lines = Saddle_Divi_Loop_Repair::explain( $value, sprintf( '%1$s (node %2$s)', $node['type'], $node['address'] ) );
			if ( ! $lines ) {
				continue;
			}
			$findings[] = array(
				'address'  => $node['address'],
				'source'   => 'echo',
				'severity' => 'error',
				'message'  => implode( ' ', $lines ),
				'fix_hint' => __( 'Run divi-edit-loop on this address (no changes needed) to save it in the current format.', 'saddle' ),
			);
		}
		return $findings;
	}
}
