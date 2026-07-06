<?php
/**
 * Credential scoping — a Saddle-issued key only opens Saddle's door.
 *
 * The security property pinned here: a request authenticated with a
 * `Saddle: `-prefixed Application Password is confined to `saddle/v1` REST
 * routes. Without this, the credential (a full core Application Password)
 * would authenticate the ENTIRE REST API, letting an agent bypass every
 * Saddle safety layer via wp/v2 or wp-abilities/v1.
 *
 * @package Saddle
 */

class Saddle_Scoping_Test extends WP_UnitTestCase {

	private $admin;
	private $login;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		// Building the REST server for the first time in the process triggers
		// a one-time doing_it_wrong from the bundled MCP adapter (its own
		// discover-abilities ability isn't registered in the test env). Warm
		// it up here, before per-test notice tracking starts, so that
		// third-party notice can't fail an unrelated assertion.
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->login = get_userdata( $this->admin )->user_login;

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		// Reset the per-request auth record between scenarios.
		$GLOBALS['wp_rest_application_password_uuid'] = null;
		parent::tear_down();
	}

	/**
	 * Create an app password and authenticate the "request" with it, the way
	 * core does for a real REST call (sets the authenticated-app-password
	 * global via the application_password_did_authenticate action).
	 *
	 * @param string $name Application password name.
	 */
	private function authenticate_with( $name ) {
		$created = WP_Application_Passwords::create_new_application_password(
			$this->admin,
			array( 'name' => $name )
		);
		$this->assertNotWPError( $created );

		$user = wp_authenticate_application_password( null, $this->login, $created[0] );
		$this->assertInstanceOf( 'WP_User', $user );
		wp_set_current_user( $user->ID );

		$this->assertNotEmpty(
			rest_get_authenticated_app_password(),
			'Core must record the app password used for this request.'
		);
	}

	/* -------- the security property -------- */

	public function test_saddle_credential_is_refused_outside_saddle_namespace() {
		$this->authenticate_with( 'Saddle: Claude Code' );

		foreach ( array( '/wp/v2/posts', '/wp/v2/users/me', '/wp/v2/settings' ) as $route ) {
			$res = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 403, $res->get_status(), "{$route} must be refused." );
			$this->assertSame( 'saddle_credential_scope', $res->as_error()->get_error_code() );
		}
	}

	public function test_saddle_credential_still_reaches_the_mcp_endpoint() {
		$this->authenticate_with( 'Saddle: Claude Code' );

		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array( 'protocolVersion' => '2025-11-25' ),
				)
			)
		);

		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status(), 'The MCP endpoint is the one door a Saddle key must open.' );
	}

	public function test_saddle_credential_is_refused_on_saddles_own_control_plane() {
		$this->authenticate_with( 'Saddle: Claude Code' );

		// The credential authenticates as the admin who issued it, so without
		// the scope wall each of these manage_options routes would let an
		// agent undo the safety model (raise its tier, re-enable tools,
		// install skills, mint or revoke credentials). Each request carries
		// valid params so it clears core's arg validation and actually
		// reaches the scope wall — proving the wall, not a 400, is the refusal.
		$control_plane = array(
			array( 'GET', '/saddle/v1/settings', array() ),
			array( 'POST', '/saddle/v1/settings', array( 'tier' => 'admin', 'paused' => false ) ),
			array( 'POST', '/saddle/v1/abilities', array( 'disabled' => array() ) ),
			array( 'POST', '/saddle/v1/skills', array( 'md' => '# x' ) ),
			array( 'GET', '/saddle/v1/clients', array() ),
			array( 'POST', '/saddle/v1/clients', array( 'name' => 'rogue' ) ),
		);

		foreach ( $control_plane as $call ) {
			$req = new WP_REST_Request( $call[0], $call[1] );
			foreach ( $call[2] as $param => $value ) {
				$req->set_param( $param, $value );
			}
			$res = rest_do_request( $req );
			$this->assertSame( 403, $res->get_status(), "{$call[0]} {$call[1]} must be refused for a Saddle-issued key." );
			$this->assertSame( 'saddle_credential_scope', $res->as_error()->get_error_code(), "{$call[0]} {$call[1]} must be blocked by the scope wall specifically." );
		}
	}

	public function test_non_saddle_credential_is_untouched() {
		$this->authenticate_with( 'Some Backup Plugin' );

		$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->assertSame( 200, $res->get_status(), 'Credentials Saddle did not issue must keep full REST access.' );
	}

	public function test_cookie_sessions_are_untouched() {
		wp_set_current_user( $this->admin ); // No app password in play.

		$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_kill_switch_filter_disables_scoping() {
		$this->authenticate_with( 'Saddle: Claude Code' );
		add_filter( 'saddle_scope_credentials', '__return_false' );

		$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );

		remove_filter( 'saddle_scope_credentials', '__return_false' );
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_allowed_routes_filter_can_extend_the_scope() {
		$this->authenticate_with( 'Saddle: Claude Code' );
		$extend = static function ( $allowed ) {
			$allowed[] = '/wp/v2/posts';
			return $allowed;
		};
		add_filter( 'saddle_credential_allowed_routes', $extend );

		$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );

		remove_filter( 'saddle_credential_allowed_routes', $extend );
		$this->assertSame( 200, $res->get_status() );
	}

	/* -------- XML-RPC surface -------- */

	public function test_saddle_credential_is_refused_over_xmlrpc() {
		// Simulate the XML-RPC surface without defining the constant, which
		// would poison every later test in this PHP process.
		add_filter( 'saddle_is_xmlrpc_request', '__return_true' );

		$created = WP_Application_Passwords::create_new_application_password(
			$this->admin,
			array( 'name' => 'Saddle: Claude Code' )
		);
		$this->assertNotWPError( $created );

		$result = wp_authenticate_application_password( null, $this->login, $created[0] );
		remove_filter( 'saddle_is_xmlrpc_request', '__return_true' );

		$this->assertNotInstanceOf( 'WP_User', $result, 'Saddle keys must not authenticate XML-RPC.' );

		// And a non-Saddle key still may.
		add_filter( 'saddle_is_xmlrpc_request', '__return_true' );
		$other = WP_Application_Passwords::create_new_application_password(
			$this->admin,
			array( 'name' => 'Mobile App' )
		);
		$ok = wp_authenticate_application_password( null, $this->login, $other[0] );
		remove_filter( 'saddle_is_xmlrpc_request', '__return_true' );
		$this->assertInstanceOf( 'WP_User', $ok );
	}
}
