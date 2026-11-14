<?php
/**
 * divi-get-style-schema: where each style of a module is written.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a module's styling schema from its module.json, its default render
 * attributes and the Saddle_Divi_Style vocabulary: every decoration group
 * with the exact path an agent writes, the advanced options that style a
 * module (id and class, HTML wrapper, link, text colour, dividers, gutter),
 * custom CSS, and how hover, sticky and screen sizes are written.
 */
class Saddle_Divi_Style_Schema {

	/**
	 * Groups that carry their example in the list view. The rest show it
	 * when expanded, which keeps the list small.
	 */
	const LIST_EXAMPLES = array( 'background', 'spacing', 'border', 'sizing', 'font', 'headingFont', 'bodyFont', 'boxShadow', 'button', 'layout', 'zIndex', 'icon' );

	/**
	 * The styling schema for one module, or one group of it.
	 *
	 * @param string $type  Module type, e.g. 'divi/text'.
	 * @param string $group Optional group: "font", or "title.font" for one
	 *                      attribute's group.
	 * @return array|WP_Error
	 */
	public static function build( $type, $group = '' ) {
		$json = Saddle_Divi_Schema::module_json( $type );
		if ( null === $json ) {
			return new WP_Error(
				'saddle_unknown_module',
				sprintf( 'No module.json found for "%s". Use divi-list-modules to see what exists on this site.', $type )
			);
		}

		$render = Saddle_Divi_Schema::render_defaults( $type );
		$groups = array();
		foreach ( self::attributes( $json ) as $attr_name => $settings ) {
			$decoration = isset( $settings['decoration'] ) && is_array( $settings['decoration'] ) ? $settings['decoration'] : array();
			$hidden     = self::hidden_groups( $decoration );
			foreach ( $decoration as $g => $group_settings ) {
				$g = (string) $g;
				if ( in_array( $g, $hidden, true ) ) {
					continue;
				}
				$entry     = array(
					'group' => $g,
					'attr'  => (string) $attr_name,
					'path'  => self::value_path( $render, (string) $attr_name, $g, $group_settings ),
				);
				$subgroups = self::subgroups( $group_settings );
				if ( $subgroups ) {
					$entry['subgroups'] = $subgroups;
				}
				$example = in_array( $g, self::LIST_EXAMPLES, true ) ? Saddle_Divi_Style::example( $g ) : null;
				if ( null === $example && ! Saddle_Divi_Style::example( $g ) ) {
					$example = self::mined_example( $render, (string) $attr_name, $g );
				}
				if ( null !== $example ) {
					$entry['example'] = $example;
				}
				$groups[] = $entry;
			}
		}

		if ( '' !== $group ) {
			return self::expand( $type, $group, $groups, $render );
		}

		return array(
			'type'         => $type,
			'style_groups' => $groups,
			'advanced'     => Saddle_Divi_Style_Advanced::for_module( $json ),
			'css'          => Saddle_Divi_Style_Advanced::custom_css( $json ),
			'breakpoints'  => Saddle_Divi_Style::breakpoints(),
			'states'       => Saddle_Divi_Style::states_note(),
			'note'         => __( 'Universal styling. Call again with "group" (e.g. "font", or "title.font" for one element) for its fields and a correct example. Write with divi-edit-module raw "attrs" at <path>.<field>. A module\'s own paths never transfer to another module. Non-style options of a module are in divi-get-module-schema settings.', 'saddle' ),
		);
	}

	/**
	 * Groups an element hides. Divi's button component turns whole groups
	 * off with "<group>Group": {"render": false}: the standalone button's
	 * spacing and box shadow, which ButtonModule.php strips from the button
	 * element and reads from module.decoration instead.
	 *
	 * @param array $decoration An attribute's settings.decoration.
	 * @return string[]
	 */
	public static function hidden_groups( array $decoration ) {
		$hidden = array();
		foreach ( $decoration as $group_settings ) {
			$fields = isset( $group_settings['component']['props']['fields'] ) && is_array( $group_settings['component']['props']['fields'] ) ? $group_settings['component']['props']['fields'] : array();
			foreach ( $fields as $field_name => $field ) {
				$field_name = (string) $field_name;
				if ( is_array( $field ) && isset( $field['render'] ) && false === $field['render'] && strlen( $field_name ) > 5 && 'Group' === substr( $field_name, -5 ) ) {
					$hidden[] = substr( $field_name, 0, -5 );
				}
			}
		}
		return array_values( array_unique( $hidden ) );
	}

