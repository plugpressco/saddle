<?php
/**
 * AIOSEO abilities (native integration).
 *
 * Per-post SEO fields via AIOSEO's own Post model — NOT a wrapper (AIOSEO
 * registers no abilities of its own). Registered in the `saddle/` namespace
 * so these surface automatically through Saddle's MCP server, Permissions
 * UI, and per-ability toggles. Registered unconditionally — each ability
 * refuses cleanly when AIOSEO isn't active.
 *
 * Three abilities, not five: free AIOSEO has NO per-term SEO (no Term
 * model, no aioseo_terms table — Pro-only, confirmed live), and its
 * primary_term column is an unverified JSON shape — both deferred, recorded
 * in issue #66.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the AIOSEO abilities. Hooked to `wp_abilities_api_init`
 * at priority 30 through saddle_register_ability_once(), so a site still
 * running an older add-on that registers the same names keeps that copy.
 */
function saddle_register_aioseo_abilities() {

	saddle_register_ability_once(
		'saddle/aioseo-check-setup',
		array(
			'label'               => __( 'Check AIOSEO setup', 'saddle' ),
			'description'         => __( 'Reports whether AIOSEO (All in One SEO) is active on this site (and its version). Read-only. Call this before using the other aioseo-* tools.', 'saddle' ),
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
			'execute_callback'    => array( 'Saddle_Aioseo_Abilities', 'check_setup' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'aioseo-check-setup' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/aioseo-get-post-seo',
		array(
			'label'               => __( 'Get AIOSEO post SEO', 'saddle' ),
			'description'         => __( 'Returns a post\'s AIOSEO fields: title, description, focus keyword, canonical URL, robots (index/follow/advanced), Open Graph + Twitter title/description/image, and whether it is marked pillar content. Read-only. Per-term SEO is an AIOSEO Pro feature and has no tool yet.', 'saddle' ),
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
			'execute_callback'    => array( 'Saddle_Aioseo_Abilities', 'get_post_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'aioseo-get-post-seo' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/aioseo-edit-post-seo',
		array(
			'label'               => __( 'Edit AIOSEO post SEO', 'saddle' ),
			'description'         => __( 'Sets a post\'s AIOSEO fields — partial merge, only the fields you pass change; an empty string resets a field to AIOSEO\'s own default. robots_index takes "default"/"index"/"noindex"; robots_follow takes "follow"/"nofollow". AIOSEO\'s "default" switch covers ALL robots flags together, so "default" only takes effect when no other robots flag remains set on the post. The response carries non-blocking length warnings when title/description run long.', 'saddle' ),
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
						'description' => __( 'Focus keyphrase. Additional keyphrases and their scores are preserved.', 'saddle' ),
					),
					'canonical'           => array(
						'type'        => 'string',
						'description' => __( 'Canonical URL override.', 'saddle' ),
					),
					'robots_index'        => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'index', 'noindex' ),
						'description' => __( 'Search-index directive. "default" inherits the site setting — only honored when no other robots flag remains set.', 'saddle' ),
					),
					'robots_follow'       => array(
						'type'        => 'string',
						'enum'        => array( 'follow', 'nofollow' ),
						'description' => __( 'Link-follow directive.', 'saddle' ),
					),
					'robots_advanced'     => array(
						'type'        => 'string',
						'description' => __( 'Comma list of advanced robots flags: noarchive, nosnippet, noimageindex. Empty string clears them.', 'saddle' ),
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
						'description' => __( 'Open Graph custom image URL. Setting it switches the OG image source to "custom".', 'saddle' ),
					),
					'twitter_title'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card title override. Setting any twitter field stops the card mirroring Open Graph.', 'saddle' ),
					),
					'twitter_description' => array(
						'type'        => 'string',
						'description' => __( 'Twitter card description override.', 'saddle' ),
					),
					'twitter_image'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card custom image URL. Setting it switches the Twitter image source to "custom".', 'saddle' ),
					),
					'pillar_content'      => array(
						'type'        => 'boolean',
						'description' => __( 'Mark/unmark this post as AIOSEO pillar content.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Aioseo_Abilities', 'edit_post_seo' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'aioseo-edit-post-seo' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);
}

/**
 * Execute callbacks for the AIOSEO abilities.
 *
 * Permission has already passed (tier + capability + pause + per-ability
 * toggle) by the time these run — same contract as every other ability.
 */
class Saddle_Aioseo_Abilities {

	use Saddle_Mutation_Log;

	/**
	 * saddle/aioseo-check-setup.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function check_setup( $input ) {
		$out = array(
			'aioseo_active'  => Saddle_Aioseo::is_active(),
			'aioseo_version' => Saddle_Aioseo::version(),
		);

		if ( ! $out['aioseo_active'] ) {
			$out['note'] = __( 'AIOSEO is not active on this site. The aioseo-* tools are unavailable; use the regular content tools.', 'saddle' );
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
	 * Shared read guard: AIOSEO active, a real post the user may read.
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function read_post_guard( $input ) {
		if ( ! Saddle_Aioseo::is_active() ) {
			return Saddle_Aioseo::not_active_error();
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
	 * Shared write guard — per-object edit_post on top of the permission
	 * callback's tier + capability (mirrors Saddle_Abilities::authorize_write).
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function edit_post_guard( $input ) {
		if ( ! Saddle_Aioseo::is_active() ) {
			return Saddle_Aioseo::not_active_error();
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
	 * saddle/aioseo-get-post-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_post_seo( $input ) {
		$post = self::read_post_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return Saddle_Aioseo_Seo::get_post_seo( $post );
	}

	/**
	 * saddle/aioseo-edit-post-seo.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function edit_post_seo( $input ) {
		$post = self::edit_post_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = Saddle_Aioseo_Seo::set_post_seo( $post, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log(
			'aioseo-edit-post-seo',
			$post->ID,
			sprintf(
				/* translators: %d: post ID. */
				__( 'Edited AIOSEO fields on post #%d.', 'saddle' ),
				$post->ID
			)
		);

		return array_merge( array( 'id' => $post->ID ), $result );
	}
}
