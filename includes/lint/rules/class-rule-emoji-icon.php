<?php
/**
 * Lint rule: an emoji used as an icon.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * An emoji at the start or end of a heading, button or label stands in for
 * an icon. It renders differently on every device, ignores the site's
 * colors, and screen readers read its name aloud ("rocket"). Check marks
 * and stars stay silent unless the emoji form is used. Divi pages only.
 */
class Saddle_Lint_Rule_Emoji_Icon extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'emoji-icon';
	}

	/**
	 * Flag headings, buttons and labels that lead or end with an emoji.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}
		$facts      = new Saddle_Divi_Text_Facts();
		$violations = array();
		foreach ( $nodes as $node ) {
			foreach ( $facts->labels( $node['block'] ) as $label ) {
				$emoji = Saddle_Divi_Text_Facts::edge_emoji( $label['text'] );
				if ( null === $emoji ) {
					continue;
				}
				$violations[] = $this->violation(
					$node['address'],
					Saddle_Lint::SEVERITY_NOTE,
					sprintf( $this->message( $label['kind'] ), $emoji, $label['text'] ),
					__( 'Remove the emoji from the text with divi-edit-module "fields", and use a real icon instead: a blurb\'s icon, divi/icon, or the button\'s own icon setting.', 'saddle' )
				);
				break;
			}
		}
		return $violations;
	}

	/**
	 * The message for a label kind, whole sentences per kind.
	 *
	 * @param string $kind heading, button or label.
	 * @return string Format with 1: the emoji, 2: the text.
	 */
	private function message( $kind ) {
		if ( 'heading' === $kind ) {
			/* translators: 1: the emoji, 2: the heading text. */
			return __( 'The emoji %1$s is used as an icon in the heading "%2$s". Emoji look different on every device, ignore the site\'s colors, and screen readers read their names aloud.', 'saddle' );
		}
		if ( 'button' === $kind ) {
			/* translators: 1: the emoji, 2: the button text. */
			return __( 'The emoji %1$s is used as an icon in the button "%2$s". Emoji look different on every device, ignore the site\'s colors, and screen readers read their names aloud.', 'saddle' );
		}
		/* translators: 1: the emoji, 2: the label text. */
		return __( 'The emoji %1$s is used as an icon in the label "%2$s". Emoji look different on every device, ignore the site\'s colors, and screen readers read their names aloud.', 'saddle' );
	}
}
