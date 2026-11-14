<?php
/**
 * Lint rule: more than one main action in a section.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A section asks the visitor for one thing. Two filled buttons of the same
 * weight (the custom button switch on, the same fill) compete, and neither
 * reads as the next step. The second and later ones get a note naming the
 * first.
 *
 * Narrow on purpose: only divi/button modules (a CTA or pricing module is
 * one action by itself), only buttons Divi renders filled (enable "on" and
 * a fill that is not transparent), never inside a loop, where the same
 * button repeats once per item. Divi pages only.
 */
class Saddle_Lint_Rule_One_Main_Action extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'one-main-action';
	}

	/**
	 * Flag a section's second filled button of the same weight.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}
		$facts      = new Saddle_Divi_Lint_Facts();
		$by_address = array_column( $nodes, null, 'address' );
		$violations = array();

		foreach ( $this->sections( $nodes ) as $section ) {
			$first = array();
			foreach ( Saddle_Lint::descendants_of( $nodes, $section['address'] ) as $node ) {
				if ( 'divi/button' !== $node['type'] || $this->in_loop( $node, $by_address ) ) {
					continue;
				}
				$fill = $facts->button_fill( $node['block'] );
				if ( null === $fill ) {
					continue;
				}
				$weight = Saddle_Lint_Color::normalize( $fill );
				if ( ! isset( $first[ $weight ] ) ) {
					$first[ $weight ] = $node['address'];
					continue;
				}
				$violations[] = $this->violation(
					$node['address'],
					Saddle_Lint::SEVERITY_NOTE,
					sprintf(
						/* translators: 1: address of the first button, 2: fill color. */
						__( 'This section already has a filled button of the same weight at %1$s (fill %2$s). Two equal buttons compete, so neither reads as the next step.', 'saddle' ),
						$first[ $weight ],
						$fill
					),
					sprintf(
						/* translators: %s: fill color. */
						__( 'Keep one main action. Make this one secondary with divi-edit-module on this address: attrs { "button.decoration.background.desktop.value.color": "transparent", "button.decoration.border.desktop.value.styles.all": { "width": "2px", "style": "solid", "color": "%s" } } and a text color that reads on the section. Keep "enable" on.', 'saddle' ),
						$fill
					)
				);
			}
		}
		return $violations;
	}

	/**
	 * Whether the node or an ancestor repeats through a Divi loop.
	 *
	 * @param array   $node       Flat node entry.
	 * @param array[] $by_address Address to entry map.
	 * @return bool
	 */
	private function in_loop( array $node, array $by_address ) {
		$current = $node;
		while ( $current ) {
			$loop = Saddle_Divi_Loop::value( $current['block'] );
			if ( is_array( $loop ) && isset( $loop['enable'] ) && 'on' === $loop['enable'] ) {
				return true;
			}
			$current = null !== $current['parent'] && isset( $by_address[ $current['parent'] ] ) ? $by_address[ $current['parent'] ] : null;
		}
		return false;
	}
}
