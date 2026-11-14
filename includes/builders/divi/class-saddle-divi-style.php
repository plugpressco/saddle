<?php
/**
 * What each Divi 5 decoration group holds, and how a value is written.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The hand-kept style vocabulary behind divi-get-style-schema.
 *
 * module.json names the decoration groups a module supports, never the value
 * keys inside them: those live in Divi's style declarations. Every field list
 * below was read from Divi 5.10.1's server source (the group's
 * `*PresetAttrsMap.php` sub-names and its `StyleLibrary/Declarations` reader);
 * nothing here was confirmed by rendering a page. Examples are JSON so they
 * read exactly as an agent writes them.
 */
class Saddle_Divi_Style {

	/**
	 * Top-level keys inside a group's value, per decoration group. The echo
	 * judges leaf keys only for CLOSED_GROUPS, so a closed group's list must
	 * stay the complete set of top-level keys.
	 */
	const FIELDS = array(
		'background'   => array( 'color', 'gradient', 'image', 'video', 'pattern', 'mask' ),
		'border'       => array( 'radius', 'styles' ),
		'spacing'      => array( 'margin', 'padding' ),
		'sizing'       => array( 'width', 'maxWidth', 'minWidth', 'height', 'minHeight', 'maxHeight', 'alignment', 'alignSelf', 'flexType', 'size', 'flexGrow', 'flexShrink', 'aspectRatio', 'gridColumnSpan', 'gridColumnStart', 'gridColumnEnd', 'gridRowSpan', 'gridRowStart', 'gridRowEnd', 'gridAlignSelf', 'gridJustifySelf' ),
		'boxShadow'    => array( 'style', 'horizontal', 'vertical', 'blur', 'spread', 'color', 'position' ),
		'filters'      => array( 'hueRotate', 'saturate', 'brightness', 'contrast', 'invert', 'sepia', 'opacity', 'blur', 'blendMode' ),
		'transform'    => array( 'scale', 'translate', 'rotate', 'skew', 'origin' ),
		'font'         => array( 'family', 'weight', 'weightFineTune', 'opticalSizing', 'variationSettings', 'style', 'capitalization', 'lineColor', 'lineStyle', 'lineThickness', 'underlineOffset', 'color', 'size', 'letterSpacing', 'lineHeight', 'textAlign', 'textWrap', 'writingMode', 'hyphens', 'columnCount', 'columnGap', 'columnRuleColor', 'columnRuleStyle', 'columnRuleWidth', 'headingLevel' ),
		'headingFont'  => array( 'family', 'weight', 'style', 'capitalization', 'color', 'size', 'letterSpacing', 'lineHeight', 'textAlign' ),
		'bodyFont'     => array( 'family', 'weight', 'style', 'capitalization', 'color', 'size', 'letterSpacing', 'lineHeight', 'textAlign' ),
		'textShadow'   => array( 'style', 'horizontal', 'vertical', 'blur', 'color' ),
		'textEffects'  => array( 'fillType', 'gradient', 'imageFill', 'strokeWidth', 'strokeColor', 'strokePosition' ),
		'position'     => array( 'mode', 'origin', 'offset' ),
		'overflow'     => array( 'x', 'y' ),
		'zIndex'       => array(),
		'animation'    => array( 'style', 'direction', 'duration', 'delay', 'intensity', 'startingOpacity', 'speedCurve', 'repeat' ),
		'transition'   => array( 'duration', 'delay', 'speedCurve' ),
		'layout'       => array( 'display', 'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'alignContent', 'columnGap', 'rowGap', 'collapseEmptyColumns', 'gridColumnCount', 'gridColumnWidth', 'gridColumnWidths', 'gridColumnMinWidth', 'gridRowCount', 'gridRowHeight', 'gridRowHeights', 'gridRowMinHeight', 'gridTemplateColumns', 'gridTemplateRows', 'gridAutoFlow', 'gridAutoColumns', 'gridAutoRows', 'gridDensity', 'gridJustifyItems', 'gridOffsetRules' ),
		// The fill switch Divi renders ghost buttons without (live-verified).
		'button'       => array( 'enable', 'alignment', 'icon' ),
		'icon'         => array( 'color', 'unicode', 'type', 'weight', 'size', 'useSize', 'show' ),
		'fit'          => array( 'objectFit', 'objectPosition' ),
		'sticky'       => array( 'position', 'offset', 'limit', 'transition' ),
		'scroll'       => array( 'motionTriggerStart', 'verticalMotion', 'horizontalMotion', 'fade', 'scaling', 'rotating', 'blur' ),
		'interactions' => array( 'interactions' ),
		'attributes'   => array( 'attributes' ),
		'disabledOn'   => array(),
		'order'        => array( 'order' ),
	);

	/**
	 * Keys one level further down, for groups whose values are objects.
	 */
	const SUB_FIELDS = array(
		'background'   => array( 'gradient.enabled', 'gradient.type', 'gradient.direction', 'gradient.directionRadial', 'gradient.stops', 'gradient.repeat', 'gradient.length', 'gradient.overlaysImage', 'image.url', 'image.size', 'image.width', 'image.height', 'image.position', 'image.horizontalOffset', 'image.verticalOffset', 'image.repeat', 'image.blend', 'image.parallax.enabled', 'image.parallax.method', 'video.mp4', 'video.webm', 'video.width', 'video.height', 'video.allowPlayerPause', 'video.pauseOutsideViewport', 'pattern.enabled', 'pattern.style', 'pattern.color', 'pattern.size', 'pattern.repeat', 'pattern.blend', 'mask.enabled', 'mask.style', 'mask.color', 'mask.size', 'mask.position', 'mask.blend' ),
		'border'       => array( 'radius.sync', 'radius.topLeft', 'radius.topRight', 'radius.bottomRight', 'radius.bottomLeft', 'styles.all.width', 'styles.all.color', 'styles.all.style', 'styles.top', 'styles.right', 'styles.bottom', 'styles.left' ),
		'spacing'      => array( 'margin.top', 'margin.right', 'margin.bottom', 'margin.left', 'padding.top', 'padding.right', 'padding.bottom', 'padding.left' ),
		'sizing'       => array( 'aspectRatio.width', 'aspectRatio.height' ),
		'transform'    => array( 'scale.x', 'scale.y', 'translate.x', 'translate.y', 'rotate.x', 'rotate.y', 'rotate.z', 'skew.x', 'skew.y', 'origin.x', 'origin.y' ),
		'textEffects'  => array( 'gradient.type', 'gradient.direction', 'gradient.stops', 'imageFill.url', 'imageFill.size', 'imageFill.position', 'imageFill.repeat' ),
		'position'     => array( 'origin.relative', 'origin.absolute', 'origin.fixed', 'offset.vertical', 'offset.horizontal' ),
		'animation'    => array( 'intensity.slide', 'intensity.zoom', 'intensity.flip', 'intensity.fold', 'intensity.roll' ),
		'button'       => array( 'icon.enable', 'icon.settings', 'icon.color', 'icon.placement', 'icon.onHover' ),
		'sticky'       => array( 'offset.top', 'offset.bottom', 'offset.surrounding', 'limit.top', 'limit.bottom' ),
		'scroll'       => array( '<effect>.enable', '<effect>.viewport.bottom', '<effect>.viewport.end', '<effect>.viewport.start', '<effect>.viewport.top', '<effect>.offset.start', '<effect>.offset.mid', '<effect>.offset.end' ),
		'interactions' => array( 'interactions[].id', 'interactions[].trigger', 'interactions[].triggerClass', 'interactions[].effect', 'interactions[].target.targetClass', 'interactions[].target.label', 'interactions[].target.targetType', 'interactions[].timeDelay', 'interactions[].attributeName', 'interactions[].attributeValue', 'interactions[].sensitivity', 'interactions[].mouseMovementType', 'interactions[].breakpointName' ),
		'attributes'   => array( 'attributes[].id', 'attributes[].name', 'attributes[].value', 'attributes[].adminLabel', 'attributes[].targetElement' ),
	);

	/**
	 * Fields Divi reads only from a given version on. The backdrop filters
	 * are not read by Divi 5.10.1 at all; the names come from a Divi 5
	 * reference that measured them on 5.11, not from source we could read.
	 */
	const VERSIONED_FIELDS = array(
		'filters' => array(
			'5.11' => array( 'backdropBlur', 'backdropInvert', 'backdropSepia' ),
		),
	);

	/**
	 * Groups whose field list is CLOSED: the complete set of keys a value may
	 * hold. Only these are safe for the echo to judge leaf keys against.
	 */
	const CLOSED_GROUPS = array( 'spacing' );

	/**
	 * Groups whose value is one plain value, not an object of fields.
	 */
	const SCALAR_GROUPS = array( 'zIndex', 'disabledOn' );

	/**
	 * One correct VALUE per group, as JSON. Hand-written rather than mined:
	 * Divi's own defaults are sparse and full of internal $variable(…)$
	 * tokens that would mis-teach agents.
	 */
	const EXAMPLES = array(
		'background'   => '{"color":"#0f172a"}',
		'spacing'      => '{"padding":{"top":"96px","bottom":"96px"}}',
		'border'       => '{"radius":{"sync":"on","topLeft":"8px","topRight":"8px","bottomLeft":"8px","bottomRight":"8px"},"styles":{"all":{"width":"1px","color":"#e2e8f0","style":"solid"}}}',
		'sizing'       => '{"maxWidth":"640px","alignment":"center"}',
		'font'         => '{"color":"#ffffff","size":"48px","weight":"700","textAlign":"center"}',
		'headingFont'  => '{"color":"#111111","size":"32px","weight":"700"}',
		'bodyFont'     => '{"color":"#333333","size":"16px","lineHeight":"1.7em"}',
		'boxShadow'    => '{"style":"preset1","horizontal":"0px","vertical":"12px","blur":"24px","spread":"0px","position":"outer","color":"rgba(0,0,0,0.12)"}',
		'button'       => '{"enable":"on"}',
		'layout'       => '{"display":"flex","flexDirection":"row","columnGap":"16px","rowGap":"16px"}',
		'zIndex'       => '"10"',
		'icon'         => '{"color":"#ffffff","useSize":"on","size":"24px"}',
		'filters'      => '{"brightness":"110%","saturate":"120%"}',
		'transform'    => '{"translate":{"x":"0px","y":"-8px"},"scale":{"x":"105%","y":"105%"},"origin":{"x":"50%","y":"50%"}}',
		'position'     => '{"mode":"absolute","origin":{"absolute":"bottom left"},"offset":{"vertical":"56px","horizontal":"-28px"}}',
		'overflow'     => '{"x":"hidden","y":"hidden"}',
		'animation'    => '{"style":"slide","direction":"bottom","duration":"600ms","delay":"0ms","startingOpacity":"0%","speedCurve":"ease-out","repeat":"once","intensity":{"slide":"20%"}}',
		'transition'   => '{"duration":"300ms","delay":"0ms","speedCurve":"ease"}',
		'fit'          => '{"objectFit":"cover","objectPosition":"center center"}',
		'textShadow'   => '{"style":"preset1","horizontal":"0em","vertical":"0.1em","blur":"0.1em","color":"rgba(0,0,0,0.4)"}',
		'textEffects'  => '{"fillType":"gradient","gradient":{"type":"linear","direction":"90deg","stops":[{"position":0,"color":"#7c3aed"},{"position":100,"color":"#db2777"}]}}',
		'sticky'       => '{"position":"top","offset":{"top":"0px","bottom":"0px","surrounding":"on"},"limit":{"top":"none","bottom":"none"},"transition":"on"}',
		'scroll'       => '{"motionTriggerStart":"middle","verticalMotion":{"enable":"on","viewport":{"bottom":"0","end":"50","start":"50","top":"100"},"offset":{"start":"4","mid":"0","end":"-4"}}}',
		'interactions' => '{"interactions":[{"id":"i1","trigger":"click","triggerClass":"et-interaction-trigger-plans","effect":"toggleVisibility","target":{"targetClass":"et-interaction-target-yearly","label":"yearly","targetType":"module"},"timeDelay":"0ms"}]}',
		'attributes'   => '{"attributes":[{"id":"a1","name":"data-section","value":"pricing","adminLabel":"Section name","targetElement":"main"}]}',
		'disabledOn'   => '"on"',
		'order'        => '{"order":"2"}',
	);

	/**
	 * A second example for groups where one value cannot show the shape.
	 */
	const MORE_EXAMPLES = array(
		'background' => '{"image":{"url":"https://example.com/hero.jpg","size":"cover","position":"center","repeat":"no-repeat"},"gradient":{"enabled":"on","type":"linear","direction":"180deg","stops":[{"position":0,"color":"rgba(15,23,42,0.2)"},{"position":100,"color":"rgba(15,23,42,0.8)"}],"overlaysImage":"on"}}',
		'position'   => '{"mode":"relative","origin":{"relative":"top left"},"offset":{"vertical":"0px","horizontal":"0px"}}',
	);

	/**
	 * Every documented field of a group, top-level and nested, for this Divi
	 * version.
	 *
	 * @param string      $group   Decoration group.
	 * @param string|null $version Divi version (Saddle_Divi::version()).
	 * @return string[]
	 */
	public static function fields( $group, $version = null ) {
		$fields = isset( self::FIELDS[ $group ] ) ? self::FIELDS[ $group ] : array();
		if ( isset( self::VERSIONED_FIELDS[ $group ] ) && $version ) {
			foreach ( self::VERSIONED_FIELDS[ $group ] as $since => $extra ) {
				if ( version_compare( (string) $version, $since, '>=' ) ) {
					$fields = array_merge( $fields, $extra );
				}
			}
		}
		return $fields;
	}

	/**
	 * The nested field names of a group, or an empty list.
	 *
	 * @param string $group Decoration group.
	 * @return string[]
	 */
	public static function sub_fields( $group ) {
		return isset( self::SUB_FIELDS[ $group ] ) ? self::SUB_FIELDS[ $group ] : array();
	}

	/**
	 * The hand-written example value of a group, or null.
	 *
	 * @param string $group Decoration group.
	 * @param bool   $more  The second example instead.
	 * @return mixed|null
	 */
	public static function example( $group, $more = false ) {
		$table = $more ? self::MORE_EXAMPLES : self::EXAMPLES;
		return isset( $table[ $group ] ) ? json_decode( $table[ $group ], true ) : null;
	}

	/**
	 * What to know about a group beyond its fields: one or two plain
	 * sentences, or ''.
	 *
	 * @param string $group Decoration group.
	 * @return string
	 */
	public static function note( $group ) {
		$notes = array(
			'zIndex'       => __( 'The value is one plain number as a string, never an object: { "<path>": "10" }.', 'saddle' ),
			'disabledOn'   => __( 'Hides the module on one screen size: write "on" per screen size, e.g. module.decoration.disabledOn.phone.value = "on". There are no fields.', 'saddle' ),
			'conditions'   => __( 'Do not write this group by hand. Use divi-set-display-conditions.', 'saddle' ),
			'layout'       => __( 'Gaps are columnGap and rowGap; there is no "gap" key. Tablet and phone inherit the desktop value, so a three-column grid stays three columns on a phone unless gridColumnCount is set there too.', 'saddle' ),
			'font'         => __( 'Uppercase and the like go in "capitalization" (uppercase, lowercase, capitalize), not in "style". "style" is a list: italic, underline, overline, strikethrough. Text columns are inherited by tablet and phone: set columnCount "1" at phone.', 'saddle' ),
			'boxShadow'    => __( 'Always include "style" (preset1 to preset7, or "none"); a shadow without it renders nothing.', 'saddle' ),
			'background'   => __( 'Image size is cover, contain, stretch or custom (then width and height). Gradient type is linear, circular, elliptical or conic, and the gradient renders only with "enabled": "on".', 'saddle' ),
			'position'     => __( '"origin" is keyed by the mode: { "absolute": "bottom left" } for absolute, { "relative": "top left" } for relative. Give the parent a relative position with an origin that is not "center".', 'saddle' ),
			'animation'    => __( 'Put "style" first in the value. "fade" takes no direction. Stagger items with "delay".', 'saddle' ),
			'transform'    => __( 'Each part is an object with x and y (rotate also takes z). Transform shares one CSS transform with scroll effects.', 'saddle' ),
			'sticky'       => __( '"position" is none, top, bottom or topBottom. The "sticky" state of other groups only shows once this group is on.', 'saddle' ),
			'scroll'       => __( 'Effects: verticalMotion, horizontalMotion, fade, scaling, rotating, blur, each { "enable": "on", "viewport": {…}, "offset": {…} }. In "viewport", bottom is where the effect starts, top where it ends; end and start are the two middle points.', 'saddle' ),
			'interactions' => __( 'Desktop only. triggerClass must start with "et-interaction-trigger-" and targetClass with "et-interaction-target-". The trigger module also needs the attribute data-interaction-trigger and the target module data-interaction-target, written through the "attributes" group.', 'saddle' ),
			'attributes'   => __( 'Any HTML attribute: id, class, style or data-*. An entry with an empty name or value is skipped. An empty "targetElement" means the main element.', 'saddle' ),
			'filters'      => __( 'Each filter is a CSS value: "120%", "4px", "90deg". blendMode is a CSS mix-blend-mode.', 'saddle' ),
			'button'       => __( 'Turn "enable" on, or the button renders as an outline whatever else is styled.', 'saddle' ),
		);
		return isset( $notes[ $group ] ) ? $notes[ $group ] : '';
	}

	/**
	 * The breakpoints this site has switched on, desktop first. Divi keeps
	 * phoneWide, tabletWide, widescreen and ultraWide off by default, and a
	 * value written for a switched-off breakpoint emits no CSS.
	 *
	 * @return string[]
	 */
	public static function breakpoints() {
		$names = array();
		$class = '\ET\Builder\Framework\Breakpoint\Breakpoint';
		if ( class_exists( $class ) && method_exists( $class, 'get_enabled_breakpoint_names' ) ) {
			$names = (array) call_user_func( array( $class, 'get_enabled_breakpoint_names' ) );
		} else {
			// Divi's own option (Breakpoint::$option_name), read the way
			// Breakpoint::get_settings_values() does: no saved items means
			// Divi's defaults, where only tablet and phone join desktop.
			$saved = get_option( 'et_divi_builder_breakpoints', array() );
			$items = is_array( $saved ) && ! empty( $saved['items'] ) && is_array( $saved['items'] ) ? $saved['items'] : array();
			if ( ! $items ) {
				$names = array( 'tablet', 'phone' );
			}
			foreach ( $items as $name => $item ) {
				$enable = is_array( $item ) && array_key_exists( 'enable', $item ) ? $item['enable'] : in_array( (string) $name, array( 'tablet', 'phone' ), true );
				if ( true === $enable || 'on' === $enable || 'true' === $enable || 1 === $enable ) {
					$names[] = (string) $name;
				}
			}
		}

		return array_values( array_unique( array_merge( array( 'desktop' ), array_filter( array_map( 'strval', $names ) ) ) ) );
	}

	/**
	 * How hover, sticky and screen sizes are written, with this site's
	 * breakpoints named.
	 *
	 * @return string
	 */
	public static function states_note() {
		return sprintf(
			/* translators: %s: comma-separated breakpoint names. */
			__( 'Values sit at <path-to-group>.<breakpoint>.<state>. Breakpoints switched on here: %s. Write only these; any other emits no CSS. State is "value", "hover" or "sticky", side by side under a breakpoint. "hover" and "sticky" hold the same content as "value", not wrapped in another "value", and may hold only the fields that change: { "desktop": { "value": { "color": "#2563eb" }, "hover": { "color": "#1e40af" } } }. Every path below ends at desktop.value; swap desktop or value for the breakpoint or state you want.', 'saddle' ),
			implode( ', ', self::breakpoints() )
		);
	}
}
