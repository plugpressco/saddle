<?php
/**
 * Divi ability registration + execution through free Saddle's safety model.
 *
 * The point pinned down here: Pro abilities are ordinary `saddle/` abilities —
 * they appear in the shared registry, run behind Saddle_Capabilities (tier +
 * pause + per-ability toggle), and behave correctly on non-Divi content.
 *
 * @package Saddle
 */

class Saddle_Divi_Abilities_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function test_divi_abilities_register_into_the_saddle_namespace() {
		$abilities = wp_get_abilities();

		foreach ( array( 'saddle/divi-check-setup', 'saddle/divi-list-modules', 'saddle/divi-get-page' ) as $name ) {
			$this->assertArrayHasKey( $name, $abilities, "{$name} must be registered." );
		}

		$check = $abilities['saddle/divi-check-setup'];
		$meta  = $check->get_meta();
		$this->assertSame( 'read', $meta['saddle']['tier'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
	}

	public function test_check_setup_reports_no_divi_on_a_plain_theme() {
		$result = wp_get_ability( 'saddle/divi-check-setup' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['divi_active'] );
		$this->assertArrayHasKey( 'note', $result );
	}

	public function test_list_modules_returns_the_catalog() {
		$result = wp_get_ability( 'saddle/divi-list-modules' )->execute( array() );

		$this->assertNotWPError( $result );
		$types = wp_list_pluck( $result['modules'], 'type' );
		$this->assertContains( 'divi/section', $types );
		$this->assertContains( 'divi/text', $types );
	}

	public function test_get_page_refuses_a_non_divi_post() {
		$post_id = self::factory()->post->create( array( 'post_content' => '<p>Plain content</p>' ) );

		$result = wp_get_ability( 'saddle/divi-get-page' )->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_divi5', $result->get_error_code() );
	}

	public function test_get_page_refuses_a_divi4_shortcode_post() {
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[et_pb_section fb_built="1"][/et_pb_section]' )
		);

		$result = wp_get_ability( 'saddle/divi-get-page' )->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_divi5', $result->get_error_code() );
	}

	public function test_get_page_returns_the_addressable_tree_for_divi5_content() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:divi/text --><p>Hi</p><!-- /wp:divi/text -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);

		$result = wp_get_ability( 'saddle/divi-get-page' )->execute( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['tree_valid'] );
		$this->assertSame(
			array( '0', '0.0', '0.0.0', '0.0.0.0' ),
			wp_list_pluck( $result['nodes'], 'address' )
		);
	}

	public function test_pro_abilities_respect_the_pause_switch() {
		Saddle_Capabilities::set_paused( true );

		$ability = wp_get_ability( 'saddle/divi-list-modules' );
		$this->assertFalse( $ability->check_permissions( array() ), 'Paused must deny Pro abilities too.' );

		Saddle_Capabilities::set_paused( false );
		$this->assertTrue( $ability->check_permissions( array() ), 'Resume must restore access.' );
	}
}
