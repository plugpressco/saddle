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
 * what the Visual Builder writes. It was not render-tested:
 *
 * - subTypes is a list of {value, label} items. A plain list of strings is
 *   dropped by Divi's normalizer, and the query then runs on every post type.
 * - order is "descending" or "ascending". Divi sorts newest first only for
 *   "descending" or an empty value; anything else sorts ascending.
 * - "terms" and "users" are older Saddle names. Divi's builder writes
 *   "post_taxonomies" and "user_roles", and its per-item reader prints
 *   nothing for a loop stored as "users".
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
	 * Older names, stored as the query type Divi's builder writes.
	 */
	const ALIASES = array(
		'terms' => 'post_taxonomies',
		'users' => 'user_roles',
	);

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
	 * Custom field comparisons and types Divi's meta query accepts.
	 */
	const META_COMPARE = array( '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE' );
	const META_TYPES   = array( 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' );

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
	 * Rewrite a stored loop into the shape Divi reads, whatever form an older
	 * write left: string sub types, "asc"/"desc", the two older type names.
	 *
	 * @param array $value Stored loop value.
	 * @return array
	 */
	public static function normalize( array $value ) {
		if ( isset( $value['queryType'] ) && isset( self::ALIASES[ $value['queryType'] ] ) ) {
			$value['queryType'] = self::ALIASES[ $value['queryType'] ];
		}
		if ( array_key_exists( 'subTypes', $value ) ) {
			$value['subTypes'] = self::sub_type_items( $value['subTypes'] );
		}
		if ( isset( $value['order'] ) ) {
			$value['order'] = self::order( $value['order'] );
		}
		return $value;
	}

	/**
	 * Any stored sub type form as {value, label} items.
	 *
	 * @param mixed $raw Strings, items, or a comma-separated string.
	 * @return array[]
	 */
	public static function sub_type_items( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}
		$items = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['value'] ) && is_scalar( $entry['value'] ) ) {
				$items[] = array(
					'value' => (string) $entry['value'],
					'label' => isset( $entry['label'] ) && is_string( $entry['label'] ) ? $entry['label'] : (string) $entry['value'],
				);
			} elseif ( is_scalar( $entry ) && '' !== (string) $entry ) {
				$items[] = array(
					'value' => (string) $entry,
					'label' => (string) $entry,
				);
			}
		}
		return $items;
	}

	/**
	 * Map any accepted order word to the value Divi reads.
	 *
	 * @param mixed $order Input or stored order.
	 * @return string "ascending" or "descending".
	 */
	public static function order( $order ) {
		$order = strtolower( trim( (string) $order ) );
		return in_array( $order, array( 'asc', 'ascending' ), true ) ? 'ascending' : 'descending';
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
		$current = self::normalize( $current );

		$type = $replace ? 'post_types' : ( isset( $current['queryType'] ) ? (string) $current['queryType'] : 'post_types' );
		if ( isset( $input['query_type'] ) ) {
			$type = self::query_type( $input );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
		}
		$type_changed = ! $replace && isset( $current['queryType'] ) && $current['queryType'] !== $type;
		$base         = self::base_type( $type );

		$value = $current;
		if ( $replace || $type_changed ) {
			// A fresh config, or a new query type: the old sub types, sort
			// and term filters belong to the old type, so they start over,
			// as Divi's builder does when the query type changes.
			$value = $replace ? array() : $current;
			unset( $value['subTypes'], $value['orderBy'], $value['includePostWithSpecificTerms'], $value['excludePostWithSpecificTerms'] );
			$value['queryType'] = $type;
			if ( isset( self::ORDER_BY[ $base ] ) ) {
				$value['orderBy'] = self::ORDER_BY[ $base ][0];
			}
			if ( $replace ) {
				$value['order']       = 'descending';
				$value['postPerPage'] = 10;
				$value['postOffset']  = 0;
			}
			$default = array(
				'post_types'      => 'post',
				'post_taxonomies' => 'category',
			);
			$items   = isset( $default[ $base ] ) && ! isset( $input['sub_types'] ) ? self::validate_sub_types( $base, array( $default[ $base ] ) ) : null;
			if ( is_array( $items ) ) {
				$value['subTypes'] = $items;
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
		list( $value, $warnings ) = $applied;

		if ( 'menus' === $base && empty( $value['subTypes'] ) ) {
			return new WP_Error( 'saddle_loop_needs_menu', __( 'A menus loop needs the menu to repeat: pass its id or slug in sub_types.', 'saddle' ) );
		}

		$value['enable'] = 'on';
		return array( $value, $warnings );
	}

	/**
	 * Apply every passed parameter to the loop value. A list parameter
	 * replaces the stored list; an empty list clears it.
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
			$value['subTypes'] = $items;
		}
		if ( isset( $input['order'] ) ) {
			$value['order'] = self::order( $input['order'] );
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
				$items = self::id_items( $input[ $in ], 'user_roles' === $base );
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
				$groups = self::term_groups( $input[ $in ] );
				if ( is_wp_error( $groups ) ) {
					return $groups;
				}
				$value[ $key ] = $groups;
			}
		}

		if ( isset( $input['meta_query'] ) && is_array( $input['meta_query'] ) ) {
			$meta = self::meta_items( $input['meta_query'] );
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
	 * Post (or user) ids as Divi's {value, label} items.
	 *
	 * @param array $ids   Ids.
	 * @param bool  $users True on a user loop.
	 * @return array[]|WP_Error
	 */
	private static function id_items( array $ids, $users ) {
		$items = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$label = null;
			if ( $users ) {
				$user  = $id > 0 && current_user_can( 'list_users' ) ? get_userdata( $id ) : false;
				$label = $user ? $user->display_name : null;
			} elseif ( $id > 0 && get_post( $id ) && current_user_can( 'read_post', $id ) ) {
				$label = get_the_title( $id );
			}
			if ( null === $label ) {
				return new WP_Error(
					'saddle_bad_loop_post',
					sprintf(
						/* translators: %d: id. */
						__( 'Item %d does not exist or you cannot read it. include_posts and exclude_posts take post ids (user ids on a user loop).', 'saddle' ),
						$id
					)
				);
			}
			$items[] = array(
				'value' => (string) $id,
				'label' => wp_strip_all_tags( (string) $label ),
			);
		}
		return $items;
	}

	/**
	 * A {taxonomy: [term ids or slugs]} map as Divi's term filter groups.
	 *
	 * @param array $map Taxonomy => terms.
	 * @return array[]|WP_Error
	 */
	private static function term_groups( array $map ) {
		$groups = array();
		foreach ( $map as $taxonomy => $terms ) {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $terms ) ) {
				return new WP_Error(
					'saddle_bad_loop_term',
					sprintf(
						/* translators: %s: taxonomy. */
						__( '"%s" is not a taxonomy on this site. include_terms and exclude_terms look like {"category": [12, "news"]}: a taxonomy, then term ids or slugs.', 'saddle' ),
						$taxonomy
					)
				);
			}
			$options = array();
			foreach ( $terms as $term ) {
				$found = is_numeric( $term ) ? get_term( (int) $term, $taxonomy ) : get_term_by( 'slug', (string) $term, $taxonomy );
				if ( ! $found || is_wp_error( $found ) ) {
					return new WP_Error(
						'saddle_bad_loop_term',
						sprintf(
							/* translators: 1: term id or slug, 2: taxonomy. */
							__( 'No term "%1$s" in %2$s.', 'saddle' ),
							(string) $term,
							$taxonomy
						)
					);
				}
				$options[] = array(
					'value' => (string) $found->term_id,
					'label' => $found->name,
				);
			}
			if ( $options ) {
				$groups[] = array(
					'categoryId'      => $taxonomy,
					'categoryName'    => get_taxonomy( $taxonomy )->label,
					'selectedOptions' => $options,
				);
			}
		}
		return $groups;
	}

	/**
	 * Custom field conditions as Divi's meta query items.
	 *
	 * @param array $items List of {key, value, compare, type}.
	 * @return array[]|WP_Error
	 */
	private static function meta_items( array $items ) {
		$out = array();
		foreach ( array_slice( array_values( $items ), 0, 10 ) as $item ) {
			$key     = is_array( $item ) && isset( $item['key'] ) ? sanitize_text_field( (string) $item['key'] ) : '';
			$compare = is_array( $item ) && isset( $item['compare'] ) ? strtoupper( trim( (string) $item['compare'] ) ) : '=';
			$type    = is_array( $item ) && ! empty( $item['type'] ) ? strtoupper( trim( (string) $item['type'] ) ) : '';
			$val     = is_array( $item ) && isset( $item['value'] ) && is_scalar( $item['value'] ) ? (string) $item['value'] : '';
			$exists  = in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true );
			if ( '' === $key || ! in_array( $compare, self::META_COMPARE, true ) || ( '' !== $type && ! in_array( $type, self::META_TYPES, true ) ) || ( '' === $val && ! $exists ) ) {
				return new WP_Error(
					'saddle_bad_meta_query',
					sprintf(
						/* translators: 1: comparisons, 2: types. */
						__( 'Each meta_query item needs a "key", a "value" (not needed for EXISTS and NOT EXISTS), and may set "compare" (%1$s) and "type" (%2$s).', 'saddle' ),
						implode( ' ', self::META_COMPARE ),
						implode( ' ', self::META_TYPES )
					)
				);
			}
			$out[] = array(
				'metaKey'   => $key,
				'metaValue' => sanitize_text_field( $val ),
				'compare'   => $compare,
				'type'      => $type,
			);
		}
		return $out;
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
			return isset( self::ALIASES[ $type ] ) ? self::ALIASES[ $type ] : $type;
		}
		$field = isset( $input['repeater_field'] ) ? (string) $input['repeater_field'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $field ) ) {
			return new WP_Error( 'saddle_bad_repeater', __( 'An acf_repeater loop needs "repeater_field": the field name of an ACF repeater.', 'saddle' ) );
		}
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return new WP_Error( 'saddle_no_acf', __( 'An acf_repeater loop needs Advanced Custom Fields with repeater fields, and it is not active on this site.', 'saddle' ) );
		}
		return 'repeater_' . $field;
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
	 * Validate sub types for a query family and return Divi's items.
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
			if ( 'post_types' === $base && post_type_exists( $name ) ) {
				$label = get_post_type_object( $name )->labels->name;
			} elseif ( 'post_taxonomies' === $base && taxonomy_exists( $name ) ) {
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
						/* translators: 1: the value given, 2: the query type. */
						__( '"%1$s" is not a valid sub type for a %2$s loop on this site. Call divi-list-loop-query-types for the valid values.', 'saddle' ),
						$name,
						$base
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
		static $memo = array();
		$index       = Saddle_Divi_Schema::index();
		if ( ! isset( $index[ $type ] ) ) {
			return null;
		}
		$file = $index[ $type ];
		if ( ! array_key_exists( $file, $memo ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- A module.json bundled with the theme or an active plugin, read-only, the same file Saddle_Divi_Schema reads.
			$json     = json_decode( (string) @file_get_contents( $file ), true );
			$advanced = isset( $json['attributes']['module']['settings']['advanced'] ) ? $json['attributes']['module']['settings']['advanced'] : null;

			$memo[ $file ] = is_array( $json ) ? ( is_array( $advanced ) && array_key_exists( 'loop', $advanced ) ) : null;
		}
		return $memo[ $file ];
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

		return new WP_Error(
			'saddle_loop_not_supported',
			sprintf(
				/* translators: 1: module type, 2: list of "address (type)" entries. */
				__( '%1$s does not offer a loop: its module.json has no loop setting, so Divi\'s builder could not show or turn off a loop on it. If it runs its own query, use its own settings (divi-get-module-schema). To repeat it, put the loop on a container around it that can loop: %2$s.', 'saddle' ),
				$type,
				$around ? implode( ', ', $around ) : __( 'none found on this page; add a group or column around it', 'saddle' )
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
