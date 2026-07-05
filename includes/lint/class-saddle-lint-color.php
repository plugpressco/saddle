<?php
/**
 * Color math for lint rules.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small, dependency-free color helpers: hex parsing, WCAG contrast, and hue
 * bucketing. Anything that doesn't parse as a hex color returns null and the
 * calling rule skips — gradients, var() references an accessor couldn't
 * resolve, and CSS keywords are never guessed at.
 */
class Saddle_Lint_Color {

	/**
	 * Parse a hex color into [r, g, b] (0–255). Alpha digits are ignored.
	 *
	 * @param mixed $color Candidate color string.
	 * @return int[]|null
	 */
	public static function parse( $color ) {
		if ( ! is_string( $color ) || ! preg_match( '/^#([0-9a-f]{3,8})$/i', trim( $color ), $m ) ) {
			return null;
		}
		$hex = $m[1];
		$len = strlen( $hex );

		if ( 3 === $len || 4 === $len ) {
			return array(
				hexdec( $hex[0] . $hex[0] ),
				hexdec( $hex[1] . $hex[1] ),
				hexdec( $hex[2] . $hex[2] ),
			);
		}
		if ( 6 === $len || 8 === $len ) {
			return array(
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
			);
		}
		return null;
	}

	/**
	 * Normalize a color string for equality comparison: lowercase, trimmed,
	 * short hex expanded. Non-hex strings are only case/space-normalized.
	 *
	 * @param string $color Color string.
	 * @return string
	 */
	public static function normalize( $color ) {
		$color = strtolower( trim( (string) $color ) );
		$rgb   = self::parse( $color );
		if ( $rgb ) {
			return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
		}
		return preg_replace( '/\s+/', '', $color );
	}

	/**
	 * WCAG contrast ratio between two hex colors (1–21).
	 *
	 * @param string $a First color.
	 * @param string $b Second color.
	 * @return float|null Null when either color doesn't parse.
	 */
	public static function contrast( $a, $b ) {
		$rgb_a = self::parse( $a );
		$rgb_b = self::parse( $b );
		if ( ! $rgb_a || ! $rgb_b ) {
			return null;
		}
		$la = self::luminance( $rgb_a );
		$lb = self::luminance( $rgb_b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Relative luminance per WCAG.
	 *
	 * @param int[] $rgb [r, g, b] 0–255.
	 * @return float
	 */
	private static function luminance( array $rgb ) {
		$chan = array();
		foreach ( $rgb as $c ) {
			$c      = $c / 255;
			$chan[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
	}

	/**
	 * Whether a parsed color is a neutral (gray/near-white/near-black) —
	 * neutrals don't count as accent hues.
	 *
	 * @param int[] $rgb [r, g, b].
	 * @return bool
	 */
	public static function is_neutral( array $rgb ) {
		list( , $s, $l ) = self::hsl( $rgb );
		return $s < 0.14 || $l > 0.93 || $l < 0.07;
	}

	/**
	 * Hue family bucket (0–11, 30° per bucket) for accent grouping.
	 *
	 * @param int[] $rgb [r, g, b].
	 * @return int
	 */
	public static function hue_family( array $rgb ) {
		list( $h ) = self::hsl( $rgb );
		return (int) floor( fmod( $h, 360 ) / 30 );
	}

	/**
	 * RGB → [hue 0–360, saturation 0–1, lightness 0–1].
	 *
	 * @param int[] $rgb [r, g, b] 0–255.
	 * @return float[]
	 */
	private static function hsl( array $rgb ) {
		$r   = $rgb[0] / 255;
		$g   = $rgb[1] / 255;
		$b   = $rgb[2] / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;

		if ( 0.0 === (float) $d ) {
			return array( 0.0, 0.0, $l );
		}

		$s = $d / ( 1 - abs( 2 * $l - 1 ) );

		if ( $max === $r ) {
			$h = 60 * fmod( ( $g - $b ) / $d, 6 );
		} elseif ( $max === $g ) {
			$h = 60 * ( ( $b - $r ) / $d + 2 );
		} else {
			$h = 60 * ( ( $r - $g ) / $d + 4 );
		}

		return array( $h < 0 ? $h + 360 : $h, $s, $l );
	}
}
