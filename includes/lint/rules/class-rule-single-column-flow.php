<?php
/**
 * Lint rule: long single-column flow (the document look).
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A long run of single-column, single-module wrappers reads as a document,
 * not a designed page: heading row, text row, heading row, text row — each
 * sibling holding exactly one column holding exactly one leaf module. It is
 * the most common tell of an agent "filling in" a page instead of composing
 * one (observed live: 12 such rows in one section scored 90/A before this
 * rule existed).
 *
 * Pure tree shape — no accessor facts — so it works identically on every
 * builder: Divi's row > column > module, Gutenberg's columns > column >
 * paragraph. Bare paragraphs at the root never match (they have no
 * child-of-child), so ordinary post prose stays silent by construction.
 * One advisory per run, at the shared parent, not one per row.
 */
class Saddle_Lint_Rule_Single_Column_Flow extends Saddle_Lint_Rule {

	/**
	 * Minimum consecutive single-column wrappers before the page reads as a
	 * document. Three stacked full-width rows are a common deliberate rhythm
	 * (intro, body, aside); four or more is a flow, not a composition.
	 */
	const MIN_RUN = 4;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'single-column-flow';
	}

	/**
	 * Flag runs of >= MIN_RUN consecutive single-column single-leaf siblings.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		$violations = array();

		$parents = array( null );
		foreach ( $nodes as $node ) {
			$parents[] = $node['address'];
		}

		foreach ( $parents as $parent ) {
			$children = $this->children( $nodes, $parent );
			if ( count( $children ) < self::MIN_RUN ) {
				continue;
			}

			$run = array();
			foreach ( $children as $child ) {
				if ( $this->is_single_column_stack( $nodes, $child ) ) {
					$run[] = $child;
					continue;
				}
				$violations = array_merge( $violations, $this->flush( $run, $parent ) );
				$run        = array();
			}
			$violations = array_merge( $violations, $this->flush( $run, $parent ) );
		}

		return $violations;
	}

	/**
	 * Whether a node is a single-column stack: exactly one child, which has
	 * exactly one child, which is a leaf (row > column > module).
	 *
	 * @param array[] $nodes Flat node list.
	 * @param array   $node  Candidate wrapper node.
	 * @return bool
	 */
	private function is_single_column_stack( array $nodes, array $node ) {
		$columns = $this->children( $nodes, $node['address'] );
		if ( 1 !== count( $columns ) ) {
			return false;
		}
		$modules = $this->children( $nodes, $columns[0]['address'] );
		if ( 1 !== count( $modules ) ) {
			return false;
		}
		return array() === $this->children( $nodes, $modules[0]['address'] );
	}

	/**
	 * Turn a completed run into at most one advisory.
	 *
	 * @param array[]     $run    Consecutive matching siblings.
	 * @param string|null $parent Their shared parent address.
	 * @return array[]
	 */
	private function flush( array $run, $parent ) {
		if ( count( $run ) < self::MIN_RUN ) {
			return array();
		}
		return array(
			$this->violation(
				null === $parent ? $run[0]['address'] : $parent,
				self::SEVERITY_WARN,
				sprintf(
					/* translators: %d: number of consecutive single-column wrappers. */
					__( '%d consecutive single-column rows, each holding one module — this reads as a document, not a designed page.', 'saddle' ),
					count( $run )
				),
				__( 'Recompose instead of stacking: merge running text into one module, group related items into 2-3 column rows (features, cards, lists), or pair text with media in an asymmetric split. Keep single-column only where a full-width moment is deliberate.', 'saddle' )
			),
		);
	}
}
