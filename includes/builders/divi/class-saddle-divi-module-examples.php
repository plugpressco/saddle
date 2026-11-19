<?php
/**
 * How this site already uses a Divi module: configured examples from its
 * own published pages.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backs saddle/divi-find-module-examples. A module's schema says what can
 * be set; a page the owner designed says what this site sets. Each example
 * is the stored node, ready for divi-set-page or divi-add-module, with the
 * parts that belong to its own page taken out (interactions, the HTML id).
 *
 * Search: one WP_Query over published posts of the Divi post types whose
 * content holds the module's block comment, newest first. Each post is
 * checked for the calling account (read_post; edit_post for a type
 * visitors cannot view, such as the Library; a password is never
 * bypassed), then parsed. Examples are distinct by their settings and
 * styling (text ignored), the most configured first.
 */
class Saddle_Divi_Module_Examples {

	/**
	 * Posts read at most per call.
	 */
	const CANDIDATES = 20;

	/**
	 * Examples returned by default and at most.
	 */
	const LIMIT_DEFAULT = 3;
	const LIMIT_MAX     = 5;

	/**
	 * Largest example and whole answer, in bytes of JSON.
	 */
	const EXAMPLE_BYTES = 6144;
	const TOTAL_BYTES   = 20480;

	/**
	 * Children kept under a container example (slides, items).
	 */
	const CHILDREN_MAX = 6;

	/**
	 * Distinct uses collected at most, to bound the work on a large site.
	 */
	const FOUND_MAX = 40;

	/**
	 * The ability callback.
	 *
	 * @param array $input { type, limit?, exclude_post_id? }.
	 * @return array|WP_Error
	 */
	public static function find( $input ) {
		if ( ! Saddle_Divi::is_active() ) {
			return new WP_Error( 'saddle_no_divi', __( 'Divi 5 is not active on this site.', 'saddle' ) );
		}
		$type = isset( $input['type'] ) ? strtolower( trim( (string) $input['type'] ) ) : '';
		if ( ! preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#', $type ) ) {
			return new WP_Error( 'saddle_bad_type', __( 'Give the full module type with its namespace, such as "divi/blurb" or "divitorque/flip-box" (divi-list-modules lists them).', 'saddle' ) );
		}
		$limit   = isset( $input['limit'] ) ? max( 1, min( self::LIMIT_MAX, (int) $input['limit'] ) ) : self::LIMIT_DEFAULT;
		$exclude = isset( $input['exclude_post_id'] ) ? (int) $input['exclude_post_id'] : 0;

		$state = array(
			'found' => array(),
			'seen'  => array(),
			'uses'  => 0,
			'pages' => array(),
		);
		foreach ( self::candidates( $type, $exclude ) as $post ) {
			if ( ! self::readable( $post ) || 'divi5' !== Saddle_Divi::post_builder( $post ) ) {
				continue;
			}
			self::collect( Saddle_Divi_Tree::parse( $post->post_content ), '', $type, $post, null, $state );
		}

		$found = $state['found'];
		usort(
			$found,
			static function ( $a, $b ) {
				return $b['score'] === $a['score'] ? $a['order'] - $b['order'] : $b['score'] - $a['score'];
			}
		);

		return self::answer( $type, $found, $limit, $state['uses'], count( $state['pages'] ) );
	}

