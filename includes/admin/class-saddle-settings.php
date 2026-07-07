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
			self::menu_icon(),
			// PlugPress ecosystem menu band (see @plugpress/ui php/menu-positions.php).
			'58.20'
		);

		// Remember the hook suffix so enqueue only fires on our page.
		self::$hook_suffix = $hook;

		add_action( 'in_admin_header', array( __CLASS__, 'setup_notice_quarantine' ) );
	}

	/**
	 * The Saddle brand mark as a base64 SVG for the admin menu.
	 *
	 * Fills only (no strokes) so core's svg-painter recolors it to match the
	 * active admin color scheme. Same path as icons.jsx's <BrandMark />.
	 *
	 * @return string data: URI.
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="black" d="M12 4c-4.4 0-8 3.6-8 8v7a1 1 0 0 0 1 1h2.5a1 1 0 0 0 1-1v-6.5a3.5 3.5 0 1 1 7 0V19a1 1 0 0 0 1 1H19a1 1 0 0 0 1-1v-7c0-4.4-3.6-8-8-8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard wp-admin menu icon embedding.
	}

	/**
	 * Stored hook suffix for the admin page.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Output-buffer level when the notice capture opened (0 = not capturing).
	 *
	 * @var int
	 */
	private static $notice_buffer_level = 0;

	/**
	 * The current user's admin theme (system|light|dark), for the pre-paint
	 * theme boot. Set in enqueue_assets, consumed by print_theme_boot.
	 *
	 * @var string
	 */
	private static $theme = 'system';

	/**
	 * On Saddle's screen only: capture other plugins' admin notices instead of
	 * letting them pile above the app.
	 *
	 * Saddle's own PHP notices register at priority 0 so they print BEFORE the
	 * buffer opens at priority 1; everything after (other plugins default to
	 * 10, plus all_admin_notices output) lands in a hidden container the React
	 * app surfaces behind a quiet disclosure. Notices are moved, not deleted —
	 * dismiss buttons and inline handlers keep working.
	 */
	public static function setup_notice_quarantine() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== self::$hook_suffix ) {
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'begin_notice_capture' ), 1 );
		add_action( 'all_admin_notices', array( __CLASS__, 'end_notice_capture' ), PHP_INT_MAX );
	}

	/**
	 * Open the capture buffer (admin_notices priority 1).
	 */
	public static function begin_notice_capture() {
		ob_start();
		self::$notice_buffer_level = ob_get_level();
	}

	/**
	 * Close the capture buffer and print its contents into the hidden
	 * quarantine container (all_admin_notices, last).
	 *
	 * Degrades safely: if a notice callback closed our buffer, nothing is
	 * captured and notices show exactly as they do today.
	 */
	public static function end_notice_capture() {
		if ( ! self::$notice_buffer_level || ob_get_level() < self::$notice_buffer_level ) {
			self::$notice_buffer_level = 0;
			return;
		}
		// A notice callback may have opened buffers it never closed — flush
		// them down into ours so their output isn't lost.
		while ( ob_get_level() > self::$notice_buffer_level ) {
			ob_end_flush();
		}
		$html                      = ob_get_clean();
		self::$notice_buffer_level = 0;

		if ( '' === trim( (string) $html ) ) {
			return;
		}
		// Captured wp-admin output, re-emitted verbatim in a hidden container.
		echo '<div id="saddle-foreign-notices" hidden>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Print the pre-paint theme attribute on <body>.
	 *
	 * Runs at the top of the admin content (in_admin_header), before the app
	 * bundle (footer) and before #saddle-root paints, so the design-system
	 * tokens resolve to the right theme with no flash.
	 */
	public static function print_theme_boot() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== self::$hook_suffix ) {
			return;
		}
		printf(
			'<script>document.body.dataset.ppTheme=%s;</script>',
			wp_json_encode( self::$theme )
		);
	}

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
			// Priority 0 keeps it ahead of the notice quarantine.
			add_action(
				'admin_notices',
				function () {
					if ( get_current_screen() && false !== strpos( get_current_screen()->id, self::PAGE_SLUG ) ) {
						echo '<div class="notice notice-warning"><p>';
						echo esc_html__( 'Saddle: the admin UI has not been built yet. Run "npm install && npm run build" in the plugin folder.', 'saddle' );
						echo '</p></div>';
					}
				},
				0
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

		// wp-scripts (default config) emits two stylesheets: "index.css" holds the
		// plain-CSS imports (the @plugpress/ui design system), and
		// "style-index.css" holds the compiled style.scss (Saddle's own rules,
		// which alias the design-system --pp-* tokens). Load the design system
		// first, then Saddle on top so its higher-specificity rules win.
		$ds_dep = array( 'wp-components' );
		if ( file_exists( $build_dir . 'index.css' ) ) {
			wp_enqueue_style(
				'saddle-admin-ds',
				$build_url . 'index.css',
				array( 'wp-components' ),
				$asset['version']
			);
			$ds_dep = array( 'wp-components', 'saddle-admin-ds' );
		}
		if ( file_exists( $build_dir . 'style-index.css' ) ) {
			wp_enqueue_style(
				'saddle-admin',
				$build_url . 'style-index.css',
				$ds_dep,
				$asset['version']
			);
		}

		wp_set_script_translations( 'saddle-admin', 'saddle' );

		$current_user = wp_get_current_user();
		$theme        = (string) get_user_meta( get_current_user_id(), 'saddle_admin_theme', true );
		$theme        = in_array( $theme, array( 'light', 'dark' ), true ) ? $theme : 'system';
		self::$theme  = $theme;

		// The design system reads its --pp-* tokens from a .pp-scope ancestor,
		// so the page body carries it (portaled overlays inherit the tokens too).
		add_filter(
			'admin_body_class',
			static function ( $classes ) {
				return $classes . ' pp-scope';
			}
		);

		// Set data-pp-theme on <body> before the app paints, so an explicit
		// light/dark choice (or a system user's OS preference) never flashes the
		// other theme. The useTheme hook keeps it in sync after mount.
		add_action( 'in_admin_header', array( __CLASS__, 'print_theme_boot' ) );

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
					'theme'        => $theme,
					// Where WordPress itself lists these credentials — linked
					// from the Connect tab for transparency.
					'profileUrl'   => esc_url_raw( admin_url( 'profile.php#application-passwords-section' ) ),
				)
			) . ';',
			'before'
		);
	}
}
