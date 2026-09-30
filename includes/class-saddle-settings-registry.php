<?php
/**
 * One settings schema per scope: read, validate, store, describe.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The settings registry (ADMIN-SYSTEM.md 6.3). A scope is Core (`saddle`) or a
 * module key. Its schema is data: where the values live (`store`), an optional
 * `sanitize` callable, and the `fields`. The admin form, `/preferences/{scope}`
 * and the `saddle/*-module-settings` tools all go through here, so a value is
 * validated and stored the same way whoever changes it.
 *
 * A module's callables run server-side only. One that throws, or returns the
 * wrong shape, counts as absent: it never breaks a page or a tool.
 */
class Saddle_Settings_Registry {

	/** Field types a schema may use. */
	const TYPES = array( 'boolean', 'integer', 'number', 'string', 'secret', 'custom' );

	/**
	 * Run a schema callable without letting it break the caller.
	 *
	 * @param callable $callback Callable.
	 * @param array    $args     Arguments.
	 * @return array{ok:bool,value:mixed}
	 */
	public static function safely( $callback, array $args = array() ) {
		try {
			return array(
				'ok'    => true,
				'value' => call_user_func_array( $callback, $args ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'value' => null,
			);
		}
	}

	/**
	 * Turn what a module's `settings` callable returned into a schema, or null
	 * when it is malformed or has no fields.
	 *
	 * @param mixed $raw Raw return value.
	 * @return array|null `store`, `sanitize` (callable|null), `fields` (defaults filled).
	 */
	public static function normalize( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['store'] ) || ! is_array( $raw['store'] ) || empty( $raw['fields'] ) || ! is_array( $raw['fields'] ) ) {
			return null;
		}

		$store = $raw['store'];
		if ( ! empty( $store['option'] ) && is_string( $store['option'] ) ) {
			$store = array( 'option' => $store['option'] );
		} elseif ( ! empty( $store['get'] ) && ! empty( $store['set'] ) && is_callable( $store['get'] ) && is_callable( $store['set'] ) ) {
			$store = array(
				'get'     => $store['get'],
				'set'     => $store['set'],
				'options' => isset( $store['options'] ) ? array_values( array_filter( array_map( 'strval', (array) $store['options'] ) ) ) : array(),
			);
		} else {
			return null;
		}

