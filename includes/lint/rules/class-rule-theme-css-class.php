<?php
/**
 * Lint rule: styling delegated to theme CSS classes.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A module wearing CSS classes (module.advanced.html…class) delegates its
 * look to theme stylesheets Saddle cannot read — the server-side score
 * cannot cover what those classes do, and an agent that applies classes it
 * found somewhere is styling blind (observed live: "mesh sky" utility
 * classes produced a garish gradient the agent never saw). One advisory per
 * classed module; a class the USER named is legitimate — the fix hint says
 * exactly that.
 */
class Saddle_Lint_Rule_Theme_Css_Class extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'theme-css-class';
	}

	/**
	 * Flag modules that carry CSS classes.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}

		$violations = array();
		foreach ( $nodes as $node ) {
			$class = $accessor->css_class( $node['block'] );
			if ( null === $class ) {
				continue;
			}
			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_WARN,
				sprintf(
					/* translators: 1: module type, 2: CSS class list. */
					__( '%1$s delegates styling to the CSS class(es) "%2$s" — Saddle cannot see theme stylesheets, so nothing server-side can verify what these classes render.', 'saddle' ),
					(string) $node['type'],
					$class
				),
				__( 'Unless the user named this exact class, remove it and style through attrs, tokens (var(--gcid-…)), or presets — then check real pixels with get-preview-url.', 'saddle' )
			);
		}
		return $violations;
	}
}
