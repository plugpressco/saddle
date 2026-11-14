<?php
/**
 * Every non-text setting of a Divi 5 module, read from its module.json.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a module's settings (post count, autoplay, which menu, arrows, …)
 * from `attributes.<attr>.settings.advanced`, plus the choice-like controls
 * inside `settings.innerContent` (a toggle, a select, a widget area). Works
 * the same for Divi's own modules and any module pack with a module.json.
 *
 * Where a setting's value lives: `<attrName>.<breakpoint>.<state>`, and
 * inside that value under `<subName>` when the item declares one. With no
 * attrName, Divi uses `<attr>.advanced.<group key>` (checked against the
 * module-default-render-attributes.json files of all 85 core modules and
 * the module PHP that reads them, e.g. MenuModule reads
 * menu.advanced.menuId).
 *
 * Skipped on purpose, because they are not one setting an agent writes:
 * - divi/elements (which elements show), divi/warning (a notice),
 *   divi/conditions (display conditions have their own tool), the two
 *   post-filter cache buttons, and divi/google-api-key-field (it edits the
 *   site-wide Google Maps key, not the module);
 * - group components (component type "group", divi/composite,
 *   divi/dividers): html, link, text, gutter, sizing, spacing, layout,
 *   dividers. The style schema documents those. A field declared inside a
 *   group's props.fields is still read on its own.
 * - items Divi does not render (render: false).
 */
class Saddle_Divi_Settings {

	/**
	 * Divi component name => the control an agent sees.
	 */
	const CONTROLS = array(
		'divi/toggle'                => 'toggle',
		'divi/select'                => 'select',
		'divi/select-menu'           => 'select',
		'divi/select-contact-form-7' => 'select',
		'divi/select-widget-area'    => 'select',
		'divi/select-column-class'   => 'select',
		'divi/button-options'        => 'select',
		'divi/placement-grid-picker' => 'select',
		'divi/text'                  => 'text',
		'divi/textarea'              => 'text',
		'divi/richtext'              => 'text',
		'divi/timepicker'            => 'text',
		'divi/google-api-key-field'  => 'text',
		'divi/number'                => 'number',
		'divi/range'                 => 'range',
		'divi/color-picker'          => 'color',
		'divi/icon-picker'           => 'icon',
		'divi/upload'                => 'upload',
		'divi/checkboxes'            => 'list',
		'divi/sortable-list'         => 'list',
		'divi/draggable-list'        => 'list',
		'divi/upload-gallery'        => 'list',
		'divi/link'                  => 'link',
	);

	/**
	 * Components that hold no value an agent sets.
	 */
	const NOT_SETTINGS = array(
		'divi/elements',
		'divi/warning',
		'divi/conditions',
		'divi/post-filter-item-clear-custom-field-value-options-cache',
		'divi/post-filter-item-generate-custom-field-value-options',
		// Edits the site-wide Google Maps key (et_google_api_settings), not
		// the module: MapModule.php reads et_pb_get_google_api_key().
		'divi/google-api-key-field',
	);

	/**
	 * Group components without a "group" type in module.json.
	 */
	const GROUPS = array( 'divi/composite', 'divi/dividers' );

	/**
	 * Controls a content (innerContent) item must have to count as a
	 * setting. Text, images and links in content are content fields.
	 */
	const CONTENT_CONTROLS = array( 'toggle', 'select', 'list', 'range', 'number', 'icon', 'color' );

	/**
	 * Selects Divi fills at run time, by component name.
	 */
	const RUNTIME_SOURCES = array(
		'divi/select-menu'           => 'menus',
		'divi/select-contact-form-7' => 'cf7_forms',
		'divi/select-widget-area'    => 'widget_areas',
	);

	/**
	 * Every setting of a module, in module.json order. Empty for a module
	 * with no module.json on this site.
	 *
	 * Each entry: path, attr, key, sub_name, id, source, label, description,
	 * control, options, options_source, default, responsive, hover, sticky,
	 * dynamic, group, value_shape, and sub_fields when value_shape is object.
	 *
	 * @param string $type Module type, e.g. 'divi/blog'.
	 * @return array[]
	 */
	public static function for_module( $type ) {
		static $memo = array();
		$json        = Saddle_Divi_Schema::module_json( (string) $type );
		$key         = (string) $type . '|' . md5( (string) wp_json_encode( Saddle_Divi_Schema::index() ) );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		if ( ! is_array( $json ) || empty( $json['attributes'] ) || ! is_array( $json['attributes'] ) ) {
			$memo[ $key ] = array();
			return array();
		}

		$render  = Saddle_Divi_Schema::render_defaults( (string) $type );
		$entries = array();
		foreach ( $json['attributes'] as $attr => $attribute ) {
			$settings = is_array( $attribute ) && isset( $attribute['settings'] ) && is_array( $attribute['settings'] ) ? $attribute['settings'] : array();
			$defaults = isset( $attribute['default'] ) && is_array( $attribute['default'] ) ? $attribute['default'] : array();
			foreach ( array( 'advanced', 'innerContent' ) as $source ) {
				if ( empty( $settings[ $source ] ) || ! is_array( $settings[ $source ] ) ) {
					continue;
				}
				$groups = 'advanced' === $source ? $settings[ $source ] : array( '' => $settings[ $source ] );
				foreach ( $groups as $group_key => $group ) {
					foreach ( self::items( $group, (string) $group_key ) as $pair ) {
						$entry = self::entry( (string) $attr, $source, $pair[0], $pair[1], $render, $defaults );
						if ( $entry && ! isset( $entries[ $entry['id'] ] ) ) {
							$entries[ $entry['id'] ] = $entry;
						}
					}
				}
			}
		}

		$memo[ $key ] = array_values( $entries );
		return $memo[ $key ];
	}

