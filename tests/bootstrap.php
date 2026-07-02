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

// Load the Saddle plugin as a must-use plugin inside the test WordPress.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/saddle.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
