<?php
/**
 * Divi 5 Library + Theme Builder read abilities.
 *
 * The Library is the `et_pb_layout` CPT, read natively. Theme Builder is
 * Divi's procedural template API (et_theme_builder_*), which exists only when
 * Divi is active — those callbacks guard for it.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Library + Theme Builder read abilities. Hooked to
 * `wp_abilities_api_init` at priority 30 through saddle_register_ability_once(),
 * so a site still running an older add-on that registers the same names keeps
 * that copy.
 */
function saddle_register_divi_template_abilities() {

	saddle_register_ability_once(
		'saddle/divi-list-library-items',
		array(
			'label'               => __( 'List Divi library items', 'saddle' ),
			'description'         => __( 'Lists saved Divi library items (layouts, sections, rows, modules) with id, title, type, and whether the item is global (live-linked). Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Templates', 'list_library_items' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-library-items' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-list-theme-builder-templates',
		array(
			'label'               => __( 'List Divi Theme Builder templates', 'saddle' ),
			'description'         => __( 'Lists the site\'s Theme Builder templates — id, title, whether it is the default, its header/body/footer layout post ids, and its assignment conditions (use_on/exclude_from). Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Templates', 'list_tb_templates' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-theme-builder-templates' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-list-theme-builder-conditions',
		array(
			'label'               => __( 'List Theme Builder conditions', 'saddle' ),
			'description'         => __( 'Reference of the assignment conditions a Theme Builder template can be applied to / excluded from (e.g. singular, page, post, category:ID). Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Templates', 'list_tb_conditions' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-theme-builder-conditions' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Callbacks for the Divi Library + Theme Builder read abilities.
 */
class Saddle_Divi_Templates {

	const LIBRARY_CPT = 'et_pb_layout';

	/**
	 * saddle/divi-list-library-items.
	 *
	 * @return array
	 */
	public static function list_library_items() {
		$posts = get_posts(
			array(
				'post_type'      => self::LIBRARY_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded listing of a hand-curated Library CPT.
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			// get_posts() primed the object-term cache, so get_the_terms() is
			// a cache hit; wp_get_post_terms( …, fields => slugs ) would bypass
			// that cache and fire two fresh term queries per item (the N+1).
			$type_terms  = self::term_slugs( $post, 'layout_type' );
			$scope_terms = self::term_slugs( $post, 'scope' );
			$items[]     = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'type'   => $type_terms ? $type_terms[0] : 'layout',
				'global' => in_array( 'global', $scope_terms, true ),
			);
		}

		return array(
			'items' => $items,
			'count' => count( $items ),
		);
	}

	/**
	 * A post's term slugs for a taxonomy, from the primed object-term cache.
	 *
	 * @param WP_Post $post     Post to read.
	 * @param string  $taxonomy Taxonomy slug.
	 * @return string[] Slugs (empty if none or the taxonomy is missing).
	 */
	private static function term_slugs( $post, $taxonomy ) {
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return wp_list_pluck( $terms, 'slug' );
	}

	/**
	 * saddle/divi-list-theme-builder-templates.
	 *
	 * @return array|WP_Error
	 */
	public static function list_tb_templates() {
		if ( ! function_exists( 'et_theme_builder_get_theme_builder_templates' ) ) {
			return self::unavailable();
		}
		$templates = et_theme_builder_get_theme_builder_templates( true );
		return array( 'templates' => is_array( $templates ) ? array_values( $templates ) : array() );
	}

	/**
	 * saddle/divi-list-theme-builder-conditions.
	 *
	 * @return array|WP_Error
	 */
	public static function list_tb_conditions() {
		if ( ! function_exists( 'et_theme_builder_get_template_settings_options' ) ) {
			return self::unavailable();
		}
		return array(
			'conditions' => et_theme_builder_get_template_settings_options(),
			'note'       => __( 'These are the condition ids a Theme Builder template\'s use_on / exclude_from lists accept.', 'saddle' ),
		);
	}

	/**
	 * Uniform "Divi 5 not available" error.
	 *
	 * @return WP_Error
	 */
	private static function unavailable() {
		return new WP_Error(
			'saddle_divi_api_unavailable',
			__( 'This needs Divi 5 active (its Library / Theme Builder API is not available). Use divi-check-setup.', 'saddle' ),
			array( 'status' => 400 )
		);
	}
}
