<?php
/**
 * Extra design facts the newer Divi lint rules read.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Facts about a Divi 5 node that the lint accessor does not expose: what a
 * node paints behind its content, whether a button is filled and changes on
 * hover, and whether a container is a card.
 *
 * A separate class, not new accessor methods, so the accessor's interface
 * stays as it is. It reads through the same trait as the accessors, so a
 * path or a global color means the same thing here as there. Every reader
 * returns null or false when it cannot tell; rules skip, never guess.
 *
 * Shapes, from Divi 5.10.1 source (Background, Button, BoxShadow, Border
 * declarations):
 *   surface      module.decoration.background.<bp>.<state>.{color,gradient,image,video}
 *   button fill  button.decoration.button.desktop.value.enable = "on" and
 *                button.decoration.background.desktop.value.color
 *   button icon  button.decoration.button.desktop.value.icon.{enable,onHover}
 *                (both "on" unless set: the arrow slides in on hover)
 *   shadow       <attr>.decoration.boxShadow.desktop.value.style (not "none")
 *   border       <attr>.decoration.border.desktop.value.styles.all.{width,color}
 */
class Saddle_Divi_Lint_Facts {

	use Saddle_Divi_Attr_Resolution;

	/**
	 * Containers whose preset may paint a background this class cannot read.
	 */
	const CONTAINERS = array( 'divi/placeholder', 'divi/section', 'divi/fullwidth-section', 'divi/row', 'divi/row-inner', 'divi/column', 'divi/column-inner', 'divi/group' );

	/**
	 * Containers that read as a card when they paint and have a radius or a shadow.
	 */
	const CARD_TYPES = array( 'divi/group', 'divi/column', 'divi/column-inner' );

	/**
	 * Groups whose hover cell shows a visible change on a button.
	 */
	const HOVER_GROUPS = array(
		array( 'decoration', 'background' ),
		array( 'decoration', 'font', 'font' ),
		array( 'decoration', 'border' ),
		array( 'decoration', 'boxShadow' ),
		array( 'decoration', 'transform' ),
		array( 'decoration', 'filters' ),
	);

	/**
	 * What a node paints behind its content at desktop: its own module
	 * background. A gradient, image or video covers the color, so the
	 * surface is unknown; so is a container whose preset may paint one.
	 *
	 * @param array $node        Raw block array.
	 * @param bool  $as_ancestor Whether the node is read as an ancestor (its
	 *                           preset then counts as an unknown surface).
	 * @return array { paints: bool, color: string|null } color is null when
	 *               the node paints something this class cannot read.
	 */
	public function surface( array $node, $as_ancestor = false ) {
		$attrs      = $this->attrs( $node );
		$background = isset( $attrs['module'] ) ? $this->path( $attrs['module'], array( 'decoration', 'background', 'desktop', 'value' ) ) : null;
		$background = is_array( $background ) ? $background : array();

		if ( $this->covers( $background ) ) {
			return array(
				'paints' => true,
				'color'  => null,
			);
		}
		$color = isset( $background['color'] ) && is_string( $background['color'] ) ? trim( $background['color'] ) : '';
		if ( '' !== $color && ! self::is_transparent( $color ) ) {
			return array(
				'paints' => true,
				'color'  => $this->resolve_global_color( $color ),
			);
		}
		if ( $as_ancestor && $this->has_preset( $node ) && in_array( (string) $node['blockName'], self::CONTAINERS, true ) ) {
			return array(
				'paints' => true,
				'color'  => null,
			);
		}
		return array(
			'paints' => false,
			'color'  => null,
		);
	}

	/**
	 * The surface a node sits on: the nearest node up the chain that paints
	 * something. Null when that surface cannot be read, or nothing paints.
	 *
	 * @param array   $entry      Flat node entry (address, parent, block).
	 * @param array[] $by_address Address to entry map.
	 * @param bool    $own        Whether the node's own background counts.
	 * @return string|null
	 */
	public function surface_under( array $entry, array $by_address, $own = true ) {
		$current = $own ? $entry : $this->parent_of( $entry, $by_address );
		while ( $current ) {
			$surface = $this->surface( $current['block'], $current['address'] !== $entry['address'] );
			if ( $surface['paints'] ) {
				return $surface['color'];
			}
			$current = $this->parent_of( $current, $by_address );
		}
		return null;
	}

	/**
	 * Whether a background value covers its color (gradient, image, video).
	 *
	 * @param array $background A background value.
	 * @return bool
	 */
	private function covers( array $background ) {
		$gradient = isset( $background['gradient'] ) && is_array( $background['gradient'] ) ? $background['gradient'] : array();
		if ( $gradient && ( ! isset( $gradient['enabled'] ) || 'off' !== $gradient['enabled'] ) && ! empty( $gradient['stops'] ) ) {
			return true;
		}
		if ( $this->has_image( $background ) ) {
			return true;
		}
		$video = isset( $background['video'] ) && is_array( $background['video'] ) ? $background['video'] : array();
		return ( isset( $video['mp4'] ) && '' !== trim( (string) $video['mp4'] ) ) || ( isset( $video['webm'] ) && '' !== trim( (string) $video['webm'] ) );
	}

