<?php
/**
 * Divi 5 design tokens: global colors and design variables.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one reader for the two ways a Divi 5 attr points at a design token:
 *
 *  - the CSS form, var(--gcid-…) or var(--gvid-…), which Divi prints as is;
 *  - the token form the Visual Builder itself writes,
 *    $variable({"type":"color","value":{"name":"gcid-…","settings":{…}}})$,
 *    which Divi resolves while it renders (GlobalData::resolve_global_color_variable()
 *    in Divi 5.10.1).
 *
 * The echo uses it to catch a token id that does not exist on the site, and
 * the lint and render accessors use it to turn a token into the color it
 * paints, so contrast checks stop skipping the Visual Builder's own form.
 * Every read is guarded: without Divi's GlobalData class the site's tokens
 * are unknown and callers skip.
 */
class Saddle_Divi_Tokens {

	/**
	 * Divi's GlobalData class.
	 */
	const GLOBAL_DATA = '\ET\Builder\Packages\GlobalData\GlobalData';

	/**
	 * How deep a chain of colors pointing at colors is followed (Divi's own
	 * resolver stops at 10).
	 */
	const MAX_DEPTH = 10;

	/**
	 * A $variable(…)$ token's parts, or null when the value is not one.
	 *
	 * @param mixed $value Attr value.
	 * @return array|null { type, name, settings }.
	 */
	public static function parse_variable( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( 0 !== strpos( $value, '$variable(' ) || ')$' !== substr( $value, -2 ) ) {
			return null;
		}
		$data = json_decode( substr( $value, 10, -2 ), true );
		if ( ! is_array( $data ) || ! isset( $data['value']['name'] ) || ! is_string( $data['value']['name'] ) ) {
			return null;
		}
		return array(
			'type'     => isset( $data['type'] ) ? (string) $data['type'] : '',
			'name'     => $data['value']['name'],
			'settings' => isset( $data['value']['settings'] ) && is_array( $data['value']['settings'] ) ? $data['value']['settings'] : array(),
		);
	}

	/**
	 * The site's global colors, id => color value, or null when unknown.
	 *
	 * @return array<string,string>|null
	 */
	public static function global_colors() {
		$colors = null;
		if ( class_exists( self::GLOBAL_DATA ) ) {
			$colors = array();
			foreach ( self::deep_array( call_user_func( array( self::GLOBAL_DATA, 'get_global_colors' ) ) ) as $id => $entry ) {
				if ( isset( $entry['color'] ) && is_string( $entry['color'] ) ) {
					$colors[ (string) $id ] = $entry['color'];
				}
			}
		}

		/**
		 * Filter the global colors the Divi checks resolve against.
		 *
		 * @param array<string,string>|null $colors Id => color, or null when Divi's data is not available.
		 */
		$colors = apply_filters( 'saddle_divi_global_colors', $colors );
		return is_array( $colors ) ? $colors : null;
	}

	/**
	 * The ids of the site's design variables (every type), or null when
	 * unknown.
	 *
	 * @return string[]|null
	 */
	public static function variable_ids() {
		$ids = null;
		if ( class_exists( self::GLOBAL_DATA ) ) {
			$ids = array();
			foreach ( self::deep_array( call_user_func( array( self::GLOBAL_DATA, 'get_global_variables' ) ) ) as $bucket ) {
				if ( is_array( $bucket ) ) {
					$ids = array_merge( $ids, array_map( 'strval', array_keys( $bucket ) ) );
				}
			}
		}

		/**
		 * Filter the design variable ids the Divi checks know.
		 *
		 * @param string[]|null $ids Variable ids, or null when Divi's data is not available.
		 */
		$ids = apply_filters( 'saddle_divi_variable_ids', $ids );
		return is_array( $ids ) ? array_values( $ids ) : null;
	}

	/**
	 * Every gcid-/gvid- id a value refers to, in either form, anywhere in a
	 * nested attrs array.
	 *
	 * @param mixed $value Attr value or whole attrs.
	 * @return string[] Unique ids, first occurrence first.
	 */
	public static function references( $value ) {
		$ids = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				$ids = array_merge( $ids, self::references( $child ) );
			}
			return array_values( array_unique( $ids ) );
		}
		if ( ! is_string( $value ) || false === strpos( $value, 'id-' ) ) {
			return array();
		}
		if ( preg_match_all( '/var\(\s*--(g[cv]id-[a-z0-9-]+)\s*\)/i', $value, $m ) ) {
			$ids = $m[1];
		}
		if ( preg_match_all( '/\$variable\((\{.*?\})\)\$/', $value, $m ) ) {
			foreach ( $m[0] as $token ) {
				$parsed = self::parse_variable( $token );
				if ( $parsed && preg_match( '/^g[cv]id-/', $parsed['name'] ) ) {
					$ids[] = $parsed['name'];
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The color a global color reference paints, in either form. A token
	 * with opacity resolves to rgba(); one with hue, saturation or lightness
	 * changes stays as written (callers then skip, never guess). Anything
	 * that is not a global color reference passes through.
	 *
	 * @param mixed      $color  Color value.
	 * @param array|null $colors The site's global colors when already read (global_colors()).
	 * @param int        $depth  Recursion depth.
	 * @return mixed
	 */
	public static function resolve_color( $color, $colors = null, $depth = 0 ) {
		if ( ! is_string( $color ) || $depth > self::MAX_DEPTH ) {
			return $color;
		}

		$id       = null;
		$settings = array();
		if ( preg_match( '/^var\(\s*--(gcid-[a-z0-9-]+)\s*\)$/i', trim( $color ), $m ) ) {
			$id = $m[1];
		} else {
			$token = self::parse_variable( $color );
			if ( $token && 'color' === $token['type'] && 0 === strpos( $token['name'], 'gcid-' ) ) {
				$id       = $token['name'];
				$settings = $token['settings'];
			}
		}
		if ( null === $id ) {
			return $color;
		}

		$colors = null === $colors ? self::global_colors() : $colors;
		if ( ! $colors || ! isset( $colors[ $id ] ) ) {
			return $color;
		}
		$base = self::resolve_color( $colors[ $id ], $colors, $depth + 1 );

		foreach ( array( 'hue', 'saturation', 'lightness' ) as $shift ) {
			if ( ! empty( $settings[ $shift ] ) ) {
				return $color;
			}
		}
		if ( isset( $settings['opacity'] ) && is_numeric( $settings['opacity'] ) && (float) $settings['opacity'] < 100 ) {
			$rgb = Saddle_Lint_Color::parse( $base );
			if ( ! $rgb ) {
				return $color;
			}
			$alpha = max( 0, (float) $settings['opacity'] ) / 100;
			return sprintf( 'rgba(%1$d, %2$d, %3$d, %4$s)', $rgb[0], $rgb[1], $rgb[2], rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' ) );
		}
		return $base;
	}

	/**
	 * The font variable token Divi reads for a font family.
	 *
	 * @param string $id Variable id (gvid-…).
	 * @return string
	 */
	public static function font_token( $id ) {
		return '$variable(' . wp_json_encode(
			array(
				'type'  => 'content',
				'value' => array(
					'name'     => (string) $id,
					'settings' => new stdClass(),
				),
			)
		) . ')$';
	}

	/**
	 * Deep-cast GlobalData's stdClass trees to arrays.
	 *
	 * @param mixed $data Raw structure.
	 * @return array
	 */
	private static function deep_array( $data ) {
		$decoded = json_decode( (string) wp_json_encode( $data ), true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
