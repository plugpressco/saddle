<?php
/**
 * Lint rule: double background.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A node that sets the same background color its nearest painted ancestor
 * already set. The band the agent thought it was painting is invisible, and
 * the redundant setting makes the next redesign edit twice as many places.
 */
class Saddle_Lint_Rule_Double_Background extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'double-background';
	}

	/**
	 * Flag nodes repeating their nearest painted ancestor's background.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		$by_address  = array();
		$backgrounds = array();
		foreach ( $nodes as $node ) {
			$by_address[ $node['address'] ]  = $node;
			$backgrounds[ $node['address'] ] = $accessor->background_color( $node['block'] );
		}

		$violations = array();
		foreach ( $nodes as $node ) {
			$own = $backgrounds[ $node['address'] ];
			if ( null === $own || '' === $own ) {
				continue;
			}

			// Walk up to the nearest ancestor that paints a background.
			$parent = $node['parent'];
			while ( null !== $parent ) {
				$ancestor_background = $backgrounds[ $parent ];
				if ( null !== $ancestor_background && '' !== $ancestor_background ) {
					if ( Saddle_Lint_Color::normalize( $own ) === Saddle_Lint_Color::normalize( $ancestor_background ) ) {
						$violations[] = $this->violation(
							$node['address'],
							self::SEVERITY_WARN,
							sprintf(
								/* translators: 1: block/module type, 2: color, 3: ancestor address. */
								__( '%1$s sets background %2$s, the same background its container at %3$s already has.', 'saddle' ),
								$node['type'],
								$own,
								$parent
							),
							__( 'Remove the inner background (it inherits), or pick a genuinely different color if this was meant to read as a band.', 'saddle' )
						);
					}
					break;
				}
				$parent = $by_address[ $parent ]['parent'];
			}
		}
		return $violations;
	}
}
