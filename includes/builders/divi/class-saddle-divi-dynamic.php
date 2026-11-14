<?php
/**
 * Divi 5 dynamic content: field paths, binding settings, where a source resolves.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * What a dynamic content binding needs to render: the right attribute path,
 * the settings Divi reads, and a source that resolves where the module sits.
 *
 * Read from Divi 5.10.1 (DynamicContentUtils, DynamicContentOption* classes,
 * DynamicContentLoopOptions and the block parser), not render-tested:
 *
 * - Divi resolves `$variable(...)$` tokens anywhere in the saved page, so a
 *   token may sit in one part of an object value (an image's src).
 * - Divi keeps only string settings; a number or boolean is dropped.
 * - A custom field is read from settings.meta_key (or select_meta_key);
 *   inside a loop from settings.loop_meta_key (or select_loop_meta_key).
 * - Inside a loop only loop_ sources change per item. A plain post_title
 *   resolves against the page, or the post a Theme Builder template shows.
 */
class Saddle_Divi_Dynamic {

	/**
	 * Breakpoint names a stored value can sit under.
	 */
	const BREAKPOINTS = array( 'desktop', 'tablet', 'phone', 'phoneWide', 'tabletWide', 'widescreen', 'ultraWide' );

	/**
	 * Older or shorter source names, stored as the name Divi resolves.
	 */
	const SOURCE_ALIASES = array(
		'loop_manual_custom_field' => 'loop_post_meta_key_manual_custom_field',
		'loop_post_meta_key'       => 'loop_post_meta_key_manual_custom_field',
		'loop_term_meta_key'       => 'loop_term_meta_key_manual_custom_field',
		'loop_user_meta_key'       => 'loop_user_meta_key_manual_custom_field',
	);

