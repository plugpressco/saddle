<?php
/**
 * Thin WooCommerce stubs for the wc-* ability tests.
 *
 * WooCommerce won't be a real test dependency (same call as the Yoast/AIOSEO
 * stubs): what the suite must cover is THIS plugin's logic — selector
 * resolution and caps, the diff math (price operations, rounding, the
 * sale-vs-regular guard), the preview/confirm/undo flow, per-object
 * capability checks — not WooCommerce's own CRUD. These stubs mirror the
 * exact API surface the integration consumes: wc_get_product /
 * wc_get_products / wc_format_decimal, WC_Product getters + setters + save()
 * (products backed by real posts of a 'product' post type so status and
 * term writes are real), and a seedable wc_get_orders. Live behavior against
 * real WooCommerce (HPOS on) is verified separately on divi-dev — a
 * done-gate item, same as the Divi design-system writes.
 *
 * @package Saddle
 */

if ( ! defined( 'WC_VERSION' ) ) {
	define( 'WC_VERSION', '99.0-stub' );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {
		public $version = WC_VERSION;
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {

		private static $store = array();

		private $id;
		private $data;

		public function __construct( $id ) {
			$this->id = (int) $id;
			if ( ! isset( self::$store[ $this->id ] ) ) {
				self::$store[ $this->id ] = array(
					'type'               => 'simple',
					'sku'                => '',
					'catalog_visibility' => 'visible',
					'featured'           => false,
					'sold_individually'  => false,
					'regular_price'      => '',
					'sale_price'         => '',
					'manage_stock'       => false,
					'stock_quantity'     => null,
					'stock_status'       => 'instock',
					'backorders'         => 'no',
					'children'           => array(),
				);
			}
			$this->data = self::$store[ $this->id ];
		}

		/**
		 * Test seam: wipe the store. The suite's DB rolls back between tests
		 * (post IDs get reused), so a persistent static store would leak one
		 * test's product data into the next test's colliding IDs.
		 */
		public static function reset() {
			self::$store = array();
		}

		/** Test seam: set arbitrary stored fields directly. */
		public static function seed( $id, array $fields ) {
			$product            = new self( $id );
			self::$store[ $id ] = array_merge( $product->data, $fields );
		}

		public function get_id() {
			return $this->id;
		}
		public function get_name() {
			$post = get_post( $this->id );
			return $post ? $post->post_title : '';
		}
		public function get_status() {
			$post = get_post( $this->id );
			return $post ? $post->post_status : '';
		}
		public function set_status( $status ) {
			wp_update_post(
				array(
					'ID'          => $this->id,
					'post_status' => $status,
				)
			);
		}
		public function get_type() {
			return $this->data['type'];
		}
		public function get_sku() {
			return $this->data['sku'];
		}
		public function get_catalog_visibility() {
			return $this->data['catalog_visibility'];
		}
		public function set_catalog_visibility( $value ) {
			$this->data['catalog_visibility'] = $value;
		}
		public function get_featured() {
			return $this->data['featured'];
		}
		public function set_featured( $value ) {
			$this->data['featured'] = (bool) $value;
		}
		public function get_sold_individually() {
			return $this->data['sold_individually'];
		}
		public function set_sold_individually( $value ) {
			$this->data['sold_individually'] = (bool) $value;
		}
		public function get_regular_price() {
			return $this->data['regular_price'];
		}
		public function set_regular_price( $value ) {
			$this->data['regular_price'] = (string) $value;
		}
		public function get_sale_price() {
			return $this->data['sale_price'];
		}
		public function set_sale_price( $value ) {
			$this->data['sale_price'] = (string) $value;
		}
		public function get_manage_stock() {
			return $this->data['manage_stock'];
		}
		public function set_manage_stock( $value ) {
			$this->data['manage_stock'] = (bool) $value;
		}
		public function get_stock_quantity() {
			return $this->data['stock_quantity'];
		}
		public function set_stock_quantity( $value ) {
			$this->data['stock_quantity'] = null === $value || '' === $value ? null : (int) $value;
		}
		public function get_stock_status() {
			return $this->data['stock_status'];
		}
		public function set_stock_status( $value ) {
			$this->data['stock_status'] = $value;
		}
		public function get_backorders() {
			return $this->data['backorders'];
		}
		public function set_backorders( $value ) {
			$this->data['backorders'] = $value;
		}
		public function get_category_ids() {
			return array_map( 'intval', wp_get_object_terms( $this->id, 'product_cat', array( 'fields' => 'ids' ) ) );
		}
		public function set_category_ids( $ids ) {
			wp_set_object_terms( $this->id, array_map( 'intval', (array) $ids ), 'product_cat' );
		}
		public function get_tag_ids() {
			return array_map( 'intval', wp_get_object_terms( $this->id, 'product_tag', array( 'fields' => 'ids' ) ) );
		}
		public function set_tag_ids( $ids ) {
			wp_set_object_terms( $this->id, array_map( 'intval', (array) $ids ), 'product_tag' );
		}
		public function get_children() {
			return $this->data['children'];
		}
		public function get_attributes() {
			return array();
		}

		public function save() {
			self::$store[ $this->id ] = $this->data;
			return $this->id;
		}
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product_id ) {
		$post = get_post( (int) $product_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return false;
		}
		return new WC_Product( $post->ID );
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	function wc_get_products( $args ) {
		$posts = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => isset( $args['order'] ) && 'ASC' === $args['order'] ? 'ASC' : 'DESC',
			)
		);

		$products = array();
		foreach ( $posts as $post ) {
			$product = new WC_Product( $post->ID );
			if ( isset( $args['status'] ) && $product->get_status() !== $args['status'] ) {
				continue;
			}
			if ( isset( $args['type'] ) && $product->get_type() !== $args['type'] ) {
				continue;
			}
			if ( isset( $args['stock_status'] ) && $product->get_stock_status() !== $args['stock_status'] ) {
				continue;
			}
			if ( isset( $args['sku'] ) && false === stripos( $product->get_sku(), $args['sku'] ) ) {
				continue;
			}
			if ( isset( $args['s'] ) && false === stripos( $product->get_name(), $args['s'] ) ) {
				continue;
			}
			if ( isset( $args['category'] ) && ! has_term( (array) $args['category'], 'product_cat', $post ) ) {
				continue;
			}
			if ( isset( $args['tag'] ) && ! has_term( (array) $args['tag'], 'product_tag', $post ) ) {
				continue;
			}
			$products[] = $product;
		}

		$total = count( $products );
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		if ( $limit > 0 ) {
			$products = array_slice( $products, ( $page - 1 ) * $limit, $limit );
		}

		if ( ! empty( $args['paginate'] ) ) {
			return (object) array(
				'products'      => $products,
				'total'         => $total,
				'max_num_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
			);
		}
		return $products;
	}
}

