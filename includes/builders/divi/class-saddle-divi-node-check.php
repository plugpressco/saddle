<?php
/**
 * Checks one Divi 5 authoring node without saving it.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a node in the divi-set-page format would save and render as
 * written. Used for a module pack's guide example (Saddle_Divi_Module_Guide).
 *
 * The node is put inside the page structure it needs (a module in a
 * column, row and section; a child module in its parent first, found the
 * way the catalog finds it; a full-width module in a full-width section),
 * built with Saddle_Divi_Author, and passed through
 * Saddle_Divi_Driver::structure(): the loop repair, the nesting rules and
 * the encoding check. Then the echo runs with the verify pass's settings,
 * so only what Divi provably ignores or misreads counts; advisory findings
 * and an unknown module do not.
 *
 * The loop, binding and interaction rules of Saddle_Divi_Write_Checks are
 * left out on purpose: some depend on the account that asks (unfiltered
 * HTML, listing users, protected custom fields), and an example must be
 * the same for every account. Nothing is saved, and the author's notes for
 * the current write are put back.
 *
 * A class of its own, not more of Saddle_Divi_Driver (the save path) or
 * Saddle_Divi_Module_Guide, to keep each near 300 lines.
 */
class Saddle_Divi_Node_Check {

	/**
	 * What would stop a node from saving or rendering as written, as one
	 * whole sentence; '' when nothing would.
	 *
	 * @param array $node Authoring node {type, fields?, settings?, attrs?, children?}.
	 * @return string
	 */
	public static function problem( array $node ) {
		$notes = Saddle_Divi_Author::notes();
		$page  = self::in_page( $node );
		$tree  = Saddle_Divi_Author::expand( array( $page ) );
		Saddle_Divi_Author::restore_notes( $notes );

		$tree = is_wp_error( $tree ) ? $tree : Saddle_Divi_Driver::structure( $tree );
		if ( is_wp_error( $tree ) ) {
			$data      = $tree->get_error_data();
			$violation = is_array( $data ) && isset( $data['violations'][0] ) && is_array( $data['violations'][0] ) ? $data['violations'][0] : array();
			if ( isset( $violation['address'], $violation['problem'] ) ) {
				/* translators: 1: node address, 2: what is wrong there, one sentence. */
				return sprintf( __( 'Node %1$s breaks the page structure: %2$s', 'saddle' ), $violation['address'], $violation['problem'] );
			}
			return $tree->get_error_message();
		}

		$warnings = self::ignored( Saddle_Divi_Author::echo_nodes( array( $page ), '0' ), '0' );
		return $warnings ? (string) $warnings[0] : '';
	}

	/**
	 * The echo's certain findings for nodes and their children: fields and
	 * style paths Divi ignores or misreads.
	 *
	 * @param array  $nodes  Nodes as Saddle_Divi_Author::echo_nodes() gives them.
	 * @param string $prefix Address of their parent.
	 * @return string[]
	 */
	private static function ignored( array $nodes, $prefix ) {
		$options  = array(
			'unknown_module' => false,
			'advisory'       => false,
		);
		$warnings = array();
		foreach ( array_values( $nodes ) as $i => $node ) {
			if ( ! is_array( $node ) || empty( $node['type'] ) ) {
				continue;
			}
			$address  = $prefix . '.' . $i;
			$fields   = isset( $node['fields'] ) && is_array( $node['fields'] ) ? $node['fields'] : array();
			$attrs    = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
			$warnings = array_merge( $warnings, Saddle_Divi_Echo::check( (string) $node['type'], $fields, $attrs, $address, $options ) );
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$warnings = array_merge( $warnings, self::ignored( $node['children'], $address ) );
			}
		}
		return $warnings;
	}

	/**
	 * A node inside the page structure it needs.
	 *
	 * @param array $node Authoring node with a "type".
	 * @return array A root node.
	 */
	private static function in_page( array $node ) {
		$type = trim( (string) $node['type'] );
		if ( in_array( $type, Saddle_Divi_Tree::root_types(), true ) ) {
			return $node;
		}
		$json     = Saddle_Divi_Schema::module_json( $type );
		$category = is_array( $json ) && isset( $json['category'] ) ? (string) $json['category'] : '';
		if ( 'fullwidth-module' === $category ) {
			return array(
				'type'     => 'divi/fullwidth-section',
				'children' => array( $node ),
			);
		}
		$parent = 'child-module' === $category ? self::parent_of( $type ) : '';
		if ( '' !== $parent ) {
			$node = array(
				'type'     => $parent,
				'children' => array( $node ),
			);
		}
		if ( ! in_array( $type, array( 'divi/column', 'divi/row' ), true ) ) {
			$node = array(
				'type'     => 'divi/column',
				'children' => array( $node ),
			);
		}
		if ( 'divi/row' !== $type ) {
			$node = array(
				'type'     => 'divi/row',
				'children' => array( $node ),
			);
		}
		return array(
			'type'     => 'divi/section',
			'children' => array( $node ),
		);
	}

	/**
	 * The module that holds a child type: the first whose childrenName or
	 * childModuleName names it, as Saddle_Divi_Catalog::modules() finds a
	 * child's parent. Real module.json files carry no "parentName".
	 *
	 * @param string $type Child module type.
	 * @return string '' when none does.
	 */
	private static function parent_of( $type ) {
		foreach ( array_keys( Saddle_Divi_Schema::index() ) as $candidate ) {
			$summary = Saddle_Divi_Schema::summary( (string) $candidate );
			if ( is_array( $summary ) && in_array( $type, Saddle_Divi_Catalog::children( (string) $candidate, $summary ), true ) ) {
				return (string) $candidate;
			}
		}
		return '';
	}
}
