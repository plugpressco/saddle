<?php
/**
 * Lint rule: a filled button that does not change on hover.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A filled button that looks the same under the pointer gives no feedback
 * that it is clickable. Divi's own default already gives one: its icon
 * slides in on hover (button.decoration.button.desktop.value.icon, enable
 * and onHover both "on" unless set, ButtonIcon in Divi 5.10.1). So this
 * fires only when that icon is switched off or always shown, AND the button
 * sets no hover color, border, shadow, transform or filter of its own, AND
 * no preset is bound (a preset may carry the hover). Divi pages only.
 */
class Saddle_Lint_Rule_Button_Hover extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'button-hover';
	}

	/**
	 * Flag filled buttons with no visible hover change.
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
		$violations = array();
		foreach ( $nodes as $node ) {
			if ( 'divi/button' !== $node['type'] ) {
				continue;
			}
			$fill = $facts->button_fill( $node['block'] );
			if ( null === $fill || $facts->button_has_hover( $node['block'] ) ) {
				continue;
			}
			$violations[] = $this->violation(
				$node['address'],
				Saddle_Lint::SEVERITY_NOTE,
				__( 'This filled button looks the same on hover: its icon does not slide in and it sets no hover color, border or shadow. Visitors get no sign that it is clickable.', 'saddle' ),
				sprintf(
					/* translators: %s: the button's fill color. */
					__( 'Give it a hover state with divi-edit-module on this address, for example attrs { "button.decoration.background.desktop.hover.color": "<a shade darker than %s>" }, plus "module.decoration.transition.desktop.value": { "duration": "200ms" } so it eases.', 'saddle' ),
					$fill
				)
			);
		}
		return $violations;
	}
}
