<?php
/**
 * Stored key order inside Divi 5 decoration groups.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Divi 5 decides which CSS and scripts a page loads by running regexes over
 * the stored block JSON, and two of them read keys in order (Divi 5.10.1,
 * source reading only, nothing rendered):
 *
 * - animation (DetectFeature.php): inside "animation", "desktop" must come
 *   before any other breakpoint object, "value" first inside it, and
 *   "style" before any object inside the value. Missed, the module shows
 *   but never animates, because the animation files are not loaded.
 * - web fonts (DynamicAssetsUtils.php): inside each "font" object the
 *   breakpoint holding "family" comes first, "value" first in it, and
 *   "family" before any object. Missed, the font is not loaded.
 *
 * JSON object order means nothing to Divi otherwise, so after every write
 * SaddleWP orders each decoration group the way both regexes need: desktop
 * first and the other breakpoints in Divi's order, then "value" first among
 * the states, then plain values before objects inside "value". Nothing
 * outside `<attr>.decoration` is touched.
 */
class Saddle_Divi_Key_Order {

	/**
	 * Order the decoration groups of a module's attrs.
	 *
	 * @param array $attrs Module attrs.
	 * @return array
	 */
	public static function apply( array $attrs ) {
		foreach ( $attrs as $name => $attr ) {
			if ( is_array( $attr ) && isset( $attr['decoration'] ) && is_array( $attr['decoration'] ) && ! array_is_list( $attr['decoration'] ) ) {
				foreach ( $attr['decoration'] as $group => $value ) {
					$attrs[ $name ]['decoration'][ $group ] = self::group( $value );
				}
			}
			if ( 'children' === $name && is_array( $attr ) && ! array_is_list( $attr ) ) {
				// A parent's defaults for its children hold attrs too.
				$attrs[ $name ] = self::apply( $attr );
			}
		}
		return $attrs;
	}

	/**
	 * Order one group, or the envelopes nested in it (font.font,
	 * bodyFont.body.font).
	 *
	 * @param mixed $node Group value.
	 * @return mixed
	 */
	private static function group( $node ) {
		if ( ! is_array( $node ) || array_is_list( $node ) ) {
			return $node;
		}
		if ( Saddle_Divi_Envelope::has_breakpoint_key( $node ) ) {
			return self::envelope( $node );
		}
		foreach ( $node as $key => $value ) {
			$node[ $key ] = self::group( $value );
		}
		return $node;
	}

	/**
	 * Breakpoints in Divi's order, desktop first; anything else after them
	 * in the order it came.
	 *
	 * @param array $node Envelope.
	 * @return array
	 */
	private static function envelope( array $node ) {
		$out = array();
		foreach ( Saddle_Divi_Envelope::BREAKPOINTS as $breakpoint ) {
			if ( array_key_exists( $breakpoint, $node ) ) {
				$out[ $breakpoint ] = self::states( $node[ $breakpoint ] );
			}
		}
		foreach ( $node as $key => $value ) {
			if ( ! array_key_exists( $key, $out ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * "value" first among the states, its plain values before its objects.
	 *
	 * @param mixed $node A breakpoint's states.
	 * @return mixed
	 */
	private static function states( $node ) {
		if ( ! is_array( $node ) || array_is_list( $node ) || ! array_key_exists( 'value', $node ) ) {
			return $node;
		}
		$out = array( 'value' => self::scalars_first( $node['value'] ) );
		foreach ( $node as $key => $value ) {
			if ( 'value' !== $key ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Plain values before objects and lists, each kept in its own order.
	 *
	 * @param mixed $value A state's value.
	 * @return mixed
	 */
	private static function scalars_first( $value ) {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return $value;
		}
		$plain   = array();
		$objects = array();
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$objects[ $key ] = $item;
			} else {
				$plain[ $key ] = $item;
			}
		}
		return $plain + $objects;
	}
}
