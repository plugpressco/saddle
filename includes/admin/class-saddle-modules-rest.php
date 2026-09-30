<?php
/**
 * REST routes for the module registry and the settings schema.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * `GET /modules`, `GET /modules/{key}` and `GET|POST /preferences/{scope}`.
 *
 * The plan called the last `/settings/{scope}`. It is `/preferences/{scope}`
 * because some host firewalls answer any REST path with a `settings` segment
 * themselves (see `Saddle_REST_Admin::register_routes`). `/preferences` with
 * no scope keeps its own handler there: a scope is a separate route and the
 * two patterns never overlap.
 */
class Saddle_Modules_REST {

	/**
	 * Register the routes. All are `manage_options`, like every admin route.
	 */
	public static function register_routes() {
		$can = array( 'Saddle_REST_Admin', 'can_manage' );

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/modules',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_modules' ),
				'permission_callback' => $can,
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/modules/(?P<key>[a-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_module' ),
				'permission_callback' => $can,
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/preferences/(?P<scope>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_scope' ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_scope' ),
					'permission_callback' => $can,
				),
			)
		);
	}

	/**
	 * GET /modules.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_modules() {
		return new WP_REST_Response( array( 'areas' => Saddle_Modules_View::all() ), 200 );
	}

	/**
	 * GET /modules/{key}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_module( WP_REST_Request $request ) {
		$area = Saddle_Modules_View::one( (string) $request['key'] );
		if ( ! $area ) {
			return new WP_Error( 'saddle_unknown_module', __( 'There is no such page or module.', 'saddle' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $area, 200 );
	}

	/**
	 * GET /preferences/{scope}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_scope( WP_REST_Request $request ) {
		$scope  = (string) $request['scope'];
		$schema = Saddle_Settings_Registry::schema( $scope );
		if ( ! $schema ) {
			return self::unknown_scope();
		}

		return new WP_REST_Response( Saddle_Settings_View::describe( $scope, $schema ), 200 );
	}

	/**
	 * POST /preferences/{scope}: `{ "values": { key: value } }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_scope( WP_REST_Request $request ) {
		$scope  = (string) $request['scope'];
		$schema = Saddle_Settings_Registry::schema( $scope );
		if ( ! $schema ) {
			return self::unknown_scope();
		}

		$json   = $request->get_json_params();
		$params = is_array( $json ) ? $json : (array) $request->get_body_params();
		$values = isset( $params['values'] ) && is_array( $params['values'] ) ? $params['values'] : null;
		if ( null === $values ) {
			return new WP_Error( 'saddle_missing_values', __( 'Send "values": an object of setting keys and their new values.', 'saddle' ), array( 'status' => 400 ) );
		}

		$clean = Saddle_Settings_Registry::validate( $schema, $values );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$saved = Saddle_Settings_Registry::write( $schema, $clean );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response( Saddle_Settings_View::describe( $scope, $schema ), 200 );
	}

	/**
	 * The 404 for a scope with no settings.
	 *
	 * @return WP_Error
	 */
	private static function unknown_scope() {
		return new WP_Error( 'saddle_unknown_module', __( 'There are no settings under that name.', 'saddle' ), array( 'status' => 404 ) );
	}
}
