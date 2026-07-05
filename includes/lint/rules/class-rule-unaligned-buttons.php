<?php
/**
 * Lint rule: unaligned sibling buttons.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sibling buttons with different alignments — one centered, one left — sit
 * off-baseline and make a section look broken. Buttons whose alignment the
 * accessor can't determine are skipped; the rule only compares explicit,
 * differing values within one sibling group.
 */
class Saddle_Lint_Rule_Unaligned_Buttons extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'unaligned-buttons';
	}

	/**
	 * Flag sibling buttons that disagree with their group's first alignment.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		// Group buttons with an explicit alignment by parent address.
		$groups = array();
		foreach ( $nodes as $node ) {
			if ( ! $accessor->is_button( $node['block'] ) ) {
				continue;
			}
			$alignment = $accessor->alignment( $node['block'] );
			if ( null === $alignment || '' === $alignment ) {
				continue;
			}
			$groups[ (string) $node['parent'] ][] = array(
				'node'      => $node,
				'alignment' => (string) $alignment,
			);
		}

		$violations = array();
		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}
			$baseline = $group[0]['alignment'];
			foreach ( array_slice( $group, 1 ) as $entry ) {
				if ( $entry['alignment'] === $baseline ) {
					continue;
				}
				$violations[] = $this->violation(
					$entry['node']['address'],
					self::SEVERITY_WARN,
					sprintf(
						/* translators: 1: alignment, 2: sibling alignment. */
						__( 'This button is aligned "%1$s" while its sibling button is aligned "%2$s" — they won\'t share a baseline.', 'saddle' ),
						$entry['alignment'],
						$baseline
					),
					__( 'Give sibling buttons the same alignment (or put them in one row container that owns the alignment).', 'saddle' )
				);
			}
		}
		return $violations;
	}
}
