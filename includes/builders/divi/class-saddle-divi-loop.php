<?php
/**
 * Divi 5 query loops: the loop value Divi reads, built from tool input.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and reads the loop config at attrs.module.advanced.loop.desktop.value.
 *
 * Every key and value shape here is read from Divi 5.10.1's own loop reader
 * (LoopUtils::get_query_args_from_attrs() and the helpers it calls) and from
 * what the Visual Builder writes. It was not render-tested. The shape rules
 * (sub type items, order words, older type names) live in
 * Saddle_Divi_Loop_Repair; the include, exclude and custom field filters in
 * Saddle_Divi_Loop_Filters.
 */
class Saddle_Divi_Loop {

	/**
	 * Where the loop lives in a module's attrs.
	 */
	const PATH = array( 'module', 'advanced', 'loop', 'desktop', 'value' );

	/**
	 * Query types a caller may name. acf_repeater becomes "repeater_<field>".
	 */
	const QUERY_TYPES = array( 'post_types', 'post_taxonomies', 'user_roles', 'menus', 'current_page', 'acf_repeater', 'terms', 'users' );

	/**
	 * Divi's order-by values per query type (QueryOrderByController). Some
	 * appear only for some post types (menu_order, parent, price ...).
	 */
	const ORDER_BY = array(
		'post_types'      => array( 'date', 'modified', 'title', 'name', 'ID', 'author', 'parent', 'type', 'menu_order', 'comment_count', 'rand', 'none', 'price', 'popularity', 'rating' ),
		'current_page'    => array( 'relevance', 'date', 'modified', 'title', 'name', 'ID', 'author', 'menu_order', 'comment_count', 'rand', 'none' ),
		'post_taxonomies' => array( 'name', 'slug', 'term_id', 'id', 'description', 'count', 'parent', 'term_order', 'none', 'meta_value_num' ),
		'user_roles'      => array( 'display_name', 'name', 'login', 'nicename', 'email', 'url', 'registered', 'post_count', 'ID' ),
		'menus'           => array( 'menu_order', 'title', 'id' ),
	);

	/**
	 * Stored loop keys that belong to one loop family, and the input that
	 * sets each. Changing the family clears them.
	 */
	const FAMILY_KEYS = array(
		'includeSpecificPosts' => 'include_posts',
		'excludeSpecificPosts' => 'exclude_posts',
		'metaQuery'            => 'meta_query',
		'excludeCurrentPost'   => 'exclude_current_post',
		'ignoreStickysPost'    => 'ignore_sticky_posts',
	);

	/**
	 * The module's stored loop value, or array() when it has none.
	 *
	 * @param array $node Block node.
	 * @return array
	 */
	public static function value( array $node ) {
		$cursor = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		foreach ( self::PATH as $key ) {
			if ( ! is_array( $cursor ) || ! isset( $cursor[ $key ] ) ) {
				return array();
			}
			$cursor = $cursor[ $key ];
		}
		return is_array( $cursor ) ? $cursor : array();
	}

	/**
	 * Rewrite a stored loop into the shape Divi reads (Saddle_Divi_Loop_Repair).
	 *
	 * @param array $value Stored loop value.
	 * @return array
	 */
	public static function normalize( array $value ) {
		return Saddle_Divi_Loop_Repair::normalize( $value );
	}

