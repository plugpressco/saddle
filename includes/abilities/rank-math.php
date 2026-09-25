<?php
/**
 * Rank Math abilities (native integration).
 *
 * Per-post/per-term SEO fields — NOT a wrapper (Rank Math registers no
 * abilities of its own): a native integration reading and writing Rank
 * Math's own `rank_math_*` meta, same model as the Divi and Yoast
 * abilities. Registered in the `saddle/` namespace so these surface
 * automatically through Saddle's MCP server, Permissions UI, and
 * per-ability toggles. Registered unconditionally — each ability refuses
 * cleanly with an actionable error when Rank Math isn't active.
 *
 * Deferred by decision (issue #65): post schema (a full JSON graph, unlike
 * Yoast's two enum fields), site-wide settings, redirections, module
 * toggles — the closed #7 plan covers those when they're wanted.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Rank Math abilities. Hooked to `wp_abilities_api_init`
 * at priority 30 through saddle_register_ability_once(), so a site still
 * running an older add-on that registers the same names keeps that copy.
 */
function saddle_register_rankmath_abilities() {

	saddle_register_ability_once(
		'saddle/rank-math-check-setup',
		array(
			'label'               => __( 'Check Rank Math setup', 'saddle' ),
			'description'         => __( 'Reports whether Rank Math is active on this site (and its version). Read-only. Call this before using any other rank-math-* tool.', 'saddle' ),
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
			'execute_callback'    => array( 'Saddle_Rank_Math_Abilities', 'check_setup' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'rank-math-check-setup' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/rank-math-get-post-seo',
		array(
			'label'               => __( 'Get Rank Math post SEO', 'saddle' ),
			'description'         => __( 'Returns a post\'s Rank Math SEO fields: title, description, focus keyword, canonical URL, robots (index/follow/advanced), Open Graph + Twitter title/description/image, primary category, and whether it is marked pillar content. Read-only.', 'saddle' ),
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
			'execute_callback'    => array( 'Saddle_Rank_Math_Abilities', 'get_post_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'rank-math-get-post-seo' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/rank-math-edit-post-seo',
		array(
			'label'               => __( 'Edit Rank Math post SEO', 'saddle' ),
			'description'         => __( 'Sets a post\'s Rank Math SEO fields — partial merge, only the fields you pass change; an empty string resets a field to Rank Math\'s own default. robots_index takes "default"/"index"/"noindex" (default inherits the site setting); robots_follow takes "follow"/"nofollow". Unrelated robots flags are preserved. The response carries non-blocking length warnings when title/description run long.', 'saddle' ),
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
						'description' => __( 'Focus keyword(s); Rank Math accepts a comma-separated list.', 'saddle' ),
					),
					'canonical'           => array(
						'type'        => 'string',
						'description' => __( 'Canonical URL override.', 'saddle' ),
					),
					'robots_index'        => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'index', 'noindex' ),
						'description' => __( 'Search-index directive. "default" inherits the site setting.', 'saddle' ),
					),
					'robots_follow'       => array(
						'type'        => 'string',
						'enum'        => array( 'follow', 'nofollow' ),
						'description' => __( 'Link-follow directive. "follow" removes the nofollow flag (Rank Math has no separate inherit state for follow).', 'saddle' ),
					),
					'robots_advanced'     => array(
						'type'        => 'string',
						'description' => __( 'Comma list of advanced robots flags: noarchive, noimageindex, nosnippet. Empty string clears them.', 'saddle' ),
					),
					'og_title'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph (Facebook) title override.', 'saddle' ),
					),
					'og_description'      => array(
						'type'        => 'string',
						'description' => __( 'Open Graph (Facebook) description override.', 'saddle' ),
					),
					'og_image'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph (Facebook) image URL.', 'saddle' ),
					),
					'twitter_title'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card title override. Setting any twitter field switches the card off "use Facebook data".', 'saddle' ),
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
						'description' => __( 'Category term ID to mark as the primary category. Only applies to post types that support categories; 0 clears it.', 'saddle' ),
					),
					'pillar_content'      => array(
						'type'        => 'boolean',
						'description' => __( 'Mark/unmark this post as Rank Math pillar content.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Rank_Math_Abilities', 'edit_post_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'rank-math-edit-post-seo' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/rank-math-get-term-seo',
		array(
			'label'               => __( 'Get Rank Math term SEO', 'saddle' ),
			'description'         => __( 'Returns a term\'s (category/tag/custom taxonomy) Rank Math SEO title and meta description. Read-only.', 'saddle' ),
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
			'execute_callback'    => array( 'Saddle_Rank_Math_Abilities', 'get_term_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'rank-math-get-term-seo' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/rank-math-edit-term-seo',
		array(
			'label'               => __( 'Edit Rank Math term SEO', 'saddle' ),
			'description'         => __( 'Sets a term\'s Rank Math SEO title and/or meta description — partial merge; an empty string resets the field to Rank Math\'s default.', 'saddle' ),
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
			'execute_callback'    => array( 'Saddle_Rank_Math_Abilities', 'edit_term_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'rank-math-edit-term-seo' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);
}

/**
 * Execute callbacks for the Rank Math abilities.
 *
 * Permission has already passed (tier + capability + pause + per-ability
 * toggle) by the time these run — same contract as every other ability.
 */
class Saddle_Rank_Math_Abilities {

	use Saddle_Mutation_Log;

	/**
	 * saddle/rank-math-check-setup.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function check_setup( $input ) {
		$out = array(
			'rankmath_active'  => Saddle_Rank_Math::is_active(),
			'rankmath_version' => Saddle_Rank_Math::version(),
		);

		if ( ! $out['rankmath_active'] ) {
			$out['note'] = __( 'Rank Math is not active on this site. The rank-math-* tools are unavailable; use the regular content tools.', 'saddle' );
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
	 * Shared read guard: Rank Math active, a real post the user may read.
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function read_post_guard( $input ) {
		if ( ! Saddle_Rank_Math::is_active() ) {
			return Saddle_Rank_Math::not_active_error();
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
	 * Shared write guard — the per-object edit_post check on top of the
	 * permission callback's generic tier + capability (mirrors
	 * Saddle_Abilities::authorize_write).
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function edit_post_guard( $input ) {
		if ( ! Saddle_Rank_Math::is_active() ) {
			return Saddle_Rank_Math::not_active_error();
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
	 * Shared term guard: Rank Math active, term exists in the taxonomy.
	 *
	 * @param array $input Ability input.
	 * @return WP_Term|WP_Error
	 */
	private static function term_guard( $input ) {
		if ( ! Saddle_Rank_Math::is_active() ) {
			return Saddle_Rank_Math::not_active_error();
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
	 * saddle/rank-math-get-post-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_post_seo( $input ) {
		$post = self::read_post_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return Saddle_Rank_Math_Seo::get_post_seo( $post );
	}

	/**
	 * saddle/rank-math-edit-post-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function edit_post_seo( $input ) {
		$post = self::edit_post_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = Saddle_Rank_Math_Seo::set_post_seo( $post, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log(
			'rank-math-edit-post-seo',
			$post->ID,
			sprintf(
				/* translators: %d: post ID. */
				__( 'Edited Rank Math SEO fields on post #%d.', 'saddle' ),
				$post->ID
			)
		);

		return array_merge( array( 'id' => $post->ID ), $result );
	}

	/**
	 * saddle/rank-math-get-term-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_term_seo( $input ) {
		$term = self::term_guard( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		return Saddle_Rank_Math_Seo::get_term_seo( $term );
	}

	/**
	 * saddle/rank-math-edit-term-seo.
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

		$result = Saddle_Rank_Math_Seo::set_term_seo( $term, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log(
			'rank-math-edit-term-seo',
			$term->term_id,
			sprintf(
				/* translators: %d: term ID. */
				__( 'Edited Rank Math SEO fields on term #%d.', 'saddle' ),
				$term->term_id
			)
		);

		return array_merge( array( 'id' => $term->term_id ), $result );
	}
}
