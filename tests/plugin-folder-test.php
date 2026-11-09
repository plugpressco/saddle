<?php
/**
 * A renamed Saddle folder still satisfies the add-ons' `Requires Plugins: saddle`.
 *
 * Symptom (#405): Saddle installed as `saddle-2` runs, but WordPress refuses
 * to activate Saddle Pro, Saddle Analytics or Saddle Rank, saying a required
 * plugin is missing, because WordPress matches the slug to folder names.
 *
 * @package Saddle
 */

class Saddle_Plugin_Folder_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'wp_plugin_dependencies_slug' );
		Saddle_Plugin_Folder::register();
		parent::tear_down();
	}

	public function test_a_renamed_folder_answers_the_saddle_requirement() {
		$this->assertSame( 'saddle-2', Saddle_Plugin_Folder::slug_for( 'saddle', 'saddle-2' ) );
	}

	public function test_the_usual_folder_and_other_slugs_are_left_alone() {
		$this->assertSame( 'saddle', Saddle_Plugin_Folder::slug_for( 'saddle', 'saddle' ) );
		$this->assertSame( 'saddle', Saddle_Plugin_Folder::slug_for( 'saddle', '.' ) );
		$this->assertSame( 'saddle-pro', Saddle_Plugin_Folder::slug_for( 'saddle-pro', 'saddle-2' ) );
		$this->assertSame( 'woocommerce', Saddle_Plugin_Folder::slug_for( 'woocommerce', 'saddle-2' ) );
	}

	public function test_saddle_hooks_the_requirement_filter_while_it_runs() {
		$this->assertSame( 10, has_filter( 'wp_plugin_dependencies_slug', array( 'Saddle_Plugin_Folder', 'dependency_slug' ) ) );
	}

	/**
	 * Pins the core behaviour the fix rests on: WordPress passes each slug of
	 * a Requires Plugins header through the filter, then keeps only slug-shaped
	 * results. A folder like "saddle 2" drops the requirement, which is right
	 * while Saddle is active.
	 */
	public function test_wordpress_reads_the_answer_when_it_parses_requires_plugins() {
		$sanitize = new ReflectionMethod( 'WP_Plugin_Dependencies', 'sanitize_dependency_slugs' );
		$sanitize->setAccessible( true );

		foreach ( array(
			'saddle-2' => array( 'saddle-2', 'woocommerce' ),
			'saddle 2' => array( 'woocommerce' ),
			'saddle'   => array( 'saddle', 'woocommerce' ),
		) as $folder => $expected ) {
			remove_all_filters( 'wp_plugin_dependencies_slug' );
			add_filter(
				'wp_plugin_dependencies_slug',
				static function ( $slug ) use ( $folder ) {
					return Saddle_Plugin_Folder::slug_for( $slug, $folder );
				}
			);
			$this->assertSame( $expected, $sanitize->invoke( null, 'saddle, woocommerce' ), "Saddle in {$folder}" );
		}
	}
}