	/**
	 * The answer: the first $limit examples that fit the size caps.
	 *
	 * @param string  $type  Module type.
	 * @param array[] $found Distinct uses, best first.
	 * @param int     $limit Examples wanted.
	 * @param int     $uses  Every use seen, repeats included.
	 * @param int     $pages Posts the uses are on.
	 * @return array
	 */
	private static function answer( $type, array $found, $limit, $uses, $pages ) {
		$out = array(
			'type'     => $type,
			'uses'     => $uses,
			'pages'    => $pages,
			'examples' => array(),
		);
		if ( ! $found ) {
			$out['note'] = __( 'No published page on this site uses this module (only the newest posts that mention it are read). Build it from divi-get-module-schema: its settings, defaults and notes.', 'saddle' );
			return $out;
		}

		$total = 0;
		$cut   = false;
		foreach ( $found as $hit ) {
			if ( count( $out['examples'] ) >= $limit ) {
				break;
			}
			$example = self::fit( $hit );
			if ( null === $example ) {
				$cut = true;
				continue;
			}
			$size = strlen( (string) wp_json_encode( $example ) );
			if ( $total + $size > self::TOTAL_BYTES ) {
				$cut = true;
				break;
			}
			$total            += $size;
			$out['examples'][] = $example;
			if ( isset( $example['node']['children_total'] ) ) {
				$cut = true;
			}
		}

		$out['note'] = __( 'Each example is a node as this site stores it, from a page the owner published. Reuse its settings and styling: pass its "attrs" (and "children") to divi-set-page or divi-add-module and replace the text with the user\'s own. Presets, global colors and variables in it are this site\'s own, so they keep the page on brand. Interactions and HTML ids were left out, because they belong to the page they came from.', 'saddle' );
		if ( $cut ) {
			$out['cut'] = __( 'Some examples were too large to return whole: a container keeps its first children only, and an example still too large was skipped. Read the full node with divi-get-page post_id and address.', 'saddle' );
		}
		return $out;
	}

	/**
	 * One use as an answer entry, within EXAMPLE_BYTES; null when even its
	 * shortest form is too large.
	 *
	 * @param array $hit A collected use.
	 * @return array|null
	 */
	private static function fit( array $hit ) {
		$entry = array(
			'post_id' => $hit['post']->ID,
			'title'   => get_the_title( $hit['post'] ),
			'link'    => (string) get_permalink( $hit['post'] ),
			'address' => $hit['address'],
		);
		if ( null !== $hit['columns'] ) {
			$entry['row_columns'] = $hit['columns'];
		}
		foreach ( array( self::CHILDREN_MAX, 2, 0 ) as $keep ) {
			$node = $hit['node'];
			if ( isset( $node['children'] ) && count( $node['children'] ) > $keep ) {
				$node['children_total'] = count( $node['children'] );
				$node['children']       = array_slice( $node['children'], 0, $keep );
			}
			$entry['node'] = $node;
			if ( strlen( (string) wp_json_encode( $entry ) ) <= self::EXAMPLE_BYTES ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Published posts that may hold the module, newest first.
	 *
	 * @param string $type    Module type.
	 * @param int    $exclude A post to leave out.
	 * @return WP_Post[]
	 */
	private static function candidates( $type, $exclude ) {
		$ids = self::candidate_ids( Saddle_Divi::post_types(), '<!-- wp:' . $type, self::CANDIDATES, $exclude );
		_prime_post_caches( $ids, false, false );
		return array_values( array_filter( array_map( 'get_post', $ids ) ) );
	}

	/**
	 * Ids of published posts of these types whose content holds $needle,
	 * newest first. A direct query, not a WP_Query search, so a search
	 * plugin or a snippet that keeps pages out of search cannot change what
	 * is found. Also used by Saddle_Divi_Site_Style.
	 *
	 * @param string[] $types   Post types.
	 * @param string   $needle  Text the content must hold.
	 * @param int      $limit   Ids at most.
	 * @param int      $exclude A post to leave out (0 for none).
	 * @return int[]
	 */
	public static function candidate_ids( array $types, $needle, $limit, $exclude = 0 ) {
		global $wpdb;
		$types = array_values( array_filter( array_map( 'strval', $types ) ) );
		if ( ! $types ) {
			return array();
		}
		$in  = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$sql = "SELECT ID FROM %i WHERE post_type IN ($in) AND post_status = %s AND post_content LIKE %s AND ID <> %d ORDER BY post_modified_gmt DESC LIMIT %d";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is only "%s" placeholders, one per type, filled by prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ids only; the callers cache what they build from them.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $wpdb->posts ), $types, array( 'publish', '%' . $wpdb->esc_like( $needle ) . '%', (int) $exclude, (int) $limit ) ) ) );
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether the calling account may see this post's layout. Also used by
	 * Saddle_Divi_Site_Style.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function readable( WP_Post $post ) {
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}
		return '' === (string) $post->post_password
			&& is_post_type_viewable( $post->post_type )
			&& current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Walk a tree and collect each distinct use of the module.
	 *
	 * @param array[]    $blocks Sibling blocks.
	 * @param string     $prefix Parent address.
	 * @param string     $type   Module type.
	 * @param WP_Post    $post   The post.
	 * @param array|null $row    Nearest row above.
	 * @param array      $state  Collected uses (by reference).
	 */
	private static function collect( array $blocks, $prefix, $type, WP_Post $post, $row, array &$state ) {
		foreach ( $blocks as $i => $block ) {
			$address = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$name    = (string) $block['blockName'];
			if ( $name === $type ) {
				++$state['uses'];
				$state['pages'][ $post->ID ] = true;
				$node                        = self::node( $block );
				$key                         = md5( (string) wp_json_encode( self::without_text( $node ) ) );
				if ( ! isset( $state['seen'][ $key ] ) && count( $state['found'] ) < self::FOUND_MAX ) {
					$state['seen'][ $key ] = true;
					$state['found'][]      = array(
						'score'   => self::score( $node ),
						'order'   => count( $state['found'] ),
						'post'    => $post,
						'address' => $address,
						'columns' => self::columns( $row ),
						'node'    => $node,
					);
				}
				continue;
			}
			$next = in_array( $name, array( 'divi/row', 'divi/row-inner' ), true ) ? $block : $row;
			self::collect( $block['innerBlocks'], $address, $type, $post, $next, $state );
		}
	}

