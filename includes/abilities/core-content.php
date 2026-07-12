<?php
/**
 * Post, page, and media abilities (Saddle v0.1 content scope).
 *
 * Twenty-three abilities, all namespaced `saddle/` with DASH-separated ids (e.g.
 * `saddle/list-posts`) — core rejects underscores in ability names. The MCP
 * Adapter further exposes them to agents with the slash sanitized to a dash
 * (tool name `saddle-list-posts`). Each ability declares an explicit access
 * tier (via its permission_callback) and an explicit destructive flag (via
 * meta.annotations + the approval gate). Do not infer either from the callback
 * body — see CLAUDE.md.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Saddle ability category. Hooked to
 * `wp_abilities_api_categories_init` (must run before abilities register).
 */
function saddle_register_ability_category() {
	wp_register_ability_category(
		'saddle',
		array(
			'label'       => __( 'Saddle', 'saddle' ),
			'description' => __( 'Approval-gated content operations exposed to AI agents by Saddle.', 'saddle' ),
		)
	);
}

/**
 * Register all content abilities. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_abilities() {
	/*
	 * ---------------------------------------------------------------------
	 * General (read)
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/get-site-info',
		array(
			'label'               => __( 'Get site info', 'saddle' ),
			'description'         => __( 'Returns orientation data about this WordPress site: name, tagline, URL, WordPress version, language, content counts (posts, pages, media), the current Saddle access tier, and the connected user. Read-only. Call this first to understand what the site contains before other operations.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Abilities', 'get_site_info' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-site-info' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-instructions',
		array(
			'label'               => __( 'Get instructions', 'saddle' ),
			'description'         => __( 'Returns important context about this site (its setup and active plugins) and the site owner\'s instructions for AI agents. Call this first, before other actions, and follow the guidance it returns. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Abilities', 'get_instructions' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-instructions' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/search-content',
		array(
			'label'               => __( 'Search content', 'saddle' ),
			'description'         => __( 'Full-text search across posts and pages by keyword. Returns matching items as summaries (id, type, title, status, link, excerpt). Read-only. Use post_type to restrict to "post", "page", or "any".', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'required'   => array( 'query' ),
				'properties' => array(
					'query'     => array(
						'type'        => 'string',
						'description' => __( 'Search keywords.', 'saddle' ),
					),
					'post_type' => array(
						'type'        => 'string',
						'enum'        => array( 'post', 'page', 'any' ),
						'default'     => 'any',
						'description' => __( 'Restrict results to this type.', 'saddle' ),
					),
					'per_page'  => saddle_per_page_schema(),
					'page'      => saddle_page_schema(),
				),
			),
			'execute_callback'    => array( 'Saddle_Abilities', 'search_content' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'search-content' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Posts
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/list-posts',
		array(
			'label'               => __( 'List posts', 'saddle' ),
			'description'         => __( 'Lists posts as summaries (id, title, status, author, date, link, excerpt). Read-only. Supports filtering by status, author, category, and search term, plus pagination via per_page/page.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'status'      => saddle_status_schema(),
					'search'      => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword filter.', 'saddle' ),
					),
					'author'      => array(
						'type'        => 'integer',
						'description' => __( 'Filter by author user ID.', 'saddle' ),
					),
					'category_id' => array(
						'type'        => 'integer',
						'description' => __( 'Filter by category term ID.', 'saddle' ),
					),
					'orderby'     => array(
						'type'    => 'string',
						'enum'    => array( 'date', 'modified', 'title', 'ID' ),
						'default' => 'date',
					),
					'order'       => array(
						'type'    => 'string',
						'enum'    => array( 'ASC', 'DESC' ),
						'default' => 'DESC',
					),
					'per_page'    => saddle_per_page_schema(),
					'page'        => saddle_page_schema(),
				),
			),
			'execute_callback'    => array( 'Saddle_Abilities', 'list_posts' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-posts' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-post',
		array(
			'label'               => __( 'Get post', 'saddle' ),
			'description'         => __( 'Returns a single post in full: title, content, excerpt, status, slug, author, dates, categories, tags, featured image, discussion settings, and custom fields (meta). Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_id_schema( __( 'The post ID.', 'saddle' ) ),
			'execute_callback'    => array( 'Saddle_Abilities', 'get_post' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-post' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/create-post',
		array(
			'label'               => __( 'Create post', 'saddle' ),
			'description'         => __( 'Creates a new post and returns its id and summary. Additive (non-destructive). Accepts title, content, excerpt, status (draft/publish/pending/private), slug, author, date, category_ids, tags, featured_media, discussion settings, and custom fields via "meta". Defaults to draft status.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_writable_schema( 'post', false ),
			'execute_callback'    => array( 'Saddle_Abilities', 'create_post' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'create-post' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/update-post',
		array(
			'label'               => __( 'Update post', 'saddle' ),
			'description'         => __( 'Updates an existing post by id. Only the fields you pass are changed; omitted fields are left untouched. Accepts the same fields as create-post, including slug, author, and custom fields via "meta" (null deletes a key). WordPress stores a revision, so changes are recoverable (non-destructive). Returns the updated summary.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_writable_schema( 'post', true ),
			'execute_callback'    => array( 'Saddle_Abilities', 'update_post' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'update-post' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/delete-post',
		array(
			'label'               => __( 'Delete post', 'saddle' ),
			'description'         => __( 'Deletes a post. DESTRUCTIVE — runs through a two-step confirmation: the first call returns a preview and a confirm_token without changing anything; call again with confirm_token to execute. Without "force" the post is trashed (recoverable); with force=true it is permanently deleted (not recoverable).', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_delete_schema( __( 'The post ID to delete.', 'saddle' ), true ),
			'execute_callback'    => array( 'Saddle_Abilities', 'delete_post' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'delete_posts', 'delete-post' ),
			'meta'                => saddle_ability_meta( false, true, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/list-post-revisions',
		array(
			'label'               => __( 'List post revisions', 'saddle' ),
			'description'         => __( 'Lists the stored revisions of a post or page (id, author, date, title). Read-only. Restoring a revision is not available in this version.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_id_schema( __( 'The post or page ID.', 'saddle' ) ),
			'execute_callback'    => array( 'Saddle_Abilities', 'list_post_revisions' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-post-revisions' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Pages
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/list-pages',
		array(
			'label'               => __( 'List pages', 'saddle' ),
			'description'         => __( 'Lists pages as summaries (id, title, status, parent, menu order, link). Read-only. Supports status filtering and pagination.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'status'   => saddle_status_schema(),
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword filter.', 'saddle' ),
					),
					'parent'   => array(
						'type'        => 'integer',
						'description' => __( 'Filter by parent page ID.', 'saddle' ),
					),
					'per_page' => saddle_per_page_schema(),
					'page'     => saddle_page_schema(),
				),
			),
			'execute_callback'    => array( 'Saddle_Abilities', 'list_pages' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-pages' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-page',
		array(
			'label'               => __( 'Get page', 'saddle' ),
			'description'         => __( 'Returns a single page in full: title, content, excerpt, status, slug, author, dates, page template, parent, menu order, featured image, discussion settings, and custom fields (meta). Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_id_schema( __( 'The page ID.', 'saddle' ) ),
			'execute_callback'    => array( 'Saddle_Abilities', 'get_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-page' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/create-page',
		array(
			'label'               => __( 'Create page', 'saddle' ),
			'description'         => __( 'Creates a new page and returns its id and summary. Additive (non-destructive). Accepts title, content, excerpt, status, slug, author, date, page template, parent, menu_order, featured_media, discussion settings, and custom fields via "meta". Defaults to draft status.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_writable_schema( 'page', false ),
			'execute_callback'    => array( 'Saddle_Abilities', 'create_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_pages', 'create-page' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/update-page',
		array(
			'label'               => __( 'Update page', 'saddle' ),
			'description'         => __( 'Updates an existing page by id. Only the fields you pass are changed. Accepts the same fields as create-page, including slug, author, and custom fields via "meta" (null deletes a key). WordPress stores a revision, so changes are recoverable (non-destructive). Returns the updated summary.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_writable_schema( 'page', true ),
			'execute_callback'    => array( 'Saddle_Abilities', 'update_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_pages', 'update-page' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/delete-page',
		array(
			'label'               => __( 'Delete page', 'saddle' ),
			'description'         => __( 'Deletes a page. DESTRUCTIVE — two-step confirmation required (preview + confirm_token). Without "force" the page is trashed (recoverable); with force=true it is permanently deleted.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_delete_schema( __( 'The page ID to delete.', 'saddle' ), true ),
			'execute_callback'    => array( 'Saddle_Abilities', 'delete_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'delete_pages', 'delete-page' ),
			'meta'                => saddle_ability_meta( false, true, false, 'write' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Media
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/list-media',
		array(
			'label'               => __( 'List media', 'saddle' ),
			'description'         => __( 'Lists media library items as summaries (id, title, mime type, URL, date, attached post). Read-only. Supports filtering by mime type and parent, plus pagination.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'search'    => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword filter.', 'saddle' ),
					),
					'mime_type' => array(
						'type'        => 'string',
						'description' => __( 'Filter by MIME type or prefix, e.g. "image" or "image/png".', 'saddle' ),
					),
					'parent'    => array(
						'type'        => 'integer',
						'description' => __( 'Filter by the post the media is attached to.', 'saddle' ),
					),
					'per_page'  => saddle_per_page_schema(),
					'page'      => saddle_page_schema(),
				),
			),
			'execute_callback'    => array( 'Saddle_Abilities', 'list_media' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-media' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-media',
		array(
			'label'               => __( 'Get media', 'saddle' ),
			'description'         => __( 'Returns a single media item in full: URL, MIME type, alt text, caption, description, available image sizes, and the post it is attached to. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_id_schema( __( 'The attachment ID.', 'saddle' ) ),
			'execute_callback'    => array( 'Saddle_Abilities', 'get_media' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-media' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/upload-media',
		array(
			'label'               => __( 'Upload media from URL', 'saddle' ),
			'description'         => __( 'Downloads a file from a source URL you provide and adds it to the media library, returning the new attachment id and URL. Additive (non-destructive). This is the only ability that fetches an external URL, and it fetches only the URL you pass. Optionally set title, alt text, caption, description, and the post to attach it to.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'source_url' ),
				'properties' => array(
					'source_url'  => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => __( 'Publicly reachable URL of the file to download.', 'saddle' ),
					),
					'filename'    => array(
						'type'        => 'string',
						'description' => __( 'Override the stored filename (with extension).', 'saddle' ),
					),
					'title'       => array( 'type' => 'string' ),
					'alt'         => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Attach the media to this post/page ID.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Abilities', 'upload_media' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'upload_files', 'upload-media' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/update-media',
		array(
			'label'               => __( 'Update media metadata', 'saddle' ),
			'description'         => __( 'Updates a media item\'s metadata (title, alt text, caption, description) by id. Does not replace the file. Non-destructive. Returns the updated summary.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The attachment ID.', 'saddle' ),
					),
					'title'       => array( 'type' => 'string' ),
					'alt'         => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'Saddle_Abilities', 'update_media' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'upload_files', 'update-media' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/delete-media',
		array(
			'label'               => __( 'Delete media', 'saddle' ),
			'description'         => __( 'Deletes a media item and its files. DESTRUCTIVE and NOT RECOVERABLE — attachments have no trash state in WordPress, so deletion is permanent. Two-step confirmation required (preview + confirm_token).', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_delete_schema( __( 'The attachment ID to delete.', 'saddle' ), false ),
			'execute_callback'    => array( 'Saddle_Abilities', 'delete_media' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'delete_posts', 'delete-media' ),
			'meta'                => saddle_ability_meta( false, true, false, 'write' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Taxonomies (categories & tags)
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/list-categories',
		array(
			'label'               => __( 'List categories', 'saddle' ),
			'description'         => __( 'Lists post categories as summaries (id, name, slug, parent, post count, description). Read-only. Supports search and pagination.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_list_terms_schema(),
			'execute_callback'    => array( 'Saddle_Abilities', 'list_categories' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-categories' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/create-category',
		array(
			'label'               => __( 'Create category', 'saddle' ),
			'description'         => __( 'Creates a post category and returns it. Additive (non-destructive). Accepts name (required), slug, parent category id, and description.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_create_term_schema( true ),
			'execute_callback'    => array( 'Saddle_Abilities', 'create_category' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'manage_categories', 'create-category' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/list-tags',
		array(
			'label'               => __( 'List tags', 'saddle' ),
			'description'         => __( 'Lists post tags as summaries (id, name, slug, post count, description). Read-only. Supports search and pagination.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_list_terms_schema(),
			'execute_callback'    => array( 'Saddle_Abilities', 'list_tags' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-tags' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/create-tag',
		array(
			'label'               => __( 'Create tag', 'saddle' ),
			'description'         => __( 'Creates a post tag and returns it. Additive (non-destructive). Accepts name (required), slug, and description.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_create_term_schema( false ),
			'execute_callback'    => array( 'Saddle_Abilities', 'create_tag' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'manage_categories', 'create-tag' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);
}

/*
=========================================================================
 * Schema helpers
 * =========================================================================
 */

