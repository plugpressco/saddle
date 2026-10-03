<?php
/**
 * The pages inside a module's sections: its icon tab row.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A module page has four levels, one control each (planning/MODULE-LAYOUT.md):
 * the WordPress Saddle submenu picks the module, the left sidebar picks a
 * section (a `tabs` key, `&tab=`), the icon tab row picks a page inside the
 * section (a `subtabs` key, `&sub=`), and a drill-in opens one item
 * (`&view=`).
 *
 * This class cleans a descriptor's `subtabs` and `subtab_icons`, resolves a
 * requested page and builds the address of a section and page. It holds no
 * state; Saddle_Modules calls it while it builds the area list.
 */
class Saddle_Module_Nav {

	/**
	 * A descriptor's pages, cleaned against the module's sections.
	 *
	 * Keys go through `sanitize_key` and a label must be a non-empty string.
	 * Pages of a section that does not exist are dropped, and a section keeps
	 * its pages only when two or more survive: one page is the section.
	 *
	 * @param array $tabs  Section key => label, already cleaned.
	 * @param mixed $given The descriptor's `subtabs`: section key => ( page key => label ).
	 * @return array<string,array<string,string>> Section key => ( page key => label ).
	 */
	public static function subtabs( array $tabs, $given ) {
		$out = array();
		if ( ! is_array( $given ) ) {
			return $out;
		}

		foreach ( $given as $tab => $pages ) {
			$tab = sanitize_key( (string) $tab );
			if ( '' === $tab || ! isset( $tabs[ $tab ] ) || ! is_array( $pages ) ) {
				continue;
			}

			$clean = array();
			foreach ( $pages as $sub => $label ) {
				$sub = sanitize_key( (string) $sub );
				if ( '' !== $sub && is_string( $label ) && '' !== trim( $label ) ) {
					$clean[ $sub ] = $label;
				}
			}

			if ( count( $clean ) >= 2 ) {
				$out[ $tab ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * The icon of each page: the one the module named, or the default for the
	 * page's key (`overview`, `settings`). An unknown name draws nothing.
	 *
	 * @param array $subtabs Cleaned pages, from subtabs().
	 * @param mixed $given   The descriptor's `subtab_icons`: section key => ( page key => icon ).
	 * @return array<string,array<string,string>> Section key => ( page key => icon ); a page with no icon has no entry.
	 */
	public static function icons( array $subtabs, $given ) {
		$given = is_array( $given ) ? $given : array();
		$out   = array();

		foreach ( $subtabs as $tab => $pages ) {
			$icons = Saddle_Nav_Icons::tab_icons( $pages, isset( $given[ $tab ] ) ? $given[ $tab ] : array() );
			if ( $icons ) {
				$out[ $tab ] = $icons;
			}
		}

		return $out;
	}

	/**
	 * The pages of one section of an area: page key => label, or none.
	 *
	 * @param array  $area An area from Saddle_Modules::areas().
	 * @param string $tab  Section key.
	 * @return array<string,string>
	 */
	public static function pages( array $area, $tab ) {
		return isset( $area['subtabs'][ $tab ] ) && is_array( $area['subtabs'][ $tab ] ) ? $area['subtabs'][ $tab ] : array();
	}

	/**
	 * A page of a section. An empty or unknown page falls back to the first
	 * one, so an old or mistyped link still lands; a section with no pages
	 * has none ('').
	 *
	 * @param array  $area An area from Saddle_Modules::areas().
	 * @param string $tab  Resolved section key.
	 * @param string $sub  Requested page.
	 * @return string
	 */
	public static function resolve( array $area, $tab, $sub ) {
		$pages = self::pages( $area, $tab );
		if ( ! $pages ) {
			return '';
		}

		return isset( $pages[ $sub ] ) ? (string) $sub : (string) array_key_first( $pages );
	}

	/**
	 * The admin URL of an area, a section in it and a page in that. The
	 * first section has no `&tab=` and the first page no `&sub=`, so each
	 * screen has one canonical address.
	 *
	 * @param array  $area An area from Saddle_Modules::areas().
	 * @param string $tab  Section key; '' or unknown is the first.
	 * @param string $sub  Page key; '' or unknown is the first.
	 * @return string
	 */
	public static function url( array $area, $tab = '', $sub = '' ) {
		$tabs  = isset( $area['tabs'] ) && is_array( $area['tabs'] ) ? $area['tabs'] : array();
		$first = (string) array_key_first( $tabs );
		$tab   = '' !== $tab && isset( $tabs[ $tab ] ) ? (string) $tab : $first;
		$args  = array( 'page' => $area['slug'] );

		if ( '' !== $tab && $first !== $tab ) {
			$args['tab'] = $tab;
		}

		$pages = self::pages( $area, $tab );
		if ( '' !== $sub && isset( $pages[ $sub ] ) && (string) array_key_first( $pages ) !== $sub ) {
			$args['sub'] = $sub;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The pages of a section as the admin app draws them in the icon tab row.
	 *
	 * @param array  $area An area from Saddle_Modules::areas().
	 * @param string $tab  Section key.
	 * @return array[] `[ { key, label, url, icon } ]`, or none.
	 */
	public static function for_app( array $area, $tab ) {
		$out = array();
		foreach ( self::pages( $area, $tab ) as $sub => $label ) {
			$out[] = array(
				'key'   => $sub,
				'label' => $label,
				'url'   => esc_url_raw( self::url( $area, $tab, $sub ) ),
				'icon'  => isset( $area['subtab_icons'][ $tab ][ $sub ] ) ? $area['subtab_icons'][ $tab ][ $sub ] : '',
			);
		}

		return $out;
	}

	/**
	 * The arguments a module's own link may carry: every key but Core's
	 * (`page`, `tab`, `sub`, `view`), each a scalar, as text.
	 *
	 * @param mixed $args Raw arguments.
	 * @return array<string,string>
	 */
	public static function clean_args( $args ) {
		$out = array();
		foreach ( is_array( $args ) ? $args : array() as $name => $value ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name || in_array( $name, array( 'page', 'tab', 'sub', 'view' ), true ) || ! is_scalar( $value ) ) {
				continue;
			}
			$out[ $name ] = sanitize_text_field( (string) $value );
		}

		return $out;
	}
}
