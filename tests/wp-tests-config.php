<?php
/**
 * WordPress test-suite configuration for Saddle.
 *
 * Values that vary per machine are read from environment variables set in
 * tests/bootstrap.php (which prepares an isolated, SQLite-backed WordPress
 * content directory before this file is loaded). Nothing here needs a MySQL
 * server — the SQLite drop-in copied into the test content dir handles storage.
 *
 * @package Saddle
 */

// Path to the WordPress core we run against (a real WP install, e.g. Studio).
define( 'ABSPATH', getenv( 'SADDLE_TEST_ABSPATH' ) );

// Isolated content dir prepared by bootstrap.php — holds the SQLite drop-in,
// a throwaway database/ dir, and symlinked themes. Keeping WordPress' own
// content out of this test run is what makes the tests non-destructive.
define( 'WP_CONTENT_DIR', getenv( 'SADDLE_TEST_CONTENT_DIR' ) );
define( 'WP_CONTENT_URL', 'http://saddle.test/wp-content' );

// SQLite storage. The drop-in reads DB_ENGINE/DB_DIR/DB_FILE; the classic
// DB_* constants are still required to be defined by WordPress core, but their
// values are unused under SQLite.
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_DIR', WP_CONTENT_DIR . '/database/' );
define( 'DB_FILE', '.ht.sqlite' );
define( 'DB_NAME', 'saddle_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

define( 'WP_TESTS_DOMAIN', 'saddle.test' );
define( 'WP_TESTS_EMAIL', 'admin@saddle.test' );
define( 'WP_TESTS_TITLE', 'Saddle Test Site' );
define( 'WP_PHP_BINARY', getenv( 'SADDLE_TEST_PHP_BINARY' ) ?: 'php' );
define( 'WP_DEFAULT_THEME', 'plugpress-base' );

define( 'WPLANG', '' );

// Auth salts — arbitrary but must be defined.
define( 'AUTH_KEY', 'saddle-tests-auth-key' );
define( 'SECURE_AUTH_KEY', 'saddle-tests-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'saddle-tests-logged-in-key' );
define( 'NONCE_KEY', 'saddle-tests-nonce-key' );
define( 'AUTH_SALT', 'saddle-tests-auth-salt' );
define( 'SECURE_AUTH_SALT', 'saddle-tests-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'saddle-tests-logged-in-salt' );
define( 'NONCE_SALT', 'saddle-tests-nonce-salt' );

define( 'WP_DEBUG', true );
