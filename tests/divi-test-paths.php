<?php
/**
 * Where the Divi 5 files some tests read live.
 *
 * SADDLE_TEST_DIVI_COMPONENTS: Divi's module-library components folder
 * (the theme's includes/builder-5/visual-builder/packages/module-library/src/components).
 * SADDLE_TEST_DIVI_PACK: a module pack's modules-json folder.
 * Without them the local default below is used; a test whose folder is
 * missing is skipped.
 *
 * @package Saddle
 */

class Saddle_Test_Divi_Paths {

	/**
	 * The local wp-content holding Divi and a module pack, used when the
	 * environment names no folder.
	 */
	const LOCAL_CONTENT = '/Users/fahim/Workspace/wp/divi-dev/wp-content';

	/**
	 * Divi's components folder, or '' when it is not on this machine.
	 *
	 * @return string
	 */
	public static function components() {
		return self::dir( 'SADDLE_TEST_DIVI_COMPONENTS', self::LOCAL_CONTENT . '/themes/Divi/includes/builder-5/visual-builder/packages/module-library/src/components' );
	}

	/**
	 * A module pack's modules-json folder, or '' when it is not on this machine.
	 *
	 * @return string
	 */
	public static function pack() {
		return self::dir( 'SADDLE_TEST_DIVI_PACK', self::LOCAL_CONTENT . '/plugins/divi-torque-pro/modules-json' );
	}

	/**
	 * Divi's components folder, or skip the running test.
	 *
	 * @return string
	 */
	public static function components_or_skip() {
		$dir = self::components();
		if ( '' === $dir ) {
			PHPUnit\Framework\Assert::markTestSkipped( 'Divi 5 module.json files are not on this machine (set SADDLE_TEST_DIVI_COMPONENTS).' );
		}
		return $dir;
	}

	/**
	 * A module pack's folder, or skip the running test.
	 *
	 * @return string
	 */
	public static function pack_or_skip() {
		$dir = self::pack();
		if ( '' === $dir ) {
			PHPUnit\Framework\Assert::markTestSkipped( 'No Divi 5 module pack on this machine (set SADDLE_TEST_DIVI_PACK).' );
		}
		return $dir;
	}

	/**
	 * The folder an environment variable names, else the default; '' when
	 * neither exists.
	 *
	 * @param string $name    Environment variable.
	 * @param string $default Local default.
	 * @return string
	 */
	private static function dir( $name, $default ) {
		$dir = getenv( $name );
		$dir = is_string( $dir ) && '' !== $dir ? $dir : $default;
		return is_dir( $dir ) ? rtrim( $dir, '/' ) : '';
	}
}
