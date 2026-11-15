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
	 * The desktop values sit at the top level, as before. `tablet` and
	 * `phone` list only the values that differ from desktop on that screen,
	 * and `hover` only the colors that change on hover, each inherited the
	 * way Divi inherits (a phone with no value of its own shows the tablet's,
	 * then the desktop's).
	 *
	 * @param array $node Raw block array.
	 * @return array
	 */
	public function effective_styles( array $node ) {
		$styles = $this->styles_at( $node, 'desktop', 'value' );

		foreach ( array( 'tablet', 'phone' ) as $breakpoint ) {
			$changed = $this->changed( $styles, $this->styles_at( $node, $breakpoint, 'value' ) );
			if ( $changed ) {
				$styles[ $breakpoint ] = $changed;
			}
		}
		$hover = $this->changed( $styles, array_intersect_key( $this->styles_at( $node, 'desktop', 'hover' ), array_flip( array( 'background', 'color' ) ) ) );
		if ( $hover ) {
			$styles['hover'] = $hover;
		}

		if ( $this->facts->is_button( $node ) ) {
			$styles['button_filled'] = $this->facts->button_is_filled( $node );
		}
		return $styles;
	}

	/**
	 * The styled facts at one breakpoint and state; unstyled keys omitted.
	 *
	 * @param array  $node       Raw block array.
	 * @param string $breakpoint desktop, tablet or phone.
	 * @param string $state      value or hover.
	 * @return array
	 */
	private function styles_at( array $node, $breakpoint, $state ) {
		$padding = $this->merged_at( $node, array( 'decoration', 'spacing' ), 'padding', $breakpoint, $state );
		$align   = $this->value_at( $node, array( 'decoration', 'sizing' ), array( 'alignment' ), $breakpoint, $state );
		$size    = $this->value_at( $node, array( 'decoration', 'font', 'font' ), array( 'size' ), $breakpoint, $state );

		$styles = array(
			'background' => $this->facts->background_color_at( $node, $breakpoint, $state ),
			'color'      => $this->facts->text_color_at( $node, $breakpoint, $state ),
			'padding'    => $padding ? array_intersect_key( $padding, array_flip( array( 'top', 'right', 'bottom', 'left' ) ) ) : null,
			'textAlign'  => is_string( $align ) ? $align : null,
			'fontSize'   => is_string( $size ) ? $size : null,
		);
		return array_filter(
			$styles,
			static function ( $value ) {
				return null !== $value && array() !== $value;
			}
		);
	}

	/**
	 * An object value (padding) merged down Divi's inheritance chain, so a
	 * tablet that sets only the top padding keeps the desktop's bottom.
	 *
	 * @param array    $node       Raw block array.
	 * @param string[] $group      Keys from an attr to its breakpoints.
	 * @param string   $field      Field inside the value.
	 * @param string   $breakpoint Breakpoint.
	 * @param string   $state      State.
	 * @return array|null
	 */
	private function merged_at( array $node, array $group, $field, $breakpoint, $state ) {
		$merged = null;
		foreach ( array_reverse( $this->inherit_order( $breakpoint, $state ) ) as $cell ) {
			$value = $this->first_at_path( $node, array_merge( $group, $cell, array( $field ) ) );
			if ( is_array( $value ) ) {
				$merged = array_replace( (array) $merged, $value );
			}
		}
		return $merged;
	}

	/**
	 * The entries of $other that differ from $base.
	 *
	 * @param array $base  Desktop styles.
	 * @param array $other Styles elsewhere.
	 * @return array
	 */
	private function changed( array $base, array $other ) {
		$changed = array();
		foreach ( $other as $key => $value ) {
			if ( ! array_key_exists( $key, $base ) || $base[ $key ] !== $value ) {
				$changed[ $key ] = $value;
			}
		}
		return $changed;
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
