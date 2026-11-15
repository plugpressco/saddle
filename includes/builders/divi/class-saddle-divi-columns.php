<?php
/**
 * Column widths on a Divi 5 row, from one plain "columns" value.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A Divi 5 flex row lays out its columns from each column's own width,
 * `module.decoration.sizing.<breakpoint>.value.flexType` ("8_24" is a third),
 * and records the layout it came from in the row's
 * `module.advanced.flexColumnStructure` ("equal-columns_3"). Without a
 * flexType a column falls back to Divi's default "24_24", full width, so a
 * row of columns with no widths stacks (Divi 5.10.1 Module.php adds the
 * et_flex_column_<type> classes; column module-default-render-attributes
 * sets 24_24).
 *
 * SaddleWP writes what the Visual Builder writes when a layout is picked
 * (Divi 5.10.1, edit-post.js and the premade grids in
 * constant-library.js, read from the built code, not rendered): the row's
 * structure, `display: flex` and the flexWrap each layout uses on every
 * screen size this site has on, and each column's flexType on desktop,
 * tablet and phone, where tablet inherits desktop and phone inherits
 * tablet unless the layout says otherwise. Saddle_Divi_Row_Layout decides
 * when a row gets one.
 */
class Saddle_Divi_Columns {

	/**
	 * The Visual Builder's flex layouts: "<breakpoint>:<flexWrap>,…|" then
	 * one "<desktop>/<tablet>/<phone>" width per column ("-" = inherit).
	 */
	const LAYOUTS = array(
		'equal-columns_1'   => 'desktop:nowrap|24_24/-/-',
		'equal-columns_2'   => 'desktop:nowrap,phone:wrap|12_24/-/24_24 12_24/-/24_24',
		'equal-columns_3'   => 'desktop:nowrap,phone:wrap|8_24/-/24_24 8_24/-/24_24 8_24/-/24_24',
		'equal-columns_4'   => 'desktop:nowrap,tablet:wrap|6_24/12_24/24_24 6_24/12_24/24_24 6_24/12_24/24_24 6_24/12_24/24_24',
		'equal-columns_5'   => 'desktop:nowrap,tablet:wrap|1_5/12_24/24_24 1_5/12_24/24_24 1_5/12_24/24_24 1_5/12_24/24_24 1_5/12_24/24_24',
		'equal-columns_6'   => 'desktop:nowrap,tablet:wrap|4_24/8_24/12_24 4_24/8_24/12_24 4_24/8_24/12_24 4_24/8_24/12_24 4_24/8_24/12_24 4_24/8_24/12_24',
		'equal-columns_7'   => 'desktop:nowrap,tablet:wrap|1_7/8_24/12_24 1_7/8_24/12_24 1_7/8_24/12_24 1_7/8_24/12_24 1_7/8_24/12_24 1_7/8_24/12_24 1_7/8_24/12_24',
		'equal-columns_8'   => 'desktop:nowrap,tablet:wrap|3_24/6_24/12_24 3_24/6_24/12_24 3_24/6_24/12_24 3_24/6_24/12_24 3_24/6_24/12_24 3_24/6_24/12_24 3_24/6_24/12_24 3_24/6_24/12_24',
		'offset-columns_1'  => 'desktop:nowrap,phone:wrap|3_5/-/24_24 2_5/-/24_24',
		'offset-columns_2'  => 'desktop:nowrap,phone:wrap|2_5/-/24_24 3_5/-/24_24',
		'offset-columns_3'  => 'desktop:nowrap,tablet:wrap|12_24/24_24/- 6_24/12_24/24_24 6_24/12_24/24_24',
		'offset-columns_4'  => 'desktop:nowrap,tablet:wrap|6_24/12_24/24_24 6_24/12_24/24_24 12_24/24_24/-',
		'offset-columns_5'  => 'desktop:nowrap,phone:wrap|16_24/-/24_24 8_24/-/24_24',
		'offset-columns_6'  => 'desktop:nowrap,phone:wrap|8_24/-/24_24 16_24/-/24_24',
		'offset-columns_7'  => 'desktop:nowrap,tablet:wrap|12_24/24_24/- 4_24/8_24/12_24 4_24/8_24/12_24 4_24/8_24/12_24',
		'offset-columns_8'  => 'desktop:nowrap,tablet:wrap|4_24/8_24/12_24 4_24/8_24/12_24 4_24/8_24/12_24 12_24/24_24/-',
		'offset-columns_9'  => 'desktop:nowrap,phone:wrap|18_24/-/24_24 6_24/-/24_24',
		'offset-columns_10' => 'desktop:nowrap,phone:wrap|6_24/-/24_24 18_24/-/24_24',
		'offset-columns_11' => 'desktop:nowrap,phone:wrap|6_24/-/24_24 12_24/-/24_24 6_24/-/24_24',
		'offset-columns_12' => 'desktop:nowrap,phone:wrap|1_5/-/24_24 3_5/-/24_24 1_5/-/24_24',
	);

