<?php
/**
 * The admin-side doors to notices: the dismiss route and the Plugins screen.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kept apart from Saddle_Notices (the list and its rules) so that class stays
 * free of request handling.
 *
 * The app dismisses through `POST saddle/v1/notices/{id}/dismiss`. The Plugins
 * screen has no app and loads no script or style, so its one notice is drawn
 * in PHP and dismissed with a nonce'd admin-post.php link that redirects back.
 */
class Saddle_Notices_Admin {

	/** The admin-post action that dismisses a Plugins-screen notice. */
	const ACTION = 'saddle_dismiss_notice';

	/**
	 * Hook the route, the Plugins-screen notice and its dismiss handler.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_plugins_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * `POST saddle/v1/notices/{id}/dismiss`.
	 */
	public static function register_routes() {
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/notices/(?P<id>[A-Za-z0-9_.-]+)/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_dismiss' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
			)
		);
	}

	/**
	 * Dismiss one notice.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_Error
	 */
	public static function rest_dismiss( $request ) {
		$id     = (string) $request['id'];
		$result = Saddle_Notices::dismiss( $id );

		return is_wp_error( $result ) ? $result : array(
			'dismissed' => true,
			'id'        => $id,
		);
	}

	/**
	 * Draw the Plugins-screen notices, to administrators, on that screen only.
	 */
	public static function render_plugins_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( Saddle_Notices::for_screen( 'plugins' ) as $notice ) {
			$links = array();
			if ( $notice['action'] ) {
				$links[] = '<a href="' . esc_url( $notice['action']['url'] ) . '">' . esc_html( $notice['action']['label'] ) . '</a>';
			}
			if ( $notice['dismiss'] ) {
				$url     = wp_nonce_url(
					add_query_arg(
						array(
							'action' => self::ACTION,
							'notice' => $notice['id'],
						),
						admin_url( 'admin-post.php' )
					),
					self::ACTION . '_' . $notice['id']
				);
				$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'saddle' ) . '</a>';
			}

			printf(
				'<div class="notice notice-%1$s"><p>%2$s %3$s</p></div>',
				esc_attr( 'error' === $notice['severity'] ? 'error' : $notice['severity'] ),
				esc_html( $notice['message'] ),
				implode( ' | ', $links ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped above.
			);
		}
	}

	/**
	 * Dismiss from the Plugins screen, then go back where the owner was.
	 */
	public static function handle_dismiss() {
		wp_safe_redirect( self::dismiss_and_url() );
		exit;
	}

	/**
	 * Check the request, dismiss, and say where to send the owner.
	 *
	 * @return string The address to redirect to.
	 */
	public static function dismiss_and_url() {
		$id = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		check_admin_referer( self::ACTION . '_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You need to be an administrator to do that.', 'saddle' ), '', array( 'response' => 403 ) );
		}

		Saddle_Notices::dismiss( $id );

		$back = wp_get_referer();

		return $back ? $back : admin_url( 'plugins.php' );
	}
}
