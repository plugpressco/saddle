<?php
/**
 * The builder context bundle — the agent's memory of this site's Divi.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kills the per-session re-discovery burn (saddle-pro#12): instead of
 * re-calling list-modules / list-global-colors / list-variables /
 * get-global-fonts every session, the agent gets ONE cached payload — and a
 * compact summary of it rides the auto-served system context, so a session
 * starts oriented with zero tool calls.
 *
 * COMPOSES the existing reads (the schema catalog, the design abilities'
 * own list methods) — never re-implements them. The cache signature covers
 * everything that could stale it: Divi's version, the active plugin set
 * (module packs), and the design-system content itself; a signature miss
 * self-corrects even if an invalidation hook never fired. When the Divi
 * design runtime isn't available in this process, the bundle degrades to
 * catalog-only rather than serving empty palettes as truth.
 */
class Saddle_Divi_Bundle {

	/**
	 * Transient holding the assembled bundle.
	 */
	const TRANSIENT = 'saddle_divi_context_bundle';

	/**
	 * Cache lifetime.
	 */
	const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Hard cap on the auto-injected summary, in characters — context budget
	 * discipline applies to our own injection too.
	 */
	const SUMMARY_BUDGET = 1000;

	/**
	 * The bundle, from cache when its signature still holds.
	 *
	 * @return array
	 */
	public static function get() {
		$signature = self::signature();
		$cached    = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'] ) && $cached['version'] === $signature ) {
			return $cached;
		}

