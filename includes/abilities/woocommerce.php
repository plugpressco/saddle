<?php
/**
 * saddle/wc-* — native WooCommerce read abilities.
 *
 * Reads over WooCommerce's own CRUD API (wc_get_product / wc_get_products /
 * wc_get_orders) — never raw posts/postmeta queries, which is what keeps
 * everything HPOS-safe by construction. Not a wrapper: WooCommerce registers
 * no abilities of its own. Registered in the `saddle/` namespace so these
 * surface automatically through Saddle's MCP server, Permissions UI, and
 * per-ability toggles.
 *
 * Registered unconditionally: each one refuses cleanly with an actionable
 * error when WooCommerce isn't active rather than being conditionally
 * registered.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The product selector properties: wc-list-products filters by them, and any
 * product-targeting tool can share the same shape.
 *
 * @return array JSON-schema property map.
 */
function saddle_wc_selector_props() {
	return array(
		'product_ids'  => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
			'description' => __( 'Exact product IDs to target. When given, every other filter is ignored. Variation IDs are refused — target the parent variable product where supported.', 'saddle' ),
		),
		'category'     => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => __( 'Product category slugs to match.', 'saddle' ),
		),
		'tag'          => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => __( 'Product tag slugs to match.', 'saddle' ),
		),
		'search'       => array(
			'type'        => 'string',
			'description' => __( 'Match products by name/content search.', 'saddle' ),
		),
		'sku'          => array(
			'type'        => 'string',
			'description' => __( 'Match by SKU (partial matches included).', 'saddle' ),
		),
		'type'         => array(
			'type'        => 'string',
			'enum'        => array( 'simple', 'variable', 'grouped', 'external' ),
			'description' => __( 'Match one product type.', 'saddle' ),
		),
		'status'       => array(
			'type'        => 'string',
			'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
			'description' => __( 'Match one post status.', 'saddle' ),
		),
		'stock_status' => array(
			'type'        => 'string',
			'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
			'description' => __( 'Match one stock status.', 'saddle' ),
		),
		'all'          => array(
			'type'        => 'boolean',
			'description' => __( 'Explicitly target every product (still capped at 100 per batch). Required if no other selector is given — an empty selector never silently means "the whole store".', 'saddle' ),
		),
	);
}

/**
 * Register the WooCommerce read abilities. Hooked to `wp_abilities_api_init`
 * at priority 30 through saddle_register_ability_once(), so a site still
 * running an older add-on that registers the same names keeps that copy.
 */
