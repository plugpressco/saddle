<?php
/**
 * Skills as MCP prompts (#178): prompts/list and prompts/get on the built-in
 * transport, the adapter configuration, and the gate they share with
 * saddle/get-skill — pause, tier, the tool switch, a disabled skill.
 *
 * @package Saddle
 */

class Saddle_Prompts_Test extends WP_UnitTestCase {

	const MD = "---\nname: launch-checklist\ndescription: Checks a page before it goes live.\nwhen_to_use: before publishing a landing page\n---\n# Launch\n\nOpen <id> with get-blocks, then run verify-page.\n";

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'read' );
		Saddle_Skills::install( self::MD );
	}

	public function tear_down() {
		Saddle_Capabilities::set_paused( false );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function rpc( $method, array $params = array() ) {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 7,
					'method'  => $method,
					'params'  => (object) $params,
				)
			)
		);
		return json_decode( wp_json_encode( Saddle_MCP::handle( $req )->get_data() ), true );
	}

	private function prompt_names() {
		return wp_list_pluck( $this->rpc( 'prompts/list' )['result']['prompts'], 'name' );
	}

	public function test_initialize_advertises_prompts_when_skills_exist() {
		$result = $this->rpc(
			'initialize',
			array( 'protocolVersion' => '2025-06-18' )
		)['result'];

		$this->assertArrayHasKey( 'prompts', $result['capabilities'] );
	}

	public function test_an_enabled_skill_is_listed_at_the_read_tier() {
		$list = $this->rpc( 'prompts/list' )['result']['prompts'];
		$mine = wp_list_filter( $list, array( 'name' => 'launch-checklist' ) );
		$mine = reset( $mine );

		$this->assertSame( 'Checks a page before it goes live. Use when: before publishing a landing page', $mine['description'] );
	}

	public function test_get_returns_the_body_verbatim_as_a_user_message() {
		$result = $this->rpc( 'prompts/get', array( 'name' => 'launch-checklist' ) )['result'];

		$this->assertSame( 'user', $result['messages'][0]['role'] );
		$this->assertSame( 'text', $result['messages'][0]['content']['type'] );
		$this->assertStringContainsString( 'Open <id> with get-blocks', $result['messages'][0]['content']['text'], 'Placeholders are instruction text and must arrive unescaped.' );
	}

	public function test_a_disabled_skill_disappears() {
		Saddle_Skills::set_enabled( 'launch-checklist', false );

		$this->assertNotContains( 'launch-checklist', $this->prompt_names() );
		$this->assertArrayHasKey( 'error', $this->rpc( 'prompts/get', array( 'name' => 'launch-checklist' ) ) );
	}

	public function test_a_paused_site_lists_and_serves_nothing() {
		Saddle_Capabilities::set_paused( true );

		$this->assertSame( array(), $this->prompt_names() );
		$this->assertArrayHasKey( 'error', $this->rpc( 'prompts/get', array( 'name' => 'launch-checklist' ) ) );
		$this->assertSame( array(), Saddle_Prompts::filter_adapter_list( array( 'anything' ) ), 'The adapter path is narrowed the same way.' );
	}

	public function test_switching_off_get_skill_hides_the_prompts() {
		Saddle_Capabilities::set_disabled_abilities( array( 'get-skill' ) );

		$this->assertSame( array(), $this->prompt_names() );
	}

	public function test_the_adapter_configuration_serves_the_same_prompt() {
		$configs = Saddle_Prompts::adapter_configs();
		$mine    = wp_list_filter( $configs, array( 'name' => 'launch-checklist' ) );
		$mine    = reset( $mine );

		$result = call_user_func( $mine['handler'] );
		$this->assertStringContainsString( '# Launch', $result['messages'][0]['content']['text'] );
		$this->assertTrue( call_user_func( $mine['permission'] ) );
	}
}
