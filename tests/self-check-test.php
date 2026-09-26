<?php
/**
 * saddle/self-check: an agent diagnosing its own connection (#222).
 *
 * Driven through wp_get_ability()->execute(), the path an MCP client hits,
 * with the loopback probe faked at pre_http_request exactly as
 * connection-test.php does.
 *
 * @package Saddle
 */

class Saddle_Self_Check_Test extends WP_UnitTestCase {

	/**
	 * Loopback requests the probe made during a test.
	 *
	 * @var int
	 */
	private $requests = 0;

	public function set_up() {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->requests = 0;
	}

	public function tear_down() {
		delete_option( 'permalink_structure' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'saddle_tier_ceiling' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Answer the loopback probe per Authorization scheme, counting requests.
	 *
	 * @param bool $bearer_survives Whether a Bearer header reaches PHP.
	 */
	private function fake_loopback( $bearer_survives = true ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $bearer_survives ) {
				++$this->requests;
				$sent   = isset( $args['headers']['Authorization'] ) ? (string) $args['headers']['Authorization'] : '';
				$bearer = 0 === stripos( $sent, 'bearer ' );
				$body   = $bearer && ! $bearer_survives
					? array(
						'received'     => false,
						'scheme'       => '',
						'nonce_header' => true,
					)
					: array(
						'received'     => true,
						'scheme'       => $bearer ? 'bearer' : 'basic',
						'nonce_header' => true,
					);

				return array(
					'body'     => wp_json_encode( $body ),
					'response' => array( 'code' => 200 ),
				);
			},
			10,
			2
		);
	}

	private function as_role( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	private function run_check() {
		$result = wp_get_ability( 'saddle/self-check' )->execute( array() );
		$this->assertNotWPError( $result );
		return $result;
	}

	private function codes( array $result ) {
		return wp_list_pluck( $result['problems'], 'code' );
	}

	public function test_a_healthy_admin_connection_reports_ok() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->as_role( 'administrator' );
		$this->fake_loopback();

		$result = $this->run_check();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( array(), $result['problems'] );
		$this->assertSame( 'write', $result['connection']['effective_tier'] );
		$this->assertSame( 'write', $result['connection']['site_tier'] );
		$this->assertTrue( $result['headers']['checked'] );
		$this->assertGreaterThan( 0, $this->requests, 'The probe really ran.' );
		$this->assertSame( 'ok', $result['headers']['bearer'] );
		$this->assertArrayHasKey( 'visible', $result['tools'] );
		$this->assertArrayHasKey( 'drafts_only', $result['policies'] );
		$this->assertNotSame( '', $result['connection']['transport'] );
	}

	/**
	 * The case the tool exists for: Claude (Basic) works, so the owner assumes
	 * the server is fine, while ChatGPT (Bearer) is refused at the edge.
	 */
	public function test_it_names_a_stripped_bearer_header_with_the_fix() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->as_role( 'administrator' );
		$this->fake_loopback( false );

		$result = $this->run_check();

		$this->assertSame( 'attention', $result['status'] );
		$this->assertContains( 'bearer_header_stripped', $this->codes( $result ) );
		$problem = $result['problems'][ array_search( 'bearer_header_stripped', $this->codes( $result ), true ) ];
		$this->assertStringContainsString( 'Connection check', $problem['fix'] );
	}

	/**
	 * Read tier, read capability: a Subscriber-level credential may run it, but
	 * must not make the site send requests to itself.
	 */
	public function test_a_non_admin_gets_the_report_without_the_loopback() {
		$this->as_role( 'subscriber' );
		$this->fake_loopback();

		$result = $this->run_check();

		$this->assertSame( 0, $this->requests, 'No loopback for an account that cannot manage the site.' );
		$this->assertFalse( $result['headers']['checked'] );
		$this->assertNotEmpty( $result['headers']['reason'] );
		$this->assertSame( 'read', $result['connection']['effective_tier'] );
	}

	public function test_an_oauth_scope_below_the_site_tier_is_explained() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->as_role( 'editor' );
		add_filter(
			'saddle_tier_ceiling',
			static function () {
				return 'read';
			}
		);

		$result = $this->run_check();

		$this->assertSame( 'read', $result['connection']['effective_tier'] );
		$this->assertSame( 'write', $result['connection']['site_tier'] );
		$this->assertContains( 'scope_below_site_tier', $this->codes( $result ) );
	}

	public function test_plain_permalinks_are_reported_with_the_fix() {
		update_option( 'permalink_structure', '' );
		$this->as_role( 'subscriber' );

		$result = $this->run_check();

		$this->assertContains( 'plain_permalinks', $this->codes( $result ) );
	}

	public function test_a_paused_site_refuses_the_check_too() {
		$this->as_role( 'administrator' );
		Saddle_Capabilities::set_paused( true );

		$this->assertWPError( wp_get_ability( 'saddle/self-check' )->execute( array() ) );
	}

	public function test_it_is_declared_read_only_at_the_read_tier() {
		$meta = wp_get_ability( 'saddle/self-check' )->get_meta();

		$this->assertSame( 'read', $meta['saddle']['tier'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
	}
}
