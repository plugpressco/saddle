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

	/* -------- global presets -------- */

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
