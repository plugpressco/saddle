<?php
/**
 * Site-editor writes (#172): templates, parts and patterns, DB-only.
 *
 * What must hold: an overwrite previews its changes and needs a single-use
 * token; the write lands as the owner's customisation in the database and the
 * theme's file is untouched; a new part and a new template are created
 * without a gate; a page subtree saves as a pattern the inserter lists; a
 * classic theme, the read tier and an account without edit_theme_options are
 * refused.
 *
 * @package Saddle
 */

class Saddle_Site_Editor_Writes_Test extends WP_UnitTestCase {

	private $previous_theme;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );

		$this->previous_theme = get_stylesheet();
		register_theme_directory( __DIR__ . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		wp_clean_themes_cache();
		switch_theme( 'saddle-block-fixture' );
	}

	public function tear_down() {
		switch_theme( $this->previous_theme );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	private function header_nodes( $title ) {
		return array(
			array(
				'type'     => 'core/group',
				'children' => array(
					array(
						'type'    => 'core/paragraph',
						'content' => $title,
					),
				),
			),
		);
	}

	public function test_overwriting_a_theme_part_is_gated_and_lands_in_the_database() {
		$file  = __DIR__ . '/fixtures/themes/saddle-block-fixture/parts/header.html';
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$input = array(
			'id'    => 'saddle-block-fixture//header',
			'type'  => 'part',
			'nodes' => $this->header_nodes( 'Welcome' ),
		);

		$preview = $this->run_ability( 'set-template', $input );
		$this->assertTrue( $preview['requires_confirmation'] );
		$this->assertStringContainsString( 'Welcome', implode( "\n", $preview['preview']['changes']['added'] ) );
		$this->assertContains( '<!-- wp:site-title /-->', $preview['preview']['changes']['removed'] );
		$this->assertSame( 'theme', get_block_template( 'saddle-block-fixture//header', 'wp_template_part' )->source, 'A preview must not write.' );

		$result = $this->run_ability( 'set-template', $input + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertNotWPError( $result );

		$part = get_block_template( 'saddle-block-fixture//header', 'wp_template_part' );
		$this->assertSame( 'custom', $part->source );
		$this->assertStringContainsString( 'Welcome', $part->content );
		$this->assertSame( $bytes, file_get_contents( $file ), 'The theme file must never change.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertWPError( $this->run_ability( 'set-template', $input + array( 'confirm_token' => $preview['confirm_token'] ) ), 'A used token must be refused.' );
	}

	public function test_a_token_for_one_markup_cannot_confirm_another() {
		$preview = $this->run_ability(
			'set-template',
			array(
				'id'    => 'saddle-block-fixture//header',
				'type'  => 'part',
				'nodes' => $this->header_nodes( 'Previewed' ),
			)
		);

		$result = $this->run_ability(
			'set-template',
			array(
				'id'            => 'saddle-block-fixture//header',
				'type'          => 'part',
				'nodes'         => $this->header_nodes( 'Swapped' ),
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$this->assertWPError( $result );
	}

	public function test_raw_markup_overwrites_a_template() {
		$markup  = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:post-content /-->';
		$input   = array(
			'id'      => 'saddle-block-fixture//index',
			'content' => $markup,
		);
		$preview = $this->run_ability( 'set-template', $input );
		$this->run_ability( 'set-template', $input + array( 'confirm_token' => $preview['confirm_token'] ) );

		$template = get_block_template( 'saddle-block-fixture//index', 'wp_template' );
		$this->assertSame( 'custom', $template->source );
		$this->assertStringNotContainsString( 'post-title', $template->content );
	}

	public function test_a_new_template_and_part_are_created_without_a_gate() {
		$template = $this->run_ability(
			'set-template',
			array(
				'id'      => 'saddle-block-fixture//single',
				'content' => '<!-- wp:post-title /--><!-- wp:post-content /-->',
			)
		);
		$this->assertNotWPError( $template );
		$this->assertArrayNotHasKey( 'requires_confirmation', $template );
		$this->assertNotNull( get_block_template( 'saddle-block-fixture//single', 'wp_template' ) );

		$part = $this->run_ability(
			'create-template-part',
			array(
				'slug'  => 'promo-strip',
				'title' => 'Promo strip',
				'area'  => 'footer',
				'nodes' => $this->header_nodes( 'Sale on now' ),
			)
		);
		$this->assertNotWPError( $part );
		$saved = get_block_template( 'saddle-block-fixture//promo-strip', 'wp_template_part' );
		$this->assertSame( 'footer', $saved->area );

		$again = $this->run_ability(
			'create-template-part',
			array(
				'slug'  => 'promo-strip',
				'title' => 'Promo strip',
				'nodes' => $this->header_nodes( 'Twice' ),
			)
		);
		$this->assertWPError( $again );
		$this->assertSame( 'saddle_exists', $again->get_error_code() );
	}

	public function test_a_page_subtree_saves_as_an_unsynced_pattern() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph --><!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Pricing</h2><!-- /wp:heading --></div><!-- /wp:group -->',
			)
		);

		$result = $this->run_ability(
			'save-pattern',
			array(
				'title'   => 'Pricing block',
				'post_id' => $page,
				'address' => '1',
			)
		);
		$this->assertNotWPError( $result );

		$pattern = get_post( $result['id'] );
		$this->assertSame( 'wp_block', $pattern->post_type );
		$this->assertStringContainsString( 'Pricing', $pattern->post_content );
		$this->assertStringNotContainsString( 'Intro', $pattern->post_content );
		$this->assertSame( 'unsynced', get_post_meta( $pattern->ID, 'wp_pattern_sync_status', true ) );

		$listed = $this->run_ability( 'list-saved-patterns' );
		$this->assertContains( 'Pricing block', wp_list_pluck( $listed['patterns'], 'title' ) );
	}

	public function test_refusals_classic_theme_tier_and_capability() {
		$input = array(
			'slug'  => 'x',
			'title' => 'X',
			'nodes' => $this->header_nodes( 'X' ),
		);

		Saddle_Capabilities::set_tier( 'read' );
		$this->assertWPError( $this->run_ability( 'create-template-part', $input ) );
		Saddle_Capabilities::set_tier( 'write' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertWPError( $this->run_ability( 'create-template-part', $input ), 'Editors lack edit_theme_options.' );
		$this->assertNotWPError(
			$this->run_ability(
				'save-pattern',
				array(
					'title' => 'Editors may save patterns',
					'nodes' => $this->header_nodes( 'Ok' ),
				)
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		switch_theme( $this->previous_theme );
		$classic = $this->run_ability( 'create-template-part', $input );
		$this->assertWPError( $classic );
		$this->assertSame( 'saddle_not_block_theme', $classic->get_error_code() );
	}
}
