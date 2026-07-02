<?php
/**
 * Proves the recovery shim actually bridges to CORE authentication under a
 * simulated header-stripping host (PHP_AUTH_* absent, but the raw Authorization
 * header reachable). This is the exact beta.divitorque.com condition.
 */
class Saddle_Shim_Bridge_Test extends WP_UnitTestCase {

	public function test_shim_makes_core_authenticate_a_real_app_password() {
		$uid   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$login = get_userdata( $uid )->user_login;

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );

		list( $raw_pw ) = WP_Application_Passwords::create_new_application_password( $uid, array( 'name' => 'Saddle: shim' ) );

		// --- simulate a stripping host: PHP never split Basic auth into PHP_AUTH_*,
		//     but the raw header is present (as it is via getallheaders on beta). ---
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( $login . ':' . $raw_pw );

		// Baseline: WITHOUT the shim, core cannot see credentials.
		$before = wp_authenticate_application_password( null, $login, '' );
		$this->assertNotInstanceOf( 'WP_User', $before );

		// Run the shim, then let core read PHP_AUTH_* exactly as it does in REST.
		Saddle_Connection::recover_auth_header();
		$this->assertSame( $login, $_SERVER['PHP_AUTH_USER'] ?? null, 'Shim must populate PHP_AUTH_USER.' );

		$user = wp_authenticate_application_password( null, $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		$this->assertInstanceOf( 'WP_User', $user, 'Core must authenticate the recovered credential.' );
		$this->assertSame( $uid, $user->ID );
	}
}
