<?php
/**
 * The Gutenberg implementation of the lint accessor.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads design facts off native editor blocks. Preset slugs
 * (backgroundColor/textColor) are resolved to their theme.json values so
 * rules compare real colors; raw values in the style object are returned
 * as-is. Facts that don't exist on a block type return null and rules skip.
 */
class Saddle_Lint_Gutenberg_Accessor implements Saddle_Lint_Accessor, Saddle_Lint_Style_Accessor {

	/**
	 * theme.json color slug → value map, built once per instance.
	 *
	 * @var array<string,string>|null
	 */
	private $palette = null;

	/**
	 * theme.json font-size slug → value map, built once per instance.
	 *
	 * @var array<string,string>|null
	 */
	private $font_sizes = null;

	/**
	 * The node's background: style.color.background, or the resolved
	 * backgroundColor preset. Gradients are unresolvable to one color → null.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function background_color( array $node ) {
		$attrs = $this->attrs( $node );

		if ( isset( $attrs['style']['color']['background'] ) && is_string( $attrs['style']['color']['background'] ) ) {
			return $attrs['style']['color']['background'];
		}
		if ( ! empty( $attrs['backgroundColor'] ) && is_string( $attrs['backgroundColor'] ) ) {
			return $this->resolve_palette_slug( $attrs['backgroundColor'] );
		}
		return null;
	}

	/**
	 * The node's text color: style.color.text, or the resolved textColor preset.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function text_color( array $node ) {
		$attrs = $this->attrs( $node );

		if ( isset( $attrs['style']['color']['text'] ) && is_string( $attrs['style']['color']['text'] ) ) {
			return $attrs['style']['color']['text'];
		}
		if ( ! empty( $attrs['textColor'] ) && is_string( $attrs['textColor'] ) ) {
			return $this->resolve_palette_slug( $attrs['textColor'] );
		}
		return null;
	}

	/**
	 * core/button is the button.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function is_button( array $node ) {
		return 'core/button' === (string) $node['blockName'];
	}

	/**
	 * Filled unless the outline block style is applied.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function button_is_filled( array $node ) {
		$attrs      = $this->attrs( $node );
		$class_name = isset( $attrs['className'] ) && is_string( $attrs['className'] ) ? $attrs['className'] : '';
		return false === strpos( $class_name, 'is-style-outline' );
	}

	/**
	 * textAlign, block align, or a container's layout justification.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function alignment( array $node ) {
		$attrs = $this->attrs( $node );

		if ( ! empty( $attrs['textAlign'] ) && is_string( $attrs['textAlign'] ) ) {
			return $attrs['textAlign'];
		}
		if ( ! empty( $attrs['align'] ) && is_string( $attrs['align'] ) ) {
			return $attrs['align'];
		}
		if ( isset( $attrs['layout']['justifyContent'] ) && is_string( $attrs['layout']['justifyContent'] ) ) {
			return $attrs['layout']['justifyContent'];
		}
		return null;
	}

	/**
	 * style.spacing.padding, raw CSS strings (preset var() refs included).
	 *
	 * @param array $node Raw block array.
	 * @return array|null
	 */
	public function padding( array $node ) {
		$attrs   = $this->attrs( $node );
		$padding = isset( $attrs['style']['spacing']['padding'] ) ? $attrs['style']['spacing']['padding'] : null;
		if ( ! is_array( $padding ) || ! $padding ) {
			return null;
		}
		return array_intersect_key( $padding, array_flip( array( 'top', 'right', 'bottom', 'left' ) ) );
	}

	/**
	 * A heading's plain text; other blocks are not title-like.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function title_text( array $node ) {
		if ( 'core/heading' !== (string) $node['blockName'] ) {
			return null;
		}
		return trim( wp_strip_all_tags( (string) $node['innerHTML'] ) );
	}

	/*
	---------------------------------------------------------------------
	 * Saddle_Lint_Style_Accessor (companion facts)
	 * -------------------------------------------------------------------
	 */

