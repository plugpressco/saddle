<?php
/**
 * Which Divi 5 module may sit inside which.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Divi 5's own nesting rule, the Visual Builder's `isValidChild()`
 * (Divi 5.10.1 module-library.js, read from the built code, not rendered):
 *
 * - the page holds sections; a section holds rows, a specialty section
 *   holds columns, a full-width section holds modules;
 * - a row holds columns (a row-inner holds column-inners);
 * - a column holds rows (a nested row is a plain divi/row with
 *   divi/column children), groups and any module; a specialty column
 *   holds row-inners; a column-inner holds groups and modules;
 * - a group holds rows, groups and modules; a group carousel holds groups;
 * - any other module follows its module.json: a "childrenName" list names
 *   its child types (plus any module when "allowAllElements" is set); an
 *   empty list lets it hold other modules, rows and groups (Divi 5's
 *   nested modules: TextModule renders its child blocks); no list means it
 *   holds nothing.
 * - a child module (category "child-module": an accordion item, a slide)
 *   sits only in its own parent.
 *
 * A module with no module.json on this site is judged leniently, as
 * before: it may hold anything but the page structure, and it may sit
 * anywhere a module may. A Component (divi/global-layout) is placed by the
 * block it stands for (its "blockName"), and what it holds is not judged:
 * Divi renders the Library copy (BlockParser), and the Visual Builder keeps
 * a copy of the Component's own children inside it.
 */
class Saddle_Divi_Nesting {

	/**
	 * The page structure types. "*" in a rule never matches these.
	 */
	const STRUCTURE = array( 'divi/placeholder', 'divi/section', 'divi/fullwidth-section', 'divi/row', 'divi/row-inner', 'divi/column', 'divi/column-inner' );

	/**
	 * Types an empty childrenName list still refuses (isValidChild()).
	 */
	const NEVER_NESTED = array( 'divi/placeholder', 'divi/section', 'divi/fullwidth-section', 'divi/row-inner', 'divi/column', 'divi/column-inner' );

	/**
	 * Whether a parent block may hold a child block.
	 *
	 * @param array $container Parent block.
	 * @param array $child     Child block.
	 * @return true|string True, or a plain phrase saying what the parent holds.
	 */
	public static function accepts( array $container, array $child ) {
		$name  = (string) $container['blockName'];
		$attrs = is_array( $container['attrs'] ) ? $container['attrs'] : array();
		$type  = self::effective_type( $child );
		if ( null === $type ) {
			return true; // A Component whose block SaddleWP cannot read.
		}

		$rules = Saddle_Divi_Tree::structure();
		if ( 'divi/section' === $name ) {
			$kind = isset( $attrs['module']['advanced']['type']['desktop']['value'] ) ? (string) $attrs['module']['advanced']['type']['desktop']['value'] : '';
			if ( 'fullwidth' === $kind ) {
				$rules[ $name ] = array( '*' );
			} elseif ( 'specialty' === $kind ) {
				$rules[ $name ] = array( 'divi/column' );
			}
		}
		if ( 'divi/column' === $name && ! empty( $attrs['module']['advanced']['specialtyColumns'] ) ) {
			$rules[ $name ] = array( 'divi/row-inner' );
		}
		if ( isset( $rules[ $name ] ) ) {
			return self::matches( (array) $rules[ $name ], $type ) ? true : self::describe( (array) $rules[ $name ] );
		}

		$json = Saddle_Divi_Schema::module_json( $name );
		if ( ! is_array( $json ) ) {
			// No module.json here: hold anything but the page structure.
			return in_array( $type, self::STRUCTURE, true ) ? __( 'modules, never sections, rows or columns', 'saddle' ) : true;
		}
		if ( array_key_exists( 'childrenName', $json ) && array() === $json['childrenName'] ) {
			return ( ! in_array( $type, self::NEVER_NESTED, true ) && 'child-module' !== self::category( $type ) )
				? true
				: __( 'other modules, rows and groups, never a child module such as an accordion item', 'saddle' );
		}
		$names = Saddle_Divi_Catalog::children( $name, $json );
		if ( array( '*' ) === $names ) {
			return self::matches( array( '*' ), $type ) ? true : self::describe( array( '*' ) );
		}
		$all = ! empty( $json['allowAllElements'] );
		if ( in_array( $type, $names, true ) || ( $all && in_array( self::category( $type ), array( 'module', 'fullwidth-module' ), true ) ) ) {
			return true;
		}
		if ( ! $names ) {
			$child_json = Saddle_Divi_Schema::module_json( $type );
			if ( is_array( $child_json ) && isset( $child_json['parentName'] ) && $child_json['parentName'] === $name ) {
				return true;
			}
			return __( 'nothing: it takes no child modules', 'saddle' );
		}
		return $all
			? sprintf(
				/* translators: %s: comma-separated module types. */
				__( '%s, or any module', 'saddle' ),
				implode( ', ', $names )
			)
			: implode( ', ', $names );
	}

	/**
	 * The type a block is placed as: a Component as the block it stands for.
	 *
	 * @param array $block Block.
	 * @return string|null Null for a Component with no readable blockName.
	 */
	public static function effective_type( array $block ) {
		$name = (string) $block['blockName'];
		if ( 'divi/global-layout' !== $name ) {
			return $name;
		}
		$stands_for = isset( $block['attrs']['blockName'] ) ? $block['attrs']['blockName'] : null;
		return is_string( $stands_for ) && preg_match( '#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $stands_for ) && 'divi/global-layout' !== $stands_for ? $stands_for : null;
	}

	/**
	 * Whether a type fits a rule list ("*" = any module that is not page
	 * structure, not a child module and not a layout block such as a group,
	 * which a rule names when it takes one).
	 *
	 * @param string[] $allowed Rule list.
	 * @param string   $type    Child type.
	 * @return bool
	 */
	private static function matches( array $allowed, $type ) {
		if ( in_array( $type, $allowed, true ) ) {
			return true;
		}
		$category = self::category( $type );
		return in_array( '*', $allowed, true ) && ! in_array( $type, self::STRUCTURE, true ) && 'child-module' !== $category && 'structure' !== $category;
	}

	/**
	 * A rule list in words.
	 *
	 * @param string[] $allowed Rule list.
	 * @return string
	 */
	private static function describe( array $allowed ) {
		$named = array_values( array_diff( $allowed, array( '*' ) ) );
		if ( ! in_array( '*', $allowed, true ) ) {
			return implode( ', ', $named );
		}
		return $named
			? sprintf(
				/* translators: %s: comma-separated module types. */
				__( '%s and any module (not a child module such as an accordion item)', 'saddle' ),
				implode( ', ', $named )
			)
			: __( 'any module (not a child module such as an accordion item)', 'saddle' );
	}

	/**
	 * A module's category from its module.json (module, child-module,
	 * fullwidth-module, structure), or null when it has none here.
	 *
	 * @param string $type Module type.
	 * @return string|null
	 */
	private static function category( $type ) {
		$json = Saddle_Divi_Schema::module_json( $type );
		return is_array( $json ) && isset( $json['category'] ) ? (string) $json['category'] : null;
	}
}
