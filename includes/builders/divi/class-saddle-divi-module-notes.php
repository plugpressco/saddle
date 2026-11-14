<?php
/**
 * Known traps per Divi 5 module, shown in its schema.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A short hand-kept list of what goes wrong with a module when an agent
 * only reads its module.json. Every note was read in Divi 5.10.1's server
 * source (the file is named beside each); none was confirmed by rendering
 * a page. Other plugins add notes for their own modules through the
 * `saddle_divi_module_notes` filter.
 */
class Saddle_Divi_Module_Notes {

	/**
	 * The notes for one module type.
	 *
	 * @param string $type Module type, e.g. 'divi/menu'.
	 * @return string[]
	 */
	public static function for_module( $type ) {
		$all   = self::all();
		$notes = isset( $all[ $type ] ) ? $all[ $type ] : array();

		/**
		 * Filter the traps shown in a Divi module's schema.
		 *
		 * @param string[] $notes One plain sentence or two per note.
		 * @param string   $type  Module type.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'saddle_divi_module_notes', $notes, (string) $type ) ) ) );
	}

	/**
	 * Every built-in note, by module type.
	 *
	 * @return array<string,string[]>
	 */
	private static function all() {
		// MenuUtils.php: no menuId, then the primary-menu location, then a list of pages and categories.
		$menu = __( 'Choose a menu in menu.advanced.menuId (a nav menu id). With none chosen, Divi shows the menu in the theme\'s primary-menu location. When that, or the chosen menu, gives nothing, it lists every page and category instead.', 'saddle' );
		// Text classnames: color "light" is the class for a light background, which Divi labels "Dark" text.
		$text = __( 'Text colour: module.advanced.text.text.desktop.value.color = "light" gives DARK text (for a light background) and "dark" gives LIGHT text. This module starts on "light", so on a dark background set "dark".', 'saddle' );
		// MapModule.php reads et_pb_get_google_api_key(), the site-wide key Divi's own option text says the map requires.
		$map = __( 'Maps need a Google Maps API key, saved once for the whole site in Divi Theme Options, General, Google API Key. The map\'s height is module.decoration.sizing height, 440px by default. Pins are divi/map-pin children with pin = { address, zoom, lat, lng }.', 'saddle' );

		return array(
			'divi/menu'             => array( $menu ),
			'divi/fullwidth-menu'   => array( $menu ),
			'divi/blog'             => array(
				$text,
				// BlogModule.php and ModuleUtils::add_category_query_args().
				__( 'post.advanced.categories is a list of category ids; an empty list or "all" means every category, "current" means the category being viewed.', 'saddle' ),
			),
			'divi/map'              => array( $map ),
			'divi/fullwidth-map'    => array( $map ),
			// SliderModule.php adds et_pb_slider_empty when there are no children.
			'divi/slider'           => array( __( 'The slides are its divi/slide children. With none, the slider renders empty.', 'saddle' ) ),
			'divi/fullwidth-slider' => array( __( 'The slides are its divi/slide children. With none, the slider renders empty.', 'saddle' ) ),
			'divi/accordion'        => array( __( 'The panels are its divi/accordion-item children, each with a title and content.', 'saddle' ) ),
			'divi/tabs'             => array( __( 'The tabs are its divi/tab children, each with a title and content.', 'saddle' ) ),
			'divi/contact-form'     => array(
				__( 'The form fields are its divi/contact-field children.', 'saddle' ),
				// ContactFormHandler.php: email.advanced.receiver, else the site admin email.
				__( 'Messages go to email.advanced.receiver. Left empty, they go to the site admin email.', 'saddle' ),
			),
			'divi/pricing-tables'   => array( __( 'Each plan is a divi/pricing-table child.', 'saddle' ) ),
			'divi/icon'             => array( __( 'icon is an object { unicode, type, weight }, e.g. { "unicode": "&#x21;", "type": "divi", "weight": "400" }, not a string or an emoji.', 'saddle' ) ),
			'divi/button'           => array( __( 'Spacing and box shadow on the button element are hidden in Divi; put padding, margin and shadow on module.decoration instead. Alignment is module.advanced.alignment.', 'saddle' ) ),
			'divi/group-carousel'   => array( __( 'Its slides are divi/group children; each group holds the slide\'s modules.', 'saddle' ) ),
			'divi/sidebar'          => array( __( 'Pick the widget area in content: fields.sidebar = { "area": "sidebar-1" }, a registered widget area id. The widgets come from Appearance, Widgets, not from the page.', 'saddle' ) ),
		);
	}
}
