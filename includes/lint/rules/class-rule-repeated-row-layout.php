<?php
/**
 * Lint rule: the same multi-column row, five or more times in a row.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * single-column-flow catches a page of one-column rows. This is its
 * multi-column twin: five or more consecutive section rows with the same
 * column layout (the same number of columns, the same widths) read as a
 * template filled in, not a page composed. Rows are compared in page order
 * across sections, so a run can span several sections that each hold one
 * such row. One note per run, at its first row. Divi pages only.
 */
class Saddle_Lint_Rule_Repeated_Row_Layout extends Saddle_Lint_Rule {

	/**
	 * Consecutive identical rows before the note.
	 */
	const MIN_RUN = 5;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'repeated-row-layout';
	}

	/**
	 * Flag runs of identical multi-column rows.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}
		$facts = new Saddle_Divi_Lint_Facts();
		$runs  = array();
		$run   = array();
		$last  = null;

		foreach ( $this->sections( $nodes ) as $section ) {
			foreach ( $this->children( $nodes, $section['address'] ) as $child ) {
				$signature = 'divi/row' === $child['type'] ? $this->signature( $child, $nodes, $facts ) : null;
				if ( null !== $signature && $signature === $last ) {
					$run[] = $child;
					continue;
				}
				$runs[] = $run;
				$run    = null === $signature ? array() : array( $child );
				$last   = $signature;
			}
		}
		$runs[] = $run;

		$violations = array();
		foreach ( $runs as $run ) {
			if ( count( $run ) < self::MIN_RUN ) {
				continue;
			}
			$violations[] = $this->violation(
				$run[0]['address'],
				Saddle_Lint::SEVERITY_NOTE,
				sprintf(
					/* translators: 1: number of rows, 2: number of columns. */
					__( '%1$d rows in a row share the same %2$d-column layout. The page reads as one template filled in again and again.', 'saddle' ),
					count( $run ),
					count( $this->children( $nodes, $run[0]['address'] ) )
				),
				__( 'Vary the rhythm: turn one of these rows into a split (text beside an image), a single full-width moment, or a featured item wider than the rest (divi-edit-module on the columns\' "module.decoration.sizing.desktop.value.flexType").', 'saddle' )
			);
		}
		return $violations;
	}

	/**
	 * A row's column layout, or null when it has fewer than two columns
	 * (single-column-flow's job).
	 *
	 * @param array                  $row   Row entry.
	 * @param array[]                $nodes Flat node list.
	 * @param Saddle_Divi_Lint_Facts $facts Facts reader.
	 * @return string|null
	 */
	private function signature( array $row, array $nodes, Saddle_Divi_Lint_Facts $facts ) {
		$columns = $this->children( $nodes, $row['address'] );
		if ( count( $columns ) < 2 ) {
			return null;
		}
		$widths = array();
		foreach ( $columns as $column ) {
			$widths[] = (string) $facts->value_of( $column['block'], array( 'module', 'decoration', 'sizing' ), 'flexType' );
		}
		return count( $columns ) . ':' . implode( ',', $widths );
	}
}
