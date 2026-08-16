<?php
/**
 * The core behaviour Saddle's dashboard sign-in depends on.
 *
 * The admin app authenticates with the logged-in admin's cookie plus a `wp_rest`
 * nonce, and sends that nonce two ways — as the `X-WP-Nonce` header and as a
 * `_wpnonce` query parameter — because some hosting security layers (20i's
 * StackProtect among them) strip non-standard request headers at the edge.
 *
 * That workaround rests entirely on how core's `rest_cookie_check_errors()`
 * treats the two. There is no JS test harness in this project, so these tests
 * are the alarm: if a future WordPress stops honouring `_wpnonce`, or changes
 * which source wins, it fails here rather than silently on a customer's site.
 *
 * Note two setup requirements, both easy to trip over:
 *
 * - `rest_cookie_check_errors()` returns early when
 *   `true !== $wp_rest_auth_cookie && is_user_logged_in()`, so the global has to
 *   be set or the nonce branch is never reached.
 * - The hook fires in `WP_REST_Server::serve_request()`, not `dispatch()`, so
 *   these call the function directly rather than going through `rest_do_request()`.
 *
 * @package Saddle
 */

class Saddle_Nonce_Fallback_Test extends WP_UnitTestCase {

	private $server_backup;
	private $admin;

	/**
	 * Boot the REST server before any test's incorrect-usage capture starts.
	 *
	 * On its success path `rest_cookie_check_errors()` calls `rest_get_server()`
	 * to send a refreshed nonce header, which fires `rest_api_init` and with it
	 * the MCP adapter's server registration — and that emits a `_doing_it_wrong`
	 * in the test environment. Doing it here means it happens once, outside any
	 * test, rather than being attributed to whichever test authenticates first.
	 * Same reason `oauth-bearer-test.php` does it.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		$this->server_backup = $_SERVER;
		$this->admin         = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down() {
		$_SERVER = $this->server_backup;
		unset( $_REQUEST['_wpnonce'] );
		$GLOBALS['wp_rest_auth_cookie'] = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Put a cookie-authenticated admin in place and present the nonce whichever
	 * way the scenario calls for.
	 *
	 * @param string|null $query  Nonce for `$_REQUEST['_wpnonce']`, 'GOOD' for a valid one.
	 * @param string|null $header Nonce for `X-WP-Nonce`, 'GOOD' for a valid one.
	 * @return true|WP_Error Whatever core's cookie check returns.
	 */
	private function check( $query, $header ) {
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_auth_cookie'] = true;

		// Minted as the signed-in user — a wp_rest nonce is bound to the user id,
		// so creating it before wp_set_current_user() would never verify.
		$good = wp_create_nonce( 'wp_rest' );

		unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE'] );
		if ( null !== $query ) {
			$_REQUEST['_wpnonce'] = ( 'GOOD' === $query ) ? $good : $query;
		}
		if ( null !== $header ) {
			$_SERVER['HTTP_X_WP_NONCE'] = ( 'GOOD' === $header ) ? $good : $header;
		}

		return rest_cookie_check_errors( null );
	}

	public function test_the_header_authenticates_a_cookie_session() {
		$this->assertTrue( $this->check( null, 'GOOD' ) );
		$this->assertSame( $this->admin, get_current_user_id() );
		$this->assertTrue( Saddle_REST_Admin::can_manage() );
	}

	/**
	 * The fix itself: with the header gone, the query parameter alone keeps the
	 * session — which is why the dashboard survives a header-stripping host.
	 */
	public function test_the_query_parameter_alone_authenticates_a_cookie_session() {
		$this->assertTrue( $this->check( 'GOOD', null ) );
		$this->assertSame( $this->admin, get_current_user_id() );
		$this->assertTrue( Saddle_REST_Admin::can_manage() );
	}

	public function test_sending_the_nonce_both_ways_is_accepted() {
		$this->assertTrue( $this->check( 'GOOD', 'GOOD' ) );
		$this->assertSame( $this->admin, get_current_user_id() );
		$this->assertTrue( Saddle_REST_Admin::can_manage() );
	}

	/**
	 * The customer's exact failure. No nonce arrives at all, so core silently
	 * signs the request out — no error, just `wp_set_current_user( 0 )` — and the
	 * capability gate then fails as 401 rather than 403.
	 *
	 * The status code is the tell: `rest_authorization_required_code()` is
	 * `is_user_logged_in() ? 403 : 401`, so a 401 from an admin route always
	 * means the credentials never arrived, never that permission was refused.
	 */
	public function test_no_nonce_at_all_silently_logs_the_request_out() {
		$result = $this->check( null, null );

		$this->assertTrue( $result, 'Core reports success while having discarded the user.' );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertFalse( Saddle_REST_Admin::can_manage() );
		$this->assertSame( 401, rest_authorization_required_code() );
	}

	/**
	 * Core reads `$_REQUEST['_wpnonce']` before the header, so the query
	 * parameter does not back the header up — it overrules it.
	 */
	public function test_the_query_parameter_outranks_the_header() {
		$this->assertTrue(
			$this->check( 'GOOD', 'garbage' ),
			'A valid query nonce must win over a broken header.'
		);
		$this->assertSame( $this->admin, get_current_user_id() );
	}

	/**
	 * The other half of that precedence, and the reason the client must read the
	 * nonce live from `apiFetch.nonceMiddleware` instead of caching a copy: a
	 * stale value in the query string beats a freshly refreshed header and fails
	 * the request outright.
	 */
	public function test_a_stale_query_parameter_defeats_a_valid_header() {
		$result = $this->check( 'garbage', 'GOOD' );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_cookie_invalid_nonce', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A bad nonce and a missing nonce are different failures with different
	 * status codes — 403 versus 401. The dashboard's diagnosis leans on that.
	 */
	public function test_a_bad_nonce_is_403_not_401() {
		$result = $this->check( null, 'garbage' );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertNotSame(
			401,
			$result->get_error_data()['status'],
			'401 must stay reserved for "no credentials arrived".'
		);
	}
}
