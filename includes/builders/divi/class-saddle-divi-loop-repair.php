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
 * The render-time repair and the repair every SaddleWP write applies
 * (repair()) change only the two older shapes that keep what the author
 * asked for: plain-text sub types become items, "asc" and "desc" become
 * "ascending" and "descending". Older type names and a missing sub type are
 * left as stored; divi-edit-loop rewrites them, behind its own checks
 * (normalize()).
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
		$value    = $attrs['module']['advanced']['loop']['desktop']['value'];
		$repaired = self::repair( $value );
		if ( $repaired === $value || ! is_callable( array( self::LOOP_UTILS, 'get_query_args_from_attrs' ) ) ) {
			return $loop_data;
		}

		try {
			$read = call_user_func( array( self::LOOP_UTILS, 'get_query_args_from_attrs' ), $attrs );
			if ( ! is_array( $read ) || self::part( $read, 'query_args' ) !== self::part( $loop_data, 'query_args' ) || self::part( $read, 'query_type' ) !== self::part( $loop_data, 'query_type' ) ) {
				return $loop_data;
			}
			$attrs['module']['advanced']['loop']['desktop']['value'] = $repaired;

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
		if ( isset( self::DEFAULT_SUB_TYPES[ $type ] ) && ! self::readable_items( $raw ) ) {
			$found[] = 'no_sub_types';
		}

		$order = isset( $value['order'] ) && is_string( $value['order'] ) ? strtolower( trim( $value['order'] ) ) : '';
		if ( in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$found[] = 'order';
		}
		return $found;
	}

	/**
	 * Rewrite only the two older shapes that keep what the author asked for:
	 * a plain-text sub type becomes a {value, label} item (labelled as
	 * divi-enable-loop labels it), and "asc" or "desc" becomes "ascending"
	 * or "descending". A plain-text sub type that a loop may not name here
	 * (Saddle_Divi_Loop::sub_type_item()) stays as stored, which Divi skips
	 * as before. Older type names and a missing sub type are left alone.
	 * Used at render and on every SaddleWP write.
	 *
	 * @param array $value Stored loop value.
	 * @return array The value, unchanged when nothing applies.
	 */
	public static function repair( array $value ) {
		$type = isset( $value['queryType'] ) && is_string( $value['queryType'] ) ? $value['queryType'] : 'post_types';
		$base = isset( self::ALIASES[ $type ] ) ? self::ALIASES[ $type ] : Saddle_Divi_Loop::base_type( $type );

		$raw = isset( $value['subTypes'] ) ? $value['subTypes'] : null;
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$raw = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
		}
		if ( self::has_plain_entry( $raw ) ) {
			$items = array();
			foreach ( $raw as $entry ) {
				$item    = is_scalar( $entry ) && '' !== trim( (string) $entry ) ? Saddle_Divi_Loop::sub_type_item( $base, $entry ) : null;
				$items[] = null === $item ? $entry : $item;
			}
			// Kept as stored when no entry could be converted.
			if ( self::readable_items( $items ) > self::readable_items( $value['subTypes'] ) ) {
				$value['subTypes'] = $items;
			}
		}

		$order = isset( $value['order'] ) && is_string( $value['order'] ) ? strtolower( trim( $value['order'] ) ) : '';
		if ( in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$value['order'] = self::order( $order );
		}
		return $value;
	}

	/**
	 * Whether a stored sub type list holds a plain-text entry.
	 *
	 * @param mixed $raw Stored subTypes.
	 * @return bool
	 */
	private static function has_plain_entry( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		foreach ( $raw as $entry ) {
			if ( is_scalar( $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rewrite a stored loop into the shape divi-enable-loop writes: the two
	 * shapes repair() rewrites, plus the current type names and the default
	 * sub type on a post or taxonomy loop that names none. Only the loop
	 * tools use this, after their own checks (Saddle_Divi_Loop::build()).
	 *
	 * @param array $value Stored loop value.
	 * @return array
	 */
	public static function normalize( array $value ) {
		$value = self::repair( $value );
		$type  = isset( $value['queryType'] ) && is_string( $value['queryType'] ) ? $value['queryType'] : 'post_types';
		if ( isset( self::ALIASES[ $type ] ) ) {
			$type               = self::ALIASES[ $type ];
			$value['queryType'] = $type;
		}
		if ( isset( self::DEFAULT_SUB_TYPES[ $type ] ) && ! self::readable_items( isset( $value['subTypes'] ) ? $value['subTypes'] : null ) && ! self::has_plain_entry( isset( $value['subTypes'] ) ? $value['subTypes'] : null ) ) {
			$value['subTypes'] = array( self::default_item( $type ) );
		}
		return $value;
	}

	/**
	 * How many sub type entries Divi reads: {value, label} items whose value
	 * is a string.
	 *
	 * @param mixed $raw Stored subTypes.
	 * @return int
	 */
	public static function readable_items( $raw ) {
		$count = 0;
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['value'] ) && is_string( $entry['value'] ) && '' !== $entry['value'] ) {
				++$count;
			}
		}
		return $count;
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
			if ( $value ) {
				$repaired = self::repair( $value );
				if ( $repaired !== $value ) {
					$tree[ $i ]['attrs']['module']['advanced']['loop']['desktop']['value'] = $repaired;
					++$count;
				}
			}
			if ( ! empty( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) {
				$inner                     = self::repair_tree( $node['innerBlocks'] );
				$tree[ $i ]['innerBlocks'] = $inner[0];
				$count                    += $inner[1];
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
	 * What a visitor gets from a loop saved in an older shape, one whole
	 * sentence per effect, for a verify-page finding. Shapes Divi already
	 * reads as meant ("asc", "terms") give no sentence.
	 *
	 * @param array  $value    Stored loop value.
	 * @param string $location Module and address.
	 * @return string[]
	 */
	public static function explain( array $value, $location ) {
		$problems = self::problems( $value );
		$type     = isset( $value['queryType'] ) && is_string( $value['queryType'] ) ? $value['queryType'] : 'post_types';
		$base     = isset( self::ALIASES[ $type ] ) ? self::ALIASES[ $type ] : $type;
		$order    = isset( $value['order'] ) && is_string( $value['order'] ) ? strtolower( trim( $value['order'] ) ) : '';
		$desc     = in_array( 'order', $problems, true ) && 'desc' === $order;
		$by_date  = ! isset( $value['orderBy'] ) || '' === $value['orderBy'] || 'date' === $value['orderBy'];
		$lines    = array();

		if ( in_array( 'query_type', $problems, true ) && 'users' === $type ) {
			$lines[] = sprintf(
				/* translators: %s: module and address. */
				__( 'The loop on %s uses the older query type "users", so Divi lists the users but prints nothing for each of them.', 'saddle' ),
				$location
			);
		}

		if ( in_array( 'no_sub_types', $problems, true ) && 'post_types' === $base ) {
			if ( $desc && $by_date ) {
				$desc    = false;
				$lines[] = sprintf(
					/* translators: %s: module and address. */
					__( 'The loop on %s names no post type that Divi can read and stores its order as "desc", so it lists every post type, including pages and media, oldest first.', 'saddle' ),
					$location
				);
			} else {
				$lines[] = sprintf(
					/* translators: %s: module and address. */
					__( 'The loop on %s names no post type that Divi can read, so it lists every post type, including pages and media.', 'saddle' ),
					$location
				);
			}
		} elseif ( in_array( 'no_sub_types', $problems, true ) ) {
			$lines[] = sprintf(
				/* translators: %s: module and address. */
				__( 'The loop on %s names no taxonomy that Divi can read, so it lists the terms of every taxonomy.', 'saddle' ),
				$location
			);
		} elseif ( in_array( 'sub_types', $problems, true ) ) {
			$lines[] = sprintf(
				/* translators: %s: module and address. */
				__( 'The loop on %s lists some of its sub types as plain text, and Divi skips those.', 'saddle' ),
				$location
			);
		}

		if ( $desc ) {
			$lines[] = $by_date && 'post_taxonomies' !== $base
				? sprintf(
					/* translators: %s: module and address. */
					__( 'The loop on %s stores its order as "desc", so Divi lists it oldest first.', 'saddle' ),
					$location
				)
				: sprintf(
					/* translators: %s: module and address. */
					__( 'The loop on %s stores its order as "desc", which Divi reads as ascending, so it lists the items in the opposite order.', 'saddle' ),
					$location
				);
		}
		return $lines;
	}
}