	/**
	 * Whether a background value carries an image that is not switched off.
	 *
	 * @param array $background A background value.
	 * @return bool
	 */
	private function has_image( array $background ) {
		$image = isset( $background['image'] ) && is_array( $background['image'] ) ? $background['image'] : array();
		return isset( $image['url'] ) && is_string( $image['url'] ) && '' !== trim( $image['url'] )
			&& ( ! isset( $image['enabled'] ) || 'off' !== $image['enabled'] );
	}

	/**
	 * Whether the node's module background holds an image at any screen size.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function background_image( array $node ) {
		$attrs = $this->attrs( $node );
		foreach ( array( 'desktop', 'tablet', 'phone' ) as $breakpoint ) {
			$background = isset( $attrs['module'] ) ? $this->path( $attrs['module'], array( 'decoration', 'background', $breakpoint, 'value' ) ) : null;
			if ( is_array( $background ) && $this->has_image( $background ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The fill of a button element: its background color when Divi's custom
	 * button switch is on and the color is not transparent. Reads the
	 * `button` attr, so it works on divi/button and on modules with a
	 * button element (CTA, pricing table).
	 *
	 * @param array $node Raw block array.
	 * @return string|null Resolved color, or null when not filled.
	 */
	public function button_fill( array $node ) {
		$attrs = $this->attrs( $node );
		if ( ! isset( $attrs['button'] ) ) {
			return null;
		}
		if ( 'on' !== $this->path( $attrs['button'], array( 'decoration', 'button', 'desktop', 'value', 'enable' ) ) ) {
			return null;
		}
		$color = $this->path( $attrs['button'], array( 'decoration', 'background', 'desktop', 'value', 'color' ) );
		if ( ! is_string( $color ) || '' === trim( $color ) || self::is_transparent( $color ) ) {
			return null;
		}
		return $this->resolve_global_color( trim( $color ) );
	}

	/**
	 * Whether a button visibly changes on hover: a hover color, border,
	 * shadow, transform or filter of its own, Divi's default icon that
	 * slides in on hover, or a preset (which may carry one). False only
	 * when none of these is there.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function button_has_hover( array $node ) {
		if ( $this->has_preset( $node ) ) {
			return true;
		}
		$attrs = $this->attrs( $node );
		foreach ( array( 'button', 'module' ) as $attr ) {
			if ( ! isset( $attrs[ $attr ] ) ) {
				continue;
			}
			foreach ( self::HOVER_GROUPS as $group ) {
				$hover = $this->path( $attrs[ $attr ], array_merge( $group, array( 'desktop', 'hover' ) ) );
				if ( is_array( $hover ) ? array() !== $hover : ( null !== $hover && '' !== $hover ) ) {
					return true;
				}
			}
		}
		$icon     = isset( $attrs['button'] ) ? $this->path( $attrs['button'], array( 'decoration', 'button', 'desktop', 'value', 'icon' ) ) : null;
		$icon     = is_array( $icon ) ? $icon : array();
		$enabled  = ! isset( $icon['enable'] ) || 'off' !== $icon['enable'];
		$on_hover = ! isset( $icon['onHover'] ) || 'off' !== $icon['onHover'];
		return $enabled && $on_hover;
	}

	/**
	 * Whether the node carries a module or group preset stack.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function has_preset( array $node ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		foreach ( array( 'modulePreset', 'groupPreset' ) as $key ) {
			if ( ! empty( $attrs[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a node is a card: a group or column that paints its own
	 * background and has a corner radius or a shadow.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function is_card( array $node ) {
		if ( ! in_array( (string) $node['blockName'], self::CARD_TYPES, true ) ) {
			return false;
		}
		if ( ! $this->surface( $node )['paints'] ) {
			return false;
		}
		return $this->has_radius( $node ) || $this->has_shadow( $node );
	}

	/**
	 * Whether the module sets a corner radius above zero.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function has_radius( array $node ) {
		$attrs  = $this->attrs( $node );
		$radius = isset( $attrs['module'] ) ? $this->path( $attrs['module'], array( 'decoration', 'border', 'desktop', 'value', 'radius' ) ) : null;
		if ( ! is_array( $radius ) ) {
			return false;
		}
		foreach ( array( 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ) as $corner ) {
			if ( isset( $radius[ $corner ] ) && self::is_nonzero( $radius[ $corner ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an element of the node casts a box shadow.
	 *
	 * @param array  $node Raw block array.
	 * @param string $attr Attr envelope (module, button).
	 * @return bool
	 */
	public function has_shadow( array $node, $attr = 'module' ) {
		$attrs = $this->attrs( $node );
		$style = isset( $attrs[ $attr ] ) ? $this->path( $attrs[ $attr ], array( 'decoration', 'boxShadow', 'desktop', 'value', 'style' ) ) : null;
		return is_string( $style ) && '' !== $style && 'none' !== $style;
	}