function saddle_register_wc_abilities() {

	saddle_register_ability_once(
		'saddle/wc-check-setup',
		array(
			'label'               => __( 'Check WooCommerce setup', 'saddle' ),
			'description'         => __( 'Reports whether WooCommerce is active on this site, its version, whether High-Performance Order Storage is enabled, the store currency, and the product count. Read-only. Call this before using any other wc-* tool.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_WC_Abilities', 'check_setup' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'wc-check-setup' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/wc-list-products',
		array(
			'label'               => __( 'List WooCommerce products', 'saddle' ),
			'description'         => __( 'Lists products as compact rows (id, name, sku, type, status, prices, stock), filterable by category, tag, search, sku, type, status, and stock status. Paginated. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array_merge(
					saddle_wc_selector_props(),
					array(
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page number (default 1).', 'saddle' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Rows per page, 1–100 (default 20).', 'saddle' ),
						),
					)
				),
			),
			'execute_callback'    => array( 'Saddle_WC_Abilities', 'list_products' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'wc-list-products' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/wc-get-product',
		array(
			'label'               => __( 'Get WooCommerce product', 'saddle' ),
			'description'         => __( 'Returns one product in full: prices, stock, visibility, categories, tags — and for a variable product, its variations with their attributes and prices. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'product_id' ),
				'properties' => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'The product ID to read.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_WC_Abilities', 'get_product' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'wc-get-product' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	saddle_register_ability_once(
		'saddle/wc-list-orders',
		array(
			'label'               => __( 'List WooCommerce orders', 'saddle' ),
			'description'         => __( 'Lists orders as compact rows (id, status, date, total, customer, item count), filterable by status. Paginated, HPOS-native. Read-only — order rows include customer name and email.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'status'   => array(
						'type'        => 'string',
						'description' => __( 'Order status to match, e.g. "processing", "completed", "on-hold". Omit for all.', 'saddle' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'description' => __( 'Page number (default 1).', 'saddle' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'description' => __( 'Rows per page, 1–100 (default 20).', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_WC_Abilities', 'list_orders' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'edit_shop_orders', 'wc-list-orders' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Execute callbacks for the WooCommerce abilities.
 *
 * Permission has already passed (tier + capability + pause + per-ability
 * toggle) by the time these run — same contract as every other Saddle
 * ability.
 */
class Saddle_WC_Abilities {

	/**
	 * saddle/wc-check-setup.
	 *
	 * @return array
	 */
	public static function check_setup() {
		$out = array(
			'wc_active'  => Saddle_WC::is_active(),
			'wc_version' => Saddle_WC::version(),
		);

		if ( ! $out['wc_active'] ) {
			$out['note'] = __( 'WooCommerce is not active on this site. The wc-* tools are unavailable.', 'saddle' );
			return $out;
		}

		$counts = wp_count_posts( 'product' );

		$out['hpos_enabled']       = Saddle_WC::hpos_enabled();
		$out['currency']           = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null;
		$out['published_products'] = isset( $counts->publish ) ? (int) $counts->publish : 0;

		return $out;
	}

	/**
	 * saddle/wc-list-products.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_products( $input ) {
		if ( ! Saddle_WC::is_active() ) {
			return Saddle_WC::not_active_error();
		}

		$page = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per  = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$args = array(
			'limit'    => $per,
			'page'     => $page,
			'paginate' => true,
		);
		foreach ( array(
			'search'       => 's',
			'sku'          => 'sku',
			'type'         => 'type',
			'status'       => 'status',
			'stock_status' => 'stock_status',
			'category'     => 'category',
			'tag'          => 'tag',
		) as $key => $arg ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] && array() !== $input[ $key ] ) {
				$args[ $arg ] = $input[ $key ];
			}
		}

		$results = wc_get_products( $args );

		return array(
			'total'    => (int) $results->total,
			'page'     => $page,
			'pages'    => (int) $results->max_num_pages,
			'products' => array_map( array( 'Saddle_WC', 'product_row' ), $results->products ),
		);
	}

	/**
	 * saddle/wc-get-product.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_product( $input ) {
		if ( ! Saddle_WC::is_active() ) {
			return Saddle_WC::not_active_error();
		}

		$product = wc_get_product( isset( $input['product_id'] ) ? (int) $input['product_id'] : 0 );
		if ( ! $product ) {
			return new WP_Error( 'saddle_not_found', __( 'No product with that ID.', 'saddle' ) );
		}

		$out = Saddle_WC::product_detail( $product );

		if ( 'variable' === $product->get_type() ) {
			$variations = array();
			foreach ( array_slice( $product->get_children(), 0, 50 ) as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation ) {
					$variations[] = array_merge(
						Saddle_WC::product_row( $variation ),
						array( 'attributes' => $variation->get_attributes() )
					);
				}
			}
			$out['variations'] = $variations;
		}

		return $out;
	}

	/**
	 * saddle/wc-list-orders.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_orders( $input ) {
		if ( ! Saddle_WC::is_active() || ! function_exists( 'wc_get_orders' ) ) {
			return Saddle_WC::not_active_error();
		}

		$page = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per  = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$args = array(
			'limit'    => $per,
			'page'     => $page,
			'paginate' => true,
		);
		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$args['status'] = (string) $input['status'];
		}

		$results = wc_get_orders( $args );

		return array(
			'total'  => (int) $results->total,
			'page'   => $page,
			'pages'  => (int) $results->max_num_pages,
			'orders' => array_map( array( 'Saddle_WC', 'order_row' ), $results->orders ),
		);
	}
}
