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
 * by account. One transient holds the candidate page ids (shared: they are
 * every published Divi page, before any account's check) and the counts
 * of up to KEEP page sets, each keyed by its page ids, its newest modified
 * date, the global colors and the Divi and SaddleWP versions. It lasts up
 * to TTL and is cleared when a Divi page is saved or anything is updated.
 */
class Saddle_Divi_Site_Style {

	/**
	 * Pages read at most, and candidate pages looked at to find them.
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
	 * each as "<part>/<list>"). colors/module_backgrounds is the tool's only.
	 */
	const SHAPE = array(
		'colors'  => array( 'section_backgrounds', 'card_backgrounds', 'module_backgrounds', 'headings', 'body_text', 'buttons', 'button_text' ),
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
	 * Clear the cache.
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * save_post: saving a Divi post clears the cache; a revision or an
	 * autosave does not.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    The post.
	 */
	public static function saved( $post_id, $post ) {
		if ( $post instanceof WP_Post && ! wp_is_post_revision( $post ) && ! wp_is_post_autosave( $post )
			&& false !== strpos( (string) $post->post_content, '<!-- wp:divi/' ) ) {
			self::flush();
		}
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
	 * The pages, their counts, the palette and the preset names, read once
	 * per call; the ids and counts from the cache when it holds them.
	 *
	 * @return array {pages, counts, refs, palette, names}
	 */
	private static function read() {
		$cache  = get_transient( self::TRANSIENT );
		$cache  = is_array( $cache ) ? $cache : array();
		$stored = $cache;
		if ( ! isset( $cache['ids'] ) || ! is_array( $cache['ids'] ) ) {
			$cache['ids'] = Saddle_Divi_Module_Examples::candidate_ids( array( 'page' ), '<!-- wp:divi/', self::CANDIDATES );
		}

		$colors = Saddle_Divi_Tokens::global_colors();
		$pages  = self::pages( $cache['ids'] );
		$read   = array(
			'pages'  => $pages,
			'counts' => array(),
			'refs'   => array(),
		);
		if ( $pages ) {
			$key  = md5( implode( ',', wp_list_pluck( $pages, 'ID' ) ) . '|' . max( wp_list_pluck( $pages, 'post_modified_gmt' ) ) . '|' . md5( (string) wp_json_encode( $colors ) ) . '|' . Saddle_Divi::version() . '|' . SADDLE_VERSION );
			$sets = isset( $cache['sets'] ) && is_array( $cache['sets'] ) ? $cache['sets'] : array();
			if ( ! isset( $sets[ $key ] ) ) {
				$tally = new Saddle_Divi_Style_Tally();
				foreach ( $pages as $page ) {
					$tally->add( Saddle_Divi_Tree::parse( $page->post_content ) );
				}
				$cache['sets'] = array( $key => $tally->result() ) + array_slice( $sets, 0, self::KEEP - 1, true );
			}
			$read = array_merge( $read, $cache['sets'][ $key ] );
		}
		if ( $cache !== $stored ) {
			set_transient( self::TRANSIENT, $cache, self::TTL );
		}

		$read['palette'] = Saddle_Divi_Style_Values::palette( $colors );
		$read['names']   = isset( $read['counts']['presets'] ) ? Saddle_Divi_Style_Values::preset_names() : array();
		return $read;
	}

	/**
	 * The published Divi pages to read, the front page first, loaded one at
	 * a time until PAGES pass the account's check.
	 *
	 * @param int[] $ids Candidate page ids, newest first.
	 * @return WP_Post[]
	 */
	private static function pages( array $ids ) {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$pages = array();
		foreach ( array_unique( array_merge( $front > 0 ? array( $front ) : array(), array_map( 'intval', $ids ) ) ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status
				&& Saddle_Divi_Module_Examples::readable( $post ) && 'divi5' === Saddle_Divi::post_builder( $post ) ) {
				$pages[] = $post;
				if ( count( $pages ) >= self::PAGES ) {
					break;
				}
			}
		}
		return $pages;
	}

	/**
	 * The summary agents read.
	 *
	 * @param array $read  read() result.
	 * @param int   $limit Values per list.
	 * @param bool  $full  The tool's answer: pages with title and link, and module backgrounds.
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
				$top  = isset( $counts[ $fact ] ) && ( $full || 'colors/module_backgrounds' !== $fact ) ? self::top( $counts[ $fact ], $limit ) : array();
				// A phone list that says what desktop says adds nothing.
				$desktop = '@phone' === substr( $fact, -6 ) ? substr( $fact, 0, -6 ) : '';
				if ( $top && ! ( '' !== $desktop && isset( $counts[ $desktop ] ) && array_keys( $top ) === array_keys( self::top( $counts[ $desktop ], $limit ) ) ) ) {
					$out[ $part ][ $list ] = (object) $top;
				}
			}
		}

		$tokens = array();
		foreach ( isset( $out['colors'] ) ? $out['colors'] : array() as $list ) {
			foreach ( array_map( 'strval', array_keys( (array) $list ) ) as $value ) {
				if ( isset( $read['refs'][ $value ] ) ) {
					$tokens[ $value ] = $read['refs'][ $value ];
				} elseif ( isset( $read['palette'][ $value ] ) && 0 !== strpos( $value, 'var(' ) ) {
					$tokens[ $value ] = $read['palette'][ $value ];
				}
			}
		}
		if ( $tokens ) {
			$out['tokens'] = $tokens;
		}
		foreach ( isset( $counts['presets'] ) ? self::top( $counts['presets'], $limit ) : array() as $id => $uses ) {
			$id               = (string) $id;
			$out['presets'][] = array( 'id' => $id ) + ( isset( $read['names'][ $id ] ) ? $read['names'][ $id ] : array() ) + array( 'uses' => $uses );
		}
		$out['note'] = $full
			? __( 'What this site\'s own Divi pages use, from the pages listed: each value with how many times it is used, most used first. "default" means nothing is set, so Divi\'s own value shows. "tokens" names the global color a color matches: write the token. A value set only through a preset is not read; "presets" lists the presets used most. A "@phone" list is shown only when phones get something other than desktop.', 'saddle' )
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
}
