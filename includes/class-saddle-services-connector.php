<?php
/**
 * Unsplash in WordPress Connectors (WordPress 7.0+), mirrored on the same
 * option Saddle already uses, so there is no key to migrate.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `unsplash` connector when core has the registry, never by
 * version number. On WordPress 6.9 `wp_connectors_init` does not fire and
 * nothing here runs; Saddle's own form keeps working.
 */
class Saddle_Services_Connector {

	const ID = 'unsplash';

	/**
	 * Whether this site's core has the Connectors registry.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'WP_Connector_Registry' );
	}

	/**
	 * Hook it up. Called once from plugin boot.
	 */
	public static function register_hooks() {
		add_action( 'wp_connectors_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the connector, unless something else already owns the id.
	 *
	 * Registers the setting first: core skips a setting that is already
	 * registered, and ours rejects a malformed key on core's screen too.
	 *
	 * @param object $registry The WP_Connector_Registry.
	 */
	public static function register( $registry ) {
		if ( ! self::available() || ! is_object( $registry ) || $registry->is_registered( self::ID ) ) {
			return;
		}

		register_setting(
			'connectors',
			Saddle_Unsplash::OPTION,
			array(
				'type'              => 'string',
				'label'             => __( 'Unsplash API Key', 'saddle' ),
				'description'       => __( 'API key for the Unsplash connector.', 'saddle' ),
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);

		// A key first saved from core's screen would otherwise be autoloaded.
		add_filter( 'wp_default_autoload_value', array( __CLASS__, 'autoload' ), 10, 2 );

		$registry->register(
			self::ID,
			array(
				'name'           => 'Unsplash',
				'description'    => __( 'Stock photos for your AI apps, through Saddle.', 'saddle' ),
				'type'           => 'stock_photos',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://unsplash.com/developers',
					'setting_name'    => Saddle_Unsplash::OPTION,
				),
			)
		);
	}

	/**
	 * Saddle's rule for a key. A malformed one keeps the old value, which is
	 * how core's screen learns the key was refused.
	 *
	 * @param mixed $value The submitted key.
	 * @return string
	 */
	public static function sanitize( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || preg_match( Saddle_Unsplash::KEY_PATTERN, $value ) ) {
			return $value;
		}

		return (string) get_option( Saddle_Unsplash::OPTION, '' );
	}

	/**
	 * Keep the key out of autoload.
	 *
	 * @param bool|null $autoload Default autoload value.
	 * @param string    $option   Option name.
	 * @return bool|null
	 */
	public static function autoload( $autoload, $option ) {
		return Saddle_Unsplash::OPTION === $option ? false : $autoload;
	}

	/**
	 * The connector id when Saddle's own registration is the one in core, else
	 * null (core is older, or another plugin owns the id and its own key).
	 *
	 * @return string|null
	 */
	public static function connector_id() {
		if ( ! self::available() || ! function_exists( 'wp_get_connector' ) ) {
			return null;
		}

		$connector = wp_get_connector( self::ID );

		return is_array( $connector ) && isset( $connector['authentication']['setting_name'] ) && Saddle_Unsplash::OPTION === $connector['authentication']['setting_name']
			? self::ID
			: null;
	}
}
