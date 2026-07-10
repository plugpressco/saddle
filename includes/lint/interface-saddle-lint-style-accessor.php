<?php
/**
 * The additive companion to Saddle_Lint_Accessor.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extended design facts for the deeper quality rules (closed-loop scope,
 * https://github.com/plugpressco/saddle/issues/23).
 *
 * A SEPARATE interface, on purpose: adding methods to Saddle_Lint_Accessor
 * would fatal any older accessor implementation the moment free Saddle
 * updates. Instead, accessors additionally implement this companion, and
 * rules feature-detect with `$accessor instanceof Saddle_Lint_Style_Accessor`
 * — an accessor that hasn't caught up simply makes those rules skip.
 *
 * The base interface's contract carries over unchanged: methods take a raw
 * block array from Saddle_Tree::parse(), accessors resolve indirection where
 * they can (preset slug → theme.json value, var(--gcid-…) → global palette),
 * and every fact that is unknown or not applicable returns null — rules must
 * skip, never guess.
 */
interface Saddle_Lint_Style_Accessor {

	/**
	 * The node's own corner radius.
	 *
	 * Composite per-corner values are serialized deterministically (clockwise
	 * from top-left, space-joined) so two nodes with identical corners compare
	 * equal — the monotony rules only ever test identity, never parse.
	 *
	 * @param array $node Raw block array.
	 * @return string|null CSS radius string, or null when the node sets none.
	 */
	public function border_radius( array $node );

	/**
	 * The node's own gap between children (row/column gap).
	 *
	 * @param array $node Raw block array.
	 * @return string|null CSS gap string ("row col" when they differ), or null
	 *                     when the node sets none / has no layout gap.
	 */
	public function gap( array $node );

	/**
	 * The node's own font size, resolved as far as possible.
	 *
	 * @param array $node Raw block array.
	 * @return string|null CSS size (preset slugs resolved to their value), or
	 *                     null when unset/unresolvable.
	 */
	public function font_size( array $node );

	/**
	 * The alt text of an image-content node.
	 *
	 * Only content images count — decorative background media (covers,
	 * section backgrounds) returns null, so the missing-alt rule never nags
	 * about media that is correctly decorative. Alt bound to dynamic content
	 * counts as satisfied and returns a non-empty sentinel.
	 *
	 * @param array $node Raw block array.
	 * @return string|null null when the node is not a content image;
	 *                     '' when it is one and the alt is missing/empty
	 *                     (that IS the lint); the alt text otherwise.
	 */
	public function image_alt( array $node );

	/**
	 * The heading level of a heading node.
	 *
	 * @param array $node Raw block array.
	 * @return int|null 1–6, or null when the node is not a heading.
	 */
	public function heading_level( array $node );

	/**
	 * The id of the user-editable global preset this node is bound to.
	 *
	 * "Global preset" means a site-wide, owner-editable style entity (Divi's
	 * global presets); registered code-level block styles do not count.
	 *
	 * @param array $node Raw block array.
	 * @return string|null Preset id, or null when unbound / no such concept.
	 */
	public function global_preset_ref( array $node );

	/**
	 * The design-token variables this node's own attrs reference.
	 *
	 * @param array $node Raw block array.
	 * @return string[] Unique variable names (e.g. '--wp--preset--color--primary',
	 *                  '--gcid-abc123'), document-order first occurrence.
	 *                  Empty array when none.
	 */
	public function variable_refs( array $node );

	/**
	 * The page's committed design brief merged over derived site constraints.
	 *
	 * Shape (all keys optional): { palette: string[], accent_count: int,
	 * radius_scale: string[], spacing_scale: string[], type_scale: string[],
	 * layout_concept: string, palette_closed: bool }. The brief-conformance
	 * rule fires ONLY when this returns non-null — briefless pages get zero
	 * brief violations.
	 *
	 * @return array|null The brief, or null when none is committed/derivable.
	 */
	public function design_brief();

	/**
	 * Render-backed computed style for a node, when a render pillar can
	 * supply one (box model, resolved colors as painted). The seam the
	 * render engine fills; tree-only accessors return null and rules fall
	 * back to persisted-attr facts.
	 *
	 * @param array $node Raw block array.
	 * @return array|null Computed style map, or null when unavailable.
	 */
	public function computed_style( array $node );
}
