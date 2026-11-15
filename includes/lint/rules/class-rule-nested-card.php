<?php
/**
 * Lint rule: a card inside a card.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A card is a group or column with its own fill and a radius or a shadow.
 * One directly inside another stacks two frames, two paddings and two
 * corner shapes around the same content, which reads as clutter. Only the
 * direct parent counts. Divi pages only.
 */
class Saddle_Lint_Rule_Nested_Card extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'nested-card';
	}

	/**
	 * Flag cards whose direct parent is a card.
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
		foreach ( $nodes as $node ) {
			$parent = $facts->parent_of( $node, $by_address );
			if ( ! $parent || ! $facts->is_card( $node['block'] ) || ! $facts->is_card( $parent['block'] ) ) {
				continue;
			}
			$violations[] = $this->violation(
				$node['address'],
				Saddle_Lint::SEVERITY_NOTE,
				sprintf(
					/* translators: %s: address of the outer card. */
					__( 'This card sits directly inside another card (%s). Two frames around the same content read as clutter.', 'saddle' ),
					$parent['address']
				),
				__( 'Keep one frame. Remove this one\'s fill, radius and shadow with divi-edit-module on this address: unset ["module.decoration.background", "module.decoration.border", "module.decoration.boxShadow"]. Or remove them from the outer card instead.', 'saddle' )
			);
		}
		return $violations;
	}
}
