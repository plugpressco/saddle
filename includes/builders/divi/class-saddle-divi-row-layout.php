<?php
/**
 * A row's column widths, from its "columns" or, when it has none, equal.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Puts a "columns" value on a row and its columns (Saddle_Divi_Columns),
 * for divi-set-page, divi-add-module and divi-edit-module alike. A row
 * written with several columns and nothing that sets their widths gets
 * equal columns and a note: without widths Divi gives every column its
 * default flexType 24_24, so they stack at full width. A row that already
 * has a structure or a column width is never changed unless "columns"
 * asks for it.
 */
class Saddle_Divi_Row_Layout {

	/**
	 * Lay a row out.
	 *
	 * @param array      $attrs    Row attrs.
	 * @param array[]    $children Its child blocks.
	 * @param mixed|null $spec     The "columns" value, or null.
	 * @param string     $address  Row address, for messages.
	 * @param bool       $replace  True on an edit: the layout replaces stored
	 *                             widths. False on a new node: what the agent
	 *                             wrote itself wins, and a row with no widths
	 *                             gets equal columns.
	 * @return array|WP_Error [attrs, children, note ('' for none)].
	 */
	public static function apply( array $attrs, array $children, $spec, $address, $replace ) {
		$columns = array_filter(
			$children,
			static function ( $child ) {
				return 'divi/column' === $child['blockName'];
			}
		);

		if ( null === $spec ) {
			if ( $replace || count( $columns ) !== count( $children ) || count( $children ) < 2 || count( $children ) > 8
				|| '' !== self::other_display( $attrs ) || ! self::unset_widths( $attrs, $children ) ) {
				return array( $attrs, $children, '' );
			}
			$layout = Saddle_Divi_Columns::layout( count( $children ) );
			$note   = sprintf(
				/* translators: 1: row address, 2: number of columns, 3: layout name, 4: columns per line per screen size. */
				__( 'Row %1$s holds %2$d columns and nothing set their widths, so SaddleWP gave it equal columns (%3$s): %4$s. Without widths Divi stacks every column at full width. Pass "columns" on the row for other widths.', 'saddle' ),
				$address,
				count( $children ),
				$layout['name'],
				self::describe( $layout )
			);
			list( $attrs, $children ) = Saddle_Divi_Columns::put( $attrs, $children, $layout, false );
			return array( $attrs, $children, $note );
		}

		$layout = Saddle_Divi_Columns::layout( $spec );
		if ( ! is_wp_error( $layout ) && count( $layout['cols'] ) !== count( $children ) ) {
			$layout = new WP_Error(
				'saddle_bad_columns',
				sprintf(
					/* translators: 1: the columns value, 2: columns it makes, 3: columns the row holds. */
					__( '"columns" %1$s makes %2$d columns, but the row holds %3$d.', 'saddle' ),
					wp_json_encode( $spec ),
					count( $layout['cols'] ),
					count( $children )
				)
			);
		}
		if ( ! is_wp_error( $layout ) && count( $columns ) !== count( $children ) ) {
			$layout = new WP_Error( 'saddle_bad_columns', __( '"columns" needs a row that holds divi/column modules only.', 'saddle' ) );
		}
		if ( ! is_wp_error( $layout ) && '' !== self::other_display( $attrs ) ) {
			$layout = new WP_Error(
				'saddle_bad_columns',
				sprintf(
					/* translators: %s: display value. */
					__( '"columns" lays a row out in flex, but this row sets display "%s". Leave one of them out.', 'saddle' ),
					self::other_display( $attrs )
				)
			);
		}
		if ( is_wp_error( $layout ) ) {
			return new WP_Error(
				'saddle_bad_columns',
				sprintf(
					/* translators: 1: node address, 2: what is wrong. */
					__( 'Row %1$s: %2$s Nothing was saved.', 'saddle' ),
					$address,
					$layout->get_error_message()
				),
				array( 'status' => 400 )
			);
		}
		list( $attrs, $children ) = Saddle_Divi_Columns::put( $attrs, $children, $layout, $replace );
		return array( $attrs, $children, '' );
	}

