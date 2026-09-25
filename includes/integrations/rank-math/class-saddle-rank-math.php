<?php
/**
 * Rank Math environment detection + robots-array mapping.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether Rank Math is active, and centralizes its one real data
 * trap: `rank_math_robots` is a flat token ARRAY (index, noindex, nofollow,
 * noarchive, noimageindex, nosnippet), where index/noindex are mutually
 * exclusive, "follow" is simply the absence of the nofollow token, and an
 * absent/empty array means "inherit the site default". Agents never see the
 * array — they get friendly enum fields, and every write read-merges the
 * stored array so unrelated tokens (including ones a future Rank Math adds)
 * survive untouched.
 */
class Saddle_Rank_Math {

	/**
	 * The advanced robots tokens this integration recognizes. index/noindex/
	 * nofollow are handled by the two enum fields; everything else in the
	 * stored array is preserved verbatim on write.
	 */
	const ADVANCED_TOKENS = array( 'noarchive', 'noimageindex', 'nosnippet' );

	/**
	 * Whether Rank Math is active on this site.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$active = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );

		/**
		 * Filter Rank Math detection (tests / edge setups).
		 *
		 * @param bool $active Whether Rank Math is considered active.
		 */
		return (bool) apply_filters( 'saddle_rankmath_active', $active );
	}

	/**
	 * The active Rank Math version, or null when it isn't active.
	 *
	 * @return string|null
	 */
	public static function version() {
		return self::is_active() && defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : null;
	}

	/**
	 * The standard "not active" error — abilities always register
	 * (mirroring Divi/Yoast), each refuses cleanly without the plugin.
	 *
	 * @return WP_Error
	 */
	public static function not_active_error() {
		return new WP_Error(
			'saddle_no_rankmath',
			__( 'Rank Math is not active on this site.', 'saddle' )
		);
	}

	/**
	 * Read the friendly robots view out of a stored robots array.
	 *
	 * @param array $robots Stored `rank_math_robots` array (possibly empty).
	 * @return array{robots_index:string,robots_follow:string,robots_advanced:string}
	 */
	public static function robots_read( array $robots ) {
		$index = 'default';
		if ( in_array( 'noindex', $robots, true ) ) {
			$index = 'noindex';
		} elseif ( in_array( 'index', $robots, true ) ) {
			$index = 'index';
		}

		return array(
			'robots_index'    => $index,
			// Rank Math has no explicit "follow" token — follow IS the
			// absence of nofollow, so this field is a two-state, not a
			// tri-state like Yoast's.
			'robots_follow'   => in_array( 'nofollow', $robots, true ) ? 'nofollow' : 'follow',
			'robots_advanced' => implode( ',', array_values( array_intersect( $robots, self::ADVANCED_TOKENS ) ) ),
		);
	}

	/**
	 * Merge friendly robots inputs into a stored robots array. Only the
	 * keys present in $input change their tokens; everything else in the
	 * stored array — including tokens this integration doesn't know —
	 * is preserved.
	 *
	 * @param array $robots Stored robots array.
	 * @param array $input  Ability input (may hold robots_index /
	 *                      robots_follow / robots_advanced).
	 * @return array|WP_Error The merged array (empty = inherit site default).
	 */
	public static function robots_merge( array $robots, array $input ) {
		if ( array_key_exists( 'robots_index', $input ) ) {
			$value = strtolower( trim( (string) $input['robots_index'] ) );
			if ( ! in_array( $value, array( 'default', 'index', 'noindex' ), true ) ) {
				return new WP_Error( 'saddle_bad_robots_value', __( 'robots_index expects one of: default, index, noindex.', 'saddle' ) );
			}
			// index/noindex are mutually exclusive — strip both, then add
			// the requested one ("default" adds nothing = inherit).
			$robots = array_diff( $robots, array( 'index', 'noindex' ) );
			if ( 'default' !== $value ) {
				$robots[] = $value;
			}
		}

		if ( array_key_exists( 'robots_follow', $input ) ) {
			$value = strtolower( trim( (string) $input['robots_follow'] ) );
			if ( ! in_array( $value, array( 'follow', 'nofollow' ), true ) ) {
				return new WP_Error( 'saddle_bad_robots_value', __( 'robots_follow expects "follow" or "nofollow".', 'saddle' ) );
			}
			$robots = array_diff( $robots, array( 'nofollow' ) );
			if ( 'nofollow' === $value ) {
				$robots[] = 'nofollow';
			}
		}

		if ( array_key_exists( 'robots_advanced', $input ) ) {
			$requested = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $input['robots_advanced'] ) ) ) );
			$unknown   = array_diff( $requested, self::ADVANCED_TOKENS );
			if ( $unknown ) {
				return new WP_Error(
					'saddle_bad_robots_value',
					sprintf(
						/* translators: %s: comma-separated list of accepted tokens. */
						__( 'robots_advanced expects a comma list of: %s (empty clears them).', 'saddle' ),
						implode( ', ', self::ADVANCED_TOKENS )
					)
				);
			}
			$robots = array_merge( array_diff( $robots, self::ADVANCED_TOKENS ), $requested );
		}

		return array_values( array_unique( $robots ) );
	}
}
