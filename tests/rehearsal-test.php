<?php
/**
 * Rehearsal mode (#180): with the switch on, write tools answer with what they
 * would do and the database is unchanged; read tools run; the tier and tool
 * switches still decide first; rehearsed calls are logged as rehearsed, never
 * as changes.
 *
 * @package Saddle
 */

class Saddle_Rehearsal_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'admin' );
		Saddle_Capabilities::set_rehearsal( true );
	}

	public function tear_down() {
		Saddle_Capabilities::set_rehearsal( false );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	/** Every post row, any type or status, so a stray revision counts too. */
	private function post_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('saddle_log')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_it_is_off_by_default() {
		delete_option( Saddle_Capabilities::REHEARSAL_OPTION );

		$this->assertFalse( Saddle_Capabilities::is_rehearsal() );
	}

	public function test_write_tools_answer_and_the_database_is_unchanged() {
		$id = self::factory()->post->create(
			array(
				'post_title'   => 'Untouched',
				'post_content' => '<!-- wp:paragraph --><p>Original.</p><!-- /wp:paragraph -->',
			)
		);
		update_option( 'blogdescription', 'Same tagline' );
		$rows = $this->post_rows();

		$calls = array(
			array( 'create-post', array( 'title' => 'New', 'status' => 'publish' ) ),
			array( 'update-post', array( 'id' => $id, 'title' => 'Changed' ) ),
			array( 'delete-post', array( 'id' => $id ) ),
			array( 'delete-post', array( 'id' => $id, 'force' => true, 'confirm_token' => 'x' ) ),
			array(
				'set-blocks',
				array(
					'post_id' => $id,
					'nodes'   => array(
						array(
							'type'    => 'core/heading',
							'content' => 'Replaced',
						),
					),
				),
			),
			array( 'update-option', array( 'name' => 'blogdescription', 'value' => 'Other tagline' ) ),
		);

		foreach ( $calls as $call ) {
			$result = $this->run_ability( $call[0], $call[1] );
			$this->assertIsArray( $result, $call[0] );
			$this->assertTrue( $result['rehearsal'], $call[0] . ' must rehearse.' );
			$this->assertSame( 'saddle/' . $call[0], $result['tool'] );
			$this->assertArrayNotHasKey( 'confirm_token', $result['would'] );
		}

		$post = get_post( $id );
		$this->assertSame( 'Untouched', $post->post_title );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertStringContainsString( 'Original.', $post->post_content );
		$this->assertSame( 'Same tagline', get_option( 'blogdescription' ) );
		$this->assertSame( $rows, $this->post_rows(), 'No post, revision or approval token may be created.' );
	}

	public function test_the_answer_shows_the_target_as_it_is_now() {
		$id = self::factory()->post->create( array( 'post_title' => 'Current title' ) );

		$result = $this->run_ability(
			'update-post',
			array(
				'id'    => $id,
				'title' => 'Proposed title',
			)
		);

		$this->assertSame( 'Proposed title', $result['would']['title'] );
		$this->assertSame( 'Current title', $result['current']['title'] );
	}

	public function test_read_tools_still_run() {
		$id = self::factory()->post->create( array( 'post_title' => 'Readable' ) );

		$result = $this->run_ability( 'get-post', array( 'id' => $id ) );

		$this->assertArrayNotHasKey( 'rehearsal', $result );
		$this->assertSame( 'Readable', $result['title'] );
	}

	public function test_the_tier_still_decides_first() {
		Saddle_Capabilities::set_tier( 'read' );

		$result = $this->run_ability( 'create-post', array( 'title' => 'Nope' ) );

		$this->assertWPError( $result, 'Rehearsal must not answer a call the tier refuses.' );
	}

	public function test_a_rehearsed_call_is_logged_as_rehearsed_not_as_a_change() {
		$this->run_ability( 'create-post', array( 'title' => 'Imagined' ) );

		$rehearsed = Saddle_Log::query( 20, 1, 'rehearsed' );
		$this->assertSame( 1, $rehearsed['total'] );
		$this->assertSame( 'rehearsed', $rehearsed['entries'][0]['type'] );
		$this->assertStringContainsString( 'Nothing was saved', $rehearsed['entries'][0]['summary'] );

		$this->assertSame( 0, Saddle_Log::query( 20, 1, 'executed' )['total'] );
		$this->assertSame( array(), Saddle_Log::recent_executed( 20, 30 ), 'The agent must not be told a rehearsal happened.' );
	}

	public function test_the_context_says_so() {
		$context = Saddle_Context::system_context();

		$this->assertStringContainsString( 'REHEARSAL MODE IS ON', $context );

		Saddle_Capabilities::set_rehearsal( false );
		$this->assertStringNotContainsString( 'REHEARSAL MODE', Saddle_Context::system_context() );
	}

	public function test_the_settings_endpoint_round_trips_the_switch() {
		Saddle_Capabilities::set_rehearsal( false );

		$request = new WP_REST_Request( 'POST', '/saddle/v1/preferences' );
		$request->set_body_params( array( 'rehearsal' => true ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['rehearsal'] );
		$this->assertTrue( Saddle_Capabilities::is_rehearsal() );
	}
}