	/**
	 * Whether a row has no column widths at all: no structure, and no
	 * flexType on any of its columns. Such a row stacks its columns.
	 *
	 * @param array   $row     Row attrs.
	 * @param array[] $columns Column blocks.
	 * @return bool
	 */
	public static function unset_widths( array $row, array $columns ) {
		if ( isset( $row['module']['advanced']['flexColumnStructure'] ) || isset( $row['module']['advanced']['columnStructure'] ) ) {
			return false;
		}
		foreach ( $columns as $column ) {
			$sizing = isset( $column['attrs']['module']['decoration']['sizing'] ) ? $column['attrs']['module']['decoration']['sizing'] : array();
			foreach ( is_array( $sizing ) ? $sizing : array() as $states ) {
				if ( is_array( $states ) && isset( $states['value']['flexType'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * The row's own display at desktop when it is not flex, else ''.
	 *
	 * @param array $row Row attrs.
	 * @return string
	 */
	public static function other_display( array $row ) {
		$display = isset( $row['module']['decoration']['layout']['desktop']['value']['display'] ) ? (string) $row['module']['decoration']['layout']['desktop']['value']['display'] : 'flex';
		return 'flex' === $display ? '' : $display;
	}

	/**
	 * How many columns sit side by side per screen size, for a note.
	 *
	 * @param array $layout From layout().
	 * @return string e.g. "3 per line on desktop, 3 on tablets, 1 on phones".
	 */
	public static function describe( array $layout ) {
		$first = $layout['cols'][0];
		$tab   = null !== $first[1] ? $first[1] : $first[0];
		$phone = null !== $first[2] ? $first[2] : $tab;
		return sprintf(
			/* translators: 1: columns per line on desktop, 2: on tablets, 3: on phones. */
			__( '%1$d per line on desktop, %2$d on tablets, %3$d on phones', 'saddle' ),
			min( count( $layout['cols'] ), Saddle_Divi_Columns::per_line( $first[0] ) ),
			min( count( $layout['cols'] ), Saddle_Divi_Columns::per_line( $tab ) ),
			min( count( $layout['cols'] ), Saddle_Divi_Columns::per_line( $phone ) )
		);
	}

	/**
	 * Notes for rows whose column count no longer matches the widths they
	 * were given (a column added, moved or removed): Divi keeps the old
	 * widths, so the new column renders full width or a gap stays open.
	 *
	 * @param array[]  $tree      The tree after the change.
	 * @param string[] $addresses Rows the change touched ('' entries are skipped).
	 * @return string[]
	 */
	public static function stale( array $tree, array $addresses ) {
		$notes = array();
		foreach ( array_unique( array_filter( array_map( 'strval', $addresses ), 'strlen' ) ) as $address ) {
			$row  = Saddle_Tree::get( $tree, $address );
			$name = isset( $row['attrs']['module']['advanced']['flexColumnStructure']['desktop']['value'] ) ? $row['attrs']['module']['advanced']['flexColumnStructure']['desktop']['value'] : null;
			if ( ! $row || 'divi/row' !== $row['blockName'] || ! is_string( $name ) || ! isset( Saddle_Divi_Columns::LAYOUTS[ $name ] ) ) {
				continue;
			}
			$layout = Saddle_Divi_Columns::layout( $name );
			if ( count( $layout['cols'] ) !== count( $row['innerBlocks'] ) ) {
				$notes[] = sprintf(
					/* translators: 1: row address, 2: columns it holds, 3: columns its widths are for, 4: layout name. */
					__( 'Row %1$s now holds %2$d columns, but its widths are set for %3$d (%4$s). Set them with divi-edit-module "columns" on the row.', 'saddle' ),
					$address,
					count( $row['innerBlocks'] ),
					count( $layout['cols'] ),
					$name
				);
			}
		}
		return $notes;
	}
}
