<?php
/**
 * REST routes for the onboarding state.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * `GET saddle/v1/onboarding` and `POST saddle/v1/onboarding`.
 *
 * The first is the state; the second takes one event at a time and returns
 * the state again. No event changes the access tier.
 */
class Saddle_Onboarding_REST {

	/**
	 * Register the routes. Both are `manage_options`, like every admin route.
	 */
	public static function register_routes() {
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/onboarding',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_state' ),
					'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post_event' ),
					'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
					'args'                => array(
						'event'       => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'first_run.step', 'first_run.skip', 'first_run.done', 'tour.done', 'intro.seen', 'setup.hide' ),
						),
						'step'        => array(
							'type'     => 'string',
							'required' => false,
						),
						'app'         => array(
							'type'     => 'string',
							'required' => false,
						),
						'tier_choice' => array(
							'type'     => 'string',
							'required' => false,
						),
						'module'      => array(
							'type'     => 'string',
							'required' => false,
						),
						'version'     => array(
							'type'     => 'string',
							'required' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /onboarding.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_state() {
		return new WP_REST_Response( Saddle_Onboarding::body(), 200 );
	}

	/**
	 * POST /onboarding — one event.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_event( WP_REST_Request $request ) {
		$applied = Saddle_Onboarding::apply(
			array(
				'event'       => $request->get_param( 'event' ),
				'step'        => $request->get_param( 'step' ),
				'app'         => $request->get_param( 'app' ),
				'tier_choice' => $request->get_param( 'tier_choice' ),
				'module'      => $request->get_param( 'module' ),
				'version'     => $request->get_param( 'version' ),
			)
		);

		return is_wp_error( $applied ) ? $applied : self::get_state();
	}
}
