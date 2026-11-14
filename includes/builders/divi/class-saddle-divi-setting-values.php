<?php
/**
 * Defaults, value shapes and live choices for Divi module settings.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The value side of Saddle_Divi_Settings: what a setting holds by default,
 * whether its value is one value, an object or a list, and the choices
 * WordPress supplies for selects Divi fills at run time.
 */
class Saddle_Divi_Setting_Values {

	/**
	 * Cap on choices read from WordPress for one select.
	 */
	const MAX_CHOICES = 200;

	/**
	 * A setting's default, from (in order) the module's default render
	 * attributes, the attribute's own `default` block in module.json (module
	 * packs write it there), the item's defaultAttr, then the control's
	 * defaultValue. Divi's internal colour token is shown as the var(--…)
	 * form Saddle documents. Null when none says.
	 *
	 * @param array       $item     The setting's item node.
	 * @param string      $path     Its attribute path.
	 * @param string|null $sub      Its sub-name.
	 * @param array       $render   Default render attributes.
	 * @param array       $defaults The attribute's `default` block.
	 * @return mixed|null
	 */
	public static function default_value( array $item, $path, $sub, array $render, array $defaults ) {
		$segments   = explode( '.', (string) $path );
		$candidates = array(
			self::dig( $render, $segments ),
			self::dig( $defaults, array_slice( $segments, 1 ) ),
			isset( $item['defaultAttr'] ) ? $item['defaultAttr'] : null,
		);
		foreach ( $candidates as $node ) {
			if ( ! is_array( $node ) || ! array_key_exists( 'desktop', $node ) || ! is_array( $node['desktop'] ) || ! array_key_exists( 'value', $node['desktop'] ) ) {
				continue;
			}
			$value = $node['desktop']['value'];
			if ( null !== $sub ) {
				if ( ! is_array( $value ) || ! array_key_exists( $sub, $value ) ) {
					continue;
				}
				$value = $value[ $sub ];
			}
			return self::readable( $value );
		}
		if ( isset( $item['component']['props']['defaultValue'] ) ) {
			return self::readable( $item['component']['props']['defaultValue'] );
		}
		return null;
	}

	/**
	 * Whether a value is one value, an object (with its keys) or a list.
	 *
	 * @param string $control       The entry's control.
	 * @param mixed  $default_value Its default.
	 * @return array value_shape, plus sub_fields for an object.
	 */
	public static function value_shape( $control, $default_value ) {
		if ( 'list' === $control || ( is_array( $default_value ) && $default_value && array_is_list( $default_value ) ) ) {
			return array( 'value_shape' => 'list' );
		}
		if ( is_array( $default_value ) && $default_value ) {
			return array(
				'value_shape' => 'object',
				'sub_fields'  => array_map( 'strval', array_keys( $default_value ) ),
			);
		}
		if ( 'link' === $control ) {
			return array(
				'value_shape' => 'object',
				'sub_fields'  => array( 'url', 'target' ),
			);
		}
		// Divi's own icon picker stores {unicode, type, weight}; a module
		// pack may store a string, which its default then shows.
		if ( 'icon' === $control && ! is_string( $default_value ) ) {
			return array(
				'value_shape' => 'object',
				'sub_fields'  => array( 'unicode', 'type', 'weight' ),
			);
		}
		return array( 'value_shape' => 'scalar' );
	}

	/**
	 * Choices WordPress supplies, value => label. One query per source.
	 *
	 * @param string $source One of menus, post_types, categories, cf7_forms,
	 *                       widget_areas, taxonomies; anything else is empty.
	 * @param string $key    The setting's key (categories add Divi's own
	 *                       "all" and "current" values).
	 * @return array<string,string>
	 */
	public static function live_options( $source, $key = '' ) {
		switch ( $source ) {
			case 'menus':
				return self::pairs( wp_get_nav_menus(), 'term_id', 'name' );
			case 'post_types':
				return self::post_types();
			case 'categories':
				$terms = get_terms(
					array(
						'taxonomy'   => 'category',
						'hide_empty' => false,
						'number'     => self::MAX_CHOICES,
					)
				);
				$out   = 'categories' === $key ? array(
					'all'     => __( 'All categories', 'saddle' ),
					'current' => __( 'Current category', 'saddle' ),
				) : array();
				return $out + self::pairs( is_wp_error( $terms ) ? array() : $terms, 'term_id', 'name' );
			case 'cf7_forms':
				if ( ! post_type_exists( 'wpcf7_contact_form' ) ) {
					return array();
				}
				$forms = get_posts(
					array(
						'post_type'        => 'wpcf7_contact_form',
						'post_status'      => 'publish',
						'numberposts'      => self::MAX_CHOICES,
						'orderby'          => 'title',
						'order'            => 'ASC',
						'suppress_filters' => false,
					)
				);
				return self::pairs( $forms, 'ID', 'post_title' );
			case 'widget_areas':
				global $wp_registered_sidebars;
				return self::pairs( is_array( $wp_registered_sidebars ) ? array_values( $wp_registered_sidebars ) : array(), 'id', 'name' );
			case 'taxonomies':
				return self::pairs( array_values( get_taxonomies( array( 'public' => true ), 'objects' ) ), 'name', 'label' );
		}
		return array();
	}

