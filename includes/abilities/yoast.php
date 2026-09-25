<?php
/**
 * Yoast SEO abilities (native integration).
 *
 * Per-post/per-term SEO fields + per-post schema type overrides — NOT a
 * wrapper of Yoast's own abilities (it registers none of its own): this is
 * a native integration reading and writing Yoast's own meta directly,
 * through Yoast's documented APIs (WPSEO_Meta, WPSEO_Taxonomy_Meta,
 * WPSEO_Primary_Term), the same model the Divi abilities use for Divi's
 * own data. Registered in the `saddle/` namespace so these surface
 * automatically through Saddle's MCP server, Permissions UI, and per-ability
 * toggles — zero new transport, auth, or policy surface.
 *
 * Registered unconditionally, mirroring the Divi abilities: each one
 * refuses cleanly with an actionable error when Yoast isn't active, rather
 * than being conditionally registered.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Yoast SEO abilities. Hooked to `wp_abilities_api_init`
 * at priority 30 through saddle_register_ability_once(), so a site still
 * running an older add-on that registers the same names keeps that copy.
 */
function saddle_register_yoast_abilities() {

	saddle_register_ability_once(
		'saddle/yoast-check-setup',
		array(
			'label'               => __( 'Check Yoast SEO setup', 'saddle' ),
			'description'         => __( 'Reports whether Yoast SEO is active on this site (and its version). Read-only. Call this before using any other yoast-* tool.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Optional post or page ID to check readability for.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Yoast_Abilities', 'check_setup' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'yoast-check-setup' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/yoast-get-post-seo',
		array(
			'label'               => __( 'Get Yoast post SEO', 'saddle' ),
			'description'         => __( 'Returns a post\'s Yoast SEO fields: title, meta description, focus keyword, canonical URL, robots (index/follow/advanced), Open Graph + Twitter title/description/image, primary category, and whether it is marked cornerstone content. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page ID to read.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Yoast_Abilities', 'get_post_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'yoast-get-post-seo' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/yoast-edit-post-seo',
		array(
			'label'               => __( 'Edit Yoast post SEO', 'saddle' ),
			'description'         => __( 'Sets a post\'s Yoast SEO fields — partial merge, only the fields you pass change. robots_index/robots_follow take the friendly words "default"/"noindex"/"index" and "default"/"nofollow"/"follow" (never Yoast\'s raw 0/1/2 encoding). The response carries non-blocking length warnings when title/description run long.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id'             => array(
						'type'        => 'integer',
						'description' => __( 'The post or page ID to edit.', 'saddle' ),
					),
					'title'               => array(
						'type'        => 'string',
						'description' => __( 'SEO title.', 'saddle' ),
					),
					'description'         => array(
						'type'        => 'string',
						'description' => __( 'Meta description.', 'saddle' ),
					),
					'focus_keyword'       => array(
						'type'        => 'string',
						'description' => __( 'Focus keyword/keyphrase.', 'saddle' ),
					),
					'canonical'           => array(
						'type'        => 'string',
						'description' => __( 'Canonical URL override.', 'saddle' ),
					),
					'robots_index'        => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'noindex', 'index' ),
						'description' => __( 'Search-index directive. "default" inherits the site setting.', 'saddle' ),
					),
					'robots_follow'       => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'nofollow', 'follow' ),
						'description' => __( 'Link-follow directive. "default" inherits the site setting.', 'saddle' ),
					),
					'robots_advanced'     => array(
						'type'        => 'string',
						'description' => __( 'Comma-separated Yoast advanced robots tokens, e.g. "noimageindex,noarchive,nosnippet". Empty string clears them.', 'saddle' ),
					),
					'og_title'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph title override.', 'saddle' ),
					),
					'og_description'      => array(
						'type'        => 'string',
						'description' => __( 'Open Graph description override.', 'saddle' ),
					),
					'og_image'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph image URL.', 'saddle' ),
					),
					'twitter_title'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card title override.', 'saddle' ),
					),
					'twitter_description' => array(
						'type'        => 'string',
						'description' => __( 'Twitter card description override.', 'saddle' ),
					),
					'twitter_image'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card image URL.', 'saddle' ),
					),
					'primary_category'    => array(
						'type'        => 'integer',
						'description' => __( 'Category term ID to mark as the primary category. Only applies to post types that support categories.', 'saddle' ),
					),
					'cornerstone'         => array(
						'type'        => 'boolean',
						'description' => __( 'Mark/unmark this post as Yoast cornerstone content.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Yoast_Abilities', 'edit_post_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'yoast-edit-post-seo' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/yoast-get-term-seo',
		array(
			'label'               => __( 'Get Yoast term SEO', 'saddle' ),
			'description'         => __( 'Returns a term\'s (category/tag/custom taxonomy) Yoast SEO title and meta description. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'term_id' ),
				'properties' => array(
					'term_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The term ID to read.', 'saddle' ),
					),
					'taxonomy' => array(
						'type'        => 'string',
						'default'     => 'category',
						'description' => __( 'The term\'s taxonomy, e.g. "category" or "post_tag".', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Yoast_Abilities', 'get_term_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'yoast-get-term-seo' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/yoast-edit-term-seo',
		array(
			'label'               => __( 'Edit Yoast term SEO', 'saddle' ),
			'description'         => __( 'Sets a term\'s Yoast SEO title and/or meta description — partial merge.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'term_id' ),
				'properties' => array(
					'term_id'     => array(
						'type'        => 'integer',
						'description' => __( 'The term ID to edit.', 'saddle' ),
					),
					'taxonomy'    => array(
						'type'    => 'string',
						'default' => 'category',
					),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'Saddle_Yoast_Abilities', 'edit_term_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'yoast-edit-term-seo' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/yoast-get-post-schema',
		array(
			'label'               => __( 'Get Yoast post schema', 'saddle' ),
			'description'         => __( 'Returns a post\'s Yoast schema type overrides: page_type and (for Article-family pages) article_type. Empty means Yoast\'s site default applies. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( 'Saddle_Yoast_Abilities', 'get_post_schema' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'yoast-get-post-schema' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/yoast-edit-post-schema',
		array(
			'label'               => __( 'Edit Yoast post schema', 'saddle' ),
			'description'         => __( 'Sets a post\'s Yoast schema type overrides — partial merge; an empty string resets a field to Yoast\'s site default. Yoast validates both fields against its own fixed option list and silently keeps the prior value for anything outside it — read the response\'s "changed" (or re-read with get-post-schema) to confirm a value actually took effect rather than assuming the call succeeded because it didn\'t error.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id'      => array( 'type' => 'integer' ),
					'page_type'    => array(
						'type'        => 'string',
						'description' => __( 'Yoast schema page type OVERRIDE, e.g. "WebPage", "FAQPage", "AboutPage", "ContactPage". A post\'s default type (Article for posts, WebPage for pages) is implicit — do not pass "Article" here; use article_type to refine it instead.', 'saddle' ),
					),
					'article_type' => array(
						'type'        => 'string',
						'description' => __( 'Yoast schema article type, e.g. "Article", "BlogPosting", "NewsArticle" — refines the implicit Article type most single posts already have; unrelated to page_type.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Yoast_Abilities', 'edit_post_schema' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'yoast-edit-post-schema' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);
}

/**
 * Execute callbacks for the Yoast SEO abilities.
 *
 * Permission has already passed (tier + capability + pause + per-ability
 * toggle) by the time these run — same contract as free Saddle's callbacks
 * and the Divi abilities.
 */
class Saddle_Yoast_Abilities {

	use Saddle_Mutation_Log;

	/**
	 * saddle/yoast-check-setup.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function check_setup( $input ) {
		$out = array(
			'yoast_active'  => Saddle_Yoast::is_active(),
			'yoast_version' => Saddle_Yoast::version(),
		);

		if ( ! $out['yoast_active'] ) {
			$out['note'] = __( 'Yoast SEO is not active on this site. The yoast-* tools are unavailable; use the regular content tools.', 'saddle' );
			return $out;
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'saddle_not_found', __( 'No post with that ID.', 'saddle' ) );
			}
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				return new WP_Error( 'saddle_forbidden', __( 'You are not allowed to read that post.', 'saddle' ), array( 'status' => 403 ) );
			}
			$out['post'] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
			);
		}

		return $out;
	}

	/**
	 * Shared read guard: Yoast active, a real post, current user may read it.
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function read_post_guard( $input ) {
		if ( ! Saddle_Yoast::is_active() ) {
			return Saddle_Yoast::not_active_error();
		}
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'saddle_not_found', __( 'No post with that ID.', 'saddle' ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You are not allowed to read that post.', 'saddle' ), array( 'status' => 403 ) );
		}
		return $post;
	}

	/**
	 * Shared write guard: Yoast active, a real post, current user may edit
	 * it. Mirrors Saddle_Abilities::authorize_write's per-object check — the
	 * generic 'write' tier + 'edit_posts' capability on the permission
	 * callback is necessary but not sufficient; this is the per-object gate.
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function edit_post_guard( $input ) {
		if ( ! Saddle_Yoast::is_active() ) {
			return Saddle_Yoast::not_active_error();
		}
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'saddle_not_found', __( 'No post with that ID.', 'saddle' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You do not have permission to edit this post.', 'saddle' ), array( 'status' => 403 ) );
		}
		return $post;
	}

	/**
	 * Shared guard for term abilities: Yoast active, term exists in the
	 * given taxonomy.
	 *
	 * @param array $input Ability input.
	 * @return WP_Term|WP_Error
	 */
	private static function term_guard( $input ) {
		if ( ! Saddle_Yoast::is_active() ) {
			return Saddle_Yoast::not_active_error();
		}
		$term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
		$taxonomy = isset( $input['taxonomy'] ) && '' !== $input['taxonomy'] ? sanitize_key( $input['taxonomy'] ) : 'category';
		$term     = $term_id ? get_term( $term_id, $taxonomy ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No term with that ID in that taxonomy.', 'saddle' ) );
		}
		return $term;
	}

	/**
	 * saddle/yoast-get-post-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_post_seo( $input ) {
		$post = self::read_post_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return Saddle_Yoast_Seo::get_post_seo( $post );
	}

	/**
	 * saddle/yoast-edit-post-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function edit_post_seo( $input ) {
		$post = self::edit_post_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = Saddle_Yoast_Seo::set_post_seo( $post, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log(
			'yoast-edit-post-seo',
			$post->ID,
			sprintf(
				/* translators: %d: post ID. */
				__( 'Edited Yoast SEO fields on post #%d.', 'saddle' ),
				$post->ID
			)
		);

		return array_merge( array( 'id' => $post->ID ), $result );
	}

	/**
	 * saddle/yoast-get-term-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_term_seo( $input ) {
		$term = self::term_guard( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		return Saddle_Yoast_Seo::get_term_seo( $term );
	}

	/**
	 * saddle/yoast-edit-term-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function edit_term_seo( $input ) {
		$term = self::term_guard( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You do not have permission to edit this term.', 'saddle' ), array( 'status' => 403 ) );
		}

		$result = Saddle_Yoast_Seo::set_term_seo( $term, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log(
			'yoast-edit-term-seo',
			$term->term_id,
			sprintf(
				/* translators: %d: term ID. */
				__( 'Edited Yoast SEO fields on term #%d.', 'saddle' ),
				$term->term_id
			)
		);

		return array_merge( array( 'id' => $term->term_id ), $result );
	}

	/**
	 * saddle/yoast-get-post-schema.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_post_schema( $input ) {
		$post = self::read_post_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return Saddle_Yoast_Schema::get_post_schema( $post );
	}

	/**
	 * saddle/yoast-edit-post-schema.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function edit_post_schema( $input ) {
		$post = self::edit_post_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = Saddle_Yoast_Schema::set_post_schema( $post, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log(
			'yoast-edit-post-schema',
			$post->ID,
			sprintf(
				/* translators: %d: post ID. */
				__( 'Edited Yoast schema type on post #%d.', 'saddle' ),
				$post->ID
			)
		);

		return array_merge( array( 'id' => $post->ID ), $result );
	}
}
