<?php
/**
 * Accessor resolution — one place where a post's builder is detected and the
 * matching lint/render accessor is resolved through the integration filters.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The build-native-accessor → filter → instanceof-gate sequence used to be
 * copy-pasted across lint-page, render-node, and verify-page, with three
 * hand-synced copies of the same 409 error. It lives here once, so the
 * resolution semantics (and the "no accessor installed" message) cannot drift
 * between the three abilities.
 */
class Saddle_Accessors {

	/**
	 * Resolve the lint accessor for a post: Gutenberg for native pages, the
	 * `saddle_lint_accessor` filter for builder pages.
	 *
	 * @param WP_Post     $post    The post.
	 * @param string      $code    Error code for the unsupported case.
	 * @param string|null $message Optional full error message (sprintf template
	 *                             with %1$d post ID and %2$s builder); defaults
	 *                             to the lint-flavored one.
	 * @return Saddle_Lint_Accessor|WP_Error
	 */
	public static function lint( WP_Post $post, $code = 'saddle_lint_unsupported', $message = null ) {
		$builder  = Saddle_Abilities::builder_signature( $post );
		$driver   = null === $builder ? null : Saddle_Builder_Registry::for_builder( (string) $builder );
		$accessor = null === $builder
			? new Saddle_Lint_Gutenberg_Accessor()
			: ( $driver ? $driver->lint_accessor( $post ) : null );

		/**
		 * Filter the lint accessor for a page.
		 *
		 * Gutenberg and Divi 5 accessors are built in; a builder integration
		 * returns its Saddle_Lint_Accessor implementation when it owns
		 * $builder. Null means the page cannot be linted here.
		 *
		 * @param Saddle_Lint_Accessor|null $accessor Accessor (Gutenberg's for native pages).
		 * @param string|null               $builder  Detected builder, null = native.
		 * @param WP_Post                   $post     The post.
		 */
		$accessor = apply_filters( 'saddle_lint_accessor', $accessor, $builder, $post );

		if ( ! $accessor instanceof Saddle_Lint_Accessor ) {
			return self::unsupported(
				$post,
				$builder,
				$code,
				null !== $message
					? $message
					/* translators: 1: post ID, 2: builder name. */
					: __( 'Post #%1$d is built with %2$s, and no lint accessor for that builder is installed.', 'saddle' )
			);
		}
		return $accessor;
	}

	/**
	 * Resolve the render accessor for a post: Gutenberg for native pages, the
	 * `saddle_render_accessor` filter for builder pages.
	 *
	 * @param WP_Post $post The post.
	 * @return Saddle_Render_Accessor|WP_Error
	 */
	public static function render( WP_Post $post ) {
		$builder  = Saddle_Abilities::builder_signature( $post );
		$driver   = null === $builder ? null : Saddle_Builder_Registry::for_builder( (string) $builder );
		$accessor = null === $builder
			? new Saddle_Render_Gutenberg_Accessor()
			: ( $driver ? $driver->render_accessor() : null );

		/**
		 * Filter the render accessor for a page.
		 *
		 * Gutenberg and Divi 5 accessors are built in; a builder integration
		 * returns its Saddle_Render_Accessor implementation when it owns
		 * $builder. Null means the page cannot be rendered here.
		 *
		 * @param Saddle_Render_Accessor|null $accessor Accessor (Gutenberg's for native pages).
		 * @param string|null                 $builder  Detected builder, null = native.
		 * @param WP_Post                     $post     The post.
		 */
		$accessor = apply_filters( 'saddle_render_accessor', $accessor, $builder, $post );

		if ( ! $accessor instanceof Saddle_Render_Accessor ) {
			return self::unsupported(
				$post,
				$builder,
				'saddle_render_unsupported',
				/* translators: 1: post ID, 2: builder name. */
				__( 'Post #%1$d is built with %2$s, and no render accessor for that builder is installed.', 'saddle' )
			);
		}
		return $accessor;
	}

	/**
	 * The shared 409 for "this builder has no installed accessor".
	 *
	 * @param WP_Post     $post    The post.
	 * @param string|null $builder Detected builder.
	 * @param string      $code    Error code.
	 * @param string      $message sprintf template (%1$d post ID, %2$s builder).
	 * @return WP_Error
	 */
	private static function unsupported( WP_Post $post, $builder, $code, $message ) {
		return new WP_Error(
			$code,
			sprintf( $message, $post->ID, (string) $builder ),
			array( 'status' => 409 )
		);
	}
}
