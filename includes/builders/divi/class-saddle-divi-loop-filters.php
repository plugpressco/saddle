<?php
/**
 * Divi 5 loop filters: which items a loop keeps.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The include, exclude and custom field filters of a loop, built from tool
 * input in the shapes Divi 5.10.1's loop reader takes (LoopUtils:
 * _build_post_inclusion_exclusion_query(), _build_taxonomy_query(),
 * build_meta_query()). Read from source, not render-tested.
 */
class Saddle_Divi_Loop_Filters {

	/**
	 * Custom field comparisons and types Divi's meta query accepts.
	 */
	const META_COMPARE = array( '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE' );
	const META_TYPES   = array( 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' );

	/**
	 * Post (or user) ids as Divi's {value, label} items.
	 *
	 * @param array $ids   Ids.
	 * @param bool  $users True on a user loop.
	 * @return array[]|WP_Error
	 */
	public static function id_items( array $ids, $users ) {
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
	public static function term_groups( array $map ) {
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
	 * Custom field conditions as Divi's meta query items. Each key goes
	 * through the same custom field rule as a dynamic content binding.
	 *
	 * @param array  $items     List of {key, value, compare, type}.
	 * @param string $meta_type post, term or user: what the loop lists.
	 * @return array[]|WP_Error
	 */
	public static function meta_items( array $items, $meta_type ) {
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
			$refused = self::refuse_key( $key, $meta_type );
			if ( $refused ) {
				return $refused;
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
	 * Refuse a meta_query key the binding rule refuses.
	 *
	 * @param string $key       Meta key.
	 * @param string $meta_type post, term or user.
	 * @return WP_Error|null
	 */
	private static function refuse_key( $key, $meta_type ) {
		$rule = Saddle_Divi_Dynamic::meta_key_rule( $key, $meta_type );
		if ( 'protected' === $rule ) {
			return new WP_Error(
				'saddle_protected_meta',
				sprintf(
					/* translators: %s: meta key. */
					__( 'meta_query key "%s" is a protected custom field (its name starts with "_"), so SaddleWP does not filter a loop by it. A site owner can allow a specific key with the "saddle_dynamic_meta_allowlist" filter.', 'saddle' ),
					$key
				),
				array( 'status' => 400 )
			);
		}
		if ( 'user' === $rule ) {
			return new WP_Error(
				'saddle_protected_meta',
				__( 'A meta_query on a user loop needs an administrator account, and never takes sign-in or role data.', 'saddle' ),
				array( 'status' => 400 )
			);
		}
		return null;
	}
}
