<?php
/**
 * Lint rule: text contrast below WCAG AA.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Any text whose color fails WCAG AA against its effective background — the
 * node's own background, or the nearest ancestor's when the node paints none.
 * Generalizes button-contrast to all text; buttons stay that rule's job so a
 * bad button is flagged once, not twice.
 *
 * Thresholds follow WCAG AA: 4.5:1 for normal text, 3:1 for large text.
 * Headings and text at ≥24px count as large — rendered weight is unknowable
 * from the tree, so the rule under-flags rather than over-flags. Needs the
 * companion style accessor for the large-text call; without it the rule
 * stays silent (skip, never guess).
 */
class Saddle_Lint_Rule_Text_Contrast extends Saddle_Lint_Rule {

	/**
	 * WCAG AA minimum for normal text.
	 */
	const MINIMUM = 4.5;

	/**
	 * WCAG AA minimum for large text (≥24px, or any heading).
	 */
	const MINIMUM_LARGE = 3.0;

	/**
	 * Font size, in px, from which text counts as large.
	 */
	const LARGE_PX = 24;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'text-contrast';
	}

	/**
	 * Flag text whose contrast against its effective background is below AA.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Lint_Style_Accessor ) {
			return array();
		}

		$by_address = array();
		foreach ( $nodes as $node ) {
			$by_address[ $node['address'] ] = $node;
		}

		$violations = array();
		foreach ( $nodes as $node ) {
			// Buttons are button-contrast's job — one finding per problem.
			if ( $accessor->is_button( $node['block'] ) ) {
				continue;
			}

			$text = $accessor->text_color( $node['block'] );
			if ( null === $text ) {
				continue;
			}

			$background = $this->effective_background( $node, $by_address, $accessor );
			$ratio      = Saddle_Lint_Color::contrast( (string) $text, (string) $background );
			if ( null === $ratio ) {
				continue;
			}

			$minimum = $this->is_large( $node, $accessor ) ? self::MINIMUM_LARGE : self::MINIMUM;
			if ( $ratio >= $minimum ) {
				continue;
			}

			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_ERROR,
				sprintf(
					/* translators: 1: text color, 2: background color, 3: contrast ratio, 4: required minimum. */
					__( 'Text %1$s on background %2$s has a contrast of %3$s:1 — below the WCAG AA minimum of %4$s:1.', 'saddle' ),
					$text,
					$background,
					number_format_i18n( $ratio, 2 ),
					number_format_i18n( $minimum, 1 )
				),
				__( 'Darken the text or lighten the background (or vice versa) so the pair reaches WCAG AA.', 'saddle' )
			);
		}
		return $violations;
	}

	/**
	 * The background this node's text actually sits on: its own, or the
	 * nearest ancestor's. Null when nothing up the chain resolves — an
	 * unknown page background is never guessed at.
	 *
	 * @param array                $node       Node entry.
	 * @param array[]              $by_address Address → node map.
	 * @param Saddle_Lint_Accessor $accessor   Builder accessor.
	 * @return string|null
	 */
	private function effective_background( array $node, array $by_address, Saddle_Lint_Accessor $accessor ) {
		$current = $node;
		while ( $current ) {
			$background = $accessor->background_color( $current['block'] );
			if ( null !== $background ) {
				return $background;
			}
			$parent  = $current['parent'];
			$current = null !== $parent && isset( $by_address[ $parent ] ) ? $by_address[ $parent ] : null;
		}
		return null;
	}

	/**
	 * Whether the node's text counts as WCAG "large": any heading, or a font
	 * size of at least 24px. Only px sizes are compared — rem/em/clamp depend
	 * on context the tree can't know, so they fall back to the normal-text
	 * threshold (the stricter, safer direction... for the ratio; for the
	 * threshold choice the heading call is the under-flagging one).
	 *
	 * @param array                      $node     Node entry.
	 * @param Saddle_Lint_Style_Accessor $accessor Style accessor.
	 * @return bool
	 */
	private function is_large( array $node, Saddle_Lint_Style_Accessor $accessor ) {
		if ( null !== $accessor->heading_level( $node['block'] ) ) {
			return true;
		}
		$size = $accessor->font_size( $node['block'] );
		if ( is_string( $size ) && preg_match( '/^(\d+(?:\.\d+)?)px$/', trim( $size ), $m ) ) {
			return (float) $m[1] >= self::LARGE_PX;
		}
		return false;
	}
}
