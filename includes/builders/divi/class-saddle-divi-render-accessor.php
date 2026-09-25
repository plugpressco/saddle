<?php
/**
 * The Divi 5 implementation of free Saddle's render accessor.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gives free Saddle's render engine (saddle/render-node, the agent's eyes)
 * its Divi 5 sight.
 *
 * Two artifacts, honestly labelled:
 *
 *  - effective_styles() is the load-bearing one: persisted canonical attrs
 *    resolved through the SAME trait the lint accessor reads with —
 *    var(--gcid-…) globals included — so what lint judges and what render
 *    reports can never diverge. Deterministic, in-process, no HTTP.
 *  - render_node_html() renders through core's block renderer in-process.
 *    Divi's per-page CSS pipeline (DynamicAssets) only runs on real
 *    front-end requests, so this is MARKUP fidelity, not pixels — for
 *    pixels the agent calls saddle/get-preview-url and looks with its own
 *    browser. When Divi's runtime isn't registered in this process the
 *    render comes back empty; that returns a clear error pointing at the
 *    preview path instead of pretending an empty string is the render.
 */
class Saddle_Divi_Render_Accessor implements Saddle_Render_Accessor {

	use Saddle_Divi_Attr_Resolution;

	/**
	 * The lint accessor supplying the shared design facts.
	 *
	 * @var Saddle_Divi_Lint_Accessor
	 */
	private $facts;

	/**
	 * Wire up the shared fact reader.
	 */
	public function __construct() {
		$this->facts = new Saddle_Divi_Lint_Accessor();
	}

	/**
	 * Effective styles off the persisted canonical attrs. Base facts come
	 * from the lint accessor; the font size rides the same documented
	 * typography envelope its color lives in. Keys the node doesn't style
	 * are omitted.
	 *
	 * @param array $node Raw block array.
	 * @return array
	 */
	public function effective_styles( array $node ) {
		$size = $this->first_at_path( $node, array( 'decoration', 'font', 'font', 'desktop', 'value', 'size' ) );

		$styles = array(
			'background' => $this->facts->background_color( $node ),
			'color'      => $this->facts->text_color( $node ),
			'padding'    => $this->facts->padding( $node ),
			'textAlign'  => $this->facts->alignment( $node ),
			'fontSize'   => is_string( $size ) && '' !== $size ? $size : null,
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
	 * Render the node's subtree through core's block renderer.
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

		$html = render_block( $block );
		if ( '' === trim( (string) $html ) ) {
			return new WP_Error(
				'saddle_divi_render_unavailable',
				__( 'Divi did not render this module in the current process. The effective styles above are still the persisted truth; for real pixels call saddle/get-preview-url and open it in your browser.', 'saddle' )
			);
		}
		return $html;
	}

	/**
	 * In-process markup — Divi's page CSS only exists on real front-end
	 * requests, so pixels come from the preview URL, not from here.
	 *
	 * @return string
	 */
	public function render_fidelity() {
		return 'in-process';
	}
}
