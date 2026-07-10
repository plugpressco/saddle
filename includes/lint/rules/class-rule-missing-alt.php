<?php
/**
 * Lint rule: content image without alt text.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A content image with missing or empty alt text. The accessor decides what
 * counts as a content image — decorative background media (covers, section
 * backgrounds) returns null and is never nagged about, and alt bound to
 * dynamic content counts as satisfied. Severity is warn, not error: the tree
 * can't prove the image is meaningful rather than decorative.
 */
class Saddle_Lint_Rule_Missing_Alt extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'missing-alt-text';
	}

	/**
	 * Flag content images whose alt is empty.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Lint_Style_Accessor ) {
			return array();
		}

		$violations = array();
		foreach ( $nodes as $node ) {
			if ( '' !== $accessor->image_alt( $node['block'] ) ) {
				continue;
			}
			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_WARN,
				__( 'Image has no alt text — screen readers announce nothing useful for it.', 'saddle' ),
				__( 'Describe what the image shows in a short alt text. Leave alt empty only when the image is purely decorative.', 'saddle' )
			);
		}
		return $violations;
	}
}
