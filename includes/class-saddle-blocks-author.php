<?php
/**
 * The Gutenberg authoring layer: agent nodes → canonical editor blocks.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expand agent-authored nodes {type, content, attrs, children} into canonical
 * block arrays whose markup the block editor accepts as ITS OWN save output.
 *
 * Why this exists: a Gutenberg block is comment-JSON attributes PLUS saved
 * HTML, and several attributes (an image's src, a button's href, all preset
 * color/size classes) live only in that HTML. An agent writing raw markup
 * gets the class conventions wrong and the editor flags every block as
 * invalid. So agents write intent — "content" for the text/media payload,
 * "attrs" for real block attributes — and this layer produces the exact
 * markup the editor's save() would have produced, for a curated set of the
 * everyday core blocks:
 *
 *   paragraph, heading, list, list-item, quote, code, image, button,
 *   buttons, group, columns, column, separator, spacer, html
 *
 * Outside the curated set: dynamic blocks (server-rendered — anything with a
 * render callback) are attrs-only and need no markup, so every dynamic block
 * on the site is authorable; other static blocks require an explicit "html"
 * key (the agent supplies the exact inner markup and owns its validity).
 * Unregistered block types are refused outright — the server can't know
 * their contract. Escape hatch: "html" also overrides the template on
 * curated blocks.
 */
class Saddle_Blocks_Author {

	/**
	 * Expand a list of authored root nodes into a block tree.
	 *
	 * @param array[] $nodes Authored nodes.
	 * @return array[]|WP_Error Block tree, or the first expansion error.
	 */
	public static function expand( array $nodes ) {
		$tree = array();
		foreach ( array_values( $nodes ) as $i => $node ) {
			$block = self::expand_node( $node, (string) $i );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
			$tree[] = $block;
		}
		return $tree;
	}

	/**
	 * Expand one authored node (recursively).
	 *
	 * @param mixed  $node    Authored node {type, content, attrs, children, html}.
	 * @param string $address Position for error messages ('' when unknown).
	 * @return array|WP_Error Canonical block array.
	 */
	public static function expand_node( $node, $address = '' ) {
		if ( ! is_array( $node ) || empty( $node['type'] ) || ! is_string( $node['type'] ) ) {
			return new WP_Error(
				'saddle_bad_node',
				sprintf(
					/* translators: %s: node address. */
					__( 'Each node needs a "type" (node %s). See saddle/list-block-types.', 'saddle' ),
					'' === $address ? '?' : $address
				)
			);
		}

		$type       = trim( $node['type'] );
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $type );
		if ( ! $block_type ) {
			return new WP_Error(
				'saddle_unknown_block',
				sprintf(
					/* translators: %s: block type. */
					__( '"%s" is not a block type this site\'s server knows, so Saddle cannot compose valid markup for it. Pick a type from saddle/list-block-types.', 'saddle' ),
					$type
				)
			);
		}

		$attrs   = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$content = isset( $node['content'] ) ? $node['content'] : '';

		// Shorthands that create children, before children are expanded.
		$authored_children = isset( $node['children'] ) && is_array( $node['children'] ) ? array_values( $node['children'] ) : array();
		if ( 'core/list' === $type && is_array( $content ) ) {
			foreach ( $content as $item ) {
				$authored_children[] = array(
					'type'    => 'core/list-item',
					'content' => (string) $item,
				);
			}
			$content = '';
		}
		if ( 'core/quote' === $type && is_string( $content ) && '' !== trim( $content ) ) {
			array_unshift(
				$authored_children,
				array(
					'type'    => 'core/paragraph',
					'content' => $content,
				)
			);
			$content = '';
		}

		$children = array();
		foreach ( $authored_children as $i => $child ) {
			$expanded = self::expand_node( $child, '' === $address ? (string) $i : $address . '.' . $i );
			if ( is_wp_error( $expanded ) ) {
				return $expanded;
			}
			$children[] = $expanded;
		}

		// Explicit raw markup: the agent owns its validity.
		if ( isset( $node['html'] ) && is_string( $node['html'] ) ) {
			if ( $children ) {
				return new WP_Error(
					'saddle_bad_node',
					sprintf(
						/* translators: %s: node address. */
						__( 'A node with raw "html" cannot also have "children" (node %s).', 'saddle' ),
						$address
					)
				);
			}
			return self::static_block( $type, $attrs, $node['html'] );
		}

