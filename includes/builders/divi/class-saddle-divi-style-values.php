<?php
/**
 * Reads one style value off a Divi 5 node, in the form the site style counts.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The readers Saddle_Divi_Style_Tally counts with. They read through the
 * same trait as the page check (Saddle_Divi_Attr_Resolution), so a path,
 * an inherited phone value or a global color means the same thing here,
 * and write each value in one comparable form: "default" for a length left
 * unset, four sides or corners as one value when they are equal, colors
 * lowercase. A class of its own so the tally only decides what to count.
 */
class Saddle_Divi_Style_Values {

	use Saddle_Divi_Attr_Resolution;

	/**
	 * The font group, from an element's attr to the breakpoints.
	 */
	const FONT = array( 'decoration', 'font', 'font' );

	/**
	 * Heading levels.
	 */
	const LEVELS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * A node's attr envelopes by element name.
	 *
	 * @param array $node Raw block array.
	 * @return array[]
	 */
	public function elements( array $node ) {
		return $this->attrs( $node );
	}

	/**
	 * The value at a path inside one element, null when a key is missing.
	 *
	 * @param array    $attr Attr envelope.
	 * @param string[] $keys Path.
	 * @return mixed
	 */
	public function get( array $attr, array $keys ) {
		return $this->path( $attr, $keys );
	}

