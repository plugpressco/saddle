<?php
/**
 * Lint rule: the statistical-mean card row.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The "AI look" that survives even a committed brief: a row of three-plus
 * cards where EVERY tell co-occurs — same module type, identical explicit
 * corner radius, identical explicit padding, everything centered. That
 * combination is what a thousand generated landing pages share.
 *
 * Deliberately narrow so it never cries wolf: one matching tell alone
 * (matching cards in a row is good design) never fires; radius and padding
 * must be EXPLICITLY set and identical (all-unset means the theme is
 * styling them — fine); and the group gets ONE advisory at its container,
 * not one per card. Needs the companion style accessor; silent without it.
 */
class Saddle_Lint_Rule_Sibling_Monotony extends Saddle_Lint_Rule {

	/**
	 * Minimum siblings for a "row" to be judged.
	 */
	const MIN_SIBLINGS = 3;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'sibling-monotony';
	}

	/**
	 * Find groups of ≥3 siblings where every monotony tell co-occurs.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Lint_Style_Accessor ) {
			return array();
		}

		// Group content nodes by parent.
		$groups = array();
		foreach ( $nodes as $node ) {
			if ( null === $node['parent'] ) {
				continue;
			}
			$groups[ $node['parent'] ][] = $node;
		}

		$violations = array();
		foreach ( $groups as $parent_address => $siblings ) {
			if ( count( $siblings ) < self::MIN_SIBLINGS ) {
				continue;
			}

			// The card is often ONE module inside each column (row > column >
			// blurb). When every sibling wraps exactly one child, the children
			// are the real cards — judge them instead.
			$siblings = $this->collapse_single_children( $siblings, $groups );

			$types     = array();
			$radii     = array();
			$paddings  = array();
			$centered  = true;
			$judgeable = true;

			foreach ( $siblings as $sibling ) {
				$block   = $sibling['block'];
				$types[] = $sibling['type'];

				$radius = $accessor->border_radius( $block );
				if ( null === $radius ) {
					$judgeable = false;
					break;
				}
				$radii[] = $radius;

				$padding = $accessor->padding( $block );
				if ( null === $padding ) {
					$judgeable = false;
					break;
				}
				$paddings[] = wp_json_encode( $padding );

				if ( 'center' !== $accessor->alignment( $block ) ) {
					$centered = false;
					break;
				}
			}

			if ( ! $judgeable || ! $centered ) {
				continue;
			}
			if ( 1 !== count( array_unique( $types ) ) || 1 !== count( array_unique( $radii ) ) || 1 !== count( array_unique( $paddings ) ) ) {
				continue;
			}

			$violations[] = $this->violation(
				(string) $parent_address,
				self::SEVERITY_WARN,
				sprintf(
					/* translators: 1: sibling count, 2: module type. */
					__( '%1$d identical %2$s cards — same radius, same padding, all centered. This is the layout every generated page defaults to.', 'saddle' ),
					count( $siblings ),
					(string) $types[0]
				),
				__( 'Break the monotony deliberately: feature one card, vary the rhythm or emphasis, or left-align the copy — sameness should be a choice, not a default.', 'saddle' )
			);
		}
		return $violations;
	}

	/**
	 * When every sibling wraps exactly one child, return those children —
	 * one level only; a mixed or multi-child row is judged as-is.
	 *
	 * @param array[] $siblings Sibling node entries.
	 * @param array[] $groups   All nodes grouped by parent address.
	 * @return array[]
	 */
	private function collapse_single_children( array $siblings, array $groups ) {
		$children = array();
		foreach ( $siblings as $sibling ) {
			$own = isset( $groups[ $sibling['address'] ] ) ? $groups[ $sibling['address'] ] : array();
			if ( 1 !== count( $own ) ) {
				return $siblings;
			}
			$children[] = $own[0];
		}
		return $children;
	}
}