	/**
	 * style.border.radius: a plain string, or per-corner values serialized
	 * clockwise from top-left so identical corner sets compare equal.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function border_radius( array $node ) {
		$attrs  = $this->attrs( $node );
		$radius = isset( $attrs['style']['border']['radius'] ) ? $attrs['style']['border']['radius'] : null;

		if ( is_string( $radius ) && '' !== trim( $radius ) ) {
			return trim( $radius );
		}
		if ( is_array( $radius ) && $radius ) {
			$corners = array();
			foreach ( array( 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ) as $corner ) {
				$corners[] = isset( $radius[ $corner ] ) && is_string( $radius[ $corner ] ) ? trim( $radius[ $corner ] ) : '0';
			}
			return implode( ' ', $corners );
		}
		return null;
	}

	/**
	 * style.spacing.blockGap: a plain string, or "row col" when the two axes
	 * differ (blockGap objects carry top = row gap, left = column gap).
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function gap( array $node ) {
		$attrs = $this->attrs( $node );
		$gap   = isset( $attrs['style']['spacing']['blockGap'] ) ? $attrs['style']['spacing']['blockGap'] : null;

		if ( is_string( $gap ) && '' !== trim( $gap ) ) {
			return trim( $gap );
		}
		if ( is_array( $gap ) && $gap ) {
			$row = isset( $gap['top'] ) && is_string( $gap['top'] ) ? trim( $gap['top'] ) : '';
			$col = isset( $gap['left'] ) && is_string( $gap['left'] ) ? trim( $gap['left'] ) : '';
			if ( '' === $row && '' === $col ) {
				return null;
			}
			if ( '' === $row || '' === $col || $row === $col ) {
				return '' !== $row ? $row : $col;
			}
			return $row . ' ' . $col;
		}
		return null;
	}

	/**
	 * style.typography.fontSize raw, or the fontSize preset slug resolved to
	 * its theme.json value. Unknown slugs paint nothing → null.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function font_size( array $node ) {
		$attrs = $this->attrs( $node );

		if ( isset( $attrs['style']['typography']['fontSize'] ) && is_string( $attrs['style']['typography']['fontSize'] ) ) {
			return $attrs['style']['typography']['fontSize'];
		}
		if ( ! empty( $attrs['fontSize'] ) && is_string( $attrs['fontSize'] ) ) {
			return $this->resolve_font_size_slug( $attrs['fontSize'] );
		}
		return null;
	}

	/**
	 * core/image is the content image; its alt lives on the <img> tag in the
	 * saved markup, not in attrs. Covers and other background media are
	 * decorative by convention → null (never nag about them).
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function image_alt( array $node ) {
		if ( 'core/image' !== (string) $node['blockName'] ) {
			return null;
		}
		$html = (string) $node['innerHTML'];
		if ( false === stripos( $html, '<img' ) ) {
			// An image block with no image yet — nothing rendered to judge.
			return null;
		}
		if ( preg_match( '/<img[^>]*\salt=("|\')(.*?)\1/is', $html, $m ) ) {
			return trim( html_entity_decode( $m[2], ENT_QUOTES ) );
		}
		return '';
	}

	/**
	 * core/heading's level; the attr is only serialized when it differs from
	 * the default h2.
	 *
	 * @param array $node Raw block array.
	 * @return int|null
	 */
	public function heading_level( array $node ) {
		if ( 'core/heading' !== (string) $node['blockName'] ) {
			return null;
		}
		$attrs = $this->attrs( $node );
		$level = isset( $attrs['level'] ) ? (int) $attrs['level'] : 2;
		return ( $level >= 1 && $level <= 6 ) ? $level : 2;
	}

	/**
	 * Gutenberg has no user-editable global preset entity (registered block
	 * styles are code, not content) — nothing to couple to.
	 *
	 * @param array $node Raw block array.
	 * @return null
	 */
	public function global_preset_ref( array $node ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed accessor signature.
		return null;
	}