	/**
	 * Build the loop value an enable or edit call writes.
	 *
	 * @param array  $current Stored loop value (array() when none).
	 * @param array  $input   Ability input.
	 * @param bool   $replace True for enable (fresh config), false for edit.
	 * @param array  $tree    The page tree (for loop id uniqueness).
	 * @param string $address The module's address.
	 * @return array|WP_Error array( value, warnings ).
	 */
	public static function build( array $current, array $input, $replace, array $tree, $address ) {
		$current      = self::normalize( $current );
		$current_type = isset( $current['queryType'] ) && is_string( $current['queryType'] ) ? $current['queryType'] : 'post_types';

		$type = $replace ? 'post_types' : $current_type;
		if ( isset( $input['query_type'] ) ) {
			$type = self::query_type( $input );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
		}
		$base = self::base_type( $type );

		// A user loop lists the site's users on the page.
		if ( 'user_roles' === $base && ! current_user_can( 'list_users' ) ) {
			return new WP_Error(
				'saddle_loop_needs_list_users',
				__( 'A user loop shows the site\'s users on the page, so it needs an account that can list users. Ask an administrator to set it up.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		$warnings = array();
		$value    = $replace ? array() : $current;
		if ( $replace || $current_type !== $type ) {
			// A fresh config, or a new query type: what belonged to the old
			// type starts over, as Divi's builder does when the type changes,
			// and the filters of another loop family go with it.
			$cleared = array(
				'includePostWithSpecificTerms' => 'include_terms',
				'excludePostWithSpecificTerms' => 'exclude_terms',
			);
			if ( self::family( $current_type ) !== self::family( $type ) ) {
				$cleared = array_merge( $cleared, self::FAMILY_KEYS );
			}
			$reset = array();
			foreach ( $cleared as $key => $param ) {
				if ( ! empty( $value[ $key ] ) && 'off' !== $value[ $key ] && ! isset( $input[ $param ] ) ) {
					$reset[] = $param;
				}
				unset( $value[ $key ] );
			}
			unset( $value['subTypes'], $value['orderBy'] );

			$value['queryType'] = $type;
			if ( isset( self::ORDER_BY[ $base ] ) ) {
				$value['orderBy'] = self::ORDER_BY[ $base ][0];
			}
			if ( $replace ) {
				$value['order']       = 'descending';
				$value['postPerPage'] = 10;
				$value['postOffset']  = 0;
			}
			if ( isset( Saddle_Divi_Loop_Repair::DEFAULT_SUB_TYPES[ $base ] ) && ! isset( $input['sub_types'] ) ) {
				$value['subTypes'] = array( Saddle_Divi_Loop_Repair::default_item( $base ) );
			}
			if ( $reset ) {
				$warnings[] = sprintf(
					/* translators: 1: old query type, 2: new query type, 3: list of parameter names. */
					__( 'The loop changed from %1$s to %2$s, so these settings no longer apply and were cleared: %3$s. Pass them again if you still need them.', 'saddle' ),
					$current_type,
					$type,
					implode( ', ', $reset )
				);
			}
		}

		$loop_id = self::loop_id( $current, $input, $tree, $address );
		if ( is_wp_error( $loop_id ) ) {
			return $loop_id;
		}
		$value['loopId'] = $loop_id;

		$applied = self::apply_input( $value, $input, $base );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		list( $value, $more ) = $applied;

		if ( 'menus' === $base && empty( $value['subTypes'] ) ) {
			return new WP_Error( 'saddle_loop_needs_menu', __( 'A menus loop needs the menu to repeat: pass its id or slug in sub_types.', 'saddle' ) );
		}

		$value['enable'] = 'on';
		return array( $value, array_merge( $warnings, $more ) );
	}

	/**
	 * Apply every passed parameter to the loop value. A list parameter
	 * replaces the stored list; an empty list clears it, except sub_types on
	 * a post or taxonomy loop, which keeps its default.
	 *
	 * @param array  $value Loop value so far.
	 * @param array  $input Ability input.
	 * @param string $base  Query family.
	 * @return array|WP_Error array( value, warnings ).
	 */
	private static function apply_input( array $value, array $input, $base ) {
		$warnings = array();
		$is_posts = in_array( $base, array( 'post_types', 'current_page' ), true );

		if ( isset( $input['sub_types'] ) && is_array( $input['sub_types'] ) ) {
			$items = self::validate_sub_types( $base, $input['sub_types'] );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			if ( ! $items && isset( Saddle_Divi_Loop_Repair::DEFAULT_SUB_TYPES[ $base ] ) ) {
				$items      = array( Saddle_Divi_Loop_Repair::default_item( $base ) );
				$warnings[] = sprintf(
					/* translators: 1: default sub type, 2: list of valid values. */
					__( 'A loop of this type needs at least one sub type: with none, Divi would list every one of them. SaddleWP used "%1$s". Values on this site: %2$s.', 'saddle' ),
					$items[0]['value'],
					implode( ', ', self::sub_type_choices( $base ) )
				);
			}
			$value['subTypes'] = $items;
		}
		if ( isset( $input['order'] ) ) {
			$value['order'] = Saddle_Divi_Loop_Repair::order( $input['order'] );
		}
		if ( isset( $input['order_by'] ) && '' !== trim( (string) $input['order_by'] ) ) {
			$known = isset( self::ORDER_BY[ $base ] ) ? self::ORDER_BY[ $base ] : array();
			$given = trim( (string) $input['order_by'] );
			$match = null;
			foreach ( $known as $candidate ) {
				if ( 0 === strcasecmp( $candidate, $given ) ) {
					$match = $candidate;
				}
			}
			$value['orderBy'] = null !== $match ? $match : sanitize_key( $given );
			if ( null === $match && $known ) {
				$warnings[] = sprintf(
					/* translators: 1: the order_by given, 2: list of values. */
					__( 'order_by "%1$s" is not one Divi offers for this loop type, so its builder will not show it. Divi offers: %2$s.', 'saddle' ),
					$given,
					implode( ', ', $known )
				);
			}
		}
		if ( isset( $input['per_page'] ) ) {
			$value['postPerPage'] = max( 1, min( 100, (int) $input['per_page'] ) );
		}
		if ( isset( $input['offset'] ) ) {
			$value['postOffset'] = max( 0, (int) $input['offset'] );
		}

		$post_only = array(
			'exclude_current_post' => 'excludeCurrentPost',
			'ignore_sticky_posts'  => 'ignoreStickysPost',
		);
		foreach ( $post_only as $in => $key ) {
			if ( isset( $input[ $in ] ) ) {
				$value[ $key ] = $input[ $in ] ? 'on' : 'off';
			}
		}

		foreach ( array(
			'include_posts' => 'includeSpecificPosts',
			'exclude_posts' => 'excludeSpecificPosts',
		) as $in => $key ) {
			if ( isset( $input[ $in ] ) && is_array( $input[ $in ] ) ) {
				$items = Saddle_Divi_Loop_Filters::id_items( $input[ $in ], 'user_roles' === $base );
				if ( is_wp_error( $items ) ) {
					return $items;
				}
				$value[ $key ] = $items;
			}
		}

		foreach ( array(
			'include_terms' => 'includePostWithSpecificTerms',
			'exclude_terms' => 'excludePostWithSpecificTerms',
		) as $in => $key ) {
			if ( isset( $input[ $in ] ) && is_array( $input[ $in ] ) ) {
				$groups = Saddle_Divi_Loop_Filters::term_groups( $input[ $in ] );
				if ( is_wp_error( $groups ) ) {
					return $groups;
				}
				$value[ $key ] = $groups;
			}
		}

		if ( isset( $input['meta_query'] ) && is_array( $input['meta_query'] ) ) {
			$meta = Saddle_Divi_Loop_Filters::meta_items( $input['meta_query'], self::meta_type( $base ) );
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
			$value['metaQuery'] = $meta;
		}

		$ignored = array();
		foreach ( array( 'exclude_current_post', 'ignore_sticky_posts', 'include_terms', 'exclude_terms' ) as $in ) {
			if ( isset( $input[ $in ] ) && ! $is_posts ) {
				$ignored[] = $in;
			}
		}
		foreach ( array( 'include_posts', 'exclude_posts' ) as $in ) {
			if ( isset( $input[ $in ] ) && ! $is_posts && 'user_roles' !== $base ) {
				$ignored[] = $in;
			}
		}
		if ( $ignored ) {
			$warnings[] = sprintf(
				/* translators: %s: list of parameter names. */
				__( 'Divi ignores %s on this loop type: those filters apply to post loops (include_posts and exclude_posts also to user loops).', 'saddle' ),
				implode( ', ', $ignored )
			);
		}

		return array( $value, $warnings );
	}

	/**
	 * The stored query type for the input's query_type.
	 *
	 * @param array $input Ability input.
	 * @return string|WP_Error
	 */
	private static function query_type( array $input ) {
		$type = (string) $input['query_type'];
		if ( ! in_array( $type, self::QUERY_TYPES, true ) ) {
			return new WP_Error( 'saddle_bad_query_type', __( 'Unknown loop query_type. Call divi-list-loop-query-types for the valid values.', 'saddle' ) );
		}
		if ( 'acf_repeater' !== $type ) {
			return isset( Saddle_Divi_Loop_Repair::ALIASES[ $type ] ) ? Saddle_Divi_Loop_Repair::ALIASES[ $type ] : $type;
		}
		$field = isset( $input['repeater_field'] ) ? (string) $input['repeater_field'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $field ) ) {
			return new WP_Error( 'saddle_bad_repeater', __( 'An acf_repeater loop needs "repeater_field": the field name of an ACF repeater.', 'saddle' ) );
		}
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return new WP_Error( 'saddle_no_acf', __( 'An acf_repeater loop needs Advanced Custom Fields with repeater fields, and it is not active on this site.', 'saddle' ) );
		}
		// Divi lowercases the query type when it reads it (sanitize_key) and
		// then finds the repeater by name without regard to case
		// (DynamicContentACFUtils::_find_repeater_field_in_fields()).
		return 'repeater_' . strtolower( $field );
	}

	/**
	 * The query family a stored type belongs to ("repeater_x" → "repeater").
	 *
	 * @param string $type Stored query type.
	 * @return string
	 */
	public static function base_type( $type ) {
		return 0 === strpos( (string) $type, 'repeater_' ) ? 'repeater' : (string) $type;
	}

	/**
	 * What a loop type lists: posts, post_taxonomies, user_roles, menus or
	 * repeater. post_types and current_page both list posts.
	 *
	 * @param string $type Stored query type.
	 * @return string
	 */
	private static function family( $type ) {
		$base = self::base_type( $type );
		return in_array( $base, array( 'post_types', 'current_page' ), true ) ? 'posts' : $base;
	}

	/**
	 * The object type a loop's custom field conditions read.
	 *
	 * @param string $base Query family.
	 * @return string post, term or user.
	 */
	private static function meta_type( $base ) {
		if ( 'post_taxonomies' === $base ) {
			return 'term';
		}
		return 'user_roles' === $base ? 'user' : 'post';
	}

	/**
	 * The sub types a loop of this type may name on this site: viewable post
	 * types (not attachments), viewable taxonomies, roles, menu slugs.
	 *
	 * @param string $base Query family.
	 * @return string[]
	 */
	public static function sub_type_choices( $base ) {
		$out = array();
		if ( 'post_types' === $base ) {
			foreach ( get_post_types() as $name ) {
				if ( 'attachment' !== $name && is_post_type_viewable( $name ) ) {
					$out[] = $name;
				}
			}
		} elseif ( 'post_taxonomies' === $base ) {
			foreach ( get_taxonomies() as $name ) {
				if ( is_taxonomy_viewable( $name ) ) {
					$out[] = $name;
				}
			}
		} elseif ( 'user_roles' === $base ) {
			$out = array_keys( wp_roles()->roles );
		} elseif ( 'menus' === $base ) {
			foreach ( wp_get_nav_menus() as $menu ) {
				$out[] = $menu->slug;
			}
		}
		return array_values( array_map( 'strval', $out ) );
	}

	/**
	 * Validate sub types for a query family and return Divi's items. Only
	 * viewable post types and taxonomies can be looped.
	 *
	 * @param string   $base  Query family.
	 * @param string[] $names Post types, taxonomies, roles, or menu ids/slugs.
	 * @return array[]|WP_Error
	 */
	public static function validate_sub_types( $base, array $names ) {
		if ( in_array( $base, array( 'current_page', 'repeater' ), true ) ) {
			return $names ? new WP_Error( 'saddle_bad_sub_type', __( 'This loop type takes no sub_types: a current_page loop follows the page\'s own query, and a repeater loop reads its field.', 'saddle' ) ) : array();
		}
		$items = array();
		foreach ( $names as $name ) {
			$name  = trim( (string) $name );
			$label = null;
			if ( 'post_types' === $base && 'attachment' !== $name && post_type_exists( $name ) && is_post_type_viewable( $name ) ) {
				$label = get_post_type_object( $name )->labels->name;
			} elseif ( 'post_taxonomies' === $base && taxonomy_exists( $name ) && is_taxonomy_viewable( $name ) ) {
				$label = get_taxonomy( $name )->label;
			} elseif ( 'user_roles' === $base && wp_roles()->is_role( $name ) ) {
				$label = translate_user_role( wp_roles()->roles[ $name ]['name'] );
			} elseif ( 'menus' === $base && '' !== $name ) {
				$menu = wp_get_nav_menu_object( ctype_digit( $name ) ? (int) $name : $name );
				if ( $menu ) {
					$name  = (string) $menu->term_id;
					$label = $menu->name;
				}
			}
			if ( null === $label ) {
				return new WP_Error(
					'saddle_bad_sub_type',
					sprintf(
						/* translators: 1: the value given, 2: the query type, 3: list of valid values. */
						__( '"%1$s" is not a sub type a %2$s loop can use on this site. Valid values: %3$s.', 'saddle' ),
						$name,
						$base,
						implode( ', ', self::sub_type_choices( $base ) )
					)
				);
			}
			$items[] = array(
				'value' => $name,
				'label' => wp_strip_all_tags( (string) $label ),
			);
		}
		return $items;
	}

	/**
	 * The loop id to store: an id given, else the one the loop has, else a new one.
	 *
	 * @param array  $current Stored loop value.
	 * @param array  $input   Ability input.
	 * @param array  $tree    Page tree.
	 * @param string $address This module's address.
	 * @return string|WP_Error
	 */
	private static function loop_id( array $current, array $input, array $tree, $address ) {
		$taken = self::loop_ids( $tree, $address );
		if ( isset( $input['loop_id'] ) && '' !== (string) $input['loop_id'] ) {
			$id = (string) $input['loop_id'];
			if ( ! preg_match( '/^loop-[A-Za-z0-9_-]{1,60}$/', $id ) ) {
				return new WP_Error( 'saddle_bad_loop_id', __( 'A loop_id starts with "loop-" followed by letters, digits, "-" or "_", for example "loop-latest-posts".', 'saddle' ) );
			}
			if ( in_array( $id, $taken, true ) ) {
				return new WP_Error( 'saddle_loop_id_taken', __( 'Another loop on this page already uses that loop_id. Each loop needs its own.', 'saddle' ) );
			}
			return $id;
		}
		if ( ! empty( $current['loopId'] ) && is_string( $current['loopId'] ) ) {
			return $current['loopId'];
		}
		do {
			$id = 'loop-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		} while ( in_array( $id, $taken, true ) );
		return $id;
	}

	/**
	 * Loop ids used on the page, except by the module at $skip.
	 *
	 * @param array  $tree Page tree.
	 * @param string $skip Address to leave out.
	 * @return string[]
	 */
	public static function loop_ids( array $tree, $skip = '' ) {
		$ids = array();
		foreach ( Saddle_Divi_Tree::flatten( $tree ) as $node ) {
			$id = self::value( array( 'attrs' => $node['attrs'] ) );
			if ( $node['address'] !== $skip && ! empty( $id['loopId'] ) && is_string( $id['loopId'] ) ) {
				$ids[] = $id['loopId'];
			}
		}
		return $ids;
	}

	/**
	 * Whether a module type offers a loop in Divi's builder.
	 *
	 * @param string $type Module type.
	 * @return bool|null True or false from its module.json; null when there is none.
	 */
	public static function supports_loop( $type ) {
		$summary = Saddle_Divi_Schema::summary( $type );
		return null === $summary ? null : ! empty( $summary['loop'] );
	}

	/**
	 * Refuse a loop on a module that does not offer one; warn when unknown.
	 *
	 * @param array  $tree    Page tree.
	 * @param string $address Module address.
	 * @param string $type    Module type.
	 * @return string[]|WP_Error Warnings, or the refusal.
	 */
	public static function check_support( array $tree, $address, $type ) {
		$supports = self::supports_loop( $type );
		if ( true === $supports ) {
			return array();
		}
		if ( null === $supports ) {
			return array(
				sprintf(
					/* translators: %s: module type. */
					__( 'SaddleWP found no module.json for %s, so it could not check that this module offers a loop. Open the page: if the module shows only once, put the loop on its column instead.', 'saddle' ),
					$type
				),
			);
		}

		$around = array();
		$parts  = explode( '.', (string) $address );
		for ( $depth = count( $parts ) - 1; $depth > 0; $depth-- ) {
			$parts = array_slice( $parts, 0, $depth );
			$at    = implode( '.', $parts );
			$node  = Saddle_Divi_Tree::get( $tree, $at );
			if ( $node && true === self::supports_loop( (string) $node['blockName'] ) ) {
				$around[] = sprintf( '%s (%s)', $at, (string) $node['blockName'] );
			}
		}

		if ( ! $around ) {
			return new WP_Error(
				'saddle_loop_not_supported',
				sprintf(
					/* translators: %s: module type. */
					__( '%s does not offer a loop: its module.json has no loop setting, so Divi\'s builder could not show or turn off a loop on it. If it runs its own query, use its own settings (divi-get-module-schema). To repeat it, add a group or column around it and put the loop there.', 'saddle' ),
					$type
				)
			);
		}
		return new WP_Error(
			'saddle_loop_not_supported',
			sprintf(
				/* translators: 1: module type, 2: list of "address (type)" entries. */
				__( '%1$s does not offer a loop: its module.json has no loop setting, so Divi\'s builder could not show or turn off a loop on it. If it runs its own query, use its own settings (divi-get-module-schema). To repeat it, put the loop on a container around it that can loop: %2$s.', 'saddle' ),
				$type,
				implode( ', ', $around )
			)
		);
	}

	/**
	 * The one line every loop response carries about wiring.
	 *
	 * @param string $loop_id Loop id.
	 * @return string
	 */
	public static function wiring_note( $loop_id ) {
		return sprintf(
			/* translators: %s: loop id. */
			__( 'Loop id %s ties other modules to this loop: a Pagination module (divi/post-nav) or a Post Filter (divi/post-filter) follows it when its attrs set "module.advanced.targetLoop.desktop.value" to this id.', 'saddle' ),
			$loop_id
		);
	}
}
