<?php
/**
 * Smoke test: proves the WordPress test harness boots and Saddle loaded.
 *
 * @package Saddle
 */

class Saddle_Smoke_Test extends WP_UnitTestCase {

	public function test_wordpress_is_loaded() {
		$this->assertTrue( function_exists( 'wp_insert_post' ) );
		$this->assertTrue( defined( 'ABSPATH' ) );
	}

	public function test_saddle_classes_loaded() {
		$this->assertTrue( class_exists( 'Saddle_Capabilities' ) );
		$this->assertTrue( class_exists( 'Saddle_Approval' ) );
		$this->assertTrue( class_exists( 'Saddle_Log' ) );
	}
}
