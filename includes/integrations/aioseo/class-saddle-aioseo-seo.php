<?php
/**
 * AIOSEO — per-post SEO field read/write via AIOSEO's own Post model.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes AIOSEO's per-post SEO. AIOSEO v4+ stores this in its OWN
 * TABLE ({prefix}aioseo_posts) behind caches and NOT NULL columns — so every
 * access goes through \AIOSEO\Plugin\Common\Models\Post, never raw $wpdb
 * (the exact rule Waggle's proven aioseo-writer follows: getPost() returns
 * an unsaved model with defaults when no row exists, save() owns row
 * creation, timestamps, and cache invalidation). Column names confirmed by
 * live introspection against AIOSEO 5.0.0.1 on divi-dev before this class
 * was written.
 *
 * Free AIOSEO has NO per-term SEO (no Term model, no aioseo_terms table —
 * both Pro-only, confirmed live), so unlike the Yoast/Rank Math siblings
 * there are no term methods here; primary_term is a JSON shape we have not
 * verified, so it is deferred too (issue #66 records both).
 *
 * "changed" is re-read from a fresh model after save — applied-vs-ignored
 * honesty, same as every other writer in this plugin.
 */
class Saddle_Aioseo_Seo {

	/**
	 * Friendly field name => Post model column, for the plain text fields.
	 * Friendly names match the Yoast/Rank Math integrations — one vocabulary
	 * across the SEO integrations.
	 *
	 * @var array<string,string>
	 */
	const TEXT_FIELD_MAP = array(
		'title'               => 'title',
		'description'         => 'description',
		'canonical'           => 'canonical_url',
		'og_title'            => 'og_title',
		'og_description'      => 'og_description',
		'og_image'            => 'og_image_custom_url',
		'twitter_title'       => 'twitter_title',
		'twitter_description' => 'twitter_description',
		'twitter_image'       => 'twitter_image_custom_url',
	);

	/**
	 * AIOSEO's Post model for a post id, or null when the model class is
	 * unavailable or throws.
	 *
	 * @param int $post_id Post id.
	 * @return object|null
	 */
	private static function model( $post_id ) {
		if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			return null;
		}
		try {
			$model = \AIOSEO\Plugin\Common\Models\Post::getPost( (int) $post_id );
			return is_object( $model ) && method_exists( $model, 'save' ) ? $model : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * saddle/aioseo-get-post-seo.
	 *
	 * @param WP_Post $post Target post.
	 * @return array|WP_Error
	 */
	public static function get_post_seo( WP_Post $post ) {
		$model = self::model( $post->ID );
		if ( ! $model ) {
			return new WP_Error( 'saddle_aioseo_model', __( 'AIOSEO\'s post model is unavailable.', 'saddle' ) );
		}

		$out = array();
		foreach ( self::TEXT_FIELD_MAP as $friendly => $column ) {
			$out[ $friendly ] = (string) ( $model->{$column} ?? '' );
		}

		$out                   = array_merge( $out, Saddle_Aioseo_Keywords::read( $model ), Saddle_Aioseo::robots_read( $model ) );
		$out['pillar_content'] = Saddle_Aioseo::truthy( $model->pillar_content ?? false );

		return $out;
	}

	/**
	 * saddle/aioseo-edit-post-seo. Partial merge — only keys present in
	 * $input change; empty string clears a field so AIOSEO falls back to
	 * its own template/default.
	 *
	 * @param WP_Post $post  Target post.
	 * @param array   $input Ability input (already capability-checked).
	 * @return array|WP_Error {changed, warnings}
	 */
	public static function set_post_seo( WP_Post $post, array $input ) {
		$model = self::model( $post->ID );
		if ( ! $model ) {
			return new WP_Error( 'saddle_aioseo_model', __( 'AIOSEO\'s post model is unavailable.', 'saddle' ) );
		}

		$touched = array();

		foreach ( self::TEXT_FIELD_MAP as $friendly => $column ) {
			if ( ! array_key_exists( $friendly, $input ) ) {
				continue;
			}
			$model->{$column} = sanitize_text_field( (string) $input[ $friendly ] );
			$touched[]        = $friendly;
		}

		// A custom image URL only renders when its image "type" says custom;
		// custom twitter values only render when the card stops mirroring OG.
		if ( in_array( 'og_image', $touched, true ) && '' !== (string) $model->og_image_custom_url ) {
			$model->og_image_type = 'custom';
		}
		if ( in_array( 'twitter_image', $touched, true ) && '' !== (string) $model->twitter_image_custom_url ) {
			$model->twitter_image_type = 'custom';
		}
		if ( array_intersect( array( 'twitter_title', 'twitter_description', 'twitter_image' ), $touched ) ) {
			$model->twitter_use_og = false;
		}

		$touched = array_merge( $touched, Saddle_Aioseo_Keywords::apply( $model, $input ) );

		$robots_changes = Saddle_Aioseo::robots_changes( $model, $input );
		if ( is_wp_error( $robots_changes ) ) {
			return $robots_changes;
		}
		if ( $robots_changes ) {
			foreach ( $robots_changes as $column => $value ) {
				$model->{$column} = $value;
			}
			foreach ( array( 'robots_index', 'robots_follow', 'robots_advanced' ) as $robots_field ) {
				if ( array_key_exists( $robots_field, $input ) ) {
					$touched[] = $robots_field;
				}
			}
		}

		if ( array_key_exists( 'pillar_content', $input ) ) {
			$model->pillar_content = ! empty( $input['pillar_content'] );
			$touched[]             = 'pillar_content';
		}

		if ( ! $touched ) {
			return new WP_Error( 'saddle_empty', __( 'Provide at least one SEO field to change.', 'saddle' ) );
		}

		try {
			$model->save();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'saddle_aioseo_save', __( 'AIOSEO refused the save.', 'saddle' ) );
		}

		// Re-read through a FRESH model so "changed" reports what AIOSEO
		// actually persisted, never what we handed it.
		$current = self::get_post_seo( $post );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$warnings = Saddle_Seo_Advice::length_warnings( $current['title'], $current['description'] );
		if ( in_array( 'additional_keywords', $touched, true ) && $current['additional_keywords'] && ! Saddle_Aioseo::is_pro() ) {
			$warnings[] = __( 'Additional keyphrases are an AIOSEO Pro feature. They were saved, but the AIOSEO editor on this site does not show them.', 'saddle' );
		}

		return array(
			'changed'  => array_intersect_key( $current, array_flip( $touched ) ),
			'warnings' => $warnings,
		);
	}
}
