<?php
/**
 * The builder driver interface.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * What every page builder must supply to plug into Saddle
 * (INTEGRATIONS-PLAN §7, DESIGN-PLAN §1): the structural surface — detect /
 * read_tree / write_tree / validate / author / schema — plus the two
 * quality-engine hooks the free lint/echo layer consumes. Everything above
 * this interface (abilities, lint rules, the echo response plumbing) is
 * builder-agnostic; everything below it is one builder's storage format.
 *
 * Divi implements it today by delegating to the classes in builders/divi/;
 * Elementor and Bricks implement the same contract later (JSON-in-meta
 * trees) WITHOUT touching the ability layer.
 */
interface Saddle_Builder_Driver {

	/**
	 * Stable driver slug, e.g. 'divi'.
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * The builder label free Saddle's builder_signature() reports for pages
	 * this driver owns, e.g. 'Divi 5' — the join key between free Saddle's
	 * detection and this registry.
	 *
	 * @return string
	 */
	public function builder_name();

	/**
	 * Whether this driver owns a post's content (the builder is present AND
	 * the post is stored in this builder's editable format).
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	public function detect( WP_Post $post );

	/**
	 * Parse stored content into the addressable tree the engine operates on.
	 *
	 * @param string $content Raw stored content (post_content or meta JSON).
	 * @return array[] Block/element tree.
	 */
	public function read_tree( $content );

	/**
	 * Serialize a tree back to its storable form.
	 *
	 * @param array $tree Tree from read_tree()/author().
	 * @return string
	 */
	public function write_tree( array $tree );

	/**
	 * Validate a tree against the builder's structural contract. Invalid
	 * structure is rejected, never repaired.
	 *
	 * @param array $tree Tree.
	 * @return true|WP_Error
	 */
	public function validate( array $tree );

	/**
	 * The validated single save path: validate the tree, serialize it, and
	 * persist to the builder's storage (post_content or meta), including any
	 * builder markers the editor needs. An invalid tree never reaches the
	 * database.
	 *
	 * @param WP_Post $post Target post.
	 * @param array   $tree Tree.
	 * @return int|WP_Error Total node count on success.
	 */
	public function persist( WP_Post $post, array $tree );

	/**
	 * Expand agent-authored nodes into the builder's canonical tree.
	 *
	 * @param array $nodes Authored nodes (the builder's documented format).
	 * @return array[]|WP_Error
	 */
	public function author( array $nodes );

	/**
	 * The distilled schema for one of the builder's module/widget types.
	 *
	 * @param string $type Module/widget type, e.g. 'divi/button'.
	 * @return array|WP_Error
	 */
	public function schema( $type );

	/**
	 * The builder's lint accessor for free Saddle's design lint engine.
	 *
	 * @param WP_Post|null $post The post being linted, when known — page-
	 *                           scoped facts (the design brief) hang off it.
	 * @return Saddle_Lint_Accessor|null Null when free Saddle predates the
	 *                                   lint engine (interface absent).
	 */
	public function lint_accessor( ?WP_Post $post = null );

	/**
	 * The builder's render accessor for free Saddle's render engine (the
	 * agent's eyes: saddle/render-node effective styles + node HTML).
	 *
	 * @return Saddle_Render_Accessor|null Null when free Saddle predates the
	 *                                     render engine (interface absent).
	 */
	public function render_accessor();

	/**
	 * Applied-vs-ignored echo for one authored node AND its whole subtree:
	 * warnings for every field/attr path the builder will silently ignore,
	 * on the node itself and every descendant it inserts. Warns only on
	 * what the builder's own schema proves; never blocks.
	 *
	 * @param array  $node    Authored node (with children).
	 * @param string $address Node address for messages.
	 * @return string[]
	 */
	public function echo_warnings( array $node, $address );
}