	/**
	 * A stored block as an authoring node, without the parts that belong
	 * to its own page.
	 *
	 * @param array $block Parsed block.
	 * @return array {type, attrs?, children?}
	 */
	private static function node( array $block ) {
		$attrs = is_array( $block['attrs'] ) ? $block['attrs'] : array();
		unset( $attrs['builderVersion'] );
		if ( isset( $attrs['module']['decoration'] ) && is_array( $attrs['module']['decoration'] ) ) {
			unset( $attrs['module']['decoration']['interactions'], $attrs['module']['decoration']['interactionTrigger'], $attrs['module']['decoration']['interactionTarget'] );
		}
		if ( isset( $attrs['module']['advanced']['htmlAttributes'] ) && is_array( $attrs['module']['advanced']['htmlAttributes'] ) ) {
			foreach ( $attrs['module']['advanced']['htmlAttributes'] as $breakpoint => $states ) {
				foreach ( is_array( $states ) ? $states : array() as $state => $value ) {
					unset( $attrs['module']['advanced']['htmlAttributes'][ $breakpoint ][ $state ]['id'] );
				}
			}
		}

		$node = array( 'type' => (string) $block['blockName'] );
		if ( $attrs ) {
			$node['attrs'] = $attrs;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$node['children'] = array_map( array( __CLASS__, 'node' ), array_values( $block['innerBlocks'] ) );
		}
		return $node;
	}

	/**
	 * A node with every innerContent removed, for telling uses apart by
	 * their settings and styling only.
	 *
	 * @param array $value Node or part of one.
	 * @return array
	 */
	private static function without_text( array $value ) {
		unset( $value['innerContent'] );
		foreach ( $value as $key => $child ) {
			if ( is_array( $child ) ) {
				$value[ $key ] = self::without_text( $child );
			}
		}
		return $value;
	}

	/**
	 * How configured a node is: the values it sets outside its text.
	 *
	 * @param array $node Node.
	 * @return int
	 */
	private static function score( array $node ) {
		$count    = 0;
		$settings = self::without_text( $node );
		array_walk_recursive(
			$settings,
			static function () use ( &$count ) {
				++$count;
			}
		);
		return $count;
	}

	/**
	 * The column layout of the row above a use, as Divi stores it.
	 *
	 * @param array|null $row Row block.
	 * @return string|null
	 */
	private static function columns( $row ) {
		if ( ! is_array( $row ) || ! is_array( $row['attrs'] ) ) {
			return null;
		}
		foreach ( array( 'flexColumnStructure', 'columnStructure' ) as $key ) {
			if ( isset( $row['attrs']['module']['advanced'][ $key ]['desktop']['value'] ) && is_string( $row['attrs']['module']['advanced'][ $key ]['desktop']['value'] ) ) {
				return $row['attrs']['module']['advanced'][ $key ]['desktop']['value'];
			}
		}
		return null;
	}
}
