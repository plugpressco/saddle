<?php
/**
 * Divi 5 writes: the rules a saved page keeps, however a value was written.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One pass over the tree Saddle_Divi_Driver::persist() is about to save.
 * Every loop, dynamic content value and interaction entry that is new or
 * changed compared with the stored page must pass the rules the loop tools,
 * divi-apply-dynamic-content and divi-add-interaction apply, whichever way
 * it was written: raw attrs, fields or settings, a whole page, or another
 * plugin that saves through the same path. A value the stored page already
 * holds is not checked again, so an account can edit something else on a
 * page whose loop or binding was set up in the Visual Builder or by another
 * account.
 *
 * The checks are the ones those tools use (Saddle_Divi_Loop,
 * Saddle_Divi_Loop_Filters, Saddle_Divi_Dynamic, Saddle_Divi_Interactions).
 * A page with no loop, no binding and no interaction is passed after a few
 * string searches.
 */
class Saddle_Divi_Write_Checks {

	/**
	 * How Divi 5.10.1 finds a dynamic value in a saved string
	 * (DynamicData::get_variable_values()).
	 */
	const TOKEN = '/\$variable\((.+?)\)\$/';

	/**
	 * Divi's reader for one token's JSON.
	 */
	const DYNAMIC_DATA = 'ET\\Builder\\Packages\\Module\\Layout\\Components\\DynamicData\\DynamicData';

	/**
	 * Check a tree before it is saved.
	 *
	 * @param string $stored_content The page content as stored now.
	 * @param array  $tree           The tree about to be saved.
	 * @param string $content        That tree serialized.
	 * @return true|WP_Error The refusal names the node address and the rule.
	 */
	public static function check( $stored_content, array $tree, $content ) {
		if ( ! self::may_hold( (string) $content ) ) {
			return true;
		}
		$found = self::collect( $tree );
		if ( ! $found['loops'] && ! $found['tokens'] && ! $found['interactions'] ) {
			return true;
		}

		$known = array(
			'loops'        => array(),
			'tokens'       => array(),
			'interactions' => array(),
		);
		if ( self::may_hold( (string) $stored_content ) ) {
			// The stored page as every write saves it, so a loop the write
			// only repaired does not count as changed.
			list( $stored ) = Saddle_Divi_Loop::repair_tree( Saddle_Divi_Tree::parse( (string) $stored_content ) );
			$known          = self::collect( $stored );
		}

		$checks = array(
			'loops'        => 'loop_error',
			'tokens'       => 'token_error',
			'interactions' => 'interaction_error',
		);
		foreach ( $checks as $kind => $method ) {
			foreach ( $found[ $kind ] as $key => $entry ) {
				if ( isset( $known[ $kind ][ $key ] ) ) {
					continue;
				}
				$error = self::$method( $entry[1] );
				if ( $error ) {
					return self::at( $error, $entry[0] );
				}
			}
		}
		return true;
	}

	/**
	 * Whether serialized content can hold a loop, a binding or an
	 * interaction at all.
	 *
	 * @param string $content Serialized blocks.
	 * @return bool
	 */
	private static function may_hold( $content ) {
		return false !== strpos( $content, '"loop":' )
			|| false !== strpos( $content, '$variable(' )
			|| false !== strpos( $content, '"interactions":' );
	}

	/**
	 * Every loop, binding and interaction entry in a tree, keyed by its
	 * value, with the address of the first node that holds it.
	 *
	 * @param array[] $tree Parsed blocks.
	 * @return array { loops, tokens, interactions }: key => array( address, value ).
	 */
	private static function collect( array $tree ) {
		$found = array(
			'loops'        => array(),
			'tokens'       => array(),
			'interactions' => array(),
		);
		self::walk( $tree, '', $found );
		return $found;
	}

