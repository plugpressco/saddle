<?php
/**
 * Lint rule: links and buttons without meaningful text.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A link or button a screen reader can't name is an error: it is announced as
 * "link" and nothing else (WCAG 2.4.4, 4.1.2). One named only "click here" or
 * "read more" is a warning: in a list of the page's links it says nothing
 * about where it goes (#183).
 */
class Saddle_Lint_Rule_Link_Text extends Saddle_Lint_Rule {

	/**
	 * Link texts that say nothing about the destination.
	 */
	const VAGUE = array( 'click here', 'here', 'read more', 'more', 'learn more', 'this', 'link', 'click', 'go', 'details' );

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'link-text';
	}

	/**
	 * Flag unnamed and vaguely named links and buttons.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Lint_Link_Accessor ) {
			return array();
		}

		$violations = array();
		foreach ( $nodes as $node ) {
			foreach ( $accessor->links( $node['block'] ) as $link ) {
				$name = strtolower( trim( preg_replace( '/[\s.…!?:»›→]+/u', ' ', $link['name'] ) ) );
				if ( '' === $name ) {
					$violations[] = $this->violation(
						$node['address'],
						self::SEVERITY_ERROR,
						$link['button']
							? __( 'Button has no text — a screen reader announces it as just "button".', 'saddle' )
							: __( 'Link has no text — a screen reader announces it as just "link".', 'saddle' ),
						__( 'Give it visible text that says what it does, or an aria-label when it is an icon.', 'saddle' )
					);
				} elseif ( in_array( $name, self::VAGUE, true ) ) {
					$violations[] = $this->violation(
						$node['address'],
						self::SEVERITY_WARN,
						sprintf(
							/* translators: %s: the link text. */
							__( 'Link text "%s" says nothing about where it goes when read out of context.', 'saddle' ),
							$link['name']
						),
						__( 'Name the destination, e.g. "Read the pricing guide" instead of "Read more".', 'saddle' )
					);
				}
			}
		}
		return $violations;
	}
}
