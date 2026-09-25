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
			'saddle/wc-list-orders'   => array( 'read', false ),
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
}
