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
 * switch (verified live: styling alone renders a ghost); Gutenberg's reports
 * the outline block style. Solid same-baseline buttons are the design
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
				__( 'Primary calls-to-action convert better solid. On Divi, styling a button also requires the enable switch ({ "…decoration.button.desktop.value.enable": "on" }); on Gutenberg, drop the outline style unless this is a deliberate secondary button.', 'saddle' )
			);
		}
		return $violations;
	}
}
