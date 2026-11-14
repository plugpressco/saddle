<?php
/**
 * The optional content get-section-recipe takes.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans get-section-recipe's optional content (heading, text, buttons,
 * items, plans, quotes, questions) into one shape, for any builder's
 * recipes. The Gutenberg recipes ignore it; Divi 5's fill it in.
 */
class Saddle_Recipe_Content {

	/**
	 * The most entries each content list keeps.
	 */
	const LIMITS = array(
		'buttons'   => 2,
		'items'     => 12,
		'plans'     => 4,
		'quotes'    => 6,
		'questions' => 20,
	);

	/**
	 * Optional recipe content from get-section-recipe's input, cleaned into
	 * one shape. Strings are kept word for word (tags stripped from short
	 * labels; simple markup kept in body text). Anything missing or empty is
	 * left out, so the recipe keeps its placeholder there.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function clean( array $input ) {
		$out = array();
		foreach ( array( 'heading', 'text' ) as $key ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' !== $value ) {
				$out[ $key ] = 'text' === $key ? wp_kses_post( $value ) : sanitize_text_field( $value );
			}
		}

		$shapes = array(
			'buttons'   => array(
				'text' => 'label',
				'url'  => 'url',
			),
			'items'     => array(
				'title' => 'label',
				'text'  => 'html',
				'icon'  => 'icon',
			),
			'plans'     => array(
				'name'     => 'label',
				'price'    => 'label',
				'currency' => 'label',
				'period'   => 'label',
				'features' => 'labels',
				'button'   => 'button',
				'featured' => 'bool',
			),
			'quotes'    => array(
				'text'    => 'html',
				'name'    => 'label',
				'role'    => 'label',
				'company' => 'label',
			),
			'questions' => array(
				'question' => 'label',
				'answer'   => 'html',
			),
		);
		foreach ( $shapes as $key => $fields ) {
			if ( empty( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
				continue;
			}
			$list = array();
			foreach ( array_values( $input[ $key ] ) as $entry ) {
				$clean = is_array( $entry ) ? self::clean_entry( $entry, $fields ) : array();
				if ( $clean ) {
					$list[] = $clean;
				}
				if ( count( $list ) >= self::LIMITS[ $key ] ) {
					break;
				}
			}
			if ( $list ) {
				$out[ $key ] = $list;
			}
		}
		return $out;
	}

	/**
	 * One list entry with only its known fields, each cleaned by kind.
	 *
	 * @param array    $entry  Entry as written.
	 * @param string[] $fields Field => kind (label, html, url, labels, button, icon, bool).
	 * @return array Empty when no field has a value.
	 */
	private static function clean_entry( array $entry, array $fields ) {
		$out = array();
		foreach ( $fields as $field => $kind ) {
			if ( ! isset( $entry[ $field ] ) ) {
				continue;
			}
			$value = $entry[ $field ];
			switch ( $kind ) {
				case 'bool':
					$value = (bool) $value;
					break;
				case 'labels':
					$value = is_array( $value ) ? array_values( array_filter( array_map( 'sanitize_text_field', array_filter( $value, 'is_scalar' ) ), 'strlen' ) ) : array();
					break;
				case 'button':
					$value = is_array( $value ) ? self::clean_entry(
						$value,
						array(
							'text' => 'label',
							'url'  => 'url',
						)
					) : array();
					break;
				case 'icon':
					$value = is_array( $value )
						? array_map( 'sanitize_text_field', array_intersect_key( array_filter( $value, 'is_scalar' ), array_flip( array( 'unicode', 'type', 'weight' ) ) ) )
						: ( is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '' );
					break;
				case 'url':
					$value = is_scalar( $value ) ? esc_url_raw( trim( (string) $value ) ) : '';
					break;
				case 'html':
					$value = is_scalar( $value ) ? wp_kses_post( trim( (string) $value ) ) : '';
					break;
				default:
					$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
			if ( 'bool' === $kind || ( '' !== $value && array() !== $value ) ) {
				$out[ $field ] = $value;
			}
		}
		return array_diff_key( $out, array( 'featured' => true ) ) ? $out : array();
	}
}
