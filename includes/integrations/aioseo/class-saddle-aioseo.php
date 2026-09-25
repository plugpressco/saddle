<?php
/**
 * AIOSEO environment detection + robots-model mapping.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether AIOSEO is active, and centralizes its robots model:
 * boolean columns (robots_noindex, robots_nofollow, robots_noarchive,
 * robots_nosnippet, robots_noimageindex) all gated by ONE master switch,
 * robots_default — while it is true, AIOSEO ignores every specific flag
 * (confirmed in Waggle's writer, which learned it the hard way). So the
 * friendly fields here flip the master switch and the flags together, and
 * "default" can only be honored when no other flag remains set.
 */
class Saddle_Aioseo {

	/**
	 * Friendly advanced token => model column.
	 */
	const ADVANCED_COLUMNS = array(
		'noarchive'    => 'robots_noarchive',
		'nosnippet'    => 'robots_nosnippet',
		'noimageindex' => 'robots_noimageindex',
	);

	/**
	 * Whether AIOSEO (v4+) is active on this site.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$active = defined( 'AIOSEO_VERSION' ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' );

		/**
		 * Filter AIOSEO detection (tests / edge setups).
		 *
		 * @param bool $active Whether AIOSEO is considered active.
		 */
		return (bool) apply_filters( 'saddle_aioseo_active', $active );
	}

	/**
	 * The active AIOSEO version, or null when it isn't active.
	 *
	 * @return string|null
	 */
	public static function version() {
		return self::is_active() && defined( 'AIOSEO_VERSION' ) ? (string) AIOSEO_VERSION : null;
	}

	/**
	 * The standard "not active" error — abilities always register, each
	 * refuses cleanly without the plugin (Divi/Yoast/Rank Math precedent).
	 *
	 * @return WP_Error
	 */
	public static function not_active_error() {
		return new WP_Error(
			'saddle_no_aioseo',
			__( 'AIOSEO is not active on this site.', 'saddle' )
		);
	}

	/**
	 * A model boolean, coerced: the table hands back tinyints as strings.
	 *
	 * @param mixed $value Raw model value.
	 * @return bool
	 */
	public static function truthy( $value ) {
		return true === $value || 1 === $value || '1' === $value;
	}

	/**
	 * The friendly robots view of a Post model.
	 *
	 * @param object $model AIOSEO Post model.
	 * @return array{robots_index:string,robots_follow:string,robots_advanced:string}
	 */
	public static function robots_read( $model ) {
		if ( self::truthy( $model->robots_default ?? true ) ) {
			return array(
				'robots_index'    => 'default',
				'robots_follow'   => 'follow',
				'robots_advanced' => '',
			);
		}

		$advanced = array();
		foreach ( self::ADVANCED_COLUMNS as $token => $column ) {
			if ( self::truthy( $model->{$column} ?? false ) ) {
				$advanced[] = $token;
			}
		}

		return array(
			'robots_index'    => self::truthy( $model->robots_noindex ?? false ) ? 'noindex' : 'index',
			'robots_follow'   => self::truthy( $model->robots_nofollow ?? false ) ? 'nofollow' : 'follow',
			'robots_advanced' => implode( ',', $advanced ),
		);
	}

	/**
	 * Merge friendly robots inputs into a set of model column changes.
	 *
	 * @param object $model AIOSEO Post model (current state).
	 * @param array  $input Ability input.
	 * @return array|WP_Error Column => value changes (may be empty).
	 */
	public static function robots_changes( $model, array $input ) {
		$fields = array( 'robots_index', 'robots_follow', 'robots_advanced' );
		if ( ! array_intersect( $fields, array_keys( $input ) ) ) {
			return array();
		}

		// Current effective flag state: while the master switch is on, every
		// flag reads as false regardless of what the columns hold.
		$default = self::truthy( $model->robots_default ?? true );
		$state   = array(
			'robots_noindex'  => ! $default && self::truthy( $model->robots_noindex ?? false ),
			'robots_nofollow' => ! $default && self::truthy( $model->robots_nofollow ?? false ),
		);
		foreach ( self::ADVANCED_COLUMNS as $column ) {
			$state[ $column ] = ! $default && self::truthy( $model->{$column} ?? false );
		}

		$wants_default = false;

		if ( array_key_exists( 'robots_index', $input ) ) {
			$value = strtolower( trim( (string) $input['robots_index'] ) );
			if ( ! in_array( $value, array( 'default', 'index', 'noindex' ), true ) ) {
				return new WP_Error( 'saddle_bad_robots_value', __( 'robots_index expects one of: default, index, noindex.', 'saddle' ) );
			}
			$state['robots_noindex'] = 'noindex' === $value;
			$wants_default           = 'default' === $value;
		}

		if ( array_key_exists( 'robots_follow', $input ) ) {
			$value = strtolower( trim( (string) $input['robots_follow'] ) );
			if ( ! in_array( $value, array( 'follow', 'nofollow' ), true ) ) {
				return new WP_Error( 'saddle_bad_robots_value', __( 'robots_follow expects "follow" or "nofollow".', 'saddle' ) );
			}
			$state['robots_nofollow'] = 'nofollow' === $value;
		}

		if ( array_key_exists( 'robots_advanced', $input ) ) {
			$requested = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $input['robots_advanced'] ) ) ) );
			$unknown   = array_diff( $requested, array_keys( self::ADVANCED_COLUMNS ) );
			if ( $unknown ) {
				return new WP_Error(
					'saddle_bad_robots_value',
					sprintf(
						/* translators: %s: comma-separated list of accepted tokens. */
						__( 'robots_advanced expects a comma list of: %s (empty clears them).', 'saddle' ),
						implode( ', ', array_keys( self::ADVANCED_COLUMNS ) )
					)
				);
			}
			foreach ( self::ADVANCED_COLUMNS as $token => $column ) {
				$state[ $column ] = in_array( $token, $requested, true );
			}
		}

		// The master switch can only return to "default" when NOTHING else is
		// customized — AIOSEO's switch covers all robots flags together.
		$any_flag = (bool) array_filter( $state );
		$changes  = $state;

		$was_default               = self::truthy( $model->robots_default ?? true );
		$changes['robots_default'] = $any_flag ? false : ( $wants_default || $was_default );

		return $changes;
	}
}