		$fields = array();
		foreach ( $raw['fields'] as $key => $field ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $field ) || ! isset( $field['type'] ) || ! in_array( $field['type'], self::TYPES, true ) ) {
				continue;
			}
			$fields[ $key ]                = array_merge(
				array(
					'default'     => null,
					'label'       => $key,
					'help'        => '',
					'level'       => 'basic',
					'agent'       => 'read',
					'destructive' => false,
					'screen'      => '',
					'section'     => '',
					'control'     => 'auto',
				),
				array_intersect_key( $field, array_flip( array( 'type', 'enum', 'minimum', 'maximum', 'default', 'label', 'help', 'level', 'agent', 'destructive', 'screen', 'section', 'control' ) ) )
			);
			$fields[ $key ]['level']       = 'advanced' === $fields[ $key ]['level'] ? 'advanced' : 'basic';
			$fields[ $key ]['agent']       = 'write' === $fields[ $key ]['agent'] ? 'write' : 'read';
			$fields[ $key ]['control']     = 'custom' === $fields[ $key ]['control'] ? 'custom' : 'auto';
			$fields[ $key ]['destructive'] = (bool) $fields[ $key ]['destructive'];
			$fields[ $key ]['section']     = is_string( $fields[ $key ]['section'] ) ? wp_strip_all_tags( $fields[ $key ]['section'] ) : '';
		}

		if ( ! $fields ) {
			return null;
		}

		return array(
			'store'    => $store,
			'sanitize' => isset( $raw['sanitize'] ) && is_callable( $raw['sanitize'] ) ? $raw['sanitize'] : null,
			'fields'   => $fields,
			'title'    => isset( $raw['title'] ) ? (string) $raw['title'] : '',
		);
	}

	/**
	 * The schema of a scope, or null when the scope is unknown or has none.
	 *
	 * @param string $scope `saddle` or a module key.
	 * @return array|null
	 */
	public static function schema( $scope ) {
		if ( Saddle_Core_Settings::SCOPE === $scope ) {
			return self::normalize( Saddle_Core_Settings::schema() );
		}

		$modules = Saddle_Modules::modules();
		if ( ! isset( $modules[ $scope ]['settings'] ) ) {
			return null;
		}

		$called = self::safely( $modules[ $scope ]['settings'] );
		$schema = $called['ok'] ? self::normalize( $called['value'] ) : null;
		if ( $schema ) {
			$schema['title'] = $modules[ $scope ]['title'];
		}

		return $schema;
	}

	/**
	 * The option names a scope's store writes, for the undo journal and the
	 * protected-option check. A get/set store lists them in `options`.
	 *
	 * @param array $schema Normalised schema.
	 * @return string[]
	 */
	public static function store_options( array $schema ) {
		return isset( $schema['store']['option'] ) ? array( $schema['store']['option'] ) : $schema['store']['options'];
	}

	/**
	 * Current stored values, as the store holds them (defaults not applied).
	 *
	 * @param array $schema Normalised schema.
	 * @return array
	 */
	private static function stored( array $schema ) {
		if ( isset( $schema['store']['option'] ) ) {
			$value = get_option( $schema['store']['option'], array() );
			return is_array( $value ) ? $value : array();
		}

		$called = self::safely( $schema['store']['get'] );
		return $called['ok'] && is_array( $called['value'] ) ? $called['value'] : array();
	}

	/**
	 * Every field's current value, defaults filled and typed.
	 *
	 * @param array $schema Normalised schema.
	 * @return array<string,mixed>
	 */
	public static function values( array $schema ) {
		$stored = self::stored( $schema );
		$values = array();
		foreach ( $schema['fields'] as $key => $field ) {
			$value          = array_key_exists( $key, $stored ) ? $stored[ $key ] : $field['default'];
			$values[ $key ] = self::typed( $field['type'], $value );
		}

		return $values;
	}

	/**
	 * Cast a stored value to its field's type.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Stored value.
	 * @return mixed
	 */
	private static function typed( $type, $value ) {
		switch ( $type ) {
			case 'boolean':
				return rest_sanitize_boolean( $value );
			case 'integer':
				return (int) $value;
			case 'number':
				return (float) $value;
			case 'string':
			case 'secret':
				return is_scalar( $value ) ? (string) $value : '';
		}

		return $value;
	}

	/**
	 * Validate and sanitize submitted values against their fields.
	 *
	 * @param array $schema Normalised schema.
	 * @param array $values Submitted values by field key.
	 * @return array|WP_Error The clean values, or a 400 naming the field.
	 */
	public static function validate( array $schema, array $values ) {
		$clean = array();

		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $schema['fields'][ $key ] ) ) {
				return new WP_Error(
					'saddle_unknown_field',
					/* translators: %s: setting key. */
					sprintf( __( 'There is no setting called "%s" here.', 'saddle' ), $key ),
					array(
						'status' => 400,
						'field'  => $key,
					)
				);
			}

			$field = $schema['fields'][ $key ];
			if ( 'custom' === $field['type'] ) {
				$clean[ $key ] = map_deep(
					$value,
					static function ( $item ) {
						return is_string( $item ) ? sanitize_text_field( $item ) : $item;
					}
				);
				continue;
			}

			$rules = array( 'type' => 'secret' === $field['type'] ? 'string' : $field['type'] );
			foreach ( array( 'enum', 'minimum', 'maximum' ) as $rule ) {
				if ( isset( $field[ $rule ] ) ) {
					$rules[ $rule ] = $field[ $rule ];
				}
			}

			$valid = rest_validate_value_from_schema( $value, $rules, $key );
			if ( is_wp_error( $valid ) ) {
				return new WP_Error(
					'saddle_invalid_setting',
					/* translators: 1: setting label, 2: what is wrong. */
					sprintf( __( '%1$s: %2$s', 'saddle' ), $field['label'], $valid->get_error_message() ),
					array(
						'status' => 400,
						'field'  => $key,
					)
				);
			}
			$clean[ $key ] = rest_sanitize_value_from_schema( $value, $rules, $key );
		}

		return $clean;
	}

	/**
	 * Store validated values: merged into what is stored, run through the
	 * module's own `sanitize`, then written where `store` says.
	 *
	 * An agent write also watches the protected options while it runs, and
	 * puts them back and fails if a module's `set` callable touched one.
	 *
	 * @param array $schema  Normalised schema.
	 * @param array $changes Clean values, from validate().
	 * @param bool  $guard   Whether this is an agent write.
	 * @return true|WP_Error
	 */
	public static function write( array $schema, array $changes, $guard = false ) {
		$merged = array_merge( self::stored( $schema ), $changes );
		if ( $schema['sanitize'] ) {
			$called = self::safely( $schema['sanitize'], array( $merged ) );
			$merged = $called['ok'] && is_array( $called['value'] ) ? $called['value'] : $merged;
		}

		$before = $guard ? Saddle_Settings_Guard::snapshot() : array();

		if ( isset( $schema['store']['option'] ) ) {
			update_option( $schema['store']['option'], $merged );
			$result = true;
		} else {
			$called = self::safely( $schema['store']['set'], array( array_intersect_key( $merged, $changes ) ) );
			$result = $called['ok']
				? $called['value']
				: new WP_Error( 'saddle_setting_store_failed', __( 'The setting could not be saved.', 'saddle' ), array( 'status' => 500 ) );
			$result = true === $result || is_wp_error( $result ) ? $result : true;
		}

		if ( $guard && Saddle_Settings_Guard::restore( $before ) ) {
			return new WP_Error( 'saddle_protected_option', __( 'That change would alter what connected apps are allowed to do, so it was undone. Only the site owner can change that.', 'saddle' ), array( 'status' => 403 ) );
		}

		return $result;
	}
}
