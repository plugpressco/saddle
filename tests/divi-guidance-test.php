<?php
/**
 * What teaches an agent to build a Divi 5 page: the guidance that leads the
 * context on a Divi site, the skills index order, and the divi-build-page
 * playbook itself.
 *
 * On 2026-11-09 a page built exactly as the old playbook taught scored
 * 97 / A and looked like default Divi, and on a Divi site the block
 * guidance came first in the context and steered agents to the block tools.
 *
 * @package Saddle
 */

require_once __DIR__ . '/divi-test-paths.php';

class Saddle_Divi_Guidance_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		add_filter( 'saddle_native_builders', array( $this, 'no_foreign_builder' ) );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_active' );
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_filter( 'saddle_native_builders', array( $this, 'no_foreign_builder' ) );
		delete_option( Saddle_Capabilities::OPTION );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	/**
	 * Another test may define a foreign builder's constant for the whole
	 * run, which withholds the block playbooks; declare every builder native
	 * so they are there to check.
	 */
	public function no_foreign_builder( $native ) {
		return array_merge( (array) $native, array( 'Elementor', 'Beaver Builder', 'Bricks', 'WPBakery', 'Oxygen', 'Breakdance' ) );
	}

	private function divi( $on ) {
		remove_all_filters( 'saddle_divi_active' );
		add_filter( 'saddle_divi_active', $on ? '__return_true' : '__return_false' );
	}

	private function body() {
		foreach ( apply_filters( 'saddle_builtin_skills', array() ) as $skill ) {
			if ( 'divi-build-page' === $skill['name'] ) {
				return $skill['body'];
			}
		}
		$this->fail( 'The divi-build-page skill must be bundled while Divi is active.' );
	}

	/* -------- the context -------- */

	public function test_a_divi_site_leads_with_the_divi_guidance() {
		$this->divi( true );
		$context = Saddle_Context::system_context();

		$lead   = strpos( $context, "# Building pages with Divi 5\n" );
		$blocks = strpos( $context, '# Pages not built with Divi' );
		$this->assertNotFalse( $lead );
		$this->assertNotFalse( $blocks );
		$this->assertLessThan( $blocks, $lead, 'The Divi guidance comes before the block guidance.' );
		$this->assertLessThan( strpos( $context, '# What "designed" means' ), $lead );
		$this->assertLessThan( $blocks, strpos( $context, 'get-skill name=divi-build-page' ) );
		$this->assertSame( 1, substr_count( $context, '# Building pages with Divi 5' ), 'Placed once, never appended again at the end.' );
		$this->assertStringNotContainsString( '# Designing pages with blocks', $context );
		$this->assertStringContainsString( 'module.advanced.htmlAttributes', $context );
		$this->assertStringContainsString( '"phoneWide"', $context );
	}

	/**
	 * Pinned: a site without Divi gets exactly the block section it had.
	 */
	public function test_a_site_without_divi_keeps_its_block_section() {
		$this->divi( false );
		$context = Saddle_Context::system_context();

		$this->assertStringNotContainsString( '# Building pages with Divi 5', $context );
		$this->assertStringNotContainsString( '# Pages not built with Divi', $context );
		$this->assertStringContainsString(
			"# Designing pages with blocks\n\n- ORIENT FIRST: call saddle/context-bundle once per session.",
			$context
		);
		$this->assertStringContainsString( "- A write call returning success is not evidence the page is right: run saddle/verify-page, fix what it reports, then open saddle/get-preview-url and look. The score is server-side only.\n\n# What \"designed\" means, in numbers", $context );
		$lines = array( '# Designing pages with blocks', '', '- a line' );
		$this->assertSame( $lines, apply_filters( 'saddle_context_design_section', $lines, 'write' ) );
	}

	public function test_the_filter_alone_still_gets_the_divi_section() {
		$this->divi( true );
		$appended = apply_filters( 'saddle_system_context', '', 'read' );
		$this->assertStringContainsString( '# Building pages with Divi 5', $appended );
		$this->assertStringContainsString( 'read-only', $appended );
	}

	/* -------- the skills index -------- */

	public function test_the_divi_playbook_leads_the_index_on_a_divi_site() {
		$this->divi( true );
		$skills = apply_filters( 'saddle_builtin_skills', array() );
		$this->assertSame( 'divi-build-page', $skills[0]['name'] );
		$by_name = array_column( $skills, null, 'name' );
		$this->assertStringContainsString( 'not built with Divi', $by_name['build-page']['when_to_use'] );
		$this->assertStringContainsString( 'not built with Divi', $by_name['fix-page']['when_to_use'] );

		$index = Saddle_Context::system_context();
		$this->assertLessThan( strpos( $index, '- build-page:' ), strpos( $index, '- divi-build-page:' ) );
	}

	public function test_a_site_without_divi_keeps_its_index() {
		$this->divi( false );
		$by_name = array_column( apply_filters( 'saddle_builtin_skills', array() ), null, 'name' );
		$this->assertArrayNotHasKey( 'divi-build-page', $by_name );
		$this->assertSame( 'creating, rebuilding, or restyling any page or post', $by_name['build-page']['when_to_use'] );
	}

	/* -------- the playbook -------- */

	public function test_the_playbook_teaches_in_order_of_importance() {
		$this->divi( true );
		$body = $this->body();

		preg_match_all( '/^## (.+)$/m', $body, $m );
		$headings = $m[1];
		$order    = array( 'The workflow, in order', 'Before you build', 'Finding a module', 'The node format', 'Styling: where silent failures live', 'Responsive pass (its own step, after the desktop build)', 'Check before you call it done: the loop IS the job' );
		$at       = array();
		foreach ( $order as $heading ) {
			$this->assertContains( $heading, $headings );
			$at[] = array_search( $heading, $headings, true );
		}
		$sorted = $at;
		sort( $sorted );
		$this->assertSame( $sorted, $at, 'Sections in this order.' );

		$this->assertLessThanOrEqual( 24500, strlen( $body ), 'At most about 20 percent longer than the 20,407 bytes it replaced.' );
		$own = $body;
		foreach ( Saddle_Context::design_numbers() as $line ) {
			$own = str_replace( $line, '', $own );
		}
		$this->assertStringNotContainsString( '—', $own, 'No em-dashes (the shared design numbers are embedded as they are).' );
	}

	public function test_the_playbook_teaches_what_the_2026_11_09_page_missed() {
		$this->divi( true );
		$body = $this->body();

		foreach (
			array(
				'LOOK AT THE SITE'                  => 'study the site first',
				'PRESETS FIRST'                     => 'presets before hand styling',
				'divi-list-global-presets'          => 'the site\'s presets',
				'search=<term>'                     => 'finding a module',
				'"settings"'                        => 'module options',
				'"@tablet" or "@phone"'             => 'screen sizes',
				'":hover" or ":sticky"'             => 'states',
				'`columns` on a divi/row'           => 'column widths',
				'stack on phones'                   => 'what columns do on phones',
				'divi/global-layout'                => 'Components',
				'divi-set-page-settings'            => 'page settings',
				'RESPONSIVE PASS'                   => 'the responsive step',
				'loop_post_title'                   => 'per-item loop sources',
				'image.innerContent.src'            => 'sub-field binding',
				'targetLoop'                        => 'pagination by loop id',
				'list_users'                        => 'user loops',
				'divi-add-interaction'              => 'interactions',
				'Motion: default none'              => 'no scattered motion',
				'severity "note"'                   => 'notes are advice',
				'divi-get-rendered-page'            => 'the rendered HTML and CSS',
				'wait about two seconds'            => 'Divi defers its CSS',
				'at most three short questions'     => 'open requests',
				'five-line plan'                    => 'a plan the user approves',
				'word for word'                     => 'the user\'s copy',
				'never var()'                       => 'font variables as tokens',
				'module.advanced.htmlAttributes'    => 'the id and class path',
				'"phone", not "phoneWide"'          => 'phone, not phoneWide',
			) as $needle => $why
		) {
			$this->assertStringContainsString( $needle, $body, "The playbook must teach {$why}." );
		}
		$this->assertStringNotContainsString( 'ship DESIGNED defaults', $body, 'The claim about pack defaults was false.' );
		$this->assertStringNotContainsString( '"#0f172a"', $body, 'The example uses tokens, not hex.' );
	}

	public function test_the_playbook_matches_the_tools_and_a_fresh_site() {
		$this->divi( true );
		$body = $this->body();

		foreach (
			array(
				'divi-list-global-presets kind=all'                 => 'group presets need kind=all',
				'six or seven go three per line on tablets and two per line on phones' => 'what 6 to 8 columns do',
				'image.innerContent.alt to loop_post_featured_image_alt_text' => 'the per-item alt',
				'or the two interaction ids'                        => 'the interaction ids by hand',
				'list-section-recipes lists the recipes'            => 'the recipe list, not a fixed six',
				'one h1 per page'                                   => 'one h1',
				'set the heading level on every heading'            => 'heading levels',
				'Before Divi 5.10 the fill does not show'           => 'the button switch, softly',
			) as $needle => $why
		) {
			$this->assertStringContainsString( $needle, $body, "The playbook must teach {$why}." );
		}
		$this->assertStringNotContainsString( 'hero|features|pricing|testimonials|cta|faq', $body );

		// The worked example needs nothing a fresh Divi site lacks: no preset
		// id to fill in, and only the four colors every Divi site has.
		$start   = strpos( $body, "nodes:\n" );
		$example = substr( $body, $start, strpos( $body, "\n\n", $start ) - $start );
		$this->assertStringNotContainsString( 'modulePreset', $example );
		preg_match_all( '/--(g[cv]id-[a-z0-9-]+)/', $example, $m );
		$this->assertNotEmpty( $m[1] );
		$this->assertSame( array(), array_diff( $m[1], array( 'gcid-primary-color', 'gcid-secondary-color', 'gcid-heading-color', 'gcid-body-color' ) ) );
		preg_match_all( '/<([^>]+)>/', str_replace( array( '<p>', '</p>' ), '', $example ), $holes );
		$this->assertSame( array( 'media library URL' ), $holes[1], 'Only the image URL is left to fill in.' );
	}

	/**
	 * The worked example is real: it builds through the author and the
	 * validator against Divi's own module.json files with no echo warning.
	 */
	public function test_the_worked_example_builds_clean() {
		$this->divi( true );
		$body  = $this->body();
		$start = strpos( $body, "nodes:\n" ) + strlen( "nodes:\n" );
		$end   = strpos( $body, "\n\n", $start );
		$nodes = json_decode( substr( $body, $start, $end - $start ), true );
		$this->assertIsArray( $nodes, 'The example is valid JSON.' );

		$dir = Saddle_Test_Divi_Paths::components_or_skip();
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		Saddle_Divi_Schema::index( true );

		$tree = Saddle_Divi_Author::expand( $nodes );
		$this->assertNotWPError( $tree );
		$this->assertTrue( Saddle_Divi_Tree::validate( $tree ) );
		$this->assertSame( array(), Saddle_Divi_Echo::check_nodes( Saddle_Divi_Author::echo_nodes( $nodes, '0' ), '0' ) );

		$json = wp_json_encode( $tree );
		$this->assertStringContainsString( '"lightbox":{"desktop":{"value":"on"}}', $json, 'The settings entry is stored where Divi reads it.' );
		$this->assertStringContainsString( '"hover":{"color"', $json, 'The hover state sits beside the value.' );
		$this->assertStringContainsString( '"phone":{"value":{"size":"36px"}}', $json );
	}

	/* -------- the interactions note -------- */

	public function test_the_interactions_note_names_what_divi_reads() {
		$note = Saddle_Divi_Style::note( 'interactions' );
		$this->assertStringContainsString( 'divi-add-interaction', $note );
		$this->assertStringContainsString( 'module.decoration.interactionTrigger', $note );
		$this->assertStringContainsString( 'module.decoration.interactionTarget', $note );
		$this->assertStringNotContainsString( 'written through the "attributes" group', $note );
	}
}
