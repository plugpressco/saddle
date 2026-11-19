<?php
/**
 * How this site's own Divi pages are styled, in one short summary.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backs saddle/divi-get-site-style and the "site_style" section of
 * divi-context-bundle. Global colors, variables and presets say what the
 * site could use; this says what its pages do use, hand styling included:
 * colors, section padding, row width, type sizes, fonts, buttons, cards and
 * presets, each value with its count (Saddle_Divi_Style_Tally) and the
 * global color a color matches.
 *
 * Read from up to PAGES published Divi pages: the front page first, then
 * the most recently edited. Each page is checked for the calling account
 * (Saddle_Divi_Module_Examples::readable(): read_post, edit_post for a type
 * visitors cannot view, a password never bypassed), so the set can differ
 * by account. The counts are cached in one transient, keyed by the set's
 * page ids and its newest modified date, so an edit to one of them or a
 * new page makes a new key; saving a Divi page clears the cache.
 */
class Saddle_Divi_Site_Style {

	/**
	 * Pages read at most, and pages looked at to find them.
	 */
	const PAGES      = 6;
	const CANDIDATES = 20;

	/**
	 * The cache: up to KEEP page sets, for TTL.
	 */
	const TRANSIENT = 'saddle_divi_site_style';
	const KEEP      = 3;
	const TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * Values per list: in the tool, and at most in the bundle, whose
	 * section stays under BUNDLE_BYTES of JSON.
	 */
	const TOOL_LIMIT   = 6;
	const BUNDLE_LIMIT = 3;
	const BUNDLE_BYTES = 1536;

	/**
	 * The answer's lists, in order, by part (Saddle_Divi_Style_Tally counts
	 * each as "<part>/<list>").
	 */
	const SHAPE = array(
		'colors'  => array( 'section_backgrounds', 'card_backgrounds', 'headings', 'body_text', 'buttons', 'button_text' ),
		'spacing' => array( 'section_padding_top', 'section_padding_bottom', 'section_padding_top@phone', 'section_padding_bottom@phone', 'row_max_width' ),
		'type'    => array( 'h1', 'h1@phone', 'h2', 'h2@phone', 'h3', 'h3@phone', 'h4', 'h4@phone', 'h5', 'h5@phone', 'h6', 'h6@phone', 'body', 'body@phone', 'fonts' ),
		'buttons' => array( 'radius', 'padding', 'hover' ),
		'cards'   => array( 'radius', 'shadow', 'padding' ),
	);

	/**
	 * saddle/divi-get-site-style.
	 *
	 * @return array|WP_Error
	 */
	public static function tool() {
		if ( ! Saddle_Divi::is_active() ) {
			return new WP_Error( 'saddle_no_divi', __( 'Divi 5 is not active on this site.', 'saddle' ) );
		}
		return self::answer( self::read(), self::TOOL_LIMIT, true );
	}

	/**
	 * The bundle's section: the same summary with shorter lists, until it
	 * fits BUNDLE_BYTES. If one value per list is still too long, the least
	 * needed parts go first; pages, colors and tokens always stay.
	 *
	 * @return array
	 */
	public static function for_bundle() {
		$read = self::read();
		for ( $limit = self::BUNDLE_LIMIT; $limit >= 1; $limit-- ) {
			$answer = self::answer( $read, $limit, false );
			if ( self::fits( $answer ) ) {
				return $answer;
			}
		}
		foreach ( array( 'presets', 'cards', 'buttons', 'type', 'spacing' ) as $part ) {
			unset( $answer[ $part ] );
			$answer['cut'] = __( 'Shortened to fit. divi-get-site-style has every list.', 'saddle' );
			if ( self::fits( $answer ) ) {
				break;
			}
		}
		return $answer;
	}

	/**
	 * Whether a bundle section is within BUNDLE_BYTES of JSON.
	 *
	 * @param array $answer The section.
	 * @return bool
	 */
	private static function fits( array $answer ) {
		return strlen( (string) wp_json_encode( $answer ) ) <= self::BUNDLE_BYTES;
	}

