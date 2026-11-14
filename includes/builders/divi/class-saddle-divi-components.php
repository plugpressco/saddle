<?php
/**
 * Components (divi/global-layout) and Slots (divi/global-slot) an agent places.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A Component is a section, row or module saved to the Divi Library; a page
 * shows it through `divi/global-layout` with the Library post's id:
 *
 *   {"type":"divi/global-layout","attrs":{"globalModule":"61528"}}
 *
 * Divi 5.10.1 stores exactly that block (Conversion.php writes
 * {"globalModule","blockName","localAttrs"}) and swaps in the Library post
 * at render time, only for a published `et_pb_layout` post
 * (BlockParser.php, whose pattern wants the id as a quoted number). SaddleWP
 * checks the id is a Library item the account can read and fills
 * "blockName" from the Library item, so the validator can place the
 * Component where its block belongs: a section at the page root, a row in a
 * section, a module in a column.
 *
 * Slots (`divi/global-slot`, the part of a Component each page fills) arrive
 * in Divi 5.15. Divi 5.10.1 has no slot code, so their shape comes from a
 * Divi 5 reference measured on 5.16, not from source:
 * {"type":"divi/global-layout","attrs":{"globalModule":"61529"},"children":[
 * {"type":"divi/global-slot","attrs":{"slotId":"<id from the Library item>"},
 * "children":[ …modules… ]}]}. SaddleWP refuses them before Divi 5.15 and
 * checks each slotId exists in the Library item.
 */
class Saddle_Divi_Components {

	/**
	 * The first Divi version with Slots.
	 */
	const SLOTS_SINCE = '5.15';

	/**
	 * Check a Component node an agent wrote and complete its attrs.
	 *
	 * @param array   $attrs    Its attrs.
	 * @param array[] $children Its child blocks.
	 * @param string  $address  Node address.
	 * @return array|WP_Error Attrs with blockName set.
	 */
	public static function component( array $attrs, array $children, $address ) {
		$id      = isset( $attrs['globalModule'] ) && is_scalar( $attrs['globalModule'] ) ? trim( (string) $attrs['globalModule'] ) : '';
		$library = ctype_digit( $id ) ? get_post( (int) $id ) : null;
		if ( ! $library || 'et_pb_layout' !== $library->post_type || 'publish' !== $library->post_status || ! current_user_can( 'read_post', $library->ID ) ) {
			return self::refuse(
				$address,
				/* translators: 1: node address, 2: id as written. */
				__( 'Node %1$s (divi/global-layout): "%2$s" is not a published Divi Library item you can read. Put the Library post id in attrs.globalModule, e.g. {"type":"divi/global-layout","attrs":{"globalModule":"123"}}; divi-list-library-items lists them. Nothing was saved.', 'saddle' ),
				$id
			);
		}
		$attrs['globalModule'] = (string) $library->ID;

		$saved = self::saved_block( $library );
		if ( '' === $saved ) {
			return self::refuse(
				$address,
				/* translators: 1: node address, 2: Library id. */
				__( 'Node %1$s (divi/global-layout): Library item %2$s holds no Divi block to show. Nothing was saved.', 'saddle' ),
				$id
			);
		}
		if ( isset( $attrs['blockName'] ) && $attrs['blockName'] !== $saved ) {
			return self::refuse(
				$address,
				/* translators: 1: node address, 2: the block the Library item holds. */
				__( 'Node %1$s (divi/global-layout): this Library item holds a %2$s, so attrs.blockName must be that or left out. Nothing was saved.', 'saddle' ),
				$saved
			);
		}
		$attrs['blockName'] = $saved;

		$slots = self::slot_ids( (string) $library->post_content );
		foreach ( $children as $i => $child ) {
			if ( 'divi/global-slot' !== $child['blockName'] ) {
				return self::refuse(
					$address,
					/* translators: 1: node address, 2: child type. */
					__( 'Node %1$s (divi/global-layout): a Component holds only divi/global-slot nodes that fill its Slots, not %2$s. Leave "children" out to show the Component as it is. Nothing was saved.', 'saddle' ),
					(string) $child['blockName']
				);
			}
			$slot = isset( $child['attrs']['slotId'] ) && is_string( $child['attrs']['slotId'] ) ? $child['attrs']['slotId'] : '';
			if ( ! in_array( $slot, $slots, true ) ) {
				return new WP_Error(
					'saddle_bad_component',
					sprintf(
						/* translators: 1: node address, 2: slot id, 3: slot ids in the Library item. */
						__( 'Node %1$s: the Library item has no Slot "%2$s". Its Slots: %3$s. Nothing was saved.', 'saddle' ),
						$address . '.' . $i,
						$slot,
						$slots ? implode( ', ', $slots ) : __( '(none)', 'saddle' )
					),
					array( 'status' => 400 )
				);
			}
		}
		return $attrs;
	}

	/**
	 * Refuse a Slot on a Divi version without them.
	 *
	 * @param string $address Node address.
	 * @return true|WP_Error
	 */
	public static function slot( $address ) {
		$version = Saddle_Divi::version();
		if ( null !== $version && version_compare( (string) $version, self::SLOTS_SINCE, '<' ) ) {
			return self::refuse(
				$address,
				/* translators: 1: node address, 2: Divi version. */
				__( 'Node %1$s (divi/global-slot): Slots need Divi 5.15 or newer; this site runs Divi %2$s. Nothing was saved.', 'saddle' ),
				(string) $version
			);
		}
		return true;
	}

	/**
	 * The block a Library item saves: the first block inside its
	 * divi/placeholder, or its first block.
	 *
	 * @param WP_Post $library Library post.
	 * @return string Block name, or ''.
	 */
	private static function saved_block( WP_Post $library ) {
		$blocks = Saddle_Divi_Tree::parse( (string) $library->post_content );
		if ( isset( $blocks[0] ) && 'divi/placeholder' === $blocks[0]['blockName'] ) {
			$blocks = $blocks[0]['innerBlocks'];
		}
		return isset( $blocks[0]['blockName'] ) ? (string) $blocks[0]['blockName'] : '';
	}

	/**
	 * Every slotId in a Library item's markup.
	 *
	 * @param string $content Library post content.
	 * @return string[]
	 */
	private static function slot_ids( $content ) {
		$ids = array();
		foreach ( Saddle_Divi_Tree::flatten( Saddle_Divi_Tree::parse( $content ) ) as $node ) {
			if ( 'divi/global-slot' === $node['type'] && isset( $node['attrs']['slotId'] ) && is_string( $node['attrs']['slotId'] ) ) {
				$ids[] = $node['attrs']['slotId'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * A refusal naming the node.
	 *
	 * @param string $address Node address.
	 * @param string $format  Translated sentence with %1$s for the address.
	 * @param string $detail  Second placeholder.
	 * @return WP_Error
	 */
	private static function refuse( $address, $format, $detail ) {
		return new WP_Error(
			'saddle_bad_component',
			sprintf( $format, '' === (string) $address ? '0' : $address, $detail ),
			array( 'status' => 400 )
		);
	}
}
