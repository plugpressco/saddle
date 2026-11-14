<?php
/**
 * The real front-end HTML and Divi CSS of one page, read by the site itself.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Asks the site for its own signed preview of a post (Saddle_Preview), the
 * same page an agent would open in its browser, and returns the content
 * region and the CSS Divi printed for it.
 *
 * Why a request to the site itself and not a render inside this request:
 * tested on Divi 5.10.1 (WordPress Playground, 2026-11-14), the_content run
 * inside a REST request gives different layout classes (et_flex_section,
 * et_flex_row) than the front end prints for the same post (et_block_section,
 * et_block_row, et_pb_column_4_4). The front end is the truth, so the tool
 * reads the front end. A preview forces Divi to print the page's module CSS
 * inline (StaticCSS::setup_styles_manager(): is_preview() forces inline, under
 * a "-preview" resource name, so saved static CSS files are left alone),
 * which is why one response carries both.
 *
 * Only this site's own address is ever fetched (Saddle_HTTP::fetch_own_page(),
 * same scheme, host and port as home_url(), no cookies), and the signed URL
 * never leaves this request. A host that blocks requests to itself returns
 * an error that points at get-preview-url.
 */
class Saddle_Divi_Rendered_Page {

	/**
	 * Default and largest sizes of the two parts, in KB.
	 */
	const HTML_KB     = 48;
	const HTML_KB_MAX = 256;
	const CSS_KB      = 24;
	const CSS_KB_MAX  = 128;

	/**
	 * Style blocks Divi prints for the page and the site's design tokens
	 * (ids or classes starting with these). Read from a Divi 5.10.1 preview.
	 */
	const CSS_SOURCES = array( 'et-vb-global-data', 'et-divi-customizer', 'et-core-unified', 'et-builder-module-design' );

	/**
	 * Fetch and trim the rendered page.
	 *
	 * @param WP_Post $post   The post.
	 * @param int     $html_kb HTML cap in KB.
	 * @param int     $css_kb  CSS cap in KB.
	 * @return array|WP_Error
	 */
	public static function read( WP_Post $post, $html_kb = self::HTML_KB, $css_kb = self::CSS_KB ) {
		$fetched = Saddle_HTTP::fetch_own_page( self::preview_url( $post ) );
		if ( is_wp_error( $fetched ) || $fetched['status'] < 200 || $fetched['status'] >= 300 ) {
			return new WP_Error(
				'saddle_render_unreachable',
				sprintf(
					/* translators: %s: the reason the request failed. */
					__( 'This site could not load its own preview of the page (%s). Some hosts block a site from requesting itself. Open saddle/get-preview-url in your own browser instead.', 'saddle' ),
					is_wp_error( $fetched )
						? $fetched->get_error_message()
						/* translators: %d: HTTP status code. */
						: sprintf( __( 'HTTP %d', 'saddle' ), $fetched['status'] )
				)
			);
		}
		return self::extract( (string) $fetched['body'], $html_kb, $css_kb );
	}

	/**
	 * The signed preview address the site asks itself for. A published
	 * post's ?page_id=…&preview=1 link is sent on by WordPress's canonical
	 * redirect to the permalink without "preview" (seen on WordPress
	 * 6.9.7), and Divi then prints its styles as files, not inline. So a
	 * published post is asked for at its permalink with the preview
	 * arguments added.
	 *
	 * @param WP_Post $post The post.
	 * @return string
	 */
	public static function preview_url( WP_Post $post ) {
		$url = Saddle_Preview::mint( $post )['url'];
		if ( 'publish' !== $post->post_status ) {
			return $url;
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return add_query_arg(
			array(
				'preview'                 => 1,
				Saddle_Preview::QUERY_ARG => isset( $args[ Saddle_Preview::QUERY_ARG ] ) ? $args[ Saddle_Preview::QUERY_ARG ] : '',
			),
			get_permalink( $post )
		);
	}

	/**
	 * Cut a front-end page down to its content region and Divi's CSS.
	 *
	 * @param string $page    Full HTML response.
	 * @param int    $html_kb HTML cap in KB.
	 * @param int    $css_kb  CSS cap in KB.
	 * @return array|WP_Error
	 */
	public static function extract( $page, $html_kb = self::HTML_KB, $css_kb = self::CSS_KB ) {
		$html_kb = max( 1, min( self::HTML_KB_MAX, (int) $html_kb ) );
		$css_kb  = max( 1, min( self::CSS_KB_MAX, (int) $css_kb ) );

		$region = null;
		foreach ( array( 'et-l--post', 'entry-content' ) as $class ) {
			$region = self::element_by_class( $page, $class );
			if ( null !== $region ) {
				$region['class'] = $class;
				break;
			}
		}
		if ( null === $region ) {
			return new WP_Error( 'saddle_render_no_region', __( 'The page loaded, but its content area was not found (no et-l--post or entry-content element). The theme may not print one. Open saddle/get-preview-url in your own browser instead.', 'saddle' ) );
		}

		$html    = $region['html'];
		$scripts = preg_match_all( '#<script\b[^>]*>.*?</script>#is', $html );
		$html    = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$inner   = array();
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $m ) ) {
			$inner = $m[1];
			$html  = (string) preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
		}
		$html = trim( (string) preg_replace( '/\n\s*\n+/', "\n", $html ) );

		$blocks = self::css_blocks( $page );
		foreach ( $inner as $css ) {
			$blocks[] = array(
				'source' => 'inside-content',
				'css'    => trim( $css ),
			);
		}

		$cut_html = self::cut( $html, $html_kb * 1024 );
		$css      = self::fit_css( $blocks, $css_kb * 1024 );

