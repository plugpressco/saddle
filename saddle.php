<?php
/**
 * Plugin Name:       Saddle
 * Plugin URI:        https://plugpress.co/saddle
 * Description:       Self-hosted MCP server for WordPress. Tiered, default-safe, approval-gated access to posts, pages, and media for AI agents — with no third-party credential custody.
 * Version:           0.2.1
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            PlugPress
 * Author URI:        https://plugpress.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       saddle
 * Domain Path:       /languages
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

define( 'SADDLE_VERSION', '0.2.1' );
define( 'SADDLE_FILE', __FILE__ );
define( 'SADDLE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SADDLE_URL', plugin_dir_url( __FILE__ ) );
define( 'SADDLE_MIN_WP', '6.9' );

/**
 * Load plugin classes.
 *
 * Note: Saddle_Ecosystem is intentionally NOT loaded — it is parked Phase 3
 * scope (see MVP-PLAN.md). Do not require it without reopening that decision.
 */
require_once SADDLE_DIR . 'includes/class-saddle-tree.php';
require_once SADDLE_DIR . 'includes/class-saddle-blocks-tree.php';
require_once SADDLE_DIR . 'includes/class-saddle-blocks-author.php';
require_once SADDLE_DIR . 'includes/class-saddle-blocks-schema.php';
require_once SADDLE_DIR . 'includes/class-saddle-capabilities.php';
require_once SADDLE_DIR . 'includes/class-saddle-approval.php';
require_once SADDLE_DIR . 'includes/class-saddle-context.php';
require_once SADDLE_DIR . 'includes/class-saddle-log.php';
require_once SADDLE_DIR . 'includes/class-saddle-connection.php';
require_once SADDLE_DIR . 'includes/class-saddle-mcp.php';
require_once SADDLE_DIR . 'includes/admin/class-saddle-rest.php';
require_once SADDLE_DIR . 'includes/admin/class-saddle-settings.php';

/**
 * Bootstrap container. Wires WordPress hooks to the plugin's components.
 */
final class Saddle {

	/**
	 * Boot the plugin.
	 */
	public static function init() {
		// Recover a stripped Basic Authorization header for REST/API requests so
		// core's Application Password auth still works on hosts that mangle it.
		// Must run before REST authentication; priority 1 on plugins_loaded.
		add_action( 'plugins_loaded', array( 'Saddle_Connection', 'recover_auth_header' ), 1 );

		// Credential scoping: a Saddle-issued Application Password only opens
		// Saddle's own endpoint — never wp/v2, wp-abilities/v1, or XML-RPC.
		add_filter( 'rest_request_before_callbacks', array( 'Saddle_Connection', 'scope_credentials' ), 10, 3 );
		add_action( 'wp_authenticate_application_password_errors', array( 'Saddle_Connection', 'block_xmlrpc_credentials' ), 10, 3 );

		// Always-on infrastructure (independent of the Abilities API).
		add_action( 'init', array( 'Saddle_Approval', 'register_cpt' ) );
		add_action( 'init', array( 'Saddle_Log', 'register_cpt' ) );
		add_action( 'rest_api_init', array( 'Saddle_REST_Admin', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'Saddle_Connection', 'register_routes' ) );
		add_action( 'admin_menu', array( 'Saddle_Settings', 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'Saddle_Settings', 'enqueue_assets' ) );
		add_action( Saddle_Approval::GC_HOOK, array( 'Saddle_Approval', 'gc' ) );
		add_action( Saddle_Approval::GC_HOOK, array( 'Saddle_Log', 'gc' ) );

		// The MCP surface and abilities require core's Abilities API (WP 6.9+).
		if ( self::abilities_api_available() ) {
			require_once SADDLE_DIR . 'includes/abilities/core-content.php';
			require_once SADDLE_DIR . 'includes/abilities/blocks.php';
			add_action( 'wp_abilities_api_categories_init', 'saddle_register_ability_category' );
			add_action( 'wp_abilities_api_init', 'saddle_register_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_block_abilities' );

			// Wire the MCP transport after all plugins have loaded. This MUST be
			// deferred (see setup_mcp_transport) so the bundled adapter can't
			// collide with a standalone mcp-adapter plugin.
			add_action( 'plugins_loaded', array( __CLASS__, 'setup_mcp_transport' ) );
		} else {
			add_action( 'admin_notices', array( __CLASS__, 'abilities_api_notice' ) );
		}
	}

