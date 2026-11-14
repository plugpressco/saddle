<?php
/**
 * Whole-node checks for the Divi echo.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The echo checks that look at a node's whole attrs object instead of one
 * decoration group: custom CSS, the CSS id and class, section dividers, the
 * groups Divi drops from the button element, and token ids that do not
 * exist. Each is read from Divi 5.10.1's source, named beside the check.
 */
class Saddle_Divi_Echo_Attrs {

	/**
	 * Run every whole-node check.
	 *
	 * @param string $type     Module type.
	 * @param array  $attrs    Expanded attrs (dotted keys already nested).
	 * @param array  $contract The echo's module contract.
	 * @param string $location Module + address for messages.
	 * @return string[]
	 */
	public static function check( $type, array $attrs, array $contract, $location ) {
		$module   = isset( $attrs['module'] ) && is_array( $attrs['module'] ) ? $attrs['module'] : array();
		$advanced = isset( $module['advanced'] ) && is_array( $module['advanced'] ) ? $module['advanced'] : array();
		$warnings = array();

		if ( isset( $attrs['css'] ) && ! isset( $contract['attributes']['css'] ) ) {
			$warnings = array_merge( $warnings, self::css( $type, $attrs['css'], $location ) );
		}
		if ( isset( $advanced['htmlAttributes'] ) ) {
			$warnings = array_merge( $warnings, self::html_attributes( $advanced['htmlAttributes'], $location ) );
		}
		foreach ( $attrs as $attr_name => $attr ) {
			if ( isset( $attr['advanced']['html'] ) && is_array( $attr['advanced']['html'] ) ) {
				foreach ( array( 'id', 'class' ) as $key ) {
					if ( self::has_key( $attr['advanced']['html'], $key ) ) {
						$warnings[] = sprintf(
							/* translators: 1: id or class, 2: attribute name, 3: module + address. */
							__( '"%1$s" is not an HTML setting on %3$s: %2$s.advanced.html holds the element type and the HTML before and after the module, so Divi adds no %1$s. Write it at module.advanced.htmlAttributes.desktop.value.%1$s.', 'saddle' ),
							$key,
							(string) $attr_name,
							$location
						);
					}
				}
			}
		}
		$declared = isset( $contract['attributes']['module']['advanced'] ) ? (array) $contract['attributes']['module']['advanced'] : array();
		if ( isset( $advanced['dividers'] ) && in_array( 'dividers', $declared, true ) ) {
			$warnings = array_merge( $warnings, self::dividers( $advanced['dividers'], $location ) );
		}
		$warnings = array_merge( $warnings, self::button_groups( $type, $attrs, $location ) );
		return array_merge( $warnings, self::unknown_tokens( $attrs, $location ) );
	}

