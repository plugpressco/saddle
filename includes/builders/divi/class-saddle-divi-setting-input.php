<?php
/**
 * Checks a module setting an agent writes in a node's "settings".
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns one entry of a node's "settings" ({ "post.advanced.number": 6 })
 * into the value Divi stores, or a refusal that says what would work.
 *
 * The setting id is one from Saddle_Divi_Settings::for_module(): its path,
 * its path plus ".<sub>" when it has a sub-name, or the short
 * "<attr>.<key>" when only one setting has it. Values are checked against
 * the control: a toggle stores "on" or "off"; a select takes one of its
 * choices (WordPress's own for menus, post types, categories and the like);
 * a number or range stores the string Divi keeps, unit included. Divi's
 * own defaults for these settings are strings ("10", "2000ms"), so a value
 * is stored as a string unless the setting's default is a number.
 */
class Saddle_Divi_Setting_Input {

	/**
	 * Resolve a setting id on a module.
	 *
	 * @param string $type Module type.
	 * @param string $id   Setting id as written (no suffix).
	 * @return array|WP_Error The settings entry.
	 */
	public static function resolve( $type, $id ) {
		$all = Saddle_Divi_Settings::for_module( (string) $type );
		if ( ! $all ) {
			return new WP_Error(
				'saddle_no_settings',
				sprintf(
					/* translators: %s: module type. */
					__( 'SaddleWP has no settings list for %s on this site (no module.json), so it cannot check a "settings" entry. Write the value in "attrs" at its full path instead.', 'saddle' ),
					$type
				)
			);
		}
		$entry = Saddle_Divi_Settings::find( (string) $type, (string) $id );
		if ( $entry ) {
			return $entry;
		}
		return new WP_Error(
			'saddle_unknown_setting',
			sprintf(
				/* translators: 1: setting id, 2: module type, 3: closest setting ids. */
				__( '"%1$s" is not a setting of %2$s. Closest: %3$s. divi-get-module-schema lists every setting.', 'saddle' ),
				$id,
				$type,
				implode( ', ', self::closest( $all, (string) $id ) )
			)
		);
	}

	/**
	 * The three ids nearest to what was written.
	 *
	 * @param array[] $entries Settings entries.
	 * @param string  $id      Written id.
	 * @return string[]
	 */
	public static function closest( array $entries, $id ) {
		$scores = array();
		$needle = strtolower( $id );
		foreach ( $entries as $entry ) {
			$short = strtolower( $entry['attr'] . '.' . $entry['key'] );
			$score = min( levenshtein( $needle, strtolower( $entry['id'] ) ), levenshtein( $needle, $short ) );
			// A written key that ends like a setting ("number") is a strong hint.
			$key = strtolower( $entry['key'] );
			if ( $needle === $key || str_ends_with( $needle, '.' . $key ) ) {
				$score -= 10;
			}
			$scores[ $entry['id'] ] = $score;
		}
		asort( $scores );
		return array_slice( array_keys( $scores ), 0, 3 );
	}

	/**
	 * Check a value against the setting's control and return what Divi
	 * stores.
	 *
	 * @param array  $entry Settings entry.
	 * @param mixed  $value Written value.
	 * @param string $label "<node> (<type>), setting <id>" for messages.
	 * @return mixed|WP_Error
	 */
	public static function value( array $entry, $value, $label ) {
		if ( null === $value ) {
			return self::refuse( $label, /* translators: %s: node, module type and setting id. */ __( '%s needs a value. To remove a stored setting, use "unset" on divi-edit-module. Nothing was saved.', 'saddle' ) );
		}
		switch ( $entry['control'] ) {
			case 'toggle':
				return self::toggle( $value, $label );
			case 'select':
				return self::choice( $entry, $value, $label );
			case 'number':
			case 'range':
				return self::number( $entry, $value, $label );
			case 'list':
				return self::items( $entry, $value, $label );
			case 'text':
			case 'color':
			case 'upload':
				if ( ! is_scalar( $value ) || is_bool( $value ) ) {
					return self::refuse( $label, /* translators: %s: node, module type and setting id. */ __( '%s takes a text value. Nothing was saved.', 'saddle' ) );
				}
				return (string) $value;
		}
		if ( 'object' === $entry['value_shape'] && null === $entry['sub_name'] && ! is_array( $value ) && ! ( 'icon' === $entry['control'] && is_string( $value ) ) ) {
			return new WP_Error(
				'saddle_bad_setting_value',
				sprintf(
					/* translators: 1: node, module type and setting id, 2: comma-separated field names. */
					__( '%1$s takes an object with %2$s. Nothing was saved.', 'saddle' ),
					$label,
					implode( ', ', $entry['sub_fields'] )
				)
			);
		}
		return $value;
	}

