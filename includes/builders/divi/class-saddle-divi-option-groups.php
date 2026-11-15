<?php
/**
 * Which option groups a Divi 5 module has, for option group presets.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A module's option groups as Divi keys them for option group presets:
 * group id (the module's host path, e.g. "button.decoration.button") =>
 * group name (Divi's presetGroup, e.g. "divi/button").
 *
 * A port of GlobalPreset::get_group_preset_default_attr() and
 * get_default_group_name() in Divi 5.10.1, read from the module's
 * module.json (source reading; nothing rendered). A module points at the
 * presets it uses at the top level of its attrs:
 *
 *   "groupPreset": { "<group id>": { "presetId": ["<id>", …], "groupName": "<group name>" } }
 *
 * Another plugin can write option group presets with the same map.
 */
class Saddle_Divi_Option_Groups {

	/**
	 * Divi's fallback group names by settings group (get_default_group_name()).
	 */
	const DEFAULT_NAMES = array(
		'decoration' => array(
			'animation'   => 'divi/animation',
			'background'  => 'divi/background',
			'bodyFont'    => 'divi/font-body',
			'border'      => 'divi/border',
			'boxShadow'   => 'divi/box-shadow',
			'button'      => 'divi/button',
			'conditions'  => 'divi/conditions',
			'disabledOn'  => 'divi/disabled-on',
			'filters'     => 'divi/filters',
			'font'        => 'divi/font',
			'headingFont' => 'divi/font-header',
			'image'       => 'divi/image',
			'layout'      => 'divi/layout',
			'overflow'    => 'divi/overflow',
			'position'    => 'divi/position',
			'scroll'      => 'divi/scroll',
			'sizing'      => 'divi/sizing',
			'spacing'     => 'divi/spacing',
			'sticky'      => 'divi/sticky',
			'transform'   => 'divi/transform',
			'transition'  => 'divi/transition',
			'zIndex'      => 'divi/z-index',
		),
		'advanced'   => array(
			'html'           => 'divi/html',
			'htmlAttributes' => 'divi/id-classes',
			'loop'           => 'divi/loop',
			'text'           => 'divi/text',
		),
	);

	/**
	 * A module's option groups, from its module.json on this site.
	 *
	 * @param string $module Module type.
	 * @return array<string,string> Group id => group name; empty when unknown.
	 */
	public static function of_module( $module ) {
		$json = Saddle_Divi_Schema::module_json( (string) $module );
		return is_array( $json ) ? self::from_json( $json ) : array();
	}

	/**
	 * The option group map from a module.json.
	 *
	 * @param array $json Parsed module.json.
	 * @return array<string,string>
	 */
	public static function from_json( array $json ) {
		$out       = array();
		$composite = isset( $json['settings']['groups'] ) && is_array( $json['settings']['groups'] ) ? $json['settings']['groups'] : array();

		foreach ( ( isset( $json['attributes'] ) && is_array( $json['attributes'] ) ) ? $json['attributes'] : array() as $attr => $attribute ) {
			$settings = ( is_array( $attribute ) && isset( $attribute['settings'] ) && is_array( $attribute['settings'] ) ) ? $attribute['settings'] : array();
			foreach ( array( 'decoration', 'advanced' ) as $type ) {
				foreach ( ( isset( $settings[ $type ] ) && is_array( $settings[ $type ] ) ) ? $settings[ $type ] : array() as $group => $config ) {
					$config = is_array( $config ) ? $config : array();
					if ( isset( $config['groupType'] ) && 'group-items' === $config['groupType'] ) {
						$out = array_merge( $out, self::group_items( $config, $type, (string) $group ) );
						continue;
					}
					$name = self::group_name( $config, $type, (string) $group, $composite );
					if ( null !== $name && '' !== $name ) {
						$out[ $attr . '.' . $type . '.' . $group ] = $name;
					}
				}
			}
		}

		foreach ( $composite as $group => $config ) {
			if ( ! is_array( $config ) || ! self::composite_supported( $config ) ) {
				continue;
			}
			$props = isset( $config['component']['props'] ) && is_array( $config['component']['props'] ) ? $config['component']['props'] : array();
			$name  = isset( $props['presetGroup'] ) ? (string) $props['presetGroup'] : '';
			$id    = ( isset( $props['attrName'] ) && is_string( $props['attrName'] ) && '' !== $props['attrName'] ) ? $props['attrName'] : (string) $group;
			if ( '' === $name && self::uses_component_name( $config ) ) {
				$name = (string) $config['component']['name'];
			}
			if ( '' !== $name ) {
				$out[ $id ] = $name;
			}
		}

		return $out;
	}