	/**
	 * Top-level custom CSS: css.<breakpoint>.<state>.<field> = declarations.
	 * Divi reads it (CssStyle in every module) and skips a field name it has
	 * no selector for (CssStyleUtils::get_statements()).
	 *
	 * @param string $type     Module type.
	 * @param mixed  $css      The css payload.
	 * @param string $location Module + address.
	 * @return string[]
	 */
	private static function css( $type, $css, $location ) {
		$json   = self::module_json( $type );
		$fields = Saddle_Divi_Echo_Traps::CSS_FIELDS;
		if ( isset( $json['customCssFields'] ) && is_array( $json['customCssFields'] ) ) {
			$fields = array_merge( $fields, array_map( 'strval', array_keys( $json['customCssFields'] ) ) );
		}

		$rows = Saddle_Divi_Echo_Traps::rows( $css );
		if ( ! is_array( $css ) || ! $rows ) {
			return array(
				sprintf(
					/* translators: 1: module + address. */
					__( 'Custom CSS on %1$s goes at css.<breakpoint>.<state>.<field> as CSS declarations, for example css.desktop.value.mainElement = "letter-spacing: 2px;". Divi skips what was written.', 'saddle' ),
					$location
				),
			);
		}

		$warnings = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row['data'] ) || $row['sub'] ) {
				$warnings[] = sprintf(
					/* translators: 1: module + address, 2: written path. */
					__( 'Custom CSS on %1$s goes at css.<breakpoint>.<state>.<field> as CSS declarations, for example css.desktop.value.mainElement = "letter-spacing: 2px;". Divi skips what was written at %2$s.', 'saddle' ),
					$location,
					Saddle_Divi_Echo_Traps::row_path( 'css', $row )
				);
				continue;
			}
			foreach ( array_keys( $row['data'] ) as $field ) {
				if ( ! in_array( (string) $field, $fields, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: field name, 2: module + address, 3: valid fields. */
						__( 'Custom CSS field "%1$s" on %2$s is not one this module has, so Divi skips it. Fields: %3$s.', 'saddle' ),
						(string) $field,
						$location,
						implode( ', ', $fields )
					);
				}
			}
		}
		return $warnings;
	}

	/**
	 * The CSS id and class: Divi reads only
	 * module.advanced.htmlAttributes.desktop.value.{id,class}
	 * (IdClassesClassnames::get_html_attributes()).
	 *
	 * @param mixed  $payload  The htmlAttributes payload.
	 * @param string $location Module + address.
	 * @return string[]
	 */
	private static function html_attributes( $payload, $location ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}
		$warnings = array();
		$beside   = array_intersect( array( 'id', 'class' ), array_keys( $payload ) );
		if ( isset( $payload['desktop'] ) && is_array( $payload['desktop'] ) ) {
			$beside = array_merge( $beside, array_intersect( array( 'id', 'class' ), array_keys( $payload['desktop'] ) ) );
		}
		foreach ( array_unique( $beside ) as $key ) {
			$warnings[] = sprintf(
				/* translators: 1: id or class, 2: module + address. */
				__( 'The "%1$s" on %2$s is written beside the breakpoint instead of inside "value", so Divi will not add it. Write module.advanced.htmlAttributes.desktop.value.%1$s.', 'saddle' ),
				$key,
				$location
			);
		}
		foreach ( array_keys( $payload ) as $breakpoint ) {
			if ( 'desktop' !== $breakpoint && in_array( (string) $breakpoint, Saddle_Divi_Echo::breakpoints(), true ) ) {
				$warnings[] = sprintf(
					/* translators: 1: breakpoint, 2: module + address. */
					__( 'Divi reads the CSS id and class only from desktop, so the "%1$s" value on %2$s does nothing. Write module.advanced.htmlAttributes.desktop.value instead.', 'saddle' ),
					(string) $breakpoint,
					$location
				);
			}
		}
		return $warnings;
	}

	/**
	 * Section dividers: module.advanced.dividers.{top,bottom}.<breakpoint>.value
	 * (SectionModule reads each placement), and `flip` is a list
	 * (DividersStyleUtils::transform_state() takes an array).
	 *
	 * @param mixed  $payload  The dividers payload.
	 * @param string $location Module + address.
	 * @return string[]
	 */
	private static function dividers( $payload, $location ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}
		if ( array_intersect( array_map( 'strval', array_keys( $payload ) ), Saddle_Divi_Echo::breakpoints() ) ) {
			return array(
				sprintf(
					/* translators: 1: module + address. */
					__( 'Section dividers on %1$s need "top" or "bottom" before the breakpoint, so this divider will not render. Write module.advanced.dividers.bottom.desktop.value = { "style": "slant", "color": "#ffffff", "height": "60px" }.', 'saddle' ),
					$location
				),
			);
		}
		$warnings = array();
		foreach ( array( 'top', 'bottom' ) as $placement ) {
			foreach ( Saddle_Divi_Echo_Traps::rows( isset( $payload[ $placement ] ) ? $payload[ $placement ] : null ) as $row ) {
				if ( is_array( $row['data'] ) && isset( $row['data']['flip'] ) && ! ( is_array( $row['data']['flip'] ) && array_is_list( $row['data']['flip'] ) ) ) {
					$warnings[] = sprintf(
						/* translators: 1: module + address, 2: written value. */
						__( 'Divider "flip" on %1$s must be a list such as ["horizontal"] or ["horizontal", "vertical"], not %2$s. Divi reads only a list here.', 'saddle' ),
						$location,
						(string) wp_json_encode( $row['data']['flip'] )
					);
				}
			}
		}
		return $warnings;
	}

	/**
	 * Groups Divi strips from a button element: ButtonModule keeps only the
	 * groups whose "<group>Group" field in the button settings is not
	 * `render: false` (spacing and box shadow on divi/button), and styles
	 * spacing from module.decoration instead. Read from module.json, so no
	 * module list is kept here.
	 *
	 * @param string $type     Module type.
	 * @param array  $attrs    Expanded attrs.
	 * @param string $location Module + address.
	 * @return string[]
	 */
	private static function button_groups( $type, array $attrs, $location ) {
		if ( empty( $attrs['button']['decoration'] ) || ! is_array( $attrs['button']['decoration'] ) ) {
			return array();
		}
		$json   = self::module_json( $type );
		$fields = isset( $json['attributes']['button']['settings']['decoration']['button']['component']['props']['fields'] )
			? $json['attributes']['button']['settings']['decoration']['button']['component']['props']['fields']
			: array();

		$warnings = array();
		foreach ( is_array( $fields ) ? $fields : array() as $name => $field ) {
			$name = (string) $name;
			if ( 'Group' !== substr( $name, -5 ) || ! is_array( $field ) || ! array_key_exists( 'render', $field ) || false !== $field['render'] ) {
				continue;
			}
			$group = substr( $name, 0, -5 );
			if ( isset( $attrs['button']['decoration'][ $group ] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: decoration group, 2: module + address. */
					__( 'Divi drops the "%1$s" group from the button element of %2$s, so this styling will not render. Write it on module.decoration.%1$s instead, which styles the button itself.', 'saddle' ),
					$group,
					$location
				);
			}
		}
		return $warnings;
	}

	/**
	 * Token ids that do not exist on this site: Divi renders nothing for an
	 * unknown var(--gcid-…), var(--gvid-…) or $variable() name. Skipped when
	 * the site's tokens cannot be read.
	 *
	 * @param array  $attrs    Expanded attrs.
	 * @param string $location Module + address.
	 * @return string[]
	 */
	private static function unknown_tokens( array $attrs, $location ) {
		$ids = Saddle_Divi_Tokens::references( $attrs );
		if ( ! $ids ) {
			return array();
		}
		$colors    = Saddle_Divi_Tokens::global_colors();
		$variables = Saddle_Divi_Tokens::variable_ids();

		$warnings = array();
		foreach ( $ids as $id ) {
			$is_color = 0 === strpos( $id, 'gcid-' );
			$known    = $is_color ? $colors : $variables;
			if ( null === $known || ( $is_color ? isset( $known[ $id ] ) : in_array( $id, $known, true ) ) ) {
				continue;
			}
			$warnings[] = $is_color
				? sprintf(
					/* translators: 1: color id, 2: module + address. */
					__( 'The global color "%1$s" on %2$s does not exist on this site, so Divi renders nothing for it. Use an id from divi-list-global-colors.', 'saddle' ),
					$id,
					$location
				)
				: sprintf(
					/* translators: 1: variable id, 2: module + address. */
					__( 'The design variable "%1$s" on %2$s does not exist on this site, so Divi renders nothing for it. Use an id from divi-list-variables.', 'saddle' ),
					$id,
					$location
				);
		}
		return $warnings;
	}

	/**
	 * Whether a key appears anywhere in a nested array.
	 *
	 * @param array  $subject Array.
	 * @param string $key     Key.
	 * @return bool
	 */
	private static function has_key( array $subject, $key ) {
		foreach ( $subject as $k => $v ) {
			if ( (string) $k === $key || ( is_array( $v ) && self::has_key( $v, $key ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A module's raw module.json, or an empty array.
	 *
	 * @param string $type Module type.
	 * @return array
	 */
	private static function module_json( $type ) {
		static $memo = array();

		$index = Saddle_Divi_Schema::index();
		$file  = isset( $index[ $type ] ) ? (string) $index[ $type ] : '';
		if ( '' === $file ) {
			return array();
		}
		if ( ! array_key_exists( $file, $memo ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- A bundled, read-only module.json inside the active theme or an active plugin, the same read Saddle_Divi_Schema makes.
			$raw = @file_get_contents( $file );

			$json          = json_decode( (string) $raw, true );
			$memo[ $file ] = is_array( $json ) ? $json : array();
		}
		return $memo[ $file ];
	}
}
