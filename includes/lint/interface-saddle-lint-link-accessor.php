<?php
/**
 * Links and buttons, for the link-text rule.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The links and buttons a node renders itself (#183). A separate interface
 * for the same reason as Saddle_Lint_Style_Accessor: adding a method to an
 * existing one would fatal any accessor that hasn't caught up, so accessors
 * opt in and the rule feature-detects with instanceof.
 */
interface Saddle_Lint_Link_Accessor {

	/**
	 * The links and buttons in the node's own markup (not its children's).
	 *
	 * @param array $node Raw block array.
	 * @return array[] Each { name, button }: the accessible name a screen
	 *                 reader announces (text, aria-label, or an inner image's
	 *                 alt), and whether it is a button. Empty when none.
	 */
	public function links( array $node );
}
