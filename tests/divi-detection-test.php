<?php
/**
 * Divi 5 is found by its theme, whatever folder WordPress keeps it in.
 *
 * Reported 2026-11-09: Divi 5.16.0 in wp-content/themes/Divi-2. Saddle
 * matched the folder name only, so it reported the site's builder as
 * "classic" and offered the agent no Divi tool, in Saddle or Saddle Pro.
 *
 * @package Saddle
 */

class Saddle_Divi_Detection_Test extends WP_UnitTestCase {

	/**
	 * A theme root holding the fixture themes.
	 *
	 * @var string
	 */
	private static $root;

	/**
	 * The theme directories before this test registered its own.
	 *
	 * @var array
	 */
	private $directories;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$root = get_temp_dir() . 'saddle-divi-detection-themes';
		self::theme( 'Divi-2', 'Divi', '5.16.0' );
		self::theme( 'Extra-old', 'Extra', '4.27.4' );
		self::theme( 'agency-builder', 'Agency Builder', '5.16.0', true );
		self::theme( 'plain-classic', 'Plain Classic', '5.0.0' );
	}

	/**
	 * Writes a theme folder with a style.css header.
	 *
	 * @param string $folder   Folder name.
	 * @param string $name     Theme Name header.
	 * @param string $version  Version header.
	 * @param bool   $builder5 Whether it carries Divi 5's builder folder.
	 */
	private static function theme( $folder, $name, $version, $builder5 = false ) {
		$dir = self::$root . '/' . $folder;
		wp_mkdir_p( $builder5 ? $dir . '/includes/builder-5' : $dir );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: {$name}\nVersion: {$version}\n*/\n" );
		file_put_contents( $dir . '/index.php', "<?php\n" );
	}

	public function set_up() {
		parent::set_up();

		$this->directories = $GLOBALS['wp_theme_directories'];
		register_theme_directory( self::$root );
		search_theme_directories( true );
		wp_clean_themes_cache();
	}

	public function tear_down() {
		$GLOBALS['wp_theme_directories'] = $this->directories;
		search_theme_directories( true );
		wp_clean_themes_cache();

		parent::tear_down();
	}

	/**
	 * Makes a fixture folder the active template, as the option would.
	 *
	 * @param string $folder Folder name.
	 */
	private function use_template( $folder ) {
		add_filter(
			'template',
			static function () use ( $folder ) {
				return $folder;
			}
		);
	}

	public function test_divi_in_a_renamed_folder_is_divi_5_and_its_tools_are_offered() {
		$this->use_template( 'Divi-2' );

		$this->assertSame( '5.16.0', Saddle_Divi::version() );
		$this->assertTrue( Saddle_Divi::is_active() );
		$this->assertSame( '', Saddle_Integrations::absent_plugin( 'saddle/divi-get-page' ), 'The Divi tools are offered.' );
	}

	public function test_an_older_extra_in_a_renamed_folder_is_found_but_not_divi_5() {
		$this->use_template( 'Extra-old' );

		$this->assertSame( '4.27.4', Saddle_Divi::version() );
		$this->assertFalse( Saddle_Divi::is_active() );
	}

	public function test_a_renamed_white_label_divi_is_found_by_its_builder_folder() {
		$this->use_template( 'agency-builder' );

		$this->assertSame( '5.16.0', Saddle_Divi::version() );
		$this->assertTrue( Saddle_Divi::is_active() );
	}

	public function test_another_theme_at_version_5_is_not_divi() {
		$this->use_template( 'plain-classic' );

		$this->assertNull( Saddle_Divi::version() );
		$this->assertFalse( Saddle_Divi::is_active() );
		$this->assertSame( 'Divi 5', Saddle_Integrations::absent_plugin( 'saddle/divi-get-page' ) );
	}
}
