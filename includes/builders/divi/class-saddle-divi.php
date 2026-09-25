<?php
/**
 * Divi 5 environment detection.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers one question reliably in every request context: is Divi 5 here, and
 * is a given post built with it?
 *
 * Detection is theme-based (template = Divi/Extra at version >= 5), not
 * class-based: Divi 5's server framework (`ET\Builder\...`) loads
 * conditionally per request type, so class_exists() checks are flaky outside
 * builder/REST screens. The theme header is always readable.
 */
class Saddle_Divi {

	/**
	 * Divi 5 pages store modules as blocks in this namespace.
	 */
	const BLOCK_NS = 'divi/';

	/**
	 * Whether a Divi theme at major version >= 5 is the active template.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$active = null !== self::version() && version_compare( (string) self::version(), '5.0', '>=' );

		/**
		 * Filter the Divi 5 detection result (used by tests and edge setups
		 * where the theme header alone can't decide).
		 *
		 * @param bool $active Whether Divi 5 is considered active.
		 */
		return (bool) apply_filters( 'saddle_divi_active', $active );
	}

	/**
	 * The active Divi/Extra theme version, or null when a Divi theme isn't
	 * the template (parent-aware: a child theme of Divi still counts).
	 *
	 * @return string|null
	 */
	public static function version() {
		$template = get_template(); // Parent theme slug even under a child theme.
		if ( ! in_array( $template, array( 'Divi', 'Extra' ), true ) ) {
			return null;
		}

		$theme = wp_get_theme( $template );
		return $theme->exists() ? (string) $theme->get( 'Version' ) : null;
	}

	/**
	 * The CSS custom-property reference for a Global Data id.
	 *
	 * Divi's built-in font variables carry ids that ALREADY start with
	 * dashes ('--et_global_heading_font'); naive 'var(--' . $id . ')'
	 * doubled them into var(----et_global_heading_font) — invalid CSS
	 * served to agents as a token. Normalize once, everywhere.
	 *
	 * @param string $id Global color/variable id (e.g. 'gcid-…', '--et_…').
	 * @return string var(--…) reference.
	 */
	public static function css_var( $id ) {
		return 'var(--' . ltrim( (string) $id, '-' ) . ')';
	}

	/**
	 * How a post's content was built: 'divi5', 'divi4', or 'other'.
	 *
	 * Divi 5 pages are Gutenberg-parseable blocks (`<!-- wp:divi/section -->`);
	 * Divi 4 pages are shortcode soup (`[et_pb_section ...]`) flagged by the
	 * `_et_pb_use_builder` meta. Distinguishing matters: Saddle edits D5
	 * trees only and must refuse to touch a D4 page rather than corrupt it.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function post_builder( WP_Post $post ) {
		if ( false !== strpos( (string) $post->post_content, '<!-- wp:' . self::BLOCK_NS ) ) {
			return 'divi5';
		}

		$uses_builder = get_post_meta( $post->ID, '_et_pb_use_builder', true );
		if ( 'on' === $uses_builder || false !== strpos( (string) $post->post_content, '[et_pb_section' ) ) {
			return 'divi4';
		}

		return 'other';
	}

	/**
	 * The shared editability gate for Divi 5 write abilities: Divi active, the
	 * post exists and is a post/page, the user can edit it, and it is a Divi 5
	 * page. Returns the WP_Post on success or a WP_Error the ability returns
	 * verbatim. (divi-set-page keeps its own inline guard — it deliberately
	 * allows an empty non-Divi post to be built into one.)
	 *
	 * @param int $post_id Target post id.
	 * @return WP_Post|WP_Error
	 */
	public static function editable_divi5_post( $post_id ) {
		if ( ! self::is_active() ) {
			return new WP_Error( 'saddle_no_divi', __( 'Divi 5 is not active on this site.', 'saddle' ) );
		}
		$post = $post_id ? get_post( (int) $post_id ) : null;
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'saddle_denied', __( 'You cannot edit this post.', 'saddle' ) );
		}
		if ( 'divi5' !== self::post_builder( $post ) ) {
			return new WP_Error( 'saddle_not_divi5', __( 'This post is not a Divi 5 page. Use divi-set-page to build an empty post, or the regular content tools for non-Divi content.', 'saddle' ) );
		}
		return $post;
	}
}
