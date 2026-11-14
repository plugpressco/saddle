<?php
/**
 * A loop is refused on a module that does not offer one.
 *
 * Divi 5.10.1 offers a loop on a module when its module.json declares
 * attributes.module.settings.advanced.loop (80 core modules do, 30 do not).
 * Divi's front end still repeats any module with a loop turned on, but the
 * builder shows no loop setting on the other 30, so nobody could see or
 * turn off a loop written there.
 *
 * @package Saddle
 */

class Saddle_Divi_Loop_Support_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-loop-support-fixture-modules';

		$modules = array(
			'section'   => array( 'divi/section', true ),
			'row'       => array( 'divi/row', true ),
			'column'    => array( 'divi/column', true ),
			'card'      => array( 'saddletest/card', true ),
			'own-query' => array( 'saddletest/own-query', false ),
		);
		foreach ( $modules as $dir => $module ) {
			$path = self::$fixture_dir . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				mkdir( $path, 0755, true );
			}
			$advanced = $module[1] ? array( 'loop' => (object) array() ) : array( 'html' => (object) array() );
			file_put_contents(
				$path . '/module.json',
				wp_json_encode(
					array(
						'name'       => $module[0],
						'title'      => $dir,
						'category'   => 'module',
						'attributes' => array(
							'module' => array(
								'type'     => 'object',
								'settings' => array( 'advanced' => $advanced ),
							),
						),
					)
				)
			);
		}
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		add_filter( 'saddle_divi_active', '__return_true' );
		$this->use_dirs( array( self::$fixture_dir ) );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	private function use_dirs( array $dirs ) {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dirs ) {
				return $dirs;
			}
		);
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
	}

	private function page( $module ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:' . $module . ' --><!-- /wp:' . $module . ' -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);
	}

	private function enable( $post_id ) {
		return wp_get_ability( 'saddle/divi-enable-loop' )->execute( array( 'post_id' => $post_id, 'address' => '0.0.0.0' ) );
	}

	public function test_loop_on_a_module_that_cannot_loop_is_refused_and_names_the_containers_that_can() {
		$id     = $this->page( 'saddletest/own-query' );
		$before = get_post( $id )->post_content;
		$result = $this->enable( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_loop_not_supported', $result->get_error_code() );
		$this->assertStringContainsString( '0.0.0 (divi/column)', $result->get_error_message() );
		$this->assertStringContainsString( '0.0 (divi/row)', $result->get_error_message() );
		$this->assertStringContainsString( '0 (divi/section)', $result->get_error_message() );
		$this->assertSame( $before, get_post( $id )->post_content, 'A refused loop writes nothing.' );
	}

	public function test_loop_on_a_module_that_offers_one_has_no_warning() {
		$result = $this->enable( $this->page( 'saddletest/card' ) );
		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'warnings', $result );
	}

	public function test_loop_on_a_module_without_module_json_is_allowed_with_a_warning() {
		$result = $this->enable( $this->page( 'acme/unknown-thing' ) );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'acme/unknown-thing', $result['warnings'][0] );
	}

	/**
	 * The same check against Divi's own module.json files, where a local
	 * Divi 5 copy exists (skipped elsewhere).
	 */
	public function test_real_divi_module_json_decides_which_modules_loop() {
		$components = '/Users/fahim/Workspace/wp/divi-dev/wp-content/themes/Divi/includes/builder-5/visual-builder/packages/module-library/src/components';
		if ( ! is_dir( $components . '/blog' ) ) {
			$this->markTestSkipped( 'No local Divi 5 source.' );
		}
		$this->use_dirs( array( $components ) );

		$this->assertFalse( Saddle_Divi_Loop::supports_loop( 'divi/blog' ) );
		$this->assertFalse( Saddle_Divi_Loop::supports_loop( 'divi/post-nav' ) );
		$this->assertTrue( Saddle_Divi_Loop::supports_loop( 'divi/blurb' ) );
		$this->assertTrue( Saddle_Divi_Loop::supports_loop( 'divi/column' ) );
		$this->assertTrue( Saddle_Divi_Loop::supports_loop( 'divi/group' ) );

		$blog = $this->enable( $this->page( 'divi/blog' ) );
		$this->assertWPError( $blog );
		$this->assertStringContainsString( '0.0.0 (divi/column)', $blog->get_error_message() );
	}
}
