<?php
/**
 * The built-in build-a-page playbook.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saddle knows how to build a page properly: orient, plan, build against the
 * real design system, prove it, then look at it. Until now that knowledge was
 * scattered across tool descriptions and three bullets of context prose, so an
 * agent had to reassemble the sequence itself every session.
 *
 * This puts it in one place, as a skill the agent can read on demand. It ships
 * only on a block theme: pointing an agent at a Gutenberg playbook on a Divi
 * site would be worse than saying nothing (Saddle Pro bundles its own).
 *
 * The design bar is embedded from Saddle_Context::design_numbers() VERBATIM
 * rather than restated, because that helper is the single source of those
 * numbers and a copy here would drift the first time they change.
 */
class Saddle_Playbook {

	/**
	 * Register the built-in skill. Hooked to `saddle_builtin_skills`.
	 *
	 * @param array[] $skills Built-in skills registered so far.
	 * @return array[]
	 */
	public static function register( $skills ) {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return $skills;
		}

		$skills[] = array(
			'name'        => 'build-page',
			'description' => __( 'Build or edit a page end-to-end with real editor blocks: orient once with context-bundle, plan from a pattern or recipe, build against the site\'s own design system, then verify and look at the result before calling it done.', 'saddle' ),
			'when_to_use' => __( 'creating, rebuilding, or restyling any page or post on a block theme', 'saddle' ),
			'source'      => 'saddle',
			'body'        => self::body(),
		);

		return $skills;
	}

	/**
	 * The playbook body.
	 *
	 * @return string Markdown.
	 */
	private static function body() {
		return implode( "\n", array_merge( self::head(), self::design_bar(), self::tail() ) );
	}

	/**
	 * The workflow and the rules the server actually enforces.
	 *
	 * @return string[]
	 */
	private static function head() {
		return array(
			__( '# Building a page in WordPress — agent playbook', 'saddle' ),
			'',
			__( 'A write call returning success is not evidence the page is right. The verify score is, and even that only covers what a server can check. Work the loop below; do not stop at the first successful write.', 'saddle' ),
			'',
			__( '## The workflow — in this order', 'saddle' ),
			'',
			__( '1. ORIENT — saddle/context-bundle. One call gives you the design system, the blocks worth using, the theme\'s patterns, the site\'s templates and the section recipes. Call it once per session, not per page.', 'saddle' ),
			__( '2. LOOK AT THE SITE — saddle/get-template on the header and footer parts. What wraps every page tells you the width, the spacing and the tone you are designing into. Skipping this is how a page ends up looking bolted on.', 'saddle' ),
			__( '3. PLAN — pick a starting point before composing anything. A theme pattern (saddle/insert-block-pattern) beats a section recipe, because it already carries this theme\'s styling. A recipe (saddle/get-section-recipe) beats hand-stacking blocks. Hand-stacking is the last resort, not the default.', 'saddle' ),
			__( '4. BUILD — saddle/set-blocks for a whole page, saddle/add-block and saddle/edit-block for surgical changes. Read saddle/get-block-schema before using a block type you have not used: it carries the placement rules and a worked example.', 'saddle' ),
			__( '5. STYLE WITH THE SITE\'S OWN VALUES — use the slugs from the design system, never raw hex or pixel values. {"backgroundColor":"<slug>"}, {"textColor":"<slug>"}, {"fontSize":"<slug>"}. A page built from slugs follows the site when the owner changes their palette; a page built from hex values does not.', 'saddle' ),
			__( '6. VERIFY — saddle/verify-page. Fix in this order: structural findings, then "ignored" ones (styling that never took effect), then errors, then warnings. Addresses match saddle/get-blocks, and they SHIFT after a structural edit, so re-read before addressing again. Re-run until the score stops improving.', 'saddle' ),
			__( '7. LOOK — saddle/get-preview-url and open it. The score is explicitly not a visual sign-off: a page can pass every server-side check and still look wrong. This step is not optional.', 'saddle' ),
			'',
			__( '## Rules the server enforces (you cannot talk your way past these)', 'saddle' ),
			'',
			__( '- Invalid block markup is rejected, not saved. A refused write leaves the page exactly as it was.', 'saddle' ),
			__( '- Placement rules are real: a core/button must be inside core/buttons, a core/list-item inside core/list. get-block-schema tells you which.', 'saddle' ),
			__( '- Deleting or overwriting anything takes two calls: a preview, then the same call again with the confirm_token. Nothing is destroyed in one step.', 'saddle' ),
			__( '- Pages built with a page builder are refused by the native block tools. That is deliberate; editing them with the wrong toolset destroys the layout.', 'saddle' ),
			'',
			__( '## Design quality bar — what "designed" means in numbers', 'saddle' ),
			'',
		);
	}

	/**
	 * The shared design bar, embedded verbatim from its single source.
	 *
	 * @return string[]
	 */
	private static function design_bar() {
		$lines = array();
		if ( class_exists( 'Saddle_Context' ) && method_exists( 'Saddle_Context', 'design_numbers' ) ) {
			foreach ( Saddle_Context::design_numbers() as $line ) {
				// The heading and its blank line are supplied by head(), so
				// take only the bullets.
				if ( '' === $line || 0 === strpos( $line, '#' ) ) {
					continue;
				}
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/**
	 * What separates a designed page from a generated one.
	 *
	 * @return string[]
	 */
	private static function tail() {
		return array(
			'',
			__( '## Avoid the generated look', 'saddle' ),
			'',
			__( '- Three identical cards in a row, each with a button and nothing emphasised, is the tell. Feature one. A pricing section that recommends nothing makes the visitor do the work. (The lint rule "no-featured-plan" will say so.)', 'saddle' ),
			__( '- Every section full-width and centered, one after another, reads as a document rather than a page. Vary the rhythm: let something break the width, or pair text against media.', 'saddle' ),
			__( '- More than one accent colour competing for attention means none of them is an accent.', 'saddle' ),
			__( '- Section padding that differs section to section for no reason. Pick a value and hold it. (The lint rule "section-padding" finds the odd one out.)', 'saddle' ),
			'',
			__( '## Copy is design material', 'saddle' ),
			'',
			__( '- Write the real words. Lorem ipsum and "Your headline here" hide whether the layout works.', 'saddle' ),
			__( '- A heading that states a claim beats a label. "Set up in two minutes" beats "Features".', 'saddle' ),
			__( '- If the site already has pages, read one with saddle/get-blocks first and match its voice. You are adding to a site, not starting one.', 'saddle' ),
			'',
			__( '## When you are done', 'saddle' ),
			'',
			__( 'You are done when verify-page reports no structural and no ignored findings, and you have opened the preview URL and looked at the page. Not before.', 'saddle' ),
		);
	}
}
