<?php
/**
 * The applied-vs-ignored echo for Divi writes.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects the Divi silent no-op: a style path or content field the module
 * will never read. Divi stores whatever attrs it's given and quietly ignores
 * unknown ones — a mistyped decoration group or a fields key that isn't a
 * content attribute produces a page that LOOKS saved but renders unstyled
 * (the exact failure the live agent tests kept hitting).
 *
 * Checks run against the module's own module.json (through
 * Saddle_Divi_Schema, the ecosystem contract):
 *
 *  1. `fields` keys must be content attributes of the module.
 *  2. `attrs` top-level keys (dotted or nested) must be module attributes.
 *  3. A path's decoration/advanced group must be one the attribute declares.
 *  4. The values written there must be ones Divi reads (Saddle_Divi_Echo_Traps
 *     per decoration group, Saddle_Divi_Echo_Attrs across the node).
 *
 * Modules without a discoverable module.json (runtime-registered third-party
 * packs) are skipped — the echo warns only on what the site can prove, and
 * it never blocks the write.
 */
class Saddle_Divi_Echo {

	/**
	 * Attr keys that are canonical plumbing, not module.json attributes.
	 * modulePreset and groupPreset are the preset stacks Divi 5 keeps at the
	 * attr root of any module (divi-apply-global-preset writes the first).
	 * `css` is the custom CSS every module reads at the attr root (each
	 * module's CssStyle call); Saddle_Divi_Echo_Attrs judges its fields.
	 */
	const UNIVERSAL_KEYS = array( 'builderVersion', 'modulePreset', 'groupPreset', 'css' );

	/**
	 * Advanced groups Divi reads although no module.json declares them.
	 * Every module renders its CSS id and class from
	 * module.advanced.htmlAttributes (Module.php, IdClassesClassnames); every
	 * form field reads its focus and placeholder styling from these
	 * (FormFieldPresetAttrsMap).
	 */
	const MODULE_ADVANCED = array( 'htmlAttributes' );
	const FIELD_ADVANCED  = array( 'focus', 'focusUseBorder', 'placeholder' );

	/**
	 * Decoration groups whose settings always sit straight inside the
	 * breakpoint (Divi's default render attributes nest only the typography
	 * groups). A breakpoint found under another key in these is a misplaced
	 * write: scroll.verticalMotion.desktop instead of scroll.desktop.value.verticalMotion.
	 */
	const FLAT_GROUPS = array(
		'animation',
		'attributes',
		'background',
		'border',
		'boxShadow',
		'button',
		'conditions',
		'disabledOn',
		'filters',
		'fit',
		'icon',
		'interactions',
		'layout',
		'order',
		'overflow',
		'position',
		'scroll',
		'sizing',
		'spacing',
		'sticky',
		'transform',
		'transition',
		'zIndex',
	);

	/**
	 * Layout settings Divi renders on the structural modules although their
	 * module.json does not declare them as advanced groups: the section and
	 * column `type`, the row's column structure (flexColumnStructure since
	 * the flex layout, columnStructure before it, with tablet and phone
	 * values), and the specialty-section pair. Divi's own code reads each
	 * (ColumnModule, SectionModule, DynamicAssetsListBuilder). Writing them
	 * is how a row gets real column widths, so they must never be called
	 * ignored (#402).
	 */
	const LAYOUT_ADVANCED = array(
		'divi/section'      => array( 'type', 'specialtyColumns' ),
		'divi/row'          => array( 'columnStructure', 'flexColumnStructure' ),
		'divi/row-inner'    => array( 'columnStructure', 'flexColumnStructure' ),
		'divi/column'       => array( 'type', 'sectionColumnType' ),
		'divi/column-inner' => array( 'type' ),
	);

	/**
	 * The value states a breakpoint envelope carries
	 * (ModuleUtils::states() in Divi 5.10.1).
	 */
	const STATES = array( 'value', 'hover', 'focus', 'checked', 'active', 'sticky' );

