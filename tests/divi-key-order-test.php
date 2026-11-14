<?php
/**
 * Stored key order inside decoration groups (Saddle_Divi_Key_Order).
 *
 * Divi 5 picks the CSS and scripts a page loads by running regexes over the
 * stored block JSON. Two read keys in order; these are their patterns as
 * Divi 5.10.1 writes them (DetectFeature.php for animation,
 * DynamicAssetsUtils.php for web fonts). A write whose keys came in another
 * order saved fine and then never animated, or never loaded its font.
 *
 * @package Saddle
 */

class Saddle_Divi_Key_Order_Test extends WP_UnitTestCase {

	const ANIMATION = '/"animation":\s*{[^{}]*"desktop":\s*{[^{}]*"value":\s*{[^{}]*"style":\s*"(fade|slide|bounce|zoom|flip|fold|roll)"/';

	const FONT = '/"font":\s*\{[^}]*"([^"]+)":\s*\{[^}]*"value":\s*\{[^}]*"family":\s*"([^"]+)"/';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	/** The block JSON as WordPress stores it. */
	private function stored( array $attrs ) {
		return Saddle_Divi_Tree::serialize( array( Saddle_Divi_Tree::make_module( 'divi/text', $attrs ) ) );
	}

	private function animation( array $group ) {
		return array( 'module' => array( 'decoration' => array( 'animation' => $group ) ) );
	}

	private function font( array $envelope ) {
		return array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => $envelope ) ) ) );
	}

	/** Cases the regexes miss as written, with the reason. */
	public function missed_cases() {
		return array(
			'intensity object before style' => array(
				'animation',
				array(
					'desktop' => array(
						'value' => array(
							'intensity' => array( 'slide' => '20%' ),
							'style'     => 'slide',
						),
					),
				),
			),
			'tablet before desktop'         => array(
				'animation',
				array(
					'tablet'  => array( 'value' => array( 'style' => 'fade' ) ),
					'desktop' => array( 'value' => array( 'style' => 'fade' ) ),
				),
			),
			'hover before value'            => array(
				'font',
				array(
					'desktop' => array(
						'hover' => array( 'color' => '#111' ),
						'value' => array( 'family' => 'Inter' ),
					),
				),
			),
			'variationSettings before family' => array(
				'font',
				array(
					'desktop' => array(
						'value' => array(
							'variationSettings' => array( 'wght' => '600' ),
							'family'            => 'Inter',
						),
					),
				),
			),
			'tablet before desktop font'    => array(
				'font',
				array(
					'tablet'  => array( 'value' => array( 'size' => '20px' ) ),
					'desktop' => array( 'value' => array( 'family' => 'Inter' ) ),
				),
			),
		);
	}

	/**
	 * @dataProvider missed_cases
	 */
	public function test_divi_misses_these_until_the_keys_are_ordered( $kind, array $group ) {
		$attrs   = 'animation' === $kind ? $this->animation( $group ) : $this->font( $group );
		$pattern = 'animation' === $kind ? self::ANIMATION : self::FONT;

		$this->assertSame( 0, preg_match( $pattern, $this->stored( $attrs ) ), 'Divi misses the written order.' );
		$this->assertSame( 1, preg_match( $pattern, $this->stored( Saddle_Divi_Key_Order::apply( $attrs ) ) ), 'Found once ordered.' );
	}

	public function test_orders_already_found_stay_found_and_values_survive() {
		$attrs = $this->animation(
			array(
				'desktop' => array( 'value' => array( 'style' => 'zoom', 'duration' => '600ms' ) ),
				'phone'   => array( 'value' => array( 'style' => 'fade' ) ),
			)
		);
		$ordered = Saddle_Divi_Key_Order::apply( $attrs );
		$this->assertSame( 1, preg_match( self::ANIMATION, $this->stored( $ordered ) ) );
		$this->assertEquals( $attrs, $ordered, 'Same keys and values, only the order may move.' );
	}

	public function test_breakpoints_follow_divis_order_and_nothing_outside_decoration_moves() {
		$attrs = array(
			'module' => array(
				'advanced'   => array( 'loop' => array( 'phone' => array( 'value' => 'a' ), 'desktop' => array( 'value' => 'b' ) ) ),
				'decoration' => array(
					'spacing' => array(
						'phone'   => array( 'value' => array( 'padding' => array( 'top' => '1px' ) ) ),
						'tablet'  => array( 'value' => array( 'padding' => array( 'top' => '2px' ) ) ),
						'desktop' => array( 'hover' => array(), 'value' => array( 'padding' => array( 'top' => '3px' ) ) ),
					),
				),
			),
		);
		$ordered = Saddle_Divi_Key_Order::apply( $attrs );

		$this->assertSame( array( 'desktop', 'tablet', 'phone' ), array_keys( $ordered['module']['decoration']['spacing'] ) );
		$this->assertSame( array( 'value', 'hover' ), array_keys( $ordered['module']['decoration']['spacing']['desktop'] ) );
		$this->assertSame( array( 'phone', 'desktop' ), array_keys( $ordered['module']['advanced']['loop'] ), 'Advanced options are not touched.' );
	}

	public function test_set_page_stores_an_animation_divi_detects() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );
		$result  = wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $post_id,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array(
							array(
								'type'     => 'divi/row',
								'children' => array(
									array(
										'type'     => 'divi/column',
										'children' => array(
											array(
												'type'  => 'divi/text',
												'attrs' => array(
													'module.decoration.animation@tablet' => array( 'style' => 'fade' ),
													'module.decoration.animation' => array(
														'intensity' => array( 'slide' => '10%' ),
														'style'     => 'slide',
													),
												),
											),
										),
									),
								),
							),
						),
					),
				),
			)
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 1, preg_match( self::ANIMATION, get_post( $post_id )->post_content ) );
	}

	public function test_edit_module_stores_a_font_divi_loads() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/heading {"title":{"decoration":{"font":{"font":{"tablet":{"value":{"size":"20px"}}}}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->',
			)
		);
		$result = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $post_id,
				'address' => '0.0.0.0',
				'attrs'   => array( 'title.decoration.font.font.desktop.value.family' => 'Inter' ),
			)
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 1, preg_match( self::FONT, get_post( $post_id )->post_content ), 'Desktop now comes before the stored tablet value.' );
	}
}
