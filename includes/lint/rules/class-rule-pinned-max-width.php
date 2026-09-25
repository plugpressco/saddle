<?php
/**
 * Lint rule: a width-capped module nothing centers.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The most common composition miss observed live: an agent caps a text
 * module's line length with maxWidth (correct!) but centers nothing, so the
 * module renders pinned to the left edge with a dead right half — the page
 * looks lopsided while every server check passes.
 *
 * Fires per content module where maxWidth is set AND nothing centers it:
 * no sizing alignment, no auto side margins. An EXPLICIT alignment (left/
 * right) is a deliberate choice and stays silent, as do structural nodes
 * (rows self-center in Divi). Divi-specific shapes — gated on the concrete
 * Divi accessor.
 */
class Saddle_Lint_Rule_Pinned_Max_Width extends Saddle_Lint_Rule {

	/**
	 * Structural types whose width behavior Divi manages itself.
	 */
	const STRUCTURAL = array( 'divi/placeholder', 'divi/section', 'divi/fullwidth-section', 'divi/row', 'divi/row-inner', 'divi/column', 'divi/column-inner' );

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'pinned-max-width';
	}

	/**
	 * Flag width-capped modules with nothing centering them.
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
			if ( in_array( (string) $node['type'], self::STRUCTURAL, true ) ) {
				continue;
			}
			$max_width = $accessor->max_width( $node['block'] );
			if ( null === $max_width ) {
				continue;
			}
			if ( null !== $accessor->alignment( $node['block'] ) ) {
				continue; // Any explicit alignment — center or deliberate left — is a choice.
			}
			$margin = $accessor->margin( $node['block'] );
			if ( is_array( $margin )
				&& isset( $margin['left'], $margin['right'] )
				&& 'auto' === strtolower( trim( (string) $margin['left'] ) )
				&& 'auto' === strtolower( trim( (string) $margin['right'] ) ) ) {
				continue;
			}

			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_WARN,
				sprintf(
					/* translators: 1: module type, 2: max width. */
					__( '%1$s caps its width at %2$s but nothing centers it — it renders pinned to the left edge with a dead right half.', 'saddle' ),
					(string) $node['type'],
					$max_width
				),
				__( 'Center it: attrs { "module.decoration.sizing.desktop.value.alignment": "center" } (or auto side margins) — or make the asymmetry deliberate by placing content beside it.', 'saddle' )
			);
		}
		return $violations;
	}
}
