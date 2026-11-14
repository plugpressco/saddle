<?php
/**
 * The Divi render accessor + the Divi verify findings.
 *
 * Pins the Pro half of the closed loop (saddle-pro#9): free Saddle's
 * saddle/render-node and saddle/verify-page work on Divi 5 pages through the
 * driver — effective styles resolve off canonical attrs via the SAME shared
 * trait lint reads with, structural violations and silently-ignored attr
 * paths surface as findings at real addresses, the judgment pass runs the
 * same rule set, and a clean canonical page produces zero false positives.
 *
 * @package Saddle
 */

class Saddle_Divi_Render_Verify_Test extends WP_UnitTestCase {

	private $admin;
	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Same minimal module.json contract fixture as the lint/echo suite:
		// the button attr proves decorations font+border ONLY — anything else
		// on it is provably ignored.
		self::$fixture_dir = get_temp_dir() . 'saddle-pro-render-fixture-modules';
		$module_dir        = self::$fixture_dir . '/fancy-button';
		if ( ! is_dir( $module_dir ) ) {
			mkdir( $module_dir, 0755, true );
		}
		file_put_contents(
			$module_dir . '/module.json',
			wp_json_encode(
				array(
					'name'       => 'saddletest/fancy-button',
					'title'      => 'Fancy Button',
					'category'   => 'module',
					'attributes' => array(
						'module' => array( 'type' => 'object' ),
						'button' => array(
							'type'        => 'object',
							'elementType' => 'button',
							'settings'    => array(
								'innerContent' => array(
									'groups' => array(
										'text' => array( 'item' => array( 'subName' => 'text' ) ),
									),
								),
								'decoration'   => array(
									'font'   => array(),
									'border' => array(),
								),
							),
						),
					),
				)
			)
		);
	}

	public static function seed_core_manifests( $fixture_dir ) {
		// Real sites always ship module.json for core divi/* modules; a
		// fixture index without them would trip the unknown-module rule on
		// every core module the tests legitimately use. Shapes mirror the
		// real manifests closely enough for the attrs these tests write.
		$manifests = array(
			'core-text'   => array(
				'name'       => 'divi/text',
				'title'      => 'Text',
				'category'   => 'module',
				'attributes' => array(
					'module' => array(
						'type'     => 'object',
						'settings' => array(
							'decoration' => array(
								'background' => array(),
								'font'       => array(),
								'sizing'     => array(),
								'spacing'    => array(),
							),
						),
					),
				),
			),
			'core-button' => array(
				'name'       => 'divi/button',
				'title'      => 'Button',
				'category'   => 'module',
				'attributes' => array(
					'module' => array( 'type' => 'object' ),
					'button' => array(
						'type'        => 'object',
						'elementType' => 'button',
						'settings'    => array(
							'innerContent' => array(
								'groups' => array(
									'text' => array( 'item' => array( 'subName' => 'text' ) ),
								),
							),
							'decoration'   => array(
								'background' => array(),
								'font'       => array(),
								'border'     => array(),
								'button'     => array(),
							),
						),
					),
				),
			),
		);
		foreach ( $manifests as $dir_name => $manifest ) {
			$dir = $fixture_dir . '/' . $dir_name;
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			file_put_contents( $dir . '/module.json', wp_json_encode( $manifest ) );
		}
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		add_filter( 'saddle_divi_active', '__return_true' );

		self::seed_core_manifests( self::$fixture_dir );
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
		remove_all_filters( 'saddle_divi_editable_post_types' );
		unregister_post_type( 'saddletest_course' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function divi_page( array $column_modules ) {
		$inner = '';
		foreach ( $column_modules as $module_markup ) {
			$inner .= $module_markup . "\n";
		}
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						$inner,
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);
	}

	private function module_markup( $type, array $attrs, $inner_html = '' ) {
		return sprintf( '<!-- wp:%1$s %2$s -->%3$s<!-- /wp:%1$s -->', $type, wp_json_encode( $attrs ), $inner_html );
	}

	private function run_ability( $name, array $input ) {
		$ability = wp_get_ability( 'saddle/' . $name );
		$this->assertNotNull( $ability, "saddle/{$name} must be registered." );
		return $ability->execute( $input );
	}

	/* -------- the render accessor -------- */

	public function test_effective_styles_resolve_canonical_divi_attrs() {
		$accessor = new Saddle_Divi_Render_Accessor();

		$node = Saddle_Divi_Tree::make_module(
			'divi/text',
			array(
				'module' => array(
					'decoration' => array(
						'background' => array( 'desktop' => array( 'value' => array( 'color' => '#112233' ) ) ),
						'font'       => array(
							'font' => array(
								'desktop' => array(
									'value' => array(
										'color' => '#ffffff',
										'size'  => '18px',
									),
								),
							),
						),
						'sizing'     => array( 'desktop' => array( 'value' => array( 'alignment' => 'center' ) ) ),
						'spacing'    => array( 'desktop' => array( 'value' => array( 'padding' => array( 'top' => '96px' ) ) ) ),
					),
				),
			)
		);

		$styles = $accessor->effective_styles( $node );
		$this->assertSame( '#112233', $styles['background'] );
		$this->assertSame( '#ffffff', $styles['color'] );
		$this->assertSame( '18px', $styles['fontSize'] );
		$this->assertSame( 'center', $styles['textAlign'] );
		$this->assertSame( array( 'top' => '96px' ), $styles['padding'] );

		// A bare node styles nothing — the map is empty, never guessed.
		$this->assertSame( array(), $accessor->effective_styles( Saddle_Divi_Tree::make_module( 'divi/text' ) ) );
	}

	public function test_render_node_ability_sees_a_divi_page_through_the_driver() {
		$id = $this->divi_page(
			array(
				$this->module_markup(
					'divi/text',
					array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#0a2540' ) ) ) ) ) ),
					'<p>Divi copy.</p>'
				),
			)
		);

		$result = $this->run_ability(
			'render-node',
			array(
				'post_id' => $id,
				'address' => '0.0.0.0',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'divi/text', $result['type'] );
		$this->assertSame( '#0a2540', $result['styles']['background'] );
		$this->assertStringContainsString( 'Divi copy.', $result['html'] );
		$this->assertSame( 'in-process', $result['fidelity'] );
	}

	public function test_render_node_degrades_cleanly_when_divi_renders_nothing() {
		$id = $this->divi_page(
			array( $this->module_markup( 'saddletest/fancy-button', array( 'module' => array() ) ) )
		);

		$result = $this->run_ability(
			'render-node',
			array(
				'post_id' => $id,
				'address' => '0.0.0.0',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertNull( $result['html'], 'An empty render is a labelled gap, not an empty string.' );
		$this->assertStringContainsString( 'get-preview-url', $result['html_note'] );
		$this->assertIsArray( $result['styles'], 'Effective styles stay available regardless.' );
	}

	/* -------- verify-page on Divi -------- */

	public function test_clean_canonical_divi_page_scores_100() {
		$id = $this->divi_page(
			array(
				$this->module_markup(
					'saddletest/fancy-button',
					array(
						'button' => array(
							'innerContent' => array( 'desktop' => array( 'value' => array( 'text' => 'Go' ) ) ),
							'decoration'   => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => '#ffffff' ) ) ) ) ),
						),
					)
				),
			)
		);

		$result = $this->run_ability( 'verify-page', array( 'post_id' => $id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Divi 5', $result['builder'] );
		$this->assertSame( array(), $result['skipped'], 'All three passes ran through the driver.' );
		$this->assertSame( 100, $result['score'], 'Canonical attrs the module.json proves must not false-positive.' );
		$this->assertSame( array(), $result['findings'] );
	}

	public function test_ignored_attr_and_ghost_button_surface_as_findings() {
		$id = $this->divi_page(
			array(
				// A FOREIGN decoration group (not in Divi's universal
				// ElementStyle vocabulary) is provably ignored. Universal
				// groups like background render regardless of declaration.
				$this->module_markup(
					'saddletest/fancy-button',
					array( 'button' => array( 'decoration' => array( 'glowEffect' => array( 'desktop' => array( 'value' => array( 'color' => '#2271b1' ) ) ) ) ) )
				),
				// A real divi/button styled without the enable switch renders
				// ghost — the judgment pass through the Divi lint accessor.
				$this->module_markup(
					'divi/button',
					array( 'button' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#2271b1' ) ) ) ) ) )
				),
			)
		);

		$result = $this->run_ability( 'verify-page', array( 'post_id' => $id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['counts']['ignored'], 'The proven-ignored decoration is an echo finding.' );
		$this->assertSame( 'echo', $result['findings'][0]['source'] );
		$this->assertSame( '0.0.0.0', $result['findings'][0]['address'] );

		$rules = wp_list_pluck( array_slice( $result['findings'], 1 ), 'rule' );
		$this->assertContains( 'ghost-button', $rules, 'The lint pass ran the same rule set on Divi.' );

		// 100 - 10 (echo) - 3 (ghost-button warn) = 87.
		$this->assertSame( 87, $result['score'] );

		// Verify honesty (free-side): an echo finding caps the grade at B —
		// "styling never took effect" is categorically not an A — and every
		// report carries the coverage caveat pointing at real pixels.
		$this->assertSame( 'B', $result['grade'] );
		$this->assertArrayHasKey( 'coverage', $result );
		$this->assertStringContainsString( 'get-preview-url', $result['coverage'] );
	}

	public function test_structural_violation_is_the_first_finding() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/section --><!-- /wp:divi/section -->'
					. '<!-- wp:paragraph --><p>Foreign block at the Divi root.</p><!-- /wp:paragraph -->',
			)
		);

		$result = $this->run_ability( 'verify-page', array( 'post_id' => $id ) );

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, $result['counts']['structural'] );
		$this->assertSame( 'structural', $result['findings'][0]['source'] );
		$this->assertSame( '1', $result['findings'][0]['address'] );
		$this->assertLessThanOrEqual( 75, $result['score'] );
	}

	/* -------- lint notes are advice -------- */

	/**
	 * Lint severity "note" (round 2) cost 3 points like a warning, so a page
	 * could drop a grade on taste alone. A note now costs nothing, is
	 * counted and returned apart, and never caps the grade.
	 */
	public function test_a_note_costs_no_points_and_is_returned_apart() {
		// The real advice rules need content this fixture cannot carry (an
		// emoji is stored as an entity on this test database), so a rule
		// that gives one note drives the real lint and verify engines.
		$id   = $this->divi_page( array( $this->clean_button() ) );
		$note = new Saddle_Test_Note_Rule();
		add_filter(
			'saddle_lint_rules',
			static function ( $rules ) use ( $note ) {
				$rules[] = $note;
				return $rules;
			}
		);

		$lint = $this->run_ability( 'lint-page', array( 'post_id' => $id ) );
		$this->assertSame( array( 'test-note' ), wp_list_pluck( $lint['violations'], 'rule' ) );
		$this->assertSame( 1, $lint['notes'] );
		$this->assertSame( 0, $lint['errors'] );

		$result = $this->run_ability( 'verify-page', array( 'post_id' => $id ) );
		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 'A', $result['grade'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( 1, $result['counts']['notes'] );
		$this->assertSame( 0, $result['counts']['warnings'] );
		remove_all_filters( 'saddle_lint_rules' );
		$this->assertSame( 'test-note', $result['notes'][0]['rule'] );
		$this->assertSame( 'note', $result['notes'][0]['severity'] );
		$this->assertStringContainsString( '"notes"', $result['note'] );
		$this->assertStringContainsString( 'costs no points', wp_get_ability( 'saddle/verify-page' )->get_description() );
		$this->assertStringContainsString( 'Severity "note"', wp_get_ability( 'saddle/lint-page' )->get_description() );
	}

	/* -------- Divi pages of other post types -------- */

	/**
	 * lint-page, verify-page, render-node, get-preview-url and
	 * divi-get-rendered-page checked Saddle_Post_Types::content_types(), so a
	 * Divi 5 page of a type the Divi tools edit but the content tools do not
	 * list (a builder-only type) was "not found" in the very tools that check
	 * the build.
	 */
	public function test_the_check_tools_open_a_divi_page_of_another_post_type() {
		$id = $this->builder_only_page( '<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column -->' . $this->clean_button() . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->' );
		$this->assertNotContains( 'saddletest_course', Saddle_Post_Types::content_types(), 'The fixture type is outside the content tools.' );

		$this->assertNotWPError( $this->run_ability( 'lint-page', array( 'post_id' => $id ) ) );
		$verify = $this->run_ability( 'verify-page', array( 'post_id' => $id ) );
		$this->assertNotWPError( $verify );
		$this->assertSame( 100, $verify['score'], wp_json_encode( $verify['findings'] ) );
		$this->assertNotWPError( $this->run_ability( 'render-node', array( 'post_id' => $id ) ) );
		$preview = $this->run_ability( 'get-preview-url', array( 'post_id' => $id ) );
		$this->assertNotWPError( $preview );
		$this->assertStringContainsString( 'p=' . $id, $preview['url'] );

		add_filter( 'pre_http_request', array( $this, 'answer_with_a_page' ), 10, 3 );
		$rendered = $this->run_ability( 'divi-get-rendered-page', array( 'post_id' => $id ) );
		remove_filter( 'pre_http_request', array( $this, 'answer_with_a_page' ), 10 );
		$this->assertNotWPError( $rendered );
		$this->assertStringContainsString( 'Course outline', $rendered['html'] );
	}

	public function test_the_same_access_checks_still_apply() {
		$id = $this->builder_only_page( '<!-- wp:divi/section --><!-- /wp:divi/section -->' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( 'lint-page', 'verify-page', 'render-node', 'get-preview-url', 'divi-get-rendered-page' ) as $tool ) {
			$result = $this->run_ability( $tool, array( 'post_id' => $id ) );
			$this->assertWPError( $result, $tool );
			$this->assertSame( 'saddle_forbidden', $result->get_error_code(), "{$tool}: the draft stays closed to an account that cannot edit it." );
		}
	}

	public function test_only_a_divi_5_page_of_that_type_is_opened() {
		$plain = $this->builder_only_page( '<p>Not a Divi page.</p>' );
		$this->assertSame( 'saddle_not_found', $this->run_ability( 'verify-page', array( 'post_id' => $plain ) )->get_error_code() );

		// Without Divi, the tools accept exactly the content types, as before.
		$divi = $this->builder_only_page( '<!-- wp:divi/section --><!-- /wp:divi/section -->' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		add_filter( 'saddle_divi_active', '__return_false' );
		$this->assertSame( Saddle_Post_Types::content_types(), Saddle_Verify::page_types( $divi ) );
		$this->assertSame( 'saddle_not_found', $this->run_ability( 'lint-page', array( 'post_id' => $divi ) )->get_error_code() );
		remove_filter( 'saddle_divi_active', '__return_false' );
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	/**
	 * The module the clean page above scores 100 with.
	 */
	private function clean_button() {
		return $this->module_markup(
			'saddletest/fancy-button',
			array(
				'button' => array(
					'innerContent' => array( 'desktop' => array( 'value' => array( 'text' => 'Go' ) ) ),
					'decoration'   => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'color' => '#ffffff' ) ) ) ) ),
				),
			)
		);
	}

	/**
	 * A draft of a type Divi's builder edits but WordPress lists nowhere
	 * (no admin screen, not public), added through the Divi tools' filter.
	 */
	private function builder_only_page( $content ) {
		register_post_type(
			'saddletest_course',
			array(
				'public'  => false,
				'show_ui' => false,
			)
		);
		add_filter(
			'saddle_divi_editable_post_types',
			static function ( $types ) {
				$types[] = 'saddletest_course';
				return $types;
			}
		);
		return self::factory()->post->create(
			array(
				'post_type'    => 'saddletest_course',
				'post_status'  => 'draft',
				'post_author'  => $this->admin,
				'post_content' => $content,
			)
		);
	}

	public function answer_with_a_page() {
		return array(
			'headers'  => array(),
			'body'     => '<html><body><div class="et-l et-l--post"><div class="et_pb_text"><p>Course outline</p></div></div></body></html>',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}

/**
 * A lint rule that gives one note, at the first node.
 */
class Saddle_Test_Note_Rule extends Saddle_Lint_Rule {

	public function id() {
		return 'test-note';
	}

	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		return $nodes ? array( $this->violation( $nodes[0]['address'], Saddle_Lint::SEVERITY_NOTE, 'Advice.', 'Weigh it.' ) ) : array();
	}
}
