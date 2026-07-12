<?php
/**
 * Plugin Name:       Saddle
 * Plugin URI:        https://plugpress.co/saddle
 * Description:       Self-hosted MCP server for WordPress. Tiered, default-safe, approval-gated access to posts, pages, and media for AI agents — with no third-party credential custody.
 * Version:           0.10.0
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

define( 'SADDLE_VERSION', '0.10.0' );
define( 'SADDLE_FILE', __FILE__ );
define( 'SADDLE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SADDLE_URL', plugin_dir_url( __FILE__ ) );
define( 'SADDLE_MIN_WP', '6.9' );

/**
 * Load plugin classes.
 *
 * Note: Saddle_Ecosystem is intentionally NOT loaded — it is parked Phase 3
 * scope (see https://github.com/plugpressco/saddle/issues/12). Do not require it without reopening that decision.
 */
require_once SADDLE_DIR . 'includes/class-saddle-tree.php';
require_once SADDLE_DIR . 'includes/class-saddle-blocks-tree.php';
require_once SADDLE_DIR . 'includes/class-saddle-blocks-author.php';
require_once SADDLE_DIR . 'includes/class-saddle-blocks-schema.php';
require_once SADDLE_DIR . 'includes/class-saddle-recipes.php';
require_once SADDLE_DIR . 'includes/class-saddle-blocks-echo.php';
require_once SADDLE_DIR . 'includes/lint/interface-saddle-lint-accessor.php';
require_once SADDLE_DIR . 'includes/lint/interface-saddle-lint-style-accessor.php';
require_once SADDLE_DIR . 'includes/lint/class-saddle-lint.php';
require_once SADDLE_DIR . 'includes/lint/class-saddle-lint-rule.php';
require_once SADDLE_DIR . 'includes/lint/class-saddle-lint-color.php';
require_once SADDLE_DIR . 'includes/lint/class-saddle-lint-gutenberg-accessor.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-empty-title.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-button-contrast.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-ghost-button.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-double-background.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-mixed-accents.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-unaligned-buttons.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-section-padding.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-featured-plan.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-text-contrast.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-missing-alt.php';
require_once SADDLE_DIR . 'includes/lint/rules/class-rule-heading-order.php';
require_once SADDLE_DIR . 'includes/render/interface-saddle-render-accessor.php';
require_once SADDLE_DIR . 'includes/render/class-saddle-render.php';
require_once SADDLE_DIR . 'includes/render/class-saddle-render-gutenberg-accessor.php';
require_once SADDLE_DIR . 'includes/preview/class-saddle-preview.php';
require_once SADDLE_DIR . 'includes/verify/class-saddle-verify.php';
require_once SADDLE_DIR . 'includes/class-saddle-capabilities.php';
require_once SADDLE_DIR . 'includes/class-saddle-approval.php';
require_once SADDLE_DIR . 'includes/class-saddle-context.php';
require_once SADDLE_DIR . 'includes/class-saddle-skills.php';
require_once SADDLE_DIR . 'includes/class-saddle-memory.php';
require_once SADDLE_DIR . 'includes/class-saddle-log.php';
require_once SADDLE_DIR . 'includes/class-saddle-connection.php';
require_once SADDLE_DIR . 'includes/class-saddle-integrations.php';
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

		// Make a bare 401 legible: a revoked/rejected key (reconnect) reads
		// differently from a stripped Authorization header (host config). Late
		// priority so core has already produced its auth result to relabel.
		add_filter( 'rest_authentication_errors', array( 'Saddle_Connection', 'explain_auth_error' ), 20 );

		// Always-on infrastructure (independent of the Abilities API).
		// The preview serving path stays up even when minting isn't — an
		// outstanding token must keep working for its full (short) life.
		Saddle_Preview::register();
		add_action( 'init', array( 'Saddle_Approval', 'register_cpt' ) );
		add_action( 'init', array( 'Saddle_Log', 'register_cpt' ) );
		add_action( 'init', array( 'Saddle_Skills', 'register_cpt' ) );
		add_action( 'init', array( 'Saddle_Memory', 'register_cpt' ) );

		// Skills index + core memory ride the same context every session
		// receives (the initialize handshake + get-instructions), via the
		// shared filter.
		add_filter( 'saddle_system_context', array( 'Saddle_Skills', 'append_index' ) );
		add_filter( 'saddle_system_context', array( 'Saddle_Memory', 'append_context' ) );
		add_action( 'rest_api_init', array( 'Saddle_REST_Admin', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'Saddle_Connection', 'register_routes' ) );
		add_action( 'admin_menu', array( 'Saddle_Settings', 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'Saddle_Settings', 'enqueue_assets' ) );
		add_action( Saddle_Approval::GC_HOOK, array( 'Saddle_Approval', 'gc' ) );
		add_action( Saddle_Approval::GC_HOOK, array( 'Saddle_Log', 'gc' ) );
		add_action( Saddle_Approval::GC_HOOK, array( 'Saddle_Memory', 'gc' ) );

		// The MCP surface and abilities require core's Abilities API (WP 6.9+).
		if ( self::abilities_api_available() ) {
			require_once SADDLE_DIR . 'includes/abilities/core-content.php';
			require_once SADDLE_DIR . 'includes/abilities/blocks.php';
			require_once SADDLE_DIR . 'includes/abilities/site.php';
			require_once SADDLE_DIR . 'includes/abilities/users.php';
			require_once SADDLE_DIR . 'includes/abilities/context.php';
			require_once SADDLE_DIR . 'includes/abilities/lint.php';
			require_once SADDLE_DIR . 'includes/abilities/render.php';
			require_once SADDLE_DIR . 'includes/abilities/verify.php';
			require_once SADDLE_DIR . 'includes/abilities/memory.php';
			add_action( 'wp_abilities_api_categories_init', 'saddle_register_ability_category' );
			add_action( 'wp_abilities_api_init', 'saddle_register_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_block_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_site_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_user_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_context_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_lint_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_render_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_verify_abilities' );
			add_action( 'wp_abilities_api_init', 'saddle_register_memory_abilities' );
			// First-party integration wrappers run late (30) so the partner
			// plugins' own abilities exist to discover.
			add_action( 'wp_abilities_api_init', array( 'Saddle_Integrations', 'register_wrappers' ), 30 );
			add_filter( 'saddle_system_context', array( 'Saddle_Integrations', 'append_context' ) );

			// Wire the MCP transport after all plugins have loaded. This MUST be
			// deferred (see setup_mcp_transport) so the bundled adapter can't
			// collide with a standalone mcp-adapter plugin.
			add_action( 'plugins_loaded', array( __CLASS__, 'setup_mcp_transport' ) );
		} else {
			// Priority 0: prints before the notice quarantine opens on
			// Saddle's own screen (Saddle_Settings::setup_notice_quarantine).
			add_action( 'admin_notices', array( __CLASS__, 'abilities_api_notice' ), 0 );
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
