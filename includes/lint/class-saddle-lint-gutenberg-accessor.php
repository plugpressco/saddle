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
class Saddle_Lint_Gutenberg_Accessor implements Saddle_Lint_Accessor {

	/**
	 * theme.json color slug → value map, built once per instance.
	 *
	 * @var array<string,string>|null
	 */
	private $palette = null;

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
}
