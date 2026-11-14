<?php
/**
 * Where a Divi 5 attribute's breakpoint and state envelope sits.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every Divi 5 value lives in an envelope: `<path>.<breakpoint>.<state>`,
 * then the value itself. This class says where the envelope sits on a path
 * an agent wrote (locate()), for the plain write forms of divi-set-page,
 * divi-add-module and divi-edit-module (Saddle_Divi_Attr_Keys).
 *
 * The envelope rules were read in Divi 5.10.1 (module.json `attrName`s and
 * every core module's module-default-render-attributes.json): an envelope is
 * `<attr>.innerContent`, `<attr>.meta.<x>`, `<attr>.decoration.<group>` and
 * `<attr>.advanced.<option>`, except typography, which nests one level
 * deeper (`<attr>.decoration.font.font`, `.bodyFont.<part>.font`,
 * `.headingFont.<level>.font`), the text option (`advanced.text.text`), the
 * section dividers (`advanced.dividers.top|bottom`) and form focus styles
 * (`advanced.focus.<group>`). A module's own settings list, when it has one,
 * wins: some settings sit at `<attr>.advanced` with sub-names. Nothing here
 * was confirmed by rendering a page.
 */
class Saddle_Divi_Envelope {

	/**
	 * Every breakpoint name Divi 5 uses, in Divi's style order
	 * (Breakpoint::get_default_style_breakpoint_order()). desktopAbove and
	 * tabletOnly only appear under disabledOn.
	 */
	const BREAKPOINTS = array( 'desktop', 'desktopAbove', 'tabletWide', 'tablet', 'tabletOnly', 'phoneWide', 'phone', 'widescreen', 'ultraWide' );

	/**
	 * Every state name Divi 5 uses (ModuleUtils::states()).
	 */
	const STATES = array( 'value', 'hover', 'sticky', 'focus', 'checked', 'active' );


	/**
	 * Whether a path segment names a breakpoint.
	 *
	 * @param string $segment Segment.
	 * @return bool
	 */
	public static function is_breakpoint( $segment ) {
		return in_array( (string) $segment, self::BREAKPOINTS, true );
	}

	/**
	 * Whether an array is shaped like an envelope: one of its keys names a
	 * breakpoint.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function has_breakpoint_key( $value ) {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}
		foreach ( array_keys( $value ) as $key ) {
			if ( self::is_breakpoint( (string) $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where the envelope sits on a path.
	 *
	 * @param string   $type     Module type.
	 * @param string[] $segments Path segments, with no breakpoint in them.
	 * @return array|string|null {env: string[], rest: string[], setting?: array}
	 *                           when the path reaches an envelope (setting:
	 *                           the module setting it is); 'partial'
	 *                           when it stops before one (e.g. "module" or
	 *                           "module.decoration"); null when SaddleWP
	 *                           cannot tell.
	 */
	public static function locate( $type, array $segments ) {
		$segments = array_values( array_map( 'strval', $segments ) );
		if ( ! $segments ) {
			return null;
		}

		// A setting from the module's own list wins: its path is the
		// envelope Divi reads (module.json attrName).
		$best    = null;
		$setting = null;
		$all     = Saddle_Divi_Settings::for_module( (string) $type );
		foreach ( $all as $entry ) {
			$path = explode( '.', $entry['path'] );
			if ( count( $path ) <= count( $segments ) && array_slice( $segments, 0, count( $path ) ) === $path
				&& ( null === $best || count( $path ) > count( $best ) ) ) {
				$best    = $path;
				$setting = $entry;
				// Sub-named settings share a path: the one the next segment names.
				$sub = isset( $segments[ count( $path ) ] ) ? $segments[ count( $path ) ] : null;
				foreach ( $all as $sibling ) {
					if ( $sibling['path'] === $entry['path'] && null !== $sub && $sibling['sub_name'] === $sub ) {
						$setting = $sibling;
					}
				}
			}
		}
		if ( null !== $best ) {
			return array(
				'env'     => $best,
				'rest'    => array_slice( $segments, count( $best ) ),
				'setting' => $setting,
			);
		}

		if ( 'css' === $segments[0] ) {
			return self::split( $segments, 1 );
		}
		if ( 'children' === $segments[0] ) {
			// A parent's defaults for its children: children.<attr>.….
			if ( count( $segments ) < 2 ) {
				return 'partial';
			}
			$inner = self::structural( array_slice( $segments, 1 ) );
			if ( is_array( $inner ) ) {
				array_unshift( $inner['env'], 'children' );
			}
			return $inner;
		}
		return self::structural( $segments );
	}

