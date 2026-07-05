<?php
/**
 * Lint rule: inconsistent section padding.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One section breaking the page's vertical rhythm: when a clear majority of
 * top-level sections share the same vertical padding, the sections that
 * deviate get flagged. Pages where every section differs (no rhythm exists
 * to break — or the variation is deliberate) produce no violations; this
 * rule points at the odd one out, it doesn't impose a scale.
 */
class Saddle_Lint_Rule_Section_Padding extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'section-padding';
	}

	/**
	 * Flag sections whose vertical padding deviates from the page majority.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		$sections = array(); // Each: node, key ("top|bottom").
		foreach ( $this->sections( $nodes ) as $section ) {
			$padding = $accessor->padding( $section['block'] );
			if ( ! is_array( $padding ) ) {
				continue;
			}
			$top    = isset( $padding['top'] ) ? preg_replace( '/\s+/', '', strtolower( (string) $padding['top'] ) ) : '';
			$bottom = isset( $padding['bottom'] ) ? preg_replace( '/\s+/', '', strtolower( (string) $padding['bottom'] ) ) : '';
			if ( '' === $top && '' === $bottom ) {
				continue;
			}
			$sections[] = array(
				'node' => $section,
				'key'  => $top . '|' . $bottom,
			);
		}

		if ( count( $sections ) < 3 ) {
			return array();
		}

		// A strict majority value must exist; otherwise there is no rhythm to enforce.
		$counts = array_count_values( wp_list_pluck( $sections, 'key' ) );
		arsort( $counts, SORT_NUMERIC );
		$majority_key   = array_key_first( $counts );
		$majority_count = $counts[ $majority_key ];
		if ( $majority_count * 2 <= count( $sections ) ) {
			return array();
		}

		$violations = array();
		foreach ( $sections as $entry ) {
			if ( $entry['key'] === $majority_key ) {
				continue;
			}
			$violations[] = $this->violation(
				$entry['node']['address'],
				self::SEVERITY_WARN,
				sprintf(
					/* translators: %s: block/module type. */
					__( '%s breaks the page\'s vertical rhythm — most sections share one top/bottom padding, this one differs.', 'saddle' ),
					$entry['node']['type']
				),
				__( 'Match the other sections\' vertical padding (a consistent ~96px rhythm is the usual mark), unless this section is deliberately tighter, like a thin banner.', 'saddle' )
			);
		}
		return $violations;
	}
}