	/**
	 * The color of a visible border on an element, when it has one.
	 *
	 * @param array  $node Raw block array.
	 * @param string $attr Attr envelope (module, button).
	 * @return string|null Resolved color ('' when the width is set but the color is not).
	 */
	public function border_color( array $node, $attr = 'module' ) {
		$attrs = $this->attrs( $node );
		$all   = isset( $attrs[ $attr ] ) ? $this->path( $attrs[ $attr ], array( 'decoration', 'border', 'desktop', 'value', 'styles', 'all' ) ) : null;
		if ( ! is_array( $all ) || ! isset( $all['width'] ) || ! self::is_nonzero( $all['width'] ) ) {
			return null;
		}
		if ( isset( $all['style'] ) && 'none' === $all['style'] ) {
			return null;
		}
		return isset( $all['color'] ) && is_string( $all['color'] ) ? $this->resolve_global_color( trim( $all['color'] ) ) : '';
	}

	/**
	 * A value read the way Divi inherits it, for rules that need one field
	 * of one attr at a breakpoint.
	 *
	 * @param array    $node       Raw block array.
	 * @param string[] $group      Keys from the attrs root to the breakpoints.
	 * @param string   $field      Key inside the value.
	 * @param string   $breakpoint desktop, tablet or phone.
	 * @return mixed
	 */
	public function value_of( array $node, array $group, $field, $breakpoint = 'desktop' ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		foreach ( $this->inherit_order( $breakpoint, 'value' ) as $cell ) {
			$value = $this->path( $attrs, array_merge( $group, $cell, array( $field ) ) );
			if ( null !== $value && '' !== $value ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Whether the node writes a field at exactly this breakpoint.
	 *
	 * @param array    $node       Raw block array.
	 * @param string[] $group      Keys from the attrs root to the breakpoints.
	 * @param string   $field      Key inside the value.
	 * @param string   $breakpoint Breakpoint.
	 * @return bool
	 */
	public function writes( array $node, array $group, $field, $breakpoint ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$value = $this->path( $attrs, array_merge( $group, array( $breakpoint, 'value', $field ) ) );
		return null !== $value && '' !== $value;
	}

	/**
	 * The parent entry of a flat node entry.
	 *
	 * @param array   $entry      Flat node entry.
	 * @param array[] $by_address Address to entry map.
	 * @return array|null
	 */
	public function parent_of( array $entry, array $by_address ) {
		$parent = $entry['parent'];
		return null !== $parent && isset( $by_address[ $parent ] ) ? $by_address[ $parent ] : null;
	}

	/**
	 * Whether a color is transparent or not a solid paint.
	 *
	 * @param string $color Color value.
	 * @return bool
	 */
	public static function is_transparent( $color ) {
		$color = strtolower( trim( (string) $color ) );
		if ( in_array( $color, array( 'transparent', 'none', 'initial', 'inherit', 'unset' ), true ) ) {
			return true;
		}
		if ( preg_match( '/^rgba?\([^)]*[,\/]\s*0(\.0+)?\s*\)$/', $color ) || preg_match( '/^hsla?\([^)]*[,\/]\s*0(\.0+)?\s*\)$/', $color ) ) {
			return true;
		}
		return (bool) preg_match( '/^#(?:[0-9a-f]{3}0|[0-9a-f]{6}00)$/', $color );
	}

	/**
	 * Whether a color is a solid hex the contrast helper can judge (no
	 * alpha below full).
	 *
	 * @param mixed $color Color value.
	 * @return bool
	 */
	public static function is_solid_hex( $color ) {
		if ( ! is_string( $color ) || null === Saddle_Lint_Color::parse( $color ) ) {
			return false;
		}
		$hex = ltrim( strtolower( trim( $color ) ), '#' );
		if ( 4 === strlen( $hex ) ) {
			return 'f' === $hex[3];
		}
		return 8 !== strlen( $hex ) || 'ff' === substr( $hex, 6, 2 );
	}

	/**
	 * Whether a CSS length is above zero.
	 *
	 * @param mixed $value Length.
	 * @return bool
	 */
	public static function is_nonzero( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}
		$value = trim( (string) $value );
		return '' !== $value && ! preg_match( '/^0+(\.0+)?([a-z%]*)$/i', $value );
	}
}
