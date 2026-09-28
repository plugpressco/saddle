<?php
/**
 * Site-editor writes: templates, template parts and saved patterns (#172).
 *
 * The reads in site-editor.php let an agent look at the whole site; these let
 * it change the header, a page template or a reusable section, which is the
 * difference between building a page and building a site. Everything lands in
 * the database, never in the theme's files: templates and parts are written
 * through core's own /wp/v2/templates and /wp/v2/template-parts routes — the
 * ones the Site Editor saves through, which create the customised copy of a
 * theme file, set its theme and area, and check edit_theme_options — and a
 * pattern is a wp_block post.
 *
 * Overwriting a template or part changes every page that uses it, so it goes
 * through the approval gate with a before/after preview. Creating something
 * new is additive and is not gated.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the site-editor write abilities. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_site_editor_write_abilities() {
	$nodes   = array(
		'type'        => 'array',
		'description' => __( 'The blocks, authored like saddle/set-blocks: each {"type","content","attrs","children"}.', 'saddle' ),
		'items'       => array(
			'type'                 => 'object',
			'additionalProperties' => true,
		),
	);
	$content = array(
		'type'        => 'string',
		'description' => __( 'Instead of "nodes": serialized block markup, for example an edited copy of what get-template returned.', 'saddle' ),
	);
	$confirm = array(
		'type'        => 'string',
		'description' => __( 'The single-use token returned by the preview call. Omit on the first call to receive a preview.', 'saddle' ),
	);

	wp_register_ability(
		'saddle/set-template',
		array(
			'label'               => __( 'Set a template', 'saddle' ),
			'description'         => __( 'Replaces the blocks of a template or template part (the header, the footer, the single-post template…) on a block theme. Pass the id from list-templates, its type, and "nodes" or "content". The change is saved in the database as the owner\'s customisation; the theme\'s file is never touched, and the Site Editor can reset to it. Overwriting an existing template changes every page that uses it, so the first call returns a preview with the lines that change and a confirm_token; call again with the token to save. A template id for this theme that doesn\'t exist yet is created without a preview.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'            => array(
						'type'        => 'string',
						'description' => __( 'The template id, e.g. "twentytwentyfive//header".', 'saddle' ),
					),
					'type'          => array(
						'type'    => 'string',
						'enum'    => array( 'template', 'part' ),
						'default' => 'template',
					),
					'nodes'         => $nodes,
					'content'       => $content,
					'confirm_token' => $confirm,
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Editor_Writes', 'set_template' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_theme_options', 'set-template' ),
			'meta'                => saddle_ability_meta( false, true, true, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/create-template-part',
		array(
			'label'               => __( 'Create a template part', 'saddle' ),
			'description'         => __( 'Creates a new template part on a block theme, such as a second footer or a call-to-action strip, saved in the database. Give a slug, a title, its area (header, footer or uncategorized) and "nodes" or "content". Use it in a template with a core/template-part block whose "slug" is this slug. To change an existing part, use saddle/set-template.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'slug', 'title' ),
				'properties' => array(
					'slug'    => array( 'type' => 'string' ),
					'title'   => array( 'type' => 'string' ),
					'area'    => array(
						'type'    => 'string',
						'enum'    => array( 'header', 'footer', 'uncategorized' ),
						'default' => 'uncategorized',
					),
					'nodes'   => $nodes,
					'content' => $content,
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Editor_Writes', 'create_template_part' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_theme_options', 'create-template-part' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/save-pattern',
		array(
			'label'               => __( 'Save a pattern', 'saddle' ),
			'description'         => __( 'Saves blocks as a pattern in the owner\'s pattern library, so the section can be inserted again from the block inserter or with saddle/insert-block-pattern. Take the blocks from a page ("post_id" and the "address" of the block to save, with everything inside it) or pass "nodes" or "content". Unsynced (the default) inserts a copy each time; synced means editing the pattern changes it everywhere it is used.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'title' ),
				'properties' => array(
					'title'   => array( 'type' => 'string' ),
					'synced'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A page or post to take the blocks from, with "address".', 'saddle' ),
					),
					'address' => array(
						'type'        => 'string',
						'description' => __( 'The address of the block to save, from get-blocks.', 'saddle' ),
					),
					'nodes'   => $nodes,
					'content' => $content,
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Editor_Writes', 'save_pattern' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'save-pattern' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);
}

/**
 * Execute callbacks for the site-editor writes.
 */
class Saddle_Site_Editor_Writes {

	/** Most changed lines a preview lists on each side. */
	const DIFF_LINES = 40;