	/**
	 * The envelope by Divi's general attribute shape.
	 *
	 * @param string[] $s Segments, attribute name first.
	 * @return array|string|null See locate().
	 */
	private static function structural( array $s ) {
		$n = count( $s );
		if ( 1 === $n ) {
			return 'partial';
		}
		switch ( $s[1] ) {
			case 'innerContent':
				return self::split( $s, 2 );
			case 'meta':
				return $n < 3 ? 'partial' : self::split( $s, 3 );
			case 'decoration':
				if ( $n < 3 ) {
					return 'partial';
				}
				if ( 'font' === $s[2] ) {
					// FontStyle reads <attr>.decoration.font.font, beside
					// textShadow and textEffects. "font.size" means
					// font.font + size.
					if ( $n < 4 ) {
						return 'partial';
					}
					if ( in_array( $s[3], array( 'font', 'textShadow', 'textEffects' ), true ) ) {
						return self::split( $s, 4 );
					}
					return array(
						'env'  => array( $s[0], 'decoration', 'font', 'font' ),
						'rest' => array_slice( $s, 3 ),
					);
				}
				if ( in_array( $s[2], array( 'bodyFont', 'headingFont' ), true ) ) {
					// <attr>.decoration.bodyFont.<part>.font.
					if ( $n < 5 ) {
						return 'partial';
					}
					if ( 'font' === $s[4] ) {
						return self::split( $s, 5 );
					}
					return array(
						'env'  => array( $s[0], 'decoration', $s[2], $s[3], 'font' ),
						'rest' => array_slice( $s, 4 ),
					);
				}
				return self::split( $s, 3 );
			case 'advanced':
				if ( $n < 3 ) {
					return 'partial';
				}
				if ( 'text' === $s[2] ) {
					if ( $n < 4 ) {
						return 'partial';
					}
					if ( in_array( $s[3], array( 'text', 'textShadow' ), true ) ) {
						return self::split( $s, 4 );
					}
					return array(
						'env'  => array( $s[0], 'advanced', 'text', 'text' ),
						'rest' => array_slice( $s, 3 ),
					);
				}
				if ( in_array( $s[2], array( 'dividers', 'focus' ), true ) ) {
					return $n < 4 ? 'partial' : self::split( $s, 4 );
				}
				return self::split( $s, 3 );
		}
		return null;
	}

	/**
	 * Split segments into the envelope and what follows it.
	 *
	 * @param string[] $s     Segments.
	 * @param int      $depth Envelope length.
	 * @return array
	 */
	private static function split( array $s, $depth ) {
		return array(
			'env'  => array_slice( $s, 0, $depth ),
			'rest' => array_slice( $s, $depth ),
		);
	}

	/**
	 * The one envelope a path that stops short can only mean, for a suffix
	 * written on it: "title.decoration.font" is font.font,
	 * "content.decoration.bodyFont.body" is body.font, "module.advanced.text"
	 * is text.text. Null when the path could mean several.
	 *
	 * @param string[] $s Segments locate() called partial.
	 * @return string[]|null
	 */
	public static function only_envelope( array $s ) {
		$n = count( $s );
		if ( 3 === $n && 'decoration' === $s[1] && 'font' === $s[2] ) {
			return array_merge( $s, array( 'font' ) );
		}
		if ( 4 === $n && 'decoration' === $s[1] && in_array( $s[2], array( 'bodyFont', 'headingFont' ), true ) ) {
			return array_merge( $s, array( 'font' ) );
		}
		if ( 3 === $n && 'advanced' === $s[1] && 'text' === $s[2] ) {
			return array_merge( $s, array( 'text' ) );
		}
		return null;
	}

	/**
	 * Parts that need a name before a screen size can go in: bodyFont and
	 * headingFont parts, divider edges, the text option.
	 *
	 * @param string[] $segments Segments that locate() called partial.
	 * @return string Plain hint, or ''.
	 */
	public static function partial_hint( array $segments ) {
		$last = (string) end( $segments );
		$hint = array(
			'bodyFont'    => 'bodyFont.body, bodyFont.link, bodyFont.ul, bodyFont.ol, bodyFont.quote',
			'headingFont' => 'headingFont.h1 … headingFont.h6',
			'dividers'    => 'dividers.top, dividers.bottom',
		);
		return isset( $hint[ $last ] ) ? $hint[ $last ] : '';
	}
}
