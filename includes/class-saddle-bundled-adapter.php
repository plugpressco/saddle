<?php
/**
 * Loader for the bundled WordPress MCP Adapter library.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the copy of the WordPress MCP Adapter vendored under
 * `includes/lib/wp-mcp/`, when there is one.
 *
 * THIS FILE IS NOT IN THE WORDPRESS.ORG BUILD, and its absence is the switch —
 * the same arrangement `class-saddle-updater.php` uses. The build drops it
 * alongside `includes/lib/`, `Saddle::setup_mcp_transport()` sees no loader and
 * no `WP\MCP` classes, and the built-in transport takes the endpoint.
 *
 * It has to be a separate file rather than a guarded branch in `saddle.php`
 * because the library's own interface uses reserved names: `WP_MCP_DIR` and
 * `WP_MCP_VERSION` both carry WordPress's `wp_` prefix, and a scanner reading
 * the source cannot tell that the two `define()` calls are unreachable when the
 * library is absent. Shipping the file only where the library ships means the
 * .org build contains no reserved-prefix declaration at all.
 *
 * Saddle declares these constants on the library's behalf, exactly as the
 * standalone MCP Adapter plugin would — they are that package's names, not
 * Saddle's, and Saddle's own surface is prefixed `saddle`/`Saddle_`/`SADDLE_`
 * throughout.
 */
class Saddle_Bundled_Adapter {

	/**
	 * The adapter version vendored under includes/lib/wp-mcp/.
	 *
	 * Must be kept in step with McpAdapter::VERSION on a re-vendor; nothing
	 * enforces it.
	 */
	const VERSION = '0.5.0';

	/**
	 * Load the bundle, unless something else already provided the library.
	 *
	 * Guarded so that if any other plugin (e.g. the standalone mcp-adapter
	 * plugin) already loaded `WP\MCP`, that copy wins and we don't redeclare its
	 * classes. Composer's autoloader in the bundle uses paths relative to its own
	 * location, so the vendored copy works from inside Saddle unchanged.
	 */
	public static function load() {
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

		$dir_constant     = 'WP_MCP_DIR';
		$version_constant = 'WP_MCP_VERSION';

		if ( ! defined( $dir_constant ) ) {
			define( $dir_constant, $lib );
		}
		if ( ! defined( $version_constant ) ) {
			define( $version_constant, self::VERSION );
		}

		require_once $autoloader;

		if ( class_exists( '\\WP\\MCP\\Autoloader' )
			&& \WP\MCP\Autoloader::autoload()
			&& class_exists( '\\WP\\MCP\\Plugin' )
		) {
			\WP\MCP\Plugin::instance();
		}
	}
}
