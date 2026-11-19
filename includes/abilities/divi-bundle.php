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
			'description'         => __( 'Returns this site\'s whole Divi working memory in ONE call: the module catalog in short form (each module\'s type, title and group, and what a container holds, with installed module packs), the design system (global colors, variables, presets, fonts), the site\'s brand basics, the authoring conventions, and "site_style": how the site\'s own pages are styled (the colors, section padding, type sizes, fonts, buttons, cards and presets they use most, each with a count and its global color token), from up to 6 published pages you can read. Call this ONCE at the start of a session instead of divi-list-global-colors + divi-list-variables + divi-get-global-fonts separately; call divi-list-modules when you need each module\'s purpose or a search, and divi-get-site-style for longer style lists. Cached and cheap; the "version" changes when anything but "site_style" does. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Bundle_Abilities', 'context_bundle' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-context-bundle' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	// The tool gate is read; each page read is then checked for the caller
	// in the callback (Saddle_Divi_Module_Examples::readable()): read_post,
	// edit_post for a type visitors cannot view, and a password is never
	// bypassed.
	saddle_register_ability_once(
		'saddle/divi-get-site-style',
		array(
			'label'               => __( 'Get the Divi site style', 'saddle' ),
			'description'         => __( 'Returns how this site\'s own Divi pages are styled, read from up to 6 published Divi pages you can read (the front page first, then the most recently edited): the colors used for section backgrounds, card backgrounds, headings, body text, button fills and button text; section padding (top and bottom) and row width; heading sizes per level and body size; fonts and weights; button radius, padding and whether hover changes them; card radius, shadow and padding; and the presets used most, with their names. Each list gives the values most used first, with how many times each is used. "tokens" names the global color a color matches, so you write the token instead of the hex. "default" means nothing is set, so Divi\'s own value shows. A "@phone" list appears when phones get other values than desktop. A value set only through a preset is not read; the presets are counted instead. Use it before designing a page, so the page matches the site; then read one page with divi-get-page only if you need more. divi-context-bundle carries the same summary with shorter lists. Cached until a Divi page is saved. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Divi_Site_Style', 'tool' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-get-site-style' ),
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
		// The bundle's cache is shared by every account; the style summary
		// depends on the pages this account may read, so it is added here.
		$bundle               = Saddle_Divi_Bundle::get();
		$bundle['site_style'] = Saddle_Divi_Site_Style::for_bundle();
		return $bundle;
	}
}
