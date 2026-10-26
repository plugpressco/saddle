<?php
/**
 * Insight in wp-admin: the two REST routes the Overview card, Settings and
 * the deactivation dialog call, the dialog on the Plugins screen, and the
 * weekly update's hook. Saddle_Insight holds the rules.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * `POST saddle/v1/insight` and `POST saddle/v1/insight/deactivate`, and the
 * Plugins screen's dialog.
 */
class Saddle_Insight_Admin {

	/**
	 * The dialog's script handle.
	 */
	const HANDLE = 'saddle-insight-deactivate';

	/**
	 * Hook everything up.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_dialog' ) );
		add_action( 'admin_init', array( 'Saddle_Insight', 'schedule' ) );
		add_action( Saddle_Insight::HOOK, array( 'Saddle_Insight', 'weekly' ) );
	}

	/**
	 * Register the routes.
	 */
	public static function register_routes() {
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/insight',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'answer' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
				'args'                => array(
					'choice' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'allow', 'skip', 'off', 'news' ),
					),
					'parts'  => array(
						'type'                 => 'object',
						'required'             => false,
						'properties'           => array(
							'site'    => array( 'type' => 'boolean' ),
							'plugins' => array( 'type' => 'boolean' ),
							'profile' => array( 'type' => 'boolean' ),
						),
						'additionalProperties' => false,
					),
					'email'  => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/insight/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'deactivate' ),
				'permission_callback' => array( __CLASS__, 'can_deactivate' ),
				'args'                => array(
					'plugin'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'reason'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Saddle_Insight::REASONS,
					),
					'comment' => array(
						'type'      => 'string',
						'required'  => false,
						'maxLength' => 2000,
					),
					'email'   => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Whether this user may turn plugins off, and so answer the dialog.
	 *
	 * @return bool
	 */
	public static function can_deactivate() {
		return current_user_can( 'activate_plugins' );
	}

	/**
	 * POST /insight: the card's answer, the Settings switch, or Saddle news.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function answer( WP_REST_Request $request ) {
		$choice = $request->get_param( 'choice' );
		$email  = (string) $request->get_param( 'email' );
		if ( 'news' === $choice ) {
			$body         = Saddle_Insight::for_app();
			$body['news'] = Saddle_Insight::subscribe( $email );
			return new WP_REST_Response( $body, 200 );
		}
		$parts = $request->get_param( 'parts' );
		$body  = Saddle_Insight::answer( $choice, is_array( $parts ) ? $parts : array(), $email );
		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * POST /insight/deactivate: "Send and deactivate". The plugin is turned
	 * off by WordPress's own link afterwards, whatever this answers.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function deactivate( WP_REST_Request $request ) {
		$sent = Saddle_Insight::deactivation(
			(string) $request->get_param( 'plugin' ),
			(string) $request->get_param( 'reason' ),
			(string) $request->get_param( 'comment' ),
			(string) $request->get_param( 'email' )
		);
		return new WP_REST_Response( array( 'sent' => $sent ), 200 );
	}

	/**
	 * The dialog, on the site's own Plugins screen only, for people who may
	 * turn plugins off. Not on the network screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_dialog( $hook_suffix ) {
		if ( 'plugins.php' !== $hook_suffix || is_network_admin() || ! self::can_deactivate() || ! Saddle_Insight::enabled() ) {
			return;
		}
		$asset_php = SADDLE_DIR . 'admin/build/insight-deactivate.asset.php';
		if ( ! file_exists( $asset_php ) ) {
			return;
		}
		$asset = require $asset_php;
		wp_enqueue_script(
			self::HANDLE,
			SADDLE_URL . 'admin/build/insight-deactivate.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		if ( file_exists( SADDLE_DIR . 'admin/build/insight-deactivate.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				SADDLE_URL . 'admin/build/insight-deactivate.css',
				array(),
				$asset['version'] . '.' . filemtime( SADDLE_DIR . 'admin/build/insight-deactivate.css' )
			);
		}
		wp_set_script_translations( self::HANDLE, 'saddle' );

		$plugins = array();
		foreach ( Saddle_Insight_Payload::products() as $product ) {
			if ( $product['active'] ) {
				$plugins[ $product['file'] ] = $product['name'];
			}
		}
		wp_add_inline_script(
			self::HANDLE,
			'window.saddleInsight = ' . wp_json_encode(
				array(
					'path'    => '/' . Saddle_REST_Admin::REST_NAMESPACE . '/insight/deactivate',
					'plugins' => $plugins,
				)
			) . ';',
			'before'
		);
	}
}