	/**
	 * A toggle: true, false, "on" or "off", stored as "on" or "off".
	 *
	 * @param mixed  $value Value.
	 * @param string $label "<node> (<type>), setting <id>".
	 * @return string|WP_Error
	 */
	private static function toggle( $value, $label ) {
		if ( is_bool( $value ) ) {
			return $value ? 'on' : 'off';
		}
		if ( is_string( $value ) && in_array( strtolower( $value ), array( 'on', 'off' ), true ) ) {
			return strtolower( $value );
		}
		return self::refuse( $label, /* translators: %s: node, module type and setting id. */ __( '%s is a switch: write true, false, "on" or "off". Nothing was saved.', 'saddle' ) );
	}

	/**
	 * A select: one of its choices, stored as a string.
	 *
	 * @param array  $entry Settings entry.
	 * @param mixed  $value Value.
	 * @param string $label "<node> (<type>), setting <id>".
	 * @return string|WP_Error
	 */
	private static function choice( array $entry, $value, $label ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return self::refuse( $label, /* translators: %s: node, module type and setting id. */ __( '%s takes one choice, written as text. Nothing was saved.', 'saddle' ) );
		}
		$value   = (string) $value;
		$options = Saddle_Divi_Settings::options( $entry );
		// No list to check against: a loop id, a choice Divi builds in the
		// browser, or an account that may not read the WordPress list.
		if ( ! $options || array_key_exists( $value, $options ) ) {
			return $value;
		}
		return self::not_a_choice( $label, $options );
	}

	/**
	 * A number or range: a number, or a number with a unit, kept as the
	 * string Divi stores. A range also takes a CSS function or a variable.
	 *
	 * @param array  $entry Settings entry.
	 * @param mixed  $value Value.
	 * @param string $label "<node> (<type>), setting <id>".
	 * @return string|int|float|WP_Error
	 */
	private static function number( array $entry, $value, $label ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return ( is_int( $entry['default'] ) || is_float( $entry['default'] ) ) ? $value : (string) $value;
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( preg_match( '/^-?(\d+\.?\d*|\.\d+)([a-z%]{1,4})?$/i', $value ) ) {
				return $value;
			}
			if ( 'range' === $entry['control'] && preg_match( '/^(calc|clamp|min|max|var)\(|^\$variable\(/i', $value ) ) {
				return $value;
			}
		}
		return self::refuse( $label, /* translators: %s: node, module type and setting id. */ __( '%s takes a number, for example 6, "6" or "24px". Nothing was saved.', 'saddle' ) );
	}

	/**
	 * A list control: a list (or a comma-separated string when it has
	 * choices), each item one of the choices when there are any.
	 *
	 * @param array  $entry Settings entry.
	 * @param mixed  $value Value.
	 * @param string $label "<node> (<type>), setting <id>".
	 * @return array|WP_Error
	 */
	private static function items( array $entry, $value, $label ) {
		$options = null === $entry['options'] && null === $entry['options_source'] ? array() : Saddle_Divi_Settings::options( $entry );
		if ( is_string( $value ) && $options ) {
			$value = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return self::refuse( $label, /* translators: %s: node, module type and setting id. */ __( '%s takes a list, for example ["12", "15"]. Nothing was saved.', 'saddle' ) );
		}
		if ( ! $options ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) || ! array_key_exists( (string) $item, $options ) ) {
				return self::not_a_choice( $label, $options );
			}
			$out[] = (string) $item;
		}
		return $out;
	}

	/**
	 * A refusal that lists the valid choices with their labels, capped.
	 *
	 * @param string               $label   "<node> (<type>), setting <id>".
	 * @param array<string,string> $options Choices.
	 * @return WP_Error
	 */
	private static function not_a_choice( $label, array $options ) {
		$shown = array();
		foreach ( array_slice( $options, 0, 20, true ) as $key => $name ) {
			$shown[] = (string) $key === (string) $name ? '"' . $key . '"' : sprintf( '"%1$s" (%2$s)', $key, $name );
		}
		return new WP_Error(
			'saddle_bad_setting_value',
			sprintf(
				/* translators: 1: node, module type and setting id, 2: comma-separated valid values. */
				__( '%1$s must be one of: %2$s. Nothing was saved.', 'saddle' ),
				$label,
				implode( ', ', $shown ) . ( count( $options ) > 20 ? ' …' : '' )
			)
		);
	}

	/**
	 * A refusal naming the node, the module and the setting.
	 *
	 * @param string $label  "<node> (<type>), setting <id>".
	 * @param string $format A whole translated sentence with one %s for the label.
	 * @return WP_Error
	 */
	private static function refuse( $label, $format ) {
		return new WP_Error( 'saddle_bad_setting_value', sprintf( $format, $label ) );
	}
}
