<?php
/**
 * Lint rule: mixed accent colors.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Buttons across the page drawing from more than one hue family. One accent
 * plus neutrals is the design rule Saddle teaches; a page whose CTAs are
 * blue here and orange there reads as unfinished. Neutral fills (grays,
 * near-black/near-white) never count as accents, and unresolvable colors are
 * skipped, so themes that mix a colored primary with neutral secondaries
 * pass clean.
 */
class Saddle_Lint_Rule_Mixed_Accents extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mixed-accents';
	}

	/**
	 * Flag buttons whose accent hue strays from the page's dominant family.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		$accents = array(); // Each: node, color, family.
		foreach ( $nodes as $node ) {
			if ( ! $accessor->is_button( $node['block'] ) ) {
				continue;
			}
			$color = $accessor->background_color( $node['block'] );
			$rgb   = Saddle_Lint_Color::parse( (string) $color );
			if ( ! $rgb || Saddle_Lint_Color::is_neutral( $rgb ) ) {
				continue;
			}
			$accents[] = array(
				'node'   => $node,
				'color'  => $color,
				'family' => Saddle_Lint_Color::hue_family( $rgb ),
			);
		}

		$families = array_unique( wp_list_pluck( $accents, 'family' ) );
		if ( count( $families ) < 2 ) {
			return array();
		}

		// Dominant family = the most used one; first seen wins a tie.
		$counts = array_count_values( wp_list_pluck( $accents, 'family' ) );
		arsort( $counts, SORT_NUMERIC );
		$dominant = array_key_first( $counts );

		$violations = array();
		foreach ( $accents as $accent ) {
			if ( $accent['family'] === $dominant ) {
				continue;
			}
			$violations[] = $this->violation(
				$accent['node']['address'],
				self::SEVERITY_WARN,
				sprintf(
					/* translators: %s: color. */
					__( 'This button\'s accent %s belongs to a different hue family than the page\'s dominant accent — mixed accents read as unfinished.', 'saddle' ),
					$accent['color']
				),
				__( 'Use ONE accent color for all primary buttons (neutrals are fine for secondary actions), ideally a design-token color so it follows redesigns.', 'saddle' )
			);
		}
		return $violations;
	}
}
