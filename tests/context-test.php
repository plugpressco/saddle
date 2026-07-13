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
		remove_all_filters( 'saddle_system_context' );
		parent::tear_down();
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
