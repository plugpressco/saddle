<?php
/**
 * Body and heading font paths on Divi modules with no default to read them from.
 *
 * @package Saddle
 */

/**
 * Divi nests a text body's typography one key deeper than other fonts
 * (content.decoration.bodyFont.body.font.desktop.value). With no default
 * render value to read that from, divi-get-style-schema answered
 * content.decoration.bodyFont.desktop.value: a write there saved, passed the
 * echo, and rendered nothing. Real Divi has 47 such attributes, the text
 * module's among them.
 */
class Saddle_Divi_Font_Paths_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	/**
	 * Divi 5's own module.json files, for the tests that must not trust a
	 * fixture. Skipped where they are not on disk (CI).
	 *
	 * @return string
	 */
	private static function divi_components() {
		$dir = getenv( 'SADDLE_TEST_DIVI_COMPONENTS' ) ?: '/Users/fahim/Workspace/wp/divi-dev/wp-content/themes/Divi/includes/builder-5/visual-builder/packages/module-library/src/components';
		return is_dir( $dir ) ? $dir : '';
	}

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$fixture_dir = get_temp_dir() . 'saddle-font-path-fixture-modules';
		$module_dir        = self::$fixture_dir . '/body-text';
		if ( ! is_dir( $module_dir ) ) {
			mkdir( $module_dir, 0755, true );
		}
		// A button with a plain "font" group and no default render file:
		// the shape of Divi's own button, which ships no font default.
		if ( ! is_dir( self::$fixture_dir . '/plain-button' ) ) {
			mkdir( self::$fixture_dir . '/plain-button', 0755, true );
		}
		file_put_contents(
			self::$fixture_dir . '/plain-button/module.json',
			wp_json_encode(
				array(
					'name'       => 'saddletest/plain-button',
					'title'      => 'Plain button',
					'category'   => 'module',
					'attributes' => array(
						'module' => array( 'type' => 'object' ),
						'button' => array(
							'type'        => 'object',
							'elementType' => 'button',
							'settings'    => array(
								'innerContent' => array( 'item' => array( 'subName' => 'text' ) ),
								'decoration'   => array( 'font' => array() ),
							),
						),
					),
				)
			)
		);
		$font = static function ( $component, array $groups ) {
			return array(
				'groupType' => 'group-item',
				'item'      => array(
					'component' => array(
						'name'  => $component,
						'type'  => 'group',
						'props' => array( 'groups' => array_fill_keys( $groups, array() ) ),
					),
				),
			);
		};
		file_put_contents(
			$module_dir . '/module.json',
			wp_json_encode(
				array(
					'name'       => 'saddletest/body-text',
					'title'      => 'Body text',
					'category'   => 'module',
					'attributes' => array(
						'module'  => array( 'type' => 'object' ),
						'content' => array(
							'type'        => 'object',
							'elementType' => 'content',
							'settings'    => array(
								'innerContent' => array( 'item' => array( 'label' => 'Body' ) ),
								'decoration'   => array(
									'bodyFont'    => $font( 'divi/font-body', array( 'body', 'link', 'ul' ) ),
									'headingFont' => $font( 'divi/font-header', array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) ),
								),
							),
						),
					),
				)
			)
		);
	}

	public function set_up() {
		parent::set_up();
		add_filter( 'saddle_divi_active', '__return_true' );
		$dir = self::$fixture_dir;
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	private function group( $name ) {
		foreach ( Saddle_Divi_Schema::style( 'saddletest/body-text' )['style_groups'] as $sg ) {
			if ( $name === $sg['group'] ) {
				return $sg;
			}
		}
		$this->fail( "No $name group." );
	}

	public function test_a_body_font_path_nests_under_its_first_subgroup() {
		$body = $this->group( 'bodyFont' );

		$this->assertSame( 'content.decoration.bodyFont.body.font.desktop.value', $body['path'] );
		$this->assertSame( array( 'body', 'link', 'ul' ), $body['subgroups'] );
	}

	public function test_a_heading_font_path_nests_under_a_level() {
		$heading = $this->group( 'headingFont' );

		$this->assertSame( 'content.decoration.headingFont.h1.font.desktop.value', $heading['path'] );
		$this->assertContains( 'h2', $heading['subgroups'] );
	}

	/**
	 * The echo judges nesting from the same paths, so the flat write that
	 * used to pass now says it will not render.
	 */
	public function test_the_echo_flags_the_flat_body_font_write() {
		$flat = Saddle_Divi_Echo::check_nodes(
			array(
				array(
					'type'  => 'saddletest/body-text',
					'attrs' => array( 'content.decoration.bodyFont.desktop.value' => array( 'color' => '#2f5d50' ) ),
				),
			)
		);
		$nested = Saddle_Divi_Echo::check_nodes(
			array(
				array(
					'type'  => 'saddletest/body-text',
					'attrs' => array( 'content.decoration.bodyFont.body.font.desktop.value' => array( 'color' => '#2f5d50' ) ),
				),
			)
		);

		$this->assertNotEmpty( $flat );
		$this->assertSame( array(), $nested );
	}

	/**
	 * Bug B5: the "font" group's path was read from Divi's default render
	 * attributes and fell back to the flat <group>.desktop.value when the
	 * module ships no font default. Divi's element style hands attr.font to
	 * FontStyle, which reads attr.font.font, on every module. The old test
	 * fixture carried a font default, which hid the bug.
	 */
	public function test_a_font_group_without_a_default_still_nests_under_font() {
		$paths = Saddle_Divi_Schema::group_paths( 'saddletest/plain-button' );

		$this->assertSame( 'button.decoration.font.font.desktop.value', $paths['button.font'] );
	}

	public function test_the_echo_flags_the_flat_button_font_write() {
		$flat = Saddle_Divi_Echo::check_nodes(
			array(
				array(
					'type'  => 'saddletest/plain-button',
					'attrs' => array( 'button.decoration.font.desktop.value' => array( 'color' => '#ffffff' ) ),
				),
			)
		);

		$this->assertNotEmpty( $flat, 'The flat font write renders nothing, so the echo must say so.' );
	}

	/**
	 * The same symptom on Divi's real button module.json, which has no font
	 * default: divi-get-style-schema said button.decoration.font.desktop.value.
	 */
	public function test_divis_own_button_gets_the_nested_font_path() {
		$dir = self::divi_components();
		if ( '' === $dir ) {
			$this->markTestSkipped( 'Divi 5 module.json files are not on this machine.' );
		}
		$defaults = json_decode( (string) file_get_contents( $dir . '/button/module-default-render-attributes.json' ), true );
		$this->assertArrayNotHasKey( 'font', (array) ( $defaults['button']['decoration'] ?? array() ), 'The real button ships no font default; that is what hid the bug.' );
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			},
			20
		);
		Saddle_Divi_Schema::index( true );

		$font = Saddle_Divi_Schema::style( 'divi/button', 'button.font' );

		$this->assertNotWPError( $font );
		$this->assertSame( 'button.decoration.font.font.desktop.value', $font['path'] );
		$this->assertSame( 'button.decoration.font.textShadow.desktop.value', $font['siblings']['textShadow']['path'] );
	}
}
