<?php
/**
 * The dynamic content sources a Divi 5 field can bind to on this site.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists dynamic content sources: from Divi's own registry when it is loaded
 * (DynamicContentOptions::get_options(), which runs during REST requests),
 * and from a hand-kept list read from Divi 5.10.1's option classes otherwise.
 * Sources that resolve without a registered option (featured image alt text,
 * any_post_link_url) come from the hand-kept list in both cases.
 */
class Saddle_Divi_Dynamic_Sources {

	/**
	 * Divi's registry class.
	 */
	const REGISTRY = 'ET\\Builder\\Packages\\Module\\Layout\\Components\\DynamicContent\\DynamicContentOptions';

	/**
	 * Settings every loop source takes.
	 */
	const LOOP = array( 'before', 'after', 'loop_position' );

	/**
	 * Date settings.
	 */
	const DATE = array( 'date_format', 'custom_date_format' );

	/**
	 * Which loop query types each loop family prints.
	 */
	const LOOP_TYPES = array(
		'post'    => array( 'post_types', 'current_page' ),
		'product' => array( 'post_types (product)', 'current_page' ),
		'term'    => array( 'post_taxonomies' ),
		'user'    => array( 'user_roles' ),
		'menu'    => array( 'menus' ),
		'acf'     => array( 'acf_repeater' ),
	);

