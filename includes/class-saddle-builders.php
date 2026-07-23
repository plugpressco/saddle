<?php
/**
 * The builder taxonomy — one table describing every page builder Saddle
 * knows about.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for builder knowledge that used to live in three
 * unrelated lists: per-post detection (Saddle_Abilities::builder_signature),
 * site-wide plugin signals (Saddle_Context::builder_signals), and the block
 * namespaces builders own (Saddle_Blocks_Tree::builder_namespaces). Keeping
 * them here means a new builder is added in one row — and the labels the
 * accessor filters receive can never drift from the labels the context
 * warnings use.
 *
 * Two granularities on purpose: per-post rows are FORMATS ("Divi 5" vs
 * "Divi (classic)" — different storage, different tooling), while site
 * signals are keyed by PRODUCT ("Divi" — one install covers both formats).
 */
final class Saddle_Builders {

	/**
	 * The taxonomy. Row order is detection order — first match wins.
	 *
	 * Per row:
	 *   - label      Per-post format label, emitted to the accessor filters.
	 *   - product    Product label used in site-wide context warnings.
	 *   - signals    Constants/functions/classes that mean the product is
	 *                active site-wide (present on one row per product).
	 *   - content    Substring of post_content that marks ownership.
	 *   - meta       Meta key => expected value (true = any truthy value).
	 *   - namespaces Block namespaces the builder owns (invalid in a native
	 *                Gutenberg tree).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function table() {
		return array(
			'divi5'      => array(
				'label'      => 'Divi 5',
				'product'    => 'Divi',
				'signals'    => array( 'ET_BUILDER_VERSION', 'ET_CORE_VERSION', 'et_setup_theme' ),
				'content'    => '<!-- wp:divi/',
				'namespaces' => array( 'divi' ),
			),
			'divi4'      => array(
				'label'   => 'Divi (classic)',
				'product' => 'Divi',
				'meta'    => array( '_et_pb_use_builder' => 'on' ),
				'content' => '[et_pb_section',
			),
			'elementor'  => array(
				'label'   => 'Elementor',
				'product' => 'Elementor',
				'signals' => array( 'ELEMENTOR_VERSION', '\\Elementor\\Plugin' ),
				'meta'    => array( '_elementor_edit_mode' => 'builder' ),
			),
			'beaver'     => array(
				'label'   => 'Beaver Builder',
				'product' => 'Beaver Builder',
				'signals' => array( 'FL_BUILDER_VERSION', 'FLBuilderModel' ),
				'meta'    => array( '_fl_builder_enabled' => true ),
			),
			'bricks'     => array(
				'label'   => 'Bricks',
				'product' => 'Bricks',
				'signals' => array( 'BRICKS_VERSION' ),
				'meta'    => array( '_bricks_page_content_2' => true ),
			),
			'wpbakery'   => array(
				'label'   => 'WPBakery',
				'product' => 'WPBakery',
				'signals' => array( 'WPB_VC_VERSION', 'vc_map' ),
				'content' => '[vc_row',
			),
			'oxygen'     => array(
				'label'   => 'Oxygen',
				'product' => 'Oxygen',
				'signals' => array( 'CT_VERSION' ),
				'meta'    => array( 'ct_builder_shortcodes' => true ),
			),
			'breakdance' => array(
				'label'   => 'Breakdance',
				'product' => 'Breakdance',
				'signals' => array( '__BREAKDANCE_VERSION' ),
				'meta'    => array( 'breakdance_data' => true ),
			),
		);
	}

	/**
	 * Which builder format (if any) owns a post's content. First matching
	 * row wins, mirroring the original if/elseif chain exactly.
	 *
	 * No result cache on purpose: WordPress's meta cache already makes the
	 * repeated get_post_meta() probes O(1) per request, and a static result
	 * cache would go stale the moment an ability (or test) flips builder
	 * meta mid-request.
	 *
	 * @param WP_Post $post Post to inspect.
	 * @return string|null Format label ("Divi 5"), or null for plain content.
	 */
	public static function detect( WP_Post $post ) {
		$content = (string) $post->post_content;

		foreach ( self::table() as $row ) {
			if ( isset( $row['content'] ) && false !== strpos( $content, $row['content'] ) ) {
				return $row['label'];
			}
			if ( isset( $row['meta'] ) ) {
				foreach ( $row['meta'] as $key => $expected ) {
					$value = get_post_meta( $post->ID, $key, true );
					if ( true === $expected ? (bool) $value : $expected === $value ) {
						return $row['label'];
					}
				}
			}
		}

		return null;
	}

	/**
	 * Site-wide detection signals, keyed by PRODUCT label — the shape the
	 * context builder warnings consume.
	 *
	 * @return array<string,string[]> Product label => signal list.
	 */
	public static function site_signals() {
		$signals = array();
		foreach ( self::table() as $row ) {
			if ( isset( $row['signals'] ) && ! isset( $signals[ $row['product'] ] ) ) {
				$signals[ $row['product'] ] = $row['signals'];
			}
		}
		return $signals;
	}

	/**
	 * Every block namespace owned by a builder — invalid inside a native
	 * Gutenberg tree.
	 *
	 * @return string[]
	 */
	public static function namespaces() {
		$namespaces = array();
		foreach ( self::table() as $row ) {
			if ( isset( $row['namespaces'] ) ) {
				$namespaces = array_merge( $namespaces, (array) $row['namespaces'] );
			}
		}
		return array_values( array_unique( $namespaces ) );
	}
}
