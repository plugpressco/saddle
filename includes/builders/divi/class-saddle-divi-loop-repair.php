<?php
/**
 * Divi 5 loops saved in an older shape: find them, rewrite them, and let
 * Divi read them the way their author meant.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one loop shape Divi reads, and the older shapes it reads differently.
 * Read from Divi 5.10.1's loop reader (LoopUtils::get_query_args_from_attrs()
 * and the helpers it calls), not render-tested:
 *
 * - subTypes: Divi keeps only {value, label} items whose value is a string.
 *   With no item left, a post loop has no post type and Divi queries every
 *   post type, private posts included; a taxonomy loop queries every
 *   taxonomy. The Visual Builder writes a default sub type whenever it turns
 *   a loop on (the post type being edited, or "category").
 * - order: only "descending" or an empty value sorts newest first, so "desc"
 *   sorts oldest first.
 * - "terms" and "users" are older names of "post_taxonomies" and
 *   "user_roles"; Divi's per-item reader prints no user values for "users".
 *
 * Divi runs a loop's query while it parses the page: its block parser calls
 * LoopUtils::parse_and_duplicate_loop_blocks() inside parse_blocks(), before
 * WordPress fires render_block_data for any block. So the render-time repair
 * hooks Divi's own loop filters, which fire just before the query runs.
 */
class Saddle_Divi_Loop_Repair {

	/**
	 * Older query type names and the names Divi's builder writes.
	 */
	const ALIASES = array(
		'terms' => 'post_taxonomies',
		'users' => 'user_roles',
	);

	/**
	 * The sub type a loop of each type gets when it names none.
	 */
	const DEFAULT_SUB_TYPES = array(
		'post_types'      => 'post',
		'post_taxonomies' => 'category',
	);

	/**
	 * Divi's loop reader.
	 */
	const LOOP_UTILS = 'ET\\Builder\\Packages\\Module\\Options\\Loop\\LoopUtils';

	/**
	 * Hook the render-time repair once the theme is known.
	 */
	public static function register() {
		add_action( 'after_setup_theme', array( __CLASS__, 'hook' ), 20 );
	}

	/**
	 * Add the loop filters while Divi 5 is the active theme.
	 */
	public static function hook() {
		if ( ! Saddle_Divi::is_active() ) {
			return;
		}
		// Before the query of a loop being rendered, and on the query a
		// Pagination module runs ahead of its loop (that path has only the
		// "after" filter).
		add_filter( 'divi_loop_data_before_execution', array( __CLASS__, 'repair_loop_data' ), 1, 2 );
		add_filter( 'divi_loop_data_after_execution', array( __CLASS__, 'repair_loop_data' ), 1, 2 );
	}

	/**
	 * Divi's loop data for a module, rebuilt from its repaired loop value
	 * when the stored one is in an older shape. Left alone when something
	 * already changed the data Divi built from the attrs.
	 *
	 * @param mixed $loop_data Divi's loop data (query_args, query_type ...).
	 * @param mixed $attrs     The module's block attrs.
	 * @return mixed
	 */
	public static function repair_loop_data( $loop_data, $attrs = array() ) {
		if ( ! is_array( $loop_data ) || ! is_array( $attrs ) || ! isset( $attrs['module']['advanced']['loop']['desktop']['value'] ) || ! is_array( $attrs['module']['advanced']['loop']['desktop']['value'] ) ) {
			return $loop_data;
		}
		$value = $attrs['module']['advanced']['loop']['desktop']['value'];
		if ( ! self::problems( $value ) || ! is_callable( array( self::LOOP_UTILS, 'get_query_args_from_attrs' ) ) ) {
			return $loop_data;
		}

		try {
			$read = call_user_func( array( self::LOOP_UTILS, 'get_query_args_from_attrs' ), $attrs );
			if ( ! is_array( $read ) || self::part( $read, 'query_args' ) !== self::part( $loop_data, 'query_args' ) || self::part( $read, 'query_type' ) !== self::part( $loop_data, 'query_type' ) ) {
				return $loop_data;
			}
			$attrs['module']['advanced']['loop']['desktop']['value'] = self::normalize( $value );

			$fixed = call_user_func( array( self::LOOP_UTILS, 'get_query_args_from_attrs' ), $attrs );
		} catch ( Throwable $e ) {
			return $loop_data;
		}
		return is_array( $fixed ) ? array_merge( $loop_data, $fixed ) : $loop_data;
	}

	/**
	 * One key of an array, or null.
	 *
	 * @param array  $data Array.
	 * @param string $key  Key.
	 * @return mixed
	 */
	private static function part( array $data, $key ) {
		return isset( $data[ $key ] ) ? $data[ $key ] : null;
	}

