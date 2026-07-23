<?php
/**
 * Base class every lint rule extends.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One rule = one small class: an id() and a check() over the flat node list
 * (https://github.com/plugpressco/saddle/issues/10 §2.1). The base class carries only the violation format and
 * the tree-shape helpers every rule needs; design judgment lives in the
 * subclasses, builder knowledge lives in the accessor.
 *
 * Nodes arrive as the engine's flat list, document order. Each entry:
 * address, type (blockName), block (raw block array), parent (address or
 * null), depth. Rules return violations; the engine stamps the rule id.
 */
abstract class Saddle_Lint_Rule {

	const SEVERITY_ERROR = 'error';
	const SEVERITY_WARN  = 'warn';

	/**
	 * Stable rule id, e.g. 'button-contrast'.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Check the page and return violations.
	 *
	 * @param array[]              $nodes    Flat node list (see class doc).
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[] Violations: address, severity, message, fix_hint.
	 */
	abstract public function check( array $nodes, Saddle_Lint_Accessor $accessor );

	/**
	 * Build one violation entry.
	 *
	 * @param string $address  Node address.
	 * @param string $severity self::SEVERITY_*.
	 * @param string $message  What is wrong, in plain language.
	 * @param string $fix_hint How an agent fixes it.
	 * @return array
	 */
	protected function violation( $address, $severity, $message, $fix_hint ) {
		return array(
			'address'  => (string) $address,
			'severity' => $severity,
			'message'  => $message,
			'fix_hint' => $fix_hint,
		);
	}

	/**
	 * The direct children of an address, in order.
	 *
	 * @param array[]     $nodes  Flat node list.
	 * @param string|null $parent_address Parent address (null = roots).
	 * @return array[]
	 */
	protected function children( array $nodes, $parent_address ) {
		return Saddle_Lint::children_of( $nodes, $parent_address );
	}

	/**
	 * Whether $node lies strictly inside the subtree at $ancestor_address.
	 *
	 * @param array  $node             Node entry.
	 * @param string $ancestor_address Candidate ancestor address.
	 * @return bool
	 */
	protected function is_descendant( array $node, $ancestor_address ) {
		return Saddle_Lint::is_descendant( $node, $ancestor_address );
	}

	/**
	 * The page's top-level sections: the root nodes, or — when the page is a
	 * single root wrapper (Divi's divi/placeholder, a lone all-page group) —
	 * that wrapper's children. This is what "sibling sections" means to the
	 * padding-rhythm and card-row rules on every builder.
	 *
	 * @param array[] $nodes Flat node list.
	 * @return array[]
	 */
	protected function sections( array $nodes ) {
		return Saddle_Lint::sections( $nodes );
	}
}