/**
 * Build the `meta` array for an ability with explicit annotations.
 *
 * The tier is stored explicitly under meta.saddle.tier so the capability
 * catalog (and the admin UI's Capability Map) can introspect it without parsing
 * the permission_callback closure. Per CLAUDE.md, tier is declared, not inferred.
 *
 * @param bool   $is_readonly Whether the ability only reads.
 * @param bool   $destructive Whether the ability may destroy data.
 * @param bool   $idempotent  Whether repeated identical calls are no-ops.
 * @param string $tier        Minimum access tier ('read'|'write'|'admin').
 * @return array
 */
function saddle_ability_meta( $is_readonly, $destructive, $idempotent, $tier = 'read' ) {
	return array(
		'show_in_rest' => true,
		'mcp'          => array( 'public' => true ),
		'saddle'       => array( 'tier' => $tier ),
		'annotations'  => array(
			'readonly'    => (bool) $is_readonly,
			'destructive' => (bool) $destructive,
			'idempotent'  => (bool) $idempotent,
		),
	);
}

/**
 * Schema for a term-listing ability (categories/tags).
 *
 * @return array
 */
function saddle_list_terms_schema() {
	return array(
		'type'       => 'object',
		'default'    => (object) array(),
		'properties' => array(
			'search'     => array(
				'type'        => 'string',
				'description' => __( 'Optional keyword filter.', 'saddle' ),
			),
			'hide_empty' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Exclude terms that have no posts.', 'saddle' ),
			),
			'per_page'   => saddle_per_page_schema(),
			'page'       => saddle_page_schema(),
		),
	);
}

/**
 * Schema for a term-creation ability.
 *
 * @param bool $with_parent Whether a parent term id applies (categories).
 * @return array
 */
function saddle_create_term_schema( $with_parent ) {
	$props = array(
		'name'        => array(
			'type'        => 'string',
			'description' => __( 'The term name (required).', 'saddle' ),
		),
		'slug'        => array( 'type' => 'string' ),
		'description' => array( 'type' => 'string' ),
	);

	if ( $with_parent ) {
		$props['parent'] = array(
			'type'        => 'integer',
			'description' => __( 'Parent category term ID.', 'saddle' ),
		);
	}

	return array(
		'type'       => 'object',
		'required'   => array( 'name' ),
		'properties' => $props,
	);
}

