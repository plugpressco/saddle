<?php
/**
 * The Divi 5 builder driver.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapts the Divi layer to the builder driver interface, delegating to the
 * existing, tested Divi classes in this directory. The seams the ability
 * layer crosses on every write ARE driver-owned: persist() (the validated
 * single save path) and author() (whole-page node expansion), plus the
 * lint/render accessor wiring. The finer-grained node-level entry points
 * (expand_node, dotted-key expansion, per-field echo) stay Divi-direct in
 * the divi-* abilities until the generic tool surface lands with a second
 * builder (INTEGRATIONS-PLAN §7).
 */
class Saddle_Divi_Driver implements Saddle_Builder_Driver {

	/**
	 * Driver slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'divi';
	}

	/**
	 * Free Saddle's builder_signature() label for Divi 5 pages.
	 *
	 * @return string
	 */
	public function builder_name() {
		return 'Divi 5';
	}

	/**
	 * Divi 5 owns the post: the theme is active and the content is stored as
	 * Divi 5 blocks (never Divi 4 shortcodes or foreign content).
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	public function detect( WP_Post $post ) {
		return Saddle_Divi::is_active() && 'divi5' === Saddle_Divi::post_builder( $post );
	}

	/**
	 * Divi 5 stores block markup in post_content — the shared tree engine
	 * parses it directly.
	 *
	 * @param string $content Raw post_content.
	 * @return array[]
	 */
	public function read_tree( $content ) {
		return Saddle_Divi_Tree::parse( (string) $content );
	}

	/**
	 * Serialize a tree back to block markup.
	 *
	 * @param array $tree Block tree.
	 * @return string
	 */
	public function write_tree( array $tree ) {
		return Saddle_Divi_Tree::serialize( $tree );
	}

	/**
	 * Structural validation: [placeholder >] section > row > column > modules.
	 *
	 * @param array $tree Block tree.
	 * @return true|WP_Error
	 */
	public function validate( array $tree ) {
		return Saddle_Divi_Tree::validate( $tree );
	}

	/**
	 * Validate a tree and persist it to post_content — the single save path
	 * every Divi write uses. An invalid tree never reaches the database.
	 *
	 * @param WP_Post $post Target post.
	 * @param array   $tree Block tree.
	 * @return int|WP_Error Total module count on success.
	 */
	public function persist( WP_Post $post, array $tree ) {
		$valid = Saddle_Divi_Tree::validate( $tree );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				// Preserve the current status EXPLICITLY. A content-only update
				// shouldn't change it, but a live session saw set-page flip a
				// published page back to draft (a filter on that site defaulting
				// the status); pinning it here makes the invariant unconditional.
				'post_status'  => $post->post_status,
				// wp_update_post unslashes; serialized block JSON contains
				// backslash escapes that must survive the round trip.
				'post_content' => wp_slash( Saddle_Divi_Tree::serialize( $tree ) ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_post_meta( $post->ID, '_et_pb_use_builder', 'on' );
		update_post_meta( $post->ID, '_et_pb_use_divi_5', 'on' );

		return count( Saddle_Divi_Tree::flatten( $tree ) );
	}

	/**
	 * Expand {type, fields, attrs, children} nodes into canonical Divi blocks.
	 *
	 * @param array $nodes Authoring nodes.
	 * @return array[]|WP_Error
	 */
	public function author( array $nodes ) {
		return Saddle_Divi_Author::expand( $nodes );
	}

	/**
	 * A module's distilled content schema from its module.json.
	 *
	 * @param string $type Module type, e.g. 'divi/button'.
	 * @return array|WP_Error
	 */
	public function schema( $type ) {
		return Saddle_Divi_Schema::describe( (string) $type );
	}

	/**
	 * The Divi lint accessor (null while free Saddle predates the lint engine).
	 *
	 * @param WP_Post|null $post The post being linted, for page-scoped facts.
	 * @return Saddle_Lint_Accessor|null
	 */
	public function lint_accessor( ?WP_Post $post = null ) {
		return class_exists( 'Saddle_Divi_Lint_Accessor' ) ? new Saddle_Divi_Lint_Accessor( $post ) : null;
	}

	/**
	 * The Divi render accessor (null while free Saddle predates the render
	 * engine).
	 *
	 * @return Saddle_Render_Accessor|null
	 */
	public function render_accessor() {
		return class_exists( 'Saddle_Divi_Render_Accessor' ) ? new Saddle_Divi_Render_Accessor() : null;
	}

	/**
	 * Applied-vs-ignored echo for one authored node and its whole subtree.
	 *
	 * @param array  $node    Authoring node (with children).
	 * @param string $address Node address for messages.
	 * @return string[]
	 */
	public function echo_warnings( array $node, $address ) {
		return Saddle_Divi_Echo::check_subtree( $node, $address );
	}
}
