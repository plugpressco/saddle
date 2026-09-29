<?php
/**
 * Which content types Saddle's post tools manage.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one answer to "which post types may an agent read and write through
 * Saddle" (#137): posts, pages, and every custom post type a plugin or theme
 * registered for people to edit — shown in wp-admin, and public or exposed to
 * the REST API. Core's own internal types (attachments, templates, navigation,
 * revisions) and Saddle's records are never included; they have their own
 * tools or none.
 *
 * A separate class because three layers ask the same question — the tool
 * inputs, the read funnel (Saddle_Abilities::require_readable_post()) and the
 * served context — and the answer must not drift between them.
 */
class Saddle_Post_Types {

	/**
	 * Every type the content tools accept: post, page, then custom types.
	 *
	 * @return string[]
	 */
	public static function content_types() {
		return array_merge( array( 'post', 'page' ), self::custom_types() );
	}

	/**
	 * The custom post types Saddle manages.
	 *
	 * @return string[]
	 */
	public static function custom_types() {
		$types = array();
		foreach ( get_post_types( array( '_builtin' => false ), 'objects' ) as $object ) {
			if ( $object->show_ui && ( $object->public || $object->show_in_rest ) && 0 !== strpos( $object->name, 'saddle_' ) ) {
				$types[] = $object->name;
			}
		}

		/**
		 * Filter the custom post types Saddle's content tools may read and write.
		 * Each still answers to its own capabilities; this only decides which
		 * types the tools accept at all.
		 *
		 * @param string[] $types Post type names.
		 */
		$types = (array) apply_filters( 'saddle_post_types', $types );

		return array_values(
			array_filter(
				array_unique( $types ),
				static function ( $type ) {
					return is_string( $type ) && post_type_exists( $type ) && ! in_array( $type, array( 'post', 'page', 'attachment', 'revision' ), true );
				}
			)
		);
	}

	/**
	 * The type a post tool acts on: the optional "post_type" input, or the
	 * tool's own type when it is absent.
	 *
	 * @param array  $input    Ability input.
	 * @param string $own_type The tool's own type ('post').
	 * @return string|WP_Error
	 */
	public static function resolve( array $input, $own_type ) {
		if ( ! isset( $input['post_type'] ) || '' === $input['post_type'] ) {
			return $own_type;
		}
		$type = sanitize_key( (string) $input['post_type'] );
		if ( in_array( $type, self::content_types(), true ) ) {
			return $type;
		}
		return new WP_Error(
			'saddle_unknown_post_type',
			sprintf(
				/* translators: %s: post type name. */
				__( '"%s" is not a content type Saddle manages here. Call saddle/list-post-types for the ones you can use.', 'saddle' ),
				$type
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * What an agent needs to know about one type.
	 *
	 * @param string $type Post type name.
	 * @return array
	 */
	public static function describe( $type ) {
		$object     = get_post_type_object( $type );
		$counts     = wp_count_posts( $type );
		$taxonomies = array();
		foreach ( get_object_taxonomies( $type, 'objects' ) as $taxonomy ) {
			if ( $taxonomy->show_ui ) {
				$taxonomies[] = array(
					'name'         => $taxonomy->name,
					'label'        => $taxonomy->labels->name,
					'hierarchical' => (bool) $taxonomy->hierarchical,
				);
			}
		}

		return array(
			'name'         => $type,
			'label'        => $object->labels->name,
			'singular'     => $object->labels->singular_name,
			'hierarchical' => (bool) $object->hierarchical,
			// Core's own test (use_block_editor_for_post_type() is admin-only).
			'block_editor' => post_type_supports( $type, 'editor' ) && ! empty( $object->show_in_rest ),
			'supports'     => array_keys( array_filter( (array) get_all_post_type_supports( $type ) ) ),
			'taxonomies'   => $taxonomies,
			'published'    => isset( $counts->publish ) ? (int) $counts->publish : 0,
			'use'          => self::how_to_use( $type ),
		);
	}

	/**
	 * Which tools reach a type, in a sentence.
	 *
	 * @param string $type Post type name.
	 * @return string
	 */
	private static function how_to_use( $type ) {
		if ( 'post' === $type ) {
			return __( 'The post tools: list-posts, get-post, create-post, update-post, delete-post.', 'saddle' );
		}
		if ( 'page' === $type ) {
			return __( 'The page tools: list-pages, get-page, create-page, update-page, delete-page.', 'saddle' );
		}
		return sprintf(
			/* translators: %s: post type name. */
			__( 'The post tools (list-posts, get-post, create-post, update-post, delete-post) with post_type "%s". The block tools take its ids directly.', 'saddle' ),
			$type
		);
	}
}
