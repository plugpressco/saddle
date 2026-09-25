<?php
/**
 * Rank Math — per-post and per-term SEO field read/write.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes Rank Math's own `rank_math_*` post/term meta. Unlike
 * Yoast there is no API layer or indexable cache in the way — plain
 * (non-underscore-prefixed) meta IS Rank Math's storage path, read back by
 * its own Helper::get_post_meta at render time (verify live on divi-dev per
 * the standing rule before trusting this comment). The robots array is the
 * one trap, handled entirely in Saddle_Rank_Math::robots_read/merge —
 * this class never touches the tokens directly.
 *
 * "changed" is always re-read after write, never echoed from input — the
 * same applied-vs-ignored honesty as the Yoast and Divi writers.
 */
class Saddle_Rank_Math_Seo {

	/**
	 * Friendly field name => rank_math_* meta key — the scalar text fields
	 * shared by the post and term shapes. Friendly names match the Yoast
	 * integration on purpose (og_* / twitter_*), so an agent that learned
	 * one SEO integration can drive the next without a new vocabulary.
	 *
	 * @var array<string,string>
	 */
	const TEXT_FIELD_MAP = array(
		'title'               => 'rank_math_title',
		'description'         => 'rank_math_description',
		'focus_keyword'       => 'rank_math_focus_keyword',
		'canonical'           => 'rank_math_canonical_url',
		'og_title'            => 'rank_math_facebook_title',
		'og_description'      => 'rank_math_facebook_description',
		'og_image'            => 'rank_math_facebook_image',
		'twitter_title'       => 'rank_math_twitter_title',
		'twitter_description' => 'rank_math_twitter_description',
		'twitter_image'       => 'rank_math_twitter_image',
	);

	/**
	 * saddle/rank-math-get-post-seo.
	 *
	 * @param WP_Post $post Target post.
	 * @return array
	 */
	public static function get_post_seo( WP_Post $post ) {
		$out = array();
		foreach ( self::TEXT_FIELD_MAP as $friendly => $meta_key ) {
			$out[ $friendly ] = (string) get_post_meta( $post->ID, $meta_key, true );
		}

		$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
		$out    = array_merge( $out, Saddle_Rank_Math::robots_read( is_array( $robots ) ? $robots : array() ) );

		$out['pillar_content']   = 'on' === (string) get_post_meta( $post->ID, 'rank_math_pillar_content', true );
		$out['primary_category'] = is_object_in_taxonomy( $post->post_type, 'category' )
			? (int) get_post_meta( $post->ID, 'rank_math_primary_category', true )
			: null;

		return $out;
	}

	/**
	 * saddle/rank-math-edit-post-seo. Partial merge — only keys present in
	 * $input change; an empty string deletes the meta so Rank Math falls
	 * back to its own template/default for that field.
	 *
	 * @param WP_Post $post  Target post.
	 * @param array   $input Ability input (already capability-checked by the caller).
	 * @return array|WP_Error {changed, warnings}
	 */
	public static function set_post_seo( WP_Post $post, array $input ) {
		$touched = array();

		foreach ( self::TEXT_FIELD_MAP as $friendly => $meta_key ) {
			if ( ! array_key_exists( $friendly, $input ) ) {
				continue;
			}
			self::write_meta( $post->ID, $meta_key, sanitize_text_field( (string) $input[ $friendly ] ) );
			$touched[] = $friendly;
		}

		// Twitter fields only render when the "use Facebook data" toggle is
		// off — writing a twitter value while leaving the toggle on would be
		// a silent no-op (verify live), so the write flips it.
		if ( array_intersect( array( 'twitter_title', 'twitter_description', 'twitter_image' ), $touched ) ) {
			update_post_meta( $post->ID, 'rank_math_twitter_use_facebook', 'off' );
		}

		if ( array_key_exists( 'robots_index', $input ) || array_key_exists( 'robots_follow', $input ) || array_key_exists( 'robots_advanced', $input ) ) {
			$stored = get_post_meta( $post->ID, 'rank_math_robots', true );
			$merged = Saddle_Rank_Math::robots_merge( is_array( $stored ) ? $stored : array(), $input );
			if ( is_wp_error( $merged ) ) {
				return $merged;
			}
			if ( $merged ) {
				update_post_meta( $post->ID, 'rank_math_robots', $merged );
			} else {
				delete_post_meta( $post->ID, 'rank_math_robots' ); // Empty = inherit the site default.
			}
			foreach ( array( 'robots_index', 'robots_follow', 'robots_advanced' ) as $robots_field ) {
				if ( array_key_exists( $robots_field, $input ) ) {
					$touched[] = $robots_field;
				}
			}
		}

		if ( array_key_exists( 'pillar_content', $input ) ) {
			self::write_meta( $post->ID, 'rank_math_pillar_content', ! empty( $input['pillar_content'] ) ? 'on' : '' );
			$touched[] = 'pillar_content';
		}

		if ( array_key_exists( 'primary_category', $input ) ) {
			if ( ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
				return new WP_Error( 'saddle_no_primary_term', __( 'This post type has no category taxonomy.', 'saddle' ) );
			}
			$term_id = (int) $input['primary_category'];
			if ( $term_id && ! term_exists( $term_id, 'category' ) ) {
				return new WP_Error( 'saddle_bad_term', __( 'No category with that ID.', 'saddle' ) );
			}
			self::write_meta( $post->ID, 'rank_math_primary_category', $term_id ? (string) $term_id : '' );
			$touched[] = 'primary_category';
		}

		if ( ! $touched ) {
			return new WP_Error( 'saddle_empty', __( 'Provide at least one SEO field to change.', 'saddle' ) );
		}

		$current = self::get_post_seo( $post );
		return array(
			'changed'  => array_intersect_key( $current, array_flip( $touched ) ),
			'warnings' => Saddle_Seo_Advice::length_warnings( $current['title'], $current['description'] ),
		);
	}

	/**
	 * saddle/rank-math-get-term-seo.
	 *
	 * @param WP_Term $term Target term.
	 * @return array
	 */
	public static function get_term_seo( WP_Term $term ) {
		return array(
			'title'       => (string) get_term_meta( $term->term_id, 'rank_math_title', true ),
			'description' => (string) get_term_meta( $term->term_id, 'rank_math_description', true ),
		);
	}

	/**
	 * saddle/rank-math-edit-term-seo. Partial merge; "changed" re-read
	 * after write.
	 *
	 * @param WP_Term $term  Target term.
	 * @param array   $input Ability input.
	 * @return array|WP_Error {changed}
	 */
	public static function set_term_seo( WP_Term $term, array $input ) {
		$touched = array();
		$map     = array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
		);

		foreach ( $map as $friendly => $meta_key ) {
			if ( ! array_key_exists( $friendly, $input ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $input[ $friendly ] );
			if ( '' === $value ) {
				delete_term_meta( $term->term_id, $meta_key );
			} else {
				update_term_meta( $term->term_id, $meta_key, $value );
			}
			$touched[] = $friendly;
		}

		if ( ! $touched ) {
			return new WP_Error( 'saddle_empty', __( 'Provide "title" and/or "description" to change.', 'saddle' ) );
		}

		$current = self::get_term_seo( $term );
		return array( 'changed' => array_intersect_key( $current, array_flip( $touched ) ) );
	}

	/**
	 * Write one scalar meta value; empty string deletes the row so Rank
	 * Math falls back to its default instead of rendering an empty value.
	 *
	 * @param int    $post_id  Target post.
	 * @param string $meta_key Meta key.
	 * @param string $value    Sanitized value ('' = delete).
	 */
	private static function write_meta( $post_id, $meta_key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}
}
