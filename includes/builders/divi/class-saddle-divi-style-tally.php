<?php
/**
 * Counts the style values Divi 5 pages really use.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Walks Divi 5 trees and counts each style value by use: colors, section
 * padding, row width, type sizes, fonts, buttons, cards and presets.
 * Saddle_Divi_Site_Style turns the counts into the summary agents read.
 *
 * Values are read with Saddle_Divi_Style_Values and the page check's own
 * facts (Saddle_Divi_Lint_Facts: what a section paints, what a card is,
 * whether a button changes on hover). Only values a node sets itself
 * are read: Core does not resolve what a preset sets, so presets are
 * counted by id instead. A size, padding, width or radius a node leaves
 * unset counts as "default"; an unset color or font is not counted. Each
 * value is cut to VALUE_MAX characters and each list to LIST_MAX values,
 * so the counts stay small whatever a page holds.
 *
 * Shapes, from Divi 5.10.1 module.json files:
 *   background   <element>.decoration.background.<bp>.value.color
 *   padding      module.decoration.spacing.<bp>.value.padding.{top,bottom,…}
 *   row width    module.decoration.sizing.<bp>.value.maxWidth
 *   heading      <element>.decoration.font.font.<bp>.value.{headingLevel,size,color,family,weight},
 *                <element>.decoration.headingFont.<h1…h6>.font.<bp>.value (text modules)
 *   body text    <element>.decoration.bodyFont.body.font.<bp>.value
 *   button       <any element named *button*>.decoration.{button.enable,background,
 *                font.font,border,spacing}; a divi/button keeps its padding on
 *                module.decoration.spacing
 *   card         module.decoration.{background,border.radius,boxShadow.style,
 *                spacing.padding} of a node Saddle_Divi_Lint_Facts::is_card() accepts;
 *                every other background is a module background
 */
class Saddle_Divi_Style_Tally {

	/**
	 * The body text group, from an element's attr to the breakpoints.
	 */
	const BODY = array( 'decoration', 'bodyFont', 'body', 'font' );

	/**
	 * Longest value counted, in characters, and most values kept per list.
	 */
	const VALUE_MAX = 80;
	const LIST_MAX  = 50;

	/**
	 * Uses by value, per fact ("<part>/<list>" as the answer shows it, and
	 * "presets").
	 *
	 * @var array<string,array<string,int>>
	 */
	private $counts = array();

	/**
	 * Colors written as a global color reference: value => token.
	 *
	 * @var array<string,string>
	 */
	private $refs = array();

	/**
	 * The value readers.
	 *
	 * @var Saddle_Divi_Style_Values
	 */
	private $values;

	/**
	 * The page check's design facts.
	 *
	 * @var Saddle_Divi_Lint_Facts
	 */
	private $facts;

	/**
	 * A fresh tally.
	 */
	public function __construct() {
		$this->values = new Saddle_Divi_Style_Values();
		$this->facts  = new Saddle_Divi_Lint_Facts();
	}

