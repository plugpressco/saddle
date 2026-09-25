<?php
/**
 * Divi page-level layout settings (saddle-pro#51).
 *
 * The gap this closes: an agent could build every module on a landing page and
 * still not make it a landing page, because full width, hidden title and no
 * sidebar are post meta rather than modules — and free's generic `meta`
 * argument denies them, correctly, as unregistered protected keys.
 *
 * What matters here is the round trip. A tool that reports what it was asked to
 * do can only ever agree with itself; these assert the values actually landed
 * in the meta Divi reads, and that the reader maps them back.
 *
 * @package Saddle
 */

class Saddle_Divi_Page_Settings_Test extends WP_UnitTestCase {

	private $admin;
	private $page;

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		// The suite runs without the Divi theme installed; this is the filter
		// the detector exposes for exactly that.
		add_filter( 'saddle_divi_active', '__return_true' );

		$this->page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Pet Shop',
				'post_content' => '',
			)
		);
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		parent::tear_down();
	}

	private function apply_settings( array $input ) {
		return Saddle_Divi_Abilities::set_page_settings( $input );
	}

	public function test_it_registers_as_a_write_tier_non_destructive_tool() {
		$abilities = wp_get_abilities();
		$this->assertArrayHasKey( 'saddle/divi-set-page-settings', $abilities );

		$meta = $abilities['saddle/divi-set-page-settings']->get_meta();
		$this->assertSame( 'write', $meta['saddle']['tier'] );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
	}

	/**
	 * The whole point: a true landing page. Asserted against the meta Divi
	 * itself reads, not against the tool's own return value.
	 */
	public function test_a_landing_page_is_full_width_with_no_title() {
		$result = $this->apply_settings(
			array(
				'post_id'    => $this->page,
				'layout'     => 'full_width',
				'show_title' => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'et_full_width_page', get_post_meta( $this->page, '_et_pb_page_layout', true ) );
		$this->assertSame( 'off', get_post_meta( $this->page, '_et_pb_show_title', true ) );

		// And the tool reports it back in Saddle's vocabulary, which is what an
		// agent is told to read to confirm the settings stuck.
		$this->assertSame( 'full_width', $result['layout'] );
		$this->assertFalse( $result['show_title'] );
	}

	public function test_every_layout_maps_to_divis_own_value() {
		$expected = array(
			'full_width'    => 'et_full_width_page',
			'no_sidebar'    => 'et_no_sidebar',
			'right_sidebar' => 'et_right_sidebar',
			'left_sidebar'  => 'et_left_sidebar',
		);

		foreach ( $expected as $ours => $divis ) {
			$result = $this->apply_settings(
				array(
					'post_id' => $this->page,
					'layout'  => $ours,
				)
			);

			$this->assertNotWPError( $result );
			$this->assertSame( $divis, get_post_meta( $this->page, '_et_pb_page_layout', true ) );
			$this->assertSame( $ours, $result['layout'] );
		}
	}

	/**
	 * An unknown layout is refused rather than written. A typo that silently
	 * stores nonsense is a page that keeps its sidebar with no error to explain
	 * why.
	 */
	public function test_an_unknown_layout_is_refused_and_writes_nothing() {
		update_post_meta( $this->page, '_et_pb_page_layout', 'et_no_sidebar' );

		$result = $this->apply_settings(
			array(
				'post_id' => $this->page,
				'layout'  => 'et_full_width_page',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_layout', $result->get_error_code() );
		$this->assertSame( 'et_no_sidebar', get_post_meta( $this->page, '_et_pb_page_layout', true ) );
	}

	/**
	 * Omitting a field leaves it alone, so a second call that only changes the
	 * title does not silently reset the layout.
	 */
	public function test_omitted_fields_are_left_untouched() {
		$this->apply_settings(
			array(
				'post_id'    => $this->page,
				'layout'     => 'full_width',
				'show_title' => false,
			)
		);

		$this->apply_settings(
			array(
				'post_id'  => $this->page,
				'hide_nav' => true,
			)
		);

		$this->assertSame( 'et_full_width_page', get_post_meta( $this->page, '_et_pb_page_layout', true ) );
		$this->assertSame( 'off', get_post_meta( $this->page, '_et_pb_show_title', true ) );
		$this->assertSame( 'on', get_post_meta( $this->page, '_et_pb_post_hide_nav', true ) );
	}

	/**
	 * A page Divi has never been told about reports null rather than guessing a
	 * default the tool does not read.
	 */
	public function test_an_untouched_page_reports_no_layout_and_a_visible_title() {
		$settings = Saddle_Divi_Abilities::page_settings( $this->page );

		$this->assertNull( $settings['layout'] );
		$this->assertTrue( $settings['show_title'] );
		$this->assertFalse( $settings['hide_nav'] );
	}

	public function test_it_refuses_when_divi_is_not_active() {
		remove_filter( 'saddle_divi_active', '__return_true' );

		$result = $this->apply_settings(
			array(
				'post_id' => $this->page,
				'layout'  => 'full_width',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_no_divi', $result->get_error_code() );
	}

	public function test_it_refuses_a_post_the_user_cannot_edit() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->apply_settings(
			array(
				'post_id' => $this->page,
				'layout'  => 'full_width',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_denied', $result->get_error_code() );
	}

	public function test_it_refuses_a_page_owned_by_another_builder() {
		$other = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<p>Made in the block editor.</p>',
			)
		);

		$result = $this->apply_settings(
			array(
				'post_id' => $other,
				'layout'  => 'full_width',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_has_content', $result->get_error_code() );
	}
}
