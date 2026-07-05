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
		const match = php.match( /Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/ );
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
							// Internal docs (never shipped to users).
							'!BUILD-GUIDE.md',
							'!MVP-PLAN.md',
							'!DESIGN-PLAN.md',
							'!PRO-PLAN.md',
							'!AGENT-CONTEXT-PLAN.md',
							'!MEMORY-PLAN.md',
							'!CLAUDE.md',
							'!README.md',
							'!admin/DESIGN-ALIGNMENT.md',
							// Dev caches.
							'!.phpunit.result.cache',
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
		'Bump the plugin version (patch|minor|major, or --to=x.y.z).',
		function ( type ) {
			const from = currentVersion();
			const to = grunt.option( 'to' ) || bump( from, type || 'patch' );

			if ( ! /^[0-9]+\.[0-9]+\.[0-9]+$/.test( to ) ) {
				grunt.fail.fatal( 'Invalid target version: ' + to );
			}

			// Main file: the plugin header Version and the SADDLE_VERSION constant.
			let php = grunt.file.read( MAIN_FILE );
			php = php.replace( /(Version:\s*)[0-9.]+/, '$1' + to );
			php = php.replace(
				/(define\(\s*'SADDLE_VERSION',\s*')[0-9.]+('\s*\))/,
				'$1' + to + '$2'
			);
			grunt.file.write( MAIN_FILE, php );

			// readme.txt: Stable tag.
			if ( grunt.file.exists( 'readme.txt' ) ) {
				let readme = grunt.file.read( 'readme.txt' );
				readme = readme.replace( /(Stable tag:\s*)[0-9.]+/, '$1' + to );
				grunt.file.write( 'readme.txt', readme );
			}

			// package.json: version.
			const pkg = grunt.file.readJSON( 'package.json' );
			pkg.version = to;
			grunt.file.write(
				'package.json',
				JSON.stringify( pkg, null, '\t' ) + '\n'
			);

			grunt.log.ok( 'Version ' + from + ' → ' + to );
		}
	);

	grunt.registerTask(
		'zip',
		'Name the archive after the current version.',
		function () {
			const archive = 'dist/' + SLUG + '-' + currentVersion() + '.zip';
			grunt.config.set( 'compress.dist.options.archive', archive );
			grunt.log.ok( 'Archive: ' + archive );
		}
	);

	grunt.registerTask( 'build', [
		'clean:dist',
		'copy:dist',
		'zip',
		'compress:dist',
	] );
	grunt.registerTask( 'release', [ 'version', 'build' ] );
	grunt.registerTask( 'default', [ 'build' ] );
};
