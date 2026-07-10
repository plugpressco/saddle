<?php
/**
 * The builder-specific surface of the render engine.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * How the render engine turns a persisted node into something an agent can
 * SEE (closed-loop scope, https://github.com/plugpressco/saddle/issues/24).
 *
 * Mirrors the lint accessor split: the engine (Saddle_Render) is generic,
 * and everything builder-specific — how attrs resolve to effective styles,
 * how a node renders to HTML — lives behind this interface. Free Saddle
 * implements it for Gutenberg; Saddle Pro's Divi driver implements it for
 * Divi 5; Elementor/Bricks follow later.
 *
 * Both artifacts describe the PERSISTED state (what actually landed in the
 * database, resolved through presets/globals), never a guess at what an
 * editor canvas might show.
 */
interface Saddle_Render_Accessor {

	/**
	 * The node's effective styles: persisted attrs merged with whatever
	 * preset/global indirection the builder supports, resolved to a small
	 * normalized map (background, color, padding, fontSize, borderRadius,
	 * gap, textAlign, …). Keys the node doesn't style are omitted.
	 *
	 * @param array $node Raw block array (Saddle_Tree::parse() shape).
	 * @return array Normalized style map; empty when the node styles nothing.
	 */
	public function effective_styles( array $node );

	/**
	 * The node's rendered HTML, as faithful as the builder allows in this
	 * process. Implementations label their fidelity via render_fidelity().
	 *
	 * @param WP_Post $post    The post the tree belongs to.
	 * @param array   $tree    Full parsed tree (for builders that render in context).
	 * @param string  $address Dot address of the node to render.
	 * @return string|WP_Error Rendered HTML, or an error when rendering is
	 *                         impossible here.
	 */
	public function render_node_html( WP_Post $post, array $tree, $address );

	/**
	 * How faithful render_node_html() is, e.g. 'in-process' (rendered inside
	 * this request, theme CSS not applied) or 'front-end' (fetched from the
	 * site's real front end). Surfaced verbatim to the agent so it knows what
	 * it is looking at.
	 *
	 * @return string
	 */
	public function render_fidelity();
}
