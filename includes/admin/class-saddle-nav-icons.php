<?php
/**
 * Iconoir icon names for module tabs and sidebars.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The family's icons, read from `assets/icons/`.
 *
 * The files are Iconoir 7.12.1 (MIT), copied by `npm run icons`
 * (scripts/icons.mjs) with `width` and `height` removed. The folder is the
 * allowlist: a module descriptor's `icon` and `tab_icons` may only name a
 * file in it, and any other name draws no icon.
 */
class Saddle_Nav_Icons {

	/**
	 * Where the icons live, under the plugin folder.
	 */
	const DIR = 'assets/icons/';

	/**
	 * The icon a tab gets by its key when the module names none.
	 */
	const TAB_DEFAULTS = array(
		'overview' => 'dashboard-dots',
		'settings' => 'settings',
	);

	/**
	 * The names in the folder, once read.
	 *
	 * @var string[]|null
	 */
	private static $names = null;

	/**
	 * Every icon name: the files in assets/icons/, without `.svg`, sorted.
	 *
	 * @return string[]
	 */
	public static function names() {
		if ( null === self::$names ) {
			$files = glob( SADDLE_DIR . self::DIR . '*.svg' );
			$names = array();
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				$names[] = basename( $file, '.svg' );
			}
			sort( $names );
			self::$names = $names;
		}

		return self::$names;
	}

	/**
	 * A name from a descriptor, cleaned and checked against the allowlist.
	 *
	 * @param mixed $name Raw name.
	 * @return string The name, or '' when there is no such icon.
	 */
	public static function pick( $name ) {
		if ( ! is_string( $name ) ) {
			return '';
		}

		$name = sanitize_key( $name );

		return in_array( $name, self::names(), true ) ? $name : '';
	}

	/**
	 * The icon of each tab: the one the module named, or the default for the
	 * tab's key. A tab with neither has no entry.
	 *
	 * @param array $tabs  Tab key => label.
	 * @param mixed $given Tab key => icon name, from the descriptor.
	 * @return array<string,string>
	 */
	public static function tab_icons( array $tabs, $given ) {
		$given = is_array( $given ) ? $given : array();
		$icons = array();

		foreach ( array_keys( $tabs ) as $tab ) {
			$icon = isset( $given[ $tab ] ) ? self::pick( $given[ $tab ] ) : '';
			if ( '' === $icon && isset( self::TAB_DEFAULTS[ $tab ] ) ) {
				$icon = self::pick( self::TAB_DEFAULTS[ $tab ] );
			}
			if ( '' !== $icon ) {
				$icons[ $tab ] = $icon;
			}
		}

		return $icons;
	}
}
