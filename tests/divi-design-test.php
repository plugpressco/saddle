<?php
/**
 * Divi design-system abilities (global colors + fonts).
 *
 * These read/write Divi's own runtime storage via GlobalData / et_*_option,
 * which only exist when Divi 5 is active. In this harness Divi is NOT active,
 * so the read/write round-trip is verified live on divi-dev (the done-gate).
 * What IS pinned here: registration + tier meta, input validation (runs before
 * the Divi dependency), the graceful "Divi unavailable" path, and the delete
 * gate / protected-id guard.
 *
 * @package Saddle
 */

require_once __DIR__ . '/divi-test-paths.php';

class Saddle_Divi_Design_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'admin' );
	}

	public function tear_down() {
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	public function test_design_read_abilities_register_read_only() {
		$reads = array(
			'saddle/divi-list-global-colors',
			'saddle/divi-get-global-fonts',
			'saddle/divi-list-variables',
			'saddle/divi-list-global-presets',
			'saddle/divi-get-global-preset',
		);
		foreach ( $reads as $name ) {
			$meta = $this->ability( $name )->get_meta();
			$this->assertSame( 'read', $meta['saddle']['tier'], "{$name} tier" );
			$this->assertTrue( $meta['annotations']['readonly'], "{$name} is read-only" );
		}
	}

	public function test_reads_report_unavailable_without_divi() {
		$this->assertSame( 'saddle_divi_api_unavailable', $this->ability( 'saddle/divi-list-global-colors' )->execute( array() )->get_error_code() );
		$this->assertSame( 'saddle_divi_api_unavailable', $this->ability( 'saddle/divi-get-global-fonts' )->execute( array() )->get_error_code() );
	}

	/* -------- design variables -------- */

	/**
	 * The tool told agents to write every variable as var(--<id>). For a
	 * font that never loads the webfont, and for a string, link or image
	 * the CSS form puts nothing in content: those take Divi's $variable
	 * token.
	 */
	public function test_each_variable_type_gets_the_reference_divi_reads() {
		$token = static function ( $kind, $id ) {
			return '$variable({"type":"' . $kind . '","value":{"name":"' . $id . '","settings":{}}})$';
		};
		$this->assertSame( $token( 'content', 'gvid-f1' ), Saddle_Divi_Design::reference( 'fonts', 'gvid-f1' ) );
		$this->assertSame( 'var(--gvid-n1)', Saddle_Divi_Design::reference( 'numbers', 'gvid-n1' ) );
		$this->assertSame( $token( 'color', 'gcid-c1' ), Saddle_Divi_Design::reference( 'colors', 'gcid-c1' ) );
		$this->assertSame( $token( 'gradient', 'gvid-g1' ), Saddle_Divi_Design::reference( 'gradients', 'gvid-g1' ) );
		foreach ( array( 'strings', 'links', 'images' ) as $type ) {
			$this->assertSame( $token( 'content', 'gvid-x' ), Saddle_Divi_Design::reference( $type, 'gvid-x' ), $type );
		}
		$this->assertSame( $token( 'content', '--et_global_heading_font' ), Saddle_Divi_Design::reference( 'fonts', '--et_global_heading_font' ), 'The customizer fonts keep their own id.' );
		$this->assertContains( 'fonts', Saddle_Divi_Design::VARIABLE_TYPES );

		$description = $this->ability( 'saddle/divi-list-variables' )->get_description();
		$this->assertStringContainsString( '$variable({"type":"content"', $description );
		$this->assertStringContainsString( 'never loads the webfont', $description );
	}

	/* -------- global presets -------- */

	/**
	 * Stored presets as GlobalPreset::get_data() returns them (Divi 5.10.1
	 * GlobalPreset::prepare_data()): module presets by module type, option
	 * group presets by group name.
	 */
	private function stored_presets() {
		return array(
			'module' => array(
				'divi/button' => array(
					'default' => 'mp-2',
					'items'   => array(
						'mp-1' => array(
							'id'    => 'mp-1',
							'name'  => 'Ghost',
							'attrs' => array( 'button' => array() ),
						),
						'mp-2' => array(
							'id'   => 'mp-2',
							'name' => 'Primary',
						),
					),
				),
			),
			'group'  => array(
				'divi/font' => array(
					'default' => '',
					'items'   => array(
						'gp-1' => array(
							'id'              => 'gp-1',
							'name'            => 'Eyebrow',
							'groupName'       => 'divi/font',
							'groupId'         => 'designTitleText',
							'moduleName'      => 'divi/heading',
							'primaryAttrName' => 'title',
							'attrs'           => array( 'title' => array( 'decoration' => array() ) ),
						),
					),
				),
			),
		);
	}

	public function test_presets_list_module_presets_by_default_and_group_presets_on_request() {
		$modules = Saddle_Divi_Design::presets_from( $this->stored_presets(), array() );
		$this->assertSame( 2, $modules['count'], 'kind "module" is the default: the answer is what it was, plus "kind".' );
		$this->assertSame(
			array(
				'kind'       => 'module',
				'module'     => 'divi/button',
				'id'         => 'mp-1',
				'name'       => 'Ghost',
				'is_default' => false,
			),
			$modules['presets'][0]
		);
		$this->assertTrue( $modules['presets'][1]['is_default'] );

		$groups = Saddle_Divi_Design::presets_from( $this->stored_presets(), array( 'kind' => 'group' ) );
		$this->assertSame(
			array(
				array(
					'kind'       => 'group',
					'id'         => 'gp-1',
					'name'       => 'Eyebrow',
					'group_name' => 'divi/font',
					'group_id'   => 'designTitleText',
					'module'     => 'divi/heading',
					'is_default' => false,
				),
			),
			$groups['presets']
		);

		$this->assertSame( 3, Saddle_Divi_Design::presets_from( $this->stored_presets(), array( 'kind' => 'all' ) )['count'] );
		$this->assertSame(
			0,
			Saddle_Divi_Design::presets_from(
				$this->stored_presets(),
				array(
					'kind'       => 'group',
					'group_name' => 'divi/button',
				)
			)['count']
		);
		$this->assertSame( 0, Saddle_Divi_Design::presets_from( $this->stored_presets(), array( 'module' => 'divi/text' ) )['count'] );
	}

	/**
	 * For option group presets, "module" means the presets that module can
	 * take: the groups its module.json has.
	 */
	public function test_a_module_filter_keeps_the_group_presets_it_can_take() {
		$dir = Saddle_Test_Divi_Paths::components_or_skip();
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		Saddle_Divi_Schema::index( true );

		$button = Saddle_Divi_Option_Groups::of_module( 'divi/button' );
		$this->assertSame( 'divi/button', $button['button.decoration.button'] );
		$this->assertSame( 'divi/spacing', $button['module.decoration.spacing'] );

		$data                         = $this->stored_presets();
		$data['group']['divi/button'] = array(
			'default' => 'gp-2',
			'items'   => array(
				'gp-2' => array(
					'name'       => 'Pill',
					'groupId'    => 'button.decoration.button',
					'moduleName' => 'divi/button',
				),
			),
		);
		$for_button = Saddle_Divi_Design::presets_from(
			$data,
			array(
				'kind'   => 'group',
				'module' => 'divi/button',
			)
		);
		$by_id = array_column( $for_button['presets'], null, 'id' );
		$this->assertArrayHasKey( 'gp-2', $by_id );
		$this->assertTrue( $by_id['gp-2']['is_default'] );
		$this->assertArrayHasKey( 'gp-1', $by_id, 'A button has a font group, so a font preset fits it too.' );
		$this->assertSame(
			0,
			Saddle_Divi_Design::presets_from(
				$data,
				array(
					'kind'   => 'group',
					'module' => 'saddletest/none',
				)
			)['count'],
			'A module SaddleWP cannot read takes no group preset.'
		);

		$schema = $this->ability( 'saddle/divi-get-module-schema' )->execute(
			array(
				'type'   => 'divi/button',
				'detail' => 'full',
			)
		);
		$this->assertSame( $button, $schema['option_groups'] );
		$compact = $this->ability( 'saddle/divi-get-module-schema' )->execute( array( 'type' => 'divi/button' ) );
		$this->assertArrayNotHasKey( 'option_groups', $compact, 'The compact answer stays small.' );

		remove_all_filters( 'saddle_divi_module_json_dirs' );
		Saddle_Divi_Schema::index( true );
	}

	public function test_get_preset_finds_either_kind_by_id() {
		$module = Saddle_Divi_Design::preset_from(
			$this->stored_presets(),
			array(
				'module'    => 'divi/button',
				'preset_id' => 'mp-1',
			)
		);
		$this->assertSame(
			array(
				'kind'   => 'module',
				'module' => 'divi/button',
				'id'     => 'mp-1',
				'name'   => 'Ghost',
				'attrs'  => array( 'button' => array() ),
			),
			$module
		);

		$group = Saddle_Divi_Design::preset_from(
			$this->stored_presets(),
			array(
				'kind'      => 'group',
				'preset_id' => 'gp-1',
			)
		);
		foreach ( array( 'kind', 'id', 'name', 'group_name', 'group_id', 'module', 'is_default', 'attrs' ) as $key ) {
			$this->assertArrayHasKey( $key, $group );
		}
		$this->assertSame( 'divi/font', $group['group_name'] );
		$this->assertSame(
			array(
				'groupPreset' => array(
					'designTitleText' => array(
						'presetId'  => array( 'gp-1' ),
						'groupName' => 'divi/font',
					),
				),
			),
			$group['use']
		);
		$this->assertSame( 'group', Saddle_Divi_Design::preset_from( $this->stored_presets(), array( 'preset_id' => 'gp-1' ) )['kind'], 'Without kind, an id is found in either.' );

		$this->assertWPError( Saddle_Divi_Design::preset_from( $this->stored_presets(), array( 'preset_id' => 'nope' ) ) );
		$this->assertWPError(
			Saddle_Divi_Design::preset_from(
				$this->stored_presets(),
				array(
					'kind'      => 'module',
					'preset_id' => 'gp-1',
				)
			),
			'kind narrows the search.'
		);
		$schema = $this->ability( 'saddle/divi-get-global-preset' )->get_input_schema();
		$this->assertSame( array( 'preset_id' ), $schema['required'], 'module is optional now; old calls that pass it still work.' );
	}

	public function test_apply_preset_replaces_by_default_and_stacks_on_append() {
		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_tier( 'write' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/button {"modulePreset":["a"]} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
			)
		);
		$apply = function ( $preset, $mode = null ) use ( $id ) {
			$input = array(
				'post_id'   => $id,
				'address'   => '0.0.0.0.0',
				'preset_id' => $preset,
			);
			if ( $mode ) {
				$input['mode'] = $mode;
			}
			$result = $this->ability( 'saddle/divi-apply-global-preset' )->execute( $input );
			$this->assertNotWPError( $result );
			$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $id )->post_content ), '0.0.0.0.0' );
			return $node['attrs']['modulePreset'];
		};

		$this->assertSame( array( 'a', 'b' ), $apply( 'b', 'append' ) );
		$this->assertSame( array( 'b', 'a' ), $apply( 'a', 'append' ), 'An id already in the stack moves to the end, where it wins.' );
		$this->assertSame( array( 'c' ), $apply( 'c' ), 'replace stays the default.' );
		remove_filter( 'saddle_divi_active', '__return_true' );
	}

	/* -------- GlobalData shape normalization -------- */

	/**
	 * Regression: on a real Divi install GlobalData/GlobalPreset getters return
	 * nested stdClass trees (raw JSON decode). A shallow (array) cast only fixes
	 * the top level, which made edit/delete-variable unable to find entries the
	 * Visual Builder wrote and let create-variable clobber an stdClass bucket.
	 * Found live on divi-dev 2026-07-07. deep_array() must flatten every level.
	 */
	public function test_css_var_never_doubles_leading_dashes() {
		// Divi's built-in font variables carry ids that already start with
		// dashes; the naive wrap produced var(----et_global_heading_font) —
		// invalid CSS served to agents as a token.
		$this->assertSame( 'var(--gcid-abc)', Saddle_Divi::css_var( 'gcid-abc' ) );
		$this->assertSame( 'var(--gvid-123)', Saddle_Divi::css_var( 'gvid-123' ) );
		$this->assertSame( 'var(--et_global_heading_font)', Saddle_Divi::css_var( '--et_global_heading_font' ) );
	}

	public function test_deep_array_normalizes_stdclass_trees() {
		// Shaped like GlobalData::get_global_variables() on a live site:
		// outer array, per-type buckets and entries as stdClass.
		$live = json_decode(
			wp_json_encode(
				array(
					'numbers' => array(
						'gvid-abc' => array(
							'label'  => 'Spacing',
							'value'  => '24px',
							'status' => 'active',
						),
					),
					'fonts'   => array(
						'--et_global_heading_font' => array(
							'label' => 'Heading',
							'value' => 'none',
						),
					),
				)
			)
		); // no assoc flag -> stdClass all the way down, like Divi.

		$this->assertIsObject( $live->numbers, 'Fixture must reproduce the stdClass bucket shape.' );

		$normalized = Saddle_Divi_Design::deep_array( (array) $live );

		$this->assertIsArray( $normalized['numbers'], 'Buckets must become arrays.' );
		$this->assertIsArray( $normalized['numbers']['gvid-abc'], 'Entries must become arrays.' );
		$this->assertSame( '24px', $normalized['numbers']['gvid-abc']['value'] );
		$this->assertTrue( isset( $normalized['numbers']['gvid-abc'] ), 'Entry lookup by id must work post-normalization.' );
	}

	public function test_deep_array_handles_junk_input() {
		$this->assertSame( array(), Saddle_Divi_Design::deep_array( null ) );
		$this->assertSame( array(), Saddle_Divi_Design::deep_array( 'not-a-structure' ) );
		$this->assertSame( array( 'a' => 1 ), Saddle_Divi_Design::deep_array( array( 'a' => 1 ) ) );
	}
}
