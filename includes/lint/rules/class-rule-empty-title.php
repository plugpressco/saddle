<?php
/**
 * Lint rule: empty titles.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A title-carrying element whose title text is empty. Agents produce these
 * when a heading node is added but its content payload is dropped or a
 * template slot goes unfilled — the page renders with a blank line where the
 * message should be.
 */
class Saddle_Lint_Rule_Empty_Title extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'empty-title';
	}

	/**
	 * Flag title nodes whose text is empty.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		$violations = array();
		foreach ( $nodes as $node ) {
			$title = $accessor->title_text( $node['block'] );
			if ( null !== $title && '' === trim( (string) $title ) ) {
				$violations[] = $this->violation(
					$node['address'],
					self::SEVERITY_ERROR,
					sprintf(
						/* translators: %s: block/module type. */
						__( '%s has an empty title.', 'saddle' ),
						$node['type']
					),
					__( 'Write the heading text, or remove the element if the section needs no title.', 'saddle' )
				);
			}
		}
		return $violations;
	}
}
