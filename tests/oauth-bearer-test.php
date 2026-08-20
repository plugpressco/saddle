<?php
/**
 * Bearer tokens: what they authenticate, what they cannot reach, and how far
 * they are allowed to go once they are in.
 *
 * The properties pinned here are the ones that keep OAuth from becoming a way
 * around the safety model rather than a way into it: a token only works on the
 * MCP endpoint, only up to the scope its owner approved, and never above the
 * tier the site is set to.
 *
 * @package Saddle
 */

class Saddle_OAuth_Bearer_Test extends WP_UnitTestCase {

	private $admin;
	private $token;
	private $grant_id;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		update_option( 'permalink_structure', '/%postname%/' );

		// Per test, not once per class: WP_UnitTestCase snapshots the filter
		// table the first time ANY test case in the process runs, and restores
		// to that snapshot after every test — so anything registered in
		// set_up_before_class is wiped as soon as another class ran first.
		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );

		// Other suites leave Basic credentials on $_SERVER; a leftover would make
		// the challenge suppress itself as if a real key had been rejected.
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'] );

		Saddle_OAuth_Store::register_cpt();
	}

	public function tear_down() {
		remove_filter( 'saddle_tier_ceiling', array( 'Saddle_OAuth_Bearer', 'tier_ceiling' ) );

		delete_option( 'permalink_structure' );
		delete_option( Saddle_Capabilities::OPTION );

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI'] );

		// The resolver caches the token record and its grant's scope for the
		// request; reset both between scenarios so one test's token can't leak
		// into the next.
		foreach ( array( 'token_record' => null, 'grant_scope' => '' ) as $property => $empty ) {
			$reset = new ReflectionProperty( 'Saddle_OAuth_Bearer', $property );
			$reset->setAccessible( true );
			$reset->setValue( null, $empty );
		}

		parent::tear_down();
	}

	/**
	 * Mint a live grant + access token, and make the request look like it is
	 * carrying that token to the MCP endpoint.
	 *
	 * @param string $scope Granted scope.
	 * @return string The raw access token.
	 */
	private function issue_token( $scope = 'saddle:read' ) {
		$this->grant_id = Saddle_OAuth::random_secret( 16 );
		$this->token    = Saddle_OAuth::random_secret( 32 );

		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => $this->grant_id,
				'client_id'   => 'saddle_test',
				'client_name' => 'ChatGPT',
				'user_id'     => $this->admin,
				'scope'       => $scope,
				'resource'    => Saddle_OAuth::resource_id(),
			)
		);

		Saddle_OAuth_Store::save_token(
			'access',
			$this->token,
			array(
				'grant_id'  => $this->grant_id,
				'client_id' => 'saddle_test',
				'user_id'   => $this->admin,
				'scope'     => $scope,
				'resource'  => Saddle_OAuth::resource_id(),
			)
		);

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';

		return $this->token;
	}

	/* ------------------------------------------------------------------
	 * Resolution
	 * --------------------------------------------------------------- */

	public function test_a_live_token_resolves_to_its_user() {
		$this->issue_token();

		$this->assertSame( $this->admin, Saddle_OAuth_Bearer::resolve( false ) );
	}

	public function test_an_unknown_token_resolves_to_nobody() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';

		$this->assertFalse( Saddle_OAuth_Bearer::resolve( false ) );
	}

	public function test_a_revoked_connection_kills_its_token_immediately() {
		$this->issue_token();
		Saddle_OAuth_Store::revoke_grant( $this->grant_id );

		$this->assertFalse(
			Saddle_OAuth_Bearer::resolve( false ),
			'Revocation has to take effect before the access token expires, or it means nothing.'
		);
	}

	public function test_a_token_bound_to_another_site_is_refused() {
		$this->issue_token();

		// Rewrite the audience to a different resource, as a token minted by
		// some other Saddle install would carry.
		$record = Saddle_OAuth_Store::get_access_token( $this->token );
		update_post_meta( $record['id'], '_saddle_resource', 'https://elsewhere.example/wp-json/saddle/v1/mcp' );

		$this->assertFalse( Saddle_OAuth_Bearer::resolve( false ) );
	}

	public function test_an_existing_session_is_never_clobbered() {
		$this->issue_token();

		$this->assertSame(
			99,
			Saddle_OAuth_Bearer::resolve( 99 ),
			'A user resolved by cookie or Application Password must always win.'
		);
	}

	public function test_oauth_disabled_means_tokens_do_not_work() {
		$this->issue_token();
		remove_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_enabled', '__return_false' );

		$this->assertFalse( Saddle_OAuth_Bearer::resolve( false ) );
	}

	public function test_cleartext_transport_refuses_the_token() {
		$this->issue_token();
		remove_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_false' );

		$this->assertFalse( Saddle_OAuth_Bearer::resolve( false ) );

		remove_filter( 'saddle_oauth_readiness_ssl', '__return_false' );
	}

	/* ------------------------------------------------------------------
	 * Pre-init resolution
	 *
	 * determine_current_user is not a REST-time hook: any plugin calling
	 * is_user_logged_in() during plugins_loaded fires it before
	 * wp-settings.php has created $GLOBALS['wp_rewrite'] — the same window
	 * Saddle_OAuth_Store::find() documents for the DB path. The resolver
	 * must authenticate there too, not just avoid the fatal: core memoizes
	 * whatever user it resolves for the rest of the request, so a refusal
	 * here is a dead connector exactly like a crash.
	 * --------------------------------------------------------------- */

	/**
	 * Run $fn in the state determine_current_user fires in when another
	 * plugin checks the user during plugins_loaded — before wp-settings.php
	 * has created $wp_rewrite.
	 *
	 * try/finally, per call: WP_UnitTestCase never restores this global, so
	 * a failing assertion must not leak a nulled instance into later tests.
	 *
	 * @param callable $fn What to run without the rewrite global.
	 * @return mixed Whatever $fn returns.
	 */
	private function before_wp_rewrite_exists( $fn ) {
		$saved                 = $GLOBALS['wp_rewrite'];
		$GLOBALS['wp_rewrite'] = null;
		try {
			return $fn();
		} finally {
			$GLOBALS['wp_rewrite'] = $saved;
		}
	}

	public function test_resolve_works_before_wp_rewrite_exists() {
		$this->issue_token();

		$user = $this->before_wp_rewrite_exists(
			function () {
				return Saddle_OAuth_Bearer::resolve( false );
			}
		);

		$this->assertSame(
			$this->admin,
			$user,
			'is_user_logged_in() during plugins_loaded fires determine_current_user before $wp_rewrite exists; a valid token must resolve there, not fatal or 401.'
		);
	}

	public function test_resource_id_is_identical_before_and_after_init() {
		$structures = array(
			'pretty' => '/%postname%/',
			'index'  => '/index.php/%postname%/',
			'plain'  => '',
		);

		foreach ( $structures as $label => $structure ) {
			// set_permalink_structure(), not update_option(): rest_url()
			// reads the live instance, and a stale one would make this
			// compare the replica against the wrong baseline.
			$this->set_permalink_structure( $structure );

			$post_init = Saddle_OAuth::resource_id();
			$pre_init  = $this->before_wp_rewrite_exists(
				function () {
					return Saddle_OAuth::resource_id();
				}
			);

			$this->assertSame(
				$post_init,
				$pre_init,
				sprintf( 'resource_id() diverged on %s permalinks — every stored token audience would stop matching.', $label )
			);
		}

		// Leave the live instance matching what set_up put in the option,
		// so state cannot leak into whichever test runs next.
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function test_a_foreign_token_is_still_refused_before_wp_rewrite_exists() {
		$this->issue_token();

		$record = Saddle_OAuth_Store::get_access_token( $this->token );
		update_post_meta( $record['id'], '_saddle_resource', 'https://elsewhere.example/wp-json/saddle/v1/mcp' );

		$this->assertFalse(
			$this->before_wp_rewrite_exists(
				function () {
					return Saddle_OAuth_Bearer::resolve( false );
				}
			),
			'Being early is not a reason to skip the RFC 8707 audience check.'
		);
	}

	/* ------------------------------------------------------------------
	 * Confinement
	 * --------------------------------------------------------------- */

	public function test_a_token_does_not_authenticate_outside_the_mcp_endpoint() {
		$this->issue_token();

		foreach ( array( '/wp-json/wp/v2/posts', '/wp-json/saddle/v1/settings', '/wp-json/wp-abilities/v1/abilities' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;

			$this->assertFalse(
				Saddle_OAuth_Bearer::resolve( false ),
				sprintf( 'A bearer token must never authenticate %s — not even far enough to be refused later.', $uri )
			);
		}
	}

	public function test_confinement_refuses_a_strayed_token_at_the_route() {
		$this->issue_token();
		Saddle_OAuth_Bearer::resolve( false );

		$request = new WP_REST_Request( 'GET', '/saddle/v1/settings' );
		$result  = Saddle_OAuth_Bearer::confine( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_oauth_scope', $result->get_error_code() );
	}

	public function test_confinement_lets_the_mcp_endpoint_through() {
		$this->issue_token();
		Saddle_OAuth_Bearer::resolve( false );

		$request = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );

		$this->assertNull( Saddle_OAuth_Bearer::confine( null, array(), $request ) );
	}

	/* ------------------------------------------------------------------
	 * The scope clamp
	 * --------------------------------------------------------------- */

	public function test_a_read_token_cannot_write_on_a_write_tier_site() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->issue_token( 'saddle:read' );
		Saddle_OAuth_Bearer::resolve( false );

		$this->assertSame( 'write', Saddle_Capabilities::get_site_tier(), 'The owner’s setting is unchanged.' );
		$this->assertSame( 'read', Saddle_Capabilities::get_tier(), 'But this request only carries read.' );
		$this->assertFalse( Saddle_Capabilities::tier_allows( 'write' ) );
	}

	public function test_a_scope_can_never_exceed_the_site_tier() {
		Saddle_Capabilities::set_tier( 'read' );
		$this->issue_token( 'saddle:admin' );
		Saddle_OAuth_Bearer::resolve( false );

		$this->assertSame(
			'read',
			Saddle_Capabilities::get_tier(),
			'A token asking for more than the site allows still only gets what the site allows.'
		);
	}

	public function test_a_matching_scope_leaves_the_tier_alone() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->issue_token( 'saddle:write' );
		Saddle_OAuth_Bearer::resolve( false );

		$this->assertSame( 'write', Saddle_Capabilities::get_tier() );
		$this->assertTrue( Saddle_Capabilities::tier_allows( 'write' ) );
	}

	/* ------------------------------------------------------------------
	 * What a token is OFFERED — the tier filter, over the OAuth path
	 * --------------------------------------------------------------- */

	/**
	 * tools/list is filtered to what the credential can call, and the tier it
	 * reads comes from a different route on this path: tier_ceiling() →
	 * scope_to_tier() → the saddle_tier_ceiling filter. The clamp tests above
	 * prove the tier is right; these prove the TOOL LIST that now depends on
	 * it is right, which is the part a connected app actually sees.
	 *
	 * Worth its own cover because an empty list here is indistinguishable, from
	 * the user's side, from the bug this whole branch is about: an app that
	 * signs in fine and then reports no callable actions.
	 *
	 * @param string $scope Granted scope.
	 * @return string[] Tool names offered to that token.
	 */
	private function tools_offered_to( $scope ) {
		$this->issue_token( $scope );
		Saddle_OAuth_Bearer::resolve( false );
		wp_set_current_user( $this->admin );

		$req = new WP_REST_Request( 'POST', '/saddle/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );

		return wp_list_pluck( Saddle_MCP::handle( $req )->get_data()['result']['tools'], 'name' );
	}

	public function test_a_read_token_is_offered_the_read_tools_and_no_others() {
		Saddle_Capabilities::set_tier( 'admin' );

		$names = $this->tools_offered_to( 'saddle:read' );

		$this->assertNotEmpty( $names, 'A read token must still be offered the whole read surface.' );
		$this->assertContains( 'saddle-list-posts', $names );
		$this->assertNotContains( 'saddle-create-post', $names, 'The scope narrows the list, even on an admin-tier site.' );
	}

	public function test_a_write_token_is_offered_the_write_tools() {
		Saddle_Capabilities::set_tier( 'admin' );

		$names = $this->tools_offered_to( 'saddle:write' );

		$this->assertContains( 'saddle-create-post', $names );
		$this->assertNotContains( 'saddle-list-plugins', $names, 'write is still not admin.' );
	}

	public function test_an_admin_token_on_an_admin_site_is_offered_everything() {
		Saddle_Capabilities::set_tier( 'admin' );

		$names = $this->tools_offered_to( 'saddle:admin' );

		$this->assertContains( 'saddle-list-plugins', $names );
	}

	public function test_the_denial_reason_sends_the_user_to_reconnect_not_to_permissions() {
		Saddle_Capabilities::set_tier( 'admin' );
		$this->issue_token( 'saddle:read' );
		// Core sets the current user from what determine_current_user returns;
		// denial_reason() checks that the caller is signed in before it looks at
		// tiers at all.
		wp_set_current_user( Saddle_OAuth_Bearer::resolve( false ) );

		$reason = Saddle_Capabilities::denial_reason( 'saddle/create-post' );

		// The site allows this; the app was not granted it. Telling the agent to
		// raise the site's access level would send the user to the wrong screen.
		$this->assertSame( 'saddle_insufficient_scope', $reason['code'] );
		$this->assertStringContainsString( 'reconnect', strtolower( $reason['message'] ) );
	}

	/* ------------------------------------------------------------------
	 * The challenge
	 * --------------------------------------------------------------- */

	public function test_a_401_on_the_mcp_endpoint_carries_the_discovery_pointer() {
		$_SERVER['REQUEST_URI'] = '/wp-json/saddle/v1/mcp';

		$response = Saddle_OAuth_Bearer::challenge(
			new WP_REST_Response( null, 401 ),
			rest_get_server(),
			new WP_REST_Request( 'POST', '/saddle/v1/mcp' )
		);

		$header = $response->get_headers()['WWW-Authenticate'];

		$this->assertStringStartsWith( 'Bearer ', $header );
		$this->assertStringContainsString( 'resource_metadata="', $header );
		$this->assertStringContainsString( Saddle_OAuth_Discovery::protected_resource_url(), $header );
	}

	public function test_the_adapter_denial_is_challenged_through_the_real_filter_chain() {
		$_SERVER['REQUEST_URI'] = '/wp-json/saddle/v1/mcp';

		// The vendored MCP adapter discards authenticated()'s WP_Error and
		// returns false from its permission_callback, so an anonymous caller
		// gets core's synthesized rest_forbidden 401 — this pins that the
		// challenge hook (wired unconditionally on rest_post_dispatch)
		// decorates exactly that shape, ChatGPT's very first probe.
		$error    = new WP_Error(
			'rest_forbidden',
			'Sorry, you are not allowed to do that.',
			array( 'status' => 401 )
		);
		$response = rest_convert_error_to_response( $error );

		$filtered = apply_filters(
			'rest_post_dispatch',
			$response,
			rest_get_server(),
			new WP_REST_Request( 'POST', '/saddle/v1/mcp' )
		);

		$header = $filtered->get_headers()['WWW-Authenticate'] ?? '';
		$this->assertStringStartsWith( 'Bearer ', $header );
		$this->assertStringContainsString( 'resource_metadata="', $header );
	}

	public function test_the_challenge_never_offers_basic() {
		$_SERVER['REQUEST_URI'] = '/wp-json/saddle/v1/mcp';

		$response = Saddle_OAuth_Bearer::challenge(
			new WP_REST_Response( null, 401 ),
			rest_get_server(),
			new WP_REST_Request( 'POST', '/saddle/v1/mcp' )
		);

		// A Basic challenge would make browsers pop a credential dialog.
		$this->assertStringNotContainsStringIgnoringCase( 'Basic', $response->get_headers()['WWW-Authenticate'] );
	}

	public function test_a_rejected_application_password_is_not_pushed_into_oauth() {
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'user:revoked-key' );

		$response = Saddle_OAuth_Bearer::challenge(
			new WP_REST_Response( null, 401 ),
			rest_get_server(),
			new WP_REST_Request( 'POST', '/saddle/v1/mcp' )
		);

		// Claude Code, Cursor and VS Code users keep the legible "your key was
		// rejected, reconnect the app" error instead of being sent into a
		// consent flow they never asked for.
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $response->get_headers() );
	}

	public function test_no_challenge_on_other_routes() {
		$response = Saddle_OAuth_Bearer::challenge(
			new WP_REST_Response( null, 401 ),
			rest_get_server(),
			new WP_REST_Request( 'GET', '/wp/v2/posts' )
		);

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $response->get_headers() );
	}

	public function test_no_challenge_while_oauth_is_off() {
		remove_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_enabled', '__return_false' );
		$_SERVER['REQUEST_URI'] = '/wp-json/saddle/v1/mcp';

		$response = Saddle_OAuth_Bearer::challenge(
			new WP_REST_Response( null, 401 ),
			rest_get_server(),
			new WP_REST_Request( 'POST', '/saddle/v1/mcp' )
		);

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $response->get_headers() );
	}

	/* ------------------------------------------------------------------
	 * Grants follow the account
	 * --------------------------------------------------------------- */

	public function test_deleting_a_user_revokes_their_connections() {
		$this->issue_token();

		Saddle_OAuth_Bearer::revoke_user_grants( $this->admin );

		$this->assertSame( array(), Saddle_OAuth_Store::list_grants() );
		$this->assertNull( Saddle_OAuth_Store::get_access_token( $this->token ) );
	}

	public function test_demoting_a_user_revokes_their_connections() {
		$this->issue_token();

		$user = get_userdata( $this->admin );
		$user->set_role( 'subscriber' );
		Saddle_OAuth_Bearer::reassess_user_grants( $this->admin, 'subscriber', array( 'administrator' ) );

		// A demotion that left a live token acting as the old role would be
		// cosmetic.
		$this->assertSame( array(), Saddle_OAuth_Store::list_grants() );
	}
}
