<?php
/**
 * Navigation menu abilities: read menus, edit their items, assign locations.
 *
 * Classic themes have no navigation block, so these are the only way an agent
 * can change a menu on most Divi and classic sites (#244). Core's menu API
 * only: wp_get_nav_menus(), wp_get_nav_menu_items(), wp_update_nav_menu_item(),
 * and wp_delete_post() for an item.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the menu abilities. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_menu_abilities() {
	$menu_ref = array(
		'type'        => array( 'integer', 'string' ),
		'description' => __( 'The menu: its id, slug or name, from saddle/list-menus.', 'saddle' ),
	);
	$item_id  = array(
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'The menu item id, from saddle/get-menu.', 'saddle' ),
	);
	$position = array(
		'type'        => 'integer',
		'minimum'     => 0,
		'description' => __( 'Place among its siblings, 0 = first. Omit to add at the end.', 'saddle' ),
	);
	$parent   = array(
		'type'        => 'integer',
		'minimum'     => 0,
		'description' => __( 'Parent menu item id, or 0 for the top level.', 'saddle' ),
	);

	wp_register_ability(
		'saddle/list-menus',
		array(
			'label'               => __( 'List menus', 'saddle' ),
			'description'         => __( 'Lists the site\'s navigation menus (id, name, slug, item count, the theme locations each is assigned to) and the theme\'s menu locations with the menu in each. Read-only. Classic themes show these menus in their header and footer; block themes use the navigation block instead.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Menu_Abilities', 'list_menus' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-menus' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-menu',
		array(
			'label'               => __( 'Get menu', 'saddle' ),
			'description'         => __( 'Returns one menu\'s items in display order: id, title, url, what it links to (type: custom, post_type or taxonomy; the object and its id), parent, position among its siblings, depth, and link target. Pass "menu", or "location" for the menu in a theme location. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'menu'     => $menu_ref,
					'location' => array(
						'type'        => 'string',
						'description' => __( 'A theme location slug from saddle/list-menus, instead of "menu".', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Menu_Abilities', 'get_menu' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-menu' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/add-menu-item',
		array(
			'label'               => __( 'Add menu item', 'saddle' ),
			'description'         => __( 'Adds an item to a menu. "type" is "custom" (give url and title), "post" (give object_id: a post, page or other content item; its title is used unless you give one) or "term" (give object_id: a category, tag or other term). Place it with parent and position. Returns the new item and the menu.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'menu', 'type' ),
				'properties' => array(
					'menu'      => $menu_ref,
					'type'      => array(
						'type' => 'string',
						'enum' => array( 'custom', 'post', 'term' ),
					),
					'object_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The post or term id, for type "post" or "term".', 'saddle' ),
					),
					'url'       => array(
						'type'        => 'string',
						'description' => __( 'The link, for type "custom".', 'saddle' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The label. Required for "custom"; optional otherwise.', 'saddle' ),
					),
					'parent'    => $parent,
					'position'  => $position,
					'target'    => array(
						'type'        => 'string',
						'enum'        => array( '', '_blank' ),
						'description' => __( '"_blank" opens the link in a new tab.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Menu_Abilities', 'add_menu_item' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_theme_options', 'add-menu-item' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/update-menu-item',
		array(
			'label'               => __( 'Update menu item', 'saddle' ),
			'description'         => __( 'Changes a menu item\'s label, link (custom items only), target, CSS classes, title attribute or description. Only the fields you pass change. To move it, use saddle/move-menu-item.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'          => $item_id,
					'title'       => array( 'type' => 'string' ),
					'url'         => array( 'type' => 'string' ),
					'target'      => array(
						'type' => 'string',
						'enum' => array( '', '_blank' ),
					),
					'classes'     => array(
						'type'        => 'string',
						'description' => __( 'Space-separated CSS classes.', 'saddle' ),
					),
					'attr_title'  => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'Saddle_Menu_Abilities', 'update_menu_item' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_theme_options', 'update-menu-item' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/move-menu-item',
		array(
			'label'               => __( 'Move menu item', 'saddle' ),
			'description'         => __( 'Moves a menu item, with the items nested under it, to a new parent and position within the same menu. An item can\'t move under itself or its own children.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id', 'parent' ),
				'properties' => array(
					'id'       => $item_id,
					'parent'   => $parent,
					'position' => $position,
				),
			),
			'execute_callback'    => array( 'Saddle_Menu_Abilities', 'move_menu_item' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_theme_options', 'move-menu-item' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/remove-menu-item',
		array(
			'label'               => __( 'Remove menu item', 'saddle' ),
			'description'         => __( 'Removes an item from a menu; items nested under it move up one level, as in wp-admin. The page or term it linked to is not touched. DESTRUCTIVE — two steps: the first call returns a preview and a confirm_token without changing anything; call again with confirm_token to remove.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'            => $item_id,
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'The single-use token returned by the preview call. Omit on the first call to receive a preview.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Menu_Abilities', 'remove_menu_item' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_theme_options', 'remove-menu-item' ),
			'meta'                => saddle_ability_meta( false, true, false, 'write' ),
		)
	);

	wp_register_ability(
		'saddle/set-menu-location',
		array(
			'label'               => __( 'Set menu location', 'saddle' ),
			'description'         => __( 'Shows a menu in one of the theme\'s menu locations (the header, the footer…), replacing whatever menu was there. Pass "menu": 0 to leave the location empty. The response names the menu that was there before.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'location', 'menu' ),
				'properties' => array(
					'location' => array(
						'type'        => 'string',
						'description' => __( 'A theme location slug from saddle/list-menus.', 'saddle' ),
					),
					'menu'     => $menu_ref,
				),
			),
			'execute_callback'    => array( 'Saddle_Menu_Abilities', 'set_menu_location' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_theme_options', 'set-menu-location' ),
			'meta'                => saddle_ability_meta( false, false, true, 'write' ),
		)
	);
}

/**
 * Execute callbacks for the menu abilities.
 *
 * An item is a nav_menu_item post: menu_order is its place in the whole
 * menu's flat list, and _menu_item_menu_item_parent its parent item. Every
 * move re-derives both from one depth-first walk, so the stored order always
 * matches what the menu screen shows.
 */
