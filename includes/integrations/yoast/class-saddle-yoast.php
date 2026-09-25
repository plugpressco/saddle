<?php
/**
 * Yoast SEO environment detection + shared field-mapping helpers.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether Yoast SEO is active, and centralizes the small pieces of
 * Yoast's data model every Yoast ability needs: the robots tri-state
 * encoding and length-warning thresholds. One place, so the robots trap
 * can't drift between the SEO and schema classes.
 */
class Saddle_Yoast {

	/**
	 * Whether Yoast SEO is active on this site.
	 *
	 * Constant + class check, not is_plugin_active() — matches how every
	 * other detection in this plugin works (theme/plugin classes load in
	 * every request context that matters; the options-table plugin list
	 * does not need consulting).
	 *
	 * @return bool
	 */
	public static function is_active() {
		$active = defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Meta' );

		/**
		 * Filter Yoast SEO detection (tests / edge setups where the
		 * constant/class check alone can't decide).
		 *
		 * @param bool $active Whether Yoast SEO is considered active.
		 */
		return (bool) apply_filters( 'saddle_yoast_active', $active );
	}

	/**
	 * The active Yoast SEO version, or null when it isn't active.
	 *
	 * @return string|null
	 */
	public static function version() {
		return self::is_active() && defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : null;
	}

	/**
	 * The standard "not active" error every Yoast ability returns when
	 * called on a site without Yoast — abilities always register (mirroring
	 * the Divi abilities' unconditional registration), so this is the one
	 * place that refusal message lives.
	 *
	 * @return WP_Error
	 */
	public static function not_active_error() {
		return new WP_Error(
			'saddle_no_yoast',
			__( 'Yoast SEO is not active on this site.', 'saddle' )
		);
	}

	/**
	 * Robots "noindex" friendly-word <-> Yoast tri-state maps.
	 *
	 * Yoast stores noindex as '0' (default/inherit), '1' (noindex), or '2'
	 * (index) — never boolean, never absent-means-false. Exposing only these
	 * three friendly words keeps an agent from ever needing to know the
	 * encoding, and normalizing before write means an invalid fourth value
	 * can never be persisted.
	 *
	 * @return array{to: array<string,string>, from: array<string,string>}
	 */
	public static function noindex_maps() {
		return array(
			'to'   => array(
				'default' => '0',
				'noindex' => '1',
				'index'   => '2',
			),
			'from' => array(
				'0' => 'default',
				'1' => 'noindex',
				'2' => 'index',
			),
		);
	}

	/**
	 * Robots "nofollow" friendly-word <-> Yoast tri-state maps.
	 *
	 * @return array{to: array<string,string>, from: array<string,string>}
	 */
	public static function nofollow_maps() {
		return array(
			'to'   => array(
				'default'  => '0',
				'nofollow' => '1',
				'follow'   => '2',
			),
			'from' => array(
				'0' => 'default',
				'1' => 'nofollow',
				'2' => 'follow',
			),
		);
	}

	/**
	 * Normalize a friendly robots value to Yoast's stored tri-state, or a
	 * WP_Error naming the accepted words.
	 *
	 * @param string $friendly Friendly value from ability input.
	 * @param array  $map      The 'to' half of noindex_maps()/nofollow_maps().
	 * @return string|WP_Error
	 */
	public static function to_tri_state( $friendly, array $map ) {
		$key = strtolower( trim( (string) $friendly ) );
		if ( ! isset( $map[ $key ] ) ) {
			return new WP_Error(
				'saddle_bad_robots_value',
				sprintf(
					/* translators: %s: comma-separated list of accepted values. */
					__( 'Expected one of: %s.', 'saddle' ),
					implode( ', ', array_keys( $map ) )
				)
			);
		}
		return $map[ $key ];
	}

	/**
	 * Yoast's stored tri-state -> the friendly word this integration exposes.
	 * Empty string (Yoast's "never set") reads the same as '0' (default).
	 *
	 * @param string $stored Yoast's stored value.
	 * @param array  $map    The 'from' half of noindex_maps()/nofollow_maps().
	 * @return string
	 */
	public static function from_tri_state( $stored, array $map ) {
		$stored = '' === (string) $stored ? '0' : (string) $stored;
		return isset( $map[ $stored ] ) ? $map[ $stored ] : $map['0'];
	}
}
