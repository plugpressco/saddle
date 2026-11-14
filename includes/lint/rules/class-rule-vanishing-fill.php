<?php
/**
 * Lint rule: a filled element that vanishes on its own surface.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * button-contrast judges a button's text against its fill. This judges the
 * fill against what it sits on: a primary button on a primary band is
 * 1.00:1, and its edge disappears.
 *
 *  - A filled button (custom button switch on, solid fill) under 3:1
 *    against the nearest surface, the WCAG 1.4.11 minimum for a control's
 *    edge, with no border that reaches 3:1 itself.
 *  - A card (a group or column with its own fill and a radius or shadow)
 *    whose fill is the same as its surface (under 1.1:1) with no border and
 *    no shadow: its radius and padding draw nothing. A soft card on a
 *    slightly tinted section stays silent; that is a design choice.
 *
 * The surface is the nearest ancestor that paints. A gradient, image or a
 * container preset there means it is unknown, and the rule skips. Colors
 * that do not resolve to a solid hex skip too. Divi pages only.
 */
class Saddle_Lint_Rule_Vanishing_Fill extends Saddle_Lint_Rule {

	/**
	 * WCAG 1.4.11 minimum for the edge of a control.
	 */
	const BUTTON_MINIMUM = 3.0;

	/**
	 * Below this a card's fill is the same as its surface.
	 */
	const CARD_MINIMUM = 1.1;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'vanishing-fill';
	}

	/**
	 * Flag fills that disappear against their surface.
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
			$block = $node['block'];
			$fill  = $facts->button_fill( $block );
			if ( null !== $fill ) {
				// divi/button paints nothing of its own around the button; a
				// module with a button element (CTA) does.
				$surface = $facts->surface_under( $node, $by_address, 'divi/button' !== $node['type'] );
				$ratio   = $this->ratio( $fill, $surface );
				if ( null !== $ratio && $ratio < self::BUTTON_MINIMUM && ! $this->edge_shows( $facts->border_color( $block, 'button' ), $surface ) ) {
					$violations[] = $this->violation(
						$node['address'],
						Saddle_Lint_Rule::SEVERITY_WARN,
						sprintf(
							/* translators: 1: button fill, 2: surface color, 3: contrast ratio. */
							__( 'The button fill %1$s on its surface %2$s has a contrast of %3$s:1, under the 3:1 a control\'s edge needs. The button blends into the section.', 'saddle' ),
							$fill,
							$surface,
							number_format_i18n( $ratio, 2 )
						),
						__( 'Change the fill so it stands apart from the section (divi-edit-module, attrs "button.decoration.background.desktop.value.color"), or give it a border that reaches 3:1 ("button.decoration.border.desktop.value.styles.all": { "width": "2px", "style": "solid", "color": "…" }).', 'saddle' )
					);
				}
				continue;
			}

			if ( ! $facts->is_card( $block ) || $facts->has_shadow( $block ) || null !== $facts->border_color( $block ) ) {
				continue;
			}
			$own     = $facts->surface( $block )['color'];
			$surface = $facts->surface_under( $node, $by_address, false );
			$ratio   = $this->ratio( $own, $surface );
			if ( null === $ratio || $ratio >= self::CARD_MINIMUM ) {
				continue;
			}
			$violations[] = $this->violation(
				$node['address'],
				Saddle_Lint_Rule::SEVERITY_WARN,
				sprintf(
					/* translators: 1: card fill, 2: surface color. */
					__( 'This card\'s fill %1$s is the same as the surface it sits on (%2$s), and it has no border or shadow, so the card shape does not show.', 'saddle' ),
					$own,
					$surface
				),
				__( 'Set the card apart with divi-edit-module on this address: a fill that differs from the section ("module.decoration.background.desktop.value.color"), a 1px border ("module.decoration.border.desktop.value.styles.all"), or a soft shadow ("module.decoration.boxShadow.desktop.value"). Or drop the radius and padding if no card is meant.', 'saddle' )
			);
		}
		return $violations;
	}

	/**
	 * Contrast between two solid hex colors, or null when either is not one.
	 *
	 * @param string|null $a One color.
	 * @param string|null $b Another color.
	 * @return float|null
	 */
	private function ratio( $a, $b ) {
		if ( ! Saddle_Divi_Lint_Facts::is_solid_hex( $a ) || ! Saddle_Divi_Lint_Facts::is_solid_hex( $b ) ) {
			return null;
		}
		return Saddle_Lint_Color::contrast( $a, $b );
	}

	/**
	 * Whether a border shows against the surface: unknown border colors
	 * count as showing (skip, never guess).
	 *
	 * @param string|null $border  Border color, '' when unset, null when no border.
	 * @param string|null $surface Surface color.
	 * @return bool
	 */
	private function edge_shows( $border, $surface ) {
		if ( null === $border ) {
			return false;
		}
		$ratio = $this->ratio( $border, $surface );
		return null === $ratio || $ratio >= self::BUTTON_MINIMUM;
	}
}
