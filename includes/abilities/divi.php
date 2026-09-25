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
			'description'         => __( 'Returns the catalog of Divi 5 module types available for building pages, each with a one-line purpose and whether it is a structural container (section/row/column) or a content module. Read-only. Use real modules from this catalog — never approximate a layout by dumping HTML into a code module.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
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
						'description' => __( 'Top-level sections (each {"type","fields","attrs","children"}). Saddle wraps them in Divi\'s root container automatically.', 'saddle' ),
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
			'description'         => __( 'Returns one Divi module type\'s distilled schema: its content attributes with the exact "fields" syntax to write them (string vs object with named fields), and whether it is a container and which children it accepts. That is everything needed to COMPOSE the module; pass verbose=true to also list each element\'s design (decoration) option groups, or use divi-get-style-schema for styling paths. Covers core and third-party modules discovered on this site.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'type' ),
				'properties' => array(
					'type'    => array(
						'type'        => 'string',
						'description' => __( 'The module type, e.g. "divi/button".', 'saddle' ),
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
			'description'         => __( 'Returns a module\'s STYLING vocabulary — the universal decoration groups (background, spacing, border, sizing, fonts, filters, transform, …) and advanced options it supports, each with the exact attribute path to set it. This is separate from divi-get-module-schema (which covers content); read it before styling a module. Call without "group" for the list, then with a "group" for that group\'s settable fields. Style paths are per-module — never reuse one module\'s path on another.', 'saddle' ),
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
						'description' => __( 'Optional decoration group to expand, e.g. "spacing".', 'saddle' ),
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
			'description'         => __( 'Patches one addressed module on a Divi 5 page. "fields" replaces content values (same syntax as divi-set-page); "attrs" deep-merges raw canonical attributes over the existing ones (styling). Children are untouched. The result is validated before saving, and the previous state is kept as a revision. Response "warnings" flag fields or style paths this module will silently ignore.', 'saddle' ),
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

	saddle_register_ability_once(
		'saddle/divi-list-loop-query-types',
		array(
			'label'               => __( 'List Divi loop query types', 'saddle' ),
			'description'         => __( 'Reference for Divi 5 query loops: the valid query_type values (post_types, post_taxonomies, terms, users, user_roles, menus), what sub_types means for each, and the ordering options. Read-only. Call before enable-loop.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
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
			'description'         => __( 'Turns a module at an address into a query loop that repeats once per result (e.g. latest posts). Replaces any existing loop config on the module. Inside the loop, bind per-item values with apply-dynamic-content using loop_* sources. Reversible with disable-loop.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The Divi page.', 'saddle' ),
					),
					'address'    => array(
						'type'        => 'string',
						'description' => __( 'Module address from divi-get-page.', 'saddle' ),
					),
					'query_type' => array(
						'type'        => 'string',
						'enum'        => array( 'post_types', 'post_taxonomies', 'terms', 'users', 'user_roles', 'menus' ),
						'default'     => 'post_types',
						'description' => __( 'What the loop iterates over.', 'saddle' ),
					),
					'sub_types'  => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Scope within the query type: post types ("post", "page"), taxonomies, or roles.', 'saddle' ),
					),
					'order_by'   => array(
						'type'        => 'string',
						'description' => __( 'Sort field, e.g. "date", "title".', 'saddle' ),
					),
					'order'      => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'desc',
					),
					'per_page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 10,
					),
					'offset'     => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
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
			'description'         => __( 'Changes some of a live loop\'s query settings (per_page, order, sub_types, …) without replacing the whole config. Only the parameters you pass change. Fails if the module has no loop — use enable-loop first.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address' ),
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'address'    => array( 'type' => 'string' ),
					'query_type' => array(
						'type' => 'string',
						'enum' => array( 'post_types', 'post_taxonomies', 'terms', 'users', 'user_roles', 'menus' ),
					),
					'sub_types'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'order_by'   => array( 'type' => 'string' ),
					'order'      => array(
						'type' => 'string',
						'enum' => array( 'asc', 'desc' ),
					),
					'per_page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
					'offset'     => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
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
			'description'         => __( 'Switches a module\'s loop off while keeping its query config, so enable-loop can restore it later. The module renders once again.', 'saddle' ),
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
			'description'         => __( 'Reference of dynamic-content sources a module field can bind to: current-post values (post_title, post_excerpt, post_featured_image, …), site values (home_url, current_date), and per-item loop_* sources for loop-enabled modules. Read-only. Call before apply-dynamic-content.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Abilities', 'list_dynamic_sources' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-dynamic-sources' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-apply-dynamic-content',
		array(
			'label'               => __( 'Apply Divi dynamic content', 'saddle' ),
			'description'         => __( 'Binds a module field to a dynamic source (e.g. a heading inside a loop to loop_post_title), replacing the field\'s static value with a live binding. Provide the field\'s attribute path from divi-get-module-schema, e.g. "title.innerContent". Reversible with clear-dynamic-content.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address', 'field', 'source' ),
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'address'    => array( 'type' => 'string' ),
					'field'      => array(
						'type'        => 'string',
						'description' => __( 'Attribute path of the field, e.g. "title.innerContent".', 'saddle' ),
					),
					'source'     => array(
						'type'        => 'string',
						'description' => __( 'Dynamic source name from list-dynamic-sources.', 'saddle' ),
					),
					'settings'   => array(
						'type'        => 'object',
						'description' => __( 'Optional source settings (e.g. before/after text, date_format, meta key).', 'saddle' ),
					),
					'breakpoint' => array(
						'type'    => 'string',
						'default' => 'desktop',
					),
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
			'description'         => __( 'Removes a dynamic binding from a module field, leaving any literal text around it, or replaces the field with an explicit static "value" you provide.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'address', 'field' ),
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'address'    => array( 'type' => 'string' ),
					'field'      => array( 'type' => 'string' ),
					'value'      => array(
						'type'        => 'string',
						'description' => __( 'Optional static replacement value.', 'saddle' ),
					),
					'breakpoint' => array(
						'type'    => 'string',
						'default' => 'desktop',
					),
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
				'properties' => (object) array(),
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
				$out['note'] = __( 'This post uses legacy Divi 4 shortcodes. Saddle edits Divi 5 pages only — do not modify this post\'s content.', 'saddle' );
			}
		}

		return $out;
	}

	/**
	 * saddle/divi-list-modules.
	 *
	 * A curated v0.1 catalog. Divi 5 doesn't register its modules as
	 * server-side block types in every request context, so a hand-maintained
	 * list (filterable for later dynamic discovery) is the honest baseline.
	 *
	 * @return array
	 */
	public static function list_modules() {
		$modules = array(
			array(
				'type'      => 'divi/section',
				'container' => true,
				'purpose'   => __( 'Top-level page band. Pages are a stack of sections.', 'saddle' ),
			),
			array(
				'type'      => 'divi/row',
				'container' => true,
				'purpose'   => __( 'Horizontal layout inside a section; holds columns.', 'saddle' ),
			),
			array(
				'type'      => 'divi/column',
				'container' => true,
				'purpose'   => __( 'Vertical slot inside a row; holds content modules.', 'saddle' ),
			),
			array(
				'type'      => 'divi/heading',
				'container' => false,
				'purpose'   => __( 'A heading (h1–h6).', 'saddle' ),
			),
			array(
				'type'      => 'divi/text',
				'container' => false,
				'purpose'   => __( 'Rich text content.', 'saddle' ),
			),
			array(
				'type'      => 'divi/image',
				'container' => false,
				'purpose'   => __( 'A single image.', 'saddle' ),
			),
			array(
				'type'      => 'divi/button',
				'container' => false,
				'purpose'   => __( 'A call-to-action button.', 'saddle' ),
			),
			array(
				'type'      => 'divi/blurb',
				'container' => false,
				'purpose'   => __( 'Icon/image + title + short text, the classic feature card.', 'saddle' ),
			),
			array(
				'type'      => 'divi/cta',
				'container' => false,
				'purpose'   => __( 'Call-to-action band with title, text, and button.', 'saddle' ),
			),
			array(
				'type'      => 'divi/divider',
				'container' => false,
				'purpose'   => __( 'Horizontal separator/spacer.', 'saddle' ),
			),
			array(
				'type'      => 'divi/testimonial',
				'container' => false,
				'purpose'   => __( 'Quote with author attribution.', 'saddle' ),
			),
			array(
				'type'      => 'divi/toggle',
				'container' => false,
				'purpose'   => __( 'Collapsible content panel.', 'saddle' ),
			),
			array(
				'type'      => 'divi/accordion',
				'container' => false,
				'purpose'   => __( 'Stack of toggles.', 'saddle' ),
			),
			array(
				'type'      => 'divi/gallery',
				'container' => false,
				'purpose'   => __( 'Image gallery grid or slider.', 'saddle' ),
			),
			array(
				'type'      => 'divi/video',
				'container' => false,
				'purpose'   => __( 'Embedded video.', 'saddle' ),
			),
		);

		// Prefer live discovery from module.json files (Divi core + every
		// ecosystem plugin's modules-json dir); the curated list above then
		// only contributes its one-line purposes for the common modules —
		// and remains the fallback when nothing is discoverable.
		$discovered = class_exists( 'Saddle_Divi_Schema' ) ? Saddle_Divi_Schema::catalog() : array();
		if ( $discovered ) {
			$purposes = wp_list_pluck( $modules, 'purpose', 'type' );
			$modules  = array_map(
				static function ( $m ) use ( $purposes ) {
					if ( isset( $purposes[ $m['type'] ] ) ) {
						$m['purpose'] = $purposes[ $m['type'] ];
					}
					return $m;
				},
				$discovered
			);
		}

		/**
		 * Filter the Divi module catalog exposed to agents.
		 *
		 * @param array $modules Module descriptors (type, container, purpose).
		 */
		$modules = (array) apply_filters( 'saddle_divi_modules', $modules );

		return array(
			'modules' => array_values( $modules ),
			'note'    => __( 'Build pages from these real module types (divi-get-module-schema explains any one of them). Never fake a layout by dumping HTML into a single module — pages must stay editable in the Visual Builder.', 'saddle' ),
		);
	}

	/**
	 * Shared guard for the surgical write abilities: Divi present, a real
	 * post/page the user may edit, and content that is actually a Divi 5 tree.
	 *
	 * @param array $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function edit_guard( $input ) {
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
	 *
	 * @param int      $post_id   The post.
	 * @param array[]  $tree      The just-persisted tree.
	 * @param string[] $addresses Addresses the write touched ([] = none survive).
	 * @return array
	 */
	private static function view_extras( $post_id, array $tree, array $addresses ) {
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
		if ( is_wp_error( $schema ) || ! empty( $input['verbose'] ) ) {
			return $schema;
		}

		// Context discipline: composing needs content fields, not the style
		// option inventory — that detail stays behind verbose=true (or
		// divi-get-style-schema, which also carries the paths to WRITE them).
		foreach ( $schema['attributes'] as &$attribute ) {
			unset( $attribute['decorations'], $attribute['advanced_options'] );
		}
		unset( $attribute );

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
		if ( ! $fields && ! $attrs ) {
			return new WP_Error( 'saddle_empty', __( 'Provide "fields" and/or "attrs" to change.', 'saddle' ) );
		}

		$patch = array();
		foreach ( $fields as $attr_name => $value ) {
			$patch[ $attr_name ]['innerContent'] = array( 'desktop' => array( 'value' => $value ) );
		}
		// Expand dotted style paths ("module.decoration.background.desktop.value.
		// color") into the nested object Divi reads — the same as the author, so
		// a documented path written verbatim actually renders instead of being
		// stored as an inert flat key.
		$node['attrs'] = array_replace_recursive(
			is_array( $node['attrs'] ) ? $node['attrs'] : array(),
			$patch,
			Saddle_Divi_Author::expand_dotted_keys( $attrs )
		);

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

		return self::with_warnings(
			array_merge(
				array(
					'id'      => $post->ID,
					'edited'  => $address,
					'modules' => $modules,
				),
				self::view_extras( $post->ID, $next, array( $address ) )
			),
			Saddle_Divi_Echo::check( (string) $node['blockName'], $fields, $attrs, $address )
		);
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

		// Leaf: immediate, revision-recoverable, logged.
		if ( 0 === $child_count ) {
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
					/* translators: 1: module type, 2: child count, 3: address, 4: post ID. */
					__( 'Remove %1$s and the %2$d modules inside it at %3$s on Divi page #%4$d. Recoverable from revisions.', 'saddle' ),
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
			return new WP_Error( 'saddle_not_divi5', __( 'This post is built with legacy Divi 4 shortcodes, which Saddle does not edit.', 'saddle' ) );
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
			return new WP_Error( 'saddle_not_divi5', __( 'This post is built with legacy Divi 4 shortcodes, which Saddle does not edit. Leave its content untouched.', 'saddle' ) );
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
					? __( 'This post is built with legacy Divi 4 shortcodes, which Saddle does not edit. Leave its content untouched.', 'saddle' )
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
	 * A Divi 5 loop lives at attrs.module.advanced.loop.desktop.value —
	 * {enable, queryType, subTypes, orderBy, order, postPerPage, postOffset}
	 * (shape verified against Divi 5.8's own LoopUtils reader).
	 * -------------------------------------------------------------------
	 */

	/**
	 * saddle/divi-list-loop-query-types.
	 *
	 * @return array
	 */
	public static function list_loop_query_types() {
		return array(
			'query_types' => array(
				array(
					'type'     => 'post_types',
					'iterates' => __( 'Posts of the post types named in sub_types (e.g. ["post"], ["page", "product"]).', 'saddle' ),
				),
				array(
					'type'     => 'post_taxonomies',
					'iterates' => __( 'Terms attached to the current post, from the taxonomies in sub_types.', 'saddle' ),
				),
				array(
					'type'     => 'terms',
					'iterates' => __( 'All terms of the taxonomies in sub_types (e.g. ["category"]).', 'saddle' ),
				),
				array(
					'type'     => 'users',
					'iterates' => __( 'Site users.', 'saddle' ),
				),
				array(
					'type'     => 'user_roles',
					'iterates' => __( 'Users holding the roles in sub_types (e.g. ["author"]).', 'saddle' ),
				),
				array(
					'type'     => 'menus',
					'iterates' => __( 'Items of the navigation menus in sub_types.', 'saddle' ),
				),
			),
			'order'       => array( 'asc', 'desc' ),
			'note'        => __( 'Inside a loop-enabled module, bind per-item values with apply-dynamic-content and loop_* sources (see divi-list-dynamic-sources).', 'saddle' ),
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

		$current = self::loop_value( $node );
		if ( ! $replace && empty( $current ) ) {
			return new WP_Error( 'saddle_no_loop', __( 'This module has no loop to edit. Use divi-enable-loop first.', 'saddle' ) );
		}

		$value = $replace
			? array(
				'enable'      => 'on',
				'queryType'   => 'post_types',
				'order'       => 'desc',
				'postPerPage' => 10,
				'postOffset'  => 0,
			)
			: $current;

		$map = array(
			'query_type' => 'queryType',
			'order_by'   => 'orderBy',
			'per_page'   => 'postPerPage',
			'offset'     => 'postOffset',
		);
		foreach ( $map as $in => $attr ) {
			if ( isset( $input[ $in ] ) ) {
				$value[ $attr ] = 'per_page' === $in
					? max( 1, min( 100, (int) $input[ $in ] ) )
					: ( 'offset' === $in ? max( 0, (int) $input[ $in ] ) : sanitize_key( (string) $input[ $in ] ) );
			}
		}
		if ( isset( $input['order'] ) ) {
			$value['order'] = 'asc' === strtolower( (string) $input['order'] ) ? 'asc' : 'desc';
		}
		if ( isset( $input['sub_types'] ) && is_array( $input['sub_types'] ) ) {
			$value['subTypes'] = array_values( array_filter( array_map( 'sanitize_key', $input['sub_types'] ) ) );
		}

		$valid_types = array( 'post_types', 'post_taxonomies', 'terms', 'users', 'user_roles', 'menus' );
		if ( isset( $value['queryType'] ) && ! in_array( $value['queryType'], $valid_types, true ) ) {
			return new WP_Error( 'saddle_bad_query_type', __( 'Unknown loop query_type. Call divi-list-loop-query-types for the valid values.', 'saddle' ) );
		}
		$value['enable'] = 'on';

		return self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			array( 'module', 'advanced', 'loop', 'desktop', 'value' ),
			$value,
			$replace ? 'divi-enable-loop' : 'divi-edit-loop',
			sprintf(
				/* translators: 1: address, 2: post ID. */
				$replace ? __( 'Enabled a loop on the module at %1$s on Divi page #%2$d.', 'saddle' ) : __( 'Edited the loop on the module at %1$s on Divi page #%2$d.', 'saddle' ),
				$address,
				$post->ID
			),
			array( 'loop' => $value )
		);
	}

	/**
	 * saddle/divi-disable-loop — enable:'off', config kept for re-enabling.
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

		$value = self::loop_value( $node );
		if ( empty( $value ) ) {
			return new WP_Error( 'saddle_no_loop', __( 'This module has no loop to disable.', 'saddle' ) );
		}
		$value['enable'] = 'off';

		return self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			array( 'module', 'advanced', 'loop', 'desktop', 'value' ),
			$value,
			'divi-disable-loop',
			sprintf(
				/* translators: 1: address, 2: post ID. */
				__( 'Disabled the loop on the module at %1$s on Divi page #%2$d.', 'saddle' ),
				$address,
				$post->ID
			),
			array( 'loop' => $value )
		);
	}

	/*
	---------------------------------------------------------------------
	 * Dynamic content
	 *
	 * A binding is the literal token
	 *   $variable({"type":"content","value":{"name":"<source>","settings":{…}}})$
	 * stored at <field>.<breakpoint>.value (format verified against Divi
	 * 5.8's DynamicContent option classes).
	 * -------------------------------------------------------------------
	 */

	/**
	 * saddle/divi-list-dynamic-sources.
	 *
	 * A curated reference of the everyday sources (names verified against the
	 * Divi 5.8 DynamicContentOption classes). Not exhaustive — plugin-provided
	 * sources (ACF, Woo) exist beyond this list on sites that run them.
	 *
	 * @return array
	 */
	public static function list_dynamic_sources() {
		return array(
			'post'  => array( 'post_title', 'post_excerpt', 'post_date', 'post_modified_date', 'post_author', 'post_categories', 'post_tags', 'post_featured_image', 'post_link_url', 'post_comment_count', 'post_meta_key' ),
			'site'  => array( 'home_url', 'current_date' ),
			'loop'  => array(
				'note'    => __( 'Inside a loop-enabled module, the post_* sources resolve per loop item; menu loops use the loop_menu_* sources.', 'saddle' ),
				'sources' => array( 'loop_menu_text', 'loop_menu_link', 'loop_menu_description', 'loop_manual_custom_field' ),
			),
			'usage' => __( 'Bind with apply-dynamic-content: field = the attribute path from divi-get-module-schema (e.g. "title.innerContent"), source = a name above. post_meta_key needs settings.key.', 'saddle' ),
		);
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

		$field  = self::field_path( $input );
		$source = isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : '';
		if ( is_wp_error( $field ) ) {
			return $field;
		}
		if ( '' === $source ) {
			return new WP_Error( 'saddle_empty', __( 'Provide the dynamic "source" name. Call divi-list-dynamic-sources for options.', 'saddle' ) );
		}

		// A provably-wrong field binds nothing yet used to return success.
		// When the module's schema is discoverable, the path's first segment
		// must be one of its attributes (Saddle rejects, never repairs); an
		// unjudgeable module still passes.
		$described = Saddle_Divi_Schema::describe( (string) $node['blockName'] );
		if ( ! is_wp_error( $described ) ) {
			$attr_names = array_column( $described['attributes'], 'name' );
			$first      = (string) $field[0]; // field_path() returns segments.
			if ( ! in_array( $first, $attr_names, true ) ) {
				return new WP_Error(
					'saddle_bad_field',
					sprintf(
						/* translators: 1: written segment, 2: module type, 3: attribute list. */
						__( '"%1$s" is not an attribute of %2$s, so this binding would never render. Module attributes: %3$s — use the path from divi-get-module-schema (e.g. "title.innerContent").', 'saddle' ),
						(string) $first,
						(string) $node['blockName'],
						implode( ', ', $attr_names )
					)
				);
			}
		}

		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		// Refuse binding a protected ("_"-prefixed) meta key: its value would
		// render on the public page, and protected meta is exactly where other
		// plugins keep internal or semi-sensitive data — core treats it as
		// not-user-editable for that reason. A site owner can opt specific
		// public-by-design keys back in via the filter.
		if ( isset( $settings['key'] ) && 0 === strpos( (string) $settings['key'], '_' ) ) {
			/**
			 * Meta keys that may be bound to public dynamic content despite the
			 * protected "_" prefix. Empty by default — add only keys you know are
			 * safe to expose publicly.
			 *
			 * @param string[] $allowlist Allowed protected meta keys.
			 */
			$allowlist = (array) apply_filters( 'saddle_dynamic_meta_allowlist', array() );
			if ( ! in_array( (string) $settings['key'], $allowlist, true ) ) {
				return new WP_Error(
					'saddle_protected_meta',
					__( 'The bound meta key is protected (starts with "_") — its value would become publicly visible on the page, so Saddle refuses it. A site owner can allow a specific key via the "saddle_dynamic_meta_allowlist" filter.', 'saddle' ),
					array( 'status' => 400 )
				);
			}
		}

		$settings = self::sanitize_settings_map( $settings, __( 'dynamic content', 'saddle' ) );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$token = '$variable(' . wp_json_encode(
			array(
				'type'  => 'content',
				'value' => array(
					'name'     => $source,
					'settings' => $settings ? $settings : (object) array(),
				),
			),
			JSON_UNESCAPED_UNICODE
		) . ')$';

		$result = self::save_module_attr(
			$post,
			$tree,
			$address,
			$node,
			$field,
			$token,
			'divi-apply-dynamic-content',
			sprintf(
				/* translators: 1: source, 2: address, 3: post ID. */
				__( 'Bound %1$s to a module field at %2$s on Divi page #%3$d.', 'saddle' ),
				$source,
				$address,
				$post->ID
			),
			array( 'bound' => $source )
		);

		return $result;
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

		$field = self::field_path( $input );
		if ( is_wp_error( $field ) ) {
			return $field;
		}

		if ( isset( $input['value'] ) && is_string( $input['value'] ) ) {
			$value = $input['value'];
		} else {
			// Strip only the $variable(...)$ tokens, preserving literal text
			// the owner may have around the binding.
			$current = self::attr_path_get( $node['attrs'], $field );
			$value   = is_string( $current ) ? trim( preg_replace( '/\$variable\(.*?\)\$/s', '', $current ) ) : '';
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
			array( 'value' => $value )
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
	 * Validate a "field" input into an attr path array with the breakpoint
	 * envelope appended: "title.innerContent" → [title, innerContent, desktop, value].
	 *
	 * @param array $input Ability input.
	 * @return string[]|WP_Error
	 */
	private static function field_path( $input ) {
		$field = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
		if ( '' === $field || ! preg_match( '/^[A-Za-z0-9_.]+$/', $field ) ) {
			return new WP_Error( 'saddle_bad_field', __( 'Provide the field\'s attribute path from divi-get-module-schema, e.g. "title.innerContent".', 'saddle' ) );
		}

		$breakpoint = isset( $input['breakpoint'] ) ? sanitize_key( (string) $input['breakpoint'] ) : 'desktop';
		$path       = explode( '.', $field );
		$path[]     = '' !== $breakpoint ? $breakpoint : 'desktop';
		$path[]     = 'value';
		return $path;
	}

	/**
	 * The module's loop config value, or array() when none.
	 *
	 * @param array $node Block node.
	 * @return array
	 */
	private static function loop_value( $node ) {
		$value = self::attr_path_get( is_array( $node['attrs'] ) ? $node['attrs'] : array(), array( 'module', 'advanced', 'loop', 'desktop', 'value' ) );
		return is_array( $value ) ? $value : array();
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