/**
 * Reusable per_page schema fragment.
 *
 * @return array
 */
function saddle_per_page_schema() {
	return array(
		'type'        => 'integer',
		'minimum'     => 1,
		'maximum'     => 100,
		'default'     => 20,
		'description' => __( 'Results per page (1–100).', 'saddle' ),
	);
}

/**
 * Reusable page schema fragment.
 *
 * @return array
 */
function saddle_page_schema() {
	return array(
		'type'        => 'integer',
		'minimum'     => 1,
		'default'     => 1,
		'description' => __( 'Page number of results.', 'saddle' ),
	);
}

/**
 * Reusable status-filter schema fragment.
 *
 * @return array
 */
function saddle_status_schema() {
	return array(
		'type'        => 'string',
		'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' ),
		'default'     => 'any',
		'description' => __( 'Filter by post status.', 'saddle' ),
	);
}

/**
 * Schema for an ability that needs only a required integer `id`.
 *
 * @param string $description Description for the id field.
 * @return array
 */
function saddle_id_schema( $description ) {
	return array(
		'type'       => 'object',
		'required'   => array( 'id' ),
		'properties' => array(
			'id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => $description,
			),
		),
	);
}

/**
 * Schema for a destructive delete ability (id + confirm_token, optional force).
 *
 * @param string $id_description Description for the id field.
 * @param bool   $supports_force Whether a `force` (permanent) flag applies.
 * @return array
 */
function saddle_delete_schema( $id_description, $supports_force ) {
	$props = array(
		'id'            => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => $id_description,
		),
		'confirm_token' => array(
			'type'        => 'string',
			'description' => __( 'The single-use token returned by the preview call. Omit on the first call to receive a preview.', 'saddle' ),
		),
	);

	if ( $supports_force ) {
		$props['force'] = array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => __( 'If true, permanently delete instead of moving to trash.', 'saddle' ),
		);
	}

	return array(
		'type'       => 'object',
		'required'   => array( 'id' ),
		'properties' => $props,
	);
}

/**
 * Schema for create/update abilities on posts or pages.
 *
 * @param string $type      'post' or 'page'.
 * @param bool   $is_update Whether this is an update (id required) vs create.
 * @return array
 */
function saddle_writable_schema( $type, $is_update ) {
	$props = array(
		'title'          => array(
			'type'        => 'string',
			'description' => __( 'The title.', 'saddle' ),
		),
		'content'        => array(
			'type'        => 'string',
			'description' => __( 'The body content (HTML or blocks).', 'saddle' ),
		),
		'excerpt'        => array( 'type' => 'string' ),
		'status'         => array(
			'type'        => 'string',
			'enum'        => array( 'draft', 'publish', 'pending', 'private', 'future' ),
			'description' => __( 'Publication status. Defaults to draft on create.', 'saddle' ),
		),
		'slug'           => array( 'type' => 'string' ),
		'author'         => array(
			'type'        => 'integer',
			'description' => __( 'Author user ID.', 'saddle' ),
		),
		'date'           => array(
			'type'        => 'string',
			'description' => __( 'Publish date in site time, "YYYY-MM-DD HH:MM:SS".', 'saddle' ),
		),
		'comment_status' => array(
			'type' => 'string',
			'enum' => array( 'open', 'closed' ),
		),
		'ping_status'    => array(
			'type' => 'string',
			'enum' => array( 'open', 'closed' ),
		),
		'featured_media' => array(
			'type'        => 'integer',
			'description' => __( 'Attachment ID to set as the featured image.', 'saddle' ),
		),
		'meta'           => array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'description'          => __( 'Custom fields (post meta) as key/value pairs. Values may be strings, numbers, booleans, or arrays; pass null as a value to delete that key. Keys starting with an underscore are protected — writable only when the owning plugin registered them as editable. Denied keys are skipped and reported back in "meta_denied"; the rest of the request still applies.', 'saddle' ),
		),
	);

	if ( 'post' === $type ) {
		$props['category_ids'] = array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
			'description' => __( 'Category term IDs to assign.', 'saddle' ),
		);
		$props['tags']         = array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => __( 'Tag names to assign (created if missing).', 'saddle' ),
		);
	} else {
		$props['template']   = array(
			'type'        => 'string',
			'description' => __( 'Page template filename, e.g. "template-full.php".', 'saddle' ),
		);
		$props['parent']     = array(
			'type'        => 'integer',
			'description' => __( 'Parent page ID.', 'saddle' ),
		);
		$props['menu_order'] = array(
			'type'        => 'integer',
			'description' => __( 'Ordering value among sibling pages.', 'saddle' ),
		);
	}

	$schema = array(
		'type'       => 'object',
		'properties' => $props,
	);

	if ( $is_update ) {
		$props['id']          = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'The ID to update.', 'saddle' ),
		);
		$schema['properties'] = $props;
		$schema['required']   = array( 'id' );
	}

	return $schema;
}

/*
=========================================================================
 * Execute callbacks
 *
 * Tier and capability enforcement happen in each ability's permission_callback
 * (see Saddle_Capabilities). Destructive callbacks route their mutation through
 * Saddle_Approval::gate(). These methods assume permission has already passed.
 * =========================================================================
 */

/**
 * Implementations for the content abilities.
 */
class Saddle_Abilities {

