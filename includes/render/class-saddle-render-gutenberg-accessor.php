<?php
/**
 * The Gutenberg implementation of the render accessor.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Native blocks render in-process through core's own pipeline, and effective
 * styles come from the SAME resolution the lint accessor already does —
 * composition, not a second resolver, so lint and render can never disagree
 * about what a preset slug means.
 */
class Saddle_Render_Gutenberg_Accessor implements Saddle_Render_Accessor {

	/**
	 * The lint accessor doing the actual attr resolution.
	 *
	 * @var Saddle_Lint_Gutenberg_Accessor
	 */
	private $facts;

	public function __construct() {
		$this->facts = new Saddle_Lint_Gutenberg_Accessor();
	}

	/**
	 * Effective styles assembled from the lint accessor's resolved facts.
	 * Keys the node doesn't style are omitted — an empty map means "this
	 * node inherits everything".
	 *
	 * @param array $node Raw block array.
	 * @return array
	 */
	public function effective_styles( array $node ) {
		$styles = array(
			'background'   => $this->facts->background_color( $node ),
			'color'        => $this->facts->text_color( $node ),
			'padding'      => $this->facts->padding( $node ),
			'textAlign'    => $this->facts->alignment( $node ),
			'fontSize'     => $this->facts->font_size( $node ),
			'borderRadius' => $this->facts->border_radius( $node ),
			'gap'          => $this->facts->gap( $node ),
		);
		$styles = array_filter(
			$styles,
			static function ( $value ) {
				return null !== $value;
			}
		);

		if ( $this->facts->is_button( $node ) ) {
			$styles['button_filled'] = $this->facts->button_is_filled( $node );
		}
		return $styles;
	}

	/**
	 * Render the node through core's own block renderer. In-process: block
	 * markup and dynamic blocks are real, theme stylesheets are not applied.
	 *
	 * @param WP_Post $post    The post.
	 * @param array   $tree    Parsed tree.
	 * @param string  $address Dot address.
	 * @return string|WP_Error
	 */
	public function render_node_html( WP_Post $post, array $tree, $address ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Fixed accessor signature.
		$block = Saddle_Tree::get( $tree, (string) $address );
		if ( ! $block ) {
			return new WP_Error( 'saddle_render_no_node', __( 'No node at that address.', 'saddle' ) );
		}
		return render_block( $block );
	}

	/**
	 * In-process: rendered inside this request, theme CSS not applied.
	 *
	 * @return string
	 */
	public function render_fidelity() {
		return 'in-process';
	}
}
