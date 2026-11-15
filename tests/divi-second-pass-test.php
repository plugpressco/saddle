<?php
/**
 * Two small rules found by running the tools on a real Divi site.
 *
 * @package Saddle
 */

class Saddle_Divi_Second_Pass_Test extends WP_UnitTestCase {

	public function test_findings_without_a_rule_sort_without_a_notice() {
		$a = array(
			'address' => '0.0.0',
			'source'  => 'echo',
		);
		$b = array(
			'address' => '0.0.0',
			'rule'    => 'heading-order',
		);

		$this->assertIsInt( Saddle_Lint::compare_addresses( $a, $b ) );
		$this->assertIsInt( Saddle_Lint::compare_addresses( $b, $a ) );
		$this->assertSame( 0, Saddle_Lint::compare_addresses( $a, $a ) );
	}

	public function test_internal_post_types_are_not_offered_to_loops() {
		register_post_type(
			'_saddle_internal',
			array(
				'public'             => false,
				'publicly_queryable' => true,
			)
		);
		register_post_type( 'saddle_story', array( 'public' => true ) );

		$choices = Saddle_Divi_Loop::sub_type_choices( 'post_types' );
		unregister_post_type( '_saddle_internal' );
		unregister_post_type( 'saddle_story' );

		$this->assertContains( 'saddle_story', $choices );
		$this->assertNotContains( '_saddle_internal', $choices );
	}
}
