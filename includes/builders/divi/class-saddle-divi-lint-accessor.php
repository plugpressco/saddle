<?php
/**
 * The Divi 5 implementation of free Saddle's lint accessor.
 *
 * First resident of builders/divi/ — the driver directory the
 * INTEGRATIONS-PLAN §7 refactor moves the rest of the Divi layer into.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads design facts off canonical Divi 5 module attrs so free Saddle's
 * builder-agnostic lint rules work on Divi pages unchanged.
 *
 * Divi's canonical shapes (verified against module.json + Divi 5.8 render
 * attributes, same knowledge Saddle_Divi_Schema distills):
 *   background   <attr>.decoration.background.desktop.value.color
 *   text color   <attr>.decoration.font.font.desktop.value.color (the font
 *                group nests its value under an extra `font` key)
 *   button fill  <attr>.decoration.button.desktop.value.enable — live-
 *                verified: a styled button renders ghost until this is "on"
 *   alignment    <attr>.decoration.sizing.desktop.value.alignment
 *   padding      <attr>.decoration.spacing.desktop.value.padding
 *   title        title.innerContent.desktop.value
 *
 * Global-color references (var(--gcid-…)) resolve through Divi's GlobalData
 * palette when the Divi runtime is present; otherwise the raw var() string
 * is returned — rules compare it for equality and skip contrast math.
 *
 * Also implements the companion Saddle_Lint_Style_Accessor (free ≥ 0.9) for
 * the deeper quality rules. The additional canonical shapes, verified
 * against Divi 5.8 source (Border.php, HeadingPresetAttrsMap.php,
 * image/module.json) the same way the base ones were:
 *   radius        <attr>.decoration.border.desktop.value.radius
 *                 {topLeft/topRight/bottomRight/bottomLeft}
 *   font size     <attr>.decoration.font.font.desktop.value.size
 *   heading level title.decoration.font.font.desktop.value.headingLevel
 *                 ('h1'…'h6'; divi/heading defaults to h2 like the builder)
 *   image alt     image.innerContent.desktop.value.alt (divi/image only —
 *                 other modules' media is decorative by convention)
 *   preset ref    attrs.modulePreset (a stack of preset ids at the attr root
 *                 — the same place saddle/divi-apply-global-preset writes)
 *   variables     var(--gvid-…) / var(--gcid-…) references anywhere in attrs
 */
class Saddle_Divi_Lint_Accessor implements Saddle_Lint_Accessor, Saddle_Lint_Style_Accessor {

	use Saddle_Divi_Attr_Resolution;

	/**
	 * The post being linted, when known — the design brief is page-scoped.
	 *
	 * @var WP_Post|null
	 */
	private $post;

	/**
	 * The effective brief, memoized per instance (false = not yet loaded).
	 *
	 * @var array|null|false
	 */
	private $brief = false;

	/**
	 * Accessor for a page's lint run.
	 *
	 * @param WP_Post|null $post The post being linted (null for bare
	 *                           tree-level use — brief facts return null).
	 */
	public function __construct( ?WP_Post $post = null ) {
		$this->post = $post;
	}