	/**
	 * Clear the cache.
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * save_post: a saved Divi page clears the cache.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    The post.
	 */
	public static function saved( $post_id, $post ) {
		if ( $post instanceof WP_Post && 'divi5' === Saddle_Divi::post_builder( $post ) ) {
			self::flush();
		}
	}

	/**
	 * The pages and their counts, from the cache when the set is unchanged.
	 *
	 * @return array {pages: WP_Post[], counts, refs}
	 */
	private static function read() {
		$pages = self::pages();
		if ( ! $pages ) {
			return array(
				'pages'  => array(),
				'counts' => array(),
				'refs'   => array(),
			);
		}
		$key   = md5( implode( ',', wp_list_pluck( $pages, 'ID' ) ) . '|' . max( wp_list_pluck( $pages, 'post_modified_gmt' ) ) );
		$cache = get_transient( self::TRANSIENT );
		$cache = is_array( $cache ) ? $cache : array();
		if ( ! isset( $cache[ $key ] ) ) {
			$tally = new Saddle_Divi_Style_Tally();
			foreach ( $pages as $page ) {
				$tally->add( Saddle_Divi_Tree::parse( $page->post_content ) );
			}
			$cache = array( $key => $tally->result() ) + array_slice( $cache, 0, self::KEEP - 1, true );
			set_transient( self::TRANSIENT, $cache, self::TTL );
		}
		return array( 'pages' => $pages ) + $cache[ $key ];
	}

