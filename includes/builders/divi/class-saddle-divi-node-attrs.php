<?php
/**
 * A node's "fields", "settings" and "attrs" as the attrs Divi stores.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plain write forms of divi-set-page, divi-add-module and
 * divi-edit-module, turned into canonical Divi 5 attribute writes.
 *
 * - fields: { "title": "Hi" } is title.innerContent.desktop.value;
 *   { "title@phone": "Hi" } is title.innerContent.phone.value.
 * - settings: { "post.advanced.number": 6 } is checked against the module's
 *   settings list and stored at post.advanced.number.desktop.value as "6".
 * - attrs: raw canonical paths keep working exactly as before. On top:
 *   a key may end in "@<breakpoint>" and/or ":hover" / ":sticky"
 *   ("module.decoration.spacing@phone": {…}), and a path that names no
 *   breakpoint means the desktop value ("module.decoration.spacing.padding
 *   .top": "32px").
 *
 * The result is a list of writes, each [path segments, value], applied in
 * order: fields, then settings, then attrs, so attrs win as they always
 * did. A path SaddleWP cannot place is refused, never stored as garbage.
 */
class Saddle_Divi_Node_Attrs {


	/**
	 * Build a node's writes.
	 *
	 * @param string $type    Module type.
	 * @param array  $node    Authoring node, or an edit-module input with
	 *                        fields, settings and attrs.
	 * @param string $address Node address, for messages.
	 * @return array|WP_Error {writes: array[], echo_fields: array, echo_attrs: array}
	 */
	public static function build( $type, array $node, $address ) {
		$out = array(
			'writes'      => array(),
			'echo_fields' => array(),
			'echo_attrs'  => array(),
		);
		foreach ( array( 'fields', 'settings', 'attrs' ) as $key ) {
			if ( isset( $node[ $key ] ) && ! is_array( $node[ $key ] ) ) {
				return self::at(
					new WP_Error(
						'saddle_bad_node',
						/* translators: %s: node key. */
						sprintf( __( '"%s" must be an object.', 'saddle' ), $key )
					),
					$type,
					$address
				);
			}
		}

		foreach ( isset( $node['fields'] ) ? $node['fields'] : array() as $name => $value ) {
			$parsed = Saddle_Divi_Attr_Keys::parse_key( (string) $name );
			if ( is_wp_error( $parsed ) ) {
				return self::at( $parsed, $type, $address );
			}
			if ( false !== strpos( $parsed['base'], '.' ) ) {
				return self::at(
					new WP_Error(
						'saddle_bad_field',
						sprintf(
							/* translators: %s: field key as written. */
							__( '"fields" takes attribute names, not paths: "%s". For one part of a composite field write the whole object, e.g. fields.button = {"text": "…", "linkUrl": "…"}.', 'saddle' ),
							(string) $name
						)
					),
					$type,
					$address
				);
			}
			if ( null === $parsed['breakpoint'] && null === $parsed['state'] ) {
				$out['writes'][]                       = array( array( $parsed['base'], 'innerContent', 'desktop', 'value' ), $value );
				$out['echo_fields'][ $parsed['base'] ] = $value;
				continue;
			}
			$ok = Saddle_Divi_Attr_Keys::check_suffix( $parsed['breakpoint'], $parsed['state'], (string) $name );
			if ( is_wp_error( $ok ) ) {
				return self::at( $ok, $type, $address );
			}
			$segments                                       = array( $parsed['base'], 'innerContent', Saddle_Divi_Attr_Keys::bp( $parsed ), Saddle_Divi_Attr_Keys::state( $parsed ) );
			$out['writes'][]                                = array( $segments, $value );
			$out['echo_attrs'][ implode( '.', $segments ) ] = $value;
		}

		foreach ( isset( $node['settings'] ) ? $node['settings'] : array() as $id => $value ) {
			$write = self::setting( $type, (string) $id, $value, $address );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
			$out['writes'][] = $write;
		}

		$writes = array();
		$walked = Saddle_Divi_Attr_Keys::walk( $type, array(), isset( $node['attrs'] ) ? $node['attrs'] : array(), $writes );
		if ( is_wp_error( $walked ) ) {
			return self::at( $walked, $type, $address );
		}
		foreach ( $writes as $write ) {
			$out['writes'][]                                = $write;
			$out['echo_attrs'][ implode( '.', $write[0] ) ] = $write[1];
		}
		return $out;
	}

