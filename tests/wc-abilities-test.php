<?php
/**
 * saddle/wc-* — native WooCommerce read abilities.
 *
 * Coverage: registration tiers, the not-active refusal, and the product and
 * order reads. Against the thin WooCommerce
 * CRUD stubs in tests/stubs-woocommerce.php — behavior against real
 * WooCommerce (HPOS on) is verified separately on divi-dev.
 *
 * @package Saddle
 */

class Saddle_WC_Abilities_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		WC_Product::reset(); // Post IDs are reused across rolled-back tests — stale stub data must not survive.
		WC_Order::seed( array() );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		remove_filter( 'saddle_wc_active', '__return_false' );
		parent::tear_down();
	}

	/**
	 * A product post + seeded WC data.
	 *
	 * @param string $title  Product name.
	 * @param array  $fields WC_Product stub fields (regular_price, type, …).
	 * @param string $status Post status.
	 * @return int Product id.
	 */
	private function product( $title, array $fields = array(), $status = 'publish' ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
		WC_Product::seed( $id, $fields );
		return $id;
	}

	/* -------- registration -------- */

	public function test_registration_tiers() {
		$expect = array(
			'saddle/wc-check-setup'   => array( 'read', false ),
			'saddle/wc-list-products' => array( 'read', false ),
			'saddle/wc-get-product'   => array( 'read', false ),
			// Customer names and emails: Edit content, not Read only (#321).
			'saddle/wc-list-orders'   => array( 'write', false ),
		);

		foreach ( $expect as $name => list( $tier, $destructive ) ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "{$name} must be registered." );
			$this->assertSame( $tier, $ability->get_meta()['saddle']['tier'], "{$name} tier" );
			$this->assertSame( $destructive, $ability->get_meta()['annotations']['destructive'], "{$name} destructive flag" );
		}
	}

	/* -------- check-setup + not-active refusal -------- */

	public function test_check_setup_reports_active_and_version() {
		$this->product( 'Hoodie', array( 'regular_price' => '25' ) );

		$result = wp_get_ability( 'saddle/wc-check-setup' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['wc_active'] );
		$this->assertSame( '99.0-stub', $result['wc_version'] );
		$this->assertSame( 1, $result['published_products'] );
	}

	public function test_abilities_report_not_active_when_wc_inactive() {
		add_filter( 'saddle_wc_active', '__return_false' );

		$setup = wp_get_ability( 'saddle/wc-check-setup' )->execute( array() );
		$this->assertFalse( $setup['wc_active'] );
		$this->assertNotEmpty( $setup['note'] );

		$result = wp_get_ability( 'saddle/wc-list-products' )->execute( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_no_wc', $result->get_error_code() );
	}

	/* -------- reads -------- */

	public function test_list_products_filters_and_paginates() {
		$this->product( 'Hoodie', array( 'regular_price' => '25', 'sku' => 'HOOD-1' ) );
		$this->product( 'Mug', array( 'regular_price' => '9' ) );
		$this->product( 'Draft thing', array(), 'draft' );

		$all = wp_get_ability( 'saddle/wc-list-products' )->execute( array() );
		$this->assertSame( 3, $all['total'] );

		$published = wp_get_ability( 'saddle/wc-list-products' )->execute( array( 'status' => 'publish' ) );
		$this->assertSame( 2, $published['total'] );

		$paged = wp_get_ability( 'saddle/wc-list-products' )->execute( array( 'per_page' => 2 ) );
		$this->assertSame( 3, $paged['total'] );
		$this->assertSame( 2, $paged['pages'] );
		$this->assertCount( 2, $paged['products'] );

		$by_sku = wp_get_ability( 'saddle/wc-list-products' )->execute( array( 'sku' => 'hood' ) );
		$this->assertSame( 1, $by_sku['total'] );
		$this->assertSame( 'Hoodie', $by_sku['products'][0]['name'] );
	}

	public function test_get_product_returns_detail() {
		$term = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Apparel' ) );
		$id   = $this->product( 'Hoodie', array( 'regular_price' => '25', 'sale_price' => '19.99', 'sku' => 'HOOD-1' ) );
		wp_set_object_terms( $id, array( $term ), 'product_cat' );

		$result = wp_get_ability( 'saddle/wc-get-product' )->execute( array( 'product_id' => $id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Hoodie', $result['name'] );
		$this->assertSame( '25', $result['regular_price'] );
		$this->assertSame( '19.99', $result['sale_price'] );
		$this->assertSame( 'visible', $result['catalog_visibility'] );
		$this->assertSame( 'Apparel', $result['categories'][0]['name'] );

		$missing = wp_get_ability( 'saddle/wc-get-product' )->execute( array( 'product_id' => 999999 ) );
		$this->assertWPError( $missing );
	}

	public function test_get_product_accepts_id_as_well_as_product_id() {
		$id = $this->product( 'Hoodie', array( 'regular_price' => '25' ) );

		// The content tools call it `id`, so that is an agent's first try.
		$by_id = wp_get_ability( 'saddle/wc-get-product' )->execute( array( 'id' => $id ) );
		$this->assertNotWPError( $by_id );
		$this->assertSame( 'Hoodie', $by_id['name'] );

		// product_id stays the documented name (R4).
		$schema = wp_get_ability( 'saddle/wc-get-product' )->get_input_schema();
		$this->assertArrayHasKey( 'product_id', $schema['properties'] );
		$this->assertSame( 'Hoodie', wp_get_ability( 'saddle/wc-get-product' )->execute( array( 'product_id' => $id ) )['name'] );
		$this->assertSame( 'Hoodie', wp_get_ability( 'saddle/wc-get-product' )->execute( array( 'product_id' => $id, 'id' => $id ) )['name'] );
	}

	public function test_get_product_refuses_no_id_and_two_different_ids() {
		$a = $this->product( 'Hoodie' );
		$b = $this->product( 'Mug' );

		$none = wp_get_ability( 'saddle/wc-get-product' )->execute( array() );
		$this->assertWPError( $none );
		$this->assertStringContainsString( 'product_id', $none->get_error_message() );

		$both = wp_get_ability( 'saddle/wc-get-product' )->execute( array( 'product_id' => $a, 'id' => $b ) );
		$this->assertWPError( $both );
		$this->assertSame( 'saddle_ambiguous_product', $both->get_error_code() );
	}

	public function test_list_orders_rows_and_status_filter() {
		WC_Order::seed(
			array(
				array( 'id' => 1, 'status' => 'processing', 'total' => '30.00', 'email' => 'a@x.test', 'first_name' => 'Ann', 'last_name' => 'Lee', 'items' => array( 1, 2 ) ),
				array( 'id' => 2, 'status' => 'completed', 'total' => '9.00', 'email' => 'b@x.test' ),
			)
		);

		$all = wp_get_ability( 'saddle/wc-list-orders' )->execute( array() );
		$this->assertSame( 2, $all['total'] );
		$this->assertSame( 'Ann Lee', $all['orders'][0]['customer']['name'] );
		$this->assertSame( 2, $all['orders'][0]['items'] );

		$done = wp_get_ability( 'saddle/wc-list-orders' )->execute( array( 'status' => 'completed' ) );
		$this->assertSame( 1, $done['total'] );
		$this->assertSame( 2, $done['orders'][0]['id'] );
	}

	/* -------- customer data needs Edit content (#321) -------- */

	public function test_list_orders_is_refused_at_read_only_with_the_tier_message() {
		WC_Order::seed(
			array(
				array( 'id' => 1, 'status' => 'processing', 'total' => '30.00', 'email' => 'a@x.test', 'first_name' => 'Ann', 'last_name' => 'Lee' ),
			)
		);
		Saddle_Capabilities::set_tier( 'read' );

		$result = wp_get_ability( 'saddle/wc-list-orders' )->execute( array() );

		$this->assertWPError( $result, 'Order rows carry customer names and emails; Read only must not reach them.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$reason = Saddle_Capabilities::denial_reason( 'saddle/wc-list-orders' );
		$this->assertSame( 'saddle_tier_denied', $reason['code'] );
		$this->assertStringContainsString( '"write"', $reason['message'] );
		$this->assertStringContainsString( 'Saddle → AI apps', $reason['message'] );
	}

	public function test_list_orders_works_at_edit_content() {
		WC_Order::seed(
			array(
				array( 'id' => 1, 'status' => 'processing', 'total' => '30.00', 'email' => 'a@x.test', 'first_name' => 'Ann', 'last_name' => 'Lee' ),
			)
		);
		Saddle_Capabilities::set_tier( 'write' );

		$result = wp_get_ability( 'saddle/wc-list-orders' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertSame( 'a@x.test', $result['orders'][0]['customer']['email'] );
		$this->assertNull( Saddle_Capabilities::denial_reason( 'saddle/wc-list-orders' ) );
	}

	public function test_product_reads_stay_at_read_only() {
		$id = $this->product( 'Hoodie', array( 'regular_price' => '25' ) );
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertNotWPError( wp_get_ability( 'saddle/wc-check-setup' )->execute( array() ) );
		$this->assertNotWPError( wp_get_ability( 'saddle/wc-list-products' )->execute( array() ) );
		$this->assertNotWPError( wp_get_ability( 'saddle/wc-get-product' )->execute( array( 'product_id' => $id ) ) );
	}

	/**
	 * Saddle Pro 1.6.1 and older register saddle/wc-list-orders themselves, at
	 * Read only and at priority 20, before free's priority-30 registration.
	 * Free must replace that copy, or the older add-on keeps customer data at
	 * Read only on every site that still runs it.
	 */
	public function test_an_older_add_on_copy_at_read_only_is_replaced() {
		global $wp_filter;

		WP_Abilities_Registry::get_instance();
		wp_unregister_ability( 'saddle/wc-list-orders' );

		$add_on = array(
			'label'               => 'Add-on copy',
			'description'         => 'Older add-on registration.',
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => '__return_empty_array',
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'edit_shop_orders', 'wc-list-orders' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		);

		// Fire wp_abilities_api_init with only these two callbacks, so the rest
		// of the registry is not registered a second time.
		$saved = isset( $wp_filter['wp_abilities_api_init'] ) ? $wp_filter['wp_abilities_api_init'] : null;
		unset( $wp_filter['wp_abilities_api_init'] );
		try {
			add_action(
				'wp_abilities_api_init',
				static function () use ( $add_on ) {
					wp_register_ability( 'saddle/wc-list-orders', $add_on );
				},
				20
			);
			add_action( 'wp_abilities_api_init', 'saddle_register_wc_abilities', 30 );
			do_action( 'wp_abilities_api_init' );
		} finally {
			unset( $wp_filter['wp_abilities_api_init'] );
			if ( null !== $saved ) {
				$wp_filter['wp_abilities_api_init'] = $saved;
			}
		}

		$ability = wp_get_ability( 'saddle/wc-list-orders' );
		$this->assertSame( 'List WooCommerce orders', $ability->get_label(), 'Free\'s copy must win over the older add-on\'s.' );
		$this->assertSame( 'write', $ability->get_meta()['saddle']['tier'] );

		Saddle_Capabilities::set_tier( 'read' );
		$this->assertWPError( $ability->execute( array() ), 'The replaced copy must refuse Read only.' );
	}
}
