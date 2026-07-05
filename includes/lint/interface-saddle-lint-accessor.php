<?php
/**
 * The builder-specific surface of the lint engine.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * How lint rules read design facts off a raw block node.
 *
 * This interface is the ONLY builder-specific part of the lint engine
 * (DESIGN-PLAN.md §2.1): rules never reach into builder attrs directly, they
 * ask an accessor. Free Saddle implements it for Gutenberg; Saddle Pro's Divi
 * driver implements it for Divi 5; Elementor/Bricks follow later.
 *
 * Every method takes a raw block array as produced by Saddle_Tree::parse()
 * (blockName/attrs/innerBlocks/innerHTML/innerContent). Accessors RESOLVE
 * indirection where they can — a Gutenberg preset slug to its theme.json hex,
 * a Divi var(--gcid-…) to its global-palette value — so rules compare real
 * values. When a fact is unknown or not applicable, return null: rules must
 * skip, never guess (the engine never false-rejects).
 */
interface Saddle_Lint_Accessor {

	/**
	 * The node's own background color, resolved as far as possible.
	 *
	 * @param array $node Raw block array.
	 * @return string|null CSS color (hex when resolvable), or null when the
	 *                     node sets no background / it cannot be read.
	 */
	public function background_color( array $node );

	/**
	 * The node's own text color, resolved as far as possible.
	 *
	 * @param array $node Raw block array.
	 * @return string|null CSS color, or null when unset/unreadable.
	 */
	public function text_color( array $node );

	/**
	 * Whether this node is a button (a styled call-to-action link).
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function is_button( array $node );

	/**
	 * Whether a button renders FILLED (solid background) rather than as a
	 * ghost/outline. Only meaningful when is_button() is true. Encodes the
	 * builder's judgment of intent: a default, unstyled button counts as
	 * filled (don't cry wolf), while a button that is styled but misses the
	 * builder's "actually fill it" switch (Divi's enable=on) does not.
	 *
	 * @param array $node Raw block array.
	 * @return bool
	 */
	public function button_is_filled( array $node );

	/**
	 * The node's horizontal alignment.
	 *
	 * @param array $node Raw block array.
	 * @return string|null e.g. 'left'|'center'|'right', or null when unset.
	 */
	public function alignment( array $node );

	/**
	 * The node's own padding.
	 *
	 * @param array $node Raw block array.
	 * @return array|null Map with any of top/right/bottom/left as raw CSS
	 *                    strings, or null when the node sets no padding.
	 */
	public function padding( array $node );

	/**
	 * The node's title text, when the node is title-like.
	 *
	 * @param array $node Raw block array.
	 * @return string|null Trimmed plain text ('' when the title is empty —
	 *                     that IS the lint), or null when the node is not a
	 *                     title-carrying element.
	 */
	public function title_text( array $node );
}