	/**
	 * Group name for one decoration or advanced group (the non-list kinds).
	 *
	 * @param array  $config    Group config.
	 * @param string $type      decoration | advanced.
	 * @param string $group     Group key.
	 * @param array  $composite settings.groups of the module.
	 * @return string|null Null when Divi skips the group.
	 */
	private static function group_name( array $config, $type, $group, array $composite ) {
		$props = isset( $config['component']['props'] ) && is_array( $config['component']['props'] ) ? $config['component']['props'] : array();
		$kind  = isset( $config['groupType'] ) ? (string) $config['groupType'] : '';
		$name  = '';

		if ( ! empty( $props['presetGroup'] ) ) {
			$name = (string) $props['presetGroup'];
		} elseif ( ! $config || '' === $kind ) {
			$name = self::default_name( $type, $group );
		} elseif ( 'group' === $kind ) {
			if ( isset( $props['grouped'] ) && false === $props['grouped'] ) {
				return null;
			}
			$name = isset( $config['groupName'] ) ? (string) $config['groupName'] : self::default_name( $type, $group );
		} elseif ( 'group-item' === $kind ) {
			$item = isset( $config['item'] ) && is_array( $config['item'] ) ? $config['item'] : array();
			$slug = isset( $item['groupSlug'] ) ? (string) $item['groupSlug'] : '';
			if ( '' !== $slug && isset( $composite[ $slug ] ) && is_array( $composite[ $slug ] ) && self::composite_supported( $composite[ $slug ] ) ) {
				$cprops = isset( $composite[ $slug ]['component']['props'] ) ? (array) $composite[ $slug ]['component']['props'] : array();
				if ( ! empty( $cprops['presetGroup'] ) ) {
					$name = (string) $cprops['presetGroup'];
				} elseif ( self::uses_component_name( $composite[ $slug ] ) ) {
					$name = (string) $composite[ $slug ]['component']['name'];
				}
			}
			if ( '' === $name ) {
				$name = self::grouped_component_name( isset( $item['component'] ) && is_array( $item['component'] ) ? $item['component'] : array() );
			}
		}

		return '' !== $name ? $name : self::default_name( $type, $group );
	}

	/**
	 * Entries of a "group-items" group: each item's own attr name.
	 *
	 * @param array  $config Group config.
	 * @param string $type   decoration | advanced.
	 * @param string $group  Group key.
	 * @return array<string,string>
	 */
	private static function group_items( array $config, $type, $group ) {
		$out = array();
		foreach ( ( isset( $config['items'] ) && is_array( $config['items'] ) ) ? $config['items'] : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$component = isset( $item['component'] ) && is_array( $item['component'] ) ? $item['component'] : array();
			$attr      = ! empty( $item['attrName'] ) ? (string) $item['attrName'] : ( ! empty( $component['props']['attrName'] ) ? (string) $component['props']['attrName'] : '' );
			if ( '' === $attr ) {
				continue;
			}
			$name = ! empty( $component['props']['presetGroup'] ) ? (string) $component['props']['presetGroup'] : self::grouped_component_name( $component );
			$name = '' !== $name ? $name : self::default_name( $type, $group );
			if ( '' !== $name ) {
				$out[ $attr ] = $name;
			}
		}
		return $out;
	}

	/**
	 * A component's name when it is a grouped "group" component.
	 *
	 * @param array $component Component config.
	 * @return string
	 */
	private static function grouped_component_name( array $component ) {
		$grouped = ! isset( $component['props']['grouped'] ) || false !== $component['props']['grouped'];
		return ( isset( $component['type'] ) && 'group' === $component['type'] && $grouped && ! empty( $component['name'] ) ) ? (string) $component['name'] : '';
	}

	/**
	 * Divi's fallback group name.
	 *
	 * @param string $type  decoration | advanced.
	 * @param string $group Group key.
	 * @return string
	 */
	private static function default_name( $type, $group ) {
		return isset( self::DEFAULT_NAMES[ $type ][ $group ] ) ? self::DEFAULT_NAMES[ $type ][ $group ] : '';
	}

	/**
	 * Whether Divi reads presets from a settings.groups entry.
	 *
	 * @param array $config Group config.
	 * @return bool
	 */
	private static function composite_supported( array $config ) {
		$name = isset( $config['component']['name'] ) ? (string) $config['component']['name'] : '';
		return 'divi/composite' === $name || self::uses_component_name( $config );
	}

	/**
	 * Whether a settings.groups entry names its preset group after its component.
	 *
	 * @param array $config Group config.
	 * @return bool
	 */
	private static function uses_component_name( array $config ) {
		$name = isset( $config['component']['name'] ) ? (string) $config['component']['name'] : '';
		if ( ! empty( $config['component']['props']['useComponentNameAsPresetGroup'] ) && true === $config['component']['props']['useComponentNameAsPresetGroup'] ) {
			return true;
		}
		return in_array( $name, array( 'divi/form-field', 'divi/checkbox', 'divi/checkboxes', 'divi/radio', 'divi/radios' ), true );
	}
}
