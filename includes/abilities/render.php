<?php
/**
 * The render abilities — the agent's eyes.
 *
 * saddle/render-node shows an agent the EFFECTIVE result of what it built:
 * persisted attrs resolved through presets/globals into a normalized style
 * map, plus rendered HTML. Native pages use the Gutenberg accessor; builder
 * pages resolve theirs through the `saddle_render_accessor` filter — the
 * same one-tool-surface pattern as lint (closed-loop scope,
 * https://github.com/plugpressco/saddle/issues/24).
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the render abilities. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_render_abilities() {

	wp_register_ability(
		'saddle/render-node',
		array(
			'label'               => __( 'Render a node', 'saddle' ),
			'description'         => __( 'Shows what a page node actually looks like from its SAVED state: effective styles (colors, padding, font size, radius — presets and global tokens resolved to real values) and its rendered HTML. Read-only. Call with an "address" from get-blocks/divi-get-page to inspect one node after building or editing it; call without an address for a whole-page section outline (address, type, first text, key styles per section) and then drill into sections by address. Use it as your eyes: build, render, judge, fix.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page to look at.', 'saddle' ),
					),
					'address' => array(
						'type'        => 'string',
						'description' => __( 'Dot address of one node (e.g. "0.1.0"). Omit for the whole-page outline.', 'saddle' ),
					),
					'include' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'styles', 'html' ),
						),
						'description' => __( 'Artifacts to include for a node (default: both).', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Render_Abilities', 'render_node' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'render-node' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-preview-url',
		array(
			'label'               => __( 'Get a preview URL', 'saddle' ),
			'description'         => __( 'Returns a short-lived, signed URL that renders the post\'s CURRENT saved layout on the site\'s own front end — drafts included, no login needed, expires in about 5 minutes. This is how you SEE real pixels: open the URL in your own browser and screenshot it (Saddle never renders or sends anything anywhere). The page is noindex and the link opens only this one post; treat it as ephemeral and do not share it.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page to preview.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Render_Abilities', 'get_preview_url' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-preview-url' ),
			'meta'                => saddle_ability_meta( true, false, false, 'read' ),
		)
	);
}

/**
 * Execute callbacks for the render abilities.
 */
class Saddle_Render_Abilities {

	/**
	 * saddle/render-node.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function render_node( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You cannot read this post.', 'saddle' ), array( 'status' => 403 ) );
		}

		$accessor = self::accessor_for( $post );
		if ( is_wp_error( $accessor ) ) {
			return $accessor;
		}

		$tree    = Saddle_Tree::parse( $post->post_content );
		$builder = Saddle_Abilities::builder_signature( $post );
		$address = isset( $input['address'] ) ? trim( (string) $input['address'] ) : '';

		if ( '' === $address ) {
			$outline = Saddle_Render::outline( $tree, $accessor );
			return array(
				'id'      => $post->ID,
				'builder' => null === $builder ? 'native' : $builder,
				'outline' => $outline,
				'count'   => count( $outline ),
				'note'    => __( 'Top-level sections only. Drill into one with the same call plus its "address". Styles shown are the persisted effective values, not a browser render.', 'saddle' ),
			);
		}

		$include = self::include_list( $input );
		$view    = Saddle_Render::node( $post, $tree, $address, $accessor, $include );
		if ( is_wp_error( $view ) ) {
			return $view;
		}

		return array_merge(
			array( 'id' => $post->ID ),
			$view,
			array(
				'note' => __( 'Effective persisted styles with presets/tokens resolved — what the saved state means, not a browser screenshot.', 'saddle' ),
			)
		);
	}

	/**
	 * saddle/get-preview-url.
	 *
	 * A preview link exposes unpublished content to whoever holds it, so the
	 * caller must be allowed to SEE that content first: published posts need
	 * read access, anything else needs edit rights — a read-only viewer must
	 * not mint a window into someone's draft.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_preview_url( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}

		$cap = 'publish' === $post->post_status ? 'read_post' : 'edit_post';
		if ( ! current_user_can( $cap, $post->ID ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You cannot preview this post.', 'saddle' ), array( 'status' => 403 ) );
		}

		$minted = Saddle_Preview::mint( $post );

		return array(
			'id'         => $post->ID,
			'url'        => $minted['url'],
			'expires_in' => $minted['expires_in'],
			'note'       => __( 'Open and screenshot this in YOUR browser — it renders the current saved layout (drafts included) on the site\'s own front end, is noindex, opens only this post, and expires. Do not share it.', 'saddle' ),
		);
	}

	/**
	 * Resolve the render accessor for a post: Gutenberg for native pages,
	 * the `saddle_render_accessor` filter for builder pages.
	 *
	 * @param WP_Post $post The post.
	 * @return Saddle_Render_Accessor|WP_Error
	 */
	private static function accessor_for( WP_Post $post ) {
		$builder  = Saddle_Abilities::builder_signature( $post );
		$accessor = null === $builder ? new Saddle_Render_Gutenberg_Accessor() : null;

		/**
		 * Filter the render accessor for a page.
		 *
		 * Builder integrations (Saddle Pro's Divi driver, Elementor/Bricks
		 * later) return their Saddle_Render_Accessor implementation when
		 * they own $builder. Null means the page cannot be rendered here.
		 *
		 * @param Saddle_Render_Accessor|null $accessor Accessor (Gutenberg's for native pages).
		 * @param string|null                 $builder  Detected builder, null = native.
		 * @param WP_Post                     $post     The post.
		 */
		$accessor = apply_filters( 'saddle_render_accessor', $accessor, $builder, $post );

		if ( ! $accessor instanceof Saddle_Render_Accessor ) {
			return new WP_Error(
				'saddle_render_unsupported',
				sprintf(
					/* translators: 1: post ID, 2: builder name. */
					__( 'Post #%1$d is built with %2$s, and no render accessor for that builder is installed. Divi 5 pages need Saddle Pro.', 'saddle' ),
					$post->ID,
					(string) $builder
				),
				array( 'status' => 409 )
			);
		}
		return $accessor;
	}

	/**
	 * The validated include list, defaulting to both artifacts.
	 *
	 * @param array $input Ability input.
	 * @return string[]
	 */
	private static function include_list( array $input ) {
		$include = isset( $input['include'] ) && is_array( $input['include'] ) ? $input['include'] : array();
		$include = array_values( array_intersect( array( 'styles', 'html' ), array_map( 'strval', $include ) ) );
		return $include ? $include : array( 'styles', 'html' );
	}
}
