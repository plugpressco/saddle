<?php
/**
 * Lint rule: a grid that keeps its desktop columns on a phone.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A Divi 5 grid container does not stack by itself. Read from Divi 5.10.1
 * source (Layout.php, css_grid_grid.css): `display` is read from desktop
 * only, `gridColumnCount` and `gridTemplateColumns` are per breakpoint and
 * inherit downward, and no stylesheet media query changes the column count.
 * A three-column grid with nothing set for tablet or phone is three
 * columns on a 375px screen.
 *
 * Fires when the desktop grid has more than one fixed column and neither
 * tablet nor phone writes a column count, a template or a width mode. The
 * self-sizing modes ("equalMinimum", "equalFixed", or a repeat(auto-fit …)
 * template) wrap on their own and stay silent. A row in grid mode with no
 * count is three columns (the stylesheet's default for rows). Divi pages
 * only.
 */
class Saddle_Lint_Rule_Grid_Phone_Columns extends Saddle_Lint_Rule {

	/**
	 * Layout keys that, written at tablet or phone, show the grid was set
	 * for small screens.
	 */
	const RESPONSIVE_KEYS = array( 'gridColumnCount', 'gridTemplateColumns', 'gridColumnWidths' );

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'grid-phone-columns';
	}

	/**
	 * Flag multi-column grids with no tablet or phone column setting.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}
		$facts      = new Saddle_Divi_Lint_Facts();
		$layout     = array( 'module', 'decoration', 'layout' );
		$violations = array();

		foreach ( $nodes as $node ) {
			$block = $node['block'];
			if ( 'grid' !== $facts->value_of( $block, $layout, 'display' ) ) {
				continue;
			}
			foreach ( array( 'tablet', 'phone' ) as $breakpoint ) {
				foreach ( self::RESPONSIVE_KEYS as $key ) {
					if ( $facts->writes( $block, $layout, $key, $breakpoint ) ) {
						continue 3;
					}
				}
			}
			$columns = $this->desktop_columns( $node, $facts, $layout );
			if ( null === $columns || $columns < 2 ) {
				continue;
			}

			$violations[] = $this->violation(
				$node['address'],
				Saddle_Lint_Rule::SEVERITY_WARN,
				sprintf(
					/* translators: %d: number of grid columns. */
					__( 'This grid has %d columns on desktop and no column setting for tablet or phone. Divi grids do not stack by themselves, so phones get the same columns, squeezed.', 'saddle' ),
					$columns
				),
				$columns > 2
					? __( 'Set the small screens with divi-edit-module on this address: attrs { "module.decoration.layout.tablet.value.gridColumnCount": "2", "module.decoration.layout.phone.value.gridColumnCount": "1" }.', 'saddle' )
					: __( 'Set phones to one column with divi-edit-module on this address: attrs { "module.decoration.layout.phone.value.gridColumnCount": "1" }.', 'saddle' )
			);
		}
		return $violations;
	}

	/**
	 * The fixed number of columns the grid draws on desktop, or null when
	 * it sizes itself or cannot be read.
	 *
	 * @param array                  $node   Flat node entry.
	 * @param Saddle_Divi_Lint_Facts $facts  Facts reader.
	 * @param string[]               $layout Path to the layout group.
	 * @return int|null
	 */
	private function desktop_columns( array $node, Saddle_Divi_Lint_Facts $facts, array $layout ) {
		$block  = $node['block'];
		$widths = (string) $facts->value_of( $block, $layout, 'gridColumnWidths' );
		if ( in_array( $widths, array( 'equalMinimum', 'equalFixed' ), true ) ) {
			return null;
		}
		$template = $facts->value_of( $block, $layout, 'gridTemplateColumns' );
		if ( 'manual' === $widths && is_string( $template ) && '' !== trim( $template ) ) {
			return $this->tracks( $template );
		}
		$count = $facts->value_of( $block, $layout, 'gridColumnCount' );
		if ( null === $count ) {
			return in_array( $node['type'], array( 'divi/row', 'divi/row-inner' ), true ) ? 3 : 1;
		}
		return is_numeric( $count ) ? (int) $count : null;
	}

	/**
	 * Tracks in a grid-template-columns value; null when it repeats with
	 * auto-fit or auto-fill (it wraps on its own) or cannot be read.
	 *
	 * @param string $template CSS grid-template-columns value.
	 * @return int|null
	 */
	private function tracks( $template ) {
		$template = trim( $template );
		if ( preg_match( '/repeat\(\s*auto-(fit|fill)/i', $template ) ) {
			return null;
		}
		if ( preg_match( '/^repeat\(\s*(\d+)\s*,/i', $template, $m ) ) {
			return (int) $m[1];
		}
		$flat = preg_replace( '/\([^()]*\)/', '', $template );
		$flat = preg_replace( '/\([^()]*\)/', '', (string) $flat );
		return count( preg_split( '/\s+/', trim( (string) $flat ) ) );
	}
}
