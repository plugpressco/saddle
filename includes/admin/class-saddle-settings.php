<?php
/**
 * Admin menu page that mounts the React app.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level "Saddle" admin page (admin.php?page=saddle) and
 * enqueues the built React assets onto it.
 */
class Saddle_Settings {

	/**
	 * Admin page slug. The connect flow redirects to admin.php?page=saddle, so
	 * this must be a top-level menu page.
	 */
	const PAGE_SLUG = 'saddle';

	/**
	 * Register the admin menu page.
	 */
	public static function register_menu() {
		$hook = add_menu_page(
			__( 'Saddle', 'saddle' ),
			__( 'Saddle', 'saddle' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-rest-api',
			81
		);

		// Remember the hook suffix so enqueue only fires on our page.
		self::$hook_suffix = $hook;
	}

	/**
	 * Stored hook suffix for the admin page.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Render the mount point for the React app.
	 */
	public static function render_page() {
		echo '<div class="wrap"><div id="saddle-root"></div></div>';
	}

	/**
	 * Enqueue the built React bundle on the Saddle page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		$build_dir = SADDLE_DIR . 'admin/build/';
		$build_url = SADDLE_URL . 'admin/build/';
		$asset_php = $build_dir . 'index.asset.php';

		// The build emits index.asset.php with the exact dependency array and a
		// content hash. Fall back to a hand-maintained list if the build hasn't
		// run yet, so a missing build degrades to "unstyled" rather than fatal.
		if ( file_exists( $asset_php ) ) {
			$asset = require $asset_php;
		} else {
			$asset = array(
				'dependencies' => array( 'react', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				'version'      => SADDLE_VERSION,
			);
		}

		$script_path = $build_dir . 'index.js';
		if ( ! file_exists( $script_path ) ) {
			// No build at all — show a helpful note instead of a blank page.
			add_action(
				'admin_notices',
				function () {
					if ( get_current_screen() && false !== strpos( get_current_screen()->id, self::PAGE_SLUG ) ) {
						echo '<div class="notice notice-warning"><p>';
						echo esc_html__( 'Saddle: the admin UI has not been built yet. Run "npm install && npm run build" in the plugin folder.', 'saddle' );
						echo '</p></div>';
					}
				}
			);
			return;
		}

		wp_enqueue_script(
			'saddle-admin',
			$build_url . 'index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// wp-scripts emits the stylesheet imported from index.js as
		// "style-index.css" (the convention for a source file named style.scss).
		// Fall back to "index.css" in case the build convention changes.
		$style_file = file_exists( $build_dir . 'style-index.css' ) ? 'style-index.css' : 'index.css';
		if ( file_exists( $build_dir . $style_file ) ) {
			wp_enqueue_style(
				'saddle-admin',
				$build_url . $style_file,
				array( 'wp-components' ),
				$asset['version']
			);
		}

		wp_set_script_translations( 'saddle-admin', 'saddle' );

		$current_user = wp_get_current_user();

		wp_add_inline_script(
			'saddle-admin',
			'window.saddleData = ' . wp_json_encode(
				array(
					'root'         => esc_url_raw( rest_url() ),
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'ns'           => Saddle_REST_Admin::REST_NAMESPACE,
					'mcpUrl'       => esc_url_raw( rest_url( Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE ) ),
					'user'         => $current_user ? $current_user->user_login : '',
					// Per-site MCP server slug ("saddle-plugpress") so someone
					// connecting several Saddle sites gets distinct entries in
					// their client, not five servers all named "saddle".
					'serverSlug'   => Saddle_MCP::server_slug(),
					'adapter'      => Saddle::mcp_adapter_available(),
					// Environment facts so the UI can warn before a connect fails.
					'appPasswords' => function_exists( 'wp_is_application_passwords_available' ) ? (bool) wp_is_application_passwords_available() : true,
					'ssl'          => is_ssl(),
				)
			) . ';',
			'before'
		);
	}
}