		$bundle = self::assemble( $signature );
		set_transient( self::TRANSIENT, $bundle, self::TTL );
		return $bundle;
	}

	/**
	 * Drop the cached bundle (and let the next get() rebuild it).
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The compact summary block for the auto-served system context: enough
	 * to start oriented, one call away from everything.
	 *
	 * @return string[] Context lines (empty when there is nothing to say).
	 */
	public static function summary_lines() {
		$bundle = self::get();

		$facts = array();
		if ( ! empty( $bundle['modules'] ) ) {
			$facts[] = sprintf( '%d Divi modules are available', count( $bundle['modules'] ) );
		}
		$design = isset( $bundle['design_system'] ) ? $bundle['design_system'] : array();
		if ( ! empty( $design['global_colors'] ) ) {
			$swatches = array();
			foreach ( array_slice( $design['global_colors'], 0, 8 ) as $color ) {
				$swatches[] = $color['color'] . ' (' . $color['var'] . ')';
			}
			$facts[] = 'the global palette is ' . implode( ', ', $swatches );
		}
		if ( ! empty( $design['variables'] ) ) {
			$facts[] = sprintf( '%d design variables exist (reference as var(--gvid-…))', count( $design['variables'] ) );
		}
		if ( ! empty( $design['fonts'] ) ) {
			$facts[] = 'global fonts: ' . implode( '/', array_filter( array_map( 'strval', (array) $design['fonts'] ) ) );
		}

		if ( ! $facts ) {
			return array();
		}

		$line = '- Site design memory: ' . implode( '; ', $facts ) . '. Use these tokens instead of ad-hoc values. Call saddle/divi-context-bundle for the full catalog + design system in one call (skip list-modules / list-global-colors / list-variables).';
		if ( strlen( $line ) > self::SUMMARY_BUDGET ) {
			$line = substr( $line, 0, self::SUMMARY_BUDGET - 1 ) . '…';
		}
		return array( $line );
	}

	/*
	---------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------
	 */

	/**
	 * Assemble the bundle from the existing reads.
	 *
	 * @param string $signature The cache signature to stamp it with.
	 * @return array
	 */
	private static function assemble( $signature ) {
		$bundle = array(
			'divi_version' => (string) Saddle_Divi::version(),
			'modules'      => Saddle_Divi_Schema::catalog(),
			'packs'        => Saddle_Divi_Schema::module_packs(),
			'brand'        => array(
				'site_name' => get_bloginfo( 'name' ),
				'tagline'   => get_bloginfo( 'description' ),
			),
			/**
			 * Filter the build conventions the Divi context bundle carries.
			 *
			 * @param string[] $conventions One short rule per entry.
			 */
			'conventions'  => array_values(
				(array) apply_filters(
					'saddle_divi_bundle_conventions',
					array(
						__( 'Structure: section > row > column > modules; trees are validated on every write, invalid ones rejected.', 'saddle' ),
						__( 'Prefer global colors/variables (var(--gcid-…)/var(--gvid-…)) over ad-hoc hex — a token change updates everywhere.', 'saddle' ),
						__( 'A styled button needs the enable switch on ( <attr>.decoration.button.desktop.value.enable = "on" ) or it renders ghost.', 'saddle' ),
						__( 'After building, run verify-page and fix by address until the score is acceptable.', 'saddle' ),
					)
				)
			),
			'version'      => $signature,
		);

		// The design-system slice needs Divi's runtime; degrade to
		// catalog-only rather than pretending empty sets are the palette.
		$design = self::design_system();
		if ( null !== $design ) {
			$bundle['design_system'] = $design;
		} else {
			$bundle['note'] = __( 'The design-system slice (colors/variables/presets/fonts) was unavailable in this process — use the divi-list-global-colors / divi-list-variables tools directly if you need it.', 'saddle' );
		}
		return $bundle;
	}

	/**
	 * The design-system slice, composed from the design abilities' own
	 * reads. Null when the runtime is unavailable.
	 *
	 * @return array|null
	 */
	private static function design_system() {
		if ( ! class_exists( 'Saddle_Divi_Design' ) ) {
			return null;
		}

		$colors = Saddle_Divi_Design::list_global_colors();
		if ( is_wp_error( $colors ) ) {
			return null;
		}

		$variables = Saddle_Divi_Design::list_variables();
		$presets   = Saddle_Divi_Design::list_global_presets( array() );
		$fonts     = Saddle_Divi_Design::get_global_fonts();

		return array(
			'global_colors' => isset( $colors['colors'] ) ? $colors['colors'] : array(),
			'variables'     => ! is_wp_error( $variables ) && isset( $variables['variables'] ) ? $variables['variables'] : array(),
			'presets'       => ! is_wp_error( $presets ) && isset( $presets['presets'] ) ? $presets['presets'] : array(),
			'fonts'         => self::fonts_slice( $fonts ),
		);
	}

	/**
	 * The bundle's `fonts` slice from Saddle_Divi_Design::get_global_fonts(),
	 * keyed as that tool returns them. It used to read a `fonts` key the tool
	 * never had, so the slice was always empty (#214). Divi stores "none" for
	 * a font the owner never chose; that is not a font, so it is left out.
	 *
	 * @param array|WP_Error $fonts get_global_fonts() result.
	 * @return array
	 */
	public static function fonts_slice( $fonts ) {
		if ( is_wp_error( $fonts ) || ! is_array( $fonts ) ) {
			return array();
		}
		$slice = array();
		foreach ( array( 'heading_font', 'body_font' ) as $key ) {
			$font = isset( $fonts[ $key ] ) ? trim( (string) $fonts[ $key ] ) : '';
			if ( '' !== $font && 'none' !== strtolower( $font ) ) {
				$slice[ $key ] = $font;
			}
		}
		return $slice;
	}

	/**
	 * Everything that could stale the bundle, hashed: Divi's version, the
	 * active plugin set, and the design-system content itself.
	 *
	 * @return string
	 */
	private static function signature() {
		$plugins = (array) get_option( 'active_plugins', array() );

		$design = '';
		if ( class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
			// Hash every design-system slice assemble() caches — colors,
			// variables, presets, fonts — so out-of-band edits (Visual
			// Builder, WP-CLI) self-correct without waiting on the TTL.
			$presets = class_exists( '\ET\Builder\Packages\GlobalData\GlobalPreset' )
				? \ET\Builder\Packages\GlobalData\GlobalPreset::get_data()
				: '';
			$fonts   = function_exists( 'et_get_option' )
				? (string) et_get_option( 'heading_font', '' ) . '|' . (string) et_get_option( 'body_font', '' )
				: '';
			$design  = md5(
				wp_json_encode( \ET\Builder\Packages\GlobalData\GlobalData::get_global_colors() )
				. wp_json_encode( get_option( 'et_global_variables', '' ) )
				. wp_json_encode( $presets )
				. $fonts
			);
		}

		return substr(
			md5( (string) Saddle_Divi::version() . '|' . md5( implode( ',', $plugins ) ) . '|' . $design ),
			0,
			12
		);
	}
}
