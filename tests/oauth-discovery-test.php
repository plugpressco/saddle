<?php
/**
 * Discovery documents — how a client that has never seen this site finds its
 * way to the authorization server.
 *
 * The properties pinned here are mostly about exactness. A conformant client
 * discards an authorization server metadata document whose `issuer` does not
 * byte-match the identifier it built the request from, so a subtly wrong value
 * fails as a silent "could not connect" rather than a visible error. Likewise
 * the root interception must claim the two paths it serves and nothing else —
 * `.well-known` is shared ground with ACME, `security.txt`, and others.
 *
 * @package Saddle
 */

class Saddle_OAuth_Discovery_Test extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Register on the real hook, before the first REST server build. Doing
		// it here rather than per-test matters twice over: register_rest_route()
		// warns when called outside rest_api_init, and these hooks are installed
		// before WP_UnitTestCase snapshots the filter table, so every test in the
		// class starts from the same registered state.
		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_action( 'rest_api_init', array( 'Saddle_OAuth_Discovery', 'register_routes' ) );

		// Re-fire the action on the EXISTING server rather than rebuilding one.
		// Another suite may already have built it, in which case rest_api_init
		// has fired and our routes would never register — but discarding the
		// server would also discard the MCP route, which the adapter only
		// registers once per process (on mcp_adapter_init).
		do_action( 'rest_api_init', rest_get_server() );
	}

	public function set_up() {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );

		// Re-added per test — see the note in set_up_before_class; the filter
		// table is restored to a process-wide snapshot after every test.
		add_filter( 'saddle_oauth_enabled', '__return_true' );
	}

	public function tear_down() {
		delete_option( 'permalink_structure' );
		unset( $_SERVER['REQUEST_URI'] );
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * Protected resource metadata
	 * --------------------------------------------------------------- */

	public function test_protected_resource_names_the_mcp_endpoint_and_its_server() {
		$doc = Saddle_OAuth_Discovery::protected_resource_metadata();

		$this->assertSame( Saddle_OAuth::resource_id(), $doc['resource'] );
		$this->assertSame( array( Saddle_OAuth::issuer() ), $doc['authorization_servers'] );
		$this->assertSame( array( 'header' ), $doc['bearer_methods_supported'] );
		$this->assertNotEmpty( $doc['scopes_supported'] );
	}

	public function test_the_resource_id_has_no_trailing_slash() {
		// RFC 8707 canonical form. A client sends this back verbatim as
		// `resource=`, and the audience check is a string comparison.
		$this->assertStringEndsWith( '/saddle/v1/mcp', Saddle_OAuth::resource_id() );
	}

	/* ------------------------------------------------------------------
	 * Authorization server metadata
	 * --------------------------------------------------------------- */

	public function test_issuer_matches_the_document_it_is_served_from() {
		$doc = Saddle_OAuth_Discovery::authorization_server_metadata();

		// A mismatch here makes every conformant client discard the document.
		$this->assertSame( Saddle_OAuth::issuer(), $doc['issuer'] );
	}

	public function test_the_issuer_carries_a_path_so_the_rest_fallback_is_legal() {
		$path = (string) wp_parse_url( Saddle_OAuth::issuer(), PHP_URL_PATH );

		// RFC 8414 only defines `<issuer>/.well-known/openid-configuration` as a
		// lookup form when the issuer has a path component. That fallback is what
		// keeps discovery working on a subdirectory install, so the path is
		// load-bearing rather than incidental.
		$this->assertNotEmpty( $path );
		$this->assertStringEndsWith( '/saddle/v1/oauth', Saddle_OAuth::issuer() );
	}

	public function test_only_s256_pkce_is_advertised() {
		$doc = Saddle_OAuth_Discovery::authorization_server_metadata();

		// Advertising `plain` would let a client downgrade itself into a flow
		// where an intercepted authorization code is enough.
		$this->assertSame( array( 'S256' ), $doc['code_challenge_methods_supported'] );
	}

	public function test_no_legacy_grants_are_advertised() {
		$doc = Saddle_OAuth_Discovery::authorization_server_metadata();

		$this->assertNotContains( 'implicit', $doc['grant_types_supported'] );
		$this->assertNotContains( 'password', $doc['grant_types_supported'] );
		$this->assertNotContains( 'token', $doc['response_types_supported'] );
	}

	public function test_the_modern_client_registration_signals_are_present() {
		$doc = Saddle_OAuth_Discovery::authorization_server_metadata();

		$this->assertTrue( $doc['client_id_metadata_document_supported'] );
		$this->assertTrue( $doc['authorization_response_iss_parameter_supported'] );
		$this->assertTrue( $doc['resource_indicators_supported'] );
	}

	public function test_registration_endpoint_is_omitted_when_self_registration_is_off() {
		update_option( Saddle_OAuth_Clients::DCR_OPTION, 0 );

		$doc = Saddle_OAuth_Discovery::authorization_server_metadata();

		// Omitted rather than left pointing at a 404, so a client falls through
		// to a mechanism that works instead of failing on one that doesn't.
		$this->assertArrayNotHasKey( 'registration_endpoint', $doc );

		delete_option( Saddle_OAuth_Clients::DCR_OPTION );
		$this->assertArrayHasKey( 'registration_endpoint', Saddle_OAuth_Discovery::authorization_server_metadata() );
	}

	/* ------------------------------------------------------------------
	 * The REST mirrors
	 * --------------------------------------------------------------- */

	public function test_the_rest_fallback_serves_the_authorization_server_document() {
		$request  = new WP_REST_Request( 'GET', '/saddle/v1/oauth/.well-known/openid-configuration' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Saddle_OAuth::issuer(), $response->get_data()['issuer'] );
	}

	public function test_the_dot_free_aliases_serve_both_documents() {
		// Some hosts block any path segment starting with a dot at the web-server
		// layer, which would take out every `.well-known` form at once. These
		// aliases are what the 401 challenge actually points at.
		$as = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/oauth/authorization-server' ) );
		$pr = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/oauth/protected-resource' ) );

		$this->assertSame( 200, $as->get_status() );
		$this->assertSame( 200, $pr->get_status() );
		$this->assertSame( Saddle_OAuth::resource_id(), $pr->get_data()['resource'] );
	}

	public function test_discovery_documents_are_readable_cross_origin() {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/oauth/protected-resource' ) );

		// A browser-hosted MCP client has to be able to read these, and must
		// never fetch them with the visitor's ambient cookie authority.
		$this->assertSame( '*', $response->get_headers()['Access-Control-Allow-Origin'] );
	}

	public function test_the_challenge_points_at_a_reachable_url() {
		$url = Saddle_OAuth_Discovery::protected_resource_url();

		$this->assertStringContainsString( '/saddle/v1/oauth/protected-resource', $url );
		$this->assertStringNotContainsString( '/.well-known/', $url );
	}

	/* ------------------------------------------------------------------
	 * Root interception
	 * --------------------------------------------------------------- */

	/**
	 * The interceptor's path matcher, without the exit() that follows a hit.
	 *
	 * @param string $path Request path to test.
	 * @return bool Whether Saddle would claim it.
	 */
	private function would_claim( $path ) {
		$reflect = new ReflectionMethod( 'Saddle_OAuth_Discovery', 'request_path' );
		$reflect->setAccessible( true );

		$_SERVER['REQUEST_URI'] = $path;
		$normalized             = $reflect->invoke( null );

		$issuer_path = (string) wp_parse_url( Saddle_OAuth::issuer(), PHP_URL_PATH );
		$mcp_path    = (string) wp_parse_url( Saddle_OAuth::resource_id(), PHP_URL_PATH );

		$claimed = array(
			Saddle_OAuth_Discovery::PRM_PATH,
			Saddle_OAuth_Discovery::PRM_PATH . $mcp_path,
			Saddle_OAuth_Discovery::ASM_PATH,
			Saddle_OAuth_Discovery::OIDC_PATH,
			Saddle_OAuth_Discovery::ASM_PATH . $issuer_path,
			Saddle_OAuth_Discovery::OIDC_PATH . $issuer_path,
		);

		return in_array( $normalized, $claimed, true );
	}

	public function test_the_documented_well_known_paths_are_claimed() {
		$mcp = (string) wp_parse_url( Saddle_OAuth::resource_id(), PHP_URL_PATH );

		$this->assertTrue( $this->would_claim( '/.well-known/oauth-protected-resource' ) );
		$this->assertTrue( $this->would_claim( '/.well-known/oauth-protected-resource' . $mcp ) );
		$this->assertTrue( $this->would_claim( '/.well-known/oauth-authorization-server' ) );
		$this->assertTrue( $this->would_claim( '/.well-known/openid-configuration' ) );
	}

	public function test_other_well_known_paths_are_left_alone() {
		// `.well-known` is shared ground. Claiming more than we serve would break
		// certificate renewal, of all things.
		$this->assertFalse( $this->would_claim( '/.well-known/acme-challenge/abc123' ) );
		$this->assertFalse( $this->would_claim( '/.well-known/security.txt' ) );
		$this->assertFalse( $this->would_claim( '/.well-known/oauth-protected-resource/some/other/thing' ) );
		$this->assertFalse( $this->would_claim( '/wp-json/wp/v2/posts' ) );
	}

	public function test_encoded_and_doubled_paths_normalize_to_the_same_decision() {
		$this->assertTrue( $this->would_claim( '//.well-known//oauth-authorization-server' ) );
		$this->assertTrue( $this->would_claim( '/.well-known/oauth-authorization-server/' ) );
		$this->assertTrue( $this->would_claim( '/.well-known/oauth-authorization-server?x=1' ) );
	}

	public function test_the_interceptor_stays_out_of_the_way_while_oauth_is_off() {
		remove_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_enabled', '__return_false' );
		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';

		// Returns rather than exits: with OAuth off there is nothing to publish,
		// and WordPress should 404 the path as it did before the plugin existed.
		$this->assertNull( Saddle_OAuth_Discovery::maybe_serve() );
	}

	/* ------------------------------------------------------------------
	 * Readiness
	 * --------------------------------------------------------------- */

	public function test_plain_permalinks_block_oauth() {
		delete_option( 'permalink_structure' );

		// Without pretty permalinks rest_url() returns `?rest_route=…`, which
		// cannot be a canonical resource identifier and leaves the issuer's
		// well-known fallback with no path to live at.
		$this->assertFalse( Saddle_OAuth::readiness()['permalinks'] );
		$this->assertFalse( Saddle_OAuth::readiness()['ready'] );
	}
}
