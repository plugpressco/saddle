<?php
/**
 * The builder driver interface + registry.
 *
 * Pins the INTEGRATIONS-PLAN §7 refactor: the Divi driver is pure
 * delegation to the tested Divi classes (no behavior change), the registry
 * resolves drivers by slug / builder label / post through the single
 * `saddle_builder_drivers` filter, and free Saddle's lint wiring gets
 * its accessor from the registry — the seam Elementor/Bricks plug into
 * without touching the ability layer.
 *
 * @package Saddle
 */

class Saddle_Divi_Builder_Driver_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'saddle_builder_drivers' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Builder_Registry::reset();
		parent::tear_down();
	}

	/* -------- registry resolution -------- */

	public function test_divi_driver_registers_by_default() {
		Saddle_Builder_Registry::reset();

		$driver = Saddle_Builder_Registry::get( 'divi' );
		$this->assertInstanceOf( 'Saddle_Divi_Driver', $driver );
		$this->assertSame( $driver->builder_name(), Saddle_Builder_Registry::for_builder( 'Divi 5' )->builder_name() );
		$this->assertNull( Saddle_Builder_Registry::get( 'elementor' ) );
		$this->assertNull( Saddle_Builder_Registry::for_builder( 'Bricks' ) );
	}

	public function test_for_post_asks_each_driver_to_detect() {
		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Builder_Registry::reset();

		$divi_page  = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
			)
		);
		$plain_page = self::factory()->post->create_and_get( array( 'post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		$this->assertInstanceOf( 'Saddle_Divi_Driver', Saddle_Builder_Registry::for_post( $divi_page ) );
		$this->assertNull( Saddle_Builder_Registry::for_post( $plain_page ) );
	}

	public function test_drivers_extend_through_the_single_filter() {
		add_filter(
			'saddle_builder_drivers',
			static function ( $drivers ) {
				$drivers[] = new class() implements Saddle_Builder_Driver {
					public function slug() {
						return 'fake';
					}
					public function builder_name() {
						return 'Fake Builder';
					}
					public function detect( WP_Post $post ) {
						return false;
					}
					public function read_tree( $content ) {
						return array();
					}
					public function write_tree( array $tree ) {
						return '';
					}
					public function validate( array $tree ) {
						return true;
					}
					public function persist( WP_Post $post, array $tree ) {
						return 0;
					}
					public function author( array $nodes ) {
						return array();
					}
					public function schema( $type ) {
						return array();
					}
					public function lint_accessor( WP_Post $post = null ) {
						return null;
					}
					public function render_accessor() {
						return null;
					}
					public function echo_warnings( array $node, $address ) {
						return array();
					}
				};
				// Non-drivers are ignored, not fataled on.
				$drivers[] = 'not-a-driver';
				return $drivers;
			}
		);
		Saddle_Builder_Registry::reset();

		$this->assertInstanceOf( 'Saddle_Builder_Driver', Saddle_Builder_Registry::for_builder( 'Fake Builder' ) );
		$this->assertCount( 2, Saddle_Builder_Registry::all() );
	}

	/* -------- the Divi driver is pure delegation -------- */

	public function test_divi_driver_round_trips_the_tree_pipeline() {
		$driver = new Saddle_Divi_Driver();

		$tree = $driver->author(
			array(
				array(
					'type'     => 'divi/section',
					'children' => array(
						array(
							'type'     => 'divi/row',
							'children' => array(
								array(
									'type'     => 'divi/column',
									'children' => array(
										array( 'type' => 'divi/heading', 'fields' => array( 'title' => 'Hello' ) ),
									),
								),
							),
						),
					),
				),
			)
		);
		$this->assertNotWPError( $tree );
		$this->assertTrue( $driver->validate( $tree ) );

		$reread = $driver->read_tree( $driver->write_tree( $tree ) );
		$this->assertSame( 'divi/placeholder', $reread[0]['blockName'] );
		$this->assertSame(
			'Hello',
			$reread[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['title']['innerContent']['desktop']['value']
		);

		// Structure violations still reject — same validator, same behavior.
		$invalid = $driver->validate( array( Saddle_Divi_Tree::make_module( 'divi/column', array() ) ) );
		$this->assertWPError( $invalid );
	}

	public function test_divi_driver_supplies_the_quality_engine_hooks() {
		$driver = new Saddle_Divi_Driver();

		$accessor = $driver->lint_accessor();
		$this->assertInstanceOf( 'Saddle_Lint_Accessor', $accessor );
		$this->assertInstanceOf( 'Saddle_Divi_Lint_Accessor', $accessor );

		// Echo delegates to the module.json-backed checker; a module with no
		// module.json now yields the cannot-verify warning (a typo'd type
		// renders nothing — silence hid that).
		$warnings = $driver->echo_warnings( array( 'type' => 'thirdparty/unknown', 'fields' => array( 'x' => 'y' ) ), '0' );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'No module.json exists', $warnings[0] );
	}

	/* -------- free Saddle's lint wiring resolves through the registry -------- */

	public function test_the_registry_serves_the_divi_driver_accessor() {
		Saddle_Builder_Registry::reset();

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );

		$this->assertInstanceOf( 'Saddle_Divi_Lint_Accessor', Saddle_Builder_Registry::for_builder( 'Divi 5' )->lint_accessor( $post ) );

		// Builders nobody registered stay unresolved — lint-page then errors
		// with its actionable "no accessor installed" message.
		$this->assertNull( Saddle_Builder_Registry::for_builder( 'Elementor' ) );
	}
}
