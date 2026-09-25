<?php
/**
 * Yoast SEO — per-post and per-term SEO field read/write.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes Yoast's own post/term SEO data, through Yoast's own
 * APIs rather than raw meta calls against guessed key names/locations:
 * WPSEO_Meta for posts (centralizes the '_yoast_wpseo_' prefixing and
 * follows the same save path Yoast's own metabox uses), and the Indexable
 * repository for terms (see indexable_repository() below for why — term
 * SEO is NOT stored where the legacy WPSEO_Taxonomy_Meta class implies).
 *
 * A write always re-reads the field it just touched rather than echoing the
 * input back: Yoast's own field definitions carry their own sanitize/
 * validate callbacks (e.g. schema fields only accept a fixed option list)
 * that can silently discard an out-of-range value, and this integration
 * must never report a value as "changed" that Yoast itself declined to
 * persist — the same applied-vs-ignored honesty Divi's write abilities hold
 * to. Confirmed against a real Yoast SEO 28.3 install on divi-dev.
 */
class Saddle_Yoast_Seo {

	/**
	 * Friendly field name => WPSEO_Meta key (posts).
	 *
	 * @var array<string,string>
	 */
	const POST_FIELD_MAP = array(
		'title'               => 'title',
		'description'         => 'metadesc',
		'focus_keyword'       => 'focuskw',
		'canonical'           => 'canonical',
		'og_title'            => 'opengraph-title',
		'og_description'      => 'opengraph-description',
		'og_image'            => 'opengraph-image',
		'twitter_title'       => 'twitter-title',
		'twitter_description' => 'twitter-description',
		'twitter_image'       => 'twitter-image',
	);

	/**
	 * saddle/yoast-get-post-seo.
	 *
	 * @param WP_Post $post Target post.
	 * @return array
	 */
	public static function get_post_seo( WP_Post $post ) {
		$out = array();
		foreach ( self::POST_FIELD_MAP as $friendly => $yoast_key ) {
			$out[ $friendly ] = (string) WPSEO_Meta::get_value( $yoast_key, $post->ID );
		}

		$noindex  = Saddle_Yoast::noindex_maps();
		$nofollow = Saddle_Yoast::nofollow_maps();

		$out['robots_index']    = Saddle_Yoast::from_tri_state( WPSEO_Meta::get_value( 'meta-robots-noindex', $post->ID ), $noindex['from'] );
		$out['robots_follow']   = Saddle_Yoast::from_tri_state( WPSEO_Meta::get_value( 'meta-robots-nofollow', $post->ID ), $nofollow['from'] );
		$out['robots_advanced'] = (string) WPSEO_Meta::get_value( 'meta-robots-adv', $post->ID );
		$out['cornerstone']     = '1' === (string) WPSEO_Meta::get_value( 'is_cornerstone', $post->ID );

		// Primary category: Yoast's own WPSEO_Primary_Term API, not a meta
		// key this integration guesses at directly.
		$out['primary_category'] = class_exists( 'WPSEO_Primary_Term' ) && is_object_in_taxonomy( $post->post_type, 'category' )
			? (int) ( new WPSEO_Primary_Term( 'category', $post->ID ) )->get_primary_term()
			: null;

		return $out;
	}

