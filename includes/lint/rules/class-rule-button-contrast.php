<?php
/**
 * Lint rule: button text contrast below WCAG AA.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A button whose label fails WCAG AA contrast (4.5:1) against its own
 * background — the classic "white text on the brand's light accent" miss.
 * Only fires when BOTH colors resolve to real hex values; a color the
 * accessor couldn't resolve (gradient, unresolved variable) is skipped, never
 * guessed at.
 */
class Saddle_Lint_Rule_Button_Contrast extends Saddle_Lint_Rule {

	/**
	 * WCAG AA minimum contrast for normal text.
	 */
	const MINIMUM = 4.5;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'button-contrast';
	}

	/**
	 * Flag buttons whose text/background contrast is below AA.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		$violations = array();
		foreach ( $nodes as $node ) {
			if ( ! $accessor->is_button( $node['block'] ) ) {
				continue;
			}

			$background = $accessor->background_color( $node['block'] );
			$text       = $accessor->text_color( $node['block'] );
			$ratio      = Saddle_Lint_Color::contrast( (string) $text, (string) $background );
			if ( null === $ratio || $ratio >= self::MINIMUM ) {
				continue;
			}

			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_ERROR,
				sprintf(
					/* translators: 1: text color, 2: background color, 3: contrast ratio. */
					__( 'Button text %1$s on background %2$s has a contrast of %3$s:1 — below the WCAG AA minimum of 4.5:1.', 'saddle' ),
					$text,
					$background,
					number_format_i18n( $ratio, 2 )
				),
				__( 'Darken the background or switch the text color so the pair reaches at least 4.5:1.', 'saddle' )
			);
		}
		return $violations;
	}
}
