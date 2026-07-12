<?php
/**
 * Block-type schemas, theme design tokens, and pattern summaries for agents.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only providers behind the block design abilities: the block-type
 * catalog and per-type schemas from WP_Block_Type_Registry, the site's
 * design tokens from theme.json (via WP_Theme_JSON_Resolver), and block
 * pattern summaries from WP_Block_Patterns_Registry. Everything here is
 * introspection over what THIS site actually registers — no hardcoded
 * WordPress knowledge that could drift from the install.
 */
class Saddle_Blocks_Schema {

	/**
	 * The block types Saddle_Blocks_Author has full composition templates for.
	 *
	 * @return string[]
	 */
	public static function curated_types() {
		return array(
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/code',
			'core/image',
			'core/button',
			'core/buttons',
			'core/group',
			'core/columns',
			'core/column',
			'core/separator',
			'core/spacer',
			'core/html',
		);
	}

	/**
	 * Catalog of registered block types, as compact summaries.
	 *
	 * @param string $search   Optional keyword filter (name/title/description).
	 * @param string $category Optional block category filter.
	 * @return array[] Summaries: name, title, category, authoring, one-line purpose.
	 */
	public static function catalog( $search = '', $category = '' ) {
		$search  = strtolower( trim( (string) $search ) );
		$out     = array();
		$curated = self::curated_types();

		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( '' !== $category && $category !== (string) $type->category ) {
				continue;
			}

			$title       = (string) $type->title;
			$description = (string) $type->description;

			if ( '' !== $search
				&& false === strpos( strtolower( $name ), $search )
				&& false === strpos( strtolower( $title ), $search )
				&& false === strpos( strtolower( $description ), $search ) ) {
				continue;
			}

			$out[] = array(
				'name'      => $name,
				'title'     => $title,
				'category'  => (string) $type->category,
				'authoring' => self::authoring_mode( $name, $type, $curated ),
				'purpose'   => mb_substr( $description, 0, 140 ),
			);
		}

