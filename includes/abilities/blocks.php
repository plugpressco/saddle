<?php
/**
 * Native block design abilities (Gutenberg structured editing).
 *
 * Eleven abilities giving agents structured, validated access to native
 * editor content — the free half of Saddle's design story (Saddle Pro adds
 * page-builder-native depth on the same engine). Same conventions as
 * core-content.php: dash-named `saddle/` ids, explicit tier + destructive
 * meta, Saddle_Capabilities permission callbacks, destructive ops through
 * Saddle_Approval::gate(), executed mutations logged via Saddle_Log.
 *
 * The write abilities work on NATIVE content only: posts built with a page
 * builder are refused (the builder-content guard's reasoning — a structural
 * write from the wrong toolset destroys the layout). Builder pages are
 * edited with builder tools (saddle/divi-* with Saddle Pro) or left alone.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the block design abilities. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_block_abilities() {
	/*
	 * ---------------------------------------------------------------------
	 * Design vocabulary (read)
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/list-block-types',
		array(
			'label'               => __( 'List block types', 'saddle' ),
			'description'         => __( 'Returns the catalog of editor block types registered on this site, each with its title, category, one-line purpose, and how it is authored: "content" (Saddle composes valid markup from your content/attrs), "attrs-only" (dynamic block, attributes only), or "raw-html" (you must supply exact markup — prefer the other kinds). Read-only. Supports search and category filters. Build pages from real blocks — never approximate a layout by dumping everything into one core/html block.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword filter on name/title/purpose.', 'saddle' ),
					),
					'category' => array(
						'type'        => 'string',
						'description' => __( 'Optional block category filter, e.g. "text", "media", "design".', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'list_block_types' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-block-types' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-block-schema',
		array(
			'label'               => __( 'Get block schema', 'saddle' ),
			'description'         => __( 'Returns one block type\'s schema: its attributes (with defaults, enums, and which are markup-sourced), placement constraints (required parents/ancestors, allowed children), supports, whether it renders dynamically, and exactly how to author it — with a concrete example node for the common core blocks. Read this before composing a block you have not used yet.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'type' ),
				'properties' => array(
					'type' => array(
						'type'        => 'string',
						'description' => __( 'The block type, e.g. "core/heading".', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'get_block_schema' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-block-schema' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-design-tokens',
		array(
			'label'               => __( 'Get design tokens', 'saddle' ),
			'description'         => __( 'Returns this site\'s design system from theme.json: the color palette, gradients, font sizes, font families, spacing scale, and layout widths, each as {slug, name, value} presets (theme-origin entries are the site\'s actual brand). Read-only. Use these preset slugs in block attrs (backgroundColor/textColor/fontSize) instead of hardcoded values, so everything you build inherits the site\'s look and follows future redesigns.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'get_design_tokens' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-design-tokens' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/list-block-patterns',
		array(
			'label'               => __( 'List block patterns', 'saddle' ),
			'description'         => __( 'Lists the block patterns registered on this site — prebuilt, theme-styled sections (heroes, feature grids, CTAs, footers) — as summaries: name, title, description, categories. Read-only. Prefer inserting a pattern (saddle/insert-block-pattern) over hand-composing a common section: patterns already match the site\'s design.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword filter on name/title/description.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'list_block_patterns' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-block-patterns' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Block tree (read + write)
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/get-blocks',
		array(
			'label'               => __( 'Get page blocks', 'saddle' ),
			'description'         => __( 'Returns a post or page\'s block tree as a flat, addressable node list: each node has an address (dot-separated child indexes like "0.1.0"), its block type, attributes, child count, and a text excerpt. Addresses are positional — re-read after any edit before addressing further nodes. Read-only. Refuses page-builder-built posts (those are edited with builder tools, not native block tools); classic (non-block) content comes back as one freeform node.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page ID whose blocks to read.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'get_blocks' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-blocks' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/set-blocks',
		array(
			'label'               => __( 'Build page blocks', 'saddle' ),
			'description'         => __( 'Replaces a post or page\'s entire content with a new block tree — the bulk-build tool for "design this page". Each node is {"type","content","attrs","children"}: "content" carries the text/media payload and Saddle composes editor-valid markup (see saddle/get-block-schema per type); "attrs" are block attributes — use preset slugs from saddle/get-design-tokens for colors and sizes. The tree is validated against each block\'s placement rules and rejected with per-node errors if invalid — nothing partial is saved. If the response carries "warnings", those attributes will silently not render (unknown attribute, preset slug, or style group) — fix and re-edit. The previous content is kept as a WordPress revision. Refuses page-builder-built posts.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'nodes' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page whose content to build.', 'saddle' ),
					),
					'nodes'   => array(
						'type'        => 'array',
						'description' => __( 'Top-level blocks (each {"type","content","attrs","children"}).', 'saddle' ),
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'set_blocks' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'set-blocks' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/add-block',
		array(
			'label'               => __( 'Add block', 'saddle' ),
			'description'         => __( 'Inserts one block (with optional children) into a post or page at an addressed position. parent_address is a container node from saddle/get-blocks ("" for the top level); position is the index among its children (omit to append). The node uses the same {"type","content","attrs","children"} format as saddle/set-blocks. Placement is validated — an insertion that breaks a block\'s parent/child rules is rejected and nothing is saved. Re-read the page afterwards: addresses shift.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'node' ),
				'properties' => array(
					'post_id'        => array( 'type' => 'integer' ),
					'parent_address' => array(
						'type'        => 'string',
						'description' => __( 'Container address from saddle/get-blocks; "" for the top level.', 'saddle' ),
					),
					'position'       => array(
						'type'        => 'integer',
						'description' => __( 'Index among the parent\'s children; omit or -1 to append.', 'saddle' ),
					),
					'node'           => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'add_block' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'add-block' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/edit-block',
		array(
			'label'               => __( 'Edit block', 'saddle' ),
			'description'         => __( 'Rewrites one addressed block on a post or page. Provide "content" and/or "attrs" — the block is recomposed from its existing values with yours applied (same authoring semantics as saddle/set-blocks), children are kept untouched. The result is validated before saving, and the previous state is kept as a revision. Response "warnings" flag attributes that will silently not render.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'address' => array(
						'type'        => 'string',
						'description' => __( 'Node address from saddle/get-blocks, e.g. "2.0".', 'saddle' ),
					),
					'content' => array(
						'type'        => array( 'string', 'object', 'array' ),
						'description' => __( 'New content payload for the block (string, or the object/array forms its schema documents).', 'saddle' ),
					),
					'attrs'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'Attributes merged over the block\'s existing ones.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'edit_block' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'edit-block' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/move-block',
		array(
			'label'               => __( 'Move block', 'saddle' ),
			'description'         => __( 'Moves an addressed block (with its subtree) to a new parent and position on the same post or page. Both addresses come from the same saddle/get-blocks read. Moving a block into its own subtree is refused; the result is validated before saving. Re-read the page afterwards: addresses shift.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'from_address' ),
				'properties' => array(
					'post_id'           => array( 'type' => 'integer' ),
					'from_address'      => array( 'type' => 'string' ),
					'to_parent_address' => array(
						'type'        => 'string',
						'description' => __( 'Destination container; "" for the top level.', 'saddle' ),
					),
					'position'          => array(
						'type'        => 'integer',
						'description' => __( 'Index among the destination\'s children; omit or -1 to append.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'move_block' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'move-block' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/remove-block',
		array(
			'label'               => __( 'Remove block', 'saddle' ),
			'description'         => __( 'Removes an addressed block from a post or page. Removing a childless block happens immediately (recoverable from revisions). Removing a container WITH children previews first: the call returns a summary and a confirm_token — repeat the call with that token to execute. Re-read the page afterwards: addresses shift.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => array(
					'post_id'       => array( 'type' => 'integer' ),
					'address'       => array( 'type' => 'string' ),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'Token from the preview step, required to remove a block that has children.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'remove_block' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'remove-block' ),
			'meta'                => saddle_ability_meta( false, true, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/insert-block-pattern',
		array(
			'label'               => __( 'Insert block pattern', 'saddle' ),
			'description'         => __( 'Inserts a registered block pattern (see saddle/list-block-patterns) into a post or page at an addressed position — the fastest way to add a professional, theme-styled section. parent_address is a container from saddle/get-blocks ("" for the top level). The resulting tree is validated before saving; the previous content is kept as a revision. Re-read the page afterwards: addresses shift.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'pattern' ),
				'properties' => array(
					'post_id'        => array( 'type' => 'integer' ),
					'pattern'        => array(
						'type'        => 'string',
						'description' => __( 'The pattern name from saddle/list-block-patterns.', 'saddle' ),
					),
					'parent_address' => array(
						'type'        => 'string',
						'description' => __( 'Container address; "" for the top level.', 'saddle' ),
					),
					'position'       => array(
						'type'        => 'integer',
						'description' => __( 'Index among the parent\'s children; omit or -1 to append.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Blocks_Abilities', 'insert_block_pattern' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'insert-block-pattern' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);
}

/**
 * Execute callbacks for the block design abilities.
 *
 * Permission (tier + capability + pause + per-ability toggle) has already
 * passed by the time these run — same contract as Saddle_Abilities.
 */
