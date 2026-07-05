<?php
/**
 * Lint rule: no featured plan in an equal card row.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A row of three or more identical-looking cards that each carry a button —
 * the pricing-table shape — where nothing is emphasized. Good pricing
 * sections feature one plan (distinct background, border, or scale); a row
 * of equals gives the visitor no recommendation. Fires once, on the row
 * container, and only when every card's background is identical (including
 * all-unset), so a row that already emphasizes one card passes clean.
 */
class Saddle_Lint_Rule_Featured_Plan extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'no-featured-plan';
	}

	/**
	 * Flag rows of ≥3 equal, button-carrying cards.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		// All container addresses → their direct children.
		$children_of = array();
		foreach ( $nodes as $node ) {
			if ( null !== $node['parent'] ) {
				$children_of[ $node['parent'] ][] = $node;
			}
		}

		$violations = array();
		foreach ( $children_of as $parent_address => $cards ) {
			if ( count( $cards ) < 3 ) {
				continue;
			}

			$backgrounds = array();
			$all_carded  = true;
			foreach ( $cards as $card ) {
				// Every card must contain a button somewhere below it.
				$has_button = false;
				foreach ( $nodes as $candidate ) {
					if ( $this->is_descendant( $candidate, $card['address'] ) && $accessor->is_button( $candidate['block'] ) ) {
						$has_button = true;
						break;
					}
				}
				if ( ! $has_button ) {
					$all_carded = false;
					break;
				}
				$background    = $accessor->background_color( $card['block'] );
				$backgrounds[] = null === $background ? '' : Saddle_Lint_Color::normalize( $background );
			}

			if ( ! $all_carded || 1 !== count( array_unique( $backgrounds ) ) ) {
				continue;
			}

			$violations[] = $this->violation(
				$parent_address,
				self::SEVERITY_WARN,
				sprintf(
					/* translators: %d: card count. */
					__( 'A row of %d equal cards, each with a button, and none stands out. If this is pricing, no plan is featured.', 'saddle' ),
					count( $cards )
				),
				__( 'Emphasize the recommended card: a distinct background or border, a "Most popular" tag, or a filled button while the others stay outline.', 'saddle' )
			);
		}
		return $violations;
	}
}