	/**
	 * Divi's universal ElementStyle decoration vocabulary. module.json
	 * declares what the VB SHOWS, but the render pipeline (ElementStyle)
	 * emits any of these groups present in the saved attrs regardless of
	 * declaration — proven live: a pack button element declaring only
	 * button/border/boxShadow still renders background/font/spacing
	 * written on it. Groups in this set are therefore never "unknown";
	 * only genuinely foreign group names (typos) warn. `fit` is read by
	 * ElementStyle too; interactionTrigger and interactionTarget are the
	 * plain ids Module.php turns into the interaction data attributes.
	 */
	const ELEMENT_STYLE_GROUPS = array(
		'animation',
		'attributes',
		'background',
		'bodyFont',
		'border',
		'boxShadow',
		'button',
		'conditions',
		'disabledOn',
		'filters',
		'fit',
		'font',
		'headingFont',
		'icon',
		'image',
		'interactions',
		'interactionTarget',
		'interactionTrigger',
		'layout',
		'order',
		'overflow',
		'position',
		'scroll',
		'sizing',
		'spacing',
		'sticky',
		'transform',
		'transition',
		'zIndex',
	);

	/**
	 * Divi 5's breakpoint vocabulary (from Divi's own portability layer).
	 * Filterable via `saddle_divi_breakpoints` should a Divi release
	 * extend it. A name here is a real breakpoint; whether this site turned
	 * it on is Saddle_Divi_Echo_Traps::enabled_breakpoints().
	 *
	 * @return string[]
	 */
	public static function breakpoints() {
		return (array) apply_filters(
			'saddle_divi_breakpoints',
			array( 'desktop', 'tablet', 'phone', 'tabletWide', 'phoneWide', 'widescreen', 'ultraWide' )
		);
	}

	/**
	 * Types exempt from the "no module.json" warning: the structural
	 * vocabulary (its containment is enforced by the tree validator — a
	 * typo'd structural type is already a hard rejection there) plus chrome
	 * that legitimately ships no manifest.
	 *
	 * @return string[]
	 */
	private static function manifest_exempt_types() {
		return array_values(
			array_unique(
				array_merge(
					array( 'divi/fullwidth-section' ),
					Saddle_Divi_Tree::root_types(),
					array_keys( Saddle_Divi_Tree::structure() ),
					// Every child type the structure map allows by name.
					array_filter(
						array_merge( ...array_values( Saddle_Divi_Tree::structure() ) ),
						static function ( $type ) {
							return '*' !== $type;
						}
					)
				)
			)
		);
	}