	/**
	 * Post types the Blog module can list: Divi's own list when it is loaded,
	 * else the public post types with an admin screen. Never attachments,
	 * as in Divi's PostTypeController.
	 *
	 * @return array<string,string>
	 */
	private static function post_types() {
		if ( function_exists( 'et_get_registered_post_type_options' ) ) {
			$types = (array) et_get_registered_post_type_options( false, false );
		} else {
			$types = array();
			foreach ( get_post_types(
				array(
					'public'  => true,
					'show_ui' => true,
				),
				'objects'
			) as $type ) {
				$types[ $type->name ] = $type->label;
			}
		}
		unset( $types['attachment'] );
		return array_map( 'strval', $types );
	}

	/**
	 * Map a list of objects or arrays to value => label.
	 *
	 * @param array  $rows  Objects or arrays.
	 * @param string $value Value property.
	 * @param string $label Label property.
	 * @return array<string,string>
	 */
	private static function pairs( $rows, $value, $label ) {
		$out = array();
		foreach ( array_slice( is_array( $rows ) ? $rows : array(), 0, self::MAX_CHOICES ) as $row ) {
			$row = (array) $row;
			if ( isset( $row[ $value ] ) ) {
				$out[ (string) $row[ $value ] ] = isset( $row[ $label ] ) ? (string) $row[ $label ] : (string) $row[ $value ];
			}
		}
		return $out;
	}

	/**
	 * Settings as divi-get-module-schema shows them. compact: path, sub,
	 * control, short choices, default and object shape. full: the whole
	 * entry. Choices Divi fills at run time are read from WordPress here.
	 *
	 * @param array[] $entries From Saddle_Divi_Settings::for_module().
	 * @param bool    $full    Full detail.
	 * @return array[]
	 */
	public static function rows( array $entries, $full = false ) {
		$rows = array();
		$live = array(); // One WordPress read per source, however many settings share it.
		foreach ( $entries as $entry ) {
			if ( null === $entry['options_source'] ) {
				$options = Saddle_Divi_Settings::options( $entry );
			} else {
				$source = $entry['options_source'] . '|' . $entry['key'];
				if ( ! isset( $live[ $source ] ) ) {
					$live[ $source ] = Saddle_Divi_Settings::options( $entry );
				}
				$options = $live[ $source ];
			}
			if ( $full ) {
				$entry['options'] = $options ? (object) $options : null;
				$rows[]           = $entry;
				continue;
			}
			$row = array( 'path' => $entry['path'] );
			if ( null !== $entry['sub_name'] ) {
				$row['sub'] = $entry['sub_name'];
			}
			$row['control'] = $entry['control'];
			if ( $options ) {
				$short          = array_slice( $options, 0, 12, true );
				$row['options'] = null === $entry['options_source'] ? array_map( 'strval', array_keys( $short ) ) : (object) $short;
				if ( count( $options ) > 12 ) {
					$row['options_count'] = count( $options );
				}
			}
			if ( null !== $entry['options_source'] ) {
				$row['options_from'] = $entry['options_source'];
			}
			if ( null !== $entry['default'] && '' !== $entry['default'] && array() !== $entry['default'] ) {
				$row['default'] = $entry['default'];
			}
			if ( 'object' === $entry['value_shape'] ) {
				$row['fields'] = $entry['sub_fields'];
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * How a setting is written today: one sentence, so a friendlier write
	 * form can replace it in one place.
	 *
	 * @return string
	 */
	public static function write_as() {
		return __( 'Write settings in a node\'s "settings" (divi-set-page, divi-add-module, divi-edit-module) by "path", or "path.sub" when a setting has a sub, with a plain value: "settings": { "post.advanced.number": 6, "module.advanced.auto": true, "divider.advanced.line.show": "on" }. SaddleWP checks the value and stores it where Divi reads it. Add "@tablet" or "@phone" to the id for another screen size, ":hover" or ":sticky" for a state: { "module.advanced.slidesToShow@phone": 1 }. The raw form in "attrs" still works but is not checked: { "post.advanced.number.desktop.value": "6" }.', 'saddle' );
	}

	/**
	 * What else to know when writing settings.
	 *
	 * @return string
	 */
	public static function note() {
		return __( 'A toggle takes true, false, "on" or "off". A select takes one of its listed choices; anything else is refused with the valid ones. A number or range takes a number or a string with a unit ("24px"). A list control takes a JSON list. "options_from" means WordPress supplies the choices (menus, post_types, categories, cf7_forms, widget_areas); "loops" means the id of a loop on the same page. A screen-size suffix works only where detail="full" says responsive, and only for the breakpoints divi-check-setup lists.', 'saddle' );
	}

	/**
	 * Walk an array by keys.
	 *
	 * @param mixed    $node Array to walk.
	 * @param string[] $keys Keys.
	 * @return mixed|null
	 */
	private static function dig( $node, array $keys ) {
		foreach ( $keys as $k ) {
			if ( ! is_array( $node ) || ! array_key_exists( $k, $node ) ) {
				return null;
			}
			$node = $node[ $k ];
		}
		return $node;
	}

	/**
	 * Divi stores a global colour as $variable({"type":"color","value":
	 * {"name":"gcid-…"}})$; Divi also renders the var(--gcid-…) form, which is
	 * the one Saddle teaches. Anything else passes through.
	 *
	 * @param mixed $value A default.
	 * @return mixed
	 */
	private static function readable( $value ) {
		if ( is_string( $value ) && 0 === strpos( $value, '$variable(' ) && '$' === substr( $value, -1 ) ) {
			$token = json_decode( substr( $value, 10, -2 ), true );
			if ( is_array( $token ) && isset( $token['value']['name'] ) && is_string( $token['value']['name'] ) ) {
				return 'var(--' . $token['value']['name'] . ')';
			}
		}
		return $value;
	}
}