	/**
	 * The tool's answer.
	 *
	 * @return array
	 */
	public static function listing() {
		$from    = 'builtin';
		$entries = array();
		if ( class_exists( self::REGISTRY ) && is_callable( array( self::REGISTRY, 'get_options' ) ) ) {
			try {
				$entries = self::from_options( (array) call_user_func( array( self::REGISTRY, 'get_options' ), 0, 'edit' ) );
			} catch ( Throwable $e ) {
				$entries = array();
			}
			$from = $entries ? 'site' : 'builtin';
		}
		foreach ( self::builtin() as $name => $entry ) {
			if ( ! isset( $entries[ $name ] ) ) {
				$entries[ $name ] = $entry;
			}
		}

		$page = array();
		$loop = array();
		foreach ( $entries as $name => $entry ) {
			$family = Saddle_Divi_Dynamic::source_family( $name );
			$row    = array_merge( array( 'name' => $name ), $entry );
			if ( 0 === strpos( $name, 'loop_' ) ) {
				if ( ! isset( $loop[ $family ] ) ) {
					$loop[ $family ] = array(
						'loop_query_types' => isset( self::LOOP_TYPES[ $family ] ) ? self::LOOP_TYPES[ $family ] : array(),
						'sources'          => array(),
					);
				}
				$loop[ $family ]['sources'][] = $row;
			} else {
				$page[ $family ][] = $row;
			}
		}

		return array(
			'from'     => $from,
			'note'     => __( 'Inside a loop, use the loop_ sources: only they change per item. Page sources (post_title, post_meta_key and the rest) show the values of the page being viewed, or of the post a Theme Builder template shows, so inside a loop they repeat the same value on every item. A loop_ source outside a loop prints only its before and after text.', 'saddle' ),
			'page'     => $page,
			'loop'     => $loop,
			'settings' => array(
				'before'        => __( 'Text shown before the value.', 'saddle' ),
				'after'         => __( 'Text shown after the value.', 'saddle' ),
				'loop_position' => __( 'A number: show the value of that item of the loop (1 is the first) instead of the current one.', 'saddle' ),
				'date_format'   => __( 'default, "M j, Y", "F d, Y", "m/d/Y", "m.d.Y", "j M, Y", "l, M d", or custom with custom_date_format (a PHP date format).', 'saddle' ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The name of a dynamic content setting, not a query argument.
				'meta_key'      => __( 'post_meta_key: the custom field name to show (settings.key is accepted as the older name).', 'saddle' ),
				'loop_meta_key' => __( 'loop_post_meta_key_manual_custom_field (and the term and user versions): the custom field name to show for each item.', 'saddle' ),
				'values'        => __( 'Every setting is a string; true and false are stored as "on" and "off".', 'saddle' ),
			),
			'usage'    => __( 'Bind with divi-apply-dynamic-content: field = the attribute path from divi-get-module-schema ("title.innerContent", or one part of an object field such as "image.innerContent.src" or "button.innerContent.linkUrl"), source = a name above. An image source needs a source of type image or url. Custom fields whose names start with "_" are refused.', 'saddle' ),
		);
	}

	/**
	 * Map Divi's registered options to entries: type and setting names.
	 * Select option lists are left out (the custom field list names the
	 * site's meta keys); write a custom field's name in its settings.
	 *
	 * @param array $options DynamicContentOptions::get_options() result.
	 * @return array<string,array>
	 */
	public static function from_options( array $options ) {
		$entries = array();
		foreach ( $options as $key => $option ) {
			$name = (string) $key;
			if ( ! is_array( $option ) || '' === $name || 'loop_' === $name || 0 === strpos( $name, 'gvid-' ) || in_array( $name, array( 'acf_groups', 'users_groups', 'terms_groups' ), true ) ) {
				continue;
			}
			$entries[ $name ] = array(
				'label'    => isset( $option['label'] ) ? wp_strip_all_tags( (string) $option['label'] ) : $name,
				'type'     => isset( $option['type'] ) ? (string) $option['type'] : 'text',
				'settings' => isset( $option['fields'] ) && is_array( $option['fields'] ) ? array_map( 'strval', array_keys( $option['fields'] ) ) : array(),
			);
		}
		return $entries;
	}

	/**
	 * The hand-kept list: name => type and settings.
	 *
	 * @return array<string,array>
	 */
	private static function builtin() {
		$text  = array( 'before', 'after' );
		$terms = array( 'before', 'after', 'link_to_term_page', 'separator', 'category_type' );
		$field = array( 'before', 'after', 'meta_key', 'select_meta_key', 'date_format', 'custom_date_format', 'enable_html' );
		$loopf = array( 'before', 'after', 'loop_meta_key', 'select_loop_meta_key', 'date_format', 'custom_date_format', 'loop_position', 'enable_html' );

		$rows = array(
			'post_title'                             => array( 'text', $text ),
			'post_excerpt'                           => array( 'text', array( 'before', 'after', 'words', 'read_more_label' ) ),
			'post_date'                              => array( 'text', array_merge( $text, self::DATE ) ),
			'post_modified_date'                     => array( 'text', array_merge( $text, self::DATE ) ),
			'post_comment_count'                     => array( 'text', array( 'before', 'after', 'link_to_comments_page' ) ),
			'post_categories'                        => array( 'text', $terms ),
			'post_tags'                              => array( 'text', $terms ),
			'post_link'                              => array( 'text', array( 'before', 'after', 'text', 'custom_text' ) ),
			'post_link_url'                          => array( 'url', array() ),
			'any_post_link_url'                      => array( 'url', array( 'post_id' ) ),
			'post_featured_image'                    => array( 'image', array() ),
			'post_featured_image_alt_text'           => array( 'text', array() ),
			'post_featured_image_title_text'         => array( 'text', array() ),
			'post_author'                            => array( 'text', array( 'before', 'after', 'name_format', 'link', 'link_destination' ) ),
			'post_author_bio'                        => array( 'text', $text ),
			'post_author_url'                        => array( 'url', array() ),
			'post_author_profile_picture'            => array( 'image', array() ),
			'site_title'                             => array( 'text', $text ),
			'site_tagline'                           => array( 'text', $text ),
			'site_logo'                              => array( 'image', array() ),
			'home_url'                               => array( 'url', array() ),
			'current_date'                           => array( 'text', array_merge( $text, self::DATE ) ),
			'term_description'                       => array( 'text', $text ),
			'post_meta_key'                          => array( 'text', $field ),
			'custom_meta_manual_custom_field_value'  => array( 'text', $field ),
			'loop_post_title'                        => array( 'text', self::LOOP ),
			'loop_post_excerpt'                      => array( 'text', array_merge( self::LOOP, array( 'words', 'read_more_label' ) ) ),
			'loop_post_date'                         => array( 'text', array_merge( self::LOOP, self::DATE ) ),
			'loop_post_modified_date'                => array( 'text', array_merge( self::LOOP, self::DATE ) ),
			'loop_post_author'                       => array( 'text', array_merge( self::LOOP, array( 'name_format', 'link', 'link_destination' ) ) ),
			'loop_post_author_bio'                   => array( 'text', self::LOOP ),
			'loop_post_author_profile_picture'       => array( 'image', array( 'loop_position' ) ),
			'loop_post_link'                         => array( 'url', self::LOOP ),
			'loop_post_comment_count'                => array( 'text', self::LOOP ),
			'loop_post_id'                           => array( 'text', self::LOOP ),
			'loop_post_featured_image'               => array( 'image', array( 'loop_position', 'thumbnail_size' ) ),
			'loop_post_featured_image_alt_text'      => array( 'text', self::LOOP ),
			'loop_post_featured_image_title_text'    => array( 'text', self::LOOP ),
			'loop_post_terms'                        => array( 'text', array_merge( self::LOOP, array( 'taxonomy_type', 'separator', 'links' ) ) ),
			'loop_post_meta_key_manual_custom_field' => array( 'text', $loopf ),
			'loop_term_name'                         => array( 'text', self::LOOP ),
			'loop_term_description'                  => array( 'text', self::LOOP ),
			'loop_term_count'                        => array( 'text', self::LOOP ),
			'loop_term_permalink'                    => array( 'url', self::LOOP ),
			'loop_term_taxonomy'                     => array( 'text', self::LOOP ),
			'loop_term_featured_image'               => array( 'image', self::LOOP ),
			'loop_term_meta_key_manual_custom_field' => array( 'text', $loopf ),
			'loop_user_name'                         => array( 'text', self::LOOP ),
			'loop_user_username'                     => array( 'text', self::LOOP ),
			'loop_user_email'                        => array( 'text', self::LOOP ),
			'loop_user_avatar'                       => array( 'image', self::LOOP ),
			'loop_user_description'                  => array( 'text', self::LOOP ),
			'loop_user_url'                          => array( 'url', self::LOOP ),
			'loop_user_meta_key_manual_custom_field' => array( 'text', $loopf ),
			'loop_menu_text'                         => array( 'text', self::LOOP ),
			'loop_menu_link'                         => array( 'url', self::LOOP ),
			'loop_menu_description'                  => array( 'text', self::LOOP ),
			'loop_menu_classes'                      => array( 'text', self::LOOP ),
			'loop_menu_attr_title'                   => array( 'text', self::LOOP ),
			'loop_menu_menu_order'                   => array( 'text', self::LOOP ),
			'loop_menu_xfn'                          => array( 'text', self::LOOP ),
		);

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' !== $post_type ) {
				$rows[ 'post_link_url_' . $post_type ] = array( 'url', array( 'post_id' ) );
			}
		}