	/**
	 * The first background color any of the node's attrs paints.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function background_color( array $node ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$color = $this->path( $attr, array( 'decoration', 'background', 'desktop', 'value', 'color' ) );
			if ( is_string( $color ) && '' !== $color ) {
				return $this->resolve_global_color( $color );
			}
		}
		return null;
	}

	/**
	 * The first text color any of the node's attrs sets (typography groups
	 * nest the value under an extra `font` key).
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function text_color( array $node ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$color = $this->path( $attr, array( 'decoration', 'font', 'font', 'desktop', 'value', 'color' ) );
			if ( is_string( $color ) && '' !== $color ) {
				return $this->resolve_global_color( $color );
			}
		}
		return null;
	}

	/**
	 * divi/button is the button module.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function is_button( array $node ) {
		return 'divi/button' === (string) $node['blockName'];
	}

	/**
	 * Live-verified Divi behavior: a button with visual styling but without
	 * the custom-button enable switch renders as a ghost/outline. An
	 * UNstyled button inherits its preset and counts as filled — the lint
	 * targets the styled-but-forgot-enable accident, not deliberate presets.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function button_is_filled( array $node ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$enable = $this->path( $attr, array( 'decoration', 'button', 'desktop', 'value', 'enable' ) );
			if ( 'on' === $enable ) {
				return true;
			}
		}
		$styled = null !== $this->background_color( $node ) || null !== $this->text_color( $node );
		return ! $styled;
	}

	/**
	 * The first sizing alignment any of the node's attrs sets.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function alignment( array $node ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$alignment = $this->path( $attr, array( 'decoration', 'sizing', 'desktop', 'value', 'alignment' ) );
			if ( is_string( $alignment ) && '' !== $alignment ) {
				return $alignment;
			}
		}
		return null;
	}

	/**
	 * The first spacing padding any of the node's attrs sets.
	 *
	 * @param array $node Raw block array.
	 * @return array|null
	 */
	public function padding( array $node ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$padding = $this->path( $attr, array( 'decoration', 'spacing', 'desktop', 'value', 'padding' ) );
			if ( is_array( $padding ) && $padding ) {
				return array_intersect_key( $padding, array_flip( array( 'top', 'right', 'bottom', 'left' ) ) );
			}
		}
		return null;
	}

	/**
	 * The first sizing maxWidth any of the node's attrs caps.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function max_width( array $node ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$width = $this->path( $attr, array( 'decoration', 'sizing', 'desktop', 'value', 'maxWidth' ) );
			if ( is_string( $width ) && '' !== $width ) {
				return $width;
			}
		}
		return null;
	}

	/**
	 * The first spacing margin any of the node's attrs sets.
	 *
	 * @param array $node Raw block array.
	 * @return array|null
	 */
	public function margin( array $node ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$margin = $this->path( $attr, array( 'decoration', 'spacing', 'desktop', 'value', 'margin' ) );
			if ( is_array( $margin ) && $margin ) {
				return array_intersect_key( $margin, array_flip( array( 'top', 'right', 'bottom', 'left' ) ) );
			}
		}
		return null;
	}

	/**
	 * The CSS class(es) the node delegates to theme stylesheets via the
	 * advanced html group, or null when none.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function css_class( array $node ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$class = $this->path( $attr, array( 'advanced', 'html', 'desktop', 'value', 'class' ) );
			if ( is_string( $class ) && '' !== trim( $class ) ) {
				return trim( $class );
			}
		}
		return null;
	}

	/**
	 * Whether a module type exists in this site's discovered schema index.
	 * Null when the index is EMPTY (harness, broken discovery) — rules must
	 * skip rather than guess; true/false otherwise.
	 *
	 * @param string $type Module type.
	 * @return bool|null
	 */
	public function module_exists( $type ) {
		$index = Saddle_Divi_Schema::index();
		if ( ! is_array( $index ) || array() === $index ) {
			return null;
		}
		return isset( $index[ (string) $type ] );
	}

	/**
	 * The title content value. divi/heading is title-carrying even when the
	 * attr is missing entirely (that IS the empty-title case); other modules
	 * only when they carry a title attr.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function title_text( array $node ) {
		$attrs = $this->attrs( $node );
		$value = isset( $attrs['title'] ) && is_array( $attrs['title'] )
			? $this->path( $attrs['title'], array( 'innerContent', 'desktop', 'value' ) )
			: null;

		if ( is_string( $value ) ) {
			return trim( wp_strip_all_tags( $value ) );
		}
		return 'divi/heading' === (string) $node['blockName'] ? '' : null;
	}

	/*
	---------------------------------------------------------------------
	 * Saddle_Lint_Style_Accessor (companion facts)
	 * -------------------------------------------------------------------
	 */

	/**
	 * Border radius, corners serialized clockwise from top-left so identical
	 * corner sets compare equal (Border.php validates exactly these keys).
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function border_radius( array $node ) {
		$radius = $this->first_at_path( $node, array( 'decoration', 'border', 'desktop', 'value', 'radius' ) );
		if ( ! is_array( $radius ) || ! $radius ) {
			return null;
		}
		$corners = array();
		foreach ( array( 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ) as $corner ) {
			$corners[] = isset( $radius[ $corner ] ) && is_string( $radius[ $corner ] ) ? trim( $radius[ $corner ] ) : '0';
		}
		return implode( ' ', $corners );
	}

	/**
	 * Divi has no per-node children-gap attr in the shapes this accessor
	 * proves — null over guessing at gutters.
	 *
	 * @param array $node Raw block array.
	 * @return null
	 */
	public function gap( array $node ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed accessor signature.
		return null;
	}

	/**
	 * Font size off the same typography envelope the text color lives in.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function font_size( array $node ) {
		$size = $this->first_at_path( $node, array( 'decoration', 'font', 'font', 'desktop', 'value', 'size' ) );
		return is_string( $size ) && '' !== $size ? $size : null;
	}

	/**
	 * divi/image is the content image; alt lives in its innerContent value.
	 * Other modules' media (backgrounds, blurb icons) is decorative by
	 * convention → null, never nagged.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function image_alt( array $node ) {
		if ( 'divi/image' !== (string) $node['blockName'] ) {
			return null;
		}
		$attrs = $this->attrs( $node );
		$value = isset( $attrs['image'] ) && is_array( $attrs['image'] )
			? $this->path( $attrs['image'], array( 'innerContent', 'desktop', 'value' ) )
			: null;
		if ( ! is_array( $value ) ) {
			return null; // No image selected yet — nothing rendered to judge.
		}
		$src = isset( $value['src'] ) && is_string( $value['src'] ) ? trim( $value['src'] ) : '';
		if ( '' === $src ) {
			return null;
		}
		$alt = isset( $value['alt'] ) && is_string( $value['alt'] ) ? trim( $value['alt'] ) : '';
		return $alt;
	}

	/**
	 * Heading level off the font decoration ('h1'…'h6', the exact value the
	 * builder renders as the tag). divi/heading defaults to h2 when unset,
	 * matching the builder's default.
	 *
	 * @param array $node Raw block array.
	 * @return int|null
	 */
	public function heading_level( array $node ) {
		$level = $this->first_at_path( $node, array( 'decoration', 'font', 'font', 'desktop', 'value', 'headingLevel' ) );
		if ( is_string( $level ) && preg_match( '/^h([1-6])$/i', trim( $level ), $m ) ) {
			return (int) $m[1];
		}
		return 'divi/heading' === (string) $node['blockName'] ? 2 : null;
	}

	/**
	 * The first global preset id on the node's modulePreset stack — the same
	 * attr saddle/divi-apply-global-preset writes.
	 *
	 * @param array $node Raw block array.
	 * @return string|null
	 */
	public function global_preset_ref( array $node ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$stack = isset( $attrs['modulePreset'] ) ? $attrs['modulePreset'] : null;
		if ( is_string( $stack ) && '' !== trim( $stack ) ) {
			return trim( $stack );
		}
		if ( is_array( $stack ) ) {
			foreach ( $stack as $id ) {
				if ( is_string( $id ) && '' !== trim( $id ) ) {
					return trim( $id );
				}
			}
		}
		return null;
	}

	/**
	 * Every Divi design-token reference in the node's own attrs: global
	 * colors (--gcid-…) and design variables (--gvid-…).
	 *
	 * @param array $node Raw block array.
	 * @return string[]
	 */
	public function variable_refs( array $node ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		if ( ! $attrs ) {
			return array();
		}
		$blob = (string) wp_json_encode( $attrs );
		if ( preg_match_all( '/var\((--g[cv]id-[a-z0-9-]+)/i', $blob, $m ) ) {
			return array_values( array_unique( $m[1] ) );
		}
		return array();
	}

	/**
	 * The page's design brief, when an extension supplies one.
	 *
	 * @return array|null
	 */
	public function design_brief() {
		if ( false === $this->brief ) {
			/**
			 * Filter the design brief a Divi page's lint rules judge against.
			 *
			 * @param array|null   $brief The brief, or null when none applies.
			 * @param WP_Post|null $post  The page being linted.
			 */
			$brief       = apply_filters( 'saddle_divi_design_brief', null, $this->post );
			$this->brief = is_array( $brief ) ? $brief : null;
		}
		return $this->brief;
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
}
