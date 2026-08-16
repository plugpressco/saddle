<?php
/**
 * System-context generation — the read-only orientation handed to agents via
 * get-instructions and the MCP initialize handshake.
 *
 * @package Saddle
 */

class Saddle_Context_Test extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( 'active_plugins' );
		wp_cache_delete( 'plugins', 'plugins' );
		remove_all_filters( 'saddle_system_context' );
		parent::tear_down();
	}

	/* ---- installed inventory is admin-tier information (WP.org review) ---- */

	/**
	 * saddle/list-plugins is admin-gated, and site.php says why: "the inventory
	 * itself is sensitive". A read-tier session was being handed a prose copy of
	 * the same list for free.
	 */
	public function test_read_tier_context_does_not_name_installed_plugins() {
		Saddle_Capabilities::set_tier( 'read' );

		$ctx = Saddle_Context::system_context();

		$this->assertStringNotContainsString( 'Plugins active on this site', $ctx );
	}

	public function test_admin_tier_context_still_names_installed_plugins() {
		Saddle_Capabilities::set_tier( 'admin' );
		$this->with_an_active_plugin();

		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'Plugins active on this site', $ctx );
		$this->assertStringContainsString( 'Pretend Plugin (4.2)', $ctx );
	}

	/**
	 * The read-tier omission has to be about the tier, not about the test site
	 * happening to have nothing installed — so prove it with a plugin present.
	 */
	public function test_read_tier_hides_a_plugin_that_admin_tier_would_name() {
		Saddle_Capabilities::set_tier( 'read' );
		$this->with_an_active_plugin();

		$this->assertStringNotContainsString( 'Pretend Plugin', Saddle_Context::system_context() );
	}

	/**
	 * Put one active plugin in front of the real code path.
	 *
	 * get_plugins() has no filter — it reads the filesystem — but it does serve
	 * from the `plugins` cache group, keyed by folder. Priming that is what lets
	 * the assertion drive active_plugin_names() for real instead of restating
	 * its logic.
	 */
	private function with_an_active_plugin() {
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'pretend/pretend.php' => array(
						'Name'    => 'Pretend Plugin',
						'Version' => '4.2',
					),
				),
			),
			'plugins'
		);

		update_option( 'active_plugins', array( 'pretend/pretend.php' ) );
	}

	/**
	 * The theme's name is inventory (saddle/list-themes is admin-gated), but
	 * whether it is block-based changes how an agent must author a page — so
	 * that fact survives at every tier while the name does not.
	 */
	public function test_read_tier_describes_the_theme_without_naming_it() {
		Saddle_Capabilities::set_tier( 'read' );
		$theme = wp_get_theme()->get( 'Name' );

		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'Active theme', $ctx );
		$this->assertStringNotContainsString( $theme, $ctx );
		$this->assertMatchesRegularExpression( '/Active theme: a (block|classic) theme/', $ctx );
	}

	public function test_admin_tier_names_the_theme() {
		Saddle_Capabilities::set_tier( 'admin' );

		$this->assertStringContainsString( wp_get_theme()->get( 'Name' ), Saddle_Context::system_context() );
	}

	public function test_context_states_read_only_scope_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );
		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'READ content only', $ctx );
		$this->assertStringContainsString( 'posts, pages, media, and their block structure', $ctx );
	}

	public function test_context_carries_the_refusal_playbook() {
		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'When a call is refused', $ctx );
		$this->assertStringContainsString( 'never retry in a loop', $ctx );
		$this->assertStringContainsString( 'confirm_token', $ctx );
	}

	public function test_context_describes_the_approval_gate_at_write_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'confirmation token', $ctx );
		$this->assertStringContainsString( 'Nothing is ever deleted in one step', $ctx );
	}

	public function test_context_reports_content_counts() {
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'Published posts: 3', $ctx );
		$this->assertStringContainsString( 'Published pages: 1', $ctx );
	}

	public function test_context_names_custom_post_types_it_does_not_manage() {
		register_post_type( 'saddle_test_cpt', array( 'public' => true, 'label' => 'Test Widgets' ) );

		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'custom content types Saddle does not manage', $ctx );
		$this->assertStringContainsString( 'Test Widgets', $ctx );

		_unregister_post_type( 'saddle_test_cpt' );
	}

	public function test_context_includes_timezone() {
		update_option( 'timezone_string', 'America/New_York' );
		$ctx = Saddle_Context::system_context();
		$this->assertStringContainsString( 'America/New_York', $ctx );
	}

	public function test_context_warns_about_active_page_builder() {
		// Elementor's load marker; define it to simulate the plugin being active.
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0.0-test' );
		}

		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'page builder is active', $ctx );
		$this->assertStringContainsString( 'Elementor', $ctx );
		$this->assertStringContainsString( 'BREAK its layout', $ctx );
	}

	public function test_native_builder_gets_in_scope_note_instead_of_hands_off_warning() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0.0-test' );
		}

		add_filter(
			'saddle_native_builders',
			static function ( $native ) {
				$native[] = 'Elementor';
				return $native;
			}
		);

		$ctx = Saddle_Context::system_context();
		remove_all_filters( 'saddle_native_builders' );

		$this->assertStringContainsString( 'dedicated saddle tools', $ctx, 'A native builder must be declared in scope.' );
		$this->assertStringNotContainsString( 'Prefer leaving builder-built pages alone', $ctx, 'The hands-off warning must not undercut an installed builder addon.' );
		$this->assertStringContainsString( 'never write a builder page', $ctx, 'The raw-content prohibition must survive.' );
	}

	/* -------- the steering has to point at the tool that replaced five -------- */

	/**
	 * context-bundle exists precisely to collapse get-design-system +
	 * list-block-types + list-block-patterns + list-section-recipes +
	 * list-templates into one call. The steering was still describing the old
	 * sequence and never named the bundle, so every session paid the five
	 * calls the bundle was built to save.
	 */
	public function test_context_sends_the_agent_to_the_bundle_first() {
		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'saddle/context-bundle', $ctx );
		$this->assertStringContainsString( 'ORIENT FIRST', $ctx );
	}

	public function test_context_names_the_verify_then_look_loop() {
		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'saddle/verify-page', $ctx );
		$this->assertStringContainsString( 'saddle/get-preview-url', $ctx );
	}

	/**
	 * Saddle_Context_Bundle::summary_lines() has been computed, budgeted and
	 * documented as riding the system context since the bundle shipped — and
	 * called by nothing at all. This is the assertion that keeps it wired.
	 */
	public function test_context_carries_the_sites_design_memory() {
		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'design memory', strtolower( $ctx ) );
	}

	/* -------- recent changes: a record, not a stutter -------- */

	public function test_a_run_of_edits_to_one_post_folds_into_a_single_line() {
		// What a real site looked like: six saves of the same post in a row,
		// spending six of the fifteen lines to say one thing.
		for ( $i = 0; $i < 6; $i++ ) {
			Saddle_Log::record(
				array(
					'action'  => 'update_post',
					'target'  => '1125',
					'summary' => 'Updated post #1125 "Draft ' . $i . '"',
				)
			);
		}

		$ctx = Saddle_Context::system_context();

		$this->assertSame( 1, substr_count( $ctx, 'Updated post #1125' ), 'Six identical changes must read as one line.' );
		$this->assertStringContainsString( '×6 in a row', $ctx );
		$this->assertStringContainsString( 'Draft 5', $ctx, 'The surviving line must be the most recent state, not the oldest.' );
	}

	public function test_changes_to_different_posts_are_not_folded_together() {
		Saddle_Log::record( array( 'action' => 'update_post', 'target' => '1', 'summary' => 'Updated post #1' ) );
		Saddle_Log::record( array( 'action' => 'update_post', 'target' => '2', 'summary' => 'Updated post #2' ) );

		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'Updated post #1', $ctx );
		$this->assertStringContainsString( 'Updated post #2', $ctx );
	}

	/**
	 * Only CONSECUTIVE runs fold, so the shape of the session survives:
	 * editing A, then B, then A again is three steps, not two.
	 */
	public function test_an_interrupted_run_is_not_folded() {
		Saddle_Log::record( array( 'action' => 'update_post', 'target' => '1', 'summary' => 'Updated post #1 first' ) );
		Saddle_Log::record( array( 'action' => 'update_post', 'target' => '2', 'summary' => 'Updated post #2' ) );
		Saddle_Log::record( array( 'action' => 'update_post', 'target' => '1', 'summary' => 'Updated post #1 again' ) );

		$ctx = Saddle_Context::system_context();

		$this->assertStringContainsString( 'Updated post #1 first', $ctx );
		$this->assertStringContainsString( 'Updated post #1 again', $ctx );
		$this->assertStringNotContainsString( 'in a row', $ctx );
	}

	public function test_system_context_filter_lets_addons_append_guidance() {
		add_filter(
			'saddle_system_context',
			static function ( $ctx ) {
				return $ctx . "\n# Divi guidance\nBuild real modules.";
			}
		);

		$ctx = Saddle_Context::system_context();
		$this->assertStringContainsString( 'Build real modules.', $ctx );
	}
}
