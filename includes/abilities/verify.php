<?php
/**
 * The verify ability — the closed loop's checkpoint.
 *
 * saddle/verify-page re-reads a page's PERSISTED state and returns one
 * scored, loopable report: structural soundness, silently-ignored attrs
 * (echo), and design/accessibility judgments (lint). Addresses match the
 * page-read tools, so an agent fixes by address and re-verifies
 * (https://github.com/plugpressco/saddle/issues/26).
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the verify ability. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_verify_abilities() {

	wp_register_ability(
		'saddle/verify-page',
		array(
			'label'               => __( 'Verify a page', 'saddle' ),
			'description'         => __( 'Re-reads a page\'s SAVED state and returns one scored report (0–100 + grade): structural problems, attributes the builder silently ignores (your styling never took effect), and design/accessibility violations — each finding at a node address with a fix hint. Run it after building or editing: fix structural and "ignored" findings first, then errors, then re-run until the score is acceptable. This is how you know your work actually landed, not just that the write calls returned.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page to verify.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Verify_Abilities', 'verify_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'verify-page' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Execute callbacks for the verify ability.
 */
class Saddle_Verify_Abilities {

	/**
	 * saddle/verify-page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function verify_page( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = Saddle_Abilities::require_readable_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$builder  = Saddle_Abilities::builder_signature( $post );
		$resolved = Saddle_Accessors::lint(
			$post,
			'saddle_verify_unsupported',
			/* translators: 1: post ID, 2: builder name. */
			__( 'Post #%1$d is built with %2$s, and no verifier for that builder is installed. Divi 5 pages need Saddle Pro.', 'saddle' )
		);

		// Verify can still run its structural + echo passes without a lint
		// accessor (a builder may provide findings without lint), so an
		// unresolved accessor only skips pass three here.
		$report = Saddle_Verify::run( $post, $builder, is_wp_error( $resolved ) ? null : $resolved );

		// A builder page where NOTHING could run isn't a report, it's a gap:
		// no lint accessor resolved AND the builder's structural/echo passes
		// were skipped too.
		if ( is_wp_error( $resolved )
			&& in_array( 'structural', $report['skipped'], true )
			&& in_array( 'echo', $report['skipped'], true ) ) {
			return $resolved;
		}

		return array_merge(
			array(
				'id'      => $post->ID,
				'builder' => null === $builder ? 'native' : $builder,
			),
			$report,
			array(
				'note' => $report['findings']
					? __( 'Fix in order: structural, then "ignored" (echo — that styling never took effect), then errors, then warnings. Addresses match get-blocks/divi-get-page; re-read after structural edits, then re-run verify-page until the score is acceptable.', 'saddle' )
					: __( 'Everything checked out: the persisted state is structurally sound, every attribute takes effect, and no design violations were found.', 'saddle' ),
			)
		);
	}
}