	/**
	 * Check a list of authored nodes (recursively), as passed to divi-set-page.
	 *
	 * @param array[] $nodes  Authoring nodes {type, fields, attrs, children}.
	 * @param string  $prefix Address prefix ('' at root).
	 * @return string[] Warnings.
	 */
	public static function check_nodes( array $nodes, $prefix = '' ) {
		$warnings = array();
		foreach ( array_values( $nodes ) as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$address  = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$warnings = array_merge( $warnings, self::check_node( $node, $address ) );
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$warnings = array_merge( $warnings, self::check_nodes( $node['children'], $address ) );
			}
		}
		return $warnings;
	}

	/**
	 * Check one authored node AND its whole subtree — what divi-add-module
	 * inserts. Checking only the top node let every child of an inserted
	 * section carry bad fields/style paths with zero warnings.
	 *
	 * @param array  $node    Authoring node {type, fields, attrs, children}.
	 * @param string $address The node's persisted address.
	 * @return string[] Warnings.
	 */
	public static function check_subtree( array $node, $address ) {
		$warnings = self::check_node( $node, $address );
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			$warnings = array_merge( $warnings, self::check_nodes( $node['children'], (string) $address ) );
		}
		return $warnings;
	}

	/**
	 * Check one authored node's fields + attrs at a known address.
	 *
	 * @param array  $node    Authoring node.
	 * @param string $address Node address.
	 * @return string[] Warnings.
	 */
	public static function check_node( array $node, $address ) {
		$type   = isset( $node['type'] ) && is_string( $node['type'] ) ? trim( $node['type'] ) : '';
		$fields = isset( $node['fields'] ) && is_array( $node['fields'] ) ? $node['fields'] : array();
		$attrs  = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		return self::check( $type, $fields, $attrs, $address );
	}

	/**
	 * Check a write payload against a module's contract.
	 *
	 * @param string $type    Module type.
	 * @param array  $fields  Content fields payload.
	 * @param array  $attrs   Raw attrs payload (dotted or nested keys).
	 * @param string $address Node address for messages.
	 * @param array  $options Policy switches. 'unknown_module' (default true)
	 *                        emits the cannot-verify warning for types with
	 *                        no module.json — write responses want it; the
	 *                        verify pass sets false because its unknown-module
	 *                        LINT rule owns that signal with real severity.
	 *                        'advisory' (default true) adds the findings for
	 *                        values that render but rarely as meant (a centred
	 *                        relative origin, a font written as var()); the
	 *                        verify pass sets false and scores only certain ones.
	 * @return string[] Warnings.
	 */
	public static function check( $type, array $fields, array $attrs, $address, array $options = array() ) {
		if ( '' === $type ) {
			return array();
		}

		$location = '' === (string) $address ? $type : sprintf( '%1$s (node %2$s)', $type, $address );

		$contract = self::contract( $type );
		if ( null === $contract ) {
			// A module with no module.json is unjudgeable — but silence here
			// hid the worst failure: a typo'd type (divi/headding) saves fine
			// and renders NOTHING. Say what the site can't prove.
			if ( isset( $options['unknown_module'] ) && false === $options['unknown_module'] ) {
				return array();
			}
			if ( in_array( $type, self::manifest_exempt_types(), true ) ) {
				return array();
			}
			return array(
				sprintf(
					/* translators: 1: module type, 2: module + address. */
					__( 'No module.json exists for "%1$s" on this site, so SaddleWP cannot verify its fields or style paths (%2$s). If the type is misspelled the module renders nothing — confirm the exact type with divi-list-modules. If it is a runtime-registered third-party module that renders fine, ignore this.', 'saddle' ),
					$type,
					$location
				),
			);
		}

		$warnings = array();

		foreach ( array_keys( $fields ) as $field ) {
			$field = (string) $field;
			if ( in_array( $field, $contract['content'], true ) ) {
				// A composite content value has named sub-keys (button:
				// text, linkUrl). An unknown sub-key (label, url) saves and
				// renders an empty element — warn on what the module.json
				// proves wrong.
				$known = isset( $contract['content_fields'][ $field ] ) ? $contract['content_fields'][ $field ] : array();
				if ( $known && is_array( $fields[ $field ] ) ) {
					foreach ( array_keys( $fields[ $field ] ) as $sub ) {
						if ( ! in_array( (string) $sub, $known, true ) ) {
							$warnings[] = sprintf(
								/* translators: 1: sub-key, 2: field, 3: module + address, 4: valid sub-keys. */
								__( '"%1$s" is not a sub-field of "%2$s" on %3$s — it will be stored but never rendered. Write fields.%2$s = { %4$s }.', 'saddle' ),
								(string) $sub,
								$field,
								$location,
								implode( ', ', $known )
							);
						}
					}
				}
				continue;
			}
			if ( isset( $contract['attributes'][ $field ] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: field key, 2: module + address. */
					__( '"%1$s" on %2$s is a module attribute but NOT a content field — written via "fields" it will not render. Set it through "attrs" using the paths from divi-get-style-schema.', 'saddle' ),
					$field,
					$location
				);
			} else {
				$warnings[] = sprintf(
					/* translators: 1: field key, 2: module + address, 3: content field list. */
					__( 'Content field "%1$s" on %2$s does not exist — it will be stored but never rendered. This module\'s content fields: %3$s.', 'saddle' ),
					$field,
					$location,
					$contract['content'] ? implode( ', ', $contract['content'] ) : __( '(none)', 'saddle' )
				);
			}
		}

		foreach ( $attrs as $key => $value ) {
			$warnings = array_merge( $warnings, self::check_attr_path( (string) $key, $value, $contract, $location, $type ) );
		}

		// Second pass, over the fully-expanded payload: judge the DEEP shape
		// of each declared decoration group — missed intermediate nesting
		// (the font.font trap), typo'd breakpoints/states, and, where the
		// field set is provably closed, unknown leaf fields. These are the
		// wrong-path writes that used to save, validate, and silently render
		// nothing.
		$expanded = Saddle_Divi_Author::expand_dotted_keys( $attrs );
		$context  = array(
			'advisory'   => ! isset( $options['advisory'] ) || false !== $options['advisory'],
			// A preset can carry what the node leaves out (a box shadow style).
			'has_preset' => ! empty( $expanded['modulePreset'] ) || ! empty( $expanded['groupPreset'] ),
		);
		foreach ( $expanded as $attr_name => $attr_payload ) {
			$attr_name = (string) $attr_name;
			if ( ! isset( $contract['attributes'][ $attr_name ] ) || ! is_array( $attr_payload ) ) {
				continue; // Unknown attrs were warned above.
			}
			if ( empty( $attr_payload['decoration'] ) || ! is_array( $attr_payload['decoration'] ) ) {
				continue;
			}
			$declared = $contract['attributes'][ $attr_name ]['decoration'];
			foreach ( $attr_payload['decoration'] as $group => $group_payload ) {
				$group     = (string) $group;
				$judgeable = ( null !== $declared && in_array( $group, $declared, true ) )
					|| in_array( $group, self::ELEMENT_STYLE_GROUPS, true );
				if ( ! $judgeable ) {
					continue; // Foreign groups were warned above.
				}
				$warnings = array_merge(
					$warnings,
					self::check_group_payload( $type, $attr_name, $group, $group_payload, $location ),
					Saddle_Divi_Echo_Traps::group( $attr_name, $group, $group_payload, $location, $context )
				);
			}
		}

		return array_merge( $warnings, Saddle_Divi_Echo_Attrs::check( $type, $expanded, $contract, $location ) );
	}

	/**
	 * Judge one declared decoration group's written payload against the
	 * canonical path and vocabulary. Warns only on what is provable; an
	 * unrecognizable structure is left alone.
	 *
	 * @param string $type      Module type.
	 * @param string $attr_name Attribute the group sits on.
	 * @param string $group     Decoration group.
	 * @param mixed  $payload   What was written under attr.decoration.group.
	 * @param string $location  Module + address for messages.
	 * @return string[]
	 */
	private static function check_group_payload( $type, $attr_name, $group, $payload, $location ) {
		if ( ! is_array( $payload ) || array() === $payload ) {
			return array();
		}

		$warnings = array();

		// The canonical path may nest the value under extra keys between the
		// group and the breakpoint (typography: …font.FONT.desktop.value).
		// Writing straight to a breakpoint at group level is the classic
		// silent no-op — Divi reads one level deeper.
		$paths     = Saddle_Divi_Schema::group_paths( $type );
		$true_path = isset( $paths[ $attr_name . '.' . $group ] ) ? (string) $paths[ $attr_name . '.' . $group ] : '';
		$required  = array();
		if ( '' !== $true_path ) {
			foreach ( array_slice( explode( '.', $true_path ), 3 ) as $seg ) {
				if ( 'desktop' === $seg ) {
					break;
				}
				$required[] = $seg;
			}
		}

		$node = $payload;
		foreach ( $required as $seg ) {
			if ( isset( $node[ $seg ] ) && is_array( $node[ $seg ] ) ) {
				$node = $node[ $seg ];
				continue;
			}
			if ( array_intersect( array_keys( $node ), self::breakpoints() ) ) {
				$warnings[] = sprintf(
					/* translators: 1: group, 2: module + address, 3: canonical path, 4: missing segment. */
					__( 'The "%1$s" group on %2$s nests its value deeper than written — Divi reads %3$s (note the extra "%4$s" level), so this write will silently not render. Rewrite on that exact path.', 'saddle' ),
					$group,
					$location,
					$true_path,
					$seg
				);
			}
			return $warnings; // Structure not judgeable past here.
		}

		if ( ! is_array( $node ) ) {
			return $warnings;
		}

		$closed = in_array( $group, Saddle_Divi_Schema::CLOSED_FIELD_GROUPS, true )
			? Saddle_Divi_Schema::STYLE_FIELDS[ $group ]
			: array();

		$base = implode( '.', array_merge( array( $attr_name, 'decoration', $group ), $required ) );
		// Only Divi's own modules provably keep FLAT_GROUPS flat: a module
		// pack may store and render its own shape (one ships
		// border.radius.desktop.value as its default).
		$flat = in_array( $group, self::FLAT_GROUPS, true ) && 0 === strpos( (string) $type, 'divi/' );
		return array_merge( $warnings, self::check_envelope( $node, $group, $location, $closed, $base, $flat ) );
	}

	/**
	 * Judge one breakpoint envelope: breakpoint keys, state keys under them,
	 * and (for closed groups) leaf fields under the state.
	 *
	 * The render-defaults file is sparse, so the derived path may be the FLAT
	 * fallback while Divi actually nests the value deeper (typography's
	 * body.font). A non-breakpoint key that eventually CONTAINS a breakpoint
	 * is therefore extra nesting — recurse into it and judge there. Only a
	 * key that looks like a typo'd breakpoint (its value is a bare state
	 * envelope) is warned about.
	 *
	 * @param array  $node     Envelope payload.
	 * @param string $group    Decoration group (messages).
	 * @param string $location Module + address (messages).
	 * @param array  $closed   Leaf fields when the group's set is closed.
	 * @param string $base     Dotted path down to this envelope (messages).
	 * @param bool   $flat     Whether the group provably keeps its settings
	 *                         straight inside the breakpoint.
	 * @return string[]
	 */
	private static function check_envelope( array $node, $group, $location, array $closed, $base, $flat = false ) {
		$warnings   = array();
		$vocabulary = self::breakpoints();
		$enabled    = Saddle_Divi_Echo_Traps::enabled_breakpoints();
		if ( 'disabledOn' === $group ) {
			$vocabulary = array_merge( $vocabulary, Saddle_Divi_Echo_Traps::DISABLED_ON_BREAKPOINTS );
			$enabled    = array_merge( $enabled, Saddle_Divi_Echo_Traps::DISABLED_ON_BREAKPOINTS );
		}
		foreach ( $node as $bp => $bp_payload ) {
			$bp = (string) $bp;
			if ( ! in_array( $bp, $vocabulary, true ) ) {
				if ( is_array( $bp_payload ) && self::contains_breakpoint( $bp_payload ) ) {
					if ( $flat ) {
						$warnings[] = sprintf(
							/* translators: 1: group, 2: module + address, 3: group path, 4: written key. */
							__( 'The "%1$s" group on %2$s keeps every setting inside the breakpoint, so this write will not render. Write %3$s.desktop.value.%4$s, not %3$s.%4$s.desktop.value.', 'saddle' ),
							$group,
							$location,
							$base,
							$bp
						);
						continue;
					}
					// Extra nesting the flat fallback didn't know — judge inside.
					$warnings = array_merge( $warnings, self::check_envelope( $bp_payload, $group, $location, $closed, $base . '.' . $bp ) );
					continue;
				}
				if ( in_array( $bp, self::STATES, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: written state, 2: group, 3: module + address, 4: group path. */
						__( '"%1$s" is a state, not a breakpoint, on the "%2$s" group of %3$s, so the styling will not render. A state sits inside a breakpoint, beside "value": write %4$s.desktop.%1$s with the settings straight under it.', 'saddle' ),
						$bp,
						$group,
						$location,
						$base
					);
					continue;
				}
				if ( is_array( $bp_payload ) && array_intersect( array_keys( $bp_payload ), self::STATES ) ) {
					// A bare state envelope under a non-breakpoint key: dektop.
					$warnings[] = sprintf(
						/* translators: 1: written key, 2: group, 3: module + address, 4: valid breakpoints. */
						__( '"%1$s" is not a Divi breakpoint on the "%2$s" group of %3$s — the styling will silently not render. Valid breakpoints: %4$s.', 'saddle' ),
						$bp,
						$group,
						$location,
						implode( ', ', $vocabulary )
					);
				}
				continue; // Anything else is structure this site can't judge.
			}
			if ( ! in_array( $bp, $enabled, true ) ) {
				$warnings[] = sprintf(
					/* translators: 1: breakpoint, 2: group, 3: module + address, 4: breakpoints turned on. */
					__( 'Breakpoint "%1$s" is not turned on for this site, so Divi writes no CSS for the "%2$s" group of %3$s. Breakpoints on: %4$s. For phones write "phone".', 'saddle' ),
					$bp,
					$group,
					$location,
					implode( ', ', $enabled )
				);
				continue;
			}
			if ( ! is_array( $bp_payload ) ) {
				continue;
			}
			foreach ( $bp_payload as $state => $state_payload ) {
				$state = (string) $state;
				if ( ! in_array( $state, self::STATES, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: written key, 2: group, 3: module + address, 4: valid states. */
						__( '"%1$s" is not a value state on the "%2$s" group of %3$s — the styling will silently not render. Expected one of: %4$s.', 'saddle' ),
						$state,
						$group,
						$location,
						implode( ', ', self::STATES )
					);
					continue;
				}
				// hover, sticky and the other states hold the same bare
				// settings as value, never a second value wrapper.
				if ( 'value' !== $state && is_array( $state_payload ) && array( 'value' ) === array_map( 'strval', array_keys( $state_payload ) ) ) {
					$warnings[] = sprintf(
						/* translators: 1: state, 2: group, 3: module + address, 4: path to the breakpoint. */
						__( 'The "%1$s" state on the "%2$s" group of %3$s wraps its settings in "value", so Divi will not use them. Write them straight under the state: %4$s.%1$s.<field>, not %4$s.%1$s.value.<field>.', 'saddle' ),
						$state,
						$group,
						$location,
						$base . '.' . $bp
					);
					continue;
				}
				if ( array() === $closed || ! is_array( $state_payload ) ) {
					continue;
				}
				foreach ( array_keys( $state_payload ) as $leaf ) {
					$leaf = (string) $leaf;
					if ( ! in_array( $leaf, $closed, true ) ) {
						$warnings[] = sprintf(
							/* translators: 1: written field, 2: group, 3: module + address, 4: valid fields. */
							__( '"%1$s" is not a settable field of the "%2$s" group on %3$s — the styling will silently not render. Fields: %4$s.', 'saddle' ),
							$leaf,
							$group,
							$location,
							implode( ', ', $closed )
						);
					}
				}
			}
		}
		return $warnings;
	}

	/**
	 * Whether any breakpoint key appears anywhere in the subtree.
	 *
	 * @param array $node Subtree.
	 * @return bool
	 */
	private static function contains_breakpoint( array $node ) {
		foreach ( $node as $key => $value ) {
			if ( in_array( (string) $key, self::breakpoints(), true ) ) {
				return true;
			}
			if ( is_array( $value ) && self::contains_breakpoint( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/*
	---------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------
	 */

	/**
	 * Check one attrs entry — a dotted path key, or a nested object — against
	 * the module contract.
	 *
	 * @param string $key      Top-level attrs key (possibly dotted).
	 * @param mixed  $value    Its value.
	 * @param array  $contract Module contract (see contract()).
	 * @param string $location Module + address for messages.
	 * @param string $type     Module type, for the layout settings it renders.
	 * @return string[]
	 */
	private static function check_attr_path( $key, $value, array $contract, $location, $type = '' ) {
		$segments  = explode( '.', $key );
		$attr_name = $segments[0];

		if ( in_array( $attr_name, self::UNIVERSAL_KEYS, true ) ) {
			return array();
		}

		if ( ! isset( $contract['attributes'][ $attr_name ] ) ) {
			return array(
				sprintf(
					/* translators: 1: attr name, 2: module + address, 3: attribute list. */
					__( 'Attribute "%1$s" on %2$s is not part of this module — Divi will store and ignore it. Module attributes: %3$s.', 'saddle' ),
					$attr_name,
					$location,
					implode( ', ', array_keys( $contract['attributes'] ) )
				),
			);
		}

		// Collect the decoration/advanced groups this entry addresses, from
		// the dotted key and/or the nested value, then verify each against
		// the groups the attribute declares in module.json.
		$warnings = array();
		foreach ( array( 'decoration', 'advanced' ) as $kind ) {
			$declared = $contract['attributes'][ $attr_name ][ $kind ];
			if ( null === $declared || ( 'advanced' === $kind && array() === $declared ) ) {
				// None declared, or an empty advanced object: Divi's form
				// fields declare `"advanced": {}` and still read focus
				// styling there, so an empty list proves nothing.
				continue;
			}
			foreach ( self::addressed_groups( $segments, $value, $kind ) as $group ) {
				if ( in_array( $group, $declared, true ) ) {
					continue;
				}
				// Undeclared advanced groups Divi reads on every module or field.
				if ( 'advanced' === $kind && 'module' === $attr_name && in_array( $group, self::MODULE_ADVANCED, true ) ) {
					continue;
				}
				if ( 'advanced' === $kind && in_array( $group, self::FIELD_ADVANCED, true )
					&& ( 'field' === $attr_name || 'field' === $contract['attributes'][ $attr_name ]['element_type'] ) ) {
					continue;
				}
				// Undeclared but universal: ElementStyle renders it anyway
				// (declaration is a VB-UI fact, not a render fact).
				if ( 'decoration' === $kind && in_array( $group, self::ELEMENT_STYLE_GROUPS, true ) ) {
					continue;
				}
				// Undeclared layout settings the structural modules render.
				if ( 'advanced' === $kind && 'module' === $attr_name && isset( self::LAYOUT_ADVANCED[ $type ] )
					&& in_array( $group, self::LAYOUT_ADVANCED[ $type ], true ) ) {
					continue;
				}
				$warnings[] = sprintf(
					/* translators: 1: group kind, 2: group, 3: attr name, 4: module + address, 5: valid groups. */
					__( 'No "%2$s" %1$s group exists on "%3$s" of %4$s — the styling will silently not render. Valid %1$s groups: %5$s.', 'saddle' ),
					$kind,
					$group,
					$attr_name,
					$location,
					$declared ? implode( ', ', $declared ) : __( '(none)', 'saddle' )
				);
			}
		}
		return $warnings;
	}

	/**
	 * The $kind groups a payload addresses: "<attr>.<kind>.<group>…" in the
	 * dotted key, or the keys under $value['<kind>'] / $value[…] when the
	 * payload is nested.
	 *
	 * @param string[] $segments Dotted key segments (attr name first).
	 * @param mixed    $value    The entry value.
	 * @param string   $kind     'decoration' or 'advanced'.
	 * @return string[]
	 */
	private static function addressed_groups( array $segments, $value, $kind ) {
		// Dotted form: attr.kind.group...
		if ( isset( $segments[1], $segments[2] ) && $segments[1] === $kind ) {
			return array( $segments[2] );
		}
		// Dotted up to the kind, nested from there: attr.kind = {group: …}.
		if ( isset( $segments[1] ) && $segments[1] === $kind && ! isset( $segments[2] ) && is_array( $value ) ) {
			return array_map( 'strval', array_keys( $value ) );
		}
		// Fully nested: attr = {kind: {group: …}}.
		if ( ! isset( $segments[1] ) && is_array( $value ) && isset( $value[ $kind ] ) && is_array( $value[ $kind ] ) ) {
			return array_map( 'strval', array_keys( $value[ $kind ] ) );
		}
		return array();
	}

	/**
	 * A module's echo contract from its module.json, or null when the module
	 * has none on disk: attributes (name → {decoration: string[]|null,
	 * advanced: string[]|null}) and the content-field name list.
	 *
	 * @param string $type Module type.
	 * @return array|null
	 */
	private static function contract( $type ) {
		static $memo = array();
		// Keyed by the module.json the index resolves, so a rebuilt index (a
		// module pack switched on, a test fixture) is never answered from an
		// older read.
		$index = Saddle_Divi_Schema::index();
		$key   = $type . '|' . ( isset( $index[ $type ] ) ? (string) $index[ $type ] : '' );
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		$described = Saddle_Divi_Schema::describe( $type );
		if ( is_wp_error( $described ) ) {
			$memo[ $key ] = null;
			return null;
		}

		$attributes     = array();
		$content        = array();
		$content_fields = array();
		foreach ( $described['attributes'] as $entry ) {
			$attributes[ $entry['name'] ] = array(
				'decoration'   => isset( $entry['decorations'] ) ? array_map( 'strval', $entry['decorations'] ) : null,
				'advanced'     => isset( $entry['advanced_options'] ) ? array_map( 'strval', $entry['advanced_options'] ) : null,
				'element_type' => isset( $entry['elementType'] ) ? (string) $entry['elementType'] : '',
			);
			if ( ! empty( $entry['content'] ) ) {
				$content[] = (string) $entry['name'];
				if ( ! empty( $entry['content_fields'] ) && is_array( $entry['content_fields'] ) ) {
					$content_fields[ (string) $entry['name'] ] = array_map( 'strval', $entry['content_fields'] );
				}
			}
		}

		$memo[ $key ] = array(
			'attributes'     => $attributes,
			'content'        => $content,
			'content_fields' => $content_fields,
		);
		return $memo[ $key ];
	}
}
