<?php
/**
 * REST routes for the Services page: the list, an account's key, and a
 * third-party add-on's switch.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * `GET saddle/v1/services`, `POST …/services/{key}/key` and `…/enabled`.
 * Every route is `manage_options`. No response carries a key.
 */
class Saddle_Services_REST {

	/**
	 * Register the routes.
	 */
	public static function register_routes() {
		$can = array( 'Saddle_REST_Admin', 'can_manage' );
		$key = '/services/(?P<key>[a-z0-9_-]+)';

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/services',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_services' ),
				'permission_callback' => $can,
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			$key . '/key',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_key' ),
				'permission_callback' => $can,
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			$key . '/enabled',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_enabled' ),
				'permission_callback' => $can,
				'args'                => array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /services.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_services() {
		return new WP_REST_Response( array( 'services' => array_map( array( __CLASS__, 'public_record' ), Saddle_Services::all() ) ), 200 );
	}

	/**
	 * POST /services/{key}/key — save, replace or (empty string) remove an
	 * account's key. Returns the updated record.
	 *
	 * @param WP_REST_Request $request Request with `key`.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_key( WP_REST_Request $request ) {
		$record = Saddle_Services::get( (string) $request->get_param( 'key' ) );
		if ( ! $record ) {
			return self::not_found();
		}

		if ( 'account' !== $record['kind'] ) {
			return new WP_Error( 'saddle_service_not_account', __( 'Only an outside account has a key.', 'saddle' ), array( 'status' => 400 ) );
		}

		// Unsplash is the one account Saddle holds a key for; a plugin that
		// adds an account keeps its own key form.
		if ( 'unsplash' !== $record['key'] ) {
			return new WP_Error( 'saddle_service_key_elsewhere', __( 'This service keeps its key in its own plugin.', 'saddle' ), array( 'status' => 400 ) );
		}

		$saved = Saddle_Unsplash::set_key( (string) $request->get_param( 'key' ) );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'saddle_invalid_key', $saved->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( self::public_record( Saddle_Services::get( $record['key'] ) ), 200 );
	}

	/**
	 * POST /services/{key}/enabled — approve or switch off a third-party
	 * add-on, through the same code and activity log as /integrations.
	 *
	 * @param WP_REST_Request $request Request with `enabled`.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_enabled( WP_REST_Request $request ) {
		$record = Saddle_Services::get( (string) $request->get_param( 'key' ) );
		if ( ! $record ) {
			return self::not_found();
		}

		if ( empty( $record['can_toggle'] ) ) {
			return new WP_Error( 'saddle_service_not_toggleable', __( 'Only a plugin from another developer has a switch.', 'saddle' ), array( 'status' => 400 ) );
		}

		$done = Saddle_REST_Admin::switch_integration( $record['key'], (bool) $request->get_param( 'enabled' ) );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		return new WP_REST_Response( self::public_record( Saddle_Services::get( $record['key'] ) ), 200 );
	}

	/**
	 * A record as the browser gets it: without the agent's one-line wording.
	 *
	 * @param array|null $record A record.
	 * @return array|null
	 */
	private static function public_record( $record ) {
		if ( is_array( $record ) ) {
			unset( $record['agent'] );
		}

		return $record;
	}

	/**
	 * The 404 for an unknown service.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'saddle_service_not_found', __( 'No service with that key.', 'saddle' ), array( 'status' => 404 ) );
	}
}
