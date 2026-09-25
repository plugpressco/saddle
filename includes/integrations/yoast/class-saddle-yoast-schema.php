<?php
/**
 * Yoast SEO — per-post schema type overrides.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free Yoast's schema surface is two fields on a post — the page type and
 * (when the effective type is an Article variant) the article type — not
 * the full custom schema graph Rank Math Pro-style tools expose. Both
 * fields are validated by Yoast itself against its own fixed option lists
 * (confirmed on a real Yoast SEO 28.3 install: page_type does NOT accept
 * "Article" — a 'post' defaults to Article implicitly, refined by
 * article_type; page_type is for overriding to something else, e.g.
 * "WebPage", "FAQPage", "AboutPage"). An out-of-list value is silently
 * discarded by Yoast's own sanitizer, so this class always re-reads after
 * write and reports the value Yoast actually kept — never the raw input —
 * the same applied-vs-ignored honesty Divi's write abilities hold to.
 */
class Saddle_Yoast_Schema {

	/**
	 * Friendly field name => WPSEO_Meta key.
	 *
	 * @var array<string,string>
	 */
	const FIELD_MAP = array(
		'page_type'    => 'schema_page_type',
		'article_type' => 'schema_article_type',
	);

	/**
	 * saddle/yoast-get-post-schema.
	 *
	 * @param WP_Post $post Target post.
	 * @return array
	 */
	public static function get_post_schema( WP_Post $post ) {
		$out = array();
		foreach ( self::FIELD_MAP as $friendly => $yoast_key ) {
			$out[ $friendly ] = (string) WPSEO_Meta::get_value( $yoast_key, $post->ID );
		}
		return $out;
	}

	/**
	 * saddle/yoast-edit-post-schema. Partial merge; empty string resets a
	 * field to Yoast's site default. "changed" is re-read after write, so
	 * an out-of-list value Yoast declined to persist is reported honestly
	 * as unchanged rather than echoed back as if it took effect.
	 *
	 * @param WP_Post $post  Target post.
	 * @param array   $input Ability input.
	 * @return array|WP_Error {changed}
	 */
	public static function set_post_schema( WP_Post $post, array $input ) {
		$touched = array();

		foreach ( self::FIELD_MAP as $friendly => $yoast_key ) {
			if ( ! array_key_exists( $friendly, $input ) ) {
				continue;
			}
			WPSEO_Meta::set_value( $yoast_key, sanitize_text_field( (string) $input[ $friendly ] ), $post->ID );
			$touched[] = $friendly;
		}

		if ( ! $touched ) {
			return new WP_Error( 'saddle_empty', __( 'Provide "page_type" and/or "article_type" to change.', 'saddle' ) );
		}

		$current = self::get_post_schema( $post );
		return array( 'changed' => array_intersect_key( $current, array_flip( $touched ) ) );
	}
}