	/**
	 * saddle/yoast-edit-post-seo. Partial merge — only keys present in
	 * $input are touched. "changed" reflects the value Yoast actually
	 * persisted (re-read after write), not the raw input.
	 *
	 * @param WP_Post $post  Target post.
	 * @param array   $input Ability input (already capability-checked by the caller).
	 * @return array|WP_Error {changed, warnings?}
	 */
	public static function set_post_seo( WP_Post $post, array $input ) {
		$touched = array();

		foreach ( self::POST_FIELD_MAP as $friendly => $yoast_key ) {
			if ( ! array_key_exists( $friendly, $input ) ) {
				continue;
			}
			WPSEO_Meta::set_value( $yoast_key, sanitize_text_field( (string) $input[ $friendly ] ), $post->ID );
			$touched[] = $friendly;
		}

		if ( array_key_exists( 'robots_index', $input ) ) {
			$maps  = Saddle_Yoast::noindex_maps();
			$state = Saddle_Yoast::to_tri_state( $input['robots_index'], $maps['to'] );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			WPSEO_Meta::set_value( 'meta-robots-noindex', $state, $post->ID );
			$touched[] = 'robots_index';
		}

		if ( array_key_exists( 'robots_follow', $input ) ) {
			$maps  = Saddle_Yoast::nofollow_maps();
			$state = Saddle_Yoast::to_tri_state( $input['robots_follow'], $maps['to'] );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			WPSEO_Meta::set_value( 'meta-robots-nofollow', $state, $post->ID );
			$touched[] = 'robots_follow';
		}

		if ( array_key_exists( 'robots_advanced', $input ) ) {
			WPSEO_Meta::set_value( 'meta-robots-adv', sanitize_text_field( (string) $input['robots_advanced'] ), $post->ID );
			$touched[] = 'robots_advanced';
		}

		if ( array_key_exists( 'cornerstone', $input ) ) {
			WPSEO_Meta::set_value( 'is_cornerstone', ! empty( $input['cornerstone'] ) ? '1' : '', $post->ID );
			$touched[] = 'cornerstone';
		}

		if ( array_key_exists( 'primary_category', $input ) ) {
			if ( ! class_exists( 'WPSEO_Primary_Term' ) || ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
				return new WP_Error( 'saddle_no_primary_term', __( 'This post type has no primary-category support in Yoast.', 'saddle' ) );
			}
			$term_id = (int) $input['primary_category'];
			if ( $term_id && ! term_exists( $term_id, 'category' ) ) {
				return new WP_Error( 'saddle_bad_term', __( 'No category with that ID.', 'saddle' ) );
			}
			( new WPSEO_Primary_Term( 'category', $post->ID ) )->set_primary_term( $term_id );
			$touched[] = 'primary_category';
		}

		if ( ! $touched ) {
			return new WP_Error( 'saddle_empty', __( 'Provide at least one SEO field to change.', 'saddle' ) );
		}

		$current = self::get_post_seo( $post );
		$changed = array_intersect_key( $current, array_flip( $touched ) );

		return array(
			'changed'  => $changed,
			'warnings' => Saddle_Seo_Advice::length_warnings( $current['title'], $current['description'] ),
		);
	}

	/**
	 * Yoast's term SEO title/description live on its Indexable model, not
	 * the legacy WPSEO_Taxonomy_Meta option — confirmed by reflection AND
	 * a live round-trip against Yoast SEO 28.3 on divi-dev: writing through
	 * WPSEO_Taxonomy_Meta::set_value() landed in the wpseo_taxonomy_meta
	 * option, but get_term_meta() never reads it back (it's a vestigial
	 * back-compat path in current Yoast, not the live source of truth).
	 * find_by_id_and_type() builds a fresh Indexable row on first access —
	 * it does not return null for an existing term without prior SEO data.
	 *
	 * @return \Yoast\WP\SEO\Repositories\Indexable_Repository|null
	 */
	private static function indexable_repository() {
		if ( ! function_exists( 'YoastSEO' ) || ! class_exists( '\Yoast\WP\SEO\Repositories\Indexable_Repository' ) ) {
			return null;
		}
		try {
			return YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * saddle/yoast-get-term-seo.
	 *
	 * @param WP_Term $term Target term.
	 * @return array|WP_Error
	 */
	public static function get_term_seo( WP_Term $term ) {
		$repo = self::indexable_repository();
		if ( ! $repo ) {
			return new WP_Error( 'saddle_no_yoast_indexables', __( 'This Yoast SEO version has no term indexable API.', 'saddle' ) );
		}

		$indexable = $repo->find_by_id_and_type( $term->term_id, 'term' );
		return array(
			'title'       => (string) ( $indexable ? $indexable->title : '' ),
			'description' => (string) ( $indexable ? $indexable->description : '' ),
		);
	}

	/**
	 * saddle/yoast-edit-term-seo. Partial merge; "changed" is re-read after
	 * write.
	 *
	 * @param WP_Term $term  Target term.
	 * @param array   $input Ability input.
	 * @return array|WP_Error {changed}
	 */
	public static function set_term_seo( WP_Term $term, array $input ) {
		$repo = self::indexable_repository();
		if ( ! $repo ) {
			return new WP_Error( 'saddle_no_yoast_indexables', __( 'This Yoast SEO version has no term indexable API.', 'saddle' ) );
		}

		$indexable = $repo->find_by_id_and_type( $term->term_id, 'term' );
		if ( ! $indexable ) {
			return new WP_Error( 'saddle_no_yoast_indexables', __( 'Yoast could not build an indexable for this term.', 'saddle' ) );
		}

		$touched = array();
		if ( array_key_exists( 'title', $input ) ) {
			$indexable->title = sanitize_text_field( (string) $input['title'] );
			$touched[]        = 'title';
		}
		if ( array_key_exists( 'description', $input ) ) {
			$indexable->description = sanitize_text_field( (string) $input['description'] );
			$touched[]              = 'description';
		}

		if ( ! $touched ) {
			return new WP_Error( 'saddle_empty', __( 'Provide "title" and/or "description" to change.', 'saddle' ) );
		}

		$indexable->save();

		$current = self::get_term_seo( $term );
		return array( 'changed' => array_intersect_key( $current, array_flip( $touched ) ) );
	}
}
