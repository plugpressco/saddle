<?php
/**
 * Lint rule: ghost buttons.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A button rendering as ghost/outline instead of a solid fill. What counts
 * as "unintentionally unfilled" is the accessor's call per builder: Divi's
 * accessor reports a button styled without the custom-button enable=on
 * switch, on Divi before 5.10 only (seen there; on 5.10.1 the fill shows
 * without it); Gutenberg's reports the outline block style. Solid same-baseline buttons are the design
 * default Saddle pushes agents toward, so this stays a warning, not an error.
 */
class Saddle_Lint_Rule_Ghost_Button extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'ghost-button';
	}

	/**
	 * Flag buttons the accessor reports as not filled.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		$violations = array();
		foreach ( $nodes as $node ) {
			if ( ! $accessor->is_button( $node['block'] ) || $accessor->button_is_filled( $node['block'] ) ) {
				continue;
			}
			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_WARN,
				sprintf(
					/* translators: %s: block/module type. */
					__( '%s renders as a ghost/outline button, not a solid fill.', 'saddle' ),
					$node['type']
				),
				__( 'Primary calls-to-action convert better solid. On Divi, set the button\'s enable switch as well ({ "button.decoration.button.desktop.value.enable": "on" }): before Divi 5.10 the fill does not show without it. On Gutenberg, drop the outline style unless this is a deliberate secondary button.', 'saddle' )
			);
		}
		return $violations;
	}
}
