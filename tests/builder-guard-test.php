<?php
/**
 * The builder-content guard — a raw `content` write may never destroy a
 * page-builder layout.
 *
 * Free Saddle's positioning promise: protecting the layout is safety (never
 * paywalled); editing the layout is builder tooling. update-post/update-page
 * refuse a `content` overwrite on builder-built posts while every other
 * field stays editable.
 *
 * @package Saddle
 */

class Saddle_Builder_Guard_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function update( $id, array $fields ) {
		return wp_get_ability( 'saddle/update-page' )->execute(
			array_merge( array( 'id' => $id ), $fields )
		);
	}

	private function divi5_page() {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
			)
		);
	}

	/* -------- the guard -------- */

	public function test_content_overwrite_on_a_divi5_page_is_refused_and_nothing_changes() {
		$id     = $this->divi5_page();
		$before = get_post( $id )->post_content;

		$result = $this->update( $id, array( 'content' => '<p>New content</p>' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_builder_content', $result->get_error_code() );
		$this->assertSame( $before, get_post( $id )->post_content );
	}

	public function test_non_content_fields_stay_editable_on_builder_pages() {
		$id = $this->divi5_page();

		$result = $this->update( $id, array( 'title' => 'Renamed safely' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Renamed safely', get_post( $id )->post_title );
	}

	public function test_divi_classic_and_elementor_and_bakery_are_detected() {
		$classic = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_content' => '[et_pb_section fb_built="1"][/et_pb_section]' )
		);
		$this->assertSame(
			'saddle_builder_content',
			$this->update( $classic, array( 'content' => 'x' ) )->get_error_code()
		);

		$elementor = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '' ) );
		update_post_meta( $elementor, '_elementor_edit_mode', 'builder' );
		$result = $this->update( $elementor, array( 'content' => 'x' ) );
		$this->assertSame( 'saddle_builder_content', $result->get_error_code() );
		$this->assertStringContainsString( 'Elementor', $result->get_error_message() );

		$bakery = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_content' => '[vc_row][/vc_row]' )
		);
		$this->assertSame(
			'saddle_builder_content',
			$this->update( $bakery, array( 'content' => 'x' ) )->get_error_code()
		);
	}

	public function test_plain_pages_update_normally() {
		$id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_content' => '<p>Plain old page.</p>' )
		);

		$result = $this->update( $id, array( 'content' => '<p>Rewritten freely.</p>' ) );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Rewritten freely', get_post( $id )->post_content );
	}

	public function test_gutenberg_block_pages_are_not_builder_pages() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Blocks are fine.</p><!-- /wp:paragraph -->',
			)
		);

		$result = $this->update( $id, array( 'content' => '<!-- wp:paragraph --><p>Still fine.</p><!-- /wp:paragraph -->' ) );

		$this->assertNotWPError( $result, 'Native Gutenberg content must never trip the builder guard.' );
	}

	/* -------- the hint names only tools the agent can call -------- */

	public function test_divi_page_hint_names_the_divi_tools_when_divi_is_detected() {
		add_filter( 'saddle_divi_active', '__return_true' );
		$result = $this->update( $this->divi5_page(), array( 'content' => '<p>x</p>' ) );
		remove_filter( 'saddle_divi_active', '__return_true' );

		$this->assertStringContainsString( 'divi-set-page', $result->get_error_message() );
	}

	/**
	 * Reported 2026-11-09: Divi in a "Divi-2" folder was not detected, so the
	 * Divi tools were hidden, yet update-page told the agent to use
	 * divi-set-page. The agent could only report that the tool "isn't exposed".
	 */
	public function test_divi_page_hint_never_names_a_hidden_divi_tool() {
		add_filter( 'saddle_divi_active', '__return_false' );
		$content = $this->update( $this->divi5_page(), array( 'content' => '<p>x</p>' ) );
		$blocks  = wp_get_ability( 'saddle/set-blocks' )->execute(
			array(
				'post_id' => $this->divi5_page(),
				'nodes'   => array(
					array(
						'type'    => 'core/paragraph',
						'content' => 'x',
					),
				),
			)
		);
		remove_filter( 'saddle_divi_active', '__return_false' );

		foreach ( array( $content, $blocks ) as $result ) {
			$this->assertSame( 'saddle_builder_content', $result->get_error_code() );
			$this->assertStringNotContainsString( 'divi-', $result->get_error_message() );
			$this->assertStringContainsString( 'does not detect Divi 5', $result->get_error_message() );
		}
	}

	public function test_divi_page_hint_skips_a_divi_tool_the_owner_turned_off() {
		add_filter( 'saddle_divi_active', '__return_true' );
		Saddle_Capabilities::set_disabled_abilities( array( 'divi-set-page' ) );
		$result = $this->update( $this->divi5_page(), array( 'content' => '<p>x</p>' ) );
		Saddle_Capabilities::set_disabled_abilities( array() );
		remove_filter( 'saddle_divi_active', '__return_true' );

		$this->assertStringNotContainsString( 'divi-set-page', $result->get_error_message() );
		$this->assertStringContainsString( 'tell the site owner', $result->get_error_message() );
	}

	public function test_kill_switch_filter_disables_the_guard() {
		$id = $this->divi5_page();
		add_filter( 'saddle_protect_builder_content', '__return_false' );

		$result = $this->update( $id, array( 'content' => '<p>Forced.</p>' ) );

		remove_filter( 'saddle_protect_builder_content', '__return_false' );
		$this->assertNotWPError( $result );
	}
}