		$block = self::compose( $type, $block_type, $attrs, $content, $children, $address );
		return $block;
	}

	/* ---------------------------------------------------------------------
	 * Per-block templates
	 * ------------------------------------------------------------------- */

	/**
	 * Compose the canonical block for a type.
	 *
	 * @param string        $type       Block type name.
	 * @param WP_Block_Type $block_type Registered type.
	 * @param array         $attrs      Authored attributes.
	 * @param mixed         $content    Authored content payload.
	 * @param array[]       $children   Expanded child blocks.
	 * @param string        $address    Position for error messages.
	 * @return array|WP_Error
	 */
	private static function compose( $type, $block_type, array $attrs, $content, array $children, $address ) {
		$text = is_string( $content ) ? $content : '';

		switch ( $type ) {
			case 'core/paragraph':
				$extra = ! empty( $attrs['dropCap'] ) ? array( 'has-drop-cap' ) : array();
				return self::static_block( $type, $attrs, self::tag( 'p', self::classnames( $attrs, $extra ), self::inline_style( $attrs ) ) . $text . '</p>' );

			case 'core/heading':
				$level = isset( $attrs['level'] ) ? max( 1, min( 6, (int) $attrs['level'] ) ) : 2;
				$tag   = 'h' . $level;
				return self::static_block( $type, $attrs, self::tag( $tag, self::classnames( $attrs, array( 'wp-block-heading' ) ), self::inline_style( $attrs ) ) . $text . '</' . $tag . '>' );

			case 'core/list':
				$tag = ! empty( $attrs['ordered'] ) ? 'ol' : 'ul';
				return self::container_block( $type, $attrs, self::tag( $tag, self::classnames( $attrs, array( 'wp-block-list' ) ), self::inline_style( $attrs ) ), '</' . $tag . '>', $children );

			case 'core/list-item':
				if ( $children ) {
					// A nested list rides inside the parent <li>.
					return self::container_block( $type, $attrs, '<li>' . $text, '</li>', $children );
				}
				return self::static_block( $type, $attrs, '<li>' . $text . '</li>' );

			case 'core/quote':
				return self::container_block( $type, $attrs, self::tag( 'blockquote', self::classnames( $attrs, array( 'wp-block-quote' ) ), self::inline_style( $attrs ) ), '</blockquote>', $children );

			case 'core/code':
				return self::static_block( $type, $attrs, self::tag( 'pre', self::classnames( $attrs, array( 'wp-block-code' ) ), self::inline_style( $attrs ) ) . '<code>' . esc_html( $text ) . '</code></pre>' );

			case 'core/image':
				return self::compose_image( $attrs, $content, $address );

			case 'core/button':
				return self::compose_button( $attrs, $text, $address );

			case 'core/buttons':
				return self::container_block( $type, $attrs, self::tag( 'div', self::classnames( $attrs, array( 'wp-block-buttons' ) ), self::inline_style( $attrs ) ), '</div>', $children );

			case 'core/group':
				if ( empty( $attrs['layout'] ) ) {
					$attrs['layout'] = array( 'type' => 'constrained' );
				}
				$tag = isset( $attrs['tagName'] ) && is_string( $attrs['tagName'] ) && '' !== $attrs['tagName'] ? $attrs['tagName'] : 'div';
				return self::container_block( $type, $attrs, self::tag( $tag, self::classnames( $attrs, array( 'wp-block-group' ) ), self::inline_style( $attrs ) ), '</' . $tag . '>', $children );

			case 'core/columns':
				return self::container_block( $type, $attrs, self::tag( 'div', self::classnames( $attrs, array( 'wp-block-columns' ) ), self::inline_style( $attrs ) ), '</div>', $children );

			case 'core/column':
				$style = self::inline_style( $attrs );
				if ( ! empty( $attrs['width'] ) ) {
					$width = is_numeric( $attrs['width'] ) ? $attrs['width'] . '%' : (string) $attrs['width'];
					$style = trim( 'flex-basis:' . $width . ';' . $style, ';' );
				}
				return self::container_block( $type, $attrs, self::tag( 'div', self::classnames( $attrs, array( 'wp-block-column' ) ), $style ), '</div>', $children );

			case 'core/separator':
				return self::static_block( $type, $attrs, self::tag( 'hr', self::classnames( $attrs, array( 'wp-block-separator', 'has-alpha-channel-opacity' ) ), self::inline_style( $attrs ), true ) );

			case 'core/spacer':
				$height = isset( $attrs['height'] ) ? $attrs['height'] : '100px';
				$height = is_numeric( $height ) ? $height . 'px' : (string) $height;

				$attrs['height'] = $height;
				return self::static_block( $type, $attrs, '<div style="' . esc_attr( 'height:' . $height ) . '" aria-hidden="true" class="wp-block-spacer"></div>' );

			case 'core/html':
				return self::static_block( $type, $attrs, $text );
		}

		// Dynamic blocks render server-side from attrs; there is no markup to compose.
		if ( $block_type->is_dynamic() ) {
			if ( ( is_string( $content ) && '' !== trim( $content ) ) || $children ) {
				return new WP_Error(
					'saddle_bad_node',
					sprintf(
						/* translators: 1: block type, 2: node address. */
						__( '%1$s renders dynamically on the server — it takes "attrs" only, not "content" or "children" (node %2$s). See saddle/get-block-schema for its attributes.', 'saddle' ),
						$type,
						$address
					)
				);
			}
			return self::static_block( $type, $attrs, '' );
		}

		return new WP_Error(
			'saddle_no_template',
			sprintf(
				/* translators: %s: block type. */
				__( 'Saddle has no composition template for the static block "%s". Either build the layout from the supported core blocks (see saddle/get-block-schema), or pass this node an explicit "html" string with the exact saved markup — you then own its validity in the editor.', 'saddle' ),
				$type
			)
		);
	}

	/**
	 * core/image: src/alt/caption live in markup, not comment JSON.
	 *
	 * @param array  $attrs   Authored attributes.
	 * @param mixed  $content String src, or {src, alt, caption}.
	 * @param string $address Position for error messages.
	 * @return array|WP_Error
	 */
	private static function compose_image( array $attrs, $content, $address ) {
		$payload = is_array( $content ) ? $content : array( 'src' => (string) $content );
		$src     = ! empty( $payload['src'] ) ? (string) $payload['src'] : ( ! empty( $attrs['url'] ) ? (string) $attrs['url'] : '' );
		$alt     = isset( $payload['alt'] ) ? (string) $payload['alt'] : ( isset( $attrs['alt'] ) ? (string) $attrs['alt'] : '' );
		$caption = isset( $payload['caption'] ) ? (string) $payload['caption'] : '';

		// These are markup-sourced; carrying them in the JSON would be ignored
		// by the editor and eventually shed.
		unset( $attrs['url'], $attrs['alt'], $attrs['caption'] );

		if ( '' === $src ) {
			return new WP_Error(
				'saddle_bad_node',
				sprintf(
					/* translators: %s: node address. */
					__( 'core/image needs content.src — the image URL (node %s). Upload first with saddle/upload-media, then use the returned URL and id.', 'saddle' ),
					$address
				)
			);
		}

		$figure_classes = array( 'wp-block-image' );
		if ( ! empty( $attrs['sizeSlug'] ) ) {
			$figure_classes[] = 'size-' . $attrs['sizeSlug'];
		}

		$img = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"';
		if ( ! empty( $attrs['id'] ) ) {
			$img .= ' class="' . esc_attr( 'wp-image-' . (int) $attrs['id'] ) . '"';
		}
		$img .= '/>';

		$html = self::tag( 'figure', self::classnames( $attrs, $figure_classes ), self::inline_style( $attrs ) ) . $img;
		if ( '' !== $caption ) {
			$html .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		}
		$html .= '</figure>';

		return self::static_block( 'core/image', $attrs, $html );
	}

	/**
	 * core/button: text and href live in markup; style classes go on the link.
	 *
	 * @param array  $attrs   Authored attributes.
	 * @param string $text    Button label.
	 * @param string $address Position for error messages.
	 * @return array|WP_Error
	 */
	private static function compose_button( array $attrs, $text, $address ) {
		if ( '' === trim( $text ) ) {
			return new WP_Error(
				'saddle_bad_node',
				sprintf(
					/* translators: %s: node address. */
					__( 'core/button needs its label as "content" (node %s).', 'saddle' ),
					$address
				)
			);
		}

		$url = ! empty( $attrs['url'] ) ? (string) $attrs['url'] : '';
		unset( $attrs['url'] ); // Markup-sourced (the href).

		// The block's own class + custom className wrap the outer div; the
		// color/typography presets serialize onto the link element.
		$outer = array( 'wp-block-button' );
		if ( ! empty( $attrs['className'] ) ) {
			$outer[] = (string) $attrs['className'];
		}

		$link_attrs              = $attrs;
		$link_attrs['className'] = '';

		$link = self::tag(
			'a',
			array_merge( array( 'wp-block-button__link' ), self::classnames( $link_attrs, array() ), array( 'wp-element-button' ) ),
			self::inline_style( $attrs ),
			false,
			'' !== $url ? array( 'href' => esc_url( $url ) ) : array()
		);

		return self::static_block(
			'core/button',
			$attrs,
			'<div class="' . esc_attr( implode( ' ', $outer ) ) . '">' . $link . $text . '</a></div>'
		);
	}

	/* ---------------------------------------------------------------------
	 * Markup + block-array helpers
	 * ------------------------------------------------------------------- */

	/**
	 * The preset/style classes a block's saved markup must carry for its
	 * attributes to take effect (the editor derives these from attrs on save;
	 * we must match them or the block validates as foreign).
	 *
	 * @param array    $attrs Authored attributes.
	 * @param string[] $base  The block's own base classes.
	 * @return string[]
	 */
	private static function classnames( array $attrs, array $base = array() ) {
		$classes = $base;

		if ( ! empty( $attrs['align'] ) && in_array( $attrs['align'], array( 'wide', 'full', 'left', 'right', 'center' ), true ) ) {
			$classes[] = 'align' . $attrs['align'];
		}
		if ( ! empty( $attrs['textAlign'] ) ) {
			$classes[] = 'has-text-align-' . $attrs['textAlign'];
		}
		if ( ! empty( $attrs['backgroundColor'] ) ) {
			$classes[] = 'has-' . $attrs['backgroundColor'] . '-background-color';
			$classes[] = 'has-background';
		}
		if ( ! empty( $attrs['textColor'] ) ) {
			$classes[] = 'has-' . $attrs['textColor'] . '-color';
			$classes[] = 'has-text-color';
		}
		if ( ! empty( $attrs['gradient'] ) ) {
			$classes[] = 'has-' . $attrs['gradient'] . '-gradient-background';
			$classes[] = 'has-background';
		}
		if ( ! empty( $attrs['fontSize'] ) ) {
			$classes[] = 'has-' . $attrs['fontSize'] . '-font-size';
		}
		if ( ! empty( $attrs['fontFamily'] ) ) {
			$classes[] = 'has-' . $attrs['fontFamily'] . '-font-family';
		}
		if ( ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
			$classes[] = $attrs['className'];
		}

		if ( ! empty( $attrs['style'] ) && is_array( $attrs['style'] ) && function_exists( 'wp_style_engine_get_styles' ) ) {
			$engine = wp_style_engine_get_styles( $attrs['style'] );
			if ( ! empty( $engine['classnames'] ) ) {
				$classes = array_merge( $classes, explode( ' ', $engine['classnames'] ) );
			}
		}

		return array_values( array_unique( array_filter( $classes ) ) );
	}

	/**
	 * Inline CSS a block's saved markup must carry for its `style` attribute.
	 *
	 * @param array $attrs Authored attributes.
	 * @return string
	 */
	private static function inline_style( array $attrs ) {
		if ( empty( $attrs['style'] ) || ! is_array( $attrs['style'] ) || ! function_exists( 'wp_style_engine_get_styles' ) ) {
			return '';
		}
		$engine = wp_style_engine_get_styles( $attrs['style'] );
		return ! empty( $engine['css'] ) ? (string) $engine['css'] : '';
	}

	/**
	 * Render an opening (or void) tag.
	 *
	 * @param string   $name    Tag name.
	 * @param string[] $classes Class list (may be empty).
	 * @param string   $style   Inline CSS (may be '').
	 * @param bool     $void    Self-closing (hr).
	 * @param array    $extra   Extra attribute map (values pre-escaped).
	 * @return string
	 */
	private static function tag( $name, array $classes, $style = '', $void = false, array $extra = array() ) {
		$html = '<' . $name;
		if ( $classes ) {
			$html .= ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		}
		if ( '' !== $style ) {
			$html .= ' style="' . esc_attr( $style ) . '"';
		}
		foreach ( $extra as $key => $value ) {
			$html .= ' ' . $key . '="' . $value . '"';
		}
		return $html . ( $void ? '/>' : '>' );
	}

	/**
	 * Build a static (leaf) block array.
	 *
	 * @param string $type  Block type.
	 * @param array  $attrs Comment-JSON attributes.
	 * @param string $html  Saved inner markup ('' for dynamic blocks).
	 * @return array
	 */
	private static function static_block( $type, array $attrs, $html ) {
		return array(
			'blockName'    => $type,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => '' !== $html ? array( $html ) : array(),
		);
	}

	/**
	 * Build a container block array with wrapper markup around its children.
	 *
	 * @param string  $type     Block type.
	 * @param array   $attrs    Comment-JSON attributes.
	 * @param string  $open     Opening wrapper markup.
	 * @param string  $close    Closing wrapper markup.
	 * @param array[] $children Child block arrays.
	 * @return array
	 */
	private static function container_block( $type, array $attrs, $open, $close, array $children ) {
		$inner_content = $children
			? array_merge( array( $open ), array_fill( 0, count( $children ), null ), array( $close ) )
			: array( $open . $close );

		return array(
			'blockName'    => $type,
			'attrs'        => $attrs,
			'innerBlocks'  => array_values( $children ),
			'innerHTML'    => $open . $close,
			'innerContent' => $inner_content,
		);
	}
}
