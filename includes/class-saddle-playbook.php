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
		// The real question is not "block theme?" but "does something else own
		// these pages?". A classic theme still edits its posts and pages in the
		// block editor, and gating on wp_is_block_theme() left exactly those
		// sites — the ones with no builder and no site editor to fall back on —
		// with no playbook at all. A FOREIGN builder is the disqualifier: its
		// pages are markup inside the content and the native block tools refuse
		// them, so a Gutenberg playbook would be worse than saying nothing.
		// Divi with Saddle Pro is not foreign, and Pro bundles its own.
		if ( class_exists( 'Saddle_Context' ) && Saddle_Context::foreign_builders() ) {
			return $skills;
		}

		$skills[] = array(
			'name'        => 'build-page',
			'description' => __( 'Build or edit a page end-to-end with real editor blocks: orient once with context-bundle, plan from a pattern or recipe, build against the site\'s own design system, then verify and look at the result before calling it done.', 'saddle' ),
			'when_to_use' => __( 'creating, rebuilding, or restyling any page or post', 'saddle' ),
			'source'      => 'saddle',
			'body'        => self::body(),
		);

		$skills[] = array(
			'name'        => 'fix-page',
			'description' => __( 'Work a verify-page report down to nothing: which findings to fix first, why block addresses move under you after a structural edit, and when the page is actually done.', 'saddle' ),
			'when_to_use' => __( 'a page exists and verify-page or lint-page reported findings to fix', 'saddle' ),
			'source'      => 'saddle',
			'body'        => self::repair_body(),
		);

		return $skills;
	}

	/**
	 * The repair loop.
	 *
	 * Split out of build-page step 6, where it was one bullet. Fixing a page
	 * someone else built — or that you built three turns ago — is the flow an
	 * agent hits most often and the one it most often runs out of order:
	 * fixing warnings before structure, and re-using addresses that moved
	 * under it when the structure changed.
	 *
	 * @return string Markdown.
	 */
	private static function repair_body() {
		return implode(
			"\n",
			array(
				__( '# Fixing a page from its verify report — agent playbook', 'saddle' ),
				'',
				__( 'saddle/verify-page returns findings, a letter grade and a coverage caveat. The grade is honest about its own limits: a structural finding caps it at C and an "ignored" finding at B, because both mean the page is not doing what the markup says it does. Work them in the order below — fixing warnings first wastes the effort when a structural fix moves everything.', 'saddle' ),
				'',
				__( '## The order, and why it is this order', 'saddle' ),
				'',
				__( '1. STRUCTURAL findings first. These say the block tree itself is wrong — something nested where it cannot be, or a container with nothing in it. Every other finding is measured against a tree that is about to change, so fixing anything else first is work you will redo.', 'saddle' ),
				__( '2. IGNORED findings next. An "ignored" finding means you set something that never took effect: an attribute the block does not have, a preset slug that matches nothing on this site, a style group the block does not support. The page looks unstyled and the markup looks fine, which is why this one is easy to miss and worth its own pass. The fix is almost always a slug from saddle/context-bundle instead of an invented value.', 'saddle' ),
				__( '3. ERRORS, then WARNINGS. By this point the tree is right and the styling is landing, so these are real judgements about the design rather than noise.', 'saddle' ),
				'',
				__( '## Addresses move — re-read before you address again', 'saddle' ),
				'',
				__( 'Findings are addressed the same way saddle/get-blocks addresses nodes. Those addresses are positional, so ANY structural edit — adding, removing or moving a block — shifts every address after it. An address you read before that edit now points somewhere else.', 'saddle' ),
				__( 'So: make one structural change, then call saddle/get-blocks again before touching the next one. Batching several structural edits against one read is the single most common way to damage a page while trying to fix it.', 'saddle' ),
				__( 'Non-structural edits (saddle/edit-block on attributes or text) do not move anything, so those can be batched safely.', 'saddle' ),
				'',
				__( '## Working the loop', 'saddle' ),
				'',
				__( '- Re-run saddle/verify-page after each pass. Stop when the score stops improving, not when it hits a number you like.', 'saddle' ),
				__( '- If a finding survives a fix you believe in, read the node with saddle/get-blocks and check what actually saved. A refused write leaves the page exactly as it was, so "I fixed it" and "it is fixed" are different claims.', 'saddle' ),
				__( '- saddle/lint-page is the design-quality half on its own, without the scoring. Use it when you want the findings but not the grade.', 'saddle' ),
				'',
				__( '## When it is done', 'saddle' ),
				'',
				__( 'No structural and no ignored findings, and you have opened saddle/get-preview-url and looked at the page. The report is server-side only and says so in its own coverage caveat — it cannot see overlap, contrast in context, or a section that is simply ugly. A page can score well and still be wrong, and that judgement is yours to make with your eyes.', 'saddle' ),
			)
		);
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
	 * Whether this site has block templates and parts to read.
	 *
	 * The playbook now ships on classic themes too, where step 2 — go look at
	 * the header and footer — has nothing to point at, because they live in
	 * theme PHP Saddle does not edit. Telling an agent to call get-template
	 * there sends it after a tool that will refuse it.
	 *
	 * @return bool
	 */
	private static function has_block_templates() {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * The workflow and the rules the server actually enforces.
	 *
	 * @return string[]
	 */
	private static function head() {
		$look = self::has_block_templates()
			? __( '2. LOOK AT THE SITE — saddle/get-template on the header and footer parts. What wraps every page tells you the width, the spacing and the tone you are designing into. Skipping this is how a page ends up looking bolted on.', 'saddle' )
			: __( '2. LOOK AT THE SITE — this is a classic theme, so the header and footer live in theme files Saddle does not read. Open saddle/get-blocks on an existing page instead: what is already there tells you the width, the spacing and the tone you are designing into. Skipping this is how a page ends up looking bolted on.', 'saddle' );

		return array(
			__( '# Building a page in WordPress — agent playbook', 'saddle' ),
			'',
			__( 'A write call returning success is not evidence the page is right. The verify score is, and even that only covers what a server can check. Work the loop below; do not stop at the first successful write.', 'saddle' ),
			'',
			__( '## The workflow — in this order', 'saddle' ),
			'',
			__( '1. ORIENT — saddle/context-bundle. One call gives you the design system, the blocks worth using, the theme\'s patterns, the site\'s templates and the section recipes. Call it once per session, not per page.', 'saddle' ),
			$look,
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