	/**
	 * Wire the MCP transport, deferred to `plugins_loaded`.
	 *
	 * The bundled WP\MCP library must NOT load during Saddle's own plugin-include
	 * phase. If a standalone mcp-adapter plugin is also active, whichever loads
	 * the library first would make the other's un-guarded `require_once
	 * Autoloader.php` fatal on class redeclaration. By waiting until
	 * plugins_loaded — after every plugin main file is included — a standalone
	 * copy (if any) has already defined WP\MCP and we defer to it. Only when no
	 * copy exists do we load Saddle's bundle.
	 *
	 * The official adapter serves the MCP endpoint; if it can't load at all,
	 * Saddle's built-in transport takes over. Both serve /saddle/v1/mcp, and the
	 * tier + approval gate live in the abilities regardless of transport.
	 */
	public static function setup_mcp_transport() {
		self::load_bundled_mcp_adapter();

		if ( self::mcp_adapter_available() ) {
			add_action( 'mcp_adapter_init', array( 'Saddle_MCP', 'register_adapter_server' ) );
		} else {
			add_action( 'rest_api_init', array( 'Saddle_MCP', 'register_routes' ) );
		}
	}

	/**
	 * Whether the official WordPress MCP Adapter library is available.
	 *
	 * @return bool
	 */
	public static function mcp_adapter_available() {
		return class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * Boot the bundled WordPress MCP Adapter library.
	 *
	 * Guarded so that if any other plugin (e.g. the standalone mcp-adapter
	 * plugin) already loaded `WP\MCP`, that copy wins and we don't redeclare its
	 * classes. Composer's autoloader in the bundle uses paths relative to its own
	 * location, so the vendored copy works from inside Saddle unchanged.
	 */
	private static function load_bundled_mcp_adapter() {
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return; // Provided elsewhere — defer to it.
		}

		/**
		 * Filter whether Saddle loads its bundled MCP Adapter library.
		 *
		 * Return false to keep the bundle dormant — e.g. if you prefer to run the
		 * standalone WordPress "MCP Adapter" plugin instead. (Two copies of the
		 * un-guarded library cannot load in one request, so use one or the other.)
		 *
		 * @param bool $load Whether to load the bundled library. Default true.
		 */
		if ( ! apply_filters( 'saddle_load_bundled_mcp_adapter', true ) ) {
			return;
		}

		$lib        = SADDLE_DIR . 'includes/lib/wp-mcp/';
		$autoloader = $lib . 'includes/Autoloader.php';
		if ( ! is_readable( $autoloader ) ) {
			return; // Bundle missing — the built-in transport will take over.
		}

		if ( ! defined( 'WP_MCP_DIR' ) ) {
			define( 'WP_MCP_DIR', $lib );
		}
		if ( ! defined( 'WP_MCP_VERSION' ) ) {
			define( 'WP_MCP_VERSION', '0.5.0' );
		}

		require_once $autoloader;

		if ( class_exists( '\\WP\\MCP\\Autoloader' )
			&& \WP\MCP\Autoloader::autoload()
			&& class_exists( '\\WP\\MCP\\Plugin' )
		) {
			\WP\MCP\Plugin::instance();
		}
	}

	/**
	 * Whether core's Abilities API is present.
	 *
	 * @return bool
	 */
	public static function abilities_api_available() {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Admin notice shown when the Abilities API is missing.
	 */
	public static function abilities_api_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s: minimum WordPress version. */
			esc_html__( 'Saddle requires WordPress %s or later (the core Abilities API). The MCP server is disabled until you upgrade.', 'saddle' ),
			esc_html( SADDLE_MIN_WP )
		);
		echo '</p></div>';
	}

	/**
	 * Activation: set the default (read) tier without overwriting an existing
	 * choice, register the CPT so its rewrite/state is known, and schedule GC.
	 */
	public static function activate() {
		// Default-safe: new installs start at the read tier. Never override an
		// existing value here — that would silently downgrade a configured site.
		if ( false === get_option( Saddle_Capabilities::OPTION, false ) ) {
			add_option( Saddle_Capabilities::OPTION, Saddle_Capabilities::DEFAULT_TIER );
		}

		Saddle_Approval::register_cpt();

		if ( ! wp_next_scheduled( Saddle_Approval::GC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Saddle_Approval::GC_HOOK );
		}
	}

	/**
	 * Deactivation: clear the scheduled token garbage-collection event.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( Saddle_Approval::GC_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Saddle_Approval::GC_HOOK );
		}
	}
}

register_activation_hook( __FILE__, array( 'Saddle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Saddle', 'deactivate' ) );

Saddle::init();
