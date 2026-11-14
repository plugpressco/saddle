<?php
/**
 * The keys an agent writes in a node's "attrs": suffixes and paths.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the keys of a node's "attrs" (and the suffixes on "fields" and
 * "settings" keys) and turns each into a write at its canonical path:
 *
 * - "<path>@<breakpoint>", "<path>:hover", "<path>:sticky", or both, put
 *   the value at <envelope>.<breakpoint>.<state>, after checking the
 *   breakpoint is switched on for the site;
 * - a path that names no breakpoint is the desktop value;
 * - a full canonical path ("….desktop.value.color") is stored exactly as
 *   before.
 *
 * Saddle_Divi_Envelope says where each path's envelope sits.
 */
class Saddle_Divi_Attr_Keys {

	/**
	 * The states a suffix may name.
	 */
	const SUFFIX_STATES = array( 'hover', 'sticky' );

	/**
	 * Groups Divi reads at desktop only, with no state.
	 */
	const DESKTOP_ONLY = array( 'advanced.htmlAttributes', 'decoration.interactions' );

	/**
	 * Read a written key: "<path>", "<path>@<breakpoint>", "<path>:<state>"
	 * or both, in either order.
	 *
	 * @param string $key Written key.
	 * @return array|WP_Error {base, breakpoint|null, state|null}.
	 */
	public static function parse_key( $key ) {
		$key = (string) $key;
		if ( false === strpos( $key, '@' ) && false === strpos( $key, ':' ) ) {
			return array(
				'base'       => $key,
				'breakpoint' => null,
				'state'      => null,
			);
		}
		$m = array();
		if ( preg_match( '/^([^@:]+)(?:@([A-Za-z]+))?(?::([A-Za-z]+))?$/', $key, $m ) ) {
			return array(
				'base'       => $m[1],
				'breakpoint' => isset( $m[2] ) && '' !== $m[2] ? $m[2] : null,
				'state'      => isset( $m[3] ) && '' !== $m[3] ? $m[3] : null,
			);
		}
		if ( preg_match( '/^([^@:]+):([A-Za-z]+)@([A-Za-z]+)$/', $key, $m ) ) {
			return array(
				'base'       => $m[1],
				'breakpoint' => $m[3],
				'state'      => $m[2],
			);
		}
		return new WP_Error(
			'saddle_bad_key_suffix',
			sprintf(
				/* translators: %s: the key as written. */
				__( 'The key "%s" has a screen size or state SaddleWP cannot read. Write "<path>@phone" for a screen size, "<path>:hover" for a state, or "<path>@tablet:hover" for both.', 'saddle' ),
				$key
			)
		);
	}

	/**
	 * Check a suffix against what this site has on.
	 *
	 * @param string|null $breakpoint Breakpoint from the suffix.
	 * @param string|null $state      State from the suffix.
	 * @param string      $key        The key as written, for messages.
	 * @return true|WP_Error
	 */
	public static function check_suffix( $breakpoint, $state, $key ) {
		if ( null !== $breakpoint ) {
			$enabled = Saddle_Divi_Style::breakpoints();
			if ( ! in_array( $breakpoint, $enabled, true ) ) {
				return new WP_Error(
					'saddle_breakpoint_off',
					sprintf(
						/* translators: 1: key, 2: breakpoint, 3: comma-separated breakpoint names. */
						__( '"%1$s": the screen size "%2$s" is not switched on for this site, so Divi would print nothing for it. Screen sizes on here: %3$s.', 'saddle' ),
						$key,
						$breakpoint,
						implode( ', ', $enabled )
					)
				);
			}
		}
		if ( null !== $state && ! in_array( $state, self::SUFFIX_STATES, true ) ) {
			return new WP_Error(
				'saddle_bad_state',
				sprintf(
					/* translators: 1: key, 2: state, 3: comma-separated state names. */
					__( '"%1$s": "%2$s" is not a state a suffix can name. Use %3$s; leave the state out for the normal value.', 'saddle' ),
					$key,
					$state,
					':' . implode( ', :', self::SUFFIX_STATES )
				)
			);
		}
		return true;
	}

