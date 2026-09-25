<?php
/**
 * WooCommerce environment detection + shared projections.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether WooCommerce is active and centralizes the projections every
 * wc-* ability shares — one place for the compact product/order row shapes so
 * list, preview, and undo responses can never drift apart.
 *
 * Native integration, not a wrapper: WooCommerce registers no abilities of
 * its own to wrap, so these abilities speak WooCommerce's own CRUD API
 * directly (wc_get_product / WC_Product setters + save() / wc_get_orders) —
 * never raw posts/postmeta queries, which is what keeps every read and write
 * HPOS-safe by construction.
 */
class Saddle_WC {

	/**
	 * Whether WooCommerce is active on this site.
	 *
	 * Class + function check, not is_plugin_active() — same detection style
	 * as the SEO integrations.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$active = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );

		/**
		 * Filter WooCommerce detection (tests / edge setups where the class
		 * check alone can't decide).
		 *
		 * @param bool $active Whether WooCommerce is considered active.
		 */
		return (bool) apply_filters( 'saddle_wc_active', $active );
	}

	/**
	 * The active WooCommerce version, or null when it isn't active.
	 *
	 * @return string|null
	 */
	public static function version() {
		return self::is_active() && defined( 'WC_VERSION' ) ? (string) WC_VERSION : null;
	}

	/**
	 * Whether High-Performance Order Storage (custom order tables) is the
	 * authoritative order store. Informational — every order read here goes
	 * through wc_get_orders(), which is HPOS-native either way.
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The standard "not active" error every wc ability returns when called on
	 * a site without WooCommerce — abilities always register (mirroring the
	 * Divi and SEO abilities' unconditional registration), so this is the one
	 * place that refusal message lives.
	 *
	 * @return WP_Error
	 */
	public static function not_active_error() {
		return new WP_Error(
			'saddle_no_wc',
			__( 'WooCommerce is not active on this site.', 'saddle' )
		);
	}

	/**
	 * Compact product row: the projection wc-list-products returns and every
	 * bulk preview names its items with.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function product_row( $product ) {
		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
		);
	}

	/**
	 * Full product projection for wc-get-product.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function product_detail( $product ) {
		$categories = array();
		foreach ( $product->get_category_ids() as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$categories[] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		$tags = array();
		foreach ( $product->get_tag_ids() as $term_id ) {
			$term = get_term( $term_id, 'product_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$tags[] = $term->name;
			}
		}

		return array_merge(
			self::product_row( $product ),
			array(
				'catalog_visibility' => $product->get_catalog_visibility(),
				'featured'           => $product->get_featured(),
				'sold_individually'  => $product->get_sold_individually(),
				'manage_stock'       => $product->get_manage_stock(),
				'backorders'         => $product->get_backorders(),
				'categories'         => $categories,
				'tags'               => $tags,
				'permalink'          => get_permalink( $product->get_id() ),
			)
		);
	}

	/**
	 * Compact order row for wc-list-orders. Speaks WC_Order's own getters
	 * only, so the projection is identical under HPOS and legacy storage.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order_row( $order ) {
		$date = $order->get_date_created();

		return array(
			'id'       => $order->get_id(),
			'status'   => $order->get_status(),
			'date'     => $date ? $date->date( 'Y-m-d H:i:s' ) : null,
			'total'    => $order->get_total(),
			'currency' => $order->get_currency(),
			'customer' => array(
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email' => $order->get_billing_email(),
			),
			'items'    => count( $order->get_items() ),
		);
	}
}