class Saddle_Menu_Abilities {

	/**
	 * saddle/list-menus.
	 *
	 * @return array
	 */
	public static function list_menus() {
		$assigned  = get_nav_menu_locations();
		$locations = array();
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$locations[] = array(
				'location' => $slug,
				'label'    => $label,
				'menu'     => isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0,
			);
		}

		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'id'        => (int) $menu->term_id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'items'     => (int) $menu->count,
				'locations' => array_keys( array_map( 'intval', $assigned ), (int) $menu->term_id, true ),
			);
		}

		return array(
			'menus'     => $menus,
			'locations' => $locations,
		);
	}

	/**
	 * saddle/get-menu.
	 *
	 * @param mixed $input { menu | location }.
	 * @return array|WP_Error
	 */
	public static function get_menu( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! empty( $input['location'] ) ) {
			$assigned = get_nav_menu_locations();
			$location = (string) $input['location'];
			if ( empty( $assigned[ $location ] ) ) {
				return new WP_Error( 'saddle_not_found', __( 'No menu is assigned to that location.', 'saddle' ), array( 'status' => 404 ) );
			}
			$input['menu'] = (int) $assigned[ $location ];
		}
		$menu = self::menu( $input );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		return self::menu_detail( $menu );
	}

	/**
	 * saddle/add-menu-item.
	 *
	 * @param mixed $input Item fields.
	 * @return array|WP_Error
	 */
	public static function add_menu_item( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$menu  = self::menu( $input );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$args = self::link_args( $input );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$parent = isset( $input['parent'] ) ? (int) $input['parent'] : 0;
		if ( $parent && ! self::in_menu( $parent, $menu->term_id ) ) {
			return new WP_Error( 'saddle_bad_parent', __( 'The parent must be an item of the same menu.', 'saddle' ), array( 'status' => 400 ) );
		}
		$args['menu-item-parent-id'] = $parent;
		$args['menu-item-status']    = 'publish';
		if ( isset( $input['target'] ) ) {
			$args['menu-item-target'] = '_blank' === $input['target'] ? '_blank' : '';
		}

		$id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::place( (int) $menu->term_id, (int) $id, $parent, isset( $input['position'] ) ? (int) $input['position'] : PHP_INT_MAX );

		/* translators: 1: menu item label, 2: menu name. */
		self::log( 'add-menu-item', $id, $menu, __( 'Added “%1$s” to the menu “%2$s”.', 'saddle' ) );
		return array(
			'added' => (int) $id,
			'menu'  => self::menu_detail( $menu ),
		);
	}

	/**
	 * saddle/update-menu-item. core's updater resets any field not passed, so
	 * the existing values are merged in first.
	 *
	 * @param mixed $input { id, … }.
	 * @return array|WP_Error
	 */
	public static function update_menu_item( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$item  = self::item( $input );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$menu = self::menu_of( $item->ID );

		$args = array(
			'menu-item-object-id'   => $item->object_id,
			'menu-item-object'      => $item->object,
			'menu-item-parent-id'   => $item->menu_item_parent,
			'menu-item-position'    => $item->menu_order,
			'menu-item-type'        => $item->type,
			'menu-item-title'       => $item->post_title,
			'menu-item-url'         => $item->url,
			'menu-item-description' => $item->description,
			'menu-item-attr-title'  => $item->attr_title,
			'menu-item-target'      => $item->target,
			'menu-item-classes'     => implode( ' ', array_filter( (array) $item->classes ) ),
			'menu-item-xfn'         => $item->xfn,
			'menu-item-status'      => $item->post_status,
		);
		foreach ( array(
			'title'       => 'menu-item-title',
			'target'      => 'menu-item-target',
			'classes'     => 'menu-item-classes',
			'attr_title'  => 'menu-item-attr-title',
			'description' => 'menu-item-description',
		) as $field => $key ) {
			if ( isset( $input[ $field ] ) ) {
				$args[ $key ] = sanitize_text_field( (string) $input[ $field ] );
			}
		}
		if ( isset( $input['url'] ) ) {
			if ( 'custom' !== $item->type ) {
				return new WP_Error( 'saddle_bad_field', __( 'Only a custom link has its own url; this item links to a post or term. Remove it and add a custom item instead.', 'saddle' ), array( 'status' => 400 ) );
			}
			$args['menu-item-url'] = esc_url_raw( (string) $input['url'] );
		}

		$result = wp_update_nav_menu_item( $menu->term_id, $item->ID, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		/* translators: 1: menu item label, 2: menu name. */
		self::log( 'update-menu-item', $item->ID, $menu, __( 'Updated “%1$s” in the menu “%2$s”.', 'saddle' ) );
		return self::menu_detail( $menu );
	}

	/**
	 * saddle/move-menu-item.
	 *
	 * @param mixed $input { id, parent, position }.
	 * @return array|WP_Error
	 */
	public static function move_menu_item( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$item  = self::item( $input );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$menu   = self::menu_of( $item->ID );
		$parent = isset( $input['parent'] ) ? (int) $input['parent'] : 0;
		if ( $parent && ( ! self::in_menu( $parent, $menu->term_id ) || in_array( $parent, self::subtree( (int) $menu->term_id, $item->ID ), true ) ) ) {
			return new WP_Error( 'saddle_bad_parent', __( 'The new parent must be another item of the same menu, not this item or one nested under it.', 'saddle' ), array( 'status' => 400 ) );
		}

		self::place( (int) $menu->term_id, $item->ID, $parent, isset( $input['position'] ) ? (int) $input['position'] : PHP_INT_MAX );
		/* translators: 1: menu item label, 2: menu name. */
		self::log( 'move-menu-item', $item->ID, $menu, __( 'Moved “%1$s” in the menu “%2$s”.', 'saddle' ) );
		return self::menu_detail( $menu );
	}

	/**
	 * saddle/remove-menu-item: gated; nested items move up a level.
	 *
	 * @param mixed $input { id, confirm_token }.
	 * @return array|WP_Error
	 */
	public static function remove_menu_item( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$item  = self::item( $input );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$menu     = self::menu_of( $item->ID );
		$children = self::children( (int) $menu->term_id, $item->ID );

		return Saddle_Approval::gate(
			array(
				'action'  => 'remove-menu-item',
				'target'  => (string) $item->ID,
				'summary' => sprintf(
					/* translators: 1: item label, 2: menu name. */
					__( 'Remove “%1$s” from the menu “%2$s”.', 'saddle' ),
					$item->title,
					$menu->name
				),
				'preview' => array(
					'id'               => $item->ID,
					'title'            => $item->title,
					'menu'             => $menu->name,
					'children_move_up' => count( $children ),
				),
				'input'   => $input,
				'execute' => static function () use ( $item, $menu, $children ) {
					foreach ( $children as $child ) {
						update_post_meta( $child, '_menu_item_menu_item_parent', (int) $item->menu_item_parent );
					}
					if ( ! wp_delete_post( $item->ID, true ) ) {
						return new WP_Error( 'saddle_delete_failed', __( 'WordPress could not remove the item.', 'saddle' ), array( 'status' => 500 ) );
					}
					self::renumber( (int) $menu->term_id );
					return self::menu_detail( $menu );
				},
			)
		);
	}

	/**
	 * saddle/set-menu-location.
	 *
	 * @param mixed $input { location, menu }.
	 * @return array|WP_Error
	 */
	public static function set_menu_location( $input = null ) {
		$input    = is_array( $input ) ? $input : array();
		$location = isset( $input['location'] ) ? (string) $input['location'] : '';
		$known    = get_registered_nav_menus();
		if ( ! isset( $known[ $location ] ) ) {
			return new WP_Error( 'saddle_bad_location', __( 'The theme has no menu location by that name. saddle/list-menus lists them.', 'saddle' ), array( 'status' => 400 ) );
		}

		$menu_id = 0;
		if ( ! empty( $input['menu'] ) ) {
			$menu = self::menu( $input );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
			$menu_id = (int) $menu->term_id;
		}

		$assigned              = get_nav_menu_locations();
		$before                = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;
		$assigned[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $assigned );

		Saddle_Log::record_action(
			'set-menu-location',
			$location,
			sprintf(
				/* translators: 1: location label, 2: previous menu id, 3: new menu id. */
				__( 'Set the menu location “%1$s” from menu #%2$d to menu #%3$d.', 'saddle' ),
				$known[ $location ],
				$before,
				$menu_id
			)
		);
		return array(
			'location' => $location,
			'menu'     => $menu_id,
			'previous' => $before,
		);
	}

	/*
	---------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------
	 */

	/**
	 * Resolve the "menu" input (id, slug or name).
	 *
	 * @param array $input Input.
	 * @return WP_Term|WP_Error
	 */
	private static function menu( array $input ) {
		$menu = isset( $input['menu'] ) && '' !== $input['menu'] ? wp_get_nav_menu_object( $input['menu'] ) : false;
		return $menu ? $menu : new WP_Error( 'saddle_not_found', __( 'No menu by that id, slug or name. saddle/list-menus lists them.', 'saddle' ), array( 'status' => 404 ) );
	}

	/**
	 * The menu an item belongs to.
	 *
	 * @param int $item_id Item id.
	 * @return WP_Term|false
	 */
	private static function menu_of( $item_id ) {
		$menus = wp_get_object_terms( $item_id, 'nav_menu' );
		return ! is_wp_error( $menus ) && $menus ? $menus[0] : false;
	}

	/**
	 * Resolve the "id" input to a menu item that belongs to a menu.
	 *
	 * @param array $input Input.
	 * @return WP_Post|WP_Error
	 */
	private static function item( array $input ) {
		$post = get_post( isset( $input['id'] ) ? (int) $input['id'] : 0 );
		if ( ! $post || 'nav_menu_item' !== $post->post_type || ! self::menu_of( $post->ID ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No menu item with that id. saddle/get-menu lists them.', 'saddle' ), array( 'status' => 404 ) );
		}
		return wp_setup_nav_menu_item( $post );
	}

	/**
	 * Whether an item id belongs to a menu.
	 *
	 * @param int $item_id Item id.
	 * @param int $menu_id Menu term id.
	 * @return bool
	 */
	private static function in_menu( $item_id, $menu_id ) {
		$menu = self::menu_of( $item_id );
		return $menu && (int) $menu->term_id === (int) $menu_id;
	}

	/**
	 * What a new item links to, as core's menu-item arguments.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function link_args( array $input ) {
		$type  = isset( $input['type'] ) ? (string) $input['type'] : '';
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$id    = isset( $input['object_id'] ) ? (int) $input['object_id'] : 0;

		if ( 'custom' === $type ) {
			$url = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
			if ( '' === $url || '' === $title ) {
				return new WP_Error( 'saddle_bad_item', __( 'A custom item needs both "url" and "title".', 'saddle' ), array( 'status' => 400 ) );
			}
			return array(
				'menu-item-type'  => 'custom',
				'menu-item-url'   => $url,
				'menu-item-title' => $title,
			);
		}
		if ( 'post' === $type ) {
			$post = get_post( $id );
			if ( ! $post || ! is_post_type_viewable( $post->post_type ) || 'attachment' === $post->post_type || ! current_user_can( 'read_post', $id ) ) {
				return new WP_Error( 'saddle_not_found', __( 'No post, page or other content item with that object_id.', 'saddle' ), array( 'status' => 404 ) );
			}
			return array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => $post->post_type,
				'menu-item-object-id' => $id,
				'menu-item-title'     => $title,
			);
		}
		if ( 'term' === $type ) {
			$term = get_term( $id );
			if ( ! $term instanceof WP_Term || ! is_taxonomy_viewable( $term->taxonomy ) ) {
				return new WP_Error( 'saddle_not_found', __( 'No category, tag or other term with that object_id.', 'saddle' ), array( 'status' => 404 ) );
			}
			return array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => $term->taxonomy,
				'menu-item-object-id' => $id,
				'menu-item-title'     => $title,
			);
		}
		return new WP_Error( 'saddle_bad_item', __( '"type" must be custom, post or term.', 'saddle' ), array( 'status' => 400 ) );
	}

	/**
	 * Every item of a menu grouped by parent, each group in display order.
	 *
	 * @param int $menu_id Menu term id.
	 * @return array<int, int[]>
	 */
	private static function tree( $menu_id ) {
		$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
		$ids   = array_map( 'intval', wp_list_pluck( (array) $items, 'ID' ) );
		$tree  = array();
		foreach ( (array) $items as $item ) {
			$parent = (int) $item->menu_item_parent;
			// An item whose parent is gone shows at the top level, as core does.
			$tree[ in_array( $parent, $ids, true ) ? $parent : 0 ][] = (int) $item->ID;
		}
		return $tree;
	}

	/**
	 * The direct children of an item.
	 *
	 * @param int $menu_id Menu term id.
	 * @param int $item_id Item id.
	 * @return int[]
	 */
	private static function children( $menu_id, $item_id ) {
		$tree = self::tree( $menu_id );
		return isset( $tree[ $item_id ] ) ? $tree[ $item_id ] : array();
	}

	/**
	 * An item and every item nested under it.
	 *
	 * @param int $menu_id Menu term id.
	 * @param int $item_id Item id.
	 * @return int[]
	 */
	private static function subtree( $menu_id, $item_id ) {
		$tree = self::tree( $menu_id );
		$out  = array( $item_id );
		// A breadth-first walk; the list grows as it goes.
		for ( $i = 0; isset( $out[ $i ] ); $i++ ) {
			if ( isset( $tree[ $out[ $i ] ] ) ) {
				$out = array_merge( $out, $tree[ $out[ $i ] ] );
			}
		}
		return $out;
	}

	/**
	 * Put an item (with its subtree) under a parent at a position, then
	 * renumber the whole menu.
	 *
	 * @param int $menu_id  Menu term id.
	 * @param int $item_id  Item id.
	 * @param int $parent_id Parent item id, 0 for the top level.
	 * @param int $position  Index among the new siblings.
	 */
	private static function place( $menu_id, $item_id, $parent_id, $position ) {
		$tree = self::tree( $menu_id );
		foreach ( $tree as $key => $siblings ) {
			$tree[ $key ] = array_values( array_diff( $siblings, array( $item_id ) ) );
		}
		$siblings = isset( $tree[ $parent_id ] ) ? $tree[ $parent_id ] : array();
		array_splice( $siblings, max( 0, min( $position, count( $siblings ) ) ), 0, array( $item_id ) );
		$tree[ $parent_id ] = $siblings;

		update_post_meta( $item_id, '_menu_item_menu_item_parent', $parent_id );
		self::write_order( $tree );
	}

	/**
	 * Renumber a menu from its current tree.
	 *
	 * @param int $menu_id Menu term id.
	 */
	private static function renumber( $menu_id ) {
		self::write_order( self::tree( $menu_id ) );
	}

	/**
	 * Write menu_order from a depth-first walk of the tree, touching only the
	 * items whose number changes.
	 *
	 * @param array<int, int[]> $tree Parent => children.
	 */
	private static function write_order( array $tree ) {
		$order = array();
		$walk  = static function ( $parent_id ) use ( &$walk, &$order, $tree ) {
			foreach ( isset( $tree[ $parent_id ] ) ? $tree[ $parent_id ] : array() as $id ) {
				$order[] = $id;
				$walk( $id );
			}
		};
		$walk( 0 );

		foreach ( $order as $index => $id ) {
			if ( (int) get_post_field( 'menu_order', $id ) !== $index + 1 ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'menu_order' => $index + 1,
					)
				);
			}
		}
	}

	/**
	 * A menu and its items in display order, with parent, position and depth.
	 * Items linking to something this account can't read are left out.
	 *
	 * @param WP_Term $menu Menu.
	 * @return array
	 */
	private static function menu_detail( $menu ) {
		$items    = array();
		$position = array();
		$depth    = array( 0 => -1 );
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			if ( 'post_type' === $item->type && ! current_user_can( 'read_post', (int) $item->object_id ) ) {
				continue;
			}
			$parent              = (int) $item->menu_item_parent;
			$position[ $parent ] = isset( $position[ $parent ] ) ? $position[ $parent ] + 1 : 0;
			$depth[ $item->ID ]  = isset( $depth[ $parent ] ) ? $depth[ $parent ] + 1 : 0;
			$items[]             = array(
				'id'        => (int) $item->ID,
				'title'     => $item->title,
				'url'       => $item->url,
				'type'      => $item->type,
				'object'    => $item->object,
				'object_id' => (int) $item->object_id,
				'parent'    => $parent,
				'position'  => $position[ $parent ],
				'depth'     => $depth[ $item->ID ],
				'target'    => $item->target,
			);
		}
		return array(
			'id'    => (int) $menu->term_id,
			'name'  => $menu->name,
			'items' => $items,
		);
	}

	/**
	 * Log a menu change.
	 *
	 * @param string  $action  Action key.
	 * @param int     $item_id Item id.
	 * @param WP_Term $menu    Menu.
	 * @param string  $format  Summary format: %1$s item label, %2$s menu name.
	 */
	private static function log( $action, $item_id, $menu, $format ) {
		$item = get_post( $item_id );
		Saddle_Log::record_action( $action, $item_id, sprintf( $format, $item ? wp_setup_nav_menu_item( $item )->title : '#' . $item_id, $menu->name ) );
	}
}
