<?php
/**
 * Lint rule: more than two font families on one page.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One family for headings and one for body text is the usual ceiling; a
 * third reads as noise and loads another web font. Counts only the families
 * the page's own attrs name. The site's font variables (tokens), inherited
 * theme fonts, icon fonts and generic names (sans-serif) are left out, so a
 * page styled through the design system never fires. One note per page, at
 * the node that brings in the third family. Divi pages only.
 */
class Saddle_Lint_Rule_Font_Families extends Saddle_Lint_Rule {

	/**
	 * Families a page may set before the note.
	 */
	const MAXIMUM = 2;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'font-families';
	}

	/**
	 * Flag the page when its own attrs name more than two families.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}
		$facts    = new Saddle_Divi_Text_Facts();
		$families = array();
		$third    = null;

		foreach ( $nodes as $node ) {
			foreach ( $facts->font_families( $node['block'] ) as $found ) {
				if ( isset( $families[ $found['key'] ] ) ) {
					continue;
				}
				$families[ $found['key'] ] = $found['family'];
				if ( null === $third && count( $families ) > self::MAXIMUM ) {
					$third = array(
						'address' => $node['address'],
						'path'    => $found['path'],
					);
				}
			}
		}

		if ( null === $third ) {
			return array();
		}

		return array(
			$this->violation(
				$third['address'],
				Saddle_Lint::SEVERITY_NOTE,
				sprintf(
					/* translators: 1: number of families, 2: comma-separated family names. */
					__( 'The page sets %1$d font families (%2$s). More than two reads as noise and loads extra fonts.', 'saddle' ),
					count( $families ),
					implode( ', ', $families )
				),
				sprintf(
					/* translators: %s: dotted attr path of the family. */
					__( 'Use one family for headings and one for body text, ideally the site\'s own fonts. Remove this one with divi-edit-module on this address: unset ["%s"], so it inherits the site font.', 'saddle' ),
					$third['path']
				)
			),
		);
	}
}
