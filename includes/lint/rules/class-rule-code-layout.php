<?php
/**
 * Lint rule: page layout written as HTML in a code module.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A code module that holds a stylesheet, a table or several layout tags is
 * a section built outside the builder: the owner cannot edit it in the
 * Visual Builder, the site's presets and colors never reach it, and the
 * checks here cannot see inside it. Small snippets (under 200 characters)
 * and embeds (an iframe, or a provider's script with a few wrapper tags)
 * stay silent. Divi pages only.
 */
class Saddle_Lint_Rule_Code_Layout extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'code-layout';
	}

	/**
	 * Flag code modules that carry layout HTML.
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
			$html = $facts->code_content( $node['block'] );
			if ( null === $html ) {
				continue;
			}
			$layout = Saddle_Divi_Text_Facts::code_layout( $html );
			if ( null === $layout ) {
				continue;
			}
			$violations[] = $this->violation(
				$node['address'],
				Saddle_Lint::SEVERITY_NOTE,
				self::message( $layout ),
				__( 'Rebuild it from real modules (divi-list-modules, then divi-add-module) and style it through attrs. Keep the code module only for embeds such as a booking widget or a map iframe.', 'saddle' )
			);
		}
		return $violations;
	}

	/**
	 * The finding, one whole sentence for each mix of what the HTML holds.
	 *
	 * @param array $layout From Saddle_Divi_Text_Facts::code_layout().
	 * @return string
	 */
	private static function message( array $layout ) {
		$tail = __( 'The owner cannot edit it in the Visual Builder, and the site\'s colors and presets never reach it.', 'saddle' );
		if ( $layout['style'] && $layout['table'] ) {
			/* translators: 1: number of layout tags, 2: the sentence about editing. */
			$text = __( 'This code module holds page layout as raw HTML: %1$d layout tags, a style tag and a table. %2$s', 'saddle' );
		} elseif ( $layout['style'] ) {
			/* translators: 1: number of layout tags, 2: the sentence about editing. */
			$text = __( 'This code module holds page layout as raw HTML: %1$d layout tags and a style tag. %2$s', 'saddle' );
		} elseif ( $layout['table'] ) {
			/* translators: 1: number of layout tags, 2: the sentence about editing. */
			$text = __( 'This code module holds page layout as raw HTML: %1$d layout tags and a table. %2$s', 'saddle' );
		} else {
			/* translators: 1: number of layout tags, 2: the sentence about editing. */
			$text = __( 'This code module holds page layout as raw HTML: %1$d layout tags. %2$s', 'saddle' );
		}
		return sprintf( $text, (int) $layout['structural'], $tail );
	}
}