	/**
	 * saddle/set-template.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_template( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$guard = self::require_block_theme();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id        = isset( $input['id'] ) ? trim( (string) $input['id'] ) : '';
		$is_part   = isset( $input['type'] ) && 'part' === $input['type'];
		$post_type = $is_part ? 'wp_template_part' : 'wp_template';
		$route     = $is_part ? '/wp/v2/template-parts' : '/wp/v2/templates';
		$markup    = self::markup( $input, false );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}

		$existing = '' !== $id ? get_block_template( $id, $post_type ) : null;
		if ( ! $existing ) {
			return self::create_template( $id, $route, $markup );
		}

		$before = (string) $existing->content;
		return Saddle_Approval::gate(
			array(
				'action'  => 'set-template',
				'target'  => $id,
				// The token confirms exactly the markup the preview showed.
				'bind'    => substr( hash( 'sha256', $post_type . $markup ), 0, 16 ),
				'summary' => sprintf(
					/* translators: 1: template title, 2: template id. */
					__( 'Replace the blocks of “%1$s” (%2$s). Every page that uses it changes. The theme\'s file is not touched, and the Site Editor can reset to it.', 'saddle' ),
					$existing->title,
					$id
				),
				'preview' => array(
					'id'         => $id,
					'title'      => $existing->title,
					'customized' => 'custom' === $existing->source,
					'changes'    => self::diff( $before, $markup ),
				),
				'input'   => $input,
				'execute' => static function () use ( $route, $id, $markup ) {
					return self::rest( 'POST', $route . '/' . $id, array( 'content' => $markup ) );
				},
			)
		);
	}

	/**
	 * saddle/create-template-part.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_template_part( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$guard = self::require_block_theme();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'saddle_missing_slug', __( 'Pass a "slug" for the new part.', 'saddle' ), array( 'status' => 400 ) );
		}
		if ( get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' ) ) {
			return new WP_Error( 'saddle_exists', __( 'A template part with that slug already exists. Change it with saddle/set-template (type "part") instead.', 'saddle' ), array( 'status' => 409 ) );
		}
		$markup = self::markup( $input, false );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}

		$area = isset( $input['area'] ) && in_array( $input['area'], array( 'header', 'footer' ), true ) ? $input['area'] : 'uncategorized';
		$made = self::rest(
			'POST',
			'/wp/v2/template-parts',
			array(
				'slug'    => $slug,
				'title'   => sanitize_text_field( (string) $input['title'] ),
				'area'    => $area,
				'content' => $markup,
			)
		);
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		Saddle_Log::record_action(
			'create-template-part',
			$made['id'],
			/* translators: 1: part title, 2: part id. */
			sprintf( __( 'Created the template part “%1$s” (%2$s).', 'saddle' ), sanitize_text_field( (string) $input['title'] ), $made['id'] )
		);
		return $made;
	}

	/**
	 * saddle/save-pattern.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function save_pattern( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'saddle_missing_title', __( 'Pass a "title" for the pattern.', 'saddle' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( get_post_type_object( 'wp_block' )->cap->create_posts ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'This WordPress account may not create patterns.', 'saddle' ), array( 'status' => 403 ) );
		}
		$markup = self::markup( $input, true );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => wp_slash( $markup ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$synced = ! empty( $input['synced'] );
		if ( ! $synced ) {
			// Core's own marker; a synced pattern simply has none.
			update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
		}

		Saddle_Log::record_action(
			'save-pattern',
			$id,
			/* translators: 1: pattern title, 2: pattern id. */
			sprintf( __( 'Saved the pattern “%1$s” (#%2$d).', 'saddle' ), $title, $id )
		);
		return array(
			'id'     => (int) $id,
			'title'  => $title,
			'synced' => $synced,
			'usage'  => __( 'It is in the block inserter under Patterns → My patterns. Insert it with saddle/insert-block-pattern.', 'saddle' ),
		);
	}

	/*
	---------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------
	 */

	/**
	 * A template for this theme that doesn't exist yet: created, not gated.
	 *
	 * @param string $id     Requested id, "stylesheet//slug".
	 * @param string $route  REST collection route.
	 * @param string $markup Block markup.
	 * @return array|WP_Error
	 */
	private static function create_template( $id, $route, $markup ) {
		$parts = explode( '//', $id, 2 );
		if ( 2 !== count( $parts ) || get_stylesheet() !== $parts[0] || '' === sanitize_title( $parts[1] ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No template with that id. Call list-templates for the ids this site has; a new one must be "<active theme>//<slug>".', 'saddle' ), array( 'status' => 404 ) );
		}
		$made = self::rest(
			'POST',
			$route,
			array(
				'slug'    => sanitize_title( $parts[1] ),
				'content' => $markup,
			)
		);
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		Saddle_Log::record_action(
			'set-template',
			$made['id'],
			/* translators: %s: template id. */
			sprintf( __( 'Created the template %s.', 'saddle' ), $made['id'] )
		);
		return $made;
	}

	/**
	 * The block markup to write, from "nodes", "content", or (patterns) a
	 * page's subtree. Patterns go into posts, so they must pass the same
	 * placement rules a post does; templates legally hold template-only blocks
	 * those rules don't know, so for them a parse is enough.
	 *
	 * @param array $input  Ability input.
	 * @param bool  $strict Whether to enforce the placement rules.
	 * @return string|WP_Error
	 */
	private static function markup( array $input, $strict ) {
		if ( ! empty( $input['post_id'] ) ) {
			$post = Saddle_Abilities::require_readable_post( $input );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$node = Saddle_Blocks_Tree::get( Saddle_Blocks_Tree::parse( $post->post_content ), isset( $input['address'] ) ? (string) $input['address'] : '' );
			if ( ! $node ) {
				return new WP_Error( 'saddle_bad_address', __( 'No block at that address. Read the page with get-blocks.', 'saddle' ), array( 'status' => 400 ) );
			}
			$tree = array( $node );
		} elseif ( isset( $input['nodes'] ) && is_array( $input['nodes'] ) && $input['nodes'] ) {
			$tree = Saddle_Blocks_Author::expand( $input['nodes'] );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
		} elseif ( isset( $input['content'] ) && is_string( $input['content'] ) ) {
			$tree = array_values(
				array_filter(
					Saddle_Blocks_Tree::parse( $input['content'] ),
					static function ( $block ) {
						return ! empty( $block['blockName'] );
					}
				)
			);
		} else {
			$tree = array();
		}

		if ( ! $tree ) {
			return new WP_Error( 'saddle_empty', __( 'Pass the blocks as "nodes" or as block markup in "content".', 'saddle' ), array( 'status' => 400 ) );
		}
		if ( $strict ) {
			$valid = Saddle_Blocks_Tree::validate( $tree );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		return Saddle_Blocks_Tree::serialize( $tree );
	}

	/**
	 * Lines removed and added between two block markups, for the preview.
	 *
	 * @param string $before Current markup.
	 * @param string $after  Proposed markup.
	 * @return array
	 */
	private static function diff( $before, $after ) {
		$lines   = static function ( $markup ) {
			// One block comment per line, so the diff reads block by block.
			$split = preg_split( '/\n|(?=<!-- \/?wp:)/', (string) $markup );
			return array_values( array_filter( array_map( 'trim', $split ), 'strlen' ) );
		};
		$old     = $lines( $before );
		$new     = $lines( $after );
		$removed = array_values( array_diff( $old, $new ) );
		$added   = array_values( array_diff( $new, $old ) );
		return array(
			'removed'   => array_slice( $removed, 0, self::DIFF_LINES ),
			'added'     => array_slice( $added, 0, self::DIFF_LINES ),
			'truncated' => count( $removed ) > self::DIFF_LINES || count( $added ) > self::DIFF_LINES,
		);
	}

	/**
	 * Call one of core's REST routes in-process, as the current user.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Parameters.
	 * @return array|WP_Error
	 */
	private static function rest( $method, $route, array $params ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		$data = $response->get_data();
		return array(
			'id'      => isset( $data['id'] ) ? $data['id'] : '',
			'title'   => isset( $data['title']['raw'] ) ? $data['title']['raw'] : ( isset( $data['title']['rendered'] ) ? $data['title']['rendered'] : '' ),
			'source'  => isset( $data['source'] ) ? $data['source'] : '',
			'area'    => isset( $data['area'] ) ? $data['area'] : null,
			'wp_id'   => isset( $data['wp_id'] ) ? (int) $data['wp_id'] : 0,
			'updated' => true,
		);
	}

	/**
	 * Templates and parts exist only on a block theme.
	 *
	 * @return true|WP_Error
	 */
	private static function require_block_theme() {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return true;
		}
		return new WP_Error(
			'saddle_not_block_theme',
			__( 'This site does not use a block theme, so it has no block templates or template parts to change. Its header and footer live in the theme\'s PHP files, which Saddle does not edit.', 'saddle' ),
			array( 'status' => 409 )
		);
	}
}
