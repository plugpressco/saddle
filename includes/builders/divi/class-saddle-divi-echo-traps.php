<?php
/**
 * Value-shape traps for the Divi echo.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The values Divi saves and then quietly does nothing with, even on a path
 * the module declares. Saddle_Divi_Echo proves the PATH; this class judges
 * what was written there. Every check comes from reading Divi 5.10.1's own
 * source (the class and line are named beside each one), and each fires
 * only on a shape the Visual Builder never writes, so a page built in the
 * builder stays silent.
 *
 * Two kinds of finding: certain (Divi provably drops or misreads the value)
 * and advisory (it renders, but almost never the way the writer meant).
 * verify-page asks for certain ones only.
 */
class Saddle_Divi_Echo_Traps {

	/**
	 * Custom CSS fields every module takes (CssStyleUtils::get_statements()).
	 */
	const CSS_FIELDS = array( 'mainElement', 'before', 'after', 'freeForm' );

	/**
	 * Text case values (Font.php, FontCapitalizationMigration since 5.8.1).
	 */
	const CAPITALIZATION = array( 'uppercase', 'lowercase', 'capitalize', 'smallCaps', 'allSmallCaps' );

	/**
	 * Background image sizes (BackgroundStyleUtils::get_background_size_css(),
	 * the Visual Builder's own option list). Anything else renders `initial`.
	 */
	const BACKGROUND_SIZES = array( 'cover', 'contain', 'initial', 'stretch', 'custom', 'auto' );

	/**
	 * Gradient types (GradientUtils). Anything else renders linear.
	 */
	const GRADIENT_TYPES = array( 'linear', 'circular', 'elliptical', 'conic' );

	/**
	 * Animation styles and directions (AnimationUtils::_get_options() and its
	 * direction filter: an unknown direction falls back to center).
	 */
	const ANIMATION_STYLES     = array( 'none', 'fade', 'bounce', 'slide', 'zoom', 'flip', 'fold', 'roll' );
	const ANIMATION_DIRECTIONS = array( 'center', 'top', 'bottom', 'left', 'right' );

	/**
	 * Breakpoint names only the disabledOn group uses, always on
	 * (Breakpoint::get_default_settings_values() disabledOnItems).
	 */
	const DISABLED_ON_BREAKPOINTS = array( 'desktopAbove', 'tabletOnly' );

	/**
	 * Typography groups: their value nests under extra keys (font.font,
	 * bodyFont.body.font, headingFont.h1.font).
	 */
	const FONT_GROUPS = array( 'font', 'bodyFont', 'headingFont' );

	/**
	 * The breakpoints this site has turned on. Divi writes no CSS for the
	 * others (Breakpoint::is_enabled_for_style()). Read from Divi when it is
	 * loaded, else from its saved option, else Divi's defaults.
	 *
	 * @return string[]
	 */
	public static function enabled_breakpoints() {
		return Saddle_Divi_Style::breakpoints();
	}