if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( $number ) {
		if ( '' === $number || null === $number ) {
			return '';
		}
		$formatted = number_format( (float) $number, 2, '.', '' );
		return false === strpos( $formatted, '.' ) ? $formatted : rtrim( rtrim( $formatted, '0' ), '.' );
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {

		private static $orders = array();
		private $data;

		public function __construct( array $data ) {
			$this->data = $data;
		}

		/** Test seam: replace the whole seeded order list. */
		public static function seed( array $rows ) {
			self::$orders = array();
			foreach ( $rows as $row ) {
				self::$orders[] = new self( $row );
			}
		}
		public static function all() {
			return self::$orders;
		}

		public function get_id() {
			return (int) $this->data['id'];
		}
		public function get_status() {
			return $this->data['status'];
		}
		public function get_total() {
			return $this->data['total'];
		}
		public function get_currency() {
			return isset( $this->data['currency'] ) ? $this->data['currency'] : 'USD';
		}
		public function get_date_created() {
			return new class() {
				public function date( $format ) {
					return gmdate( $format, 1700000000 );
				}
			};
		}
		public function get_billing_first_name() {
			return isset( $this->data['first_name'] ) ? $this->data['first_name'] : '';
		}
		public function get_billing_last_name() {
			return isset( $this->data['last_name'] ) ? $this->data['last_name'] : '';
		}
		public function get_billing_email() {
			return isset( $this->data['email'] ) ? $this->data['email'] : '';
		}
		public function get_items() {
			return isset( $this->data['items'] ) ? $this->data['items'] : array();
		}
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args ) {
		$orders = WC_Order::all();
		if ( isset( $args['status'] ) && '' !== $args['status'] ) {
			$orders = array_values(
				array_filter(
					$orders,
					static function ( $order ) use ( $args ) {
						return $order->get_status() === $args['status'];
					}
				)
			);
		}

		$total = count( $orders );
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		if ( $limit > 0 ) {
			$orders = array_slice( $orders, ( $page - 1 ) * $limit, $limit );
		}

		if ( ! empty( $args['paginate'] ) ) {
			return (object) array(
				'orders'        => $orders,
				'total'         => $total,
				'max_num_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
			);
		}
		return $orders;
	}
}
