#!/usr/bin/env php
<?php
/**
 * Re-apply Saddle's one recorded change to the vendored MCP adapter.
 *
 * `includes/lib/wp-mcp/` is vendored and must not be hand-edited — CLAUDE.md
 * says so twice. Exactly one deviation is nevertheless carried, deliberately:
 * every i18n text domain inside it is rewritten from `mcp-adapter` to `saddle`,
 * so the self-hosted build (the only build that ships the library at all — the
 * WordPress.org zip excludes it) exposes one text domain to translators rather
 * than two.
 *
 * That deviation was applied by hand, and a hand-applied change to a vendored
 * tree is lost the moment someone drops in a fresh upstream copy — silently,
 * because nothing fails. This script is the change, written down and
 * re-runnable, so re-vendoring is:
 *
 *     1. replace includes/lib/wp-mcp/ with the upstream release
 *     2. php scripts/revendor-wp-mcp.php
 *     3. composer test && composer lint
 *
 * It is idempotent: running it on an already-patched tree reports zero
 * changes. `--check` makes no changes and exits non-zero if any are pending,
 * which is what to run before a release.
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH: `'mcp-adapter'` also appears as an
 * ability category and as the adapter's own server id. Those are identifiers,
 * not translatable strings, and rewriting them would rename things upstream
 * code looks up by name. Only the text-domain ARGUMENT is rewritten — the
 * last argument of an i18n call, recognised by the closing parenthesis that
 * follows it.
 *
 * Not shipped: `scripts/` is excluded from both build channels.
 *
 * @package Saddle
 */

// This is a developer CLI tool, not plugin code — it runs outside WordPress.
if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script is CLI-only.\n" );
	exit( 1 );
}

$saddle_lib = dirname( __DIR__ ) . '/includes/lib/wp-mcp';

if ( ! is_dir( $saddle_lib ) ) {
	// The WordPress.org build has no vendored library, and neither does a
	// checkout that never had one. Nothing to do is a success, not a failure.
	fwrite( STDOUT, "No vendored adapter at includes/lib/wp-mcp — nothing to patch.\n" );
	exit( 0 );
}

$saddle_check_only = in_array( '--check', $argv, true );

/**
 * Only ever the text-domain argument: a quoted 'mcp-adapter' whose next
 * non-whitespace character closes the call. An ability category or a server
 * id is followed by a comma, so neither matches.
 */
$saddle_pattern = "/'mcp-adapter'(\s*\))/";

$saddle_files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $saddle_lib ) );
$saddle_touched = array();
$saddle_total   = 0;

foreach ( $saddle_files as $saddle_file ) {
	if ( ! $saddle_file->isFile() || 'php' !== strtolower( $saddle_file->getExtension() ) ) {
		continue;
	}

	$saddle_path     = $saddle_file->getPathname();
	$saddle_contents = file_get_contents( $saddle_path );
	if ( false === $saddle_contents ) {
		fwrite( STDERR, sprintf( "Could not read %s\n", $saddle_path ) );
		exit( 1 );
	}

	$saddle_count   = 0;
	$saddle_patched = preg_replace( $saddle_pattern, "'saddle'\$1", $saddle_contents, -1, $saddle_count );

	if ( ! $saddle_count ) {
		continue;
	}

	$saddle_total    += $saddle_count;
	$saddle_touched[] = sprintf( '%s (%d)', substr( $saddle_path, strlen( $saddle_lib ) + 1 ), $saddle_count );

	if ( ! $saddle_check_only && false === file_put_contents( $saddle_path, $saddle_patched ) ) {
		fwrite( STDERR, sprintf( "Could not write %s\n", $saddle_path ) );
		exit( 1 );
	}
}

sort( $saddle_touched );

if ( ! $saddle_total ) {
	fwrite( STDOUT, "Vendored adapter already carries Saddle's text domain — nothing to do.\n" );
	exit( 0 );
}

fwrite(
	STDOUT,
	sprintf(
		"%s %d text-domain occurrence(s) across %d file(s):\n  %s\n",
		$saddle_check_only ? 'PENDING:' : 'Rewrote',
		$saddle_total,
		count( $saddle_touched ),
		implode( "\n  ", $saddle_touched )
	)
);

// --check is for CI and pre-release: pending work is a failure there, because
// it means the tree was re-vendored and this was never re-run.
exit( $saddle_check_only ? 1 : 0 );