	/**
	 * One setting by its id (path, then ".sub_name" when it has one), by
	 * its path when only one setting has it, or by "<attr>.<key>" when that
	 * is unique.
	 *
	 * @param string $type Module type.
	 * @param string $path Setting id, path, or "<attr>.<key>".
	 * @return array|null
	 */
	public static function find( $type, $path ) {
		$all  = self::for_module( $type );
		$path = (string) $path;
		foreach ( array( 'id', 'path', 'short' ) as $by ) {
			$hits = array();
			foreach ( $all as $entry ) {
				$value = 'short' === $by ? $entry['attr'] . '.' . $entry['key'] : $entry[ $by ];
				if ( $value === $path ) {
					$hits[] = $entry;
				}
			}
			if ( 1 === count( $hits ) ) {
				return $hits[0];
			}
		}
		return null;
	}

	/**
	 * The choices for an entry, value => label: its own options, or, when
	 * Divi fills them at run time, what WordPress has (nav menus, public post
	 * types, categories, Contact Form 7 forms, widget areas, public
	 * taxonomies). Read-only, one query per source, and empty unless the
	 * current user may edit posts.
	 *
	 * @param array $entry An entry from for_module().
	 * @return array<string,string>
	 */
	public static function options( array $entry ) {
		$source = isset( $entry['options_source'] ) ? $entry['options_source'] : null;
		if ( null === $source ) {
			return isset( $entry['options'] ) && is_array( $entry['options'] ) ? $entry['options'] : array();
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array();
		}
		return Saddle_Divi_Setting_Values::live_options( (string) $source, isset( $entry['key'] ) ? (string) $entry['key'] : '' );
	}

	/**
	 * The item nodes inside one settings group, keyed by the group key they
	 * belong to (the key a missing attrName falls back on).
	 *
	 * @param mixed  $group     A settings group from module.json.
	 * @param string $group_key Its key.
	 * @return array[] List of [group key, item] pairs.
	 */
	private static function items( $group, $group_key ) {
		if ( ! is_array( $group ) ) {
			return array();
		}
		$found = array();
		if ( isset( $group['item'] ) && is_array( $group['item'] ) ) {
			$found[] = $group['item'];
		}
		if ( isset( $group['items'] ) && is_array( $group['items'] ) ) {
			$found = array_merge( $found, array_values( array_filter( $group['items'], 'is_array' ) ) );
		}
		if ( isset( $group['groups'] ) && is_array( $group['groups'] ) ) {
			foreach ( $group['groups'] as $inner ) {
				foreach ( self::items( $inner, $group_key ) as $pair ) {
					$found[] = $pair[1];
				}
			}
		}
		if ( isset( $group['component'] ) && is_array( $group['component'] ) && ! isset( $group['item'] ) ) {
			$found[] = $group;
		}

		$pairs = array();
		foreach ( $found as $item ) {
			$component = isset( $item['component'] ) && is_array( $item['component'] ) ? $item['component'] : array();
			$name      = isset( $component['name'] ) ? (string) $component['name'] : '';
			$is_group  = ( isset( $component['type'] ) && 'group' === $component['type'] ) || in_array( $name, self::GROUPS, true );
			if ( $is_group ) {
				$fields = isset( $component['props']['fields'] ) && is_array( $component['props']['fields'] ) ? $component['props']['fields'] : array();
				foreach ( $fields as $field ) {
					if ( is_array( $field ) && isset( $field['component']['name'] ) ) {
						$pairs[] = array( $group_key, $field );
					}
				}
				continue;
			}
			$pairs[] = array( $group_key, $item );
		}

		return $pairs;
	}

