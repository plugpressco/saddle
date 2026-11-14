<?php
/**
 * Lint findings that follow what Divi renders: the button fill switch by
 * Divi version, and heading levels from each module's own defaults.
 *
 * Rendered on Divi 5.10.1 (WordPress Playground, 2026-11-14): a button with
 * a background and no fill switch got the same fill as one with it, and a
 * Heading module with no level printed an h1.
 *
 * @package Saddle
 */

require_once __DIR__ . '/divi-test-paths.php';

class Saddle_Divi_Render_Defaults_Lint_Test extends WP_UnitTestCase {

	/**
	 * A theme root holding fixture Divi themes.
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
		self::$root = get_temp_dir() . 'saddle-divi-version-themes';
		foreach ( array(
			'Divi-5-9'  => '5.9.0',
			'Divi-5-10' => '5.10.1',
		) as $folder => $version ) {
			$dir = self::$root . '/' . $folder;
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/style.css', "/*\nTheme Name: Divi\nVersion: {$version}\n*/\n" );
			file_put_contents( $dir . '/index.php', "<?php\n" );
		}
	}

	public function set_up() {
		parent::set_up();
		$this->directories = $GLOBALS['wp_theme_directories'];
		register_theme_directory( self::$root );
		search_theme_directories( true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_all_filters( 'template' );
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Divi_Schema::index( true );
		$GLOBALS['wp_theme_directories'] = $this->directories;
		search_theme_directories( true );
		wp_clean_themes_cache();
		parent::tear_down();
	}

	private function use_template( $folder ) {
		add_filter(
			'template',
			static function () use ( $folder ) {
				return $folder;
			}
		);
	}

	private static function styled_button() {
		return Saddle_Divi_Tree::make_module(
			'divi/button',
			array( 'button' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#0a3d62' ) ) ) ) ) )
		);
	}

	private static function lint_ids( array $modules ) {
		$tree   = array( Saddle_Divi_Tree::make_module( 'divi/section', array(), '', array( Saddle_Divi_Tree::make_module( 'divi/row', array(), '', array( Saddle_Divi_Tree::make_module( 'divi/column', array(), '', $modules ) ) ) ) ) );
		$result = Saddle_Lint::run( $tree, new Saddle_Divi_Lint_Accessor() );
		return $result;
	}

	/* -------- the button fill switch -------- */

	public function test_the_fill_switch_matters_only_before_divi_5_10() {
		$this->assertTrue( Saddle_Divi::fill_switch_needed( '5.9.0' ) );
		$this->assertTrue( Saddle_Divi::fill_switch_needed( null ), 'An unknown version counts as older.' );
		$this->assertFalse( Saddle_Divi::fill_switch_needed( '5.10' ) );
		$this->assertFalse( Saddle_Divi::fill_switch_needed( '5.10.1' ) );
		$this->assertFalse( Saddle_Divi::fill_switch_needed( '5.16.0' ) );
	}

	public function test_ghost_button_is_not_reported_from_divi_5_10() {
		$accessor = new Saddle_Divi_Lint_Accessor();

		$this->use_template( 'Divi-5-10' );
		$this->assertSame( '5.10.1', Saddle_Divi::version() );
		$this->assertTrue( $accessor->button_is_filled( self::styled_button() ), 'Divi 5.10.1 prints the fill without the switch.' );

		remove_all_filters( 'template' );
		$this->use_template( 'Divi-5-9' );
		$this->assertSame( '5.9.0', Saddle_Divi::version() );
		$this->assertFalse( $accessor->button_is_filled( self::styled_button() ) );
	}

	public function test_the_schema_still_teaches_the_switch_softly() {
		$components = Saddle_Test_Divi_Paths::components_or_skip();
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $components ) {
				return array( $components );
			}
		);
		Saddle_Divi_Schema::index( true );

		$note = '';
		foreach ( Saddle_Divi_Schema::describe( 'divi/button' )['attributes'] as $attribute ) {
			if ( isset( $attribute['style_note'] ) ) {
				$note = $attribute['style_note'];
			}
		}
		$this->assertStringContainsString( 'before Divi 5.10', $note );
		$this->assertStringNotContainsString( 'ghost', $note );
	}

	/* -------- heading levels -------- */

	public function test_two_headings_with_no_level_are_two_h1s() {
		$heading = static function ( $text ) {
			return Saddle_Divi_Tree::make_module( 'divi/heading', array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => $text ) ) ) ) );
		};
		$h2      = Saddle_Divi_Tree::make_module(
			'divi/heading',
			array(
				'title' => array(
					'innerContent' => array( 'desktop' => array( 'value' => 'Section' ) ),
					'decoration'   => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'headingLevel' => 'h2' ) ) ) ) ),
				),
			)
		);

		$found = array();
		foreach ( self::lint_ids( array( $heading( 'Page title' ), $h2, $heading( 'Another' ) ) ) as $violation ) {
			if ( 'heading-order' === $violation['rule'] ) {
				$found[] = $violation;
			}
		}

		$this->assertCount( 1, $found );
		$this->assertSame( '0.0.0.2', $found[0]['address'] );
		$this->assertStringContainsString( 'More than one h1', $found[0]['message'] );
		$this->assertStringNotContainsString( '—', $found[0]['message'] . $found[0]['fix_hint'] );
	}

	public function test_each_module_reads_its_own_default_level() {
		$components = Saddle_Test_Divi_Paths::components_or_skip();
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $components ) {
				return array( $components );
			}
		);
		Saddle_Divi_Schema::index( true );
		$accessor = new Saddle_Divi_Lint_Accessor();
		$titled   = static function ( $type ) {
			return Saddle_Divi_Tree::make_module( $type, array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Text' ) ) ) ) );
		};

		$this->assertSame( 1, $accessor->heading_level( $titled( 'divi/heading' ) ) );
		$this->assertSame( 4, $accessor->heading_level( $titled( 'divi/blurb' ) ) );
		$this->assertSame( 2, $accessor->heading_level( $titled( 'divi/cta' ) ) );
		$this->assertNull( $accessor->heading_level( Saddle_Divi_Tree::make_module( 'divi/blurb' ) ), 'A blurb with no title prints no heading.' );
		$this->assertNull( $accessor->heading_level( $titled( 'divi/text' ) ) );
	}

	/* -------- classes loaded on first use -------- */

	public function test_every_class_loaded_on_first_use_is_in_its_file() {
		saddle_autoload_agent_classes( 'Saddle_No_Such_Class' );
		$statics = ( new ReflectionFunction( 'saddle_autoload_agent_classes' ) )->getStaticVariables();
		$this->assertGreaterThan( 30, count( $statics['files'] ) );
		foreach ( $statics['files'] as $class => $file ) {
			$this->assertFileExists( SADDLE_DIR . $file, $class );
			$this->assertMatchesRegularExpression( '/^(abstract |final )?class ' . $class . '\\b/m', (string) file_get_contents( SADDLE_DIR . $file ), $class );
			$this->assertTrue( class_exists( $class ), $class );
		}
		$this->assertArrayNotHasKey( 'Saddle_Divi_Loop_Repair', $statics['files'], 'The front end\'s loop repair is always loaded.' );
		$this->assertArrayNotHasKey( 'Saddle_Divi_Loop', $statics['files'] );
	}
}
