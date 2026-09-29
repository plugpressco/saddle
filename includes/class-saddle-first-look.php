<?php
/**
 * First look: what Saddle can tell the owner about their site before any app
 * is connected. It feeds the first-run screen.
 *
 * Everything here is read locally, from data WordPress already holds: options,
 * post counts and the update transients. It never refreshes the update check
 * and never makes an HTTP request. Every lookup is a count that fetches at
 * most one ID.
 *
 * A class of its own rather than more of Saddle_Rest: this is site knowledge,
 * callable and testable without a request, and the REST route is one line.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the first-run summary.
 */
class Saddle_First_Look {

	/**
	 * Yoast's per-post description key.
	 */
	const YOAST_DESCRIPTION = '_yoast_wpseo_metadesc';

	/**
	 * Rank Math's per-post description key.
	 */
	const RANK_MATH_DESCRIPTION = 'rank_math_description';

	/**
	 * The whole summary.
	 *
	 * @return array
	 */
	public static function summary() {
		$seo = self::seo_plugin();

		return array(
			'site'     => self::site(),
			'content'  => self::content(),
			'seo'      => $seo,
			'updates'  => self::updates(),
			'findings' => array(
				'missing_alt'         => self::count_images_without_alt(),
				'missing_description' => self::count_without_description( $seo ),
			),
		);
	}

	/**
	 * Name, versions, theme and how pages are built.
	 *
	 * @return array
	 */
	private static function site() {
		$block_theme = wp_is_block_theme();

		if ( Saddle_Divi::is_active() ) {
			$builder = 'divi5';
		} elseif ( $block_theme ) {
			$builder = 'blocks';
		} else {
			$builder = 'classic';
		}

		return array(
			'name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'wp_version' => get_bloginfo( 'version' ),
			'theme'      => wp_get_theme()->get( 'Name' ),
			'builder'    => $builder,
		);
	}

	/**
	 * Published pages and posts, and the media library size.
	 *
	 * @return array
	 */
	private static function content() {
		$pages = wp_count_posts( 'page' );
		$posts = wp_count_posts( 'post' );
		$media = wp_count_posts( 'attachment' );

		return array(
			'pages' => isset( $pages->publish ) ? (int) $pages->publish : 0,
			'posts' => isset( $posts->publish ) ? (int) $posts->publish : 0,
			'media' => isset( $media->inherit ) ? (int) $media->inherit : 0,
		);
	}

	/**
	 * The active SEO plugin Saddle can edit through, if any.
	 *
	 * @return string|null One of yoast, rank-math, aioseo; null for none.
	 */
	private static function seo_plugin() {
		if ( Saddle_Yoast::is_active() ) {
			return 'yoast';
		}
		if ( Saddle_Rank_Math::is_active() ) {
			return 'rank-math';
		}
		if ( Saddle_Aioseo::is_active() ) {
			return 'aioseo';
		}
		return null;
	}

	/**
	 * Updates WordPress already knows about, as the Dashboard counts them.
	 *
	 * `wp_get_update_data()` reads the update transients and applies the
	 * current user's capabilities; it never contacts WordPress.org. Under
	 * DISALLOW_FILE_MODS it reports zero, which matches what Saddle can do.
	 *
	 * @return array
	 */
	private static function updates() {
		$data = wp_get_update_data();

		return array(
			'plugins' => isset( $data['counts']['plugins'] ) ? (int) $data['counts']['plugins'] : 0,
			'themes'  => isset( $data['counts']['themes'] ) ? (int) $data['counts']['themes'] : 0,
		);
	}

	/**
	 * Images in the media library with no alt text.
	 *
	 * @return int
	 */
	private static function count_images_without_alt() {
		return self::count(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'meta_query'     => self::empty_meta( '_wp_attachment_image_alt' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-off count on the first-run screen, one ID fetched.
			)
		);
	}

	/**
	 * Published pages and posts with no search description of their own.
	 *
	 * Only for the SEO plugins that keep it in post meta. AIOSEO keeps it in
	 * its own table, so it reports null (not counted) rather than a guess.
	 *
	 * @param string|null $seo The active SEO plugin.
	 * @return int|null
	 */
	public static function count_without_description( $seo ) {
		if ( 'yoast' === $seo ) {
			$key = self::YOAST_DESCRIPTION;
		} elseif ( 'rank-math' === $seo ) {
			$key = self::RANK_MATH_DESCRIPTION;
		} else {
			return null;
		}

		return self::count(
			array(
				'post_type'   => array( 'post', 'page' ),
				'post_status' => 'publish',
				'meta_query'  => self::empty_meta( $key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-off count on the first-run screen, one ID fetched.
			)
		);
	}

	/**
	 * A meta query matching a key that is missing or empty.
	 *
	 * @param string $key Meta key.
	 * @return array
	 */
	private static function empty_meta( $key ) {
		return array(
			'relation' => 'OR',
			array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => $key,
				'value'   => '',
				'compare' => '=',
			),
		);
	}

	/**
	 * How many posts match, fetching one ID at most.
	 *
	 * @param array $args WP_Query arguments.
	 * @return int
	 */
	private static function count( array $args ) {
		$query = new WP_Query(
			array_merge(
				$args,
				array(
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'no_found_rows'          => false,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
				)
			)
		);

		return (int) $query->found_posts;
	}
}
