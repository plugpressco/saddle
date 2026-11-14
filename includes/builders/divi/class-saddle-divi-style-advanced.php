<?php
/**
 * The advanced options that style a module, and its custom CSS.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The style-side advanced options for divi-get-style-schema. Each path and
 * field list was read in Divi 5.10.1's server source (the option's
 * *PresetAttrsMap.php and the module PHP that reads it); none was
 * confirmed by rendering.
 */
class Saddle_Divi_Style_Advanced {

	/**
	 * Every field of each option (FIELDS) and one example value (EXAMPLES).
	 */
	const FIELDS = array(
		'htmlAttributes' => array( 'id', 'class' ),
		'html'           => array( 'elementType', 'htmlBefore', 'htmlAfter' ),
		'link'           => array( 'url', 'target' ),
		'text'           => array( 'color', 'orientation' ),
		'dividers'       => array( 'style', 'color', 'height', 'repeat', 'flip', 'arrangement' ),
		'gutter'         => array( 'enable', 'width', 'makeEqual', 'alignColumns' ),
	);

	/**
	 * One example value per option, as JSON.
	 */
	const EXAMPLES = array(
		'htmlAttributes' => '{"id":"pricing","class":"plans-band"}',
		'html'           => '{"elementType":"section"}',
		'link'           => '{"url":"https://example.com/plans","target":"off"}',
		'text'           => '{"color":"dark","orientation":"center"}',
		'dividers'       => '{"style":"slant","color":"#f4efe4","height":"60px","repeat":"1x","flip":["horizontal"],"arrangement":"below"}',
		'gutter'         => '{"enable":"on","width":"2","makeEqual":"on","alignColumns":"stretch"}',
	);

	/**
	 * The style-side advanced options of a module, by name. htmlAttributes
	 * (the module's id and CSS class) is on every module: Divi reads it for
	 * all of them (Module.php), though module.json does not list it.
	 *
	 * @param array $json module.json.
	 * @return array<string,array>
	 */
	public static function for_module( array $json ) {
		$out = array(
			'htmlAttributes' => self::option(
				'module.advanced.htmlAttributes.desktop.value',
				'htmlAttributes',
				__( 'The module\'s HTML id and CSS class. Desktop only: Divi reads no other screen size or state here. Not module.advanced.html, which is the wrapper element.', 'saddle' )
			),
		);
		foreach ( ( isset( $json['attributes'] ) && is_array( $json['attributes'] ) ? $json['attributes'] : array() ) as $attr => $attribute ) {
			$advanced = isset( $attribute['settings']['advanced'] ) && is_array( $attribute['settings']['advanced'] ) ? $attribute['settings']['advanced'] : array();
			foreach ( array_keys( $advanced ) as $name ) {
				$entry = self::declared( (string) $attr, (string) $name );
				if ( $entry ) {
					$out[ $entry[0] ] = $entry[1];
				}
			}
		}
		return $out;
	}

	/**
	 * Custom CSS: the top-level "css" attribute (a sibling of "module", not
	 * module.advanced.css). Each field holds CSS declarations for one part
	 * of the module; freeForm holds whole rules where "selector" stands for
	 * the module (CssStyleUtils.php).
	 *
	 * @param array $json module.json.
	 * @return array
	 */
	public static function custom_css( array $json ) {
		$fields = array( 'mainElement', 'before', 'after', 'freeForm' );
		if ( isset( $json['customCssFields'] ) && is_array( $json['customCssFields'] ) ) {
			$fields = array_values( array_unique( array_merge( $fields, array_map( 'strval', array_keys( $json['customCssFields'] ) ) ) ) );
		}
		return array(
			'path'    => 'css.desktop.value',
			'fields'  => $fields,
			'example' => array(
				'mainElement' => 'letter-spacing: 0.02em;',
				'freeForm'    => 'selector:hover { opacity: 0.9; }',
			),
			'note'    => __( 'Last resort for what no style group covers. Write declarations only (no selector or braces), except in freeForm.', 'saddle' ),
		);
	}

	/**
	 * The documented entry for one declared advanced option, as
	 * [name, entry], or null for a module setting (those are in
	 * divi-get-module-schema).
	 *
	 * @param string $attr Attribute.
	 * @param string $name Advanced option.
	 * @return array|null
	 */
	private static function declared( $attr, $name ) {
		$base = $attr . '.advanced.' . $name;
		switch ( $name ) {
			case 'html':
				return array( 'html', self::option( $base . '.desktop.value', 'html', __( 'The wrapper element (elementType, e.g. section, div, article, li) and raw HTML before or after it (htmlBefore, htmlAfter).', 'saddle' ) ) );
			case 'link':
				return array( 'link', self::option( $base . '.desktop.value', 'link', __( 'Makes the whole module a link. target is "on" for a new tab, "off" for the same tab.', 'saddle' ) ) );
			case 'text':
				$entry                    = self::option( $base . '.text.desktop.value', 'text', __( 'color "light" means DARK text for a light background, "dark" means LIGHT text for a dark background. orientation is left, center, right or justify.', 'saddle' ) );
				$entry['textShadow_path'] = $base . '.textShadow.desktop.value';
				return array( 'text', $entry );
			case 'dividers':
				$entry          = self::option( $base . '.bottom.desktop.value', 'dividers', __( 'Shape at the top or bottom edge of the section: write under .top or .bottom. style is none or one of arrow, arrow2, arrow3, asymmetric to asymmetric4, clouds, clouds2, curve, curve2, graph to graph4, mountains, mountains2, ramp, ramp2, slant, slant2, triangle, wave, wave2, waves, waves2. flip is a list ("horizontal", "vertical"). arrangement is below or above the section content.', 'saddle' ) );
				$entry['paths'] = array(
					'top'    => $base . '.top.desktop.value',
					'bottom' => $base . '.bottom.desktop.value',
				);
				return array( 'dividers', $entry );
			case 'gutter':
				return array( 'gutter', self::option( $base . '.desktop.value', 'gutter', __( 'width (1 to 4), makeEqual and alignColumns apply only to a row in block layout. A row is flex by default: there, space columns with module.decoration.layout columnGap.', 'saddle' ) ) );
			case 'loop':
				return array(
					'loop',
					array(
						'path' => $base . '.desktop.value',
						'note' => __( 'Do not write by hand. Use divi-enable-loop and divi-edit-loop.', 'saddle' ),
					),
				);
		}
		return null;
	}

	/**
	 * One documented option.
	 *
	 * @param string $path Value path.
	 * @param string $name Option name (EXAMPLES key).
	 * @param string $note What to know.
	 * @return array
	 */
	private static function option( $path, $name, $note ) {
		return array(
			'path'    => $path,
			'fields'  => self::FIELDS[ $name ],
			'example' => json_decode( self::EXAMPLES[ $name ], true ),
			'note'    => $note,
		);
	}
}
