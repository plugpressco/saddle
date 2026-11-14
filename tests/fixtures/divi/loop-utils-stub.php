<?php
/**
 * A stand-in for Divi 5's loop reader in tests: the part of
 * LoopUtils::get_query_args_from_attrs() that a loop's sub types and order
 * decide, as Divi 5.10.1 reads them. Divi keeps only {value, label} items
 * whose value is a string; with none left a post loop queries every post
 * type, private posts included; only "descending" or an empty order sorts
 * newest first.
 *
 * @package Saddle
 */

namespace ET\Builder\Packages\Module\Options\Loop;

class LoopUtils {

	/**
	 * How many times the reader ran.
	 *
	 * @var int
	 */
	public static $calls = 0;

	/**
	 * Loop data for a module's attrs.
	 *
	 * @param array $attrs Block attrs.
	 * @return array
	 */
	public static function get_query_args_from_attrs( $attrs ) {
		++self::$calls;
		$loop  = isset( $attrs['module']['advanced']['loop']['desktop']['value'] ) ? $attrs['module']['advanced']['loop']['desktop']['value'] : array();
		$types = array();
		foreach ( isset( $loop['subTypes'] ) && is_array( $loop['subTypes'] ) ? $loop['subTypes'] : array() as $item ) {
			if ( is_array( $item ) && isset( $item['value'] ) && is_string( $item['value'] ) ) {
				$types[] = $item['value'];
			}
		}
		$order = isset( $loop['order'] ) ? (string) $loop['order'] : '';
		return array(
			'loop_enabled' => isset( $loop['enable'] ) ? $loop['enable'] : '',
			'query_type'   => isset( $loop['queryType'] ) ? $loop['queryType'] : 'post_types',
			'post_type'    => $types,
			'query_args'   => array(
				'post_type'   => $types ? $types : array( 'any' ),
				'post_status' => $types ? 'publish' : array( 'publish', 'inherit', 'private' ),
				'order'       => ( '' === $order || 'descending' === $order ) ? 'DESC' : 'ASC',
			),
		);
	}
}
