<?php
/**
 * Lint rule: a module type this site cannot prove exists.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A node whose type has no module.json in the site's schema index may not
 * exist at all — a typo'd type (divi/headding) passes authoring's format
 * regex, passes structure validation, saves fine, and renders NOTHING. The
 * worst silent failure, surfaced here as an error finding (a finding, never
 * a write rejection — mirroring the author's deliberate no-hard-gate
 * stance, since packs CAN register modules without shipping module.json).
 *
 * Skips entirely when the index is empty (harness, broken discovery — the
 * accessor returns null) and skips the structural vocabulary (the tree
 * validator owns those).
 */
class Saddle_Lint_Rule_Unknown_Module extends Saddle_Lint_Rule {

	/**
	 * Types whose existence the tree validator already governs.
	 */
	const STRUCTURAL = array( 'divi/placeholder', 'divi/section', 'divi/fullwidth-section', 'divi/row', 'divi/row-inner', 'divi/column', 'divi/column-inner' );

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'unknown-module';
	}

	/**
	 * Flag node types absent from the schema index.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}

		$violations = array();
		foreach ( $nodes as $node ) {
			$type = (string) $node['type'];
			if ( '' === $type || in_array( $type, self::STRUCTURAL, true ) ) {
				continue;
			}
			if ( false !== $accessor->module_exists( $type ) ) {
				continue; // Exists, or the index is empty (null) — not judgeable.
			}
			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_ERROR,
				sprintf(
					/* translators: %s: module type. */
					__( '"%s" has no module.json on this site — the module may not exist here, and a nonexistent type renders nothing at all.', 'saddle' ),
					$type
				),
				__( 'Pick a real module from divi-list-modules (this is usually a typo). If this pack registers modules without module.json and the page renders fine, ignore this finding.', 'saddle' )
			);
		}
		return $violations;
	}
}
