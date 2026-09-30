<?php
/**
 * How a settings scope is told to the admin and to agents.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The read side of the settings registry: the body `GET /preferences/{scope}`
 * and `saddle/get-module-settings` return, with secrets redacted and every
 * field carrying the page that draws it.
 */
class Saddle_Settings_View {

	/**
	 * A secret, told without being said: whether one is set and its last four.
	 *
	 * @param string $value The secret.
	 * @return array{configured:bool,hint:string}
	 */
	public static function redact( $value ) {
		$value = (string) $value;

		return array(
			'configured' => '' !== $value,
			'hint'       => '' === $value ? '' : '····' . ( strlen( $value ) > 8 ? substr( $value, -4 ) : '' ),
		);
	}

	/**
	 * The page and tab that draw a field, anchored on the field.
	 *
	 * @param string $scope Scope.
	 * @param string $key   Field key.
	 * @param array  $field Normalised field.
	 * @return string
	 */
	public static function admin_url( $scope, $key, array $field ) {
		$screen = '' !== $field['screen'] ? $field['screen'] : $scope . '/settings';
		$parts  = explode( '/', $screen, 2 );

		return Saddle_Modules::url( $parts[0], isset( $parts[1] ) ? $parts[1] : '' ) . '#saddle-field-' . $scope . '-' . $key;
	}

	/**
	 * The body `GET /preferences/{scope}` and `get-module-settings` return.
	 *
	 * @param string $scope  Scope.
	 * @param array  $schema Normalised schema.
	 * @return array
	 */
	public static function describe( $scope, array $schema ) {
		$values = Saddle_Settings_Registry::values( $schema );
		$fields = array();

		foreach ( $schema['fields'] as $key => $field ) {
			$row = array( 'key' => $key ) + array_intersect_key( $field, array_flip( array( 'type', 'enum', 'minimum', 'maximum' ) ) ) + array(
				'default'     => $field['default'],
				'label'       => $field['label'],
				'help'        => $field['help'],
				'level'       => $field['level'],
				'agent'       => $field['agent'],
				'destructive' => $field['destructive'],
				'screen'      => '' !== $field['screen'] ? $field['screen'] : $scope . '/settings',
				'section'     => $field['section'],
				'control'     => $field['control'],
				'admin_url'   => self::admin_url( $scope, $key, $field ),
				'value'       => 'secret' === $field['type'] ? self::redact( $values[ $key ] ) : $values[ $key ],
			);
			if ( 'secret' === $field['type'] ) {
				$row['default'] = self::redact( '' );
			}
			$fields[] = $row;
		}

		return array(
			'scope'  => $scope,
			'title'  => '' !== $schema['title'] ? $schema['title'] : $scope,
			'fields' => $fields,
		);
	}
}