	/**
	 * Widths Divi ships CSS for besides n/24 (flex_grid.css).
	 */
	const OTHER_WIDTHS = array( '1_5', '2_5', '3_5', '4_5', '5_5', '1_7', '1_9', '1_10', '1_11' );

	/**
	 * Read a "columns" value: a count (3 or "3"), widths ("2/3,1/3" or
	 * "16_24,8_24"), or a layout name ("offset-columns_5").
	 *
	 * @param mixed $spec Written value.
	 * @return array|WP_Error {name, wrap, cols}: cols is one
	 *                        [desktop, tablet|null, phone|null] per column.
	 */
	public static function layout( $spec ) {
		if ( is_int( $spec ) || ( is_string( $spec ) && ctype_digit( trim( $spec ) ) ) ) {
			$count = (int) $spec;
			if ( $count < 1 || $count > 8 ) {
				/* translators: %d: the count written. */
				return new WP_Error( 'saddle_bad_columns', sprintf( __( '"columns" takes 1 to 8 equal columns, not %d.', 'saddle' ), $count ) );
			}
			return self::named( 'equal-columns_' . $count );
		}
		if ( ! is_string( $spec ) || '' === trim( $spec ) ) {
			return new WP_Error( 'saddle_bad_columns', __( '"columns" takes a count (3), widths ("2/3,1/3" or "16_24,8_24") or a layout name ("offset-columns_5").', 'saddle' ) );
		}
		$spec = trim( $spec );
		if ( isset( self::LAYOUTS[ $spec ] ) ) {
			return self::named( $spec );
		}

		$widths = array();
		$sum    = 0.0;
		foreach ( array_map( 'trim', explode( ',', $spec ) ) as $part ) {
			$width = self::width( $part );
			if ( is_wp_error( $width ) ) {
				return $width;
			}
			$widths[] = $width[0];
			$sum     += $width[1];
		}
		if ( abs( $sum - 1 ) > 0.01 ) {
			return new WP_Error(
				'saddle_bad_columns',
				/* translators: 1: the widths written, 2: their sum. */
				sprintf( __( 'The widths "%1$s" add up to %2$s of the row. They must add up to the whole row (1).', 'saddle' ), $spec, round( $sum, 3 ) )
			);
		}
		if ( count( $widths ) > 8 ) {
			return new WP_Error( 'saddle_bad_columns', __( '"columns" takes at most 8 columns.', 'saddle' ) );
		}
		foreach ( array_keys( self::LAYOUTS ) as $name ) {
			$layout = self::named( $name );
			if ( array_column( $layout['cols'], 0 ) === $widths ) {
				return $layout;
			}
		}
		// A split the Visual Builder has no layout for: its own name for a
		// row of that many columns (newFlexColumnStructure), with every
		// column full width on phones, and two per line on tablets from four
		// columns up, as Divi's own layouts of the same size do.
		$wide = count( $widths ) >= 4;
		$cols = array();
		foreach ( $widths as $w ) {
			$cols[] = array( $w, $wide ? '12_24' : null, '24_24' );
		}
		return array(
			'name' => 'equal-columns_' . count( $widths ),
			'wrap' => $wide ? array(
				'desktop' => 'nowrap',
				'tablet'  => 'wrap',
			) : array(
				'desktop' => 'nowrap',
				'phone'   => 'wrap',
			),
			'cols' => $cols,
		);
	}

	/**
	 * Put a layout on a row and its columns.
	 *
	 * @param array   $row     Row attrs.
	 * @param array[] $columns Column blocks.
	 * @param array   $layout  From layout().
	 * @param bool    $replace True on an edit: the layout replaces stored
	 *                         widths. False on a new node: what the agent
	 *                         wrote itself wins.
	 * @return array [row attrs, column blocks].
	 */
	public static function put( array $row, array $columns, array $layout, $replace ) {
		$breakpoints = Saddle_Divi_Style::breakpoints();
		$add         = array();
		$add['module']['advanced']['flexColumnStructure']['desktop']['value'] = $layout['name'];
		foreach ( self::layout_breakpoints( $breakpoints ) as $bp ) {
			$add['module']['decoration']['layout'][ $bp ]['value']['display'] = 'flex';
			$wrap = self::inherited( $layout['wrap'], $bp, false );
			if ( null !== $wrap ) {
				$add['module']['decoration']['layout'][ $bp ]['value']['flexWrap'] = $wrap;
			}
		}
		$row = $replace ? array_replace_recursive( $row, $add ) : array_replace_recursive( $add, $row );

		foreach ( $columns as $i => $column ) {
			$widths       = $layout['cols'][ $i ];
			$set          = array(
				'desktop' => $widths[0],
				'tablet'  => null !== $widths[1] ? $widths[1] : $widths[0],
			);
			$set['phone'] = null !== $widths[2] ? $widths[2] : $set['tablet'];
			$sizing       = array();
			foreach ( self::layout_breakpoints( $breakpoints ) as $bp ) {
				$sizing['module']['decoration']['sizing'][ $bp ]['value']['flexType'] = self::inherited( $set, $bp, true );
			}
			$attrs                  = is_array( $column['attrs'] ) ? $column['attrs'] : array();
			$attrs                  = $replace ? array_replace_recursive( $attrs, $sizing ) : array_replace_recursive( $sizing, $attrs );
			$columns[ $i ]['attrs'] = Saddle_Divi_Key_Order::apply( $attrs );
		}
		return array( Saddle_Divi_Key_Order::apply( $row ), $columns );
	}

