<?php
/**
 * The hard rule: no tool changes what an agent may do.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps agent-written settings away from the options that decide what an
 * agent may do. The rule lives here and nowhere else, so a module cannot
 * talk its way around it: `saddle/update-module-settings` refuses Core's own
 * fields, refuses a schema stored in one of these options, and watches them
 * while a module's own `set` callable runs.
 */
class Saddle_Settings_Guard {

	/**
	 * Options that decide what an agent may do, besides every saddle_oauth_*.
	 */
	const PROTECTED_OPTIONS = array(
		'saddle_access_tier',
		'saddle_key_roles',
		'saddle_access_version',
		'saddle_paused',
		'saddle_rehearsal',
		'saddle_drafts_only',
		'saddle_enabled_integrations',
		'saddle_disabled_abilities',
		'saddle_tier_domain',
		'saddle_enforce_tier_domain',
	);

	/**
	 * Whether an option name is one no agent tool may write.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_protected_option( $name ) {
		return in_array( $name, self::PROTECTED_OPTIONS, true ) || 0 === strpos( (string) $name, 'saddle_oauth_' );
	}

	/**
	 * Whether an agent tool may write a field: never Core's, never one stored
	 * in a protected option, and only where the module said `agent: write`.
	 *
	 * @param string $scope  Scope.
	 * @param array  $schema Normalised schema.
	 * @param string $key    Field key.
	 * @return bool
	 */
	public static function agent_can_write( $scope, array $schema, $key ) {
		if ( Saddle_Core_Settings::SCOPE === $scope || ! isset( $schema['fields'][ $key ] ) || 'write' !== $schema['fields'][ $key ]['agent'] ) {
			return false;
		}

		return ! array_filter( Saddle_Settings_Registry::store_options( $schema ), array( __CLASS__, 'is_protected_option' ) );
	}

	/**
	 * Keys of the fields an agent may write.
	 *
	 * @param string $scope  Scope.
	 * @param array  $schema Normalised schema.
	 * @return string[]
	 */
	public static function agent_writable( $scope, array $schema ) {
		return array_values(
			array_filter(
				array_keys( $schema['fields'] ),
				static function ( $key ) use ( $scope, $schema ) {
					return self::agent_can_write( $scope, $schema, $key );
				}
			)
		);
	}

	/**
	 * The protected options' values right now.
	 *
	 * @return array<string,mixed> Name to value; a missing option is the `''` entry's object.
	 */
	public static function snapshot() {
		$names = array_merge(
			self::PROTECTED_OPTIONS,
			array( Saddle_OAuth::ENABLED_OPTION, Saddle_OAuth_Clients::DCR_OPTION, Saddle_OAuth_Clients::CIMD_OPTION ),
			array_filter(
				array_keys( wp_load_alloptions() ),
				static function ( $name ) {
					return 0 === strpos( $name, 'saddle_oauth_' );
				}
			)
		);

		$missing  = new stdClass();
		$snapshot = array( '' => $missing );
		foreach ( array_unique( $names ) as $name ) {
			$snapshot[ $name ] = get_option( $name, $missing );
		}

		return $snapshot;
	}

	/**
	 * Put back any protected option that changed since the snapshot.
	 *
	 * @param array $snapshot From snapshot().
	 * @return bool Whether anything had to be put back.
	 */
	public static function restore( array $snapshot ) {
		$missing  = $snapshot[''];
		$restored = false;
		unset( $snapshot[''] );

		foreach ( $snapshot as $name => $value ) {
			if ( get_option( $name, $missing ) === $value ) {
				continue;
			}
			$restored = true;
			if ( $value === $missing ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}

		return $restored;
	}
}
