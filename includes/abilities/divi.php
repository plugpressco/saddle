<?php
/**
 * Divi 5 page abilities.
 *
 * Registered into the same `saddle/` namespace as the free plugin's abilities,
 * so they surface automatically through Saddle's MCP server, its Permissions
 * UI, and its per-ability disable toggles — zero new transport, auth, or
 * policy surface. Every ability declares its tier and destructive flag
 * explicitly and runs behind Saddle_Capabilities, exactly like free abilities
 * (CLAUDE.md: the safety model is inherited unchanged, never reimplemented).
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Divi page abilities. Hooked to `wp_abilities_api_init` at
 * priority 30 through saddle_register_ability_once(), so a site still
 * running an older add-on that registers the same names keeps that copy.
 */
function saddle_register_divi_abilities() {

	saddle_register_ability_once(
		'saddle/divi-check-setup',
		array(
			'label'               => __( 'Check Divi setup', 'saddle' ),
			'description'         => __( 'Reports whether Divi 5 is active on this site (and its version), and — given a post_id — whether that post is built with Divi 5, legacy Divi 4, or something else, plus whether its module tree is structurally valid. Read-only. Call this before editing any page on a Divi site: Divi 4 pages and non-Divi pages must not be edited with divi-* tools.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Optional post or page ID to inspect.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'check_setup' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-check-setup' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-list-modules',
		array(
			'label'               => __( 'List Divi modules', 'saddle' ),
			'description'         => __( 'Returns the Divi 5 module types on this site: Divi\'s own and those of every active module pack. Each has a one-line purpose, a group (layout, text, media, forms, posts, navigation, shop, or "pack: <plugin>"), whether it is a child module, and, for a container, the child types it holds in "children" ("*" means any content module). Pass "search" (e.g. "pricing", "slider") to get only the matching modules instead of the whole list. Read-only. Then call divi-get-module-schema for one type. Use real modules; never approximate a layout by dumping HTML into a code module.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Optional words that must all appear in the module\'s type, title, purpose or group, e.g. "pricing" or "post grid".', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'list_modules' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-modules' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-get-page',
		array(
			'label'               => __( 'Get Divi page tree', 'saddle' ),
			'description'         => __( 'Returns a Divi 5 page\'s module tree as a flat, addressable node list: each node has an address (dot-separated child indexes like "0.1.0"), its module type, child count, and a text excerpt. The default "compact" mode omits attributes (a "styled" flag says whether a node has any) — pass an "address" to get one subtree WITH full attributes, or mode="full" for everything. The response carries a "version": pass it back on your next read and an unchanged page returns one line instead of the tree. Addresses are positional — re-read after any edit before addressing further nodes (writes also return their changed nodes, which usually makes that re-read unnecessary). Read-only. Fails with an explanation on Divi 4 or non-Divi posts.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page ID whose Divi tree to read.', 'saddle' ),
					),
					'mode'    => array(
						'type'        => 'string',
						'enum'        => array( 'compact', 'full' ),
						'default'     => 'compact',
						'description' => __( 'compact = skeleton without attrs (default); full = every node with attrs.', 'saddle' ),
					),
					'address' => array(
						'type'        => 'string',
						'description' => __( 'Focus one subtree — its nodes come back with full attrs regardless of mode.', 'saddle' ),
					),
					'depth'   => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Limit levels: 1 = roots only, 2 = roots + children. 0/omit = all.', 'saddle' ),
					),
					'version' => array(
						'type'        => 'string',
						'description' => __( 'The version from a previous read — an unchanged page short-circuits.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'get_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-get-page' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-set-page',
		array(
			'label'               => __( 'Build Divi page', 'saddle' ),
			'description'         => __( 'Replaces a page\'s entire Divi 5 layout with a new module tree — the bulk-build tool for "create this page". Structure: sections contain rows, rows contain columns, columns contain content modules. Each node is {"type":"divi/…", "fields":{…}, "attrs":{…}, "children":[…]}. Use "fields" for content — the value goes into that attribute\'s content slot: strings for text-like attributes (fields.title on divi/heading, fields.content on divi/text), objects for composite ones (fields.button {"text","linkUrl"} on divi/button; fields.image {"src","alt"} on divi/image). Use "attrs" only for advanced styling, with full canonical Divi attribute paths and {"desktop":{"value":…}} breakpoint envelopes; omit it to inherit the site\'s design. The tree is structurally validated and rejected with per-node errors if invalid — nothing partial is saved. If the response carries "warnings", those fields/style paths will silently not render (unknown attribute, content field, or decoration group) — fix and re-edit. The previous layout is kept as a WordPress revision and can be restored. Only works on Divi 5 pages or empty posts/pages; refuses Divi 4 and non-empty non-Divi content.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'nodes' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page whose layout to build.', 'saddle' ),
					),
					'nodes'   => array(
						'type'        => 'array',
						'description' => __( 'Top-level sections (each {"type","fields","attrs","children"}). SaddleWP wraps them in Divi\'s root container automatically.', 'saddle' ),
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'set_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-set-page' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-set-page-settings',
		array(
			'label'               => __( 'Set Divi page settings', 'saddle' ),
			'description'         => __( 'Sets a Divi page\'s own layout settings: full width vs sidebar, whether the post title shows, and whether the navigation is hidden. These are page settings, not modules — building a full-width landing page with no title needs this as well as divi-set-page, and without it a page you built module-by-module still renders with the theme\'s default title and sidebar. Returns the settings as saved, so read them back to confirm they stuck. Omit a field to leave it unchanged. Refuses Divi 4 and non-Divi posts.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The post or page to configure.', 'saddle' ),
					),
					'layout'     => array(
						'type'        => 'string',
						'enum'        => array( 'full_width', 'no_sidebar', 'right_sidebar', 'left_sidebar' ),
						'description' => __( 'Page width and sidebar. "full_width" is what a landing page needs; "no_sidebar" keeps the content column but drops the sidebar.', 'saddle' ),
					),
					'show_title' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether WordPress renders the post title above the layout. A landing page whose hero carries its own headline wants this false.', 'saddle' ),
					),
					'hide_nav'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to hide the theme navigation on this page. Rarely wanted; leave unset unless asked.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'set_page_settings' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-set-page-settings' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-get-module-schema',
		array(
			'label'               => __( 'Get Divi module schema', 'saddle' ),
			'description'         => __( 'Returns one Divi module type\'s schema, for Divi\'s own modules and every module pack on this site. "attributes": its content, with the exact "fields" syntax (a string, or an object with named fields). "settings": every other option (post count, which menu, autoplay, arrows, prices), each with its path, control (toggle, select, text, number, range, color, icon, upload, list, link, other), choices and default; toggles take the strings "on" and "off". "notes": known traps for this module. "children": what a container holds. Pass detail="full" for each setting\'s label, description and whether it can differ by screen size, on hover or when sticky. For styling paths use divi-get-style-schema.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'type' ),
				'properties' => array(
					'type'    => array(
						'type'        => 'string',
						'description' => __( 'The module type, e.g. "divi/button".', 'saddle' ),
					),
					'detail'  => array(
						'type'        => 'string',
						'enum'        => array( 'compact', 'full' ),
						'default'     => 'compact',
						'description' => __( 'compact (default): settings as path, control, short choices and default. full: also labels, descriptions, every choice with its label, and the screen-size, hover, sticky and dynamic-content flags.', 'saddle' ),
					),
					'verbose' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Include each element\'s decoration/advanced option groups.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'get_module_schema' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-get-module-schema' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-get-style-schema',
		array(
			'label'               => __( 'Get Divi style schema', 'saddle' ),
			'description'         => __( 'Returns a module\'s STYLING vocabulary: each decoration group it supports (background, spacing, border, sizing, fonts, filters, transform, position, animation, sticky, scroll, interactions, …) with the exact attribute path to set it; the advanced options that style it (HTML id and class, wrapper element, link, text colour, section dividers, row gutter); its custom CSS fields; the breakpoints switched on for this site; and how hover, sticky and screen-size values are written. Separate from divi-get-module-schema (content and settings); read it before styling a module. Call without "group" for the list, then with a "group" for that group\'s fields, sub-fields and one correct example. Style paths are per-module: never reuse one module\'s path on another.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'type' ),
				'properties' => array(
					'type'  => array(
						'type'        => 'string',
						'description' => __( 'The module type, e.g. "divi/text".', 'saddle' ),
					),
					'group' => array(
						'type'        => 'string',
						'description' => __( 'Optional decoration group to expand, e.g. "spacing", or "title.font" for the group on one element when several elements have it.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'get_style_schema' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-get-style-schema' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-add-module',
		array(
			'label'               => __( 'Add Divi module', 'saddle' ),
			'description'         => __( 'Inserts one module (with optional children) into a Divi 5 page at an addressed position. parent_address is the container node from divi-get-page ("" for the page root); position is the index among its children (omit to append). The node uses the same {"type","fields","attrs","children"} format as divi-set-page. Structure is validated — an insertion that would break section>row>column rules is rejected and nothing is saved. Re-read the page afterwards: addresses shift.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'node' ),
				'properties' => array(
					'post_id'        => array( 'type' => 'integer' ),
					'parent_address' => array(
						'type'        => 'string',
						'description' => __( 'Container address from divi-get-page; "" for the page root.', 'saddle' ),
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
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'add_module' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-add-module' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-edit-module',
		array(
			'label'               => __( 'Edit Divi module', 'saddle' ),
			'description'         => __( 'Patches one addressed module on a Divi 5 page. "fields" sets content values (same syntax as divi-set-page). "attrs" merges raw canonical attributes into the stored ones (styling), with dotted paths allowed as keys: an object merges key by key, a list (gradient stops, custom attributes, interactions, a font style list) REPLACES the stored list, and a null value is ignored. "unset" removes attributes by dotted path, for example ["module.decoration.background.desktop.value.gradient"]; a number in the path names one list item. Unset runs before the merge, so one call can remove a group and write a new one. To empty a list, unset it. Children are untouched. The result is validated before saving, and the previous state is kept as a revision. Response "warnings" flag fields or style paths this module will silently ignore; "removed" and "not_found" list what unset did.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'address' => array(
						'type'        => 'string',
						'description' => __( 'Node address from divi-get-page, e.g. "0.0.0.1".', 'saddle' ),
					),
					'fields'  => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'attrs'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'unset'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => 50,
						'description' => __( 'Dotted attribute paths to remove, e.g. "module.decoration.background.desktop.value.gradient". Parents left empty are removed too.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'edit_module' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-edit-module' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-move-module',
		array(
			'label'               => __( 'Move Divi module', 'saddle' ),
			'description'         => __( 'Moves an addressed module (with its subtree) to a new parent and position on the same Divi 5 page. Both addresses come from the same divi-get-page read. Moving a node into its own subtree is refused; the result is structurally validated before saving. Re-read the page afterwards: addresses shift.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'from_address' ),
				'properties' => array(
					'post_id'           => array( 'type' => 'integer' ),
					'from_address'      => array( 'type' => 'string' ),
					'to_parent_address' => array(
						'type'        => 'string',
						'description' => __( 'Destination container; "" for the page root.', 'saddle' ),
					),
					'position'          => array(
						'type'        => 'integer',
						'description' => __( 'Index among the destination\'s children; omit or -1 to append.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'move_module' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-move-module' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-remove-module',
		array(
			'label'               => __( 'Remove Divi module', 'saddle' ),
			'description'         => __( 'Removes an addressed module from a Divi 5 page. Removing a leaf module happens immediately (recoverable from revisions). Removing a container WITH children previews first: the call returns a summary and a confirm_token — repeat the call with that token to execute. Re-read the page afterwards: addresses shift.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => array(
					'post_id'       => array( 'type' => 'integer' ),
					'address'       => array( 'type' => 'string' ),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'Token from the preview step, required to remove a module that has children.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'remove_module' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-remove-module' ),
			'meta'                => saddle_ability_meta( false, true, false, 'write' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Loops — query-driven repeated content on a module
	 * ---------------------------------------------------------------------
	 */

	$loop_properties = array(
		'post_id'              => array(
			'type'        => 'integer',
			'description' => __( 'The Divi page.', 'saddle' ),
		),
		'address'              => array(
			'type'        => 'string',
			'description' => __( 'Address of the module that repeats, from divi-get-page. Usually the column or group that holds one card.', 'saddle' ),
		),
		'query_type'           => array(
			'type'        => 'string',
			'enum'        => array( 'post_types', 'post_taxonomies', 'user_roles', 'menus', 'current_page', 'acf_repeater', 'terms', 'users' ),
			'description' => __( 'What the loop repeats over: post_types (posts of the types in sub_types), post_taxonomies (terms of the taxonomies in sub_types), user_roles (users, of the roles in sub_types or all), menus (items of the menu in sub_types), current_page (the posts the page itself lists: blog, archive or search results), acf_repeater (the rows of the ACF repeater named in repeater_field). "terms" and "users" are older names for post_taxonomies and user_roles.', 'saddle' ),
		),
		'sub_types'            => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => __( 'Post type slugs ("post", "product"), taxonomy slugs ("category"), role slugs ("author"), or a menu id or slug. A post_types loop defaults to ["post"], a post_taxonomies loop to ["category"]. divi-list-loop-query-types lists this site\'s values.', 'saddle' ),
		),
		'repeater_field'       => array(
			'type'        => 'string',
			'description' => __( 'For query_type acf_repeater: the field name of the ACF repeater.', 'saddle' ),
		),
		'order_by'             => array(
			'type'        => 'string',
			'description' => __( 'Sort field. Posts: date, modified, title, name, ID, author, parent, type, menu_order, comment_count, rand, none (and price, popularity, rating for products); a current_page loop also takes relevance. Terms: name, slug, term_id, count, description, parent, term_order, none. Users: display_name, name, login, nicename, email, url, registered, post_count, ID. Menus: menu_order, title, id. Defaults to the first of each list.', 'saddle' ),
		),
		'order'                => array(
			'type'        => 'string',
			'enum'        => array( 'desc', 'asc', 'descending', 'ascending' ),
			'description' => __( '"desc" (newest first, Z to A; the default) or "asc". Stored as "descending" or "ascending", the values Divi reads.', 'saddle' ),
		),
		'per_page'             => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => 100,
			'description' => __( 'Items per page (default 10).', 'saddle' ),
		),
		'offset'               => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => __( 'Items to skip (default 0).', 'saddle' ),
		),
		'loop_id'              => array(
			'type'        => 'string',
			'description' => __( 'Optional id for the loop: "loop-" then letters, digits, "-" or "_". Leave it out and SaddleWP makes one; enable and edit keep the id a loop already has.', 'saddle' ),
		),
		'exclude_current_post' => array(
			'type'        => 'boolean',
			'description' => __( 'Leave out the post being viewed, e.g. "related posts" on a post template (post loops).', 'saddle' ),
		),
		'ignore_sticky_posts'  => array(
			'type'        => 'boolean',
			'description' => __( 'Leave sticky posts out of the loop (post loops).', 'saddle' ),
		),
		'include_posts'        => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
			'description' => __( 'Only these post ids (user ids on a user loop). An empty list clears it.', 'saddle' ),
		),
		'exclude_posts'        => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
			'description' => __( 'Leave out these post ids (user ids on a user loop). Exclusions win over inclusions.', 'saddle' ),
		),
		'include_terms'        => array(
			'type'                 => 'object',
			'additionalProperties' => array(
				'type'  => 'array',
				'items' => array( 'type' => array( 'integer', 'string' ) ),
			),
			'description'          => __( 'Only posts in these terms: {"category": [12, "news"]}, a taxonomy then term ids or slugs. With several taxonomies a post in any of them matches. {} clears it.', 'saddle' ),
		),
		'exclude_terms'        => array(
			'type'                 => 'object',
			'additionalProperties' => array(
				'type'  => 'array',
				'items' => array( 'type' => array( 'integer', 'string' ) ),
			),
			'description'          => __( 'Leave out posts in these terms, same shape as include_terms. Exclusions win.', 'saddle' ),
		),
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- An ability parameter name, not a query argument.
		'meta_query'           => array(
			'type'        => 'array',
			'maxItems'    => 10,
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'key'     => array( 'type' => 'string' ),
					'value'   => array( 'type' => array( 'string', 'number' ) ),
					'compare' => array( 'type' => 'string' ),
					'type'    => array( 'type' => 'string' ),
				),
			),
			'description' => __( 'Custom field conditions: [{"key":"price","value":"10","compare":">=","type":"NUMERIC"}]. compare: = != > >= < <= LIKE, NOT LIKE, IN, NOT IN, BETWEEN, NOT BETWEEN, EXISTS, NOT EXISTS, REGEXP, NOT REGEXP, RLIKE (default =); IN and BETWEEN take a comma list as value. type: NUMERIC, BINARY, CHAR, DATE, DATETIME, DECIMAL, SIGNED, TIME, UNSIGNED. With several conditions Divi keeps items that match any one of them. An empty list clears it.', 'saddle' ),
		),
	);

	saddle_register_ability_once(
		'saddle/divi-list-loop-query-types',
		array(
			'label'               => __( 'List Divi loop query types', 'saddle' ),
			'description'         => __( 'Reference for Divi 5 query loops on this site: each query_type (post_types, post_taxonomies, user_roles, menus, current_page, acf_repeater) with what it repeats over, this site\'s sub_types values, the order_by values Divi offers for it and the loop_ sources that print each item; the order values; the filters (include and exclude posts or terms, custom field conditions, exclude current post, sticky posts); and how a loop id ties pagination to a loop. Read-only. Call before divi-enable-loop.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'list_loop_query_types' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-loop-query-types' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-enable-loop',
		array(
			'label'               => __( 'Enable Divi loop', 'saddle' ),
			'description'         => __( 'Turns the module at an address into a query loop: Divi repeats it once per result, for example a card per latest post. Put the loop on the module that should repeat, usually the column or group holding one card. Writes a fresh loop config and keeps the loop id the module already has. A module that offers no loop in Divi\'s builder (blog, portfolio, gallery, post slider and others with their own query, and pack modules that do not declare one) is refused, and the error names the containers around it that can loop; a module SaddleWP has no module.json for is allowed with a warning. Inside the loop, bind each item\'s values with divi-apply-dynamic-content and the loop_ sources (loop_post_title, loop_post_featured_image, loop_post_link ...): a plain post_title shows the page\'s own title on every item. The response carries "loop_id": a Pagination module (divi/post-nav) or a Post Filter (divi/post-filter) follows this loop when its attrs set "module.advanced.targetLoop.desktop.value" to that id. Reversible with divi-disable-loop.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => $loop_properties,
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'enable_loop' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-enable-loop' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-edit-loop',
		array(
			'label'               => __( 'Edit Divi loop', 'saddle' ),
			'description'         => __( 'Changes some of a loop\'s settings without replacing its config: only the parameters you pass change, the loop id is kept, and a loop that was switched off is turned back on. Takes the same parameters as divi-enable-loop. A list parameter (sub_types, include_posts, include_terms, meta_query ...) replaces the stored list; an empty list clears it. Changing query_type resets sub_types, order_by and the term filters to that type\'s defaults unless you pass them. Also rewrites a loop saved in an older format into the one Divi reads. Fails if the module has no loop: use divi-enable-loop first. Returns "loop_id".', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => $loop_properties,
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'edit_loop' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-edit-loop' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-disable-loop',
		array(
			'label'               => __( 'Disable Divi loop', 'saddle' ),
			'description'         => __( 'Switches a module\'s loop off and keeps its config and loop id: the module renders once again. divi-edit-loop turns it back on with the same config. Returns "loop_id".', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'address' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'disable_loop' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-disable-loop' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Dynamic content — bind attribute values to live data
	 * ---------------------------------------------------------------------
	 */

	saddle_register_ability_once(
		'saddle/divi-list-dynamic-sources',
		array(
			'label'               => __( 'List Divi dynamic sources', 'saddle' ),
			'description'         => __( 'Lists the dynamic content sources a module field can bind to on this site, read from what Divi registers here when it can (a built-in list otherwise), each with its value type (text, url, image) and the settings it takes. "page" sources (post, author, site, term, product when WooCommerce is active, custom fields) show the values of the page being viewed, or of the post a Theme Builder template shows. "loop" sources (loop_post_*, loop_product_*, loop_term_*, loop_user_*, loop_menu_*) change per item and resolve only inside a loop: inside a loop use them, because a plain post_title repeats the page\'s own title on every item. Each loop family says which loop query types it prints. Read-only. Call before divi-apply-dynamic-content.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'list_dynamic_sources' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-dynamic-sources' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	$dynamic_breakpoint = array(
		'type'        => 'string',
		'enum'        => array( 'desktop', 'tablet', 'phone', 'phoneWide', 'tabletWide', 'widescreen', 'ultraWide' ),
		'default'     => 'desktop',
		'description' => __( 'Screen size the binding applies to (default desktop). Ignored when "field" already names one.', 'saddle' ),
	);

	saddle_register_ability_once(
		'saddle/divi-apply-dynamic-content',
		array(
			'label'               => __( 'Apply Divi dynamic content', 'saddle' ),
			'description'         => __( 'Binds a module field to a dynamic source, replacing that value with a live binding. "field" is the attribute path from divi-get-module-schema: "title.innerContent" for a text field. For a field that holds an object, bind one part and the other parts stay: "image.innerContent.src" (the image), "button.innerContent.text" or "button.innerContent.linkUrl". A full path with a breakpoint also works, e.g. "module.advanced.link.desktop.value.url". "source" is a name from divi-list-dynamic-sources; inside a loop use the loop_ sources. "settings" holds the source\'s settings, e.g. {"before":"By ","date_format":"M j, Y"}; numbers and true/false are stored as the strings Divi reads. For a custom field use source post_meta_key with settings.meta_key, or inside a loop loop_post_meta_key_manual_custom_field with settings.loop_meta_key; settings.key is accepted as the older name. Custom fields whose names start with "_" are refused. The response "warnings" say when the source will not resolve where the module sits (a page source inside a loop, a loop_ source outside one). Reversible with divi-clear-dynamic-content.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address', 'field', 'source' ),
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'address'    => array( 'type' => 'string' ),
					'field'      => array(
						'type'        => 'string',
						'description' => __( 'Attribute path of the field: "title.innerContent", or one part of an object field such as "image.innerContent.src".', 'saddle' ),
					),
					'source'     => array(
						'type'        => 'string',
						'description' => __( 'Dynamic source name from divi-list-dynamic-sources.', 'saddle' ),
					),
					'settings'   => array(
						'type'        => 'object',
						'description' => __( 'Optional source settings: before, after, date_format, meta_key, loop_position and the others the source lists.', 'saddle' ),
					),
					'breakpoint' => $dynamic_breakpoint,
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'apply_dynamic_content' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-apply-dynamic-content' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-clear-dynamic-content',
		array(
			'label'               => __( 'Clear Divi dynamic content', 'saddle' ),
			'description'         => __( 'Removes a dynamic binding from a module field and keeps any literal text around it, or replaces the bound value with a static "value" you give. "field" takes the same paths as divi-apply-dynamic-content, so "image.innerContent.src" clears only the image. Naming a whole object field ("button.innerContent") removes every binding inside it and keeps the rest; a static "value" needs one part.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address', 'field' ),
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'address'    => array( 'type' => 'string' ),
					'field'      => array(
						'type'        => 'string',
						'description' => __( 'Attribute path of the bound field, as given to divi-apply-dynamic-content.', 'saddle' ),
					),
					'value'      => array(
						'type'        => 'string',
						'description' => __( 'Optional static replacement value.', 'saddle' ),
					),
					'breakpoint' => $dynamic_breakpoint,
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'clear_dynamic_content' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-clear-dynamic-content' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Presets applied to a module + display conditions (per-module writes)
	 * ---------------------------------------------------------------------
	 */

	saddle_register_ability_once(
		'saddle/divi-apply-global-preset',
		array(
			'label'               => __( 'Apply Divi global preset', 'saddle' ),
			'description'         => __( 'Applies an existing global style preset to the module at an address, so it inherits that preset\'s styling. Provide the preset_id from divi-list-global-presets (its module type must match the target module). Prefer presets over repeating the same edit-module styling.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address', 'preset_id' ),
				'properties' => array(
					'post_id'   => array( 'type' => 'integer' ),
					'address'   => array( 'type' => 'string' ),
					'preset_id' => array(
						'type'        => 'string',
						'description' => __( 'Preset id from divi-list-global-presets.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'apply_global_preset' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-apply-global-preset' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-list-condition-types',
		array(
			'label'               => __( 'List Divi display condition types', 'saddle' ),
			'description'         => __( 'Reference of the display-condition types a module can use to show/hide itself (logged-in status, post type, category page, date, author, and more), with the displayRule values each accepts. Read-only. Call before set-display-conditions.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'list_condition_types' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-condition-types' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-set-display-conditions',
		array(
			'label'               => __( 'Set Divi display conditions', 'saddle' ),
			'description'         => __( 'Sets the display (show/hide) conditions on the module at an address, replacing any existing set. Provide "conditions" as a list of {conditionName, displayRule, settings?, operator?}. An empty list clears all conditions (module always shows). Call divi-list-condition-types for valid names.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address', 'conditions' ),
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'address'    => array( 'type' => 'string' ),
					'conditions' => array(
						'type'        => 'array',
						'description' => __( 'List of {conditionName, displayRule, settings?, operator?}. Empty clears all.', 'saddle' ),
						'items'       => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'set_display_conditions' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-set-display-conditions' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);
}

/**
 * Execute callbacks for the Divi abilities.
 *
 * Permission has already passed (tier + capability + pause + per-ability
 * toggle) by the time these run — same contract as free Saddle's callbacks.
 */
class Saddle_Divi_Abilities {

	use Saddle_Mutation_Log;

	/**
	 * saddle/divi-check-setup.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function check_setup( $input ) {
		$out = array(
			'divi_active'  => Saddle_Divi::is_active(),
			'divi_version' => Saddle_Divi::version(),
		);

		if ( ! $out['divi_active'] ) {
			$out['note'] = __( 'Divi 5 is not the active theme. The divi-* tools are unavailable; use the regular content tools.', 'saddle' );
			return $out;
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'saddle_not_found', __( 'No post with that ID.', 'saddle' ) );
			}
			// Same per-object read guard get_page uses — the read tier alone
			// must not let a client read another user's private/draft title.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				return new WP_Error( 'saddle_forbidden', __( 'You are not allowed to read that post.', 'saddle' ), array( 'status' => 403 ) );
			}
			$builder                         = Saddle_Divi::post_builder( $post );
			$out['post']                     = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'builder' => $builder,
			);
			$out['editable_with_divi_tools'] = ( 'divi5' === $builder );

			if ( 'divi5' === $builder ) {
				$valid                     = Saddle_Divi_Tree::validate( Saddle_Divi_Tree::parse( $post->post_content ) );
				$out['post']['tree_valid'] = ! is_wp_error( $valid );
				if ( is_wp_error( $valid ) ) {
					$out['post']['tree_violations'] = $valid->get_error_data()['violations'];
				}
			} elseif ( 'divi4' === $builder ) {
				$out['note'] = __( 'This post uses legacy Divi 4 shortcodes. SaddleWP edits Divi 5 pages only — do not modify this post\'s content.', 'saddle' );
			}
		}

		return $out;
	}

	/**
	 * saddle/divi-list-modules.
	 *
	 * The catalog from every module.json on this site (Saddle_Divi_Catalog),
	 * optionally narrowed by a search. When nothing is discoverable, the few
	 * core modules every Divi 5 site has, so an agent is never left with
	 * an empty list.
	 *
	 * @param array $input Ability input (optional "search").
	 * @return array
	 */
	public static function list_modules( $input = array() ) {
		$search  = is_array( $input ) && isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
		$modules = class_exists( 'Saddle_Divi_Catalog' ) ? Saddle_Divi_Catalog::modules( $search ) : array();
		if ( ! $modules && '' === $search && ! Saddle_Divi_Schema::index() ) {
			$modules = array();
			foreach ( array( 'divi/section', 'divi/row', 'divi/column', 'divi/heading', 'divi/text', 'divi/image', 'divi/button', 'divi/blurb', 'divi/cta' ) as $type ) {
				$modules[] = array(
					'type'      => $type,
					'container' => in_array( $type, array( 'divi/section', 'divi/row', 'divi/column' ), true ),
				);
			}
		}

		/**
		 * Filter the Divi module catalog exposed to agents.
		 *
		 * @param array  $modules Module descriptors (type, title, purpose, group, container, child, children).
		 * @param string $search  The search, or ''.
		 */
		$modules = (array) apply_filters( 'saddle_divi_modules', $modules, $search );

		return array(
			'modules' => array_values( $modules ),
			'note'    => __( 'Build pages from these real module types (divi-get-module-schema explains any one of them). Never fake a layout by dumping HTML into a single module — pages must stay editable in the Visual Builder.', 'saddle' ),
		);
	}

	/**
	 * Shared guard for the surgical write abilities: Divi present, a real
	 * post/page the user may edit, and content that is actually a Divi 5 tree.
	 * Public for sibling ability classes that write the same pages.
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	public static function edit_guard( $input ) {
		return Saddle_Divi::editable_divi5_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
	}

	/**
	 * Public entry to the validated save path, for sibling ability classes
	 * (e.g. the Library "apply" write) that build a tree and need the same
	 * validate → markers → cache-clear guarantees.
	 *
	 * @param WP_Post $post Target post.
	 * @param array   $tree Block tree.
	 * @return int|WP_Error Total module count on success.
	 */
	public static function persist_public( WP_Post $post, array $tree ) {
		return self::persist_tree( $post, $tree );
	}

	/**
	 * Validate a tree and persist it — the single save path every write uses,
	 * owned by the post's builder driver. An invalid tree never reaches the
	 * database.
	 *
	 * @param WP_Post $post Target post.
	 * @param array   $tree Block tree.
	 * @return int|WP_Error Total module count on success.
	 */
	private static function persist_tree( WP_Post $post, array $tree ) {
		return self::driver_for( $post )->persist( $post, $tree );
	}

	/**
	 * The driver that owns this post's builder format. Falls back to the
	 * Divi driver when nothing claims the post — these ARE the divi-* tools,
	 * and set-page legitimately builds on empty posts where detection has
	 * nothing to read yet.
	 *
	 * @param WP_Post $post Target post.
	 * @return Saddle_Builder_Driver
	 */
	private static function driver_for( WP_Post $post ) {
		$driver = Saddle_Builder_Registry::for_post( $post );
		return $driver ? $driver : Saddle_Builder_Registry::get( 'divi' );
	}

	/**
	 * Attach applied-vs-ignored echo warnings to a successful write response.
	 * The write already landed — warnings tell the agent which style paths or
	 * content fields Divi will silently ignore, so it corrects immediately
	 * instead of discovering an unstyled page later.
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
	 * The context-discipline extras every write response carries: the fresh
	 * post version, and the full node(s) the write touched — extracted from
	 * the just-persisted tree so the follow-up divi-get-page is unnecessary.
	 * Public for sibling ability classes that write the same pages.
	 *
	 * @param int      $post_id   The post.
	 * @param array[]  $tree      The just-persisted tree.
	 * @param string[] $addresses Addresses the write touched ([] = none survive).
	 * @return array
	 */
	public static function view_extras( $post_id, array $tree, array $addresses ) {
		$fresh  = get_post( (int) $post_id );
		$extras = array( 'version' => $fresh ? Saddle_Divi_View::version( $fresh ) : null );

		$changed = $addresses ? Saddle_Divi_View::changed( $tree, $addresses ) : array();
		if ( $changed ) {
			$extras['changed'] = $changed;
		}
		return $extras;
	}

	/**
	 * saddle/divi-get-module-schema.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_module_schema( $input ) {
		$type = isset( $input['type'] ) ? trim( (string) $input['type'] ) : '';
		if ( '' === $type ) {
			return new WP_Error( 'saddle_missing_type', __( 'Provide a module "type", e.g. "divi/button".', 'saddle' ) );
		}
		$schema = Saddle_Divi_Schema::describe( $type );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		// Context discipline: composing needs content fields, not the style
		// option inventory — that detail stays behind verbose=true (or
		// divi-get-style-schema, which also carries the paths to WRITE them).
		if ( empty( $input['verbose'] ) ) {
			foreach ( $schema['attributes'] as &$attribute ) {
				unset( $attribute['decorations'], $attribute['advanced_options'] );
			}
			unset( $attribute );
		}

		$full     = isset( $input['detail'] ) && 'full' === $input['detail'];
		$settings = Saddle_Divi_Setting_Values::rows( Saddle_Divi_Settings::for_module( $type ), $full );
		if ( $settings ) {
			$schema['settings']          = $settings;
			$schema['settings_write_as'] = Saddle_Divi_Setting_Values::write_as();
			$schema['settings_note']     = Saddle_Divi_Setting_Values::note();
		}
		$notes = Saddle_Divi_Module_Notes::for_module( $type );
		if ( $notes ) {
			$schema['notes'] = $notes;
		}

		return $schema;
	}

	/**
	 * saddle/divi-get-style-schema.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_style_schema( $input ) {
		$type = isset( $input['type'] ) ? trim( (string) $input['type'] ) : '';
		if ( '' === $type ) {
			return new WP_Error( 'saddle_missing_type', __( 'Provide a module "type", e.g. "divi/text".', 'saddle' ) );
		}
		$group = isset( $input['group'] ) ? trim( (string) $input['group'] ) : '';
		return Saddle_Divi_Schema::style( $type, $group );
	}

	/**
	 * saddle/divi-add-module.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function add_module( $input ) {
		$post = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$node = isset( $input['node'] ) && is_array( $input['node'] ) ? $input['node'] : null;
		if ( ! $node ) {
			return new WP_Error( 'saddle_empty', __( 'Provide the module to add as "node".', 'saddle' ) );
		}

		$block = Saddle_Divi_Author::expand_node( $node );
		if ( is_wp_error( $block ) ) {
			return $block;
		}

		$tree     = Saddle_Divi_Tree::parse( $post->post_content );
		$parent   = isset( $input['parent_address'] ) ? trim( (string) $input['parent_address'] ) : '';
		$position = isset( $input['position'] ) ? (int) $input['position'] : -1;

		// Resolve where the new node lands (append or clamped position).
		$at = Saddle_Divi_Tree::resolve_insert_position( $tree, $parent, $position );
		if ( is_wp_error( $at ) ) {
			return $at;
		}

		$next = Saddle_Divi_Tree::insert( $tree, $parent, $at, $block );
		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$modules = self::persist_tree( $post, $next );
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		$address = '' === $parent ? (string) $at : $parent . '.' . $at;
		self::log(
			'divi-add-module',
			$post->ID,
			sprintf(
				/* translators: 1: module type, 2: post ID, 3: address. */
				__( 'Added %1$s to Divi page #%2$d at %3$s.', 'saddle' ),
				(string) $node['type'],
				$post->ID,
				$address
			)
		);

		return self::with_warnings(
			array_merge(
				array(
					'id'      => $post->ID,
					'added'   => $address,
					'modules' => $modules,
					'note'    => __( 'The added node is in "changed" — no re-read needed for it. Other addresses shift after edits; re-read before addressing other nodes.', 'saddle' ),
				),
				self::view_extras( $post->ID, $next, array( $address ) )
			),
			Saddle_Divi_Echo::check_subtree( $node, $address )
		);
	}

	/**
	 * saddle/divi-edit-module.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function edit_module( $input ) {
		$post = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$address = isset( $input['address'] ) ? trim( (string) $input['address'] ) : '';
		$tree    = Saddle_Divi_Tree::parse( $post->post_content );
		$node    = '' !== $address ? Saddle_Divi_Tree::get( $tree, $address ) : null;
		if ( ! $node ) {
			return new WP_Error( 'saddle_bad_address', sprintf( /* translators: %s: node address. */ __( 'No module at address %s.', 'saddle' ), $address ) );
		}

		$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		$attrs  = isset( $input['attrs'] ) && is_array( $input['attrs'] ) ? $input['attrs'] : array();
		$unset  = isset( $input['unset'] ) && is_array( $input['unset'] ) ? array_values( $input['unset'] ) : array();
		if ( ! $fields && ! $attrs && ! $unset ) {
			return new WP_Error( 'saddle_empty', __( 'Provide "fields", "attrs" and/or "unset" to change.', 'saddle' ) );
		}

		$stored  = is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$removed = array();
		$missing = array();
		foreach ( $unset as $path ) {
			$path = is_string( $path ) ? trim( $path ) : '';
			if ( ! preg_match( '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $path ) ) {
				return new WP_Error( 'saddle_bad_unset', __( 'Each "unset" entry is a dotted attribute path, e.g. "module.decoration.background.desktop.value.gradient".', 'saddle' ) );
			}
			$found  = false;
			$stored = self::unset_attr_path( $stored, explode( '.', $path ), $found );
			if ( $found ) {
				$removed[] = $path;
			} else {
				$missing[] = $path;
			}
		}

		// Fields fill content slots; attrs may use dotted paths ("module.
		// decoration.background.desktop.value.color"), applied at that path,
		// so a documented path written verbatim renders.
		foreach ( $fields as $attr_name => $value ) {
			$stored = self::merge_attrs( $stored, array( $attr_name => array( 'innerContent' => array( 'desktop' => array( 'value' => $value ) ) ) ) );
		}
		$node['attrs'] = self::merge_attrs( $stored, $attrs );

		$next = Saddle_Divi_Tree::replace( $tree, $address, $node );
		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$modules = self::persist_tree( $post, $next );
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		self::log(
			'divi-edit-module',
			$post->ID,
			sprintf(
				/* translators: 1: module type, 2: address, 3: post ID. */
				__( 'Edited %1$s at %2$s on Divi page #%3$d.', 'saddle' ),
				(string) $node['blockName'],
				$address,
				$post->ID
			)
		);

		$result = array(
			'id'      => $post->ID,
			'edited'  => $address,
			'modules' => $modules,
		);
		if ( $unset ) {
			$result['removed']   = $removed;
			$result['not_found'] = $missing;
		}

		return self::with_warnings(
			array_merge( $result, self::view_extras( $post->ID, $next, array( $address ) ) ),
			Saddle_Divi_Echo::check( (string) $node['blockName'], $fields, $attrs, $address )
		);
	}

	/**
	 * Merge an edit-module patch into stored attrs. An object merges key by
	 * key; a list replaces the stored list (a shorter gradient or attributes
	 * list must shrink); null changes nothing; an empty array leaves a
	 * stored value as it is. A dotted key ("a.b.c") is applied at that path,
	 * so "…stops.1.color" still edits one list item.
	 *
	 * @param array $stored Stored attrs (or a sub-object).
	 * @param array $patch  Patch at the same level.
	 * @return array
	 */
	private static function merge_attrs( array $stored, array $patch ) {
		foreach ( $patch as $key => $value ) {
			$path = is_string( $key ) && false !== strpos( $key, '.' ) ? explode( '.', $key ) : array( $key );
			$head = array_shift( $path );
			if ( $path ) {
				$value = array( implode( '.', $path ) => $value );
			}
			$current         = array_key_exists( $head, $stored ) ? $stored[ $head ] : null;
			$merged          = self::merge_attr_value( $current, $value, array_key_exists( $head, $stored ) );
			$stored[ $head ] = $merged;
			if ( null === $merged ) {
				unset( $stored[ $head ] );
			}
		}
		return $stored;
	}

	/**
	 * One patch value against the stored one (see merge_attrs()).
	 *
	 * @param mixed $current Stored value (null when absent).
	 * @param mixed $value   Patch value.
	 * @param bool  $exists  Whether a stored value exists.
	 * @return mixed The value to store; null to store nothing.
	 */
	private static function merge_attr_value( $current, $value, $exists ) {
		if ( null === $value ) {
			return $exists ? $current : null;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array() === $value ) {
			return $exists ? $current : array();
		}
		if ( array_is_list( $value ) ) {
			return self::without_nulls( Saddle_Divi_Author::expand_dotted_keys( $value ) );
		}
		$base = is_array( $current ) ? $current : array();
		if ( $base && array_is_list( $base ) && ! self::indexes_items( $value ) ) {
			$base = array(); // An object replaces a stored list.
		}
		return self::merge_attrs( $base, $value );
	}

	/**
	 * Whether every key of a patch names a list item ("1" or "1.color").
	 *
	 * @param array $value Patch.
	 * @return bool
	 */
	private static function indexes_items( array $value ) {
		foreach ( array_keys( $value ) as $key ) {
			$head = explode( '.', (string) $key );
			if ( ! ctype_digit( $head[0] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Drop nulls at every level of a value being written.
	 *
	 * @param array $value Value.
	 * @return array
	 */
	private static function without_nulls( array $value ) {
		$list = array_is_list( $value );
		foreach ( $value as $key => $item ) {
			if ( null === $item ) {
				unset( $value[ $key ] );
			} elseif ( is_array( $item ) ) {
				$value[ $key ] = self::without_nulls( $item );
			}
		}
		return $list ? array_values( $value ) : $value;
	}

	/**
	 * Remove one attr path; parents left empty go with it. A number names a
	 * list item, and the list is reindexed.
	 *
	 * @param array    $attrs Attrs.
	 * @param string[] $path  Key path.
	 * @param bool     $found Set to true when the path existed.
	 * @return array
	 */
	private static function unset_attr_path( array $attrs, array $path, &$found ) {
		$key = array_shift( $path );
		if ( ! array_key_exists( $key, $attrs ) ) {
			return $attrs;
		}
		$list = array_is_list( $attrs );
		if ( $path ) {
			if ( ! is_array( $attrs[ $key ] ) ) {
				return $attrs;
			}
			$attrs[ $key ] = self::unset_attr_path( $attrs[ $key ], $path, $found );
			if ( ! $found || array() !== $attrs[ $key ] ) {
				return $attrs;
			}
		}
		$found = true;
		unset( $attrs[ $key ] );
		return $list ? array_values( $attrs ) : $attrs;
	}

	/**
	 * saddle/divi-move-module.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function move_module( $input ) {
		$post = self::edit_guard( $input );
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
			return new WP_Error( 'saddle_bad_move', __( 'A module cannot be moved into its own subtree.', 'saddle' ) );
		}

		$tree = Saddle_Divi_Tree::parse( $post->post_content );
		$node = Saddle_Divi_Tree::get( $tree, $from );
		if ( ! $node ) {
			return new WP_Error( 'saddle_bad_address', sprintf( /* translators: %s: node address. */ __( 'No module at address %s.', 'saddle' ), $from ) );
		}

		$without = Saddle_Divi_Tree::remove( $tree, $from );
		if ( is_wp_error( $without ) ) {
			return $without;
		}

		// Removing shifts later sibling indexes at the removal level — adjust
		// the destination if it was addressed in the same snapshot.
		$dest = self::adjust_after_removal( $from, $to_parent );

		$at = Saddle_Divi_Tree::resolve_insert_position( $without, $dest, $position );
		if ( is_wp_error( $at ) ) {
			return $at;
		}

		$next = Saddle_Divi_Tree::insert( $without, $dest, $at, $node );
		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$modules = self::persist_tree( $post, $next );
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		$new_address = '' === $dest ? (string) $at : $dest . '.' . $at;
		self::log(
			'divi-move-module',
			$post->ID,
			sprintf(
				/* translators: 1: module type, 2: old address, 3: new address, 4: post ID. */
				__( 'Moved %1$s from %2$s to %3$s on Divi page #%4$d.', 'saddle' ),
				(string) $node['blockName'],
				$from,
				$new_address,
				$post->ID
			)
		);

		return array_merge(
			array(
				'id'      => $post->ID,
				'moved'   => $new_address,
				'modules' => $modules,
				'note'    => __( 'The moved node is in "changed" at its new address. Other addresses shift after edits; re-read before addressing other nodes.', 'saddle' ),
			),
			self::view_extras( $post->ID, $next, array( $new_address ) )
		);
	}

	/**
	 * Adjust an address for the index shift caused by removing a node.
	 *
	 * Only the removed node's later siblings shift (their index at the
	 * removal depth decrements); anything on another branch is unaffected.
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
	 * saddle/divi-remove-module.
	 *
	 * Leaves are removed immediately (revisions recover them). A container
	 * with children routes through the approval gate: first call returns a
	 * preview + confirm_token, the second call (with the token) executes.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function remove_module( $input ) {
		$post = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$address = isset( $input['address'] ) ? trim( (string) $input['address'] ) : '';
		$tree    = Saddle_Divi_Tree::parse( $post->post_content );
		$node    = '' !== $address ? Saddle_Divi_Tree::get( $tree, $address ) : null;
		if ( ! $node ) {
			return new WP_Error( 'saddle_bad_address', sprintf( /* translators: %s: node address. */ __( 'No module at address %s.', 'saddle' ), $address ) );
		}

		$execute = static function () use ( $post, $tree, $address ) {
			$next = Saddle_Divi_Tree::remove( $tree, $address );
			if ( is_wp_error( $next ) ) {
				return $next;
			}
			$modules = self::persist_tree( $post, $next );
			if ( is_wp_error( $modules ) ) {
				return $modules;
			}
			return array_merge(
				array(
					'id'      => $post->ID,
					'removed' => $address,
					'modules' => $modules,
					'note'    => __( 'Recoverable from the post’s revisions. Addresses shift — re-read the page.', 'saddle' ),
				),
				self::view_extras( $post->ID, $next, array() )
			);
		};

		$child_count = count( $node['innerBlocks'] );
		$token       = isset( $input['confirm_token'] ) && is_string( $input['confirm_token'] ) ? trim( $input['confirm_token'] ) : '';

		// Leaf: immediate, revision-recoverable, logged. A call that carries a
		// confirm_token always goes through the gate below, so a reused token
		// is refused instead of removing the leaf that now sits at the address.
		if ( 0 === $child_count && '' === $token ) {
			$result = $execute();
			if ( ! is_wp_error( $result ) ) {
				self::log(
					'divi-remove-module',
					$post->ID,
					sprintf(
						/* translators: 1: module type, 2: address, 3: post ID. */
						__( 'Removed %1$s at %2$s from Divi page #%3$d.', 'saddle' ),
						(string) $node['blockName'],
						$address,
						$post->ID
					)
				);
			}
			return $result;
		}

		// Subtree: two-step confirm via the shared approval gate (which logs
		// the confirmed execution itself). The bind stamps the page's content
		// version + the node's identity: addresses are positional, so a token
		// previewed before an intervening edit must be refused rather than
		// remove whatever NOW sits at the shifted address.
		return Saddle_Approval::gate(
			array(
				'action'  => 'divi-remove-module',
				'target'  => $post->ID . ':' . $address,
				'bind'    => Saddle_Divi_View::version( $post ) . '|' . md5( wp_json_encode( array( $address, (string) $node['blockName'], $child_count ) ) ),
				'summary' => sprintf(
					/* translators: 1: module type, 2: number of modules inside it, 3: address, 4: post ID. */
					_n(
						'Remove %1$s and the %2$d module inside it at %3$s on Divi page #%4$d. Recoverable from revisions.',
						'Remove %1$s and the %2$d modules inside it at %3$s on Divi page #%4$d. Recoverable from revisions.',
						$child_count,
						'saddle'
					),
					(string) $node['blockName'],
					$child_count,
					$address,
					$post->ID
				),
				'done'    => sprintf(
					/* translators: 1: module type, 2: number of modules inside it, 3: address, 4: post ID. */
					_n(
						'Removed %1$s and the %2$d module inside it at %3$s from Divi page #%4$d.',
						'Removed %1$s and the %2$d modules inside it at %3$s from Divi page #%4$d.',
						$child_count,
						'saddle'
					),
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
	 * Divi's page-level layout settings, which are post meta rather than part of
	 * the module tree.
	 *
	 * Why an ability instead of update-page's generic `meta` argument: every one
	 * of these keys is protected (leading underscore) and unregistered, so
	 * apply_custom_meta()'s current_user_can( 'edit_post_meta' ) check denies
	 * them even for an administrator — the same rule core's REST API applies.
	 * That guard is right and stays; this writes the four keys Divi actually
	 * owns, by name, with their values validated against Divi's own enum. It is
	 * the same thing Saddle_Divi_Driver already does for _et_pb_use_builder,
	 * and the same thing Divi's own Theme Builder API does for the layout key.
	 *
	 * Returns what is now stored rather than what was asked for, because the
	 * point of this tool in a build loop is that the agent can read the settings
	 * back and confirm they stuck instead of assuming.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_page_settings( $input ) {
		if ( ! Saddle_Divi::is_active() ) {
			return new WP_Error( 'saddle_no_divi', __( 'Divi 5 is not active on this site, so its page settings would do nothing.', 'saddle' ) );
		}

		$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'saddle_denied', __( 'You cannot edit this post.', 'saddle' ) );
		}

		// Same builder guard as set_page: a Divi 4 page is left alone entirely,
		// and these settings mean nothing on a page another builder owns.
		$builder = Saddle_Divi::post_builder( $post );
		if ( 'divi4' === $builder ) {
			return new WP_Error( 'saddle_not_divi5', __( 'This post is built with legacy Divi 4 shortcodes, which SaddleWP does not edit.', 'saddle' ) );
		}
		if ( 'other' === $builder && '' !== trim( (string) $post->post_content ) ) {
			return new WP_Error( 'saddle_has_content', __( 'This post is not a Divi page, so Divi page settings would not apply to it.', 'saddle' ) );
		}

		// Saddle's vocabulary on the left, Divi's stored values on the right.
		// The agent should never have to know the et_ prefixes, and a typo in
		// one is a page that silently keeps its sidebar.
		$layouts = array(
			'full_width'    => 'et_full_width_page',
			'no_sidebar'    => 'et_no_sidebar',
			'right_sidebar' => 'et_right_sidebar',
			'left_sidebar'  => 'et_left_sidebar',
		);

		if ( isset( $input['layout'] ) ) {
			$layout = (string) $input['layout'];
			if ( ! isset( $layouts[ $layout ] ) ) {
				return new WP_Error(
					'saddle_bad_layout',
					sprintf(
						/* translators: %s: comma-separated list of accepted values. */
						__( 'Unknown layout. Use one of: %s.', 'saddle' ),
						implode( ', ', array_keys( $layouts ) )
					)
				);
			}
			update_post_meta( $post->ID, '_et_pb_page_layout', $layouts[ $layout ] );
		}

		// Divi stores these as the strings 'on'/'off', not booleans.
		if ( isset( $input['show_title'] ) ) {
			update_post_meta( $post->ID, '_et_pb_show_title', $input['show_title'] ? 'on' : 'off' );
		}
		if ( isset( $input['hide_nav'] ) ) {
			update_post_meta( $post->ID, '_et_pb_post_hide_nav', $input['hide_nav'] ? 'on' : 'off' );
		}

		return array_merge(
			array( 'post_id' => $post->ID ),
			self::page_settings( $post->ID )
		);
	}

	/**
	 * The Divi page settings currently stored, in Saddle's vocabulary.
	 *
	 * Read from the database rather than echoed from the input: an agent asked
	 * to confirm a setting stuck needs the stored value, and a tool that replays
	 * its own arguments can only ever agree with itself.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function page_settings( $post_id ) {
		$stored = (string) get_post_meta( $post_id, '_et_pb_page_layout', true );
		$names  = array(
			'et_full_width_page' => 'full_width',
			'et_no_sidebar'      => 'no_sidebar',
			'et_right_sidebar'   => 'right_sidebar',
			'et_left_sidebar'    => 'left_sidebar',
		);

		return array(
			// '' means Divi has never been told, so the theme default applies.
			// Reported as null rather than guessed at, because which default a
			// site uses is a theme option this tool does not read.
			'layout'     => isset( $names[ $stored ] ) ? $names[ $stored ] : null,
			'show_title' => 'off' !== (string) get_post_meta( $post_id, '_et_pb_show_title', true ),
			'hide_nav'   => 'on' === (string) get_post_meta( $post_id, '_et_pb_post_hide_nav', true ),
		);
	}

	/**
	 * saddle/divi-set-page — replace a page's whole Divi tree.
	 *
	 * Permission (tier + pause + toggle) has passed; this enforces the
	 * per-object capability, the builder guard, and structural validity.
	 * The save is revision-backed, so the previous layout is recoverable.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_page( $input ) {
		if ( ! Saddle_Divi::is_active() ) {
			return new WP_Error( 'saddle_no_divi', __( 'Divi 5 is not active on this site, so a Divi layout would not render. Use the regular content tools instead.', 'saddle' ) );
		}

		$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'saddle_denied', __( 'You cannot edit this post.', 'saddle' ) );
		}

		// Builder guard: build on Divi 5 pages or empty posts. Never
		// overwrite Divi 4 shortcodes or existing non-Divi content.
		$builder = Saddle_Divi::post_builder( $post );
		if ( 'divi4' === $builder ) {
			return new WP_Error( 'saddle_not_divi5', __( 'This post is built with legacy Divi 4 shortcodes, which SaddleWP does not edit. Leave its content untouched.', 'saddle' ) );
		}
		if ( 'other' === $builder && '' !== trim( (string) $post->post_content ) ) {
			return new WP_Error( 'saddle_has_content', __( 'This post already has non-Divi content. Building a Divi layout would overwrite it — edit it with update-post/update-page instead, or use an empty page.', 'saddle' ) );
		}

		$nodes = isset( $input['nodes'] ) && is_array( $input['nodes'] ) ? $input['nodes'] : array();
		if ( ! $nodes ) {
			return new WP_Error( 'saddle_empty', __( 'Provide at least one section node.', 'saddle' ) );
		}

		$tree = self::driver_for( $post )->author( $nodes );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$node_count = self::persist_tree( $post, $tree );
		if ( is_wp_error( $node_count ) ) {
			return $node_count;
		}

		self::log(
			'divi-set-page',
			$post->ID,
			sprintf(
				/* translators: 1: post ID, 2: module count. */
				__( 'Built Divi layout for post #%1$d (%2$d modules). Previous layout kept as a revision.', 'saddle' ),
				$post->ID,
				$node_count
			)
		);

		// Re-read: report the ACTUAL post-save state (status can be surprising —
		// a page created as draft stays draft; slugs can shift on publish), so
		// the agent knows whether a publish step is still needed and the real URL.
		$saved = get_post( $post->ID );

		return self::with_warnings(
			array(
				'id'       => $post->ID,
				'modules'  => $node_count,
				'status'   => $saved ? $saved->post_status : $post->post_status,
				'slug'     => $saved ? $saved->post_name : $post->post_name,
				'link'     => get_permalink( $post->ID ),
				'version'  => $saved ? Saddle_Divi_View::version( $saved ) : null,
				// The compact skeleton of what was just persisted — the full
				// address map, same shape as divi-get-page compact. Without
				// it a full build returned only a count and the agent's very
				// next edit needed a re-read.
				'nodes'    => Saddle_Divi_View::project( Saddle_Divi_Tree::flatten( $tree ), 'compact' ),
				'note'     => ( $saved && 'publish' !== $saved->post_status )
					? __( 'The page is NOT published (drafts 404 for visitors) — publish it with update-page status=publish when ready.', 'saddle' )
					: null,
				'restored' => __( 'The previous layout is recoverable from the post\'s revisions.', 'saddle' ),
			),
			// The author wraps input in a divi/placeholder root unless it
			// already is one, so persisted addresses gain a leading level.
			// The echo must speak the PERSISTED addresses — an agent pastes
			// them straight into divi-edit-module.
			Saddle_Divi_Echo::check_nodes(
				$nodes,
				( 1 === count( $nodes ) && isset( $nodes[0]['type'] ) && 'divi/placeholder' === $nodes[0]['type'] ) ? '' : '0'
			)
		);
	}

	/**
	 * saddle/divi-get-page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_page( $input ) {
		$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post ) {
			return new WP_Error( 'saddle_not_found', __( 'No post with that ID.', 'saddle' ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'saddle_denied', __( 'You cannot read this post.', 'saddle' ) );
		}

		$builder = Saddle_Divi::post_builder( $post );
		if ( 'divi5' !== $builder ) {
			return new WP_Error(
				'saddle_not_divi5',
				'divi4' === $builder
					? __( 'This post is built with legacy Divi 4 shortcodes, which SaddleWP does not edit. Leave its content untouched.', 'saddle' )
					: __( 'This post is not built with Divi. Use the regular content tools instead.', 'saddle' )
			);
		}

		// Diffed reads: a caller holding the current version skips the tree
		// entirely — the page hasn't changed since it last looked.
		$version = Saddle_Divi_View::version( $post );
		if ( isset( $input['version'] ) && is_string( $input['version'] ) && $input['version'] === $version ) {
			return array(
				'id'        => $post->ID,
				'version'   => $version,
				'unchanged' => true,
			);
		}

		$tree  = Saddle_Divi_Tree::parse( $post->post_content );
		$valid = Saddle_Divi_Tree::validate( $tree );

		$mode    = isset( $input['mode'] ) && 'full' === $input['mode'] ? 'full' : 'compact';
		$address = isset( $input['address'] ) ? trim( (string) $input['address'] ) : '';
		$depth   = isset( $input['depth'] ) ? max( 0, (int) $input['depth'] ) : 0;

		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'version'    => $version,
			'mode'       => $mode,
			'tree_valid' => ! is_wp_error( $valid ),
			// The page's own layout settings ride along, because they are half
			// of what "is this page right" means and they are invisible in the
			// module tree: a landing page can have a perfect hero and still
			// render with the theme's title and sidebar on top of it. Cheap
			// (three meta reads on a post already loaded), and it is what lets
			// an agent confirm a full-width build actually took.
			'settings'   => self::page_settings( $post->ID ),
			'nodes'      => Saddle_Divi_View::project( Saddle_Divi_Tree::flatten( $tree ), $mode, $address, $depth ),
		);
	}

	/*
	---------------------------------------------------------------------
	 * Loops
	 *
	 * A Divi 5 loop lives at attrs.module.advanced.loop.desktop.value. The
	 * value Divi reads is built by Saddle_Divi_Loop.
	 * -------------------------------------------------------------------
	 */

	/**
	 * saddle/divi-list-loop-query-types.
	 *
	 * @return array
	 */
	public static function list_loop_query_types() {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $name ) {
			if ( 'attachment' !== $name ) {
				$post_types[] = $name;
			}
		}
		$taxonomies = array_values( get_taxonomies( array( 'public' => true ), 'names' ) );
		$menus      = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = $menu->slug;
		}

		$types = array(
			array(
				'type'      => 'post_types',
				'iterates'  => __( 'Posts of the post types in sub_types (default ["post"]).', 'saddle' ),
				'sub_types' => $post_types,
				'sources'   => 'loop_post_*',
			),
			array(
				'type'      => 'post_taxonomies',
				'iterates'  => __( 'Terms of the taxonomies in sub_types (default ["category"]), e.g. a card per category.', 'saddle' ),
				'sub_types' => $taxonomies,
				'sources'   => 'loop_term_*',
			),
			array(
				'type'      => 'user_roles',
				'iterates'  => __( 'Users with the roles in sub_types, or every user when it is empty.', 'saddle' ),
				'sub_types' => array_keys( wp_roles()->roles ),
				'sources'   => 'loop_user_*',
			),
			array(
				'type'      => 'menus',
				'iterates'  => __( 'Items of the menu named in sub_types (an id or slug; required).', 'saddle' ),
				'sub_types' => $menus,
				'sources'   => 'loop_menu_*',
			),
			array(
				'type'      => 'current_page',
				'iterates'  => __( 'The posts the page itself lists: the blog page, an archive, a search. For Theme Builder archive and search templates. Takes no sub_types.', 'saddle' ),
				'sub_types' => array(),
				'sources'   => 'loop_post_*',
			),
			array(
				'type'      => 'acf_repeater',
				'iterates'  => __( 'The rows of an ACF repeater field, named in repeater_field. Needs Advanced Custom Fields with repeaters.', 'saddle' ),
				'sub_types' => array(),
				'sources'   => __( 'loop_acf_<repeater>|||<sub field>, listed by divi-list-dynamic-sources on a site with repeaters', 'saddle' ),
			),
		);
		foreach ( $types as $i => $type ) {
			$base                    = 'acf_repeater' === $type['type'] ? 'repeater' : $type['type'];
			$types[ $i ]['order_by'] = isset( Saddle_Divi_Loop::ORDER_BY[ $base ] ) ? Saddle_Divi_Loop::ORDER_BY[ $base ] : array();
		}

		return array(
			'query_types' => $types,
			'older_names' => array(
				'terms' => 'post_taxonomies',
				'users' => 'user_roles',
			),
			'order'       => array(
				'desc' => __( 'Newest first, or Z to A (the default). Stored as "descending".', 'saddle' ),
				'asc'  => __( 'Oldest first, or A to Z. Stored as "ascending".', 'saddle' ),
			),
			'filters'     => array(
				'include_posts'        => __( 'Only these post ids (user ids on a user loop).', 'saddle' ),
				'exclude_posts'        => __( 'Leave out these ids. Exclusions win.', 'saddle' ),
				'include_terms'        => __( 'Only posts in these terms: {"category": [12, "news"]}.', 'saddle' ),
				'exclude_terms'        => __( 'Leave out posts in these terms, same shape.', 'saddle' ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- An ability parameter name, not a query argument.
				'meta_query'           => __( 'Custom field conditions: [{"key","value","compare","type"}]; an item matching any one condition is kept.', 'saddle' ),
				'exclude_current_post' => __( 'Leave out the post being viewed.', 'saddle' ),
				'ignore_sticky_posts'  => __( 'Leave sticky posts out.', 'saddle' ),
			),
			'loop_id'     => __( 'Every loop has an id ("loop-…"), made when the loop is turned on and kept by later edits. A Pagination module (divi/post-nav) or a Post Filter (divi/post-filter) follows a loop when its attrs set "module.advanced.targetLoop.desktop.value" to that id.', 'saddle' ),
			'note'        => __( 'Put the loop on the module that should repeat, usually the column or group holding one card. Inside it, bind values with divi-apply-dynamic-content and the loop_ sources for its type: a plain post_title shows the page\'s own title on every item.', 'saddle' ),
		);
	}

	/**
	 * saddle/divi-enable-loop — write a fresh loop config (replace semantics).
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function enable_loop( $input ) {
		return self::write_loop( $input, true );
	}

	/**
	 * saddle/divi-edit-loop — merge changes into a live loop.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function edit_loop( $input ) {
		return self::write_loop( $input, false );
	}

	/**
	 * Shared implementation for enable/edit-loop.
	 *
	 * @param array $input   Ability input.
	 * @param bool  $replace Replace the loop config (enable) vs merge (edit).
	 * @return array|WP_Error
	 */
	private static function write_loop( $input, $replace ) {
		$located = self::locate_module( $input );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		list( $post, $tree, $address, $node ) = $located;

		$current = Saddle_Divi_Loop::value( $node );
		if ( ! $replace && empty( $current ) ) {
			return new WP_Error( 'saddle_no_loop', __( 'This module has no loop to edit. Use divi-enable-loop first.', 'saddle' ) );
		}

		$warnings = array();
		if ( $replace ) {
			$warnings = Saddle_Divi_Loop::check_support( $tree, $address, (string) $node['blockName'] );
			if ( is_wp_error( $warnings ) ) {
				return $warnings;
			}
		}

		$built = Saddle_Divi_Loop::build( $current, (array) $input, $replace, $tree, $address );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		list( $value, $more ) = $built;

		$result = self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			Saddle_Divi_Loop::PATH,
			$value,
			$replace ? 'divi-enable-loop' : 'divi-edit-loop',
			sprintf(
				/* translators: 1: address, 2: post ID. */
				$replace ? __( 'Enabled a loop on the module at %1$s on Divi page #%2$d.', 'saddle' ) : __( 'Edited the loop on the module at %1$s on Divi page #%2$d.', 'saddle' ),
				$address,
				$post->ID
			),
			array(
				'loop'    => $value,
				'loop_id' => $value['loopId'],
				'note'    => Saddle_Divi_Loop::wiring_note( $value['loopId'] ),
			)
		);
		return is_wp_error( $result ) ? $result : self::with_warnings( $result, array_merge( $warnings, $more ) );
	}

	/**
	 * saddle/divi-disable-loop — enable:'off', config and loop id kept.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function disable_loop( $input ) {
		$located = self::locate_module( $input );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		list( $post, $tree, $address, $node ) = $located;

		$value = Saddle_Divi_Loop::value( $node );
		if ( empty( $value ) ) {
			return new WP_Error( 'saddle_no_loop', __( 'This module has no loop to disable.', 'saddle' ) );
		}
		$value           = Saddle_Divi_Loop::normalize( $value );
		$value['enable'] = 'off';
		$loop_id         = isset( $value['loopId'] ) && is_string( $value['loopId'] ) ? $value['loopId'] : '';

		return self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			Saddle_Divi_Loop::PATH,
			$value,
			'divi-disable-loop',
			sprintf(
				/* translators: 1: address, 2: post ID. */
				__( 'Disabled the loop on the module at %1$s on Divi page #%2$d.', 'saddle' ),
				$address,
				$post->ID
			),
			array(
				'loop'    => $value,
				'loop_id' => $loop_id,
				'note'    => '' !== $loop_id ? Saddle_Divi_Loop::wiring_note( $loop_id ) : __( 'This loop has no id yet; divi-edit-loop gives it one when it turns the loop back on.', 'saddle' ),
			)
		);
	}

	/*
	---------------------------------------------------------------------
	 * Dynamic content
	 *
	 * A binding is the literal token
	 *   $variable({"type":"content","value":{"name":"<source>","settings":{…}}})$
	 * stored at a field path, or at one part of an object value. Paths,
	 * settings and the source list are built by Saddle_Divi_Dynamic and
	 * Saddle_Divi_Dynamic_Sources.
	 * -------------------------------------------------------------------
	 */

	/**
	 * saddle/divi-list-dynamic-sources.
	 *
	 * @return array
	 */
	public static function list_dynamic_sources() {
		return Saddle_Divi_Dynamic_Sources::listing();
	}

	/**
	 * Validate an agent-supplied `settings` map before it is written into a
	 * module's attributes (dynamic-content tokens, display-condition settings).
	 *
	 * These blobs are otherwise free-form and reach the DB and the Visual
	 * Builder largely as-is, so this refuses the shapes that never make sense —
	 * nested structures, non-scalar values, oversized keys/values, or too many
	 * keys — and sanitizes the string values that remain. It deliberately does
	 * NOT enforce a per-source/per-condition schema (those vary widely across
	 * Divi sources and would be brittle to enumerate); it's a structural guard,
	 * not an allowlist.
	 *
	 * @param array  $settings Raw settings map.
	 * @param string $context  Human label for error messages.
	 * @return array|WP_Error  Sanitized flat map, or WP_Error on a structural violation.
	 */
	private static function sanitize_settings_map( array $settings, $context ) {
		if ( count( $settings ) > 30 ) {
			return new WP_Error(
				'saddle_settings_too_large',
				sprintf(
					/* translators: %s: settings context, e.g. "dynamic content". */
					__( 'Too many %s settings (max 30 keys).', 'saddle' ),
					$context
				),
				array( 'status' => 400 )
			);
		}

		$clean = array();
		foreach ( $settings as $key => $val ) {
			if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 64 ) {
				return new WP_Error(
					'saddle_bad_settings_key',
					sprintf(
						/* translators: %s: settings context. */
						__( 'Invalid %s settings key: keys must be non-empty strings under 64 characters.', 'saddle' ),
						$context
					),
					array( 'status' => 400 )
				);
			}

			if ( is_array( $val ) || is_object( $val ) ) {
				return new WP_Error(
					'saddle_nested_settings',
					sprintf(
						/* translators: 1: settings context, 2: key name. */
						__( 'The %1$s setting "%2$s" must be a scalar value, not a nested structure.', 'saddle' ),
						$context,
						$key
					),
					array( 'status' => 400 )
				);
			}

			if ( is_string( $val ) ) {
				if ( strlen( $val ) > 2000 ) {
					return new WP_Error(
						'saddle_settings_too_long',
						sprintf(
							/* translators: 1: settings context, 2: key name. */
							__( 'The %1$s setting "%2$s" is too long (max 2000 characters).', 'saddle' ),
							$context,
							$key
						),
						array( 'status' => 400 )
					);
				}
				$clean[ $key ] = sanitize_text_field( $val );
			} else {
				// Scalars (int/float/bool) and null pass through unchanged.
				$clean[ $key ] = $val;
			}
		}

		return $clean;
	}

	/**
	 * saddle/divi-apply-dynamic-content.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function apply_dynamic_content( $input ) {
		$located = self::locate_module( $input );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		list( $post, $tree, $address, $node ) = $located;

		$field = Saddle_Divi_Dynamic::field_path( isset( $input['field'] ) ? $input['field'] : '', isset( $input['breakpoint'] ) ? $input['breakpoint'] : '' );
		if ( is_wp_error( $field ) ) {
			return $field;
		}
		$source = Saddle_Divi_Dynamic::source_name( isset( $input['source'] ) ? $input['source'] : '' );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		// A provably wrong field binds nothing yet used to return success:
		// Saddle rejects, never repairs. An unjudgeable module still passes.
		$checked = Saddle_Divi_Dynamic::check_field( (string) $node['blockName'], $field );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		// Sanitize first, then map the older names and guard every meta key
		// form on the values that will be stored.
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$settings = self::sanitize_settings_map( $settings, __( 'dynamic content', 'saddle' ) );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$settings = Saddle_Divi_Dynamic::prepare_settings( $source, $settings );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$result = self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			$field,
			Saddle_Divi_Dynamic::token( $source, Saddle_Divi_Dynamic::stringify( $settings ) ),
			'divi-apply-dynamic-content',
			sprintf(
				/* translators: 1: source, 2: address, 3: post ID. */
				__( 'Bound %1$s to a module field at %2$s on Divi page #%3$d.', 'saddle' ),
				$source,
				$address,
				$post->ID
			),
			array(
				'bound' => $source,
				'field' => implode( '.', $field ),
			)
		);

		return is_wp_error( $result ) ? $result : self::with_warnings( $result, Saddle_Divi_Dynamic::context_warnings( $source, $tree, $address ) );
	}

	/**
	 * saddle/divi-clear-dynamic-content.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function clear_dynamic_content( $input ) {
		$located = self::locate_module( $input );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		list( $post, $tree, $address, $node ) = $located;

		$field = Saddle_Divi_Dynamic::field_path( isset( $input['field'] ) ? $input['field'] : '', isset( $input['breakpoint'] ) ? $input['breakpoint'] : '' );
		if ( is_wp_error( $field ) ) {
			return $field;
		}
		$checked = Saddle_Divi_Dynamic::check_field( (string) $node['blockName'], $field, true );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$current = self::attr_path_get( is_array( $node['attrs'] ) ? $node['attrs'] : array(), $field );
		if ( isset( $input['value'] ) && is_string( $input['value'] ) ) {
			if ( is_array( $current ) ) {
				return new WP_Error( 'saddle_bad_field', __( 'This field holds several parts, so a static "value" needs one part, e.g. "button.innerContent.text".', 'saddle' ) );
			}
			$value = $input['value'];
		} else {
			// Strip only the $variable(...)$ tokens, keeping literal text the
			// owner may have around the binding, in every part of an object.
			$value = null === $current ? '' : Saddle_Divi_Dynamic::strip( $current );
		}

		return self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			$field,
			$value,
			'divi-clear-dynamic-content',
			sprintf(
				/* translators: 1: address, 2: post ID. */
				__( 'Cleared a dynamic binding on the module at %1$s on Divi page #%2$d.', 'saddle' ),
				$address,
				$post->ID
			),
			array(
				'value' => $value,
				'field' => implode( '.', $field ),
			)
		);
	}

	/*
	---------------------------------------------------------------------
	 * Shared attr-write helpers
	 * -------------------------------------------------------------------
	 */

	/**
	 * Resolve input to (post, tree, address, node) behind the edit guard.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error [ WP_Post, array, string, array ].
	 */
	private static function locate_module( $input ) {
		$post = self::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$address = isset( $input['address'] ) ? trim( (string) $input['address'] ) : '';
		$tree    = Saddle_Divi_Tree::parse( $post->post_content );
		$node    = '' !== $address ? Saddle_Divi_Tree::get( $tree, $address ) : null;
		if ( ! $node ) {
			return new WP_Error( 'saddle_bad_address', sprintf( /* translators: %s: node address. */ __( 'No module at address %s.', 'saddle' ), $address ) );
		}

		return array( $post, $tree, $address, $node );
	}

	/**
	 * Read a nested attr value by path.
	 *
	 * @param array    $attrs Attrs array.
	 * @param string[] $path  Key path.
	 * @return mixed Null when the path doesn't resolve.
	 */
	private static function attr_path_get( $attrs, $path ) {
		$cursor = $attrs;
		foreach ( $path as $key ) {
			if ( ! is_array( $cursor ) || ! isset( $cursor[ $key ] ) ) {
				return null;
			}
			$cursor = $cursor[ $key ];
		}
		return $cursor;
	}

	/**
	 * Set a nested attr value by path (creating intermediate arrays).
	 *
	 * @param array    $attrs Attrs array (by value; returned modified).
	 * @param string[] $path  Key path.
	 * @param mixed    $value Value to set.
	 * @return array
	 */
	private static function attr_path_set( $attrs, $path, $value ) {
		$cursor =& $attrs;
		$last   = count( $path ) - 1;
		foreach ( $path as $i => $key ) {
			if ( $last === $i ) {
				$cursor[ $key ] = $value;
				break;
			}
			if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
				$cursor[ $key ] = array();
			}
			$cursor =& $cursor[ $key ];
		}
		return $attrs;
	}

	/**
	 * Write one attr path on one module and persist — the shared tail of every
	 * loop/dynamic ability: set, replace node, validate+save, log, respond.
	 *
	 * @param WP_Post  $post    Target post.
	 * @param array    $tree    Parsed tree.
	 * @param string   $address Module address.
	 * @param array    $node    Module node.
	 * @param string[] $path    Attr path to set.
	 * @param mixed    $value   Value to set.
	 * @param string   $action  Log action key.
	 * @param string   $summary Log summary.
	 * @param array    $extra   Extra keys merged into the response.
	 * @return array|WP_Error
	 */
	private static function save_module_attr( $post, $tree, $address, $node, $path, $value, $action, $summary, $extra = array() ) {
		$node['attrs'] = self::attr_path_set( is_array( $node['attrs'] ) ? $node['attrs'] : array(), $path, $value );

		$next = Saddle_Divi_Tree::replace( $tree, $address, $node );
		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$modules = self::persist_tree( $post, $next );
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		self::log( $action, $post->ID, $summary );

		return array_merge(
			array(
				'id'      => $post->ID,
				'address' => $address,
				'modules' => $modules,
			),
			// version + the changed node, same as the structural writes —
			// these attr writes used to return neither, forcing a re-read.
			self::view_extras( $post->ID, $next, array( $address ) ),
			$extra
		);
	}

	/*
	---------------------------------------------------------------------
	 * Presets applied to a module + display conditions
	 * -------------------------------------------------------------------
	 */

	/**
	 * saddle/divi-apply-global-preset — set attrs.modulePreset = [preset_id].
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function apply_global_preset( $input ) {
		$located = self::locate_module( $input );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		list( $post, $tree, $address, $node ) = $located;

		$preset_id = isset( $input['preset_id'] ) ? trim( (string) $input['preset_id'] ) : '';
		if ( '' === $preset_id ) {
			return new WP_Error( 'saddle_empty', __( 'Provide the "preset_id" from divi-list-global-presets.', 'saddle' ) );
		}

		// modulePreset is a stack (array of ids) at the attr root.
		return self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			array( 'modulePreset' ),
			array( $preset_id ),
			'divi-apply-global-preset',
			sprintf(
				/* translators: 1: preset id, 2: address, 3: post ID. */
				__( 'Applied preset %1$s to the module at %2$s on Divi page #%3$d.', 'saddle' ),
				$preset_id,
				$address,
				$post->ID
			),
			array( 'preset' => $preset_id )
		);
	}

	/**
	 * saddle/divi-list-condition-types — curated reference of the common Divi 5
	 * display-condition types and their displayRule values.
	 *
	 * @return array
	 */
	public static function list_condition_types() {
		return array(
			'condition_types' => array(
				array(
					'conditionName' => 'loggedInStatus',
					'displayRule'   => array( 'loggedIn', 'loggedOut' ),
				),
				array(
					'conditionName' => 'userRole',
					'note'          => __( 'settings.roles = list of role slugs.', 'saddle' ),
				),
				array(
					'conditionName' => 'postType',
					'note'          => __( 'settings.postTypes = list of post type slugs.', 'saddle' ),
				),
				array(
					'conditionName' => 'categoryPage',
					'note'          => __( 'settings.categories = list of category ids.', 'saddle' ),
				),
				array(
					'conditionName' => 'tagPage',
					'note'          => __( 'settings.tags = list of tag ids.', 'saddle' ),
				),
				array(
					'conditionName' => 'author',
					'note'          => __( 'settings.authors = list of author ids.', 'saddle' ),
				),
				array(
					'conditionName' => 'dateArchive',
					'displayRule'   => array( 'isDateArchive' ),
				),
				array(
					'conditionName' => 'searchResults',
					'displayRule'   => array( 'isSearchResults' ),
				),
				array(
					'conditionName' => 'numberOfViews',
					'note'          => __( 'settings for view count + window.', 'saddle' ),
				),
			),
			'note'            => __( 'Set with divi-set-display-conditions: each condition is {conditionName, displayRule, settings?, operator?}. operator OR/AND joins multiple. conditionSettings vary per type — this is the common set, not exhaustive.', 'saddle' ),
		);
	}

	/**
	 * saddle/divi-set-display-conditions — write module.decoration.conditions.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_display_conditions( $input ) {
		$located = self::locate_module( $input );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		list( $post, $tree, $address, $node ) = $located;

		$conditions = isset( $input['conditions'] ) && is_array( $input['conditions'] ) ? array_values( $input['conditions'] ) : null;
		if ( null === $conditions ) {
			return new WP_Error( 'saddle_empty', __( 'Provide "conditions" as a list (empty to clear).', 'saddle' ) );
		}

		$value = array();
		foreach ( $conditions as $c ) {
			if ( ! is_array( $c ) || empty( $c['conditionName'] ) || ! is_string( $c['conditionName'] ) ) {
				return new WP_Error( 'saddle_bad_condition', __( 'Each condition needs a string "conditionName". See divi-list-condition-types.', 'saddle' ) );
			}
			$settings = ( isset( $c['settings'] ) && is_array( $c['settings'] ) ) ? $c['settings'] : array();
			$settings = self::sanitize_settings_map( $settings, __( 'display condition', 'saddle' ) );
			if ( is_wp_error( $settings ) ) {
				return $settings;
			}
			$settings['enableCondition'] = 'on';
			if ( isset( $c['displayRule'] ) && is_string( $c['displayRule'] ) ) {
				$settings['displayRule'] = sanitize_text_field( $c['displayRule'] );
			}
			$value[] = array(
				'id'                => wp_generate_uuid4(),
				'conditionName'     => sanitize_text_field( $c['conditionName'] ),
				'conditionSettings' => $settings,
				'operator'          => ( isset( $c['operator'] ) && 'AND' === strtoupper( (string) $c['operator'] ) ) ? 'AND' : 'OR',
			);
		}

		return self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			array( 'module', 'decoration', 'conditions', 'desktop', 'value' ),
			$value,
			'divi-set-display-conditions',
			sprintf(
				/* translators: 1: count, 2: address, 3: post ID. */
				__( 'Set %1$d display condition(s) on the module at %2$s on Divi page #%3$d.', 'saddle' ),
				count( $value ),
				$address,
				$post->ID
			),
			array( 'conditions' => count( $value ) )
		);
	}
}