class Saddle_Blocks_Abilities {

	/**
	 * saddle/list-block-types.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_block_types( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$types = Saddle_Blocks_Schema::catalog(
			isset( $input['search'] ) ? (string) $input['search'] : '',
			isset( $input['category'] ) ? (string) $input['category'] : ''
		);

		return array(
			'block_types' => $types,
			'note'        => __( 'Compose real blocks ("content" ones are easiest — saddle/get-block-schema shows each type\'s exact syntax). Prefer saddle/insert-block-pattern for common sections, and preset slugs from saddle/get-design-tokens for colors and sizes.', 'saddle' ),
		);
	}

	/**
	 * saddle/get-block-schema.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_block_schema( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$type  = isset( $input['type'] ) ? trim( (string) $input['type'] ) : '';
		if ( '' === $type ) {
			return new WP_Error( 'saddle_missing_type', __( 'Provide a block "type", e.g. "core/heading".', 'saddle' ) );
		}
		return Saddle_Blocks_Schema::describe( $type );
	}

	/**
	 * saddle/get-design-tokens.
	 *
	 * @return array
	 */
	public static function get_design_tokens() {
		return Saddle_Blocks_Schema::design_tokens();
	}

	/**
	 * saddle/list-block-patterns.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_block_patterns( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		return array(
			'patterns' => Saddle_Blocks_Schema::patterns( isset( $input['search'] ) ? (string) $input['search'] : '' ),
			'note'     => __( 'Insert with saddle/insert-block-pattern. Patterns arrive already styled for this theme — prefer them over hand-composing common sections.', 'saddle' ),
		);
	}

	/**
	 * saddle/get-blocks.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_blocks( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You cannot read this post.', 'saddle' ), array( 'status' => 403 ) );
		}

		$guarded = self::guard_native( $post );
		if ( is_wp_error( $guarded ) ) {
			return $guarded;
		}

		$tree  = Saddle_Blocks_Tree::parse( $post->post_content );
		$valid = Saddle_Blocks_Tree::validate( $tree );

		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'tree_valid' => ! is_wp_error( $valid ),
			'nodes'      => Saddle_Blocks_Tree::flatten( $tree ),
		);
	}

	/**
	 * saddle/set-blocks.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_blocks( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$nodes = isset( $input['nodes'] ) && is_array( $input['nodes'] ) ? $input['nodes'] : array();
		if ( ! $nodes ) {
			return new WP_Error( 'saddle_empty', __( 'Provide at least one block node.', 'saddle' ) );
		}

		$tree = Saddle_Blocks_Author::expand( $nodes );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$count = self::persist_tree( $post, $tree );
		if ( is_wp_error( $count ) ) {
			return $count;
		}

		Saddle_Log::record_action(
			'set-blocks',
			$post->ID,
			sprintf(
				/* translators: 1: post ID, 2: block count. */
				__( 'Built block content for post #%1$d (%2$d blocks). Previous content kept as a revision.', 'saddle' ),
				$post->ID,
				$count
			)
		);

		return self::with_warnings(
			array(
				'id'     => $post->ID,
				'blocks' => $count,
				'link'   => get_permalink( $post ),
				'note'   => __( 'The previous content is recoverable from the post\'s revisions.', 'saddle' ),
			),
			Saddle_Blocks_Echo::check_nodes( $nodes )
		);
	}

	/**
	 * saddle/add-block.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function add_block( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$node = isset( $input['node'] ) && is_array( $input['node'] ) ? $input['node'] : null;
		if ( ! $node ) {
			return new WP_Error( 'saddle_empty', __( 'Provide the block to add as "node".', 'saddle' ) );
		}

		$block = Saddle_Blocks_Author::expand_node( $node );
		if ( is_wp_error( $block ) ) {
			return $block;
		}

		$tree     = Saddle_Blocks_Tree::parse( $post->post_content );
		$parent   = isset( $input['parent_address'] ) ? trim( (string) $input['parent_address'] ) : '';
		$position = isset( $input['position'] ) ? (int) $input['position'] : -1;

		$at = self::resolve_position( $tree, $parent, $position );
		if ( is_wp_error( $at ) ) {
			return $at;
		}

		$next = Saddle_Blocks_Tree::insert( $tree, $parent, $at, $block );
		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$count = self::persist_tree( $post, $next );
		if ( is_wp_error( $count ) ) {
			return $count;
		}

		$address = '' === $parent ? (string) $at : $parent . '.' . $at;
		Saddle_Log::record_action(
			'add-block',
			$post->ID,
			sprintf(
				/* translators: 1: block type, 2: post ID, 3: address. */
				__( 'Added %1$s to post #%2$d at %3$s.', 'saddle' ),
				(string) $node['type'],
				$post->ID,
				$address
			)
		);

		return self::with_warnings(
			array(
				'id'     => $post->ID,
				'added'  => $address,
				'blocks' => $count,
				'note'   => __( 'Addresses shift after edits — re-read the page before addressing other nodes.', 'saddle' ),
			),
			Saddle_Blocks_Echo::check_node( $node, $address )
		);
	}

	/**
	 * saddle/edit-block.
	 *
	 * The block is recomposed through the authoring layer from its existing
	 * attrs with the patch applied, so markup-sourced values stay consistent
	 * with the attribute JSON; existing children are reattached untouched.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function edit_block( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$address = isset( $input['address'] ) ? trim( (string) $input['address'] ) : '';
		$tree    = Saddle_Blocks_Tree::parse( $post->post_content );
		$node    = '' !== $address ? Saddle_Blocks_Tree::get( $tree, $address ) : null;
		if ( ! $node ) {
			return new WP_Error( 'saddle_bad_address', sprintf( 'No block at address %s.', $address ) );
		}

		$has_content = array_key_exists( 'content', $input );
		$attrs_patch = isset( $input['attrs'] ) && is_array( $input['attrs'] ) ? $input['attrs'] : array();
		if ( ! $has_content && ! $attrs_patch ) {
			return new WP_Error( 'saddle_empty', __( 'Provide "content" and/or "attrs" to change.', 'saddle' ) );
		}

		$authored = array(
			'type'  => (string) $node['blockName'],
			'attrs' => array_replace_recursive( is_array( $node['attrs'] ) ? $node['attrs'] : array(), $attrs_patch ),
		);
		if ( $has_content ) {
			$authored['content'] = $input['content'];
		} elseif ( ! $node['innerBlocks'] ) {
			// Keep the block's current inner markup when only attrs change.
			$authored['html'] = (string) $node['innerHTML'];
		}

		$fresh = Saddle_Blocks_Author::expand_node( $authored, $address );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		// Reattach existing children (content/attrs edits never touch them).
		if ( $node['innerBlocks'] ) {
			$open  = (string) $fresh['innerHTML'];
			$close = '';
			// Split the recomposed wrapper back into open/close halves.
			if ( '' !== $open && is_array( $fresh['innerContent'] ) && 1 === count( $fresh['innerContent'] ) ) {
				$tag_end = strrpos( $open, '</' );
				if ( false !== $tag_end ) {
					$close = substr( $open, $tag_end );
					$open  = substr( $open, 0, $tag_end );
				}
			}
			$fresh['innerBlocks']  = $node['innerBlocks'];
			$fresh['innerHTML']    = $open . $close;
			$fresh['innerContent'] = array_merge(
				'' !== $open ? array( $open ) : array(),
				array_fill( 0, count( $node['innerBlocks'] ), null ),
				'' !== $close ? array( $close ) : array()
			);
		}

		$next = Saddle_Blocks_Tree::replace( $tree, $address, $fresh );
		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$count = self::persist_tree( $post, $next );
		if ( is_wp_error( $count ) ) {
			return $count;
		}

		Saddle_Log::record_action(
			'edit-block',
			$post->ID,
			sprintf(
				/* translators: 1: block type, 2: address, 3: post ID. */
				__( 'Edited %1$s at %2$s on post #%3$d.', 'saddle' ),
				(string) $node['blockName'],
				$address,
				$post->ID
			)
		);

		return self::with_warnings(
			array(
				'id'     => $post->ID,
				'edited' => $address,
				'blocks' => $count,
			),
			Saddle_Blocks_Echo::check_attrs( (string) $node['blockName'], $attrs_patch, $address )
		);
	}

	/**
	 * saddle/move-block.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function move_block( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$from      = isset( $input['from_address'] ) ? trim( (string) $input['from_address'] ) : '';
		$to_parent = isset( $input['to_parent_address'] ) ? trim( (string) $input['to_parent_address'] ) : '';
		$position  = isset( $input['position'] ) ? (int) $input['position'] : -1;

		if ( '' === $from ) {
			return new WP_Error( 'saddle_empty', __( 'Provide "from_address".', 'saddle' ) );
		}
		if ( $to_parent === $from || 0 === strpos( $to_parent . '.', $from . '.' ) ) {
			return new WP_Error( 'saddle_bad_move', __( 'A block cannot be moved into its own subtree.', 'saddle' ) );
		}

		$tree = Saddle_Blocks_Tree::parse( $post->post_content );
		$node = Saddle_Blocks_Tree::get( $tree, $from );
		if ( ! $node ) {
			return new WP_Error( 'saddle_bad_address', sprintf( 'No block at address %s.', $from ) );
		}

		$without = Saddle_Blocks_Tree::remove( $tree, $from );
		if ( is_wp_error( $without ) ) {
			return $without;
		}

		// Removing shifts later sibling indexes at the removal level — adjust
		// the destination if it was addressed in the same snapshot.
		$dest = self::adjust_after_removal( $from, $to_parent );

		$at = self::resolve_position( $without, $dest, $position );
		if ( is_wp_error( $at ) ) {
			return new WP_Error( 'saddle_bad_address', sprintf( 'No block at address %s.', $to_parent ) );
		}

		$next = Saddle_Blocks_Tree::insert( $without, $dest, $at, $node );
		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$count = self::persist_tree( $post, $next );
		if ( is_wp_error( $count ) ) {
			return $count;
		}

		$new_address = '' === $dest ? (string) $at : $dest . '.' . $at;
		Saddle_Log::record_action(
			'move-block',
			$post->ID,
			sprintf(
				/* translators: 1: block type, 2: old address, 3: new address, 4: post ID. */
				__( 'Moved %1$s from %2$s to %3$s on post #%4$d.', 'saddle' ),
				(string) $node['blockName'],
				$from,
				$new_address,
				$post->ID
			)
		);

		return array(
			'id'     => $post->ID,
			'moved'  => $new_address,
			'blocks' => $count,
			'note'   => __( 'Addresses shift after edits — re-read the page before addressing other nodes.', 'saddle' ),
		);
	}

	/**
	 * saddle/remove-block.
	 *
	 * Childless blocks are removed immediately (revisions recover them). A
	 * container with children routes through the approval gate: first call
	 * returns a preview + confirm_token, the second call (with the token)
	 * executes.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function remove_block( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$address = isset( $input['address'] ) ? trim( (string) $input['address'] ) : '';
		$tree    = Saddle_Blocks_Tree::parse( $post->post_content );
		$node    = '' !== $address ? Saddle_Blocks_Tree::get( $tree, $address ) : null;
		if ( ! $node ) {
			return new WP_Error( 'saddle_bad_address', sprintf( 'No block at address %s.', $address ) );
		}

		$execute = static function () use ( $post, $tree, $address ) {
			$next = Saddle_Blocks_Tree::remove( $tree, $address );
			if ( is_wp_error( $next ) ) {
				return $next;
			}
			$count = self::persist_tree( $post, $next );
			if ( is_wp_error( $count ) ) {
				return $count;
			}
			return array(
				'id'      => $post->ID,
				'removed' => $address,
				'blocks'  => $count,
				'note'    => __( 'Recoverable from the post\'s revisions. Addresses shift — re-read the page.', 'saddle' ),
			);
		};

		$child_count = count( $node['innerBlocks'] );

		if ( 0 === $child_count ) {
			$result = $execute();
			if ( ! is_wp_error( $result ) ) {
				Saddle_Log::record_action(
					'remove-block',
					$post->ID,
					sprintf(
						/* translators: 1: block type, 2: address, 3: post ID. */
						__( 'Removed %1$s at %2$s from post #%3$d.', 'saddle' ),
						(string) $node['blockName'],
						$address,
						$post->ID
					)
				);
			}
			return $result;
		}

		return Saddle_Approval::gate(
			array(
				'action'  => 'remove-block',
				'target'  => $post->ID . ':' . $address,
				'summary' => sprintf(
					/* translators: 1: block type, 2: child count, 3: address, 4: post ID. */
					__( 'Remove %1$s and the %2$d blocks inside it at %3$s on post #%4$d. Recoverable from revisions.', 'saddle' ),
					(string) $node['blockName'],
					$child_count,
					$address,
					$post->ID
				),
				'preview' => array(
					'address'  => $address,
					'type'     => (string) $node['blockName'],
					'children' => $child_count,
				),
				'input'   => $input,
				'execute' => $execute,
			)
		);
	}

	/**
	 * saddle/insert-block-pattern.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function insert_block_pattern( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$pattern = isset( $input['pattern'] ) ? trim( (string) $input['pattern'] ) : '';
		$blocks  = Saddle_Blocks_Schema::pattern_tree( $pattern );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$tree     = Saddle_Blocks_Tree::parse( $post->post_content );
		$parent   = isset( $input['parent_address'] ) ? trim( (string) $input['parent_address'] ) : '';
		$position = isset( $input['position'] ) ? (int) $input['position'] : -1;

		$at = self::resolve_position( $tree, $parent, $position );
		if ( is_wp_error( $at ) ) {
			return $at;
		}

		$next = $tree;
		foreach ( $blocks as $offset => $block ) {
			$next = Saddle_Blocks_Tree::insert( $next, $parent, $at + $offset, $block );
			if ( is_wp_error( $next ) ) {
				return $next;
			}
		}

		$count = self::persist_tree( $post, $next );
		if ( is_wp_error( $count ) ) {
			return $count;
		}

		$address = '' === $parent ? (string) $at : $parent . '.' . $at;
		Saddle_Log::record_action(
			'insert-block-pattern',
			$post->ID,
			sprintf(
				/* translators: 1: pattern name, 2: post ID, 3: address. */
				__( 'Inserted pattern "%1$s" into post #%2$d at %3$s.', 'saddle' ),
				$pattern,
				$post->ID,
				$address
			)
		);

		return array(
			'id'       => $post->ID,
			'inserted' => $address,
			'blocks'   => $count,
			'note'     => __( 'Addresses shift after edits — re-read the page before addressing other nodes.', 'saddle' ),
		);
	}

	/*
	---------------------------------------------------------------------
	 * Shared plumbing
	 * -------------------------------------------------------------------
	 */

	/**
	 * Attach applied-vs-ignored echo warnings to a successful write response.
	 * The write already landed — warnings tell the agent which of its style
	 * intentions did NOT take effect, so it can correct instead of assuming.
	 *
	 * @param array    $result   Ability response.
	 * @param string[] $warnings Echo warnings (possibly empty).
	 * @return array
	 */
	private static function with_warnings( array $result, array $warnings ) {
		if ( $warnings ) {
			$result['warnings'] = array_values( $warnings );
		}
		return $result;
	}

	/**
	 * Refuse builder-built posts: their layout belongs to the builder's own
	 * tools, and a native-block write would destroy it.
	 *
	 * @param WP_Post $post Target post.
	 * @return true|WP_Error
	 */
	private static function guard_native( $post ) {
		$builder = Saddle_Abilities::builder_signature( $post );
		if ( null === $builder ) {
			return true;
		}

		$divi5_tools = 'Divi 5' === $builder
			&& function_exists( 'wp_get_abilities' )
			&& isset( wp_get_abilities()['saddle/divi-get-page'] );

		return new WP_Error(
			'saddle_builder_content',
			sprintf(
				/* translators: 1: post ID, 2: builder name. */
				__( 'Post #%1$d is built with %2$s, so the native block tools do not apply to it. ', 'saddle' ),
				$post->ID,
				$builder
			) . ( $divi5_tools
				? __( 'Use the saddle/divi-* tools for this page instead.', 'saddle' )
				: __( 'Its layout is edited with the builder\'s own editor — tell the site owner instead of forcing the edit.', 'saddle' ) ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Shared guard for the write abilities: a real post/page the user may
	 * edit, and content that is native (not builder-owned).
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function edit_guard( array $input ) {
		$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You cannot edit this post.', 'saddle' ), array( 'status' => 403 ) );
		}

		$guarded = self::guard_native( $post );
		if ( is_wp_error( $guarded ) ) {
			return $guarded;
		}

		return $post;
	}

	/**
	 * Resolve an append/insert position against a parent's child count.
	 *
	 * @param array[] $tree     Block tree.
	 * @param string  $parent_address Parent address ('' = top level).
	 * @param int     $position Requested index (-1 = append).
	 * @return int|WP_Error
	 */
	private static function resolve_position( array $tree, $parent_address, $position ) {
		if ( '' === $parent_address ) {
			$sibling_count = count( $tree );
		} else {
			$parent_node = Saddle_Blocks_Tree::get( $tree, $parent_address );
			if ( ! $parent_node ) {
				return new WP_Error( 'saddle_bad_address', sprintf( 'No block at address %s.', $parent_address ) );
			}
			$sibling_count = count( $parent_node['innerBlocks'] );
		}
		return ( $position < 0 ) ? $sibling_count : min( (int) $position, $sibling_count );
	}

	/**
	 * Adjust an address for the index shift caused by removing a node.
	 *
	 * @param string $removed Address that was removed.
	 * @param string $target  Address captured from the same snapshot.
	 * @return string Adjusted target.
	 */
	private static function adjust_after_removal( $removed, $target ) {
		if ( '' === $target ) {
			return $target;
		}
		$r     = explode( '.', $removed );
		$t     = explode( '.', $target );
		$level = count( $r ) - 1;

		if ( count( $t ) > $level
			&& array_slice( $t, 0, $level ) === array_slice( $r, 0, $level )
			&& (int) $t[ $level ] > (int) $r[ $level ] ) {
			$t[ $level ] = (string) ( (int) $t[ $level ] - 1 );
		}
		return implode( '.', $t );
	}

	/**
	 * Validate a tree and persist it — the single save path every write uses.
	 * An invalid tree never reaches the database.
	 *
	 * @param WP_Post $post Target post.
	 * @param array   $tree Block tree.
	 * @return int|WP_Error Total block count on success.
	 */
	private static function persist_tree( WP_Post $post, array $tree ) {
		$valid = Saddle_Blocks_Tree::validate( $tree );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				// wp_update_post unslashes; serialized block JSON contains
				// backslash escapes that must survive the round trip.
				'post_content' => wp_slash( Saddle_Blocks_Tree::serialize( $tree ) ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return count( Saddle_Blocks_Tree::flatten( $tree ) );
	}
}