	/**
	 * A named layout as {name, wrap, cols}.
	 *
	 * @param string $name Layout name.
	 * @return array
	 */
	private static function named( $name ) {
		list( $wrap_part, $cols_part ) = explode( '|', self::LAYOUTS[ $name ] );
		$wrap                          = array();
		foreach ( explode( ',', $wrap_part ) as $pair ) {
			list( $bp, $value ) = explode( ':', $pair );
			$wrap[ $bp ]        = $value;
		}
		$cols = array();
		foreach ( explode( ' ', $cols_part ) as $col ) {
			$cols[] = array_map(
				static function ( $w ) {
					return '-' === $w ? null : $w;
				},
				explode( '/', $col )
			);
		}
		return array(
			'name' => $name,
			'wrap' => $wrap,
			'cols' => $cols,
		);
	}

	/**
	 * One written width as [flexType, fraction of the row].
	 *
	 * @param string $part "1/3" or "8_24".
	 * @return array|WP_Error
	 */
	private static function width( $part ) {
		if ( preg_match( '/^(\d+)_(\d+)$/', $part, $m ) && ( ( '24' === $m[2] && (int) $m[1] >= 1 && (int) $m[1] <= 24 ) || in_array( $part, self::OTHER_WIDTHS, true ) ) ) {
			return array( $part, (int) $m[1] / (int) $m[2] );
		}
		if ( preg_match( '/^(\d+)\s*\/\s*(\d+)$/', $part, $m ) && (int) $m[2] > 0 && (int) $m[1] > 0 ) {
			$fraction = (int) $m[1] / (int) $m[2];
			$in24     = $fraction * 24;
			if ( abs( $in24 - round( $in24 ) ) < 0.0001 && round( $in24 ) >= 1 && round( $in24 ) <= 24 ) {
				return array( (int) round( $in24 ) . '_24', $fraction );
			}
			foreach ( self::OTHER_WIDTHS as $other ) {
				list( $n, $d ) = explode( '_', $other );
				if ( abs( $fraction - (int) $n / (int) $d ) < 0.0001 ) {
					return array( $other, $fraction );
				}
			}
		}
		return new WP_Error(
			'saddle_bad_columns',
			sprintf(
				/* translators: %s: the width written. */
				__( 'Divi has no column width "%s". Widths are fractions of the row: any n/24 (1/2, 1/3, 1/4, 2/3, 3/4, 1/6, 5/12…), fifths (1/5 to 4/5), or 1/7, 1/9, 1/10, 1/11.', 'saddle' ),
				$part
			)
		);
	}

	/**
	 * The screen sizes a layout is written for: desktop and the smaller
	 * ones this site has on (the Visual Builder writes tabletWide, tablet,
	 * phoneWide and phone; larger screens inherit desktop).
	 *
	 * @param string[] $enabled Enabled breakpoints.
	 * @return string[]
	 */
	private static function layout_breakpoints( array $enabled ) {
		return array_values( array_intersect( array( 'desktop', 'tabletWide', 'tablet', 'phoneWide', 'phone' ), $enabled ) );
	}

	/**
	 * A layout value at a breakpoint: tabletWide takes tablet's, phoneWide
	 * takes phone's (as the Visual Builder does).
	 *
	 * @param array  $values  Breakpoint => value.
	 * @param string $bp      Breakpoint.
	 * @param bool   $inherit Fall back to desktop when unset.
	 * @return string|null
	 */
	private static function inherited( array $values, $bp, $inherit ) {
		$from = array(
			'tabletWide' => 'tablet',
			'phoneWide'  => 'phone',
		);
		if ( isset( $values[ $bp ] ) ) {
			return $values[ $bp ];
		}
		if ( isset( $from[ $bp ], $values[ $from[ $bp ] ] ) ) {
			return $values[ $from[ $bp ] ];
		}
		return $inherit && isset( $values['desktop'] ) ? $values['desktop'] : null;
	}

	/**
	 * Columns of one width that fit on a line.
	 *
	 * @param string $flex_type e.g. "8_24".
	 * @return int
	 */
	public static function per_line( $flex_type ) {
		list( $n, $d ) = array_map( 'intval', explode( '_', (string) $flex_type ) );
		return $n > 0 ? max( 1, (int) floor( $d / $n + 0.0001 ) ) : 1;
	}
}