	/**
	 * Apply writes to attrs the way the author always did (deep replace,
	 * dotted keys inside values expanded).
	 *
	 * @param array   $attrs  Attrs.
	 * @param array[] $writes Writes from build().
	 * @return array
	 */
	public static function apply( array $attrs, array $writes ) {
		foreach ( $writes as $write ) {
			$attrs = array_replace_recursive( $attrs, Saddle_Divi_Author::expand_dotted_keys( self::nest( $write[0], $write[1] ) ) );
		}
		return $attrs;
	}

	/**
	 * One write as a nested array.
	 *
	 * @param string[] $segments Path.
	 * @param mixed    $value    Value.
	 * @return array
	 */
	public static function nest( array $segments, $value ) {
		for ( $i = count( $segments ) - 1; $i >= 0; $i-- ) {
			$value = array( $segments[ $i ] => $value );
		}
		return $value;
	}

	/**
	 * One "settings" entry: resolved, checked, placed.
	 *
	 * @param string $type    Module type.
	 * @param string $id      Setting id as written, with any suffix.
	 * @param mixed  $value   Value.
	 * @param string $address Node address.
	 * @return array|WP_Error A write.
	 */
	private static function setting( $type, $id, $value, $address ) {
		$parsed = Saddle_Divi_Attr_Keys::parse_key( $id );
		if ( is_wp_error( $parsed ) ) {
			return self::at( $parsed, $type, $address );
		}
		$entry = Saddle_Divi_Setting_Input::resolve( $type, $parsed['base'] );
		if ( is_wp_error( $entry ) ) {
			return self::at( $entry, $type, $address );
		}
		$ok = Saddle_Divi_Attr_Keys::check_suffix( $parsed['breakpoint'], $parsed['state'], $id );
		if ( ! is_wp_error( $ok ) ) {
			$ok = Saddle_Divi_Attr_Keys::flags( $entry, $parsed, $id );
		}
		if ( is_wp_error( $ok ) ) {
			return self::at( $ok, $type, $address );
		}
		$label = sprintf(
			/* translators: 1: node address, 2: module type, 3: setting id. */
			__( 'Node %1$s (%2$s), setting "%3$s",', 'saddle' ),
			'' === (string) $address ? '0' : $address,
			$type,
			$entry['id']
		);
		$checked = Saddle_Divi_Setting_Input::value( $entry, $value, rtrim( $label, ',' ) );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$segments = array_merge( explode( '.', $entry['path'] ), array( Saddle_Divi_Attr_Keys::bp( $parsed ), Saddle_Divi_Attr_Keys::state( $parsed ) ) );
		if ( null !== $entry['sub_name'] ) {
			$segments[] = $entry['sub_name'];
		}
		return array( $segments, $checked );
	}

	/**
	 * Prefix an error with the node it came from.
	 *
	 * @param WP_Error $error   Error.
	 * @param string   $type    Module type.
	 * @param string   $address Node address.
	 * @return WP_Error
	 */
	private static function at( WP_Error $error, $type, $address ) {
		return new WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: 1: node address, 2: module type, 3: what is wrong. */
				__( 'Node %1$s (%2$s): %3$s Nothing was saved.', 'saddle' ),
				'' === (string) $address ? '0' : $address,
				$type,
				$error->get_error_message()
			),
			array( 'status' => 400 )
		);
	}
}
