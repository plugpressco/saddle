<?php
/**
 * Lint rule: broken heading hierarchy.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two heading-structure breaks screen-reader users actually hit: a level
 * skipped on the way down (h2 → h4 orphans the missing h3 in the outline),
 * and more than one h1 (the page loses its single top landmark). Moving UP
 * levels (h4 → h2, a new section) is always fine. The first heading is never
 * judged against an invisible predecessor — the page title usually lives
 * outside the content tree.
 */
class Saddle_Lint_Rule_Heading_Order extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'heading-order';
	}

	/**
	 * Flag skipped heading levels and duplicate h1s, in document order.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Lint_Style_Accessor ) {
			return array();
		}

		$violations = array();
		$previous   = null;
		$h1_seen    = false;

		foreach ( $nodes as $node ) {
			$level = $accessor->heading_level( $node['block'] );
			if ( null === $level ) {
				continue;
			}

			if ( 1 === $level ) {
				if ( $h1_seen ) {
					$violations[] = $this->violation(
						$node['address'],
						self::SEVERITY_WARN,
						__( 'More than one h1 on the page — assistive tech expects a single top-level heading.', 'saddle' ),
						__( 'Keep one h1 and demote the others to h2.', 'saddle' )
					);
				}
				$h1_seen = true;
			}

			if ( null !== $previous && $level > $previous + 1 ) {
				$violations[] = $this->violation(
					$node['address'],
					self::SEVERITY_WARN,
					sprintf(
						/* translators: 1: previous heading level, 2: this heading level. */
						__( 'Heading level jumps from h%1$d to h%2$d — the skipped level breaks the outline for screen readers.', 'saddle' ),
						$previous,
						$level
					),
					sprintf(
						/* translators: %d: expected next heading level. */
						__( 'Use h%d here, or restructure so no level is skipped.', 'saddle' ),
						$previous + 1
					)
				);
			}

			$previous = $level;
		}
		return $violations;
	}
}