	/**
	 * Count every node of a parsed tree.
	 *
	 * @param array[] $blocks Parsed blocks.
	 */
	public function add( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['blockName'] ) ) {
				continue;
			}
			$this->node( $block );
			$this->add( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array() );
		}
	}

	/**
	 * What was counted: {counts: {fact: {value: uses}}, refs: {color: token}}.
	 *
	 * @return array
	 */
	public function result() {
		return array(
			'counts' => $this->counts,
			'refs'   => $this->refs,
		);
	}

	/**
	 * Count one node.
	 *
	 * @param array $node Raw block array.
	 */
	private function node( array $node ) {
		$type = (string) $node['blockName'];
		foreach ( Saddle_Divi_Tokens::preset_references( isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array() ) as $id ) {
			$this->count( 'presets', $id );
		}
		if ( 'divi/placeholder' === $type ) {
			return;
		}
		if ( in_array( $type, array( 'divi/section', 'divi/fullwidth-section' ), true ) ) {
			$this->section( $node );
			return;
		}
		if ( in_array( $type, array( 'divi/row', 'divi/row-inner' ), true ) ) {
			$this->count( 'spacing/row_max_width', Saddle_Divi_Style_Values::text( $this->values->at( $node, 'module', array( 'decoration', 'sizing' ), 'maxWidth' ) ) );
			return;
		}

		$elements = $this->values->elements( $node );
		$card     = isset( $elements['module'] ) && $this->facts->is_card( $node );
		foreach ( $elements as $name => $attr ) {
			$name = (string) $name;
			if ( false !== stripos( $name, 'button' ) ) {
				$this->button( $node, $name, $attr );
			} elseif ( ! preg_match( Saddle_Divi_Lint_Facts::SMALL_ELEMENTS, $name ) ) {
				$this->element( $type, $name, $attr, $card && 'module' === $name );
			}
		}
		if ( $card ) {
			$this->card( $elements['module'] );
		}
	}

	/**
	 * A section: its background and its top and bottom padding.
	 *
	 * @param array $node Raw block array.
	 */
	private function section( array $node ) {
		if ( null !== $this->facts->surface( $node )['color'] ) {
			$this->color( 'colors/section_backgrounds', $this->values->at( $node, 'module', array( 'decoration', 'background' ), 'color' ) );
		}
		foreach ( array(
			'desktop' => '',
			'phone'   => '@phone',
		) as $breakpoint => $suffix ) {
			foreach ( array( 'top', 'bottom' ) as $side ) {
				$padding = $this->values->at( $node, 'module', array( 'decoration', 'spacing' ), array( 'padding', $side ), $breakpoint );
				$this->count( 'spacing/section_padding_' . $side . $suffix, Saddle_Divi_Style_Values::text( $padding ) );
			}
		}
	}

	/**
	 * One element of a module, column or group: its background, and its
	 * text as a heading or as body text.
	 *
	 * @param string $type Module type.
	 * @param string $name Element name.
	 * @param array  $attr Its attr envelope.
	 * @param bool   $card Whether it is the module element of a card.
	 */
	private function element( $type, $name, array $attr, $card ) {
		$background = $this->values->get( $attr, array( 'decoration', 'background', 'desktop', 'value', 'color' ) );
		if ( is_string( $background ) && ! Saddle_Divi_Lint_Facts::is_transparent( $background ) ) {
			$this->color( $card ? 'colors/card_backgrounds' : 'colors/module_backgrounds', $background );
		}

		$level = $this->values->level( $type, $name, $attr );
		if ( null !== $level ) {
			$this->heading( $attr, Saddle_Divi_Style_Values::FONT, $level );
		} else {
			$this->count( 'type/fonts', (string) $this->values->face( $attr, Saddle_Divi_Style_Values::FONT ) );
		}
		foreach ( Saddle_Divi_Style_Values::LEVELS as $tag ) {
			$group = array( 'decoration', 'headingFont', $tag, 'font' );
			if ( is_array( $this->values->get( $attr, $group ) ) ) {
				$this->heading( $attr, $group, $tag );
			}
		}

		if ( is_array( $this->values->get( $attr, self::BODY ) ) || ( 'content' === $name && $this->values->has_text( $attr ) ) ) {
			$this->count( 'type/body', Saddle_Divi_Style_Values::text( $this->values->in( $attr, self::BODY, 'size' ) ) );
			$this->count( 'type/body@phone', Saddle_Divi_Style_Values::text( $this->values->in( $attr, self::BODY, 'size', 'phone' ) ) );
			$this->color( 'colors/body_text', $this->values->in( $attr, self::BODY, 'color' ) );
			$this->count( 'type/fonts', (string) $this->values->face( $attr, self::BODY ) );
		}
	}

	/**
	 * A heading: its size on desktop and phone, its color and its font.
	 *
	 * @param array    $attr  Attr envelope.
	 * @param string[] $group Its font group.
	 * @param string   $level h1 … h6.
	 */
	private function heading( array $attr, array $group, $level ) {
		$this->count( 'type/' . $level, Saddle_Divi_Style_Values::text( $this->values->in( $attr, $group, 'size' ) ) );
		$this->count( 'type/' . $level . '@phone', Saddle_Divi_Style_Values::text( $this->values->in( $attr, $group, 'size', 'phone' ) ) );
		$this->color( 'colors/headings', $this->values->in( $attr, $group, 'color' ) );
		$this->count( 'type/fonts', (string) $this->values->face( $attr, $group ) );
	}

	/**
	 * A button element (button, buttonOne, readMoreButton): fill, text
	 * color, radius, padding, hover, font. Before Divi 5.10 a fill shows
	 * only with the element's button switch on, as
	 * Saddle_Divi_Lint_Facts::button_fill() reads it; from 5.10 it always
	 * shows.
	 *
	 * @param array  $node   Raw block array.
	 * @param string $name   Element name.
	 * @param array  $button The element's attr envelope.
	 */
	private function button( array $node, $name, array $button ) {
		$fill = $this->values->get( $button, array( 'decoration', 'background', 'desktop', 'value', 'color' ) );
		$on   = 'on' === $this->values->get( $button, array( 'decoration', 'button', 'desktop', 'value', 'enable' ) );
		$show = is_string( $fill ) && ! Saddle_Divi_Lint_Facts::is_transparent( $fill ) && ( $on || ! Saddle_Divi::button_fill_needs_switch() );
		if ( $show ) {
			$this->color( 'colors/buttons', $fill );
		}
		$this->color( 'colors/button_text', $this->values->in( $button, Saddle_Divi_Style_Values::FONT, 'color' ) );
		$this->count( 'buttons/radius', Saddle_Divi_Style_Values::corners( $this->values->get( $button, array( 'decoration', 'border', 'desktop', 'value', 'radius' ) ) ) );

		$padding = $this->values->get( $button, array( 'decoration', 'spacing', 'desktop', 'value', 'padding' ) );
		if ( null === $padding && 'button' === $name && 'divi/button' === (string) $node['blockName'] ) {
			// A divi/button hides its button element's spacing (Saddle_Divi_Module_Notes).
			$padding = $this->values->at( $node, 'module', array( 'decoration', 'spacing' ), 'padding' );
		}
		$this->count( 'buttons/padding', Saddle_Divi_Style_Values::sides( $padding ) );
		$this->count( 'buttons/hover', $this->facts->button_has_hover( $node ) ? 'changes' : 'no change' );
		$this->count( 'type/fonts', (string) $this->values->face( $button, Saddle_Divi_Style_Values::FONT ) );
	}

	/**
	 * A card (Saddle_Divi_Lint_Facts::is_card()): radius, shadow, padding.
	 *
	 * @param array $module The card's module attr envelope.
	 */
	private function card( array $module ) {
		$this->count( 'cards/radius', Saddle_Divi_Style_Values::corners( $this->values->get( $module, array( 'decoration', 'border', 'desktop', 'value', 'radius' ) ) ) );
		$shadow = $this->values->get( $module, array( 'decoration', 'boxShadow', 'desktop', 'value', 'style' ) );
		$this->count( 'cards/shadow', is_string( $shadow ) && '' !== $shadow ? $shadow : 'none' );
		$this->count( 'cards/padding', Saddle_Divi_Style_Values::sides( $this->values->get( $module, array( 'decoration', 'spacing', 'desktop', 'value', 'padding' ) ) ) );
	}

	/**
	 * Count a color under a fact, and remember a global color reference.
	 *
	 * @param string $fact  Fact key.
	 * @param mixed  $color Color as stored.
	 */
	private function color( $fact, $color ) {
		$parts = $this->values->color( $color );
		if ( null === $parts ) {
			return;
		}
		list( $value, $token ) = $parts;
		if ( null !== $token && $value !== $token ) {
			$this->refs[ $value ] = $token;
		}
		$this->count( $fact, $value );
	}

	/**
	 * Add one use of a value ('' adds nothing), cut to VALUE_MAX
	 * characters. A list already holding LIST_MAX values takes no new one.
	 *
	 * @param string $fact  Fact key.
	 * @param string $value Value.
	 */
	private function count( $fact, $value ) {
		$value = mb_substr( (string) $value, 0, self::VALUE_MAX );
		if ( '' === $value ) {
			return;
		}
		if ( isset( $this->counts[ $fact ][ $value ] ) ) {
			++$this->counts[ $fact ][ $value ];
		} elseif ( ! isset( $this->counts[ $fact ] ) || count( $this->counts[ $fact ] ) < self::LIST_MAX ) {
			$this->counts[ $fact ][ $value ] = 1;
		}
	}
}