		if ( class_exists( 'Saddle_WC' ) && Saddle_WC::is_active() ) {
			$titled = array( 'before', 'after', 'enable_title' );
			$rows   = array_merge(
				$rows,
				array(
					'product_breadcrumb'               => array( 'text', $text ),
					'product_price'                    => array( 'text', $text ),
					'product_description'              => array( 'text', $text ),
					'product_short_description'        => array( 'text', $text ),
					'product_sku'                      => array( 'text', $text ),
					'product_reviews_count'            => array( 'text', $text ),
					'product_reviews'                  => array( 'text', $titled ),
					'product_additional_information'   => array( 'text', $titled ),
					'product_reviews_tab'              => array( 'url', array() ),
					'loop_product_title'               => array( 'text', self::LOOP ),
					'loop_product_price'               => array( 'text', self::LOOP ),
					'loop_product_price_regular'       => array( 'text', self::LOOP ),
					'loop_product_price_sale'          => array( 'text', self::LOOP ),
					'loop_product_price_current'       => array( 'text', self::LOOP ),
					'loop_product_description'         => array( 'text', self::LOOP ),
					'loop_product_short_description'   => array( 'text', self::LOOP ),
					'loop_product_sku'                 => array( 'text', self::LOOP ),
					'loop_product_id'                  => array( 'text', self::LOOP ),
					'loop_product_stock_quantity'      => array( 'text', self::LOOP ),
					'loop_product_stock_status'        => array( 'text', self::LOOP ),
					'loop_product_reviews_count'       => array( 'text', self::LOOP ),
					'loop_product_terms'               => array( 'text', array_merge( self::LOOP, array( 'taxonomy_type', 'separator', 'links' ) ) ),
					'loop_product_post_featured_image' => array( 'image', array( 'loop_position' ) ),
					'loop_product_post_link_url'       => array( 'url', array( 'loop_position' ) ),
					'loop_product_post_date'           => array( 'text', array_merge( self::LOOP, self::DATE ) ),
				)
			);
		}

		$out = array();
		foreach ( $rows as $name => $row ) {
			$out[ $name ] = array(
				'type'     => $row[0],
				'settings' => $row[1],
			);
		}
		return $out;
	}
}