	/**
	 * Refuse a screen size or state the setting cannot hold.
	 *
	 * @param array  $entry  Settings entry.
	 * @param array  $parsed Parsed key.
	 * @param string $key    Key as written.
	 * @return true|WP_Error
	 */
	public static function flags( array $entry, array $parsed, $key ) {
		if ( null !== $parsed['breakpoint'] && 'desktop' !== $parsed['breakpoint'] && ! $entry['responsive'] ) {
			/* translators: %s: key as written. */
			return new WP_Error( 'saddle_not_responsive', sprintf( __( '"%s": this setting has one value for every screen size. Leave out the @ suffix.', 'saddle' ), $key ) );
		}
		if ( null !== $parsed['state'] && empty( $entry[ $parsed['state'] ] ) ) {
			/* translators: 1: key as written, 2: state. */
			return new WP_Error( 'saddle_no_state', sprintf( __( '"%1$s": this setting has no %2$s value. Leave out the : suffix.', 'saddle' ), $key, $parsed['state'] ) );
		}
		return true;
	}

	/**
	 * Walk "attrs" down to each envelope.
	 *
	 * @param string $type    Module type.
	 * @param array  $prefix  Segments above this level.
	 * @param array  $map     Keys and values at this level.
	 * @param array  $writes  Accumulator.
	 * @return true|WP_Error
	 */
	public static function walk( $type, array $prefix, array $map, array &$writes ) {
		foreach ( $map as $key => $value ) {
			$parsed = self::parse_key( (string) $key );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$segments = array_merge( $prefix, explode( '.', $parsed['base'] ) );
			if ( null !== $parsed['breakpoint'] || null !== $parsed['state'] ) {
				$write = self::suffixed( $type, $segments, $parsed, $value, (string) $key );
				if ( is_wp_error( $write ) ) {
					return $write;
				}
				$writes[] = $write;
				continue;
			}
			if ( in_array( '', $segments, true ) || array_filter( $segments, array( 'Saddle_Divi_Envelope', 'is_breakpoint' ) ) ) {
				$writes[] = array( $segments, $value ); // A raw path, exactly as before.
				continue;
			}
			$where = Saddle_Divi_Envelope::locate( $type, $segments );
			if ( ! is_array( $where ) ) {
				if ( 'partial' === $where && is_array( $value ) && $value && ! array_is_list( $value ) ) {
					$walked = self::walk( $type, $segments, $value, $writes );
					if ( is_wp_error( $walked ) ) {
						return $walked;
					}
					continue;
				}
				$writes[] = array( $segments, $value );
				continue;
			}
			if ( $where['rest'] && in_array( $where['rest'][0], Saddle_Divi_Envelope::STATES, true ) ) {
				return self::no_breakpoint( (string) $key );
			}
			if ( Saddle_Divi_Envelope::has_breakpoint_key( $value ) || array() === $value ) {
				$writes[] = array( $segments, $value );
				continue;
			}
			if ( is_array( $value ) && ! array_is_list( $value ) && self::has_suffixed_key( $value ) ) {
				// A screen size inside the value: place each key on its own.
				$walked = self::walk( $type, $segments, $value, $writes );
				if ( is_wp_error( $walked ) ) {
					return $walked;
				}
				continue;
			}
			// No breakpoint named: the desktop value.
			$writes[] = array( array_merge( $where['env'], array( 'desktop', 'value' ), $where['rest'] ), $value );
		}
		return true;
	}