	/**
	 * One group expanded: its fields, sub-fields, one correct example, how
	 * to write it, and for typography the sibling groups.
	 *
	 * @param string  $type   Module type.
	 * @param string  $group  "font" or "title.font".
	 * @param array[] $groups The module's groups.
	 * @param array   $render Default render attributes.
	 * @return array|WP_Error
	 */
	private static function expand( $type, $group, array $groups, array $render ) {
		$attr = '';
		if ( false !== strpos( $group, '.' ) ) {
			list( $attr, $group ) = explode( '.', $group, 2 );
		}
		$matches = array();
		foreach ( $groups as $sg ) {
			if ( $sg['group'] === $group && ( '' === $attr || $sg['attr'] === $attr ) ) {
				$matches[ $sg['attr'] ] = $sg['path'];
			}
		}
		if ( ! $matches ) {
			return new WP_Error(
				'saddle_unknown_style_group',
				sprintf( 'Module "%s" has no "%s" style group. Call divi-get-style-schema without a group for the list.', $type, '' === $attr ? $group : $attr . '.' . $group )
			);
		}

		$path    = (string) reset( $matches );
		$example = Saddle_Divi_Style::example( $group );
		if ( null === $example ) {
			$example = self::mined_example( $render, (string) key( $matches ), $group );
		}
		$out = array(
			'type'   => $type,
			'group'  => $group,
			'path'   => $path,
			'fields' => Saddle_Divi_Style::fields( $group, Saddle_Divi::version() ),
		);
		if ( count( $matches ) > 1 ) {
			$out['paths'] = $matches;
		}
		$sub = Saddle_Divi_Style::sub_fields( $group );
		if ( $sub ) {
			$out['sub_fields'] = $sub;
		}
		if ( null !== $example ) {
			$out['example'] = $example;
		}
		$more = Saddle_Divi_Style::example( $group, true );
		if ( null !== $more ) {
			$out['example_2'] = $more;
		}
		if ( 'font' === $group ) {
			$out['siblings'] = self::font_siblings( $path );
		}
		$note = Saddle_Divi_Style::note( $group );
		if ( '' !== $note ) {
			$out['group_note'] = $note;
		}
		$out['note']   = self::write_note( $group, $path, $example );
		$out['states'] = Saddle_Divi_Style::states_note();
		return $out;
	}

	/**
	 * The sentence that says how to write this group, with an example built
	 * from the group's own example, never a stock ".color".
	 *
	 * @param string $group   Group.
	 * @param string $path    Its path.
	 * @param mixed  $example Its example, or null.
	 * @return string
	 */
	private static function write_note( $group, $path, $example ) {
		if ( 'conditions' === $group ) {
			return Saddle_Divi_Style::note( 'conditions' );
		}
		if ( in_array( $group, Saddle_Divi_Style::SCALAR_GROUPS, true ) || ( null !== $example && ! is_array( $example ) ) ) {
			$sample = sprintf( '{ "%s": %s }', $path, wp_json_encode( null === $example ? 'on' : $example ) );
		} elseif ( is_array( $example ) && $example ) {
			$field  = (string) key( $example );
			$sample = sprintf( '{ "%s.%s": %s }', $path, $field, wp_json_encode( reset( $example ) ) );
		} else {
			$sample = sprintf( '{ "%s.<field>": <value> }', $path );
		}
		return sprintf(
			/* translators: %s: an example attrs object. */
			__( 'Set with divi-edit-module by passing the dotted path as an attrs KEY, e.g. attrs = %s. SaddleWP expands the dotted key into the nested object Divi reads. Omit to inherit the site design. "example" shows a correct value SHAPE: some fields take objects, not single values.', 'saddle' ),
			$sample
		);
	}

	/**
	 * Text shadow and text effects sit beside "font" inside the font group
	 * (FontStyle.php reads attr.font, attr.textShadow and attr.textEffects).
	 *
	 * @param string $font_path The font path, …decoration.font.font.desktop.value.
	 * @return array
	 */
	private static function font_siblings( $font_path ) {
		$base = substr( $font_path, 0, - strlen( '.font.desktop.value' ) );
		$out  = array();
		foreach ( array( 'textShadow', 'textEffects' ) as $sibling ) {
			$out[ $sibling ] = array(
				'path'    => $base . '.' . $sibling . '.desktop.value',
				'fields'  => Saddle_Divi_Style::fields( $sibling ),
				'example' => Saddle_Divi_Style::example( $sibling ),
			);
		}
		return $out;
	}

