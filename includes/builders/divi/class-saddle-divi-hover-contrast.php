<?php
/**
 * Lint rule: text contrast on hover, for Divi pages.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * text-contrast and button-contrast judge the colors at rest. A Divi module
 * can set its own hover text or background color
 * (<attr>.decoration.background.desktop.hover.color), and a hover that fails
 * WCAG AA is as unreadable as a resting state that does: the pointer is on
 * it the moment someone reads it. This rule judges that pair, only on nodes
 * that set a hover color themselves, and only when the hover pair differs
 * from the resting one (the resting rules already judged that).
 *
 * The background is the node's own hover color, else its resting color,
 * else the nearest ancestor's, read at hover too (pointing at a child
 * hovers its ancestors). Unknown or unresolvable colors skip, never guess.
 */
class Saddle_Divi_Lint_Rule_Hover_Contrast extends Saddle_Lint_Rule {

	/**
	 * WCAG AA minimum for normal text and for buttons.
	 */
	const MINIMUM = 4.5;

	/**
	 * WCAG AA minimum for large text (24px and up, or any heading).
	 */
	const MINIMUM_LARGE = 3.0;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'hover-contrast';
	}

	/**
	 * Flag hover color pairs below AA.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}

		$by_address = array();
		foreach ( $nodes as $node ) {
			$by_address[ $node['address'] ] = $node;
		}

		$violations = array();
		foreach ( $nodes as $node ) {
			$block = $node['block'];
			if ( ! $accessor->sets_state_color( $block, 'hover' ) ) {
				continue;
			}

			$text       = $accessor->text_color_at( $block, 'desktop', 'hover' );
			$background = $this->background( $node, $by_address, $accessor, 'hover' );
			if ( null === $text || null === $background ) {
				continue;
			}
			$same_text       = Saddle_Lint_Color::normalize( $text ) === Saddle_Lint_Color::normalize( (string) $accessor->text_color( $block ) );
			$same_background = Saddle_Lint_Color::normalize( $background ) === Saddle_Lint_Color::normalize( (string) $this->background( $node, $by_address, $accessor, 'value' ) );
			if ( $same_text && $same_background ) {
				continue;
			}

			$ratio = Saddle_Lint_Color::contrast( $text, $background );
			if ( null === $ratio ) {
				continue;
			}
			$minimum = ! $accessor->is_button( $block ) && $this->is_large( $block, $accessor ) ? self::MINIMUM_LARGE : self::MINIMUM;
			if ( $ratio >= $minimum ) {
				continue;
			}

			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_ERROR,
				sprintf(
					/* translators: 1: text color, 2: background color, 3: contrast ratio, 4: required minimum. */
					__( 'On hover, text %1$s on background %2$s has a contrast of %3$s:1, below the WCAG AA minimum of %4$s:1.', 'saddle' ),
					$text,
					$background,
					number_format_i18n( $ratio, 2 ),
					number_format_i18n( $minimum, 1 )
				),
				__( 'Change the hover text or background color so the pair reaches WCAG AA, or remove the hover color so the colors at rest stay.', 'saddle' )
			);
		}
		return $violations;
	}

	/**
	 * The background the node's text sits on in a state: its own, else the
	 * nearest ancestor's.
	 *
	 * @param array                     $node       Node entry.
	 * @param array[]                   $by_address Address → node map.
	 * @param Saddle_Divi_Lint_Accessor $accessor   Divi accessor.
	 * @param string                    $state      value or hover.
	 * @return string|null
	 */
	private function background( array $node, array $by_address, Saddle_Divi_Lint_Accessor $accessor, $state ) {
		$current = $node;
		while ( $current ) {
			$background = $accessor->background_color_at( $current['block'], 'desktop', $state );
			if ( null !== $background ) {
				return $background;
			}
			$parent  = $current['parent'];
			$current = null !== $parent && isset( $by_address[ $parent ] ) ? $by_address[ $parent ] : null;
		}
		return null;
	}

	/**
	 * WCAG large text: any heading, or 24px and up (px only).
	 *
	 * @param array                     $block    Raw block array.
	 * @param Saddle_Divi_Lint_Accessor $accessor Divi accessor.
	 * @return bool
	 */
	private function is_large( array $block, Saddle_Divi_Lint_Accessor $accessor ) {
		if ( null !== $accessor->heading_level( $block ) ) {
			return true;
		}
		$size = $accessor->font_size( $block );
		return is_string( $size ) && preg_match( '/^(\d+(?:\.\d+)?)px$/', trim( $size ), $m ) && (float) $m[1] >= 24;
	}
}
