<?php
/**
 * Lint rule: an empty image column with nothing to give it height.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A column with no modules, only a background image, is as tall as its
 * content: nothing. It shows only while the row stretches it to a taller
 * sibling column. It collapses, and the image disappears, when:
 *
 *  - no other column in the row holds anything;
 *  - the row does not stretch its columns (layout alignItems other than
 *    "stretch", the row default);
 *  - at some screen size the column is full width ("24_24", Divi's default
 *    flexType) beside other columns, so it wraps onto its own line.
 *
 * A minimum height, a height, an aspect ratio or vertical padding at any
 * screen size gives it a height, and the rule stays silent. Rows in grid
 * mode stretch their items and are skipped. Read from Divi 5.10.1 source
 * (Module.php flex column classes, column default flexType "24_24").
 * Divi pages only.
 */
class Saddle_Lint_Rule_Collapsed_Column extends Saddle_Lint_Rule {

	/**
	 * Column types judged.
	 */
	const COLUMNS = array( 'divi/column', 'divi/column-inner' );

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'collapsed-column';
	}

	/**
	 * Flag empty image columns that collapse.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Divi_Lint_Accessor ) {
			return array();
		}
		$facts      = new Saddle_Divi_Lint_Facts();
		$by_address = array_column( $nodes, null, 'address' );
		$violations = array();

		foreach ( $nodes as $node ) {
			$block = $node['block'];
			if ( ! in_array( $node['type'], self::COLUMNS, true ) || $this->children( $nodes, $node['address'] )
				|| ! $facts->background_image( $block ) || $this->has_height( $block, $facts ) ) {
				continue;
			}
			$row = $facts->parent_of( $node, $by_address );
			if ( ! $row || 'grid' === $facts->value_of( $row['block'], array( 'module', 'decoration', 'layout' ), 'display' ) ) {
				continue;
			}
			$where = $this->collapses( $node, $row, $nodes, $facts );
			if ( null === $where ) {
				continue;
			}
			$violations[] = $this->violation(
				$node['address'],
				Saddle_Lint_Rule::SEVERITY_WARN,
				$where,
				__( 'Give it a height with divi-edit-module on this address, for example attrs { "module.decoration.sizing.desktop.value.minHeight": "400px", "module.decoration.sizing.phone.value.minHeight": "240px" }. Or use an image module instead of a background.', 'saddle' )
			);
		}
		return $violations;
	}

	/**
	 * Why and where the column collapses, as the finding's message, or null.
	 *
	 * @param array                  $node  Column entry.
	 * @param array                  $row   Row entry.
	 * @param array[]                $nodes Flat node list.
	 * @param Saddle_Divi_Lint_Facts $facts Facts reader.
	 * @return string|null
	 */
	private function collapses( array $node, array $row, array $nodes, Saddle_Divi_Lint_Facts $facts ) {
		$siblings = array();
		$filled   = false;
		foreach ( $this->children( $nodes, $row['address'] ) as $sibling ) {
			if ( $sibling['address'] !== $node['address'] && in_array( $sibling['type'], self::COLUMNS, true ) ) {
				$siblings[] = $sibling;
				$filled     = $filled || (bool) $this->children( $nodes, $sibling['address'] );
			}
		}
		if ( ! $filled ) {
			return __( 'This column holds only a background image, and no other column in the row has content, so it has no height and the image does not show.', 'saddle' );
		}

		$align = $facts->value_of( $row['block'], array( 'module', 'decoration', 'layout' ), 'alignItems' );
		if ( is_string( $align ) && '' !== $align && 'stretch' !== $align ) {
			return __( 'This column holds only a background image, and its row does not stretch its columns, so it has no height and the image does not show.', 'saddle' );
		}

		$names = array(
			'desktop' => __( 'On desktop, this column holds only a background image and takes the full row width, so it sits on its own line with no height and the image does not show.', 'saddle' ),
			'tablet'  => __( 'On tablets, this column holds only a background image and takes the full row width, so it sits on its own line with no height and the image does not show.', 'saddle' ),
			'phone'   => __( 'On phones, this column holds only a background image and takes the full row width, so it sits on its own line with no height and the image does not show.', 'saddle' ),
		);
		foreach ( $names as $breakpoint => $message ) {
			$type = $facts->value_of( $node['block'], array( 'module', 'decoration', 'sizing' ), 'flexType', $breakpoint );
			if ( null === $type || '24_24' === $type ) {
				return $message;
			}
		}
		return null;
	}

	/**
	 * Whether the column gets a height of its own at any screen size.
	 *
	 * @param array                  $block Raw block array.
	 * @param Saddle_Divi_Lint_Facts $facts Facts reader.
	 * @return bool
	 */
	private function has_height( array $block, Saddle_Divi_Lint_Facts $facts ) {
		foreach ( array( 'desktop', 'tablet', 'phone' ) as $breakpoint ) {
			foreach ( array( 'minHeight', 'height' ) as $key ) {
				if ( Saddle_Divi_Lint_Facts::is_nonzero( $facts->value_of( $block, array( 'module', 'decoration', 'sizing' ), $key, $breakpoint ) ) ) {
					return true;
				}
			}
			if ( is_array( $facts->value_of( $block, array( 'module', 'decoration', 'sizing' ), 'aspectRatio', $breakpoint ) ) ) {
				return true;
			}
			$padding = $facts->value_of( $block, array( 'module', 'decoration', 'spacing' ), 'padding', $breakpoint );
			if ( is_array( $padding ) && ( Saddle_Divi_Lint_Facts::is_nonzero( isset( $padding['top'] ) ? $padding['top'] : '' ) || Saddle_Divi_Lint_Facts::is_nonzero( isset( $padding['bottom'] ) ? $padding['bottom'] : '' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
