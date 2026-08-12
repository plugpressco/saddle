<?php
/**
 * Site-editor reads — templates, parts, global styles, saved patterns.
 *
 * What must hold: an agent can see the whole site, not just one post; template
 * nodes come back in the SAME addressable shape get-blocks returns, so an
 * address means one thing everywhere; a classic theme is refused with a reason
 * rather than an empty list that reads as "this site has no header"; and every
 * one of these is a read that the read tier allows and a switched-off tool
 * refuses.
 *
 * @package Saddle
 */

class Saddle_Site_Editor_Test extends WP_UnitTestCase {

	private $admin;

	private $previous_theme;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'read' );

		// The harness symlinks wp-content/themes from the host WordPress, and
		// that install has no block theme. Without one, every template and
		// global-styles test skips — a suite that looks green while verifying
		// nothing. So register a second theme root holding a minimal block
		// theme fixture and switch to it for these tests only.
		$this->previous_theme = get_stylesheet();
		register_theme_directory( __DIR__ . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		wp_clean_themes_cache();

		if ( wp_get_theme( 'saddle-block-fixture' )->exists() ) {
			switch_theme( 'saddle-block-fixture' );
		}
	}

	public function tear_down() {
		if ( $this->previous_theme && get_stylesheet() !== $this->previous_theme ) {
			switch_theme( $this->previous_theme );
		}
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	/**
	 * Assert the fixture actually took. If it did not, skip loudly rather than
	 * silently passing a test that exercised nothing.
	 */
	private function require_block_theme() {
		if ( ! wp_is_block_theme() ) {
			$this->markTestSkipped( 'The block-theme fixture did not activate; template reads cannot be exercised.' );
		}
	}

	/* -------- registration -------- */

	public function test_all_four_abilities_register_as_reads() {
		foreach ( array( 'list-templates', 'get-template', 'get-global-styles', 'list-saved-patterns' ) as $short ) {
			$ability = wp_get_ability( 'saddle/' . $short );
			$this->assertNotNull( $ability, "saddle/{$short} must be registered." );

			$meta = $ability->get_meta();
			$this->assertTrue( $meta['annotations']['readonly'], "saddle/{$short} must be readonly." );
			$this->assertFalse( $meta['annotations']['destructive'], "saddle/{$short} must not be destructive." );
			$this->assertSame( 'read', $meta['saddle']['tier'], "saddle/{$short} must be read tier." );
		}
	}

	/* -------- templates -------- */

	public function test_list_templates_finds_parts_and_marks_customization() {
		$this->require_block_theme();

		$all = $this->run_ability( 'list-templates' );
		$this->assertNotWPError( $all );
		$this->assertNotEmpty( $all['templates'], 'A block theme must expose at least one template.' );

		foreach ( array( 'id', 'slug', 'title', 'type', 'source', 'customized' ) as $key ) {
			$this->assertArrayHasKey( $key, $all['templates'][0] );
		}

		// The type filter must actually filter, and parts must be reachable —
		// finding the header is the whole point of this ability.
		$parts = $this->run_ability( 'list-templates', array( 'type' => 'part' ) );
		$this->assertNotWPError( $parts );
		foreach ( $parts['templates'] as $row ) {
			$this->assertSame( 'part', $row['type'] );
		}

		$templates = $this->run_ability( 'list-templates', array( 'type' => 'template' ) );
		$this->assertNotWPError( $templates );
		foreach ( $templates['templates'] as $row ) {
			$this->assertSame( 'template', $row['type'] );
		}
	}

	public function test_get_template_returns_the_same_node_shape_as_get_blocks() {
		$this->require_block_theme();

		$list = $this->run_ability( 'list-templates', array( 'type' => 'template' ) );
		$this->assertNotEmpty( $list['templates'] );
		$first = $list['templates'][0];

		$template = $this->run_ability( 'get-template', array( 'id' => $first['id'] ) );
		$this->assertNotWPError( $template );
		$this->assertArrayHasKey( 'nodes', $template );

		if ( ! $template['nodes'] ) {
			$this->markTestSkipped( 'This theme\'s first template has no blocks to compare.' );
		}

		// The contract that makes a future set-template possible: a template
		// node is addressed exactly like a post node.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->",
			)
		);
		$blocks  = $this->run_ability( 'get-blocks', array( 'post_id' => $post_id ) );

		$this->assertSame(
			array_keys( $blocks['nodes'][0] ),
			array_keys( $template['nodes'][0] ),
			'Template nodes must carry the same keys as post nodes.'
		);
	}

	public function test_get_template_rejects_an_unknown_id_with_a_useful_error() {
		$this->require_block_theme();

		$missing = $this->run_ability( 'get-template', array( 'id' => 'nosuchtheme//nosuchtemplate' ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'saddle_not_found', $missing->get_error_code() );
	}

	/* -------- global styles -------- */

	public function test_get_global_styles_separates_the_owners_choices() {
		$this->require_block_theme();

		$styles = $this->run_ability( 'get-global-styles' );
		$this->assertNotWPError( $styles );

		foreach ( array( 'customized', 'colors', 'font_sizes', 'spacing', 'note', 'usage' ) as $key ) {
			$this->assertArrayHasKey( $key, $styles );
		}
		$this->assertIsBool( $styles['customized'] );
	}

	/* -------- patterns -------- */

	public function test_list_patterns_reports_sync_status() {
		$synced   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Saddle Synced Fixture',
				'post_status'  => 'publish',
				'post_content' => "<!-- wp:paragraph -->\n<p>Synced</p>\n<!-- /wp:paragraph -->",
			)
		);
		$unsynced = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Saddle Unsynced Fixture',
				'post_status'  => 'publish',
				'post_content' => "<!-- wp:paragraph -->\n<p>Unsynced</p>\n<!-- /wp:paragraph -->",
			)
		);
		update_post_meta( $unsynced, 'wp_pattern_sync_status', 'unsynced' );

		$list = $this->run_ability( 'list-saved-patterns' );
		$this->assertNotWPError( $list );

		$by_id = array();
		foreach ( $list['patterns'] as $row ) {
			$by_id[ $row['id'] ] = $row;
		}

		$this->assertArrayHasKey( $synced, $by_id );
		$this->assertArrayHasKey( $unsynced, $by_id );

		// Absence of the meta means synced — core stores nothing for those.
		$this->assertTrue( $by_id[ $synced ]['synced'] );
		$this->assertFalse( $by_id[ $unsynced ]['synced'] );
	}

	public function test_list_patterns_filters_by_search() {
		self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Zzzz Unique Saddle Fixture',
				'post_status' => 'publish',
			)
		);

		$hit = $this->run_ability( 'list-saved-patterns', array( 'search' => 'Zzzz Unique Saddle' ) );
		$this->assertNotWPError( $hit );
		$this->assertNotEmpty( $hit['patterns'] );

		$miss = $this->run_ability( 'list-saved-patterns', array( 'search' => 'nothingmatchesthisstring' ) );
		$this->assertNotWPError( $miss );
		$this->assertSame( array(), $miss['patterns'] );
	}

	/* -------- the gates -------- */

	public function test_reads_are_allowed_at_the_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		foreach ( array( 'list-templates', 'get-global-styles', 'list-saved-patterns' ) as $short ) {
			$this->assertTrue(
				wp_get_ability( 'saddle/' . $short )->check_permissions( array() ),
				"saddle/{$short} is a read and must be allowed at the read tier."
			);
		}
	}

	public function test_a_switched_off_tool_is_refused() {
		update_option( 'saddle_disabled_abilities', array( 'list-templates' ) );

		$this->assertFalse(
			wp_get_ability( 'saddle/list-templates' )->check_permissions( array() ),
			'An ability the owner switched off must refuse even at the right tier.'
		);

		delete_option( 'saddle_disabled_abilities' );
	}
}
