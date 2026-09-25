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
}
