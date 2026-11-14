<?php
/**
 * Divi 5 design-system read abilities: the global palette, variables,
 * presets and site fonts.
 *
 * These read Divi's OWN design-system storage through Divi's native PHP APIs
 * (ET\Builder\Packages\GlobalData\GlobalData / GlobalPreset), never by
 * reading options by hand. Read-tier.
 *
 * Divi's runtime classes only exist when Divi 5 is active; every callback
 * guards for that and returns an actionable error otherwise.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the design-system read abilities. Hooked to `wp_abilities_api_init`
 * at priority 30 through saddle_register_ability_once(), so a site still
 * running an older add-on that registers the same names keeps that copy.
 */
function saddle_register_divi_design_abilities() {

	saddle_register_ability_once(
		'saddle/divi-list-global-colors',
		array(
			'label'               => __( 'List Divi global colors', 'saddle' ),
			'description'         => __( 'Lists the site\'s Divi global color palette — each entry\'s id (e.g. "gcid-…"), color value, and status. Read-only. Reference a global color in a module by its CSS variable, var(--<id>). Prefer these over hardcoded hex values so a palette change updates every page.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Design', 'list_global_colors' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-global-colors' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-get-global-fonts',
		array(
			'label'               => __( 'Get Divi global fonts', 'saddle' ),
			'description'         => __( 'Returns the site\'s Divi global heading and body font families. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Design', 'get_global_fonts' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-get-global-fonts' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-list-variables',
		array(
			'label'               => __( 'List Divi design variables', 'saddle' ),
			'description'         => __( 'Lists the site\'s Divi design variables (reusable tokens): each id ("gvid-…"), type (numbers, strings, colors, images, links, gradients, fonts), label, value, and "reference", the exact string to write in a module so a value change updates everywhere. Use "reference" as it is. A number is var(--<id>) in any size. A font, string, link or image is the token $variable({"type":"content","value":{"name":"<id>","settings":{}}})$: a font family written as var(--<id>) never loads the webfont. A color takes the "color" token and a gradient the "gradient" token, which goes in a background gradient\'s "stops". Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Design', 'list_variables' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-variables' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-list-global-presets',
		array(
			'label'               => __( 'List Divi global presets', 'saddle' ),
			'description'         => __( 'Lists the site\'s Divi style presets. kind "module" (the default) lists module presets, each styling a whole module of one type: {kind, module, id, name, is_default}; apply one with divi-apply-global-preset. kind "group" lists option group presets, each styling one option group (a button, a font, spacing, a border) on any module that has it: {kind, id, name, group_name (e.g. "divi/button"), group_id (the option group it was made on), module (the module it was made on), is_default}. kind "all" lists both. Read-only. Use the site\'s presets before styling by hand.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'kind'       => array(
						'type'        => 'string',
						'enum'        => array( 'module', 'group', 'all' ),
						'default'     => 'module',
						'description' => __( 'Which presets: "module" (default), "group" or "all".', 'saddle' ),
					),
					'module'     => array(
						'type'        => 'string',
						'description' => __( 'Optional: only presets for this module type; for option group presets, those whose group the module has (divi-get-module-schema detail="full" lists its option_groups).', 'saddle' ),
					),
					'group_name' => array(
						'type'        => 'string',
						'description' => __( 'Optional: only option group presets of this group, e.g. "divi/button".', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Design', 'list_global_presets' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-list-global-presets' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/divi-get-global-preset',
		array(
			'label'               => __( 'Get Divi global preset', 'saddle' ),
			'description'         => __( 'Returns one preset\'s full definition by its preset_id from divi-list-global-presets. A module preset: {kind, module, id, name, attrs}. An option group preset (kind "group"): {kind, id, name, group_name, group_id, module, is_default, attrs}, plus "use", the "groupPreset" entry a module\'s attrs take to point at it. Ids are unique, so module and kind only narrow the search. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'preset_id' ),
				'properties' => array(
					'preset_id' => array( 'type' => 'string' ),
					'kind'      => array(
						'type'        => 'string',
						'enum'        => array( 'module', 'group' ),
						'description' => __( 'Optional: "module" or "group". Omit to search module presets, then option group presets.', 'saddle' ),
					),
					'module'    => array(
						'type'        => 'string',
						'description' => __( 'Optional: the module type of a module preset.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Design', 'get_global_preset' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-get-global-preset' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Callbacks for the Divi design-system read abilities, and the read helpers
 * the context bundle and the design-system shape are built from.
 */
class Saddle_Divi_Design {

	/**
	 * The five built-in customizer colors, which Divi does not allow deleting.
	 */
	const FIXED_COLOR_IDS = array(
		'gcid-primary-color',
		'gcid-secondary-color',
		'gcid-heading-color',
		'gcid-body-color',
		'gcid-link-color',
	);

	/**
	 * The valid variable buckets an agent may create in.
	 */
	const VARIABLE_TYPES = array( 'numbers', 'strings', 'colors', 'images', 'links', 'gradients', 'fonts' );

	/**
	 * saddle/divi-list-global-colors.
	 *
	 * @return array|WP_Error
	 */
	public static function list_global_colors() {
		if ( ! self::global_data() ) {
			return self::unavailable();
		}

		$colors = self::deep_array( \ET\Builder\Packages\GlobalData\GlobalData::get_global_colors() );
		$out    = array();
		foreach ( $colors as $id => $entry ) {
			$out[] = array(
				'id'     => (string) $id,
				// Divi names its five built-in colors ("Primary Color"); a
				// custom one has no name in Divi 5.
				'label'  => isset( $entry['label'] ) ? (string) $entry['label'] : '',
				'color'  => isset( $entry['color'] ) ? (string) $entry['color'] : '',
				'status' => isset( $entry['status'] ) ? (string) $entry['status'] : 'active',
				'var'    => Saddle_Divi::css_var( $id ),
				'fixed'  => in_array( (string) $id, self::FIXED_COLOR_IDS, true ),
			);
		}

		return array(
			'colors' => $out,
			'count'  => count( $out ),
		);
	}

	/**
	 * saddle/divi-get-global-fonts.
	 *
	 * @return array|WP_Error
	 */
	public static function get_global_fonts() {
		if ( ! function_exists( 'et_get_option' ) ) {
			return self::unavailable();
		}
		return array(
			'heading_font' => (string) et_get_option( 'heading_font', 'Open Sans' ),
			'body_font'    => (string) et_get_option( 'body_font', 'Open Sans' ),
		);
	}

	/**
	 * saddle/divi-list-variables.
	 *
	 * @return array|WP_Error
	 */
	public static function list_variables() {
		if ( ! self::global_data() ) {
			return self::unavailable();
		}

		$data = self::deep_array( \ET\Builder\Packages\GlobalData\GlobalData::get_global_variables() );
		$out  = array();
		foreach ( $data as $type => $bucket ) {
			foreach ( (array) $bucket as $id => $entry ) {
				$entry = (array) $entry;
				$out[] = array(
					'id'        => (string) $id,
					'type'      => (string) $type,
					'label'     => isset( $entry['label'] ) ? (string) $entry['label'] : '',
					'value'     => isset( $entry['value'] ) ? (string) $entry['value'] : '',
					'status'    => isset( $entry['status'] ) ? (string) $entry['status'] : 'active',
					'var'       => Saddle_Divi::css_var( $id ),
					'reference' => self::reference( (string) $type, (string) $id ),
				);
			}
		}

		return array(
			'variables' => $out,
			'count'     => count( $out ),
		);
	}

	/**
	 * How a module refers to a variable of a type, the same string another
	 * plugin returns when it creates one. Divi resolves its $variable token
	 * for every type; only that token loads a font's webfont and puts a
	 * string, link or image into content, a gradient token goes in a
	 * background gradient's "stops", and a number works as var(--<id>) in
	 * any size (Divi 5.10.1 DynamicContentGlobalVariableOptions,
	 * StyleLibrary Utils::resolve_dynamic_variable(), read in source).
	 *
	 * @param string $type Variable bucket.
	 * @param string $id   Variable id.
	 * @return string
	 */
	public static function reference( $type, $id ) {
		if ( 'numbers' === $type ) {
			return Saddle_Divi::css_var( $id );
		}
		$kind = 'colors' === $type ? 'color' : ( 'gradients' === $type ? 'gradient' : 'content' );
		return '$variable(' . wp_json_encode(
			array(
				'type'  => $kind,
				'value' => array(
					'name'     => (string) $id,
					'settings' => new stdClass(),
				),
			)
		) . ')$';
	}

	/**
	 * saddle/divi-list-global-presets.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_global_presets( $input ) {
		if ( ! self::global_preset() ) {
			return self::unavailable();
		}
		return self::presets_from( self::deep_array( \ET\Builder\Packages\GlobalData\GlobalPreset::get_data() ), is_array( $input ) ? $input : array() );
	}

	/**
	 * saddle/divi-get-global-preset.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_global_preset( $input ) {
		if ( ! self::global_preset() ) {
			return self::unavailable();
		}
		return self::preset_from( self::deep_array( \ET\Builder\Packages\GlobalData\GlobalPreset::get_data() ), is_array( $input ) ? $input : array() );
	}

	/**
	 * The preset list from Divi's stored presets: {module: {<type>:
	 * {default, items}}, group: {<group name>: {default, items}}}
	 * (GlobalPreset::prepare_data()). Module rows keep the shape they always
	 * had, plus "kind".
	 *
	 * @param array $data  Stored presets, as arrays.
	 * @param array $input {kind, module, group_name}.
	 * @return array
	 */
	public static function presets_from( array $data, array $input ) {
		$kind   = isset( $input['kind'] ) && in_array( $input['kind'], array( 'group', 'all' ), true ) ? $input['kind'] : 'module';
		$module = isset( $input['module'] ) ? trim( (string) $input['module'] ) : '';
		$group  = isset( $input['group_name'] ) ? trim( (string) $input['group_name'] ) : '';

		// For option group presets, "module" means the groups that module has.
		$usable = '' !== $module && 'module' !== $kind ? array_values( array_unique( Saddle_Divi_Option_Groups::of_module( $module ) ) ) : null;

		$out = array();
		foreach ( self::rows( $data ) as $row ) {
			if ( 'all' !== $kind && $kind !== $row['kind'] ) {
				continue;
			}
			if ( 'module' === $row['kind'] && '' !== $module && $module !== $row['module'] ) {
				continue;
			}
			if ( 'group' === $row['kind'] && ( ( '' !== $group && $group !== $row['group_name'] ) || ( null !== $usable && ! in_array( $row['group_name'], $usable, true ) ) ) ) {
				continue;
			}
			unset( $row['attrs'] );
			$out[] = $row;
		}

		return array(
			'presets' => $out,
			'count'   => count( $out ),
		);
	}

	/**
	 * One preset from Divi's stored presets, found by id (ids are unique
	 * across both kinds), narrowed by kind or module when given.
	 *
	 * @param array $data  Stored presets, as arrays.
	 * @param array $input {preset_id, kind, module}.
	 * @return array|WP_Error
	 */
	public static function preset_from( array $data, array $input ) {
		$id     = isset( $input['preset_id'] ) ? trim( (string) $input['preset_id'] ) : '';
		$kind   = isset( $input['kind'] ) ? (string) $input['kind'] : '';
		$module = isset( $input['module'] ) ? trim( (string) $input['module'] ) : '';

		foreach ( self::rows( $data ) as $row ) {
			if ( '' === $id || $id !== $row['id'] || ( '' !== $kind && $kind !== $row['kind'] ) || ( 'module' === $row['kind'] && '' !== $module && $module !== $row['module'] ) ) {
				continue;
			}
			if ( 'module' === $row['kind'] ) {
				return array(
					'kind'   => 'module',
					'module' => $row['module'],
					'id'     => $id,
					'name'   => $row['name'],
					'attrs'  => $row['attrs'],
				);
			}
			$row['use']  = array(
				'groupPreset' => array(
					( '' !== $row['group_id'] ? $row['group_id'] : '<option group id>' ) => array(
						'presetId'  => array( $id ),
						'groupName' => $row['group_name'],
					),
				),
			);
			$row['note'] = __( 'A module points at an option group preset through "groupPreset" in its attrs, keyed by its own option group id ("use" shows the id this preset was made on; another module type may name the group differently: its option_groups in divi-get-module-schema detail="full"). Several ids in "presetId" stack; the last one wins.', 'saddle' );
			return $row;
		}
		return new WP_Error( 'saddle_preset_not_found', __( 'No preset with that id. Use divi-list-global-presets.', 'saddle' ) );
	}

	/**
	 * Every stored preset as one row, module presets first.
	 *
	 * @param array $data Stored presets, as arrays.
	 * @return array[]
	 */
	private static function rows( array $data ) {
		$rows = array();
		foreach ( array( 'module', 'group' ) as $type ) {
			foreach ( isset( $data[ $type ] ) && is_array( $data[ $type ] ) ? $data[ $type ] : array() as $name => $bucket ) {
				$default = isset( $bucket['default'] ) ? (string) $bucket['default'] : '';
				foreach ( isset( $bucket['items'] ) && is_array( $bucket['items'] ) ? $bucket['items'] : array() as $id => $item ) {
					$item   = is_array( $item ) ? $item : array();
					$common = array(
						'id'   => (string) $id,
						'name' => isset( $item['name'] ) ? (string) $item['name'] : '',
					);
					$rows[] = 'module' === $type
						? array(
							'kind'   => 'module',
							'module' => (string) $name,
						) + $common + array(
							'is_default' => ( (string) $id === $default ),
							'attrs'      => isset( $item['attrs'] ) ? $item['attrs'] : array(),
						)
						: array( 'kind' => 'group' ) + $common + array(
							'group_name' => (string) $name,
							'group_id'   => isset( $item['groupId'] ) ? (string) $item['groupId'] : '',
							'module'     => isset( $item['moduleName'] ) ? (string) $item['moduleName'] : '',
							'is_default' => ( (string) $id === $default ),
							'attrs'      => isset( $item['attrs'] ) ? $item['attrs'] : array(),
						);
				}
			}
		}
		return $rows;
	}

	/**
	 * Deep-cast a GlobalData / GlobalPreset structure to nested associative
	 * arrays.
	 *
	 * Divi 5 stores these as JSON and its getters return the raw decode, so
	 * nested levels arrive as stdClass trees on a real install (a shallow
	 * `(array)` cast only converts the top level). Every reader and writer in
	 * this file assumes arrays; normalizing here is what keeps edits/deletes
	 * finding entries the Visual Builder wrote — and keeps creates from
	 * clobbering an existing stdClass bucket.
	 *
	 * @param mixed $data Raw structure from GlobalData/GlobalPreset.
	 * @return array
	 */
	public static function deep_array( $data ) {
		$decoded = json_decode( wp_json_encode( $data ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Whether Divi 5's GlobalPreset API is available.
	 *
	 * @return bool
	 */
	private static function global_preset() {
		return class_exists( '\ET\Builder\Packages\GlobalData\GlobalPreset' );
	}

	/**
	 * Whether Divi 5's GlobalData API is available (Divi 5 active).
	 *
	 * @return bool
	 */
	private static function global_data() {
		return class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' );
	}

	/**
	 * Uniform "Divi 5 API not available" error.
	 *
	 * @return WP_Error
	 */
	private static function unavailable() {
		return new WP_Error(
			'saddle_divi_api_unavailable',
			__( 'The Divi 5 design-system API is not available (Divi 5 is not active). Use divi-check-setup.', 'saddle' ),
			array( 'status' => 400 )
		);
	}
}