	/**
	 * Every var(--…) reference in the node's own attrs, plus Gutenberg's
	 * internal var:preset|group|slug form normalized to its CSS custom
	 * property name.
	 *
	 * @param array $node Raw block array.
	 * @return string[]
	 */
	public function variable_refs( array $node ) {
		$attrs = $this->attrs( $node );
		if ( ! $attrs ) {
			return array();
		}
		$blob = (string) wp_json_encode( $attrs );

		$refs = array();
		if ( preg_match_all( '/var\((--[a-z0-9_\-]+)/i', $blob, $m ) ) {
			$refs = $m[1];
		}
		// Internal preset syntax: "var:preset|color|primary".
		if ( preg_match_all( '/var:preset\|([a-z0-9_\-]+)\|([a-z0-9_\-]+)/i', $blob, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$refs[] = '--wp--preset--' . $hit[1] . '--' . $hit[2];
			}
		}
		return array_values( array_unique( $refs ) );
	}

	/**
	 * Free Gutenberg pages have no committed brief store — the brief is the
	 * builder driver's concern. Returning null keeps the conformance rule
	 * silent here (briefless pages get zero brief violations).
	 *
	 * @return null
	 */
	public function design_brief() {
		return null;
	}

	/**
	 * Tree-only accessor: the render pillar fills this seam later.
	 *
	 * @param array $node Raw block array.
	 * @return null
	 */
	public function computed_style( array $node ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed accessor signature.
		return null;
	}

	/*
	---------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------
	 */

	/**
	 * A node's attrs, always as an array.
	 *
	 * @param array $node Raw block array.
	 * @return array
	 */
	private function attrs( array $node ) {
		return isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
	}

	/**
	 * Resolve a palette preset slug to its theme.json value. Unknown slugs
	 * return null — an unknown slug paints nothing, so there is no color to
	 * compare (the write-echo separately warns about the slug itself).
	 *
	 * @param string $slug Preset slug.
	 * @return string|null
	 */
	private function resolve_palette_slug( $slug ) {
		if ( null === $this->palette ) {
			$this->palette = array();
			if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
				$settings = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
				$palette  = isset( $settings['color']['palette'] ) ? (array) $settings['color']['palette'] : array();
				// Origin-keyed or flat; theme entries are the site's brand and win.
				if ( isset( $palette[0] ) ) {
					$groups = array( $palette );
				} else {
					$groups = array();
					foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
						if ( isset( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
							$groups[] = $palette[ $origin ];
						}
					}
				}
				foreach ( $groups as $group ) {
					foreach ( $group as $preset ) {
						if ( is_array( $preset ) && isset( $preset['slug'], $preset['color'] ) && ! isset( $this->palette[ $preset['slug'] ] ) ) {
							$this->palette[ (string) $preset['slug'] ] = (string) $preset['color'];
						}
					}
				}
			}
		}
		return isset( $this->palette[ $slug ] ) ? $this->palette[ $slug ] : null;
	}

	/**
	 * Resolve a font-size preset slug to its theme.json value. Unknown slugs
	 * return null — same contract as resolve_palette_slug().
	 *
	 * @param string $slug Preset slug.
	 * @return string|null
	 */
	private function resolve_font_size_slug( $slug ) {
		if ( null === $this->font_sizes ) {
			$this->font_sizes = array();
			if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
				$settings = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
				$sizes    = isset( $settings['typography']['fontSizes'] ) ? (array) $settings['typography']['fontSizes'] : array();
				// Origin-keyed or flat; theme entries are the site's scale and win.
				if ( isset( $sizes[0] ) ) {
					$groups = array( $sizes );
				} else {
					$groups = array();
					foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
						if ( isset( $sizes[ $origin ] ) && is_array( $sizes[ $origin ] ) ) {
							$groups[] = $sizes[ $origin ];
						}
					}
				}
				foreach ( $groups as $group ) {
					foreach ( $group as $preset ) {
						if ( is_array( $preset ) && isset( $preset['slug'], $preset['size'] ) && ! isset( $this->font_sizes[ $preset['slug'] ] ) ) {
							$this->font_sizes[ (string) $preset['slug'] ] = (string) $preset['size'];
						}
					}
				}
			}
		}
		return isset( $this->font_sizes[ $slug ] ) ? $this->font_sizes[ $slug ] : null;
	}
}