	/**
	 * The published Divi pages to read, the front page first.
	 *
	 * @return WP_Post[]
	 */
	private static function pages() {
		$query = new WP_Query(
			array(
				'post_type'           => 'page',
				'post_status'         => 'publish',
				's'                   => '<!-- wp:divi/',
				'sentence'            => true,
				'search_columns'      => array( 'post_content' ),
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'posts_per_page'      => self::CANDIDATES,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
		$posts = $query->posts;
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$front = $front > 0 ? get_post( $front ) : null;
		if ( $front instanceof WP_Post ) {
			array_unshift( $posts, $front );
		}

		$pages = array();
		foreach ( $posts as $post ) {
			if ( count( $pages ) >= self::PAGES ) {
				break;
			}
			if ( $post instanceof WP_Post && ! isset( $pages[ $post->ID ] ) && 'page' === $post->post_type && 'publish' === $post->post_status
				&& Saddle_Divi_Module_Examples::readable( $post ) && 'divi5' === Saddle_Divi::post_builder( $post ) ) {
				$pages[ $post->ID ] = $post;
			}
		}
		return array_values( $pages );
	}

	/**
	 * The summary agents read.
	 *
	 * @param array $read  read() result.
	 * @param int   $limit Values per list.
	 * @param bool  $full  Whether pages carry their title and link.
	 * @return array
	 */
	private static function answer( array $read, $limit, $full ) {
		if ( ! $read['pages'] ) {
			return array(
				'pages' => array(),
				'note'  => __( 'No published Divi page this account can read was found, so there is no site style to report yet. Use the design system instead: global colors, variables, presets and fonts.', 'saddle' ),
			);
		}

		$out = array( 'pages' => array() );
		foreach ( $read['pages'] as $page ) {
			$out['pages'][] = $full ? array(
				'id'    => $page->ID,
				'title' => get_the_title( $page ),
				'link'  => (string) get_permalink( $page ),
			) : $page->ID;
		}

		$counts = $read['counts'];
		foreach ( self::SHAPE as $part => $lists ) {
			foreach ( $lists as $list ) {
				$fact = $part . '/' . $list;
				$top  = isset( $counts[ $fact ] ) ? self::top( $counts[ $fact ], $limit ) : array();
				// A phone list that says what desktop says adds nothing.
				$desktop = '@phone' === substr( $fact, -6 ) ? substr( $fact, 0, -6 ) : '';
				if ( $top && ! ( '' !== $desktop && isset( $counts[ $desktop ] ) && array_keys( $top ) === array_keys( self::top( $counts[ $desktop ], $limit ) ) ) ) {
					$out[ $part ][ $list ] = (object) $top;
				}
			}
		}

		$tokens = self::tokens( isset( $out['colors'] ) ? $out['colors'] : array(), $read['refs'] );
		if ( $tokens ) {
			$out['tokens'] = $tokens;
		}
		$presets = isset( $counts['presets'] ) ? self::presets( self::top( $counts['presets'], $limit ) ) : array();
		if ( $presets ) {
			$out['presets'] = $presets;
		}
		$out['note'] = $full
			? __( 'What this site\'s own Divi pages use, from the pages listed: each value with how many times it is used, most used first. "default" means nothing is set, so Divi\'s own value shows. "tokens" names the global color a color matches: write the token. A value set only through a preset is not read; "presets" lists the presets used most. A "@phone" list is shown only when phones get something else than desktop.', 'saddle' )
			: __( 'Uses per value, most used first. "default": nothing set. Write a color as its "tokens" entry. divi-get-site-style has longer lists.', 'saddle' );
		return $out;
	}

	/**
	 * The $limit most used values, most used first (first seen first on a tie).
	 *
	 * @param array<string,int> $counts Uses by value.
	 * @param int               $limit  Values kept.
	 * @return array<string,int>
	 */
	private static function top( array $counts, $limit ) {
		$order = array_flip( array_map( 'strval', array_keys( $counts ) ) );
		uksort(
			$counts,
			static function ( $a, $b ) use ( $counts, $order ) {
				return $counts[ $b ] === $counts[ $a ] ? $order[ (string) $a ] - $order[ (string) $b ] : $counts[ $b ] - $counts[ $a ];
			}
		);
		return array_slice( $counts, 0, max( 1, (int) $limit ), true );
	}

	/**
	 * The global color token of each color shown: the one it was written
	 * as, else the global color whose value it matches.
	 *
	 * @param array                $colors The answer's color lists.
	 * @param array<string,string> $refs   Colors written as a token.
	 * @return array<string,string>
	 */
	private static function tokens( array $colors, array $refs ) {
		$palette = array();
		$global  = (array) Saddle_Divi_Tokens::global_colors();
		foreach ( $global as $id => $value ) {
			$hex = Saddle_Divi_Style_Values::normal( Saddle_Divi_Tokens::resolve_color( $value, $global ) );
			if ( ! isset( $palette[ $hex ] ) ) {
				$palette[ $hex ] = Saddle_Divi::css_var( $id );
			}
		}
		$tokens = array();
		foreach ( $colors as $list ) {
			foreach ( array_keys( (array) $list ) as $value ) {
				$value = (string) $value;
				if ( 0 === strpos( $value, 'var(' ) ) {
					continue;
				}
				if ( isset( $refs[ $value ] ) ) {
					$tokens[ $value ] = $refs[ $value ];
				} elseif ( isset( $palette[ $value ] ) ) {
					$tokens[ $value ] = $palette[ $value ];
				}
			}
		}
		return $tokens;
	}

	/**
	 * The presets used most, with their name and what they style when
	 * Divi's preset data can be read.
	 *
	 * @param array<string,int> $top Uses by preset id.
	 * @return array[]
	 */
	private static function presets( array $top ) {
		$known = array();
		if ( class_exists( 'Saddle_Divi_Design' ) && class_exists( Saddle_Divi_Tokens::GLOBAL_PRESET ) ) {
			$data = Saddle_Divi_Design::deep_array( call_user_func( array( Saddle_Divi_Tokens::GLOBAL_PRESET, 'get_data' ) ) );
			foreach ( Saddle_Divi_Design::presets_from( is_array( $data ) ? $data : array(), array( 'kind' => 'all' ) )['presets'] as $row ) {
				$known[ $row['id'] ] = array(
					'name' => $row['name'],
					'for'  => 'module' === $row['kind'] ? $row['module'] : $row['group_name'],
				);
			}
		}
		$out = array();
		foreach ( $top as $id => $uses ) {
			$out[] = array( 'id' => (string) $id ) + ( isset( $known[ (string) $id ] ) ? $known[ (string) $id ] : array() ) + array( 'uses' => $uses );
		}
		return $out;
	}
}