	/**
	 * Depth-first walk for collect().
	 *
	 * @param array[] $blocks Sibling blocks.
	 * @param string  $prefix Parent address.
	 * @param array   $found  Accumulator.
	 */
	private static function walk( array $blocks, $prefix, array &$found ) {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$address = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( $attrs ) {
				$loop = Saddle_Divi_Loop::value( array( 'attrs' => $attrs ) );
				if ( $loop ) {
					self::add( $found['loops'], self::key( $loop ), $address, $loop );
				}
				$groups = isset( $attrs['module']['decoration']['interactions'] ) && is_array( $attrs['module']['decoration']['interactions'] ) ? $attrs['module']['decoration']['interactions'] : array();
				foreach ( $groups as $states ) {
					foreach ( is_array( $states ) ? $states : array() as $state ) {
						$entries = is_array( $state ) && isset( $state['interactions'] ) && is_array( $state['interactions'] ) ? $state['interactions'] : array();
						foreach ( $entries as $entry ) {
							if ( is_array( $entry ) ) {
								self::add( $found['interactions'], self::key( $entry ), $address, $entry );
							}
						}
					}
				}
				self::tokens( $attrs, $address, $found['tokens'] );
			}
			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				self::tokens( array( $block['innerHTML'] ), $address, $found['tokens'] );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $address, $found );
			}
		}
	}

	/**
	 * Collect the dynamic values in every string of a value.
	 *
	 * @param array  $values  Values.
	 * @param string $address Node address.
	 * @param array  $tokens  Accumulator: whole token => array( address, inner JSON ).
	 */
	private static function tokens( array $values, $address, array &$tokens ) {
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				self::tokens( $value, $address, $tokens );
			} elseif ( is_string( $value ) && false !== strpos( $value, '$variable(' ) && preg_match_all( self::TOKEN, $value, $m ) ) {
				foreach ( $m[0] as $n => $whole ) {
					self::add( $tokens, $whole, $address, $m[1][ $n ] );
				}
			}
		}
	}

	/**
	 * Record a value once, at the first address that holds it.
	 *
	 * @param array  $bucket  Accumulator.
	 * @param string $key     Value key.
	 * @param string $address Node address.
	 * @param mixed  $value   The value.
	 */
	private static function add( array &$bucket, $key, $address, $value ) {
		if ( '' !== $key && ! isset( $bucket[ $key ] ) ) {
			$bucket[ $key ] = array( $address, $value );
		}
	}

	/**
	 * A key for a stored object that does not depend on key order.
	 *
	 * @param array $value Value.
	 * @return string
	 */
	private static function key( array $value ) {
		$encoded = wp_json_encode( self::sorted( $value ) );
		return false === $encoded ? '' : $encoded;
	}

	/**
	 * A value with the keys of every object sorted.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( __CLASS__, 'sorted' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}

	/**
	 * The loop tools' rules for a loop that is on: who may list users, the
	 * sub types a loop may name and that Divi reads, the custom fields its
	 * conditions may use, the posts or users it may include.
	 *
	 * @param array $value Loop value.
	 * @return WP_Error|null
	 */
	private static function loop_error( array $value ) {
		if ( ! isset( $value['enable'] ) || 'on' !== $value['enable'] ) {
			return null;
		}
		$type = isset( $value['queryType'] ) && is_string( $value['queryType'] ) ? $value['queryType'] : 'post_types';
		$base = Saddle_Divi_Loop::base_type( isset( Saddle_Divi_Loop_Repair::ALIASES[ $type ] ) ? Saddle_Divi_Loop_Repair::ALIASES[ $type ] : $type );

		$refused = Saddle_Divi_Loop::type_access_error( $base );
		if ( $refused ) {
			return $refused;
		}

		if ( in_array( $base, array( 'post_types', 'post_taxonomies', 'user_roles', 'menus' ), true ) ) {
			$names = self::values( isset( $value['subTypes'] ) ? $value['subTypes'] : array() );
			if ( $names ) {
				$items = Saddle_Divi_Loop::validate_sub_types( $base, $names );
				if ( is_wp_error( $items ) ) {
					return $items;
				}
			} elseif ( isset( Saddle_Divi_Loop_Repair::DEFAULT_SUB_TYPES[ $base ] ) ) {
				return new WP_Error(
					'saddle_loop_needs_sub_type',
					__( 'This loop names no post type or taxonomy that Divi can read, so it would list every one of them. Name them in subTypes as {"value", "label"} items, or turn the loop on with divi-enable-loop.', 'saddle' )
				);
			}
		}

		$meta_type = Saddle_Divi_Loop::meta_type( $base );
		$meta      = isset( $value['metaQuery'] ) && is_array( $value['metaQuery'] ) ? $value['metaQuery'] : array();
		foreach ( $meta as $item ) {
			// Divi reads the key field first, then metaKey, in LoopUtils'
			// meta query builder: both are checked.
			foreach ( array( 'key', 'metaKey' ) as $field ) {
				if ( is_array( $item ) && isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) && '' !== (string) $item[ $field ] ) {
					$refused = Saddle_Divi_Loop_Filters::refuse_key( sanitize_text_field( (string) $item[ $field ] ), $meta_type );
					if ( $refused ) {
						return $refused;
					}
				}
			}
		}

		if ( in_array( $base, array( 'post_types', 'current_page', 'user_roles' ), true ) ) {
			foreach ( array( 'includeSpecificPosts', 'excludeSpecificPosts' ) as $key ) {
				$ids = self::values( isset( $value[ $key ] ) ? $value[ $key ] : array() );
				if ( $ids ) {
					$items = Saddle_Divi_Loop_Filters::id_items( $ids, 'user_roles' === $base );
					if ( is_wp_error( $items ) ) {
						return $items;
					}
				}
			}
		}
		return null;
	}

	/**
	 * The names in a stored list of {value, label} items, plain-text
	 * entries included.
	 *
	 * @param mixed $raw Stored list (or a comma-separated string).
	 * @return string[]
	 */
	private static function values( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		$names = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['value'] ) && is_scalar( $entry['value'] ) ) {
				$entry = $entry['value'];
			}
			if ( is_scalar( $entry ) && '' !== trim( (string) $entry ) ) {
				$names[] = trim( (string) $entry );
			}
		}
		return $names;
	}

	/**
	 * divi-apply-dynamic-content's rules for one stored binding: the source,
	 * every form of custom field name, and raw HTML.
	 *
	 * @param string $inner The JSON between "$variable(" and ")$".
	 * @return WP_Error|null
	 */
	private static function token_error( $inner ) {
		$data = self::token_data( (string) $inner );
		if ( ! isset( $data['type'], $data['value']['name'] ) || 'content' !== $data['type'] || ! is_string( $data['value']['name'] ) ) {
			return null; // A color or variable reference, or nothing Divi resolves.
		}
		$source = Saddle_Divi_Dynamic::source_name( $data['value']['name'] );
		if ( is_wp_error( $source ) ) {
			$source = $data['value']['name'];
		}
		// Divi keeps only string settings.
		$settings = array();
		foreach ( isset( $data['value']['settings'] ) && is_array( $data['value']['settings'] ) ? $data['value']['settings'] : array() as $name => $setting ) {
			if ( is_string( $name ) && is_string( $setting ) ) {
				$settings[ $name ] = $setting;
			}
		}

		$prepared = Saddle_Divi_Dynamic::prepare_settings( $source, $settings );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( isset( $settings['enable_html'] ) && 'on' === $settings['enable_html'] && ! Saddle_Divi_Dynamic::may_show_raw_html() ) {
			return new WP_Error(
				'saddle_raw_html',
				__( 'This dynamic content shows its value as raw HTML (enable_html "on"), which needs an account that may post unfiltered HTML. Leave enable_html out, or set it to "off".', 'saddle' ),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * One token's JSON as Divi reads it, or array() when it reads nothing.
	 *
	 * @param string $inner The JSON between "$variable(" and ")$".
	 * @return array
	 */
	private static function token_data( $inner ) {
		if ( is_callable( array( self::DYNAMIC_DATA, 'get_data_value' ) ) ) {
			try {
				$data = call_user_func( array( self::DYNAMIC_DATA, 'get_data_value' ), $inner );
				return is_array( $data ) ? $data : array();
			} catch ( Throwable $e ) {
				return array();
			}
		}
		// Without Divi loaded: the forms its reader accepts (plain JSON,
		// unicode-escaped or entity-encoded quotes, slashed).
		foreach ( array( $inner, str_replace( array( '\\u0022', '&quot;' ), '"', $inner ) ) as $candidate ) {
			foreach ( array( $candidate, wp_unslash( $candidate ) ) as $json ) {
				$data = json_decode( $json, true );
				if ( is_array( $data ) ) {
					return $data;
				}
			}
		}
		return array();
	}

	/**
	 * divi-add-interaction's rule for the attribute an entry changes.
	 *
	 * @param array $entry Interaction entry.
	 * @return WP_Error|null
	 */
	private static function interaction_error( array $entry ) {
		if ( ! isset( $entry['attributeName'] ) || '' === $entry['attributeName'] ) {
			return null;
		}
		return Saddle_Divi_Interactions::attribute_name_error( $entry['attributeName'] );
	}

	/**
	 * A refusal that names the node.
	 *
	 * @param WP_Error $error   The rule's refusal.
	 * @param string   $address Node address.
	 * @return WP_Error
	 */
	private static function at( WP_Error $error, $address ) {
		$data = $error->get_error_data();
		return new WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: 1: node address, 2: the rule that refused the value. */
				__( 'Node %1$s: %2$s Nothing was saved.', 'saddle' ),
				$address,
				$error->get_error_message()
			),
			array_merge( is_array( $data ) ? $data : array(), array( 'address' => $address ) )
		);
	}
}