		// Curated types missing from the server registry (some core blocks
		// register only in the editor's JS) are still fully authorable —
		// surface them so agents don't conclude they're unavailable.
		$listed = wp_list_pluck( $out, 'name' );
		foreach ( array_diff( $curated, array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) ) as $name ) {
			if ( in_array( $name, $listed, true ) ) {
				continue;
			}
			$summary = self::curated_summary( $name );
			if ( '' !== $category && $category !== $summary['category'] ) {
				continue;
			}
			if ( '' !== $search
				&& false === strpos( strtolower( $summary['name'] ), $search )
				&& false === strpos( strtolower( $summary['title'] ), $search )
				&& false === strpos( strtolower( $summary['purpose'] ), $search ) ) {
				continue;
			}
			$out[] = $summary;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Catalog summary for a curated type absent from the server registry.
	 *
	 * @param string $name Curated block type name.
	 * @return array
	 */
	private static function curated_summary( $name ) {
		$known = array(
			'core/paragraph' => array( __( 'Paragraph', 'saddle' ), 'text', __( 'A paragraph of rich text.', 'saddle' ) ),
			'core/heading'   => array( __( 'Heading', 'saddle' ), 'text', __( 'A heading (h1–h6) that structures the page.', 'saddle' ) ),
			'core/list'      => array( __( 'List', 'saddle' ), 'text', __( 'A bulleted or numbered list.', 'saddle' ) ),
			'core/list-item' => array( __( 'List item', 'saddle' ), 'text', __( 'One item inside a list.', 'saddle' ) ),
			'core/quote'     => array( __( 'Quote', 'saddle' ), 'text', __( 'A visually emphasized quotation.', 'saddle' ) ),
			'core/code'      => array( __( 'Code', 'saddle' ), 'text', __( 'Preformatted code, displayed verbatim.', 'saddle' ) ),
			'core/image'     => array( __( 'Image', 'saddle' ), 'media', __( 'A single image with optional caption.', 'saddle' ) ),
			'core/button'    => array( __( 'Button', 'saddle' ), 'design', __( 'A call-to-action link styled as a button.', 'saddle' ) ),
			'core/buttons'   => array( __( 'Buttons', 'saddle' ), 'design', __( 'A row of buttons.', 'saddle' ) ),
			'core/group'     => array( __( 'Group', 'saddle' ), 'design', __( 'A container that groups blocks, e.g. as a banded section.', 'saddle' ) ),
			'core/columns'   => array( __( 'Columns', 'saddle' ), 'design', __( 'A multi-column row; holds core/column children.', 'saddle' ) ),
			'core/column'    => array( __( 'Column', 'saddle' ), 'design', __( 'One column inside core/columns.', 'saddle' ) ),
			'core/separator' => array( __( 'Separator', 'saddle' ), 'design', __( 'A horizontal divider between sections.', 'saddle' ) ),
			'core/spacer'    => array( __( 'Spacer', 'saddle' ), 'design', __( 'Vertical whitespace of a set height.', 'saddle' ) ),
			'core/html'      => array( __( 'Custom HTML', 'saddle' ), 'widgets', __( 'Raw HTML rendered verbatim. Last resort — prefer real blocks.', 'saddle' ) ),
		);
		$info  = isset( $known[ $name ] ) ? $known[ $name ] : array( $name, '', '' );

		return array(
			'name'      => $name,
			'title'     => $info[0],
			'category'  => $info[1],
			'authoring' => 'content',
			'purpose'   => $info[2],
		);
	}

	/**
	 * How a block type is authored through saddle/set-blocks & friends.
	 *
	 * @param string        $name    Block type name.
	 * @param WP_Block_Type $type    Registered type.
	 * @param string[]      $curated Curated type list.
	 * @return string 'content' (templated), 'attrs-only' (dynamic), or 'raw-html'.
	 */
	private static function authoring_mode( $name, $type, array $curated ) {
		if ( in_array( $name, $curated, true ) ) {
			return 'content';
		}
		if ( $type->is_dynamic() ) {
			return 'attrs-only';
		}
		return 'raw-html';
	}

	/**
	 * One block type's schema plus how to author it.
	 *
	 * @param string $name Block type name.
	 * @return array|WP_Error
	 */
	public static function describe( $name ) {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( ! $type ) {
			// Curated types stay describable without a server registration —
			// authoring guidance is Saddle's own; only the attribute list
			// depends on the registry.
			if ( in_array( $name, self::curated_types(), true ) ) {
				$summary = self::curated_summary( $name );
				return array(
					'name'        => $name,
					'title'       => $summary['title'],
					'description' => $summary['purpose'],
					'category'    => $summary['category'],
					'dynamic'     => false,
					'attributes'  => (object) array(),
					'note'        => __( 'This block registers only in the editor\'s JS on this site, so its attribute list is not exposed here — but Saddle authors it fully; follow the example below.', 'saddle' ),
					'authoring'   => self::authoring_guidance( $name, 'content' ),
				);
			}

			return new WP_Error(
				'saddle_unknown_block',
				sprintf(
					/* translators: %s: block type. */
					__( '"%s" is not registered on this site\'s server. saddle/list-block-types shows what is.', 'saddle' ),
					$name
				)
			);
		}

		$attributes = array();
		foreach ( (array) $type->attributes as $attr => $def ) {
			$def   = is_array( $def ) ? $def : array();
			$entry = array( 'type' => isset( $def['type'] ) ? $def['type'] : 'unknown' );
			if ( array_key_exists( 'default', $def ) ) {
				$entry['default'] = $def['default'];
			}
			if ( isset( $def['enum'] ) ) {
				$entry['enum'] = $def['enum'];
			}
			// Markup-sourced attributes live in the saved HTML, not the attrs
			// JSON — for templated blocks the "content" payload feeds them; for
			// raw-html blocks they belong in the html string.
			if ( isset( $def['source'] ) ) {
				$entry['markup_sourced'] = true;
			}
			$attributes[ $attr ] = $entry;
		}

		$mode = self::authoring_mode( $name, $type, self::curated_types() );

		$out = array(
			'name'        => $name,
			'title'       => (string) $type->title,
			'description' => (string) $type->description,
			'category'    => (string) $type->category,
			'dynamic'     => $type->is_dynamic(),
			'attributes'  => $attributes,
			'authoring'   => self::authoring_guidance( $name, $mode ),
		);

		if ( ! empty( $type->parent ) ) {
			$out['must_be_direct_child_of'] = (array) $type->parent;
		}
		if ( ! empty( $type->ancestor ) ) {
			$out['must_be_inside'] = (array) $type->ancestor;
		}
		if ( isset( $type->allowed_blocks ) && is_array( $type->allowed_blocks ) && $type->allowed_blocks ) {
			$out['allowed_children'] = $type->allowed_blocks;
		}
		if ( ! empty( $type->supports ) && is_array( $type->supports ) ) {
			$out['supports'] = $type->supports;
		}

		return $out;
	}

	/**
	 * Authoring guidance per mode, with a concrete example for curated types.
	 *
	 * @param string $name Block type name.
	 * @param string $mode 'content' | 'attrs-only' | 'raw-html'.
	 * @return array{mode:string,how:string,example?:array}
	 */
	private static function authoring_guidance( $name, $mode ) {
		if ( 'attrs-only' === $mode ) {
			return array(
				'mode' => $mode,
				'how'  => __( 'Renders dynamically on the server. Author it as {"type","attrs"} only — no "content", no "children"; the markup is generated at view time from the attributes above.', 'saddle' ),
			);
		}

		if ( 'raw-html' === $mode ) {
			return array(
				'mode' => $mode,
				'how'  => __( 'A static block Saddle has no template for. Prefer building from the templated core blocks; if you must use this type, pass {"type","attrs","html"} where "html" is the exact saved inner markup (markup-sourced attributes included) — you own its validity in the editor.', 'saddle' ),
			);
		}

		$examples = array(
			'core/paragraph' => array(
				'type'    => 'core/paragraph',
				'content' => 'Inline HTML like <strong>bold</strong> and <a href="/about">links</a> is fine.',
			),
			'core/heading'   => array(
				'type'    => 'core/heading',
				'content' => 'Section title',
				'attrs'   => array( 'level' => 2 ),
			),
			'core/list'      => array(
				'type'    => 'core/list',
				'content' => array( 'First item', 'Second item' ),
			),
			'core/list-item' => array(
				'type'    => 'core/list-item',
				'content' => 'One item (usually authored via core/list\'s content shorthand)',
			),
			'core/quote'     => array(
				'type'    => 'core/quote',
				'content' => 'The quoted line. Add a plain paragraph child for attribution.',
			),
			'core/code'      => array(
				'type'    => 'core/code',
				'content' => "echo 'hello'; // escaped for you",
			),
			'core/image'     => array(
				'type'    => 'core/image',
				'content' => array(
					'src' => 'https://example.com/uploads/photo.jpg',
					'alt' => 'What the image shows',
				),
				'attrs'   => array( 'id' => 123 ),
			),
			'core/button'    => array(
				'type'    => 'core/button',
				'content' => 'Get started',
				'attrs'   => array( 'url' => '/signup' ),
			),
			'core/buttons'   => array(
				'type'     => 'core/buttons',
				'children' => array(
					array(
						'type'    => 'core/button',
						'content' => 'Get started',
						'attrs'   => array( 'url' => '/signup' ),
					),
				),
			),
			'core/group'     => array(
				'type'     => 'core/group',
				'attrs'    => array( 'backgroundColor' => 'a-palette-slug-from-get-design-tokens' ),
				'children' => array(
					array(
						'type'    => 'core/paragraph',
						'content' => '…',
					),
				),
			),
			'core/columns'   => array(
				'type'     => 'core/columns',
				'children' => array(
					array(
						'type'     => 'core/column',
						'children' => array(
							array(
								'type'    => 'core/paragraph',
								'content' => 'Left',
							),
						),
					),
					array(
						'type'     => 'core/column',
						'children' => array(
							array(
								'type'    => 'core/paragraph',
								'content' => 'Right',
							),
						),
					),
				),
			),
			'core/column'    => array(
				'type'     => 'core/column',
				'attrs'    => array( 'width' => '33.33%' ),
				'children' => array(
					array(
						'type'    => 'core/paragraph',
						'content' => '…',
					),
				),
			),
			'core/separator' => array( 'type' => 'core/separator' ),
			'core/spacer'    => array(
				'type'  => 'core/spacer',
				'attrs' => array( 'height' => '48px' ),
			),
			'core/html'      => array(
				'type'    => 'core/html',
				'content' => '<!-- raw HTML, shown verbatim -->',
			),
		);

		$guidance = array(
			'mode' => $mode,
			'how'  => __( 'Author it as {"type","content","attrs","children"}. "content" carries the text/media payload (Saddle composes the editor-valid markup, including preset classes from attrs like backgroundColor/textColor/fontSize — use slugs from saddle/get-design-tokens). "attrs" are the block attributes above; markup-sourced ones are fed from "content", so never set those in attrs.', 'saddle' ),
		);
		if ( isset( $examples[ $name ] ) ) {
			$guidance['example'] = $examples[ $name ];
		}
		return $guidance;
	}

	/**
	 * The site's design tokens from merged theme.json data.
	 *
	 * @return array
	 */
	public static function design_tokens() {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return array( 'note' => __( 'This site does not expose theme.json design tokens.', 'saddle' ) );
		}

		$settings = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();

		$out = array(
			'colors'        => self::presets( $settings, array( 'color', 'palette' ) ),
			'gradients'     => self::presets( $settings, array( 'color', 'gradients' ) ),
			'font_sizes'    => self::presets( $settings, array( 'typography', 'fontSizes' ) ),
			'font_families' => self::presets( $settings, array( 'typography', 'fontFamilies' ) ),
			'spacing_sizes' => self::presets( $settings, array( 'spacing', 'spacingSizes' ) ),
			'layout'        => array(
				'content_width' => isset( $settings['layout']['contentSize'] ) ? $settings['layout']['contentSize'] : null,
				'wide_width'    => isset( $settings['layout']['wideSize'] ) ? $settings['layout']['wideSize'] : null,
			),
			'usage'         => __( 'Prefer these presets over hardcoded values so pages inherit the site\'s design: use the SLUG in block attrs ({"backgroundColor":"<color slug>"}, {"textColor":"<color slug>"}, {"fontSize":"<size slug>"}), and var(--wp--preset--color--<slug>) / var(--wp--preset--spacing--<slug>) inside a "style" object. Entries are listed theme-first — theme entries are this site\'s actual brand.', 'saddle' ),
		);

		return $out;
	}

	/**
	 * The unified, builder-agnostic design-system shape.
	 *
	 * Free Saddle fills it from theme.json (the Gutenberg/block-theme source);
	 * a page-builder addon overrides `builder`/`source` and fills the
	 * builder-native slots (colors, variables, presets, fonts) via the
	 * `saddle_design_system` filter, so an agent gets ONE shape whatever the
	 * site is built with. `variables`/`presets` are empty on a plain block
	 * theme — they are builder concepts.
	 *
	 * @return array
	 */
	public static function design_system() {
		$tokens = self::design_tokens();
		$has_tj = ! isset( $tokens['note'] );

		$shape = array(
			'builder'    => wp_is_block_theme() ? 'block-theme' : 'classic',
			'source'     => $has_tj ? 'theme.json' : 'none',
			'colors'     => $has_tj ? $tokens['colors'] : array(),
			'gradients'  => $has_tj ? $tokens['gradients'] : array(),
			'font_sizes' => $has_tj ? $tokens['font_sizes'] : array(),
			'fonts'      => $has_tj ? $tokens['font_families'] : array(),
			'spacing'    => $has_tj ? $tokens['spacing_sizes'] : array(),
			'layout'     => $has_tj ? $tokens['layout'] : array( 'content_width' => null, 'wide_width' => null ),
			'variables'  => array(),
			'presets'    => array(),
			'usage'      => __( 'This is the site\'s single source of design truth — use it before building so pages inherit the real brand instead of inventing one. On a block theme, reference the color/size SLUGS in block attrs; on a page builder, use the color/variable IDs the builder addon lists here. Entries are listed theme/brand-first.', 'saddle' ),
		);

		/**
		 * Filter the unified design system. A builder addon (e.g. Saddle Pro for
		 * Divi) sets `builder`/`source` and fills `colors`/`variables`/`presets`/
		 * `fonts` from the builder's own store, keeping one response shape across
		 * builders.
		 *
		 * @param array $shape The theme.json-derived design system.
		 */
		return apply_filters( 'saddle_design_system', $shape );
	}

	/**
	 * Flatten one preset list from theme.json settings, theme origin first.
	 *
	 * @param array    $settings Merged settings.
	 * @param string[] $path     Two-segment path, e.g. ['color','palette'].
	 * @return array[]
	 */
	private static function presets( array $settings, array $path ) {
		$node = isset( $settings[ $path[0] ][ $path[1] ] ) ? $settings[ $path[0] ][ $path[1] ] : array();
		if ( ! is_array( $node ) ) {
			return array();
		}

		// Origin-keyed ('default'/'theme'/'custom') or already a flat list.
		if ( isset( $node[0] ) ) {
			return array_values( $node );
		}

		$out = array();
		foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
			if ( empty( $node[ $origin ] ) || ! is_array( $node[ $origin ] ) ) {
				continue;
			}
			foreach ( $node[ $origin ] as $preset ) {
				if ( is_array( $preset ) ) {
					$preset['origin'] = $origin;
					$out[]            = $preset;
				}
			}
		}
		return $out;
	}

	/**
	 * Registered block patterns as summaries (content withheld — patterns can
	 * be enormous; insertion goes through saddle/insert-block-pattern).
	 *
	 * @param string $search Optional keyword filter.
	 * @return array[]
	 */
	public static function patterns( $search = '' ) {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return array();
		}

		$search = strtolower( trim( (string) $search ) );
		$out    = array();

		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$name        = isset( $pattern['name'] ) ? (string) $pattern['name'] : '';
			$title       = isset( $pattern['title'] ) ? (string) $pattern['title'] : '';
			$description = isset( $pattern['description'] ) ? (string) $pattern['description'] : '';

			if ( '' !== $search
				&& false === strpos( strtolower( $name ), $search )
				&& false === strpos( strtolower( $title ), $search )
				&& false === strpos( strtolower( $description ), $search ) ) {
				continue;
			}

			$out[] = array(
				'name'        => $name,
				'title'       => $title,
				'description' => mb_substr( $description, 0, 140 ),
				'categories'  => isset( $pattern['categories'] ) ? (array) $pattern['categories'] : array(),
			);
		}

		return $out;
	}

	/**
	 * One registered pattern's parsed content, ready to insert.
	 *
	 * @param string $name Pattern name (e.g. 'core/social-links-shared-background-color').
	 * @return array[]|WP_Error Block tree.
	 */
	public static function pattern_tree( $name ) {
		$registry = class_exists( 'WP_Block_Patterns_Registry' ) ? WP_Block_Patterns_Registry::get_instance() : null;
		$pattern  = $registry ? $registry->get_registered( (string) $name ) : null;

		if ( ! $pattern || empty( $pattern['content'] ) ) {
			return new WP_Error(
				'saddle_unknown_pattern',
				sprintf(
					/* translators: %s: pattern name. */
					__( 'No block pattern named "%s" is registered here. saddle/list-block-patterns shows what is.', 'saddle' ),
					$name
				)
			);
		}

		return Saddle_Blocks_Tree::parse( (string) $pattern['content'] );
	}
}