	/**
	 * What in a stored loop value Divi reads differently from what was
	 * meant: query_type (an older name), sub_types (items Divi skips),
	 * no_sub_types (a post or taxonomy loop naming none), order ("asc" or
	 * "desc").
	 *
	 * @param array $value Stored loop value.
	 * @return string[]
	 */
	public static function problems( array $value ) {
		$found = array();
		$type  = isset( $value['queryType'] ) && is_string( $value['queryType'] ) ? $value['queryType'] : 'post_types';
		if ( isset( self::ALIASES[ $type ] ) ) {
			$found[] = 'query_type';
			$type    = self::ALIASES[ $type ];
		}

		$raw = isset( $value['subTypes'] ) ? $value['subTypes'] : null;
		if ( is_array( $raw ) ) {
			foreach ( $raw as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['value'] ) || ! is_string( $entry['value'] ) ) {
					$found[] = 'sub_types';
					break;
				}
			}
		}
		if ( isset( self::DEFAULT_SUB_TYPES[ $type ] ) && ! self::sub_type_items( $raw ) ) {
			$found[] = 'no_sub_types';
		}

		$order = isset( $value['order'] ) && is_string( $value['order'] ) ? strtolower( trim( $value['order'] ) ) : '';
		if ( in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$found[] = 'order';
		}
		return $found;
	}

	/**
	 * Rewrite a stored loop into the shape Divi reads, whatever form an older
	 * write left: older type names, string sub types, no sub type on a post
	 * or taxonomy loop, "asc" or "desc".
	 *
	 * @param array $value Stored loop value.
	 * @return array
	 */
	public static function normalize( array $value ) {
		$type = isset( $value['queryType'] ) && is_string( $value['queryType'] ) ? $value['queryType'] : 'post_types';
		if ( isset( self::ALIASES[ $type ] ) ) {
			$type               = self::ALIASES[ $type ];
			$value['queryType'] = $type;
		}
		if ( array_key_exists( 'subTypes', $value ) ) {
			$value['subTypes'] = self::sub_type_items( $value['subTypes'] );
		}
		if ( isset( self::DEFAULT_SUB_TYPES[ $type ] ) && empty( $value['subTypes'] ) ) {
			$value['subTypes'] = array( self::default_item( $type ) );
		}
		if ( isset( $value['order'] ) && is_string( $value['order'] ) && '' !== trim( $value['order'] ) ) {
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
			if ( is_array( $entry ) && isset( $entry['value'] ) && is_scalar( $entry['value'] ) && '' !== (string) $entry['value'] ) {
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
	 * The default sub type item of a post or taxonomy loop.
	 *
	 * @param string $type post_types or post_taxonomies.
	 * @return array
	 */
	public static function default_item( $type ) {
		$value = self::DEFAULT_SUB_TYPES[ $type ];
		if ( 'post_types' === $type ) {
			$object = get_post_type_object( $value );
			$label  = $object ? $object->labels->name : $value;
		} else {
			$object = get_taxonomy( $value );
			$label  = $object ? $object->label : $value;
		}
		return array(
			'value' => $value,
			'label' => wp_strip_all_tags( (string) $label ),
		);
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
	 * A parsed block tree with every loop in an older shape rewritten, and
	 * how many loops changed. Touches nothing else and reads nothing from
	 * the database.
	 *
	 * @param array[] $tree Parsed blocks (blockName, attrs, innerBlocks).
	 * @return array array( tree, count ).
	 */
	public static function repair_tree( array $tree ) {
		$count = 0;
		foreach ( $tree as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$value = Saddle_Divi_Loop::value( $node );
			if ( $value && self::problems( $value ) ) {
				$tree[ $i ]['attrs']['module']['advanced']['loop']['desktop']['value'] = self::normalize( $value );
				++$count;
			}
			if ( ! empty( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) {
				list( $tree[ $i ]['innerBlocks'], $inner ) = self::repair_tree( $node['innerBlocks'] );
				$count += $inner;
			}
		}
		return array( $tree, $count );
	}

	/**
	 * A cheap test on raw post content: false when no loop in it can need
	 * repair, true when one may (repair_tree() then says which).
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function needs_repair( $content ) {
		$content = (string) $content;
		if ( false === strpos( $content, '"loop":' ) ) {
			return false;
		}
		if ( preg_match( '/"subTypes":(?:\[(?:\]|[^{\]])|"")|"order":"(?:asc|desc)"|"queryType":"(?:users|terms)"/i', $content ) ) {
			return true;
		}
		// A loop without subTypes: "loop" also names other settings (a video
		// that loops), so this may say yes when nothing needs repair.
		return substr_count( $content, '"subTypes":' ) < substr_count( $content, '"loop":' );
	}

	/**
	 * One whole sentence per problem, for a verify-page finding.
	 *
	 * @param array  $value    Stored loop value.
	 * @param string $location Module and address.
	 * @return string[]
	 */
	public static function explain( array $value, $location ) {
		$lines = array();
		foreach ( self::problems( $value ) as $problem ) {
			if ( 'query_type' === $problem ) {
				$lines[] = sprintf(
					/* translators: 1: module and address, 2: stored query type. */
					__( 'The loop on %1$s uses the older query type name "%2$s".', 'saddle' ),
					$location,
					(string) $value['queryType']
				);
			} elseif ( 'sub_types' === $problem ) {
				$lines[] = sprintf(
					/* translators: %s: module and address. */
					__( 'The loop on %s lists its sub types as plain text, and Divi skips those.', 'saddle' ),
					$location
				);
			} elseif ( 'no_sub_types' === $problem ) {
				$lines[] = sprintf(
					/* translators: %s: module and address. */
					__( 'The loop on %s names no post type or taxonomy Divi can read, so Divi queries every one of them.', 'saddle' ),
					$location
				);
			} else {
				$lines[] = sprintf(
					/* translators: 1: module and address, 2: stored order. */
					__( 'The loop on %1$s stores its order as "%2$s". Divi sorts newest first only for "descending".', 'saddle' ),
					$location,
					(string) $value['order']
				);
			}
		}
		return $lines;
	}
}
