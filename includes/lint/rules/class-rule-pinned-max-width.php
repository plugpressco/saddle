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
			if ( $this->under_left_heading( $node, $nodes, $accessor ) ) {
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

	/**
	 * A left-aligned lede: the capped module sits directly under a heading,
	 * and nothing in the heading, the module or the column centers. Its left
	 * edge then lines up with the heading's, which is the design, not a
	 * dead right half. (A false positive recorded live on 2026-11-09.)
	 *
	 * @param array                     $node     Flat node entry.
	 * @param array[]                   $nodes    Flat node list.
	 * @param Saddle_Divi_Lint_Accessor $accessor Divi accessor.
	 * @return bool
	 */
	private function under_left_heading( array $node, array $nodes, Saddle_Divi_Lint_Accessor $accessor ) {
		$siblings = $this->children( $nodes, $node['parent'] );
		$previous = null;
		foreach ( $siblings as $sibling ) {
			if ( $sibling['address'] === $node['address'] ) {
				break;
			}
			$previous = $sibling;
		}
		if ( ! $previous || null === $accessor->heading_level( $previous['block'] ) ) {
			return false;
		}

		$text = new Saddle_Divi_Text_Facts();
		foreach ( array( $previous['block'], $node['block'] ) as $block ) {
			if ( in_array( $text->text_align( $block ), array( 'center', 'right' ), true ) ) {
				return false;
			}
		}
		$heading_align = $accessor->alignment( $previous['block'] );
		if ( null !== $heading_align && 'left' !== $heading_align ) {
			return false;
		}

		foreach ( $nodes as $parent ) {
			if ( $parent['address'] !== $node['parent'] ) {
				continue;
			}
			$facts = new Saddle_Divi_Lint_Facts();
			$items = $facts->value_of( $parent['block'], array( 'module', 'decoration', 'layout' ), 'alignItems' );
			if ( in_array( $items, array( 'center', 'flex-end', 'end' ), true ) || in_array( $text->text_align( $parent['block'] ), array( 'center', 'right' ), true ) ) {
				return false;
			}
		}
		return true;
	}
}
