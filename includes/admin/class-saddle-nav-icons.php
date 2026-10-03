<?php
/**
 * Iconoir icons for the Saddle menu and tabs.
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
	 * File contents already read, by name.
	 *
	 * @var array<string,string>
	 */
	private static $markup = array();

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

	/**
	 * An icon's markup, ready to sit before a label: sized, hidden from
	 * assistive technology (the label carries the name) and classed for the
	 * menu's stylesheet.
	 *
	 * @param string $name Icon name.
	 * @param int    $size Pixel size.
	 * @return string `<svg>` markup, or '' for an unknown name.
	 */
	public static function svg( $name, $size = 16 ) {
		$name = self::pick( $name );
		if ( '' === $name ) {
			return '';
		}

		if ( ! isset( self::$markup[ $name ] ) ) {
			$file = SADDLE_DIR . self::DIR . $name . '.svg';
			$svg  = file_exists( $file )
				? file_get_contents( $file ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file, not a remote request.
				: false;

			self::$markup[ $name ] = is_string( $svg ) ? trim( $svg ) : '';
		}

		if ( 0 !== strpos( self::$markup[ $name ], '<svg' ) ) {
			return '';
		}

		$size = max( 1, absint( $size ) );

		return '<svg class="saddle-nav-icon" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false"' . substr( self::$markup[ $name ], 4 );
	}

	/**
	 * The menu's stylesheet: the icon beside each label, and the hairline
	 * between the products and Core's configuration pages.
	 *
	 * @return string CSS.
	 */
	public static function menu_css() {
		return '#adminmenu .saddle-nav-icon{display:inline-block;width:16px;height:16px;margin-inline-end:6px;vertical-align:-4px;flex:none}'
			. '#adminmenu li.saddle-menu-group{box-shadow:inset 0 1px 0 rgba(128,128,128,.3);margin-top:6px;padding-top:6px}';
	}
}
