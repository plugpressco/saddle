<?php
/**
 * The design-lint ability.
 *
 * One read-only ability, saddle/lint-page, running the builder-agnostic lint
 * engine (includes/lint/) over a page. Native pages use the Gutenberg
 * accessor; builder pages resolve their accessor through the
 * `saddle_lint_accessor` filter, which is how Saddle Pro plugs Divi in — the
 * whole quality layer stays ONE tool surface across builders.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the lint ability. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_lint_abilities() {

	wp_register_ability(
		'saddle/lint-page',
		array(
			'label'               => __( 'Lint page design', 'saddle' ),
			'description'         => __( 'Reviews a post or page\'s design and returns violations per node address: empty titles, button contrast below WCAG AA, ghost/outline buttons, duplicated backgrounds, mixed accent colors, unaligned sibling buttons, broken section padding rhythm, and equal card rows with no featured plan. Read-only — run it after building or editing a page, fix what you agree with (severity "error" first), then re-run. Works on native block pages, and on builder pages whose tools are installed (Divi 5 with Saddle Pro).', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post or page to lint.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Lint_Abilities', 'lint_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'lint-page' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Execute callbacks for the lint ability.
 */
class Saddle_Lint_Abilities {

	/**
	 * saddle/lint-page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function lint_page( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$post  = Saddle_Abilities::require_readable_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$builder  = Saddle_Abilities::builder_signature( $post );
		$accessor = Saddle_Accessors::lint( $post );
		if ( is_wp_error( $accessor ) ) {
			return $accessor;
		}

		$violations = Saddle_Lint::run( Saddle_Tree::parse( $post->post_content ), $accessor );

		$errors = 0;
		foreach ( $violations as $violation ) {
			if ( 'error' === $violation['severity'] ) {
				++$errors;
			}
		}

		return array(
			'id'         => $post->ID,
			'builder'    => null === $builder ? 'native' : $builder,
			'violations' => $violations,
			'count'      => count( $violations ),
			'errors'     => $errors,
			'note'       => $violations
				? __( 'Fix "error" violations first (they are objective defects), weigh "warn" ones against the design intent, then re-run. Addresses match the page\'s current get-blocks/divi-get-page read.', 'saddle' )
				: __( 'No design violations found.', 'saddle' ),
		);
	}
}