	/**
	 * A field of one element at a breakpoint, inherited the way Divi
	 * inherits (phone, then tablet, then desktop).
	 *
	 * @param array           $attr       Attr envelope.
	 * @param string[]        $group      Keys to the breakpoints.
	 * @param string|string[] $field      Key, or keys, inside the value.
	 * @param string          $breakpoint desktop or phone.
	 * @return mixed
	 */
	public function in( array $attr, array $group, $field, $breakpoint = 'desktop' ) {
		foreach ( $this->inherit_order( $breakpoint, 'value' ) as $cell ) {
			$value = $this->path( $attr, array_merge( $group, $cell, (array) $field ) );
			if ( null !== $value && '' !== $value && array() !== $value ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * in() on a node's element, by name.
	 *
	 * @param array           $node       Raw block array.
	 * @param string          $element    Element name.
	 * @param string[]        $group      Keys to the breakpoints.
	 * @param string|string[] $field      Key, or keys, inside the value.
	 * @param string          $breakpoint desktop or phone.
	 * @return mixed
	 */
	public function at( array $node, $element, array $group, $field, $breakpoint = 'desktop' ) {
		$attrs = $this->attrs( $node );
		return isset( $attrs[ $element ] ) ? $this->in( $attrs[ $element ], $group, $field, $breakpoint ) : null;
	}

	/**
	 * The heading level an element shows: the level it sets, else its
	 * module's default for that element (Divi's default render attributes)
	 * when it has text. divi/heading is an h1 when those cannot be read,
	 * as in the page check (Saddle_Divi_Lint_Accessor::heading_level()).
	 *
	 * @param string $type Module type.
	 * @param string $name Element name.
	 * @param array  $attr Its attr envelope.
	 * @return string|null h1 … h6.
	 */
	public function level( $type, $name, array $attr ) {
		$keys  = array_merge( self::FONT, array( 'desktop', 'value', 'headingLevel' ) );
		$level = $this->path( $attr, $keys );
		if ( ! is_string( $level ) ) {
			if ( ! $this->has_text( $attr ) ) {
				return null;
			}
			$defaults = Saddle_Divi_Schema::render_defaults( $type );
			$level    = isset( $defaults[ $name ] ) && is_array( $defaults[ $name ] ) ? $this->path( $defaults[ $name ], $keys ) : null;
			if ( null === $level && ! $defaults && 'divi/heading' === $type && 'title' === $name ) {
				$level = 'h1';
			}
		}
		$level = is_string( $level ) ? strtolower( trim( $level ) ) : '';
		return in_array( $level, self::LEVELS, true ) ? $level : null;
	}

	/**
	 * Whether an element shows text of its own.
	 *
	 * @param array $attr Attr envelope.
	 * @return bool
	 */
	public function has_text( array $attr ) {
		$text = $this->path( $attr, array( 'innerContent', 'desktop', 'value' ) );
		return is_string( $text ) && '' !== trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * A font face, "<family> <weight>", or null when the group sets
	 * neither. A font variable is named by its id; no family is "default".
	 *
	 * @param array    $attr  Attr envelope.
	 * @param string[] $group Font group.
	 * @return string|null
	 */
	public function face( array $attr, array $group ) {
		$family = $this->in( $attr, $group, 'family' );
		$weight = $this->in( $attr, $group, 'weight' );
		if ( ! is_string( $family ) && ! is_scalar( $weight ) ) {
			return null;
		}
		$token  = is_string( $family ) ? Saddle_Divi_Tokens::parse_variable( $family ) : null;
		$family = $token ? $token['name'] : ( is_string( $family ) ? trim( $family, " \t\n\r\0\x0B\"'" ) : 'default' );
		return trim( $family . ' ' . ( is_scalar( $weight ) ? (string) $weight : '' ) );
	}

	/**
	 * A stored color as [value, token]: the value resolved through the
	 * site's palette and normalized, and the global color token when it was
	 * written as one (null otherwise, and for a shifted global color, which
	 * is not the token itself). Null for no color.
	 *
	 * @param mixed $color Color as stored.
	 * @return array|null
	 */
	public function color( $color ) {
		if ( ! is_string( $color ) || '' === trim( $color ) ) {
			return null;
		}
		$color = trim( $color );
		$token = null;
		if ( false !== strpos( $color, 'gcid-' ) ) {
			$parsed = Saddle_Divi_Tokens::parse_variable( $color );
			$token  = $parsed ? ( array_filter( $parsed['settings'] ) ? null : Saddle_Divi::css_var( $parsed['name'] ) ) : $color;
		}
		$value = $this->resolve_global_color( $color );
		$value = $value === $color && null !== $token ? $token : self::normal( $value );
		return array( $value, $token );
	}

	/**
	 * A color in one comparable form: lowercase, #rgb and #rrggbbff as #rrggbb.
	 *
	 * @param string $color Color.
	 * @return string
	 */
	public static function normal( $color ) {
		$color = strtolower( trim( (string) $color ) );
		if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m ) ) {
			return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		}
		return preg_match( '/^(#[0-9a-f]{6})ff$/', $color, $m ) ? $m[1] : $color;
	}

	/**
	 * A length as text, "default" when unset.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function text( $value ) {
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : 'default';
	}

	/**
	 * Four corners clockwise from top left, one value when all are equal,
	 * "default" when none is set.
	 *
	 * @param mixed $radius Radius value.
	 * @return string
	 */
	public static function corners( $radius ) {
		return self::four( $radius, array( 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ), '0' );
	}

	/**
	 * Four sides in CSS order, one value when all are equal, "-" for a side
	 * left unset, "default" when none is set.
	 *
	 * @param mixed $padding Padding value.
	 * @return string
	 */
	public static function sides( $padding ) {
		return self::four( $padding, array( 'top', 'right', 'bottom', 'left' ), '-' );
	}

	/**
	 * Four keyed lengths as one string.
	 *
	 * @param mixed    $value Value.
	 * @param string[] $keys  Keys in order.
	 * @param string   $blank What an unset key shows.
	 * @return string
	 */
	private static function four( $value, array $keys, $blank ) {
		$parts = array();
		foreach ( $keys as $key ) {
			$parts[] = is_array( $value ) && isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) && '' !== trim( (string) $value[ $key ] ) ? trim( (string) $value[ $key ] ) : $blank;
		}
		$distinct = array_values( array_unique( $parts ) );
		if ( array( $blank ) === $distinct ) {
			return 'default';
		}
		return 1 === count( $distinct ) ? $parts[0] : implode( ' ', $parts );
	}
}