	/**
	 * Normalize ability input to an array.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	private static function args( $input ) {
		return is_array( $input ) ? $input : array();
	}

	/**
	 * Validate and extract a required positive integer ID.
	 *
	 * @param array  $input Input array.
	 * @param string $key   Key to read.
	 * @return int|WP_Error
	 */
	private static function require_id( array $input, $key = 'id' ) {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return new WP_Error(
				'saddle_missing_' . $key,
				sprintf(
					/* translators: %s: field name. */
					__( 'A numeric "%s" is required.', 'saddle' ),
					$key
				),
				array( 'status' => 400 )
			);
		}
		$id = (int) $input[ $key ];
		if ( $id < 1 ) {
			return new WP_Error(
				'saddle_invalid_' . $key,
				sprintf(
					/* translators: %s: field name. */
					__( 'The "%s" must be a positive integer.', 'saddle' ),
					$key
				),
				array( 'status' => 400 )
			);
		}
		return $id;
	}

	/**
	 * Standard 403 for per-object authorization failures.
	 *
	 * @param string|null $msg Optional message.
	 * @return WP_Error
	 */
	private static function forbidden( $msg = null ) {
		return new WP_Error(
			'saddle_forbidden',
			$msg ? $msg : __( 'You do not have permission to do that with this item.', 'saddle' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Enforce the meta capabilities that wp_insert_post()/wp_update_post() do
	 * not check themselves: editing a specific object, publishing, and assigning
	 * content to another author. The tier + generic-cap check in the
	 * permission_callback is necessary but not sufficient.
	 *
	 * @param string $type          'post'|'page'.
	 * @param array  $input         Writable input.
	 * @param int    $current_owner Author compared against (existing author on
	 *                              update; current user on create).
	 * @param int    $id            Post id being edited, or 0 on create.
	 * @return true|WP_Error
	 */
	private static function authorize_write( $type, array $input, $current_owner, $id ) {
		$is_page = ( 'page' === $type );

		if ( $id > 0 && ! current_user_can( 'edit_post', $id ) ) {
			return self::forbidden( __( 'You do not have permission to edit this item.', 'saddle' ) );
		}

		if ( isset( $input['status'] ) && 'publish' === sanitize_key( (string) $input['status'] ) ) {
			$publish_cap = $is_page ? 'publish_pages' : 'publish_posts';
			if ( ! current_user_can( $publish_cap ) ) {
				return self::forbidden( __( 'You do not have permission to publish content.', 'saddle' ) );
			}
		}

		if ( isset( $input['author'] ) && (int) $input['author'] !== (int) $current_owner ) {
			$others_cap = $is_page ? 'edit_others_pages' : 'edit_others_posts';
			if ( ! current_user_can( $others_cap ) ) {
				return self::forbidden( __( 'You do not have permission to assign content to another user.', 'saddle' ) );
			}
		}

		return true;
	}

	/**
	 * SSRF guard for upload_media: reject a source URL that resolves to a
	 * private or reserved IP range.
	 *
	 * wp_http_validate_url() blocks most private ranges but NOT link-local
	 * 169.254.0.0/16 — the cloud-metadata range (169.254.169.254). We resolve
	 * the host and reject any private or reserved address (NO_PRIV_RANGE covers
	 * RFC1918 + fc00::/7; NO_RES_RANGE covers link-local, loopback, 0.0.0.0/8,
	 * documentation ranges, etc.). A resolve failure is treated as unsafe.
	 *
	 * Note: this is a resolve-time check; a DNS-rebinding attacker could still
	 * change the record between validation and download_url()'s own fetch. That
	 * residual TOCTOU is acknowledged; blocking the common metadata SSRF is the
	 * priority here.
	 *
	 * @param string $url Candidate URL.
	 * @return bool True if safe to fetch.
	 */
	private static function source_url_is_safe( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$host = trim( $host, '[]' ); // Strip IPv6 brackets.

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			foreach ( array( DNS_A, DNS_AAAA ) as $dns_type ) {
				// Silenced by design: this is an SSRF pre-flight resolving a
				// user-supplied host to reject internal targets. A lookup
				// failure just means "no records" — we must not warn or throw
				// on an attacker-chosen name, only fall through to refusal.
				$records = @dns_get_record( $host, $dns_type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see comment above.
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) {
						if ( ! empty( $record['ip'] ) ) {
							$ips[] = $record['ip'];
						}
						if ( ! empty( $record['ipv6'] ) ) {
							$ips[] = $record['ipv6'];
						}
					}
				}
			}
			if ( empty( $ips ) ) {
				$resolved = gethostbyname( $host );
				if ( $resolved && $resolved !== $host ) {
					$ips[] = $resolved;
				}
			}
		}

		// Fail CLOSED when the host resolved to nothing. If PHP can't resolve the
		// host (some locked-down hosts disable dns_get_record / gethostbyname even
		// where HTTP fetches still work) we can't prove the target is external, so
		// we refuse rather than let an unverifiable name through — an internal
		// name that only resolves at fetch time would otherwise slip past this
		// pre-flight. A trusted/NAT'd environment that legitimately can't resolve
		// here can allow the source via the `saddle_source_url_is_safe` filter
		// below (the WP_Error returned to the agent names that escape hatch). The
		// residual DNS-rebinding TOCTOU on names that DO resolve is acknowledged.
		$safe = ! empty( $ips );
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$safe = false;
				break;
			}
		}

		/**
		 * Filter whether a media source URL is safe to fetch.
		 *
		 * Lets a trusted environment override the private/reserved-IP block —
		 * e.g. a NAT'd or proxied host where legitimate external names resolve to
		 * private ranges. Only override if you understand the SSRF implications.
		 *
		 * @param bool     $safe Whether the URL passed the internal-address check.
		 * @param string   $url  The candidate URL.
		 * @param string[] $ips  The resolved IPs that were checked.
		 */
		return (bool) apply_filters( 'saddle_source_url_is_safe', $safe, $url, $ips );
	}

	/*
	---------------------------------------------------------------------
	*/

	/*
	General
	*/
	/* --------------------------------------------------------------------- */

	/**
	 * Return basic site identity, content counts, access tier, and the connected user.
	 *
	 * @return array Site orientation data.
	 */
	public static function get_site_info() {
		$user = wp_get_current_user();
		return array(
			'name'         => get_bloginfo( 'name' ),
			'description'  => get_bloginfo( 'description' ),
			'url'          => home_url(),
			'wp_version'   => get_bloginfo( 'version' ),
			'language'     => get_bloginfo( 'language' ),
			'timezone'     => wp_timezone_string(),
			'counts'       => array(
				'posts' => (int) wp_count_posts( 'post' )->publish,
				'pages' => (int) wp_count_posts( 'page' )->publish,
				'media' => array_sum( (array) wp_count_attachments() ),
			),
			'access_tier'  => Saddle_Capabilities::get_tier(),
			'connected_as' => array(
				'id'    => $user->ID,
				'login' => $user->user_login,
				'name'  => $user->display_name,
			),
		);
	}

	/**
	 * Return the auto-generated site context and the owner's instructions for agents.
	 *
	 * @return array Site context and the owner's instructions for agents.
	 */
	public static function get_instructions() {
		return array(
			'site_context'       => Saddle_Context::system_context(),
			'owner_instructions' => Saddle_Context::user(),
		);
	}

	/**
	 * Search posts and pages by keyword.
	 *
	 * @param mixed $input { query, post_type, per_page, page }.
	 * @return array|WP_Error
	 */
	public static function search_content( $input = null ) {
		$input = self::args( $input );

		if ( ! isset( $input['query'] ) || ! is_string( $input['query'] ) || '' === trim( $input['query'] ) ) {
			return new WP_Error( 'saddle_missing_query', __( 'A non-empty "query" is required.', 'saddle' ), array( 'status' => 400 ) );
		}

		$post_type = isset( $input['post_type'] ) && in_array( $input['post_type'], array( 'post', 'page' ), true )
			? $input['post_type']
			: array( 'post', 'page' );

		$query = new WP_Query(
			array(
				's'              => $input['query'],
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => self::per_page( $input ),
				'paged'          => self::page( $input ),
				'no_found_rows'  => false,
			)
		);

		return self::collection( $query, array( __CLASS__, 'post_summary' ) );
	}

	/*
	---------------------------------------------------------------------
	*/

	/*
	Posts
	*/
	/* --------------------------------------------------------------------- */

	/**
	 * List posts.
	 *
	 * @param mixed $input List filters.
	 * @return array
	 */
	public static function list_posts( $input = null ) {
		return self::list_of_type( 'post', self::args( $input ) );
	}

	/**
	 * Fetch a single post by ID.
	 *
	 * @param mixed $input { id }.
	 * @return array|WP_Error
	 */
	public static function get_post( $input = null ) {
		return self::get_single( 'post', self::args( $input ) );
	}

	/**
	 * Create a post.
	 *
	 * @param mixed $input Writable fields.
	 * @return array|WP_Error
	 */
	public static function create_post( $input = null ) {
		return self::create_of_type( 'post', self::args( $input ) );
	}

	/**
	 * Update an existing post.
	 *
	 * @param mixed $input Writable fields incl. id.
	 * @return array|WP_Error
	 */
	public static function update_post( $input = null ) {
		return self::update_of_type( 'post', self::args( $input ) );
	}

	/**
	 * Delete a post through the approval gate.
	 *
	 * @param mixed $input { id, force, confirm_token }.
	 * @return array|WP_Error
	 */
	public static function delete_post( $input = null ) {
		return self::delete_of_type( 'post', 'delete_post', self::args( $input ) );
	}

	/**
	 * List revisions for a post or page.
	 *
	 * @param mixed $input { id }.
	 * @return array|WP_Error
	 */
	public static function list_post_revisions( $input = null ) {
		$input = self::args( $input );
		$id    = self::require_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}

		$revisions = wp_get_post_revisions( $id, array( 'posts_per_page' => 50 ) );
		$out       = array();
		foreach ( $revisions as $rev ) {
			$out[] = array(
				'id'       => $rev->ID,
				'author'   => (int) $rev->post_author,
				'date_gmt' => $rev->post_date_gmt,
				'title'    => $rev->post_title,
			);
		}

		return array(
			'post_id'   => $id,
			'revisions' => $out,
			'total'     => count( $out ),
		);
	}

	/*
	---------------------------------------------------------------------
	*/

	/*
	Pages
	*/
	/* --------------------------------------------------------------------- */

	/**
	 * List pages.
	 *
	 * @param mixed $input List filters.
	 * @return array
	 */
	public static function list_pages( $input = null ) {
		return self::list_of_type( 'page', self::args( $input ) );
	}

	/**
	 * Fetch a single page by ID.
	 *
	 * @param mixed $input { id }.
	 * @return array|WP_Error
	 */
	public static function get_page( $input = null ) {
		return self::get_single( 'page', self::args( $input ) );
	}

	/**
	 * Create a page.
	 *
	 * @param mixed $input Writable fields.
	 * @return array|WP_Error
	 */
	public static function create_page( $input = null ) {
		return self::create_of_type( 'page', self::args( $input ) );
	}

	/**
	 * Update an existing page.
	 *
	 * @param mixed $input Writable fields incl. id.
	 * @return array|WP_Error
	 */
	public static function update_page( $input = null ) {
		return self::update_of_type( 'page', self::args( $input ) );
	}

	/**
	 * Delete a page through the approval gate.
	 *
	 * @param mixed $input { id, force, confirm_token }.
	 * @return array|WP_Error
	 */
	public static function delete_page( $input = null ) {
		return self::delete_of_type( 'page', 'delete_page', self::args( $input ) );
	}

	/*
	---------------------------------------------------------------------
	*/

	/*
	Media
	*/
	/* --------------------------------------------------------------------- */

	/**
	 * List media library attachments.
	 *
	 * @param mixed $input List filters.
	 * @return array
	 */
	public static function list_media( $input = null ) {
		$input = self::args( $input );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => self::per_page( $input ),
			'paged'          => self::page( $input ),
		);

		if ( ! empty( $input['search'] ) && is_string( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}
		if ( ! empty( $input['mime_type'] ) && is_string( $input['mime_type'] ) ) {
			$args['post_mime_type'] = $input['mime_type'];
		}
		if ( ! empty( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		$query = new WP_Query( $args );
		return self::collection( $query, array( __CLASS__, 'media_summary' ) );
	}

	/**
	 * Fetch a single media item by ID.
	 *
	 * @param mixed $input { id }.
	 * @return array|WP_Error
	 */
	public static function get_media( $input = null ) {
		$input = self::args( $input );
		$id    = self::require_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'saddle_not_found', __( 'No media item with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}

		$meta = wp_get_attachment_metadata( $id );
		return array(
			'id'          => $id,
			'title'       => $post->post_title,
			'mime_type'   => $post->post_mime_type,
			'url'         => wp_get_attachment_url( $id ),
			'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'attached_to' => (int) $post->post_parent,
			'sizes'       => isset( $meta['sizes'] ) ? array_keys( (array) $meta['sizes'] ) : array(),
			'date_gmt'    => $post->post_date_gmt,
		);
	}

	/**
	 * Download a URL into the media library. The only outbound fetch in Saddle.
	 *
	 * @param mixed $input Upload fields.
	 * @return array|WP_Error
	 */
	public static function upload_media( $input = null ) {
		$input = self::args( $input );

		if ( ! isset( $input['source_url'] ) || ! is_string( $input['source_url'] ) || '' === trim( $input['source_url'] ) ) {
			return new WP_Error( 'saddle_missing_source_url', __( 'A "source_url" is required.', 'saddle' ), array( 'status' => 400 ) );
		}

		$url = esc_url_raw( trim( $input['source_url'] ) );
		if ( ! wp_http_validate_url( $url ) || ! self::source_url_is_safe( $url ) ) {
			return new WP_Error( 'saddle_invalid_source_url', __( 'The "source_url" is not a valid, fetchable URL, or it resolves to a disallowed internal address. If the host is external but cannot be resolved on this server, the site owner can allow it via the "saddle_source_url_is_safe" filter.', 'saddle' ), array( 'status' => 400 ) );
		}

		// If attaching to a post, require permission to edit that post.
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return self::forbidden( __( 'You do not have permission to attach media to that item.', 'saddle' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'saddle_download_failed', $tmp->get_error_message(), array( 'status' => 502 ) );
		}

		// Cap the download size — defends against an agent pointing this at a
		// huge file to exhaust memory/disk. Filterable.
		$max_bytes = (int) apply_filters( 'saddle_max_upload_bytes', 64 * MB_IN_BYTES );
		if ( $max_bytes > 0 && file_exists( $tmp ) && filesize( $tmp ) > $max_bytes ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'saddle_file_too_large',
				sprintf(
					/* translators: %s: maximum size, e.g. "64 MB". */
					__( 'The file exceeds the maximum allowed size (%s).', 'saddle' ),
					size_format( $max_bytes )
				),
				array( 'status' => 413 )
			);
		}

		$filename = '';
		if ( ! empty( $input['filename'] ) && is_string( $input['filename'] ) ) {
			$filename = sanitize_file_name( $input['filename'] );
		}
		if ( '' === $filename ) {
			$path     = wp_parse_url( $url, PHP_URL_PATH );
			$filename = $path ? sanitize_file_name( basename( $path ) ) : 'saddle-upload';
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return new WP_Error( 'saddle_sideload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}

		// Apply optional metadata.
		$update = array( 'ID' => $attachment_id );
		if ( isset( $input['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$update['post_excerpt'] = wp_kses_post( $input['caption'] );
		}
		if ( isset( $input['description'] ) ) {
			$update['post_content'] = wp_kses_post( $input['description'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		if ( isset( $input['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		}

		Saddle_Log::record_action(
			'upload-media',
			$attachment_id,
			sprintf(
				/* translators: %d: attachment id. */
				__( 'Uploaded media #%d', 'saddle' ),
				$attachment_id
			)
		);

		return self::get_media( array( 'id' => $attachment_id ) );
	}

	/**
	 * Update a media item's title, alt text, caption, or description.
	 *
	 * @param mixed $input { id, title, alt, caption, description }.
	 * @return array|WP_Error
	 */
	public static function update_media( $input = null ) {
		$input = self::args( $input );
		$id    = self::require_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'saddle_not_found', __( 'No media item with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return self::forbidden( __( 'You do not have permission to edit this media item.', 'saddle' ) );
		}

		$update = array( 'ID' => $id );
		if ( isset( $input['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$update['post_excerpt'] = wp_kses_post( $input['caption'] );
		}
		if ( isset( $input['description'] ) ) {
			$update['post_content'] = wp_kses_post( $input['description'] );
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( isset( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		}

		Saddle_Log::record_action(
			'update-media',
			$id,
			sprintf(
				/* translators: %d: attachment id. */
				__( 'Updated media #%d', 'saddle' ),
				$id
			)
		);

		return self::get_media( array( 'id' => $id ) );
	}

	/**
	 * Permanently delete a media item through the approval gate.
	 *
	 * @param mixed $input { id, confirm_token }.
	 * @return array|WP_Error
	 */
	public static function delete_media( $input = null ) {
		$input = self::args( $input );
		$id    = self::require_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'saddle_not_found', __( 'No media item with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return self::forbidden( __( 'You do not have permission to delete this media item.', 'saddle' ) );
		}

		return Saddle_Approval::gate(
			array(
				'action'  => 'delete_media',
				'target'  => (string) $id,
				'summary' => sprintf(
					/* translators: 1: attachment id, 2: title. */
					__( 'Permanently delete media #%1$d "%2$s" and its files. Media has no trash — this cannot be undone.', 'saddle' ),
					$id,
					$post->post_title
				),
				'preview' => array(
					'id'                      => $id,
					'title'                   => $post->post_title,
					'mime_type'               => $post->post_mime_type,
					'url'                     => wp_get_attachment_url( $id ),
					'recoverable'             => false,
					'will_delete_permanently' => true,
				),
				'input'   => $input,
				'execute' => function () use ( $id ) {
					$result = wp_delete_attachment( $id, true );
					if ( ! $result ) {
						return new WP_Error( 'saddle_delete_failed', __( 'WordPress could not delete the media item.', 'saddle' ), array( 'status' => 500 ) );
					}
					return array(
						'deleted'   => true,
						'id'        => $id,
						'permanent' => true,
					);
				},
			)
		);
	}

	/*
	---------------------------------------------------------------------
	*/

	/*
	Taxonomies
	*/
	/* --------------------------------------------------------------------- */

	/**
	 * List categories.
	 *
	 * @param mixed $input List filters.
	 * @return array|WP_Error
	 */
	public static function list_categories( $input = null ) {
		return self::list_terms( 'category', self::args( $input ) );
	}

	/**
	 * List tags.
	 *
	 * @param mixed $input List filters.
	 * @return array|WP_Error
	 */
	public static function list_tags( $input = null ) {
		return self::list_terms( 'post_tag', self::args( $input ) );
	}

	/**
	 * Create a category.
	 *
	 * @param mixed $input { name, slug, parent, description }.
	 * @return array|WP_Error
	 */
	public static function create_category( $input = null ) {
		return self::create_term( 'category', self::args( $input ) );
	}

	/**
	 * Create a tag.
	 *
	 * @param mixed $input { name, slug, description }.
	 * @return array|WP_Error
	 */
	public static function create_tag( $input = null ) {
		return self::create_term( 'post_tag', self::args( $input ) );
	}

	/**
	 * List terms of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $input    Filters.
	 * @return array|WP_Error
	 */
	private static function list_terms( $taxonomy, array $input ) {
		$hide_empty = ! empty( $input['hide_empty'] );
		$per_page   = self::per_page( $input );

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => $hide_empty,
			'number'     => $per_page,
			'offset'     => ( self::page( $input ) - 1 ) * $per_page,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( ! empty( $input['search'] ) && is_string( $input['search'] ) ) {
			$args['search'] = $input['search'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = self::term_summary( $term );
		}

		return array(
			'items' => $items,
			'total' => (int) wp_count_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => $hide_empty,
				)
			),
		);
	}

	/**
	 * Create a term in a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $input    Term fields.
	 * @return array|WP_Error
	 */
	private static function create_term( $taxonomy, array $input ) {
		if ( ! isset( $input['name'] ) || ! is_string( $input['name'] ) || '' === trim( $input['name'] ) ) {
			return new WP_Error( 'saddle_missing_name', __( 'A "name" is required.', 'saddle' ), array( 'status' => 400 ) );
		}

		$args = array();
		if ( ! empty( $input['slug'] ) && is_string( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = wp_kses_post( $input['description'] );
		}
		if ( 'category' === $taxonomy && ! empty( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$result = wp_insert_term( sanitize_text_field( $input['name'] ), $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term  = get_term( $result['term_id'], $taxonomy );
		$label = ( 'category' === $taxonomy ) ? 'category' : 'tag';
		Saddle_Log::record_action(
			'create-' . $label,
			$result['term_id'],
			sprintf(
				/* translators: 1: term type, 2: name. */
				__( 'Created %1$s "%2$s"', 'saddle' ),
				$label,
				$term->name
			)
		);

		return self::term_summary( $term );
	}

	/**
	 * Compact summary of a term.
	 *
	 * @param WP_Term $term Term object.
	 * @return array
	 */
	private static function term_summary( $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'description' => $term->description,
		);
	}

	/*
	---------------------------------------------------------------------
	*/

	/*
	Shared post/page implementations
	*/
	/* --------------------------------------------------------------------- */

	/**
	 * List posts/pages of a type.
	 *
	 * @param string $type  'post'|'page'.
	 * @param array  $input Filters.
	 * @return array
	 */
	private static function list_of_type( $type, array $input ) {
		$args = array(
			'post_type'      => $type,
			'post_status'    => self::status_filter( $input ),
			'posts_per_page' => self::per_page( $input ),
			'paged'          => self::page( $input ),
			'orderby'        => self::orderby( $input ),
			'order'          => self::order( $input ),
		);

		if ( ! empty( $input['search'] ) && is_string( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}
		if ( ! empty( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}
		if ( 'post' === $type && ! empty( $input['category_id'] ) ) {
			$args['cat'] = (int) $input['category_id'];
		}
		if ( 'page' === $type && isset( $input['parent'] ) && '' !== $input['parent'] ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		$query = new WP_Query( $args );
		return self::collection( $query, array( __CLASS__, 'post_summary' ) );
	}

	/**
	 * Get a single post/page in full.
	 *
	 * @param string $type  'post'|'page'.
	 * @param array  $input { id }.
	 * @return array|WP_Error
	 */
	private static function get_single( $type, array $input ) {
		$id = self::require_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post || $type !== $post->post_type ) {
			return new WP_Error(
				'saddle_not_found',
				sprintf(
					/* translators: %s: post type. */
					__( 'No %s with that ID.', 'saddle' ),
					$type
				),
				array( 'status' => 404 )
			);
		}
		return self::post_detail( $post );
	}

	/**
	 * Create a post/page.
	 *
	 * @param string $type  'post'|'page'.
	 * @param array  $input Writable fields.
	 * @return array|WP_Error
	 */
	private static function create_of_type( $type, array $input ) {
		// Per-object authorization (publish / assign-to-other-author) that
		// wp_insert_post() does not enforce itself.
		$denied = self::authorize_write( $type, $input, get_current_user_id(), 0 );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$postarr              = self::build_postarr( $type, $input );
		$postarr['post_type'] = $type;
		if ( empty( $postarr['post_status'] ) ) {
			$postarr['post_status'] = 'draft';
		}

		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$meta_denied = self::apply_terms_and_meta( $type, $id, $input );

		$post = get_post( $id );
		Saddle_Log::record_action(
			'create-' . $type,
			$id,
			sprintf(
				/* translators: 1: post type, 2: id, 3: title. */
				__( 'Created %1$s #%2$d "%3$s"', 'saddle' ),
				$type,
				$id,
				$post->post_title
			)
		);

		$detail = self::post_detail( $post );
		if ( ! empty( $meta_denied ) ) {
			$detail['meta_denied'] = $meta_denied;
		}
		return $detail;
	}

	/**
	 * Which page builder (if any) owns a post's content.
	 *
	 * Content and meta signals for the builders whose layouts live in (or are
	 * keyed off) post_content — the ones a blind `content` write destroys.
	 *
	 * @param WP_Post $post Post to inspect.
	 * @return string|null Builder name, or null for ordinary content.
	 */
	public static function builder_signature( $post ) {
		$content = (string) $post->post_content;
		$builder = null;

		if ( false !== strpos( $content, '<!-- wp:divi/' ) ) {
			$builder = 'Divi 5';
		} elseif ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) || false !== strpos( $content, '[et_pb_section' ) ) {
			$builder = 'Divi (classic)';
		} elseif ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$builder = 'Elementor';
		} elseif ( get_post_meta( $post->ID, '_fl_builder_enabled', true ) ) {
			$builder = 'Beaver Builder';
		} elseif ( get_post_meta( $post->ID, '_bricks_page_content_2', true ) ) {
			$builder = 'Bricks';
		} elseif ( false !== strpos( $content, '[vc_row' ) ) {
			$builder = 'WPBakery';
		} elseif ( get_post_meta( $post->ID, 'ct_builder_shortcodes', true ) ) {
			$builder = 'Oxygen';
		} elseif ( get_post_meta( $post->ID, 'breakdance_data', true ) ) {
			$builder = 'Breakdance';
		}

		/**
		 * Filter the detected page builder for a post (null = plain content).
		 *
		 * @param string|null $builder Builder name.
		 * @param WP_Post     $post    The post.
		 */
		return apply_filters( 'saddle_builder_signature', $builder, $post );
	}

	/**
	 * Refuse a raw `content` overwrite on builder-built posts.
	 *
	 * Protecting the layout is safety and lives in free Saddle; EDITING the
	 * layout is builder tooling (Saddle Pro's divi-* abilities for Divi 5).
	 *
	 * @param WP_Post $post Target post.
	 * @return true|WP_Error
	 */
	private static function guard_builder_content( $post ) {
		/**
		 * Filter whether builder-built content is protected from raw
		 * `content` overwrites (default true).
		 *
		 * @param bool    $protect Default true.
		 * @param WP_Post $post    The post.
		 */
		if ( ! apply_filters( 'saddle_protect_builder_content', true, $post ) ) {
			return true;
		}

		$builder = self::builder_signature( $post );
		if ( null === $builder ) {
			return true;
		}

		$divi5_tools = 'Divi 5' === $builder
			&& function_exists( 'wp_get_abilities' )
			&& isset( wp_get_abilities()['saddle/divi-set-page'] );

		return new WP_Error(
			'saddle_builder_content',
			sprintf(
				/* translators: 1: post ID, 2: builder name. */
				__( 'Post #%1$d is built with %2$s — overwriting its content field would destroy the layout, so Saddle refuses. You can still edit its title, status, excerpt, taxonomy, and meta. ', 'saddle' ),
				$post->ID,
				$builder
			) . ( $divi5_tools
				? __( 'To change the layout itself, use the saddle/divi-* tools (divi-get-page, divi-edit-module, divi-set-page).', 'saddle' )
				: __( 'Layout changes need the builder’s own editor — tell the site owner instead of forcing the edit.', 'saddle' ) ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Update a post/page.
	 *
	 * @param string $type  'post'|'page'.
	 * @param array  $input Writable fields incl. id.
	 * @return array|WP_Error
	 */
	private static function update_of_type( $type, array $input ) {
		$id = self::require_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$existing = get_post( $id );
		if ( ! $existing || $type !== $existing->post_type ) {
			return new WP_Error(
				'saddle_not_found',
				sprintf(
					/* translators: %s: post type. */
					__( 'No %s with that ID.', 'saddle' ),
					$type
				),
				array( 'status' => 404 )
			);
		}

		// Per-object authorization. The tier + generic-cap check in the
		// permission_callback is not enough: wp_update_post() does not apply
		// map_meta_cap, so a lower-privilege account could otherwise edit content
		// it doesn't own. Enforce the real meta capabilities here.
		$denied = self::authorize_write( $type, $input, (int) $existing->post_author, $id );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		// Builder-content guard: overwriting a page-builder layout's `content`
		// destroys it, so refuse rather than let an agent do it blind. Other
		// fields (title, status, meta, terms) remain freely editable.
		if ( isset( $input['content'] ) ) {
			$guarded = self::guard_builder_content( $existing );
			if ( is_wp_error( $guarded ) ) {
				return $guarded;
			}
		}

		$postarr       = self::build_postarr( $type, $input );
		$postarr['ID'] = $id;

		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$meta_denied = self::apply_terms_and_meta( $type, $id, $input );

		$post = get_post( $id );
		Saddle_Log::record_action(
			'update-' . $type,
			$id,
			sprintf(
				/* translators: 1: post type, 2: id, 3: title. */
				__( 'Updated %1$s #%2$d "%3$s"', 'saddle' ),
				$type,
				$id,
				$post->post_title
			)
		);

		$detail = self::post_detail( $post );
		if ( ! empty( $meta_denied ) ) {
			$detail['meta_denied'] = $meta_denied;
		}
		return $detail;
	}

	/**
	 * Gate-protected delete for posts/pages.
	 *
	 * @param string $type   'post'|'page'.
	 * @param string $action Gate action key.
	 * @param array  $input  { id, force, confirm_token }.
	 * @return array|WP_Error
	 */
	private static function delete_of_type( $type, $action, array $input ) {
		$id = self::require_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post || $type !== $post->post_type ) {
			return new WP_Error(
				'saddle_not_found',
				sprintf(
					/* translators: %s: post type. */
					__( 'No %s with that ID.', 'saddle' ),
					$type
				),
				array( 'status' => 404 )
			);
		}

		// Per-object delete authorization (wp_delete_post() doesn't check).
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return self::forbidden();
		}

		$force = ! empty( $input['force'] );

		$summary = $force
			? sprintf(
				/* translators: 1: type, 2: id, 3: title. */
				__( 'Permanently delete %1$s #%2$d "%3$s". This cannot be undone.', 'saddle' ),
				$type,
				$id,
				$post->post_title
			)
			: sprintf(
				/* translators: 1: type, 2: id, 3: title. */
				__( 'Move %1$s #%2$d "%3$s" to the trash. It can be restored from Trash.', 'saddle' ),
				$type,
				$id,
				$post->post_title
			);

		return Saddle_Approval::gate(
			array(
				'action'  => $action,
				'target'  => (string) $id,
				// Bind permanence into the token: a "move to trash" preview must
				// not be confirmable into a permanent, unrecoverable delete.
				'bind'    => $force ? 'permanent' : 'trash',
				'summary' => $summary,
				'preview' => array(
					'id'                      => $id,
					'type'                    => $type,
					'title'                   => $post->post_title,
					'current_status'          => $post->post_status,
					'will_delete_permanently' => $force,
					'recoverable'             => ! $force,
				),
				'input'   => $input,
				'execute' => function () use ( $id, $force ) {
					$result = wp_delete_post( $id, $force );
					if ( ! $result ) {
						return new WP_Error( 'saddle_delete_failed', __( 'WordPress could not delete the item.', 'saddle' ), array( 'status' => 500 ) );
					}
					return array(
						'deleted'    => true,
						'id'         => $id,
						'permanent'  => $force,
						'new_status' => $force ? 'deleted' : 'trash',
					);
				},
			)
		);
	}

	/**
	 * Map create/update input onto a wp_insert_post array (core fields only).
	 *
	 * @param string $type  'post'|'page'.
	 * @param array  $input Input.
	 * @return array
	 */
	private static function build_postarr( $type, array $input ) {
		$postarr = array();

		if ( isset( $input['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$postarr['post_content'] = self::normalize_block_content( wp_kses_post( $input['content'] ) );
		}
		if ( isset( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = wp_kses_post( $input['excerpt'] );
		}
		if ( ! empty( $input['status'] ) ) {
			$postarr['post_status'] = sanitize_key( $input['status'] );
		}
		if ( ! empty( $input['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $input['slug'] );
		}
		if ( ! empty( $input['author'] ) ) {
			$postarr['post_author'] = (int) $input['author'];
		}
		if ( ! empty( $input['date'] ) ) {
			$postarr['post_date'] = sanitize_text_field( $input['date'] );
		}
		if ( ! empty( $input['comment_status'] ) ) {
			$postarr['comment_status'] = sanitize_key( $input['comment_status'] );
		}
		if ( ! empty( $input['ping_status'] ) ) {
			$postarr['ping_status'] = sanitize_key( $input['ping_status'] );
		}

		if ( 'page' === $type ) {
			if ( isset( $input['parent'] ) ) {
				$postarr['post_parent'] = (int) $input['parent'];
			}
			if ( isset( $input['menu_order'] ) ) {
				$postarr['menu_order'] = (int) $input['menu_order'];
			}
		}

		return $postarr;
	}

	/**
	 * If content looks block-based, round-trip it through core's own block
	 * parser/serializer so what's stored matches the canonical form the block
	 * editor itself would produce.
	 *
	 * WordPress's block grammar is self-healing, not strict — an unclosed or
	 * mismatched block comment never throws an error server-side, it's just
	 * silently auto-closed by parse_blocks(). But the block *editor's* stricter
	 * client-side parser can still flag content that round-trips differently as
	 * "invalid content" needing recovery. Normalizing here means an agent's
	 * slightly-off block markup is corrected at write time instead of surfacing
	 * as a broken-looking post the next time a human opens it.
	 *
	 * @param string $content Already-sanitized content.
	 * @return string
	 */
	private static function normalize_block_content( $content ) {
		if ( '' === $content || ! has_blocks( $content ) ) {
			return $content;
		}
		return serialize_blocks( parse_blocks( $content ) );
	}

	/**
	 * Apply taxonomy terms, featured image, template, and custom fields after
	 * insert/update.
	 *
	 * @param string $type  'post'|'page'.
	 * @param int    $id    Post ID.
	 * @param array  $input Input.
	 * @return string[] Meta keys that were denied by capability checks.
	 */
	private static function apply_terms_and_meta( $type, $id, array $input ) {
		if ( isset( $input['featured_media'] ) ) {
			$thumb = (int) $input['featured_media'];
			if ( $thumb > 0 ) {
				set_post_thumbnail( $id, $thumb );
			} else {
				delete_post_thumbnail( $id );
			}
		}

		if ( 'post' === $type ) {
			if ( isset( $input['category_ids'] ) && is_array( $input['category_ids'] ) ) {
				wp_set_post_categories( $id, array_map( 'intval', $input['category_ids'] ) );
			}
			if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
				wp_set_post_terms( $id, array_map( 'sanitize_text_field', $input['tags'] ), 'post_tag' );
			}
		}

		if ( 'page' === $type && ! empty( $input['template'] ) ) {
			update_post_meta( $id, '_wp_page_template', sanitize_text_field( $input['template'] ) );
		}

		return self::apply_custom_meta( $id, $input );
	}

	/**
	 * Write caller-supplied custom fields, enforcing WordPress's per-key meta
	 * capability (`edit_post_meta` → map_meta_cap). Unregistered protected keys
	 * (leading underscore) are denied even for admins — same rule core's REST
	 * API applies — so plugin internals can't be clobbered by an agent.
	 *
	 * @param int   $id    Post ID.
	 * @param array $input Input (reads the `meta` key).
	 * @return string[] Keys that were denied and not written.
	 */
	private static function apply_custom_meta( $id, array $input ) {
		if ( empty( $input['meta'] ) || ! is_array( $input['meta'] ) ) {
			return array();
		}

		$denied = array();
		foreach ( $input['meta'] as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post_meta', $id, $key ) ) {
				$denied[] = $key;
				continue;
			}
			if ( null === $value ) {
				delete_post_meta( $id, $key );
				continue;
			}
			if ( ! is_scalar( $value ) && ! is_array( $value ) ) {
				$denied[] = $key;
				continue;
			}
			// Strip script/unsafe markup from string values (nested included)
			// without stringifying numbers or booleans.
			$value = map_deep(
				$value,
				function ( $leaf ) {
					return is_string( $leaf ) ? wp_kses_post( $leaf ) : $leaf;
				}
			);
			update_post_meta( $id, $key, wp_slash( $value ) );
		}

		return $denied;
	}

	/*
	---------------------------------------------------------------------
	*/

	/*
	Formatting helpers
	*/
	/* --------------------------------------------------------------------- */

	/**
	 * Build a paginated collection response from a WP_Query.
	 *
	 * @param WP_Query $query     The query.
	 * @param callable $formatter Per-post formatter.
	 * @return array
	 */
	private static function collection( WP_Query $query, $formatter ) {
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = call_user_func( $formatter, $post );
		}
		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => max( 1, (int) $query->get( 'paged' ) ),
		);
	}

	/**
	 * Compact summary of a post/page.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function post_summary( $post ) {
		return array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'author'   => (int) $post->post_author,
			'date_gmt' => $post->post_date_gmt,
			'link'     => get_permalink( $post ),
			'excerpt'  => wp_strip_all_tags( get_the_excerpt( $post ) ),
		);
	}

	/**
	 * Compact summary of an attachment.
	 *
	 * @param WP_Post $post Attachment object.
	 * @return array
	 */
	public static function media_summary( $post ) {
		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'mime_type'   => $post->post_mime_type,
			'url'         => wp_get_attachment_url( $post->ID ),
			'date_gmt'    => $post->post_date_gmt,
			'attached_to' => (int) $post->post_parent,
		);
	}

	/**
	 * Full detail of a post/page.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function post_detail( $post ) {
		$detail = array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'slug'           => $post->post_name,
			'author'         => (int) $post->post_author,
			'date_gmt'       => $post->post_date_gmt,
			'modified_gmt'   => $post->post_modified_gmt,
			'link'           => get_permalink( $post ),
			'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
		);

		if ( 'post' === $post->post_type ) {
			$detail['category_ids'] = wp_get_post_categories( $post->ID );
			$detail['tags']         = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		} else {
			$detail['parent']     = (int) $post->post_parent;
			$detail['menu_order'] = (int) $post->menu_order;
			$detail['template']   = get_page_template_slug( $post->ID );
		}

		$detail['meta'] = self::readable_meta( $post->ID );

		return $detail;
	}

	/**
	 * Custom fields safe to show the agent: every non-protected key, plus
	 * protected keys the current user is explicitly authorized for (i.e. the
	 * owning plugin registered them with an auth_callback — matches the write
	 * rule in apply_custom_meta). Unregistered protected internals like
	 * _edit_lock stay hidden even from admins.
	 *
	 * @param int $id Post ID.
	 * @return array Key => value (or array of values for multi-value keys).
	 */
	private static function readable_meta( $id ) {
		$meta = array();
		foreach ( (array) get_post_meta( $id ) as $key => $values ) {
			if ( is_protected_meta( $key, 'post' ) && ! current_user_can( 'edit_post_meta', $id, $key ) ) {
				continue;
			}
			$values       = array_map( 'maybe_unserialize', (array) $values );
			$meta[ $key ] = ( 1 === count( $values ) ) ? $values[0] : $values;
		}
		return $meta;
	}

	/*
	---------------------------------------------------------------------
	*/

	/*
	Input normalization
	*/
	/* --------------------------------------------------------------------- */

	/**
	 * Clamp the per_page input to 1-100, defaulting to 20.
	 *
	 * @param array $input Input.
	 * @return int
	 */
	private static function per_page( array $input ) {
		$value = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		return max( 1, min( 100, $value ) );
	}

	/**
	 * Normalize the page input to a positive integer, defaulting to 1.
	 *
	 * @param array $input Input.
	 * @return int
	 */
	private static function page( array $input ) {
		$value = isset( $input['page'] ) ? (int) $input['page'] : 1;
		return max( 1, $value );
	}

	/**
	 * Validate the status filter, falling back to 'any'.
	 *
	 * @param array $input Input.
	 * @return string|string[]
	 */
	private static function status_filter( array $input ) {
		$status = isset( $input['status'] ) ? (string) $input['status'] : 'any';
		$valid  = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		if ( 'any' === $status || ! in_array( $status, $valid, true ) ) {
			return 'any';
		}
		return $status;
	}

	/**
	 * Validate the orderby input, falling back to 'date'.
	 *
	 * @param array $input Input.
	 * @return string
	 */
	private static function orderby( array $input ) {
		$valid = array( 'date', 'modified', 'title', 'ID' );
		return ( isset( $input['orderby'] ) && in_array( $input['orderby'], $valid, true ) ) ? $input['orderby'] : 'date';
	}

	/**
	 * Normalize the order input to ASC or DESC, defaulting to DESC.
	 *
	 * @param array $input Input.
	 * @return string
	 */
	private static function order( array $input ) {
		return ( isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ) ? 'ASC' : 'DESC';
	}
}
