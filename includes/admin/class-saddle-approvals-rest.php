<?php
/**
 * REST routes for "Needs your OK": the requests an AI app has previewed that
 * the owner can approve or reject from the Dashboard.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * `GET saddle/v1/approvals` and `POST saddle/v1/approvals/{id}`.
 */
class Saddle_Approvals_REST {

	/**
	 * Register the routes. Both are `manage_options`, like every admin route.
	 */
	public static function register_routes() {
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/approvals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_approvals' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/approvals/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'decide' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
				'args'                => array(
					'decision' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'approve', 'reject' ),
					),
				),
			)
		);
	}

	/**
	 * GET /approvals — pending, unexpired, newest first.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_approvals() {
		return new WP_REST_Response( array( 'approvals' => Saddle_Approval::pending() ), 200 );
	}

	/**
	 * POST /approvals/{id} — approve or reject.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function decide( WP_REST_Request $request ) {
		$result = Saddle_Approval::decide( (int) $request['id'], (string) $request['decision'] );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}
}