	/**
	 * Build one entry, or null when the item is not a setting.
	 *
	 * @param string $attr      Attribute name.
	 * @param string $source    'advanced' or 'innerContent'.
	 * @param string $group_key The group key a missing attrName falls back on.
	 * @param array  $item      The item node.
	 * @param array  $render    Default render attributes.
	 * @param array  $defaults  The attribute's own `default` block.
	 * @return array|null
	 */
	private static function entry( $attr, $source, $group_key, array $item, array $render, array $defaults ) {
		$component = isset( $item['component'] ) && is_array( $item['component'] ) ? $item['component'] : array();
		$name      = isset( $component['name'] ) ? (string) $component['name'] : '';
		if ( in_array( $name, self::NOT_SETTINGS, true ) || ( isset( $item['render'] ) && false === $item['render'] ) ) {
			return null;
		}
		// A bare override of a shared group (module.advanced.text with only
		// props) names no component: nothing to set here.
		if ( '' === $name && ! ( isset( $component['type'] ) && 'field' === $component['type'] && isset( $item['label'] ) ) ) {
			return null;
		}
		$control = isset( self::CONTROLS[ $name ] ) ? self::CONTROLS[ $name ] : 'other';
		if ( 'innerContent' === $source && ! in_array( $control, self::CONTENT_CONTROLS, true ) ) {
			return null;
		}

		if ( isset( $item['attrName'] ) && is_string( $item['attrName'] ) && '' !== $item['attrName'] ) {
			$path = $item['attrName'];
		} else {
			$path = 'advanced' === $source ? $attr . '.advanced.' . $group_key : $attr . '.innerContent';
		}
		$sub      = isset( $item['subName'] ) && is_string( $item['subName'] ) && '' !== $item['subName'] ? $item['subName'] : null;
		$segments = explode( '.', $path );
		$owner    = (string) $segments[0];
		$features = isset( $item['features'] ) && is_array( $item['features'] ) ? $item['features'] : array();
		$props    = isset( $component['props'] ) && is_array( $component['props'] ) ? $component['props'] : array();

		$options        = 'toggle' === $control ? array(
			'on'  => __( 'On', 'saddle' ),
			'off' => __( 'Off', 'saddle' ),
		) : self::normalize_options( isset( $props['options'] ) ? $props['options'] : null );
		$options_source = null;
		// Only choice controls get choices from elsewhere. A sortable list,
		// a gallery or a draggable list holds the agent's own items.
		if ( ! $options && ( 'select' === $control || 'divi/checkboxes' === $name ) ) {
			$options_source = self::runtime_source( $name, null !== $sub ? $sub : (string) end( $segments ) );
		}

		$default = Saddle_Divi_Setting_Values::default_value( $item, $path, $sub, $render, $defaults );
		$entry   = array(
			'path'           => $path,
			'attr'           => $owner,
			'key'            => null !== $sub ? $sub : (string) end( $segments ),
			'sub_name'       => $sub,
			'id'             => null !== $sub ? $path . '.' . $sub : $path,
			'source'         => 'advanced' === $source ? 'advanced' : 'content',
			'label'          => isset( $item['label'] ) ? (string) $item['label'] : '',
			'description'    => isset( $item['description'] ) ? (string) $item['description'] : '',
			'control'        => $control,
			'options'        => $options ? $options : null,
			'options_source' => $options_source,
			'default'        => $default,
			'responsive'     => ! isset( $features['responsive'] ) || false !== $features['responsive'],
			'hover'          => ! isset( $features['hover'] ) || false !== $features['hover'],
			'sticky'         => ! isset( $features['sticky'] ) || false !== $features['sticky'],
			'dynamic'        => ! empty( $features['dynamicContent'] ),
			'group'          => isset( $item['groupSlug'] ) ? (string) $item['groupSlug'] : '',
		);
		return array_merge( $entry, Saddle_Divi_Setting_Values::value_shape( $control, $default ) );
	}

	/**
	 * Divi's option maps come as value => {label}, a list of {value, label},
	 * or value => {icon}. Always value => label.
	 *
	 * @param mixed $raw props.options.
	 * @return array<string,string>
	 */
	private static function normalize_options( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value => $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) && is_scalar( $option['value'] ) ) {
				$out[ (string) $option['value'] ] = isset( $option['label'] ) ? (string) $option['label'] : (string) $option['value'];
			} elseif ( is_array( $option ) && isset( $option['label'] ) && is_scalar( $option['label'] ) ) {
				$out[ (string) $value ] = (string) $option['label'];
			} elseif ( is_string( $option ) ) {
				$out[ (string) $value ] = $option;
			} else {
				$out[ (string) $value ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Where a select with no options of its own gets them.
	 *
	 * @param string $component Component name.
	 * @param string $key       Setting key.
	 * @return string
	 */
	private static function runtime_source( $component, $key ) {
		if ( isset( self::RUNTIME_SOURCES[ $component ] ) ) {
			return self::RUNTIME_SOURCES[ $component ];
		}
		if ( in_array( $key, array( 'type', 'postType' ), true ) ) {
			return 'post_types';
		}
		if ( in_array( $key, array( 'categories', 'excludedCategories' ), true ) ) {
			return 'categories';
		}
		if ( 'targetLoop' === $key ) {
			return 'loops';
		}
		return 'other';
	}
}
