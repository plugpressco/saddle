<?php
/**
 * saddle/divi-context-bundle — the site's whole Divi working memory in one
 * cached read.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Divi context-bundle ability. Hooked to `wp_abilities_api_init`
 * at priority 30 through saddle_register_ability_once(), so a site still
 * running an older add-on that registers the same name keeps that copy.
 */
function saddle_register_divi_bundle_abilities() {

	saddle_register_ability_once(
		'saddle/divi-context-bundle',
		array(
			'label'               => __( 'Get the Divi context bundle', 'saddle' ),
			'description'         => __( 'Returns this site\'s whole Divi working memory in ONE call: the full module catalog (with installed module packs), the design system (global colors, variables, presets, fonts), the site\'s brand basics, and the authoring conventions. Call this ONCE at the start of a session instead of divi-list-modules + divi-list-global-colors + divi-list-variables + divi-get-global-fonts separately. Cached and cheap; the "version" changes when anything in it does. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Bundle_Abilities', 'context_bundle' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-context-bundle' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Execute callback for the Divi context bundle.
 */
class Saddle_Divi_Bundle_Abilities {

	/**
	 * saddle/divi-context-bundle.
	 *
	 * @return array
	 */
	public static function context_bundle() {
		if ( ! Saddle_Divi::is_active() ) {
			return new WP_Error( 'saddle_no_divi', __( 'Divi 5 is not active on this site.', 'saddle' ) );
		}
		return Saddle_Divi_Bundle::get();
	}
}
