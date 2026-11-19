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
	 * Validate a tree and persist it to post_content: the single save path
	 * every Divi write uses. An invalid tree never reaches the database, and
	 * neither does a new or changed loop, binding or interaction that breaks
	 * the rules of the tools that write them (Saddle_Divi_Write_Checks).
	 *
	 * @param WP_Post $post Target post.
	 * @param array   $tree Block tree.
	 * @return int|WP_Error Total module count on success.
	 */
	public function persist( WP_Post $post, array $tree ) {
		$stored = get_post( $post->ID );
		$ready  = self::prepare( $tree, $stored ? (string) $stored->post_content : '' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		list( $tree, $content ) = $ready;

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
				'post_content' => wp_slash( $content ),
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
	 * Every check a tree passes before it is saved, without saving it:
	 * structure() and the loop, binding and interaction rules
	 * (Saddle_Divi_Write_Checks). persist() saves what this returns.
	 *
	 * @param array  $tree           Block tree.
	 * @param string $stored_content The page content as stored now ('' for none).
	 * @return array|WP_Error [repaired tree, serialized content].
	 */
	public static function prepare( array $tree, $stored_content = '' ) {
		$ready = self::structure( $tree );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$checked = Saddle_Divi_Write_Checks::check( (string) $stored_content, $ready[0], $ready[1] );
		return is_wp_error( $checked ) ? $checked : $ready;
	}

	/**
	 * The checks of prepare() that read only the tree, the same for every
	 * account: the loop repair every write applies (Saddle_Divi_Loop_Repair),
	 * the structure, and attrs that can be encoded. A module pack's guide
	 * example is checked with these (Saddle_Divi_Node_Check).
	 *
	 * @param array $tree Block tree.
	 * @return array|WP_Error [repaired tree, serialized content].
	 */
	public static function structure( array $tree ) {
		// Every SaddleWP write to a page also saves its loops' older value
		// shapes in the form Divi reads (Saddle_Divi_Loop_Repair::repair()).
		list( $tree ) = Saddle_Divi_Loop::repair_tree( $tree );

		$valid = Saddle_Divi_Tree::validate( $tree );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$encoded = self::encode_error( $tree );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		return array( $tree, Saddle_Divi_Tree::serialize( $tree ) );
	}

	/**
	 * Refuse a tree whose attrs cannot be saved: WordPress writes a block
	 * whose attrs JSON fails to encode (deeper than 512 levels) with no attrs
	 * at all, which would wipe the module.
	 *
	 * @param array $tree Block tree.
	 * @return true|WP_Error
	 */
	private static function encode_error( array $tree ) {
		if ( false !== wp_json_encode( $tree ) ) {
			return true;
		}
		foreach ( Saddle_Divi_Tree::flatten( $tree ) as $node ) {
			if ( $node['attrs'] && false === wp_json_encode( $node['attrs'] ) ) {
				return new WP_Error(
					'saddle_attrs_not_saved',
					sprintf(
						/* translators: %s: node address. */
						__( 'Node %s: its attributes are nested too deeply to be saved. Nothing was saved.', 'saddle' ),
						$node['address']
					)
				);
			}
		}
		return new WP_Error( 'saddle_attrs_not_saved', __( 'The page is nested too deeply to be saved. Nothing was saved.', 'saddle' ) );
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