	/**
	 * Attribute name => its settings block, for every attribute that has one.
	 *
	 * @param array $json module.json.
	 * @return array<string,array>
	 */
	private static function attributes( array $json ) {
		$out = array();
		foreach ( ( isset( $json['attributes'] ) && is_array( $json['attributes'] ) ? $json['attributes'] : array() ) as $name => $attr ) {
			if ( is_array( $attr ) && isset( $attr['settings'] ) && is_array( $attr['settings'] ) ) {
				$out[ (string) $name ] = $attr['settings'];
			}
		}
		return $out;
	}

	/**
	 * The settable path of a decoration group.
	 *
	 * Typography is always one key deeper: Divi's element style passes
	 * attr.font to FontStyle, which reads attr.font.font
	 * (ElementStyle.php, FontStyle.php). That holds for every module, so
	 * the font path no longer depends on whether the module ships a font
	 * default (the button did not, and was told the flat path that renders
	 * nothing). Body and heading fonts nest under their first sub-group.
	 * Every other group is read from the default render attributes, else
	 * sits at <group>.desktop.value.
	 *
	 * @param array  $render         Default render attributes.
	 * @param string $attr_name      Attribute.
	 * @param string $group          Group.
	 * @param mixed  $group_settings The group's module.json settings.
	 * @return string
	 */
	private static function value_path( array $render, $attr_name, $group, $group_settings ) {
		$base = $attr_name . '.decoration.' . $group;
		if ( 'font' === $group ) {
			return $base . '.font.desktop.value';
		}
		$node = $render;
		foreach ( array( $attr_name, 'decoration', $group ) as $k ) {
			$node = is_array( $node ) && isset( $node[ $k ] ) ? $node[ $k ] : null;
		}
		$found = self::find_desktop_path( $node, $base );
		if ( $found ) {
			return $found . '.value';
		}
		$first = array(
			'bodyFont'    => 'body',
			'headingFont' => 'h1',
		);
		if ( isset( $first[ $group ] ) ) {
			$subgroups = self::subgroups( $group_settings );
			return $base . '.' . ( $subgroups ? $subgroups[0] : $first[ $group ] ) . '.font.desktop.value';
		}
		return $base . '.desktop.value';
	}

	/**
	 * Depth-first search for the first `desktop` key.
	 *
	 * @param mixed  $node Subtree.
	 * @param string $path Path so far.
	 * @return string|null Path up to and including "desktop".
	 */
	private static function find_desktop_path( $node, $path ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		if ( isset( $node['desktop'] ) ) {
			return $path . '.desktop';
		}
		foreach ( $node as $k => $v ) {
			$r = self::find_desktop_path( $v, $path . '.' . $k );
			if ( $r ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * The sub-groups a font group declares: body, link, ul, ol, quote, or
	 * h1 to h6.
	 *
	 * @param mixed $group_settings The group's module.json settings.
	 * @return string[]
	 */
	private static function subgroups( $group_settings ) {
		$groups = is_array( $group_settings ) && isset( $group_settings['item']['component']['props']['groups'] ) ? $group_settings['item']['component']['props']['groups'] : array();
		return is_array( $groups ) ? array_map( 'strval', array_keys( $groups ) ) : array();
	}

	/**
	 * A module's own default value for a group nobody wrote an example for
	 * (a module pack's own group), or null.
	 *
	 * @param array  $render    Default render attributes.
	 * @param string $attr_name Attribute.
	 * @param string $group     Group.
	 * @return mixed|null
	 */
	private static function mined_example( array $render, $attr_name, $group ) {
		$node = $render;
		foreach ( array( $attr_name, 'decoration', $group ) as $k ) {
			if ( ! is_array( $node ) || ! isset( $node[ $k ] ) ) {
				return null;
			}
			$node = $node[ $k ];
		}
		while ( is_array( $node ) && ! isset( $node['desktop'] ) ) {
			$first = reset( $node );
			if ( false === $first ) {
				return null;
			}
			$node = $first;
		}
		if ( is_array( $node ) && isset( $node['desktop']['value'] ) && ! in_array( $node['desktop']['value'], array( null, '', array() ), true ) ) {
			return $node['desktop']['value'];
		}
		return null;
	}
}
