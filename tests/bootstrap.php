<?php
/**
 * PHPUnit bootstrap for Saddle's WordPress integration tests.
 *
 * These are real integration tests: they boot a genuine WordPress (the safety
 * gate depends on WP_Query's exact-title match, post-meta storage, and
 * capability mapping — behaviour that can only be trusted when exercised against
 * real core, not mocks). To stay runnable without a MySQL server, we drive core
 * through the SQLite drop-in against an isolated, throwaway content directory.
 *
 * Configuration (override via environment if your paths differ):
 *   SADDLE_TEST_ABSPATH   Path to a WordPress core checkout (default: the
 *                         plug-press Studio site).
 *   SADDLE_SQLITE_SRC     wp-content dir holding db.php + the
 *                         sqlite-database-integration plugin to borrow.
 *   WP_PHPUNIT__DIR       Path to the wp-phpunit library (default: vendored).
 *
 * @package Saddle
 */

$saddle_abspath   = getenv( 'SADDLE_TEST_ABSPATH' ) ?: '/Users/fahim/Workspace/wp/plug-press/';
$saddle_abspath   = rtrim( $saddle_abspath, '/' ) . '/';
$saddle_sqlite_src = getenv( 'SADDLE_SQLITE_SRC' ) ?: $saddle_abspath . 'wp-content';
$tests_dir        = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! is_readable( $saddle_abspath . 'wp-load.php' ) ) {
	fwrite( STDERR, "Saddle tests: no WordPress core at {$saddle_abspath}. Set SADDLE_TEST_ABSPATH.\n" );
	exit( 1 );
}

$sqlite_dropin = $saddle_sqlite_src . '/db.php';
$sqlite_plugin = $saddle_sqlite_src . '/mu-plugins/sqlite-database-integration';
if ( ! is_dir( $sqlite_plugin ) ) {
	$sqlite_plugin = $saddle_sqlite_src . '/plugins/sqlite-database-integration';
}
if ( ! is_readable( $sqlite_dropin ) || ! is_dir( $sqlite_plugin ) ) {
	fwrite( STDERR, "Saddle tests: SQLite drop-in/plugin not found under {$saddle_sqlite_src}. Set SADDLE_SQLITE_SRC.\n" );
	exit( 1 );
}

/*
 * Build an isolated content directory. Everything the test WordPress writes —
 * the SQLite database, uploads — lands here and nowhere near a real site. The
 * database is deleted every run so each suite starts from a clean install.
 */
$content = __DIR__ . '/.wp/wp-content';
foreach ( array( $content, $content . '/plugins', $content . '/uploads', $content . '/database' ) as $dir ) {
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
}

// Fresh database each run: delete any prior SQLite file so install starts clean.
foreach ( glob( $content . '/database/*' ) as $stale ) {
	unlink( $stale );
}

// The SQLite drop-in resolves its implementation relative to its own directory,
// so copy db.php in and place the plugin where db.php looks for it.
copy( $sqlite_dropin, $content . '/db.php' );
$plugin_link = $content . '/plugins/sqlite-database-integration';
if ( ! file_exists( $plugin_link ) ) {
	symlink( $sqlite_plugin, $plugin_link );
}
$themes_link = $content . '/themes';
if ( ! file_exists( $themes_link ) ) {
	symlink( $saddle_abspath . 'wp-content/themes', $themes_link );
}

putenv( 'SADDLE_TEST_ABSPATH=' . $saddle_abspath );
putenv( 'SADDLE_TEST_CONTENT_DIR=' . $content );
putenv( 'SADDLE_TEST_PHP_BINARY=' . PHP_BINARY );

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

require_once $tests_dir . '/includes/functions.php';

/*
 * Thin Yoast SEO stubs — Yoast won't be a real test dependency (same call
 * the closed Rank Math plan made for its own test-bootstrap problem: prefer
 * a thin stub so the actual logic — field mapping, the robots trap, length
 * warnings — is covered, rather than skip-guarding the whole suite). These
 * mirror Yoast's real meta-key prefixing/storage closely enough for unit
 * coverage; live behavior is verified separately on divi-dev with real
 * Yoast installed.
 */
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '99.0-stub' );
}

// Rank Math needs no class stubs — its storage IS plain rank_math_* meta.
// The version constant alone satisfies Saddle_Rank_Math::is_active().
if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', '99.0-stub' );
}

// AIOSEO stores in its own table behind a model class — see the stub file.
if ( ! defined( 'AIOSEO_VERSION' ) ) {
	define( 'AIOSEO_VERSION', '99.0-stub' );
}
require_once __DIR__ . '/stubs-aioseo-model.php';

if ( ! class_exists( 'WPSEO_Meta' ) ) {
	class WPSEO_Meta {
		public static function get_value( $key, $post_id = 0 ) {
			return get_post_meta( $post_id, '_yoast_wpseo_' . $key, true );
		}
		public static function set_value( $key, $value, $post_id = 0 ) {
			update_post_meta( $post_id, '_yoast_wpseo_' . $key, $value );
		}
	}
}

// Term SEO reads/writes Yoast's Indexable model (Yoast\WP\SEO\... namespaced
// classes), not WPSEO_Taxonomy_Meta — see the stub file for why.
require_once __DIR__ . '/stubs-yoast-indexables.php';

// WooCommerce CRUD-API stubs (WC_Product/wc_get_products/wc_get_orders) —
// see the stub file. The product post type + taxonomies the stubs lean on
// are registered on init below, and the WooCommerce-minted capabilities the
// wc-* permission callbacks require are granted to administrators (real
// WooCommerce grants them on activation).
require_once __DIR__ . '/stubs-woocommerce.php';
tests_add_filter(
	'init',
	static function () {
		register_post_type(
			'product',
			array(
				'public'       => true,
				'map_meta_cap' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );
		register_taxonomy( 'product_tag', 'product', array() );

		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'edit_products' );
			$admin_role->add_cap( 'edit_shop_orders' );
			$admin_role->add_cap( 'manage_woocommerce' );
		}
	}
);

if ( ! class_exists( 'WPSEO_Primary_Term' ) ) {
	class WPSEO_Primary_Term {
		private $taxonomy;
		private $post_id;
		public function __construct( $taxonomy, $post_id ) {
			$this->taxonomy = $taxonomy;
			$this->post_id  = $post_id;
		}
		public function get_primary_term() {
			return (int) get_post_meta( $this->post_id, '_yoast_wpseo_primary_' . $this->taxonomy, true );
		}
		public function set_primary_term( $term_id ) {
			update_post_meta( $this->post_id, '_yoast_wpseo_primary_' . $this->taxonomy, (int) $term_id );
		}
	}
}

// Load the Saddle plugin as a must-use plugin inside the test WordPress.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/saddle.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
