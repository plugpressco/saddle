<?php
/**
 * REST routes for what each tool may do: Allow, Ask first or Off (#364).
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * `POST saddle/v1/tool-modes` and `POST saddle/v1/tool-modes/recommended`.
 * Both are `manage_options`, like every admin route, and no Saddle key can
 * reach them (`Saddle_Connection::scope_credentials()`).
 */
class Saddle_Tool_Modes_REST {

	/**
	 * Register the routes.
	 */
	public static function register_routes() {
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/tool-modes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_mode' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
				'args'                => array(
					'tool' => array(
						'type'     => 'string',
						'required' => true,
					),
					'mode' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( Saddle_Tool_Modes::ALLOW, Saddle_Tool_Modes::ASK, Saddle_Tool_Modes::OFF ),
					),
				),
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/tool-modes/recommended',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'apply_recommended' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
			)
		);
	}

	/**
	 * POST /tool-modes — set one tool's mode.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_mode( WP_REST_Request $request ) {
		$tool = (string) $request['tool'];
		$mode = Saddle_Tool_Modes::set_mode( $tool, (string) $request['mode'] );
		if ( is_wp_error( $mode ) ) {
			return $mode;
		}

		return new WP_REST_Response(
			array(
				'tool' => sanitize_key( $tool ),
				'mode' => $mode,
			),
			200
		);
	}

	/**
	 * POST /tool-modes/recommended — apply the recommended set. It only
	 * tightens, so applying it twice changes nothing the second time.
	 *
	 * @return WP_REST_Response
	 */
	public static function apply_recommended() {
		$moved = Saddle_Tool_Modes::apply_recommended();

		if ( class_exists( 'Saddle_Log' ) ) {
			Saddle_Log::record(
				array(
					'action'  => 'tool-modes-recommended',
					'target'  => '',
					'summary' => sprintf(
						/* translators: %d: number of tools. */
						_n(
							'You applied the recommended set: %d tool now asks you first, and publishing needs your OK.',
							'You applied the recommended set: %d tools now ask you first, and publishing needs your OK.',
							count( $moved ),
							'saddle'
						),
						count( $moved )
					),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'moved'       => $moved,
				'drafts_only' => Saddle_Capabilities::is_drafts_only(),
			),
			200
		);
	}
}