		$result = array(
			'region'             => $region['class'],
			'html'               => $cut_html,
			'html_kb'            => round( strlen( $html ) / 1024, 1 ),
			'html_truncated'     => strlen( $cut_html ) < strlen( $html ),
			'css'                => $css['blocks'],
			'css_kb'             => round( $css['total'] / 1024, 1 ),
			'css_truncated'      => $css['cut'],
			'linked_stylesheets' => self::linked_stylesheets( $page ),
			'removed'            => array( 'scripts' => (int) $scripts ),
		);
		if ( ! $css['blocks'] ) {
			$result['css_note'] = __( 'No Divi style block was found on the page. The page may set no styles of its own, or this Divi version prints them under other names.', 'saddle' );
		}
		return $result;
	}

	/**
	 * The first element carrying a class, with its whole subtree, found by
	 * balancing its tag.
	 *
	 * @param string $page  HTML.
	 * @param string $name Class name.
	 * @return array|null { html } or null.
	 */
	private static function element_by_class( $page, $name ) {
		if ( ! preg_match( '#<(div|main|section|article)\b[^>]*\bclass=(["\'])(?:[^"\']*\s)?' . preg_quote( $name, '#' ) . '(?:\s[^"\']*)?\2[^>]*>#i', $page, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$tag   = strtolower( $m[1][0] );
		$start = $m[0][1];
		$depth = 0;
		if ( ! preg_match_all( '#<(/?)' . $tag . '\b[^>]*>#i', $page, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $start ) ) {
			return null;
		}
		foreach ( $tags as $found ) {
			$depth += '/' === $found[1][0] ? -1 : 1;
			if ( 0 === $depth ) {
				$end = $found[0][1] + strlen( $found[0][0] );
				return array( 'html' => substr( $page, $start, $end - $start ) );
			}
		}
		return null;
	}

	/**
	 * Divi's style blocks, in page order.
	 *
	 * @param string $page HTML.
	 * @return array[] Each { source, css }.
	 */
	private static function css_blocks( $page ) {
		$blocks = array();
		if ( ! preg_match_all( '#<style\b([^>]*)>(.*?)</style>#is', $page, $m, PREG_SET_ORDER ) ) {
			return $blocks;
		}
		foreach ( $m as $style ) {
			if ( ! preg_match( '#\b(?:id|class)=(["\'])([^"\']*)\1#i', $style[1], $name ) ) {
				continue;
			}
			foreach ( self::CSS_SOURCES as $prefix ) {
				if ( preg_match( '#(?:^|\s)' . preg_quote( $prefix, '#' ) . '#', $name[2] ) ) {
					$blocks[] = array(
						'source' => trim( $name[2] ),
						'css'    => trim( $style[2] ),
					);
					break;
				}
			}
		}
		return $blocks;
	}

	/**
	 * Fit the CSS blocks into the budget. The page's own module styles get
	 * the budget first, then the site's variables, then the customizer; the
	 * blocks kept come back in page order, which is the cascade order.
	 *
	 * @param array[] $blocks Each { source, css }, in page order.
	 * @param int     $budget Bytes.
	 * @return array { blocks, total, cut }.
	 */
	private static function fit_css( array $blocks, $budget ) {
		$rank = array_flip( array( 'et-builder-module-design', 'inside-content', 'et-core-unified', 'et-vb-global-data', 'et-divi-customizer' ) );
		$by   = array();
		foreach ( $blocks as $index => $block ) {
			$priority = count( $rank );
			foreach ( $rank as $prefix => $position ) {
				if ( 0 === strpos( $block['source'], $prefix ) ) {
					$priority = $position;
					break;
				}
			}
			$by[] = array( $priority, $index, $block );
		}
		sort( $by );

		$kept  = array();
		$total = 0;
		$cut   = false;
		foreach ( $by as $entry ) {
			list( , $index, $block ) = $entry;
			$total                  += strlen( $block['css'] );
			$css                     = $budget > 0 ? self::cut( $block['css'], $budget, '}' ) : '';
			$cut                     = $cut || strlen( $css ) < strlen( $block['css'] );
			$budget                 -= strlen( $css );
			if ( '' !== $css ) {
				$kept[ $index ] = array(
					'source' => $block['source'],
					'css'    => $css,
				);
			}
		}
		ksort( $kept );
		return array(
			'blocks' => array_values( $kept ),
			'total'  => $total,
			'cut'    => $cut,
		);
	}

	/**
	 * Cut text to a byte budget at the last boundary character before it.
	 *
	 * @param string $text   Text.
	 * @param int    $budget Bytes.
	 * @param string $after  Boundary character kept at the cut.
	 * @return string
	 */
	private static function cut( $text, $budget, $after = '>' ) {
		if ( strlen( $text ) <= $budget ) {
			return $text;
		}
		$head = substr( $text, 0, $budget );
		$at   = strrpos( $head, $after );
		return false === $at ? '' : substr( $head, 0, $at + 1 );
	}

	/**
	 * The ids of the stylesheets the page links, so the agent knows what
	 * else applies (their content is not returned).
	 *
	 * @param string $page HTML.
	 * @return string[]
	 */
	private static function linked_stylesheets( $page ) {
		$ids = array();
		if ( preg_match_all( '#<link\b[^>]*rel=(["\'])stylesheet\1[^>]*>#i', $page, $m ) ) {
			foreach ( $m[0] as $link ) {
				if ( preg_match( '#\bid=(["\'])([^"\']+)\1#', $link, $id ) ) {
					$ids[] = $id[2];
				}
			}
		}
		return $ids;
	}
}
