/**
 * Grunt build for Saddle.
 *
 * Produces a distributable plugin zip whose top-level folder is `saddle/`
 * (main file `saddle/saddle.php`), and bumps the version across the files that
 * declare it.
 *
 * Usage:
 *   grunt                 Build dist/saddle-<version>.zip at the current version.
 *   grunt build           Same as above.
 *   grunt version         Bump the patch version (0.1.0 -> 0.1.1).
 *   grunt version:minor   Bump the minor version (0.1.0 -> 0.2.0).
 *   grunt version:major   Bump the major version (0.1.0 -> 1.0.0).
 *   grunt version --to=1.2.3   Set an explicit version.
 *   grunt release[:patch|:minor|:major]   Bump the version, then build the zip.
 *
 * The version lives in saddle.php (the `Version:` header and the
 * SADDLE_VERSION constant); readme.txt (`Stable tag`) and package.json are
 * kept in sync.
 * @param grunt
 */
module.exports = function ( grunt ) {
	'use strict';

	const MAIN_FILE = 'saddle.php';
	const SLUG = 'saddle';

	function currentVersion() {
		const php = grunt.file.read( MAIN_FILE );
		// Must capture any prerelease suffix too. A numeric-only pattern reads
		// `1.0.0-rc1` as `1.0.0`, which silently names the archive after a
		// version that isn't in it — two different builds, one filename.
		const match = php.match(
			/Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.]+)?)/
		);
		if ( ! match ) {
			grunt.fail.fatal( 'Could not read the version from ' + MAIN_FILE );
		}
		return match[ 1 ];
	}

	function bump( version, type ) {
		const parts = version.split( '.' ).map( Number );
		if ( 'major' === type ) {
			parts[ 0 ]++;
			parts[ 1 ] = 0;
			parts[ 2 ] = 0;
		} else if ( 'minor' === type ) {
			parts[ 1 ]++;
			parts[ 2 ] = 0;
		} else {
			parts[ 2 ]++; // patch (default)
		}
		return parts.join( '.' );
	}

	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		clean: {
			dist: [ 'dist/' ],
		},

		copy: {
			dist: {
				files: [
					{
						expand: true,
						dot: true,
						src: [
							'**',
							// Development-only / never-shipped.
							'!node_modules/**',
							'!vendor/**',
							'!tests/**',
							'!dist/**',
							'!.git/**',
							'!.github/**',
							'!.claude/**',
							// Same reason as .claude/: agent instructions are dev
							// config, not plugin code. Excluded explicitly rather
							// than relying on how the globber treats dot-dirs.
							'!.agents/**',
							// Developer CLI tooling — re-vendoring helpers and
							// the like. Never plugin code.
							'!scripts/**',
							'!Gruntfile.js',
							'!package.json',
							'!package-lock.json',
							'!composer.json',
							'!composer.lock',
							'!phpunit.xml',
							'!phpunit.xml.dist',
							'!webpack.config.js',
							'!.distignore',
							'!.gitignore',
							'!.editorconfig',
							// No Markdown ships, anywhere in the tree — internal
							// docs, agent instructions, and vendored READMEs alike.
							'!**/*.md',
							// Exception: bundled third-party licenses must ship for
							// GPL attribution. Scoped to the vendored adapter so
							// node_modules/ and vendor/ stay excluded.
							'includes/lib/**/LICENSE.md',
							// User docs live on the website, not in the zip.
							'!docs/**',
							// The vendored WordPress MCP Adapter is NOT shipped.
							//
							// It is ~347 of 464 files and half the zip, and every
							// symbol in it is namespaced WP\MCP / hooked
							// mcp_adapter_* — `wp_` is reserved for core, which is
							// what WordPress.org rejected the submission over. It
							// also carries the only error_log/fwrite calls in the
							// tree and stands up a second, undeclared endpoint at
							// /wp-json/mcp/mcp-adapter-default-server.
							//
							// Nothing is lost by its absence: Saddle_MCP's own
							// JSON-RPC transport serves the same /saddle/v1/mcp URL
							// with the same abilities and the same tier + approval
							// gate, and Saddle::load_bundled_mcp_adapter() already
							// early-returns when the directory isn't readable —
							// before it defines WP_MCP_DIR/WP_MCP_VERSION, so those
							// two reserved constants disappear with it. A site that
							// installs the official MCP Adapter plugin gets the
							// adapter path back automatically.
							//
							// Excluded on BOTH channels, not just .org. The earlier
							// plan was to re-include it for self-hosted; that was
							// written before Saddle_MCP's own transport was hardened
							// as the one a .org install will ever have, and shipping
							// a different transport to testers than to .org users
							// costs us the field evidence we most need. Tracked as
							// its own decision in #113.
							'!includes/lib/**',
							// The loader that declares the library's own reserved
							// WP_MCP_* constants. Useless without the library, and
							// guarded with class_exists() at the call site.
							'!includes/class-saddle-bundled-adapter.php',
							// class-saddle-mcp-compat.php is deliberately NOT
							// excluded, on either channel. It used to be, and that
							// is how the ChatGPT fix in #80/#81 shipped to nobody
							// for a month (#111).
							//
							// The trap: adapter_available() tests for
							// \WP\MCP\Core\McpAdapter, not for OUR bundled copy. A
							// site that installs the official MCP Adapter plugin
							// takes the adapter path on ANY channel, including
							// .org — and then class_exists( 'Saddle_MCP_Compat' )
							// is false, the shim never registers, and the owner
							// gets an app that connects and reports no callable
							// actions. Excluding it never made a build safer; it
							// only made that failure reachable.
							//
							// It is ~300 lines of our own code with no library
							// behind it, and applies_to() already no-ops when
							// SessionManager is absent, so it costs a .org build
							// nothing. Unlike the updater, its absence is not a
							// guarantee we are making to anyone.
							//
							// WP.org listing assets — go to SVN assets/, never in the zip.
							'!.wordpress.org/**',
							// Lint config — dev-only.
							'!phpcs.xml.dist',
							// Parked Phase-3 scope — intentionally never loaded
							// (see the note in saddle.php); don't ship dead code.
							'!includes/class-saddle-ecosystem.php',
							// Dev caches.
							'!.phpunit.result.cache',
							// Self-hosted updater — see the `channel` task below.
							// Excluded by DEFAULT so the .org-safe artifact is what
							// you get if you forget to think about it; the
							// self-hosted build adds it back explicitly.
							'!includes/class-saddle-updater.php',
							// NOTE: admin/src/ IS shipped. WordPress.org requires the
							// human-readable React source alongside the compiled
							// admin/build bundle.
							// OS / editor cruft.
							'!**/.DS_Store',
							'!**/Thumbs.db',
							'!**/*.log',
						],
						dest: 'dist/' + SLUG + '/',
					},
				],
			},
		},

		compress: {
			dist: {
				// options.archive is set at run time in the `zip` task so it
				// always reflects the current (possibly just-bumped) version.
				options: { mode: 'zip' },
				files: [
					{
						expand: true,
						cwd: 'dist/',
						src: [ SLUG + '/**' ],
						dest: '',
					},
				],
			},
		},
	} );

	grunt.loadNpmTasks( 'grunt-contrib-clean' );
	grunt.loadNpmTasks( 'grunt-contrib-copy' );
	grunt.loadNpmTasks( 'grunt-contrib-compress' );

	grunt.registerTask(
		'version',
		'Bump the plugin version (patch|minor|major, or --to=x.y.z[-rcN]).',
		function ( type ) {
			const from = currentVersion();
			const to = grunt.option( 'to' ) || bump( from, type || 'patch' );

			// Prerelease suffixes are allowed and load-bearing: PHP's
			// version_compare() sorts `1.0.0-rc2` BELOW `1.0.0`, so a release
			// candidate retires itself the moment the real version ships and
			// every test install upgrades cleanly — including onto the
			// WordPress.org build. Without this, every interim build has to
			// reuse the same number and nobody can tell two builds apart.
			//
			// Note the update worker's own versionGt() strips the suffix and
			// compares numerics only; that path is beta-channel selection,
			// which this product does not use. The client-side comparison in
			// Saddle_Updater is what decides, and it uses version_compare().
			if ( ! /^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$/.test( to ) ) {
				grunt.fail.fatal( 'Invalid target version: ' + to );
			}

			// Match the existing value including any suffix, so bumping away
			// from `1.0.0-rc1` replaces the whole string rather than leaving
			// `1.0.1-rc1` behind.
			const VER = '[0-9]+\\.[0-9]+\\.[0-9]+(?:-[0-9A-Za-z.]+)?';

			// Main file: the plugin header Version and the SADDLE_VERSION constant.
			let php = grunt.file.read( MAIN_FILE );
			php = php.replace( new RegExp( '(Version:\\s*)' + VER ), '$1' + to );
			php = php.replace(
				new RegExp( "(define\\(\\s*'SADDLE_VERSION',\\s*')" + VER + "('\\s*\\))" ),
				'$1' + to + '$2'
			);
			grunt.file.write( MAIN_FILE, php );

			// readme.txt: Stable tag. A release candidate must NEVER become the
			// stable tag — that is the field WordPress.org serves to everyone —
			// so prerelease builds leave it pointing at the last real version.
			if ( grunt.file.exists( 'readme.txt' ) && ! to.includes( '-' ) ) {
				let readme = grunt.file.read( 'readme.txt' );
				readme = readme.replace(
					new RegExp( '(Stable tag:\\s*)' + VER ),
					'$1' + to
				);
				grunt.file.write( 'readme.txt', readme );
			}

			// package.json: version.
			const pkg = grunt.file.readJSON( 'package.json' );
			pkg.version = to;
			grunt.file.write(
				'package.json',
				JSON.stringify( pkg, null, '\t' ) + '\n'
			);

			// package-lock.json: BOTH copies. It carries the version at the root
			// and again under packages[''], and this task wrote neither — so
			// the lockfile silently kept the previous version while the other
			// four sites moved, which is exactly the drift the "five places
			// must agree" rule exists to prevent.
			//
			// Addressed structurally, NOT by a text substitution. Every
			// dependency in this file has a "version" field too, and a global
			// regex for `"version": "<semver>"` rewrites all of them to the
			// plugin's version. Only these two keys mean the plugin.
			if ( grunt.file.exists( 'package-lock.json' ) ) {
				const lock = grunt.file.readJSON( 'package-lock.json' );
				lock.version = to;
				if ( lock.packages && lock.packages[ '' ] ) {
					lock.packages[ '' ].version = to;
				}
				grunt.file.write(
					'package-lock.json',
					JSON.stringify( lock, null, '\t' ) + '\n'
				);
			}

			grunt.log.ok( 'Version ' + from + ' → ' + to );
		}
	);

	grunt.registerTask(
		'channel',
		'Select the build channel: wporg (default) or selfhosted.',
		function () {
			const channel = grunt.option( 'channel' ) || 'wporg';

			if ( 'wporg' !== channel && 'selfhosted' !== channel ) {
				grunt.fail.fatal(
					'Unknown channel: ' + channel + ' (use wporg or selfhosted)'
				);
			}

			if ( 'selfhosted' === channel ) {
				// Put the updater back by appending an un-negated pattern after
				// the exclusion — grunt-contrib-copy applies src patterns in
				// order, so the later include wins.
				//
				// The updater is the ONLY difference between the two channels,
				// and that is the invariant to protect: a self-hosted build is
				// the .org artifact plus an update check, so a customer testing
				// a build is testing what .org ships. The exclusion list above
				// asks for the vendored adapter to be re-added here too — that
				// predates the JSON-RPC transport being hardened as the one a
				// .org install will ever have, and re-adding it would make the
				// tester's transport differ from the shipped one in the exact
				// subsystem we most need field evidence about. #112 acted on
				// that comment; this reverts that half and leaves it as its own
				// decision (#113). The shim half of #112 stands.
				const files = grunt.config.get( 'copy.dist.files' );
				files[ 0 ].src.push( 'includes/class-saddle-updater.php' );
				grunt.config.set( 'copy.dist.files', files );
			}

			grunt.config.set( 'saddleChannel', channel );
			grunt.log.ok(
				'Channel: ' +
					channel +
					( 'wporg' === channel
						? ' (no self-hosted updater — .org safe)'
						: ' (self-hosted updater included — NEVER submit this zip to .org)' )
			);
		}
	);

	grunt.registerTask(
		'zip',
		'Name the archive after the current version and channel.',
		function () {
			// The self-hosted zip carries the channel in its filename so the two
			// artifacts can never be confused on disk — submitting the wrong one
			// to WordPress.org is an instant rejection.
			const suffix =
				'selfhosted' === grunt.config.get( 'saddleChannel' )
					? '-selfhosted'
					: '';
			const archive =
				'dist/' + SLUG + '-' + currentVersion() + suffix + '.zip';
			grunt.config.set( 'compress.dist.options.archive', archive );
			grunt.log.ok( 'Archive: ' + archive );
		}
	);

	grunt.registerTask( 'build', [
		'clean:dist',
		'channel',
		'copy:dist',
		'zip',
		'compress:dist',
	] );
	grunt.registerTask( 'release', [ 'version', 'build' ] );
	grunt.registerTask( 'default', [ 'build' ] );
};