	/**
	 * Parse a "field" into the attribute path written.
	 *
	 * "title.innerContent" → title.innerContent.<bp>.value;
	 * "image.innerContent.src" → image.innerContent.<bp>.value.src;
	 * a path that already names a breakpoint and state is used as given.
	 *
	 * @param string $field      Field input.
	 * @param string $breakpoint Breakpoint input.
	 * @return string[]|WP_Error
	 */
	public static function field_path( $field, $breakpoint ) {
		$field = trim( (string) $field );
		if ( '' === $field || ! preg_match( '/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $field ) ) {
			return new WP_Error( 'saddle_bad_field', __( 'Provide the field\'s attribute path from divi-get-module-schema, e.g. "title.innerContent" or "image.innerContent.src".', 'saddle' ) );
		}
		$breakpoint = '' === (string) $breakpoint ? 'desktop' : (string) $breakpoint;
		if ( ! in_array( $breakpoint, self::BREAKPOINTS, true ) ) {
			return new WP_Error(
				'saddle_bad_breakpoint',
				sprintf(
					/* translators: %s: list of breakpoint names. */
					__( 'Unknown breakpoint. Use one of: %s.', 'saddle' ),
					implode( ', ', self::BREAKPOINTS )
				)
			);
		}

		$segments = explode( '.', $field );
		foreach ( $segments as $i => $segment ) {
			if ( $i > 0 && in_array( $segment, self::BREAKPOINTS, true ) && isset( $segments[ $i + 1 ] ) && in_array( $segments[ $i + 1 ], array( 'value', 'hover', 'sticky' ), true ) ) {
				return $segments;
			}
		}

		$at = array_search( 'innerContent', $segments, true );
		if ( false !== $at && $at < count( $segments ) - 1 ) {
			return array_merge( array_slice( $segments, 0, $at + 1 ), array( $breakpoint, 'value' ), array_slice( $segments, $at + 1 ) );
		}
		return array_merge( $segments, array( $breakpoint, 'value' ) );
	}

	/**
	 * Check a path against the module's schema, when it has one.
	 *
	 * @param string   $type     Module type.
	 * @param string[] $path     Attribute path from field_path().
	 * @param bool     $clearing True for clear-dynamic-content, which may name a whole object.
	 * @return true|WP_Error
	 */
	public static function check_field( $type, array $path, $clearing = false ) {
		$described = Saddle_Divi_Schema::describe( $type );
		if ( is_wp_error( $described ) ) {
			return true; // An unjudgeable module still passes.
		}
		$attributes = array();
		foreach ( $described['attributes'] as $attribute ) {
			$attributes[ $attribute['name'] ] = $attribute;
		}
		if ( ! isset( $attributes[ $path[0] ] ) ) {
			return new WP_Error(
				'saddle_bad_field',
				sprintf(
					/* translators: 1: written segment, 2: module type, 3: attribute list. */
					__( '"%1$s" is not an attribute of %2$s, so this binding would never render. Module attributes: %3$s. Use the path from divi-get-module-schema (e.g. "title.innerContent").', 'saddle' ),
					$path[0],
					$type,
					implode( ', ', array_keys( $attributes ) )
				)
			);
		}

		$attribute = $attributes[ $path[0] ];
		if ( ! isset( $path[1] ) || 'innerContent' !== $path[1] || empty( $attribute['content'] ) ) {
			return true;
		}
		$part   = isset( $path[4] ) ? $path[4] : null; // innerContent.<bp>.value.<part>.
		$fields = isset( $attribute['content_fields'] ) ? (array) $attribute['content_fields'] : array();

		if ( null !== $part && ! $fields ) {
			return new WP_Error(
				'saddle_bad_field',
				sprintf(
					/* translators: %s: attribute name. */
					__( '%1$s.innerContent holds plain text, not parts. Bind the whole field: "%1$s.innerContent".', 'saddle' ),
					$path[0]
				)
			);
		}
		if ( null === $part && $fields && ! $clearing ) {
			return new WP_Error(
				'saddle_bad_field',
				sprintf(
					/* translators: 1: attribute name, 2: list of parts, 3: first part. */
					__( '%1$s.innerContent holds several parts (%2$s), and a binding replaces one of them. Bind one part, for example "%1$s.innerContent.%3$s".', 'saddle' ),
					$path[0],
					implode( ', ', $fields ),
					$fields[0]
				)
			);
		}
		if ( null !== $part && ! in_array( $part, $fields, true ) ) {
			return new WP_Error(
				'saddle_bad_field',
				sprintf(
					/* translators: 1: part, 2: attribute name, 3: list of parts. */
					__( '"%1$s" is not a part of %2$s.innerContent. Its parts: %3$s.', 'saddle' ),
					$part,
					$path[0],
					implode( ', ', $fields )
				)
			);
		}
		return true;
	}

	/**
	 * Validate a source name and map an older name to the one Divi resolves.
	 *
	 * @param mixed $raw Source input.
	 * @return string|WP_Error
	 */
	public static function source_name( $raw ) {
		$name = trim( (string) $raw );
		if ( '' === $name ) {
			return new WP_Error( 'saddle_empty', __( 'Provide the dynamic "source" name. Call divi-list-dynamic-sources for options.', 'saddle' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_|-]{1,190}$/', $name ) ) {
			return new WP_Error( 'saddle_bad_source', __( 'A source name holds only letters, digits, "_", "-" and "|". Copy it from divi-list-dynamic-sources.', 'saddle' ) );
		}
		return isset( self::SOURCE_ALIASES[ $name ] ) ? self::SOURCE_ALIASES[ $name ] : $name;
	}

	/**
	 * The meta type a loop custom field source reads ('' for other sources).
	 *
	 * @param string $source Source name.
	 * @return string post, term, user or ''.
	 */
	private static function loop_meta_type( $source ) {
		return preg_match( '/^loop_(post|term|user)_meta_key_/', $source, $m ) ? $m[1] : '';
	}

	/**
	 * Map the documented older settings to what Divi reads, then refuse any
	 * protected custom field. Guards every custom field name form: the name
	 * is worked out exactly as Divi works it out, and the raw settings are
	 * checked too.
	 *
	 * @param string $source   Source name (canonical).
	 * @param array  $settings Raw settings.
	 * @return array|WP_Error Settings with the names Divi reads.
	 */
	public static function prepare_settings( $source, array $settings ) {
		// A user loop prints these once per listed user, on the page.
		if ( in_array( $source, array( 'loop_user_username', 'loop_user_email' ), true ) && ! current_user_can( 'list_users' ) ) {
			return new WP_Error(
				'saddle_protected_source',
				__( 'This source shows each listed user\'s sign-in name or email address on the page, so it needs an account that can list users.', 'saddle' ),
				array( 'status' => 400 )
			);
		}
		if ( 'loop_user_email' === $source && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'saddle_protected_source',
				__( 'Email addresses are not bound: a user loop would show every listed user\'s email address on the public page. Only an administrator account can bind this source.', 'saddle' ),
				array( 'status' => 400 )
			);
		}

		$loop_type = self::loop_meta_type( $source );
		$keys      = array();
		if ( isset( $settings['key'] ) ) {
			$keys[] = (string) $settings['key'];
		}

		if ( '' !== $loop_type ) {
			foreach ( array( 'key', 'meta_key' ) as $alias ) {
				if ( isset( $settings[ $alias ] ) && ! isset( $settings['loop_meta_key'] ) ) {
					$settings['loop_meta_key'] = $settings[ $alias ];
				}
				unset( $settings[ $alias ] );
			}
			if ( isset( $settings['loop_meta_key'] ) && ! isset( $settings['select_loop_meta_key'] ) ) {
				$settings['select_loop_meta_key'] = 'loop_' . $loop_type . '_meta_key_manual_custom_field_value';
			}
		} elseif ( in_array( $source, array( 'post_meta_key', 'custom_meta_manual_custom_field_value' ), true ) ) {
			if ( isset( $settings['key'] ) && ! isset( $settings['meta_key'] ) ) {
				$settings['meta_key'] = $settings['key']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Divi's dynamic content setting, not a query argument.
			}
			unset( $settings['key'] );
			if ( 'post_meta_key' === $source && isset( $settings['meta_key'] ) && ! isset( $settings['select_meta_key'] ) ) {
				$settings['select_meta_key'] = 'custom_meta_manual_custom_field_value';
			}
		}

		foreach ( array( 'meta_key', 'loop_meta_key' ) as $name ) {
			if ( isset( $settings[ $name ] ) ) {
				$keys[] = (string) $settings[ $name ];
			}
		}
		$read = self::read_meta_key( $source, $settings );
		if ( null !== $read ) {
			$keys[] = $read;
		}
		if ( '' !== $loop_type ) {
			// Divi takes a loop custom field from the settings, never from the
			// source name; a name that carries one is guarded all the same.
			$named = str_replace( 'loop_' . $loop_type . '_meta_key_', '', $source );
			if ( 0 !== strpos( $named, 'manual_custom_field' ) ) {
				$keys[] = $named;
			}
		}

		$meta_type = '' !== $loop_type ? $loop_type : 'post';
		foreach ( array_unique( $keys ) as $key ) {
			$refused = self::refuse_meta_key( $key, $meta_type );
			if ( $refused ) {
				return $refused;
			}
		}
		return $settings;
	}

	/**
	 * The custom field name Divi 5.10.1 reads for a source and its settings,
	 * worked out the way Divi works it out; null when the source reads none.
	 *
	 * - custom_meta_<name>: str_replace() of every "custom_meta_", except
	 *   custom_meta_manual_custom_field_value, which reads settings.meta_key
	 *   (DynamicContentOptionPostMetaKey::render_callback()).
	 * - post_meta_key: settings.select_meta_key the same way when it is not
	 *   empty, else settings.meta_key.
	 * - loop_<post|term|user>_meta_key_*: settings.loop_meta_key when
	 *   select_loop_meta_key ends in "_manual_custom_field_value" (or, on a
	 *   user field, is empty), else str_replace() of the loop prefix that
	 *   select_loop_meta_key starts with
	 *   (DynamicContentOptionLoopPostMetaKey::get_meta_key_from_name()).
	 *
	 * @param string $source   Source name.
	 * @param array  $settings Settings as stored.
	 * @return string|null
	 */
	public static function read_meta_key( $source, array $settings ) {
		$loop_type = self::loop_meta_type( $source );
		if ( '' !== $loop_type ) {
			$select = isset( $settings['select_loop_meta_key'] ) ? (string) $settings['select_loop_meta_key'] : '';
			if ( str_ends_with( $select, '_manual_custom_field_value' ) || ( 'user' === $loop_type && '' === $select ) ) {
				return isset( $settings['loop_meta_key'] ) ? (string) $settings['loop_meta_key'] : '';
			}
			foreach ( array( 'post', 'user', 'term' ) as $type ) {
				if ( str_starts_with( $select, 'loop_' . $type . '_meta_key_' ) ) {
					return str_replace( 'loop_' . $type . '_meta_key_', '', $select );
				}
			}
			return '';
		}

		$meta_key = isset( $settings['meta_key'] ) ? (string) $settings['meta_key'] : '';
		if ( str_starts_with( $source, 'custom_meta_' ) ) {
			return 'custom_meta_manual_custom_field_value' === $source ? $meta_key : str_replace( 'custom_meta_', '', $source );
		}
		if ( 'post_meta_key' !== $source ) {
			return null;
		}
		$select = isset( $settings['select_meta_key'] ) ? (string) $settings['select_meta_key'] : '';
		if ( ! empty( $select ) ) {
			if ( 'custom_meta_manual_custom_field_value' === $select ) {
				return $meta_key;
			}
			return str_starts_with( $select, 'custom_meta_' ) ? str_replace( 'custom_meta_', '', $select ) : null;
		}
		return ! empty( $meta_key ) ? $meta_key : null;
	}

	/**
	 * Refuse a custom field whose value must not reach a public page, and a
	 * name that still holds a Divi prefix.
	 *
	 * @param string $key       Meta key.
	 * @param string $meta_type post, term or user.
	 * @return WP_Error|null
	 */
	private static function refuse_meta_key( $key, $meta_type ) {
		foreach ( array( 'custom_meta_', 'loop_post_meta_key_', 'loop_term_meta_key_', 'loop_user_meta_key_' ) as $prefix ) {
			if ( false !== strpos( (string) $key, $prefix ) ) {
				return new WP_Error(
					'saddle_bad_meta_key',
					sprintf(
						/* translators: %s: the name prefix found. */
						__( 'A custom field name cannot contain "%s". Write the field\'s own name.', 'saddle' ),
						$prefix
					),
					array( 'status' => 400 )
				);
			}
		}
		$rule = self::meta_key_rule( $key, $meta_type );
		if ( 'protected' === $rule ) {
			return new WP_Error(
				'saddle_protected_meta',
				__( 'The bound meta key is protected (starts with "_"): its value would become publicly visible on the page, so SaddleWP refuses it. A site owner can allow a specific key with the "saddle_dynamic_meta_allowlist" filter.', 'saddle' ),
				array( 'status' => 400 )
			);
		}
		if ( 'user' === $rule ) {
			return new WP_Error(
				'saddle_protected_meta',
				__( 'This user custom field is not bound: a user loop shows each user\'s value on the public page. Divi offers user custom fields only to administrators, and SaddleWP never binds sign-in or role data.', 'saddle' ),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * The custom field rule shared by bindings and loop conditions: ''
	 * when a key may be used, "protected" for a protected name that is not
	 * allowed, "user" for a user field this account may not use.
	 *
	 * @param string $key       Meta key.
	 * @param string $meta_type post, term or user.
	 * @return string
	 */
	public static function meta_key_rule( $key, $meta_type ) {
		$key = (string) $key;
		if ( is_protected_meta( $key, $meta_type ) && ! in_array( $key, self::meta_allowlist(), true ) ) {
			return 'protected';
		}
		if ( 'user' === $meta_type && ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'list_users' ) || 'session_tokens' === $key || preg_match( '/(capabilities|user_level)$/', $key ) ) ) {
			return 'user';
		}
		return '';
	}

	/**
	 * Protected custom fields that may still be shown or filtered on.
	 *
	 * @return string[]
	 */
	public static function meta_allowlist() {
		$public = class_exists( 'Saddle_WC' ) && Saddle_WC::is_active() ? array( '_price', '_regular_price', '_sale_price', '_stock_status', '_sku' ) : array();

		/**
		 * Meta keys that may be bound to public dynamic content, or used in a
		 * loop's custom field conditions, despite the protected "_" prefix.
		 * While WooCommerce is active it holds the product fields every
		 * product page shows (price, regular and sale price, stock status,
		 * SKU); empty otherwise. Add only keys you know are safe to show.
		 *
		 * @param string[] $allowlist Allowed protected meta keys.
		 */
		return array_map( 'strval', (array) apply_filters( 'saddle_dynamic_meta_allowlist', $public ) );
	}

	/**
	 * Drop enable_html unless the account may post unfiltered HTML, the
	 * same rule Divi's builder follows when it offers the setting.
	 *
	 * @param array $settings Settings.
	 * @return array array( settings, warnings ).
	 */
	public static function limit_raw_html( array $settings ) {
		if ( ! array_key_exists( 'enable_html', $settings ) || current_user_can( 'unfiltered_html' ) ) {
			return array( $settings, array() );
		}
		unset( $settings['enable_html'] );
		return array(
			$settings,
			array( __( 'enable_html was left out: only an account that may post unfiltered HTML can show a value as raw HTML, so Divi escapes it.', 'saddle' ) ),
		);
	}

	/**
	 * Divi keeps only string settings: numbers become strings, booleans
	 * become "on" or "off", nulls are dropped.
	 *
	 * @param array $settings Flat scalar settings.
	 * @return array
	 */
	public static function stringify( array $settings ) {
		$out = array();
		foreach ( $settings as $key => $value ) {
			if ( is_bool( $value ) ) {
				$out[ $key ] = $value ? 'on' : 'off';
			} elseif ( null !== $value ) {
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * The binding token Divi stores.
	 *
	 * @param string $source   Source name.
	 * @param array  $settings Settings.
	 * @return string
	 */
	public static function token( $source, array $settings ) {
		return '$variable(' . wp_json_encode(
			array(
				'type'  => 'content',
				'value' => array(
					'name'     => $source,
					'settings' => $settings ? $settings : (object) array(),
				),
			),
			JSON_UNESCAPED_UNICODE
		) . ')$';
	}

	/**
	 * Remove every binding token, keeping literal text. Walks object values.
	 *
	 * @param mixed $value Stored value.
	 * @return mixed
	 */
	public static function strip( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'strip' ), $value );
		}
		return is_string( $value ) ? trim( (string) preg_replace( '/\$variable\(.*?\)\$/s', '', $value ) ) : $value;
	}

	/**
	 * Warn when a source will not resolve per item where the module sits.
	 *
	 * @param string $source  Source name.
	 * @param array  $tree    Page tree.
	 * @param string $address Module address.
	 * @return string[]
	 */
	public static function context_warnings( $source, array $tree, $address ) {
		$loop_at = null;
		$loop    = array();
		$parts   = explode( '.', (string) $address );
		while ( $parts ) {
			$at   = implode( '.', $parts );
			$node = Saddle_Divi_Tree::get( $tree, $at );
			$loop = $node ? Saddle_Divi_Loop::normalize( Saddle_Divi_Loop::value( $node ) ) : array();
			if ( isset( $loop['enable'] ) && 'on' === $loop['enable'] ) {
				$loop_at = $at;
				break;
			}
			array_pop( $parts );
		}

		$is_loop = 0 === strpos( $source, 'loop_' );
		if ( null === $loop_at ) {
			return $is_loop ? array(
				sprintf(
					/* translators: %s: source name. */
					__( '%s changes per loop item, but neither this module nor a container around it has a loop turned on, so it prints only its before and after text. Turn on a loop with divi-enable-loop, or use a page source.', 'saddle' ),
					$source
				),
			) : array();
		}

		$base   = Saddle_Divi_Loop::base_type( isset( $loop['queryType'] ) ? $loop['queryType'] : 'post_types' );
		$family = self::source_family( $source );
		if ( ! $is_loop ) {
			$hint = self::loop_counterpart( $source );
			if ( null !== $hint && in_array( $base, array( 'post_types', 'current_page' ), true ) ) {
				return array(
					sprintf(
						/* translators: 1: source, 2: loop address, 3: suggested loop source. */
						__( 'This module sits inside the loop at %2$s, where %1$s shows the page\'s own value on every item. For each item\'s value use %3$s.', 'saddle' ),
						$source,
						$loop_at,
						$hint
					),
				);
			}
			return array();
		}

		$needs = array(
			'post'    => array( 'post_types', 'current_page' ),
			'product' => array( 'post_types', 'current_page' ),
			'term'    => array( 'post_taxonomies', 'terms' ),
			'user'    => array( 'user_roles' ),
			'menu'    => array( 'menus' ),
			'acf'     => array( 'repeater' ),
		);
		if ( isset( $needs[ $family ] ) && ! in_array( $base, $needs[ $family ], true ) ) {
			return array(
				sprintf(
					/* translators: 1: source, 2: loop address, 3: the loop's query type, 4: list of query types the source needs. */
					__( '%1$s prints the items of a loop of one of these types: %4$s. The loop at %2$s is a %3$s loop, so it prints nothing here.', 'saddle' ),
					$source,
					$loop_at,
					$base,
					implode( ', ', $needs[ $family ] )
				),
			);
		}
		return array();
	}

	/**
	 * The loop_ source that gives each item the value a page source gives
	 * the page, or null when the page source is not about the post (a link
	 * to one chosen post, the site's title).
	 *
	 * @param string $source Page source name.
	 * @return string|null
	 */
	private static function loop_counterpart( $source ) {
		$same = array( 'post_title', 'post_excerpt', 'post_date', 'post_modified_date', 'post_comment_count', 'post_id', 'post_author', 'post_author_bio', 'post_author_profile_picture', 'post_featured_image', 'post_featured_image_alt_text', 'post_featured_image_title_text' );
		if ( in_array( $source, $same, true ) ) {
			return 'loop_' . $source;
		}
		$map = array(
			'post_link'       => 'loop_post_link',
			'post_link_url'   => 'loop_post_link',
			'post_categories' => 'loop_post_terms',
			'post_tags'       => 'loop_post_terms',
			'post_meta_key'   => 'loop_post_meta_key_manual_custom_field',
		);
		if ( isset( $map[ $source ] ) ) {
			return $map[ $source ];
		}
		return 0 === strpos( $source, 'custom_meta_' ) ? 'loop_post_meta_key_manual_custom_field' : null;
	}

	/**
	 * The family a source belongs to: post, product, term, user, menu, acf,
	 * or for page sources author, site, term, product, custom_fields, post.
	 *
	 * @param string $name Source name.
	 * @return string
	 */
	public static function source_family( $name ) {
		if ( 0 === strpos( $name, 'loop_' ) ) {
			foreach ( array( 'product', 'term', 'user', 'menu', 'acf', 'post' ) as $family ) {
				if ( 0 === strpos( $name, 'loop_' . $family . '_' ) ) {
					return $family;
				}
			}
			return false !== strpos( $name, '|||' ) ? 'acf' : 'post';
		}
		if ( 'post_meta_key' === $name || 0 === strpos( $name, 'custom_meta_' ) ) {
			return 'custom_fields';
		}
		if ( 0 === strpos( $name, 'post_author' ) ) {
			return 'author';
		}
		if ( 0 === strpos( $name, 'site_' ) || in_array( $name, array( 'home_url', 'current_date' ), true ) ) {
			return 'site';
		}
		foreach ( array( 'term', 'product' ) as $family ) {
			if ( 0 === strpos( $name, $family . '_' ) ) {
				return $family;
			}
		}
		return 'post';
	}
}