	/**
	 * Every breakpoint > state > value row under a group payload, through
	 * any nesting keys (font.font, bodyFont.dropCap.font).
	 *
	 * @param mixed    $payload Group payload.
	 * @param string[] $sub     Nesting keys so far.
	 * @param int      $depth   Recursion guard.
	 * @return array[] Each { sub, breakpoint, state, data }.
	 */
	public static function rows( $payload, array $sub = array(), $depth = 0 ) {
		$rows = array();
		if ( ! is_array( $payload ) || $depth > 4 ) {
			return $rows;
		}
		$vocabulary = array_merge( Saddle_Divi_Echo::breakpoints(), self::DISABLED_ON_BREAKPOINTS );
		foreach ( $payload as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, $vocabulary, true ) ) {
				foreach ( is_array( $value ) ? $value : array() as $state => $data ) {
					$rows[] = array(
						'sub'        => $sub,
						'breakpoint' => $key,
						'state'      => (string) $state,
						'data'       => $data,
					);
				}
				continue;
			}
			if ( is_array( $value ) ) {
				$rows = array_merge( $rows, self::rows( $value, array_merge( $sub, array( $key ) ), $depth + 1 ) );
			}
		}
		return $rows;
	}

	/**
	 * Judge the values written in one decoration group.
	 *
	 * @param string $attr_name Attribute the group sits on.
	 * @param string $group     Decoration group.
	 * @param mixed  $payload   What was written under attr.decoration.group.
	 * @param string $location  Module + address for messages.
	 * @param array  $context   { advisory: bool, has_preset: bool }.
	 * @return string[]
	 */
	public static function group( $attr_name, $group, $payload, $location, array $context ) {
		$warnings = array();
		$base     = $attr_name . '.decoration.' . $group;
		$rows     = self::rows( $payload );

		foreach ( $rows as $row ) {
			$path = self::row_path( $base, $row );
			$data = $row['data'];
			if ( 'zIndex' === $group && is_array( $data ) ) {
				$warnings[] = sprintf(
					/* translators: 1: module + address, 2: written path, 3: example path. */
					__( 'The z-index on %1$s takes one plain value, not an object, so Divi will not use what was written at %2$s. Write %3$s = "10".', 'saddle' ),
					$location,
					$path,
					$base . '.' . $row['breakpoint'] . '.' . $row['state']
				);
				continue;
			}
			if ( ! is_array( $data ) ) {
				continue;
			}
			if ( in_array( $group, self::FONT_GROUPS, true ) ) {
				$warnings = array_merge( $warnings, self::font_row( $attr_name, $row, $path, $location, ! empty( $context['advisory'] ) ) );
			} elseif ( 'layout' === $group && array_key_exists( 'gap', $data ) ) {
				$warnings[] = sprintf(
					/* translators: 1: module + address, 2: written path. */
					__( 'Divi has no "gap" setting in the layout group of %1$s (%2$s), so the gap will not render. Write "columnGap" and "rowGap" instead, for example { "columnGap": "24px", "rowGap": "24px" }.', 'saddle' ),
					$location,
					$path
				);
			} elseif ( 'background' === $group ) {
				$warnings = array_merge( $warnings, self::background_row( $data, $path ) );
			} elseif ( 'animation' === $group ) {
				$warnings = array_merge( $warnings, self::animation_row( $data, $path ) );
			} elseif ( 'interactions' === $group ) {
				$warnings = array_merge( $warnings, self::interactions_row( $data, $path ) );
			} elseif ( 'position' === $group && ! empty( $context['advisory'] ) ) {
				$origin = isset( $data['origin']['relative'] ) && is_string( $data['origin']['relative'] ) ? $data['origin']['relative'] : '';
				if ( isset( $data['mode'] ) && 'relative' === $data['mode'] && false !== strpos( $origin, 'center' ) ) {
					// StyleDeclarationTrait::_get_position_translates() adds translate(-50%) per centred axis.
					$warnings[] = sprintf(
						/* translators: 1: module + address, 2: origin value. */
						__( 'The relative position on %1$s uses the origin "%2$s". With a centred origin Divi also moves the element by half its own size, which shifts the whole element. For a plain positioning parent write "origin": { "relative": "top left" } with zero offsets.', 'saddle' ),
						$location,
						$origin
					);
				}
			}
		}

		if ( 'boxShadow' === $group && empty( $context['has_preset'] ) ) {
			$warnings = array_merge( $warnings, self::box_shadow( $rows, $base, $location ) );
		}
		return $warnings;
	}

	/**
	 * A box shadow with values but no `style` anywhere: BoxShadow::value()
	 * returns nothing when the style key is missing.
	 *
	 * @param array[] $rows     Group rows.
	 * @param string  $base     Group path.
	 * @param string  $location Module + address.
	 * @return string[]
	 */
	private static function box_shadow( array $rows, $base, $location ) {
		$has_values = false;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row['data'] ) ) {
				continue;
			}
			if ( array_key_exists( 'style', $row['data'] ) ) {
				return array();
			}
			$has_values = $has_values || array() !== $row['data'];
		}
		if ( ! $has_values ) {
			return array();
		}
		return array(
			sprintf(
				/* translators: 1: module + address, 2: group path. */
				__( 'The box shadow on %1$s (%2$s) has values but no "style", so Divi does not draw it. Add "style": "preset1" (any of preset1 to preset7) beside the values.', 'saddle' ),
				$location,
				$base
			),
		);
	}

	/**
	 * Typography traps in one row.
	 *
	 * @param string $attr_name Attribute name.
	 * @param array  $row       Row.
	 * @param string $path      Row path.
	 * @param string $location  Module + address.
	 * @param bool   $advisory  Include advisory findings.
	 * @return string[]
	 */
	private static function font_row( $attr_name, array $row, $path, $location, $advisory ) {
		$warnings = array();
		$data     = $row['data'];

		if ( array_key_exists( 'textTransform', $data ) ) {
			$warnings[] = sprintf(
				/* translators: 1: module + address, 2: written path. */
				__( '"textTransform" is not a Divi font setting (%1$s, %2$s), so the text case will not change. Write "capitalization": "uppercase" (or lowercase, capitalize, smallCaps, allSmallCaps) in the same place.', 'saddle' ),
				$location,
				$path
			);
		}

		// Divi 5.8.1 moved text case out of `style` (FontCapitalizationMigration);
		// a newer site ignores it there.
		$version = Saddle_Divi::version();
		if ( isset( $data['style'] ) && ( null === $version || version_compare( $version, '5.8.1', '>=' ) ) ) {
			foreach ( array_intersect( (array) $data['style'], self::CAPITALIZATION ) as $word ) {
				$warnings[] = sprintf(
					/* translators: 1: module + address, 2: written path, 3: text case word. */
					__( 'Divi reads text case from "capitalization", not from "style" (%1$s, %2$s), so "%3$s" will not change the text. Write "capitalization": "%3$s" and keep "style" for italic, underline, overline and strikethrough.', 'saddle' ),
					$location,
					$path,
					(string) $word
				);
			}
		}

		// Font::style_declaration() reads dropCap* wherever it finds it, so on
		// the body it enlarges the whole paragraph (Font.php, FontBodyStyle).
		if ( ! in_array( 'dropCap', $row['sub'], true ) ) {
			foreach ( array_keys( $data ) as $key ) {
				if ( 0 === strpos( (string) $key, 'dropCap' ) ) {
					$warnings[] = sprintf(
						/* translators: 1: setting, 2: module + address, 3: correct path. */
						__( '"%1$s" sits in the text settings of %2$s, so Divi applies it to the whole paragraph, not to the first letter. Write it at %3$s.', 'saddle' ),
						(string) $key,
						$location,
						$attr_name . '.decoration.bodyFont.dropCap.font.' . $row['breakpoint'] . '.' . $row['state'] . '.' . $key
					);
				}
			}
		}

		// DynamicAssetsUtils loads a web font from a literal family or a
		// $variable() token of type content, never from var(--gvid-…).
		if ( $advisory && isset( $data['family'] ) && is_string( $data['family'] )
			&& preg_match( '/^var\(\s*--(gvid-[a-z0-9-]+)\s*\)$/i', trim( $data['family'] ), $m ) ) {
			$warnings[] = sprintf(
				/* translators: 1: module + address, 2: written family, 3: token to write. */
				__( 'The font family on %1$s is written as %2$s, and Divi does not load a web font written that way, so the text may show in a fallback font. Write the family as %3$s.', 'saddle' ),
				$location,
				trim( $data['family'] ),
				Saddle_Divi_Tokens::font_token( $m[1] )
			);
		}
		return $warnings;
	}

	/**
	 * Background image size and gradient type.
	 *
	 * @param array  $data Row value.
	 * @param string $path Row path.
	 * @return string[]
	 */
	private static function background_row( array $data, $path ) {
		$warnings = array();
		$size     = isset( $data['image']['size'] ) ? $data['image']['size'] : null;
		if ( is_string( $size ) && ! in_array( $size, self::BACKGROUND_SIZES, true ) ) {
			$warnings[] = sprintf(
				/* translators: 1: written size, 2: written path. */
				__( 'Background image size "%1$s" at %2$s is not one Divi knows, so the image shows at its actual size. Use cover, contain, initial, stretch, or custom with "width" and "height".', 'saddle' ),
				$size,
				$path
			);
		}
		$type = isset( $data['gradient']['type'] ) ? $data['gradient']['type'] : null;
		if ( is_string( $type ) && ! in_array( $type, self::GRADIENT_TYPES, true ) ) {
			$warnings[] = sprintf(
				/* translators: 1: written type, 2: written path. */
				__( 'Gradient type "%1$s" at %2$s is not one Divi knows, so it renders as a linear gradient. Use linear, circular, elliptical or conic.', 'saddle' ),
				$type,
				$path
			);
		}
		return $warnings;
	}

	/**
	 * Animation style and direction.
	 *
	 * @param array  $data Row value.
	 * @param string $path Row path.
	 * @return string[]
	 */
	private static function animation_row( array $data, $path ) {
		$warnings = array();
		$style    = isset( $data['style'] ) && is_string( $data['style'] ) ? $data['style'] : null;
		if ( null !== $style && ! in_array( $style, self::ANIMATION_STYLES, true ) ) {
			if ( preg_match( '/^(' . implode( '|', self::ANIMATION_STYLES ) . ')(Top|Right|Bottom|Left)$/', $style, $m ) ) {
				$warnings[] = sprintf(
					/* translators: 1: written style, 2: written path, 3: style, 4: direction. */
					__( 'Animation style "%1$s" at %2$s joins the style and the direction, so nothing animates. Divi reads them apart: write "style": "%3$s" and "direction": "%4$s".', 'saddle' ),
					$style,
					$path,
					$m[1],
					strtolower( $m[2] )
				);
			} else {
				$warnings[] = sprintf(
					/* translators: 1: written style, 2: written path, 3: valid styles. */
					__( 'Animation style "%1$s" at %2$s is not one Divi knows, so nothing animates. Use one of: %3$s.', 'saddle' ),
					$style,
					$path,
					implode( ', ', self::ANIMATION_STYLES )
				);
			}
		}
		$direction = isset( $data['direction'] ) && is_string( $data['direction'] ) ? $data['direction'] : null;
		if ( null !== $direction && ! in_array( $direction, self::ANIMATION_DIRECTIONS, true ) ) {
			$warnings[] = sprintf(
				/* translators: 1: written direction, 2: written path, 3: valid directions. */
				__( 'Animation direction "%1$s" at %2$s is not one Divi knows, so it plays from the center. Use one of: %3$s.', 'saddle' ),
				$direction,
				$path,
				implode( ', ', self::ANIMATION_DIRECTIONS )
			);
		}
		return $warnings;
	}

	/**
	 * Interaction trigger and target classes. The front-end script finds a
	 * trigger only by the "et-interaction-trigger-" prefix and an id of
	 * letters and digits (Module.php reads it with the same pattern), and a
	 * module target only by "et-interaction-target-".
	 *
	 * @param array  $data Row value.
	 * @param string $path Row path.
	 * @return string[]
	 */
	private static function interactions_row( array $data, $path ) {
		$warnings = array();
		$items    = isset( $data['interactions'] ) && is_array( $data['interactions'] ) ? $data['interactions'] : array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$trigger = isset( $item['triggerClass'] ) && is_string( $item['triggerClass'] ) ? $item['triggerClass'] : '';
			if ( '' !== $trigger && ! preg_match( '/^et-interaction-trigger-[A-Za-z0-9]+$/', $trigger ) ) {
				$warnings[] = sprintf(
					/* translators: 1: written class, 2: written path. */
					__( 'The interaction trigger class "%1$s" at %2$s must be "et-interaction-trigger-" followed by letters and digits only, or the interaction never starts. For example: et-interaction-trigger-pricing.', 'saddle' ),
					$trigger,
					$path
				);
			}
			$target_type = isset( $item['target']['targetType'] ) ? (string) $item['target']['targetType'] : 'module';
			$targets     = isset( $item['target']['targetClass'] ) && is_string( $item['target']['targetClass'] ) ? preg_split( '/[,\s]+/', trim( $item['target']['targetClass'] ) ) : array();
			foreach ( 'module' === $target_type ? array_filter( (array) $targets ) : array() as $target ) {
				if ( 0 !== strpos( $target, 'et-interaction-target-' ) ) {
					$warnings[] = sprintf(
						/* translators: 1: written class, 2: written path. */
						__( 'The interaction target class "%1$s" at %2$s must start with "et-interaction-target-", or the interaction has no target. The target module needs the same id in its module.decoration.interactionTarget.', 'saddle' ),
						$target,
						$path
					);
				}
			}
		}
		return $warnings;
	}

	/**
	 * Dotted path of one row.
	 *
	 * @param string $base Group path.
	 * @param array  $row  Row.
	 * @return string
	 */
	public static function row_path( $base, array $row ) {
		return implode( '.', array_merge( array( $base ), $row['sub'], array( $row['breakpoint'], $row['state'] ) ) );
	}
}