	/**
	 * A key with "@<breakpoint>" or ":<state>".
	 *
	 * @param string   $type     Module type.
	 * @param string[] $segments Path without the suffix.
	 * @param array    $parsed   Parsed key.
	 * @param mixed    $value    Value.
	 * @param string   $key      Key as written.
	 * @return array|WP_Error A write.
	 */
	private static function suffixed( $type, array $segments, array $parsed, $value, $key ) {
		$ok = self::check_suffix( $parsed['breakpoint'], $parsed['state'], $key );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		foreach ( $segments as $segment ) {
			if ( Saddle_Divi_Envelope::is_breakpoint( $segment ) ) {
				return new WP_Error(
					'saddle_two_breakpoints',
					sprintf(
						/* translators: 1: key as written, 2: breakpoint in the path. */
						__( '"%1$s" names a screen size twice: "%2$s" in the path and one in the suffix. Write the path up to the group with the suffix ("module.decoration.spacing@phone"), or the full path with no suffix ("module.decoration.spacing.phone.value").', 'saddle' ),
						$key,
						$segment
					)
				);
			}
		}
		$where = Saddle_Divi_Envelope::locate( $type, $segments );
		if ( 'partial' === $where ) {
			$only  = Saddle_Divi_Envelope::only_envelope( $segments );
			$where = $only ? array(
				'env'  => $only,
				'rest' => array(),
			) : null;
			if ( ! $only && '' !== Saddle_Divi_Envelope::partial_hint( $segments ) ) {
				return new WP_Error(
					'saddle_suffix_needs_part',
					sprintf(
						/* translators: 1: key as written, 2: the parts to choose from. */
						__( '"%1$s": name the part before the suffix: %2$s.', 'saddle' ),
						$key,
						Saddle_Divi_Envelope::partial_hint( $segments )
					)
				);
			}
		}
		if ( ! is_array( $where ) ) {
			return new WP_Error(
				'saddle_suffix_unplaced',
				sprintf(
					/* translators: %s: key as written. */
					__( 'SaddleWP cannot tell where the screen size or state goes in "%s". Put the suffix on a style group path from divi-get-style-schema without its ".desktop.value" ending (e.g. "module.decoration.spacing@phone"), on a field ("title@phone") or on a setting id in "settings".', 'saddle' ),
					$key
				)
			);
		}
		if ( $where['rest'] && in_array( $where['rest'][0], Saddle_Divi_Envelope::STATES, true ) ) {
			return self::no_breakpoint( $key );
		}
		$tail = implode( '.', array_slice( $where['env'], 1 ) );
		if ( in_array( $tail, self::DESKTOP_ONLY, true ) && ( ( null !== $parsed['breakpoint'] && 'desktop' !== $parsed['breakpoint'] ) || null !== $parsed['state'] ) ) {
			/* translators: %s: key as written. */
			return new WP_Error( 'saddle_desktop_only', sprintf( __( '"%s": Divi reads this group at desktop only, with no hover or sticky value. Leave out the suffix.', 'saddle' ), $key ) );
		}
		if ( isset( $where['setting'] ) ) {
			$flags = self::flags( $where['setting'], $parsed, $key );
			if ( is_wp_error( $flags ) ) {
				return $flags;
			}
		}
		return array( array_merge( $where['env'], array( self::bp( $parsed ), self::state( $parsed ) ), $where['rest'] ), $value );
	}

	/**
	 * A path that names a state but no screen size.
	 *
	 * @param string $key Key as written.
	 * @return WP_Error
	 */
	private static function no_breakpoint( $key ) {
		return new WP_Error(
			'saddle_state_without_breakpoint',
			sprintf(
				/* translators: %s: key as written. */
				__( '"%s" names a state with no screen size before it. Write "…desktop.value.<field>", or leave out ".value" ("module.decoration.background.color") for the desktop value.', 'saddle' ),
				$key
			)
		);
	}

	/**
	 * Whether any key at this level carries a suffix.
	 *
	 * @param array $value Value.
	 * @return bool
	 */
	private static function has_suffixed_key( array $value ) {
		foreach ( array_keys( $value ) as $key ) {
			if ( false !== strpbrk( (string) $key, '@:' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The breakpoint a parsed key names, desktop when none.
	 *
	 * @param array $parsed Parsed key.
	 * @return string
	 */
	public static function bp( array $parsed ) {
		return null === $parsed['breakpoint'] ? 'desktop' : $parsed['breakpoint'];
	}

	/**
	 * The state a parsed key names, "value" when none.
	 *
	 * @param array $parsed Parsed key.
	 * @return string
	 */
	public static function state( array $parsed ) {
		return null === $parsed['state'] ? 'value' : $parsed['state'];
	}
}
