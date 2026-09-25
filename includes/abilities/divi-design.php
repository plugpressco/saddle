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
				'properties' => (object) array(),
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
				'properties' => (object) array(),
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
			'description'         => __( 'Lists the site\'s Divi design variables (reusable tokens) — each id ("gvid-…"), type (numbers, strings, colors, images, links, gradients), label, and value. Read-only. Reference one in a module as var(--<id>) so a value change updates everywhere.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
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
			'description'         => __( 'Lists the site\'s Divi module style presets — for each: the module type it applies to, the preset id, name, and whether it is that module\'s default. Read-only. Apply one to a module with divi-apply-global-preset.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'module' => array(
						'type'        => 'string',
						'description' => __( 'Optional: only presets for this module type.', 'saddle' ),
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
			'description'         => __( 'Returns one preset\'s full definition (name and style attrs) for a module type and preset id. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'module', 'preset_id' ),
				'properties' => array(
					'module'    => array( 'type' => 'string' ),
					'preset_id' => array( 'type' => 'string' ),
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
	const VARIABLE_TYPES = array( 'numbers', 'strings', 'colors', 'images', 'links', 'gradients' );

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
					'id'     => (string) $id,
					'type'   => (string) $type,
					'label'  => isset( $entry['label'] ) ? (string) $entry['label'] : '',
					'value'  => isset( $entry['value'] ) ? (string) $entry['value'] : '',
					'status' => isset( $entry['status'] ) ? (string) $entry['status'] : 'active',
					'var'    => Saddle_Divi::css_var( $id ),
				);
			}
		}

		return array(
			'variables' => $out,
			'count'     => count( $out ),
		);
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
		$filter = isset( $input['module'] ) ? trim( (string) $input['module'] ) : '';

		$data    = self::deep_array( \ET\Builder\Packages\GlobalData\GlobalPreset::get_data() );
		$modules = isset( $data['module'] ) && is_array( $data['module'] ) ? $data['module'] : array();

		$out = array();
		foreach ( $modules as $module_type => $group ) {
			if ( '' !== $filter && $filter !== $module_type ) {
				continue;
			}
			$default = isset( $group['default'] ) ? (string) $group['default'] : '';
			foreach ( ( isset( $group['items'] ) && is_array( $group['items'] ) ? $group['items'] : array() ) as $id => $item ) {
				$out[] = array(
					'module'     => (string) $module_type,
					'id'         => (string) $id,
					'name'       => isset( $item['name'] ) ? (string) $item['name'] : '',
					'is_default' => ( (string) $id === $default ),
				);
			}
		}

		return array(
			'presets' => $out,
			'count'   => count( $out ),
		);
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
		$module = isset( $input['module'] ) ? trim( (string) $input['module'] ) : '';
		$id     = isset( $input['preset_id'] ) ? trim( (string) $input['preset_id'] ) : '';

		$data = self::deep_array( \ET\Builder\Packages\GlobalData\GlobalPreset::get_data() );
		$item = $data['module'][ $module ]['items'][ $id ] ?? null;
		if ( ! $item ) {
			return new WP_Error( 'saddle_preset_not_found', __( 'No preset with that module + id. Use divi-list-global-presets.', 'saddle' ) );
		}

		return array(
			'module' => $module,
			'id'     => $id,
			'name'   => isset( $item['name'] ) ? (string) $item['name'] : '',
			'attrs'  => isset( $item['attrs'] ) ? $item['attrs'] : array(),
		);
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
