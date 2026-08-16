<?php
/**
 * How much an OAuth connection is granted, and who decides.
 *
 * This exists because of a real failure, reported from a live site: the
 * Permissions screen said "Managing the site", the connected ChatGPT reported
 * `read` access, and it was offered 83 of the 160 installed tools. Every write
 * tool — including Waggle's "Save SEO Fields" — was withheld, so the agent could
 * draft an SEO title and description and had no way to save them. Reconnecting
 * changed nothing, because every path that decided a scope named `saddle:read`
 * as a constant:
 *
 *   1. `normalize_scope()` fell back to it when the client sent no scope — and
 *      ChatGPT sends none, it registers dynamically and starts the flow without
 *      a `scope` parameter at all. That was the live cause.
 *   2. The 401 challenge advertised it regardless of the site's own level, so a
 *      spec-following client was told to ask for read-only.
 *   3. The consent screen rendered Allow/Deny, with no control that could grant
 *      more than the client asked for.
 *
 * The grant then stored `saddle:read` forever and no screen anywhere could raise
 * it. What is pinned here is the fix and, just as importantly, its limits: a
 * scope still only ever lowers the site tier, and an explicit narrow request is
 * still never silently widened.
 *
 * @package Saddle
 */

class Saddle_OAuth_Scope_Test extends WP_UnitTestCase {

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );

		add_action( 'rest_api_init', array( 'Saddle_OAuth_Endpoints', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'Saddle_OAuth_Clients', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'Saddle_REST_Admin', 'register_routes' ) );

		Saddle_OAuth_Store::register_cpt();

		do_action( 'rest_api_init', rest_get_server() );
	}

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );

		update_option( 'permalink_structure', '/%postname%/' );

		Saddle_OAuth_Store::register_cpt();
	}

	public function tear_down() {
		remove_filter( 'saddle_tier_ceiling', array( 'Saddle_OAuth_Bearer', 'tier_ceiling' ) );

		delete_option( 'permalink_structure' );
		delete_option( Saddle_Capabilities::OPTION );

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI'] );

		foreach ( array(
			'token_record' => null,
			'grant_scope'  => '',
		) as $property => $empty ) {
			$reset = new ReflectionProperty( 'Saddle_OAuth_Bearer', $property );
			$reset->setAccessible( true );
			$reset->setValue( null, $empty );
		}

		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	/**
	 * Run the authorize endpoint and return the scope it parked for consent.
	 *
	 * Stops at the pending request deliberately: this is the step that decides
	 * what the consent screen will be asked to approve, and it is where the bug
	 * lived.
	 *
	 * @param array $params Extra query params. Omit `scope` to send none at all.
	 * @return string The normalized scope on the pending request.
	 */
	private function pending_scope( array $params = array() ) {
		$client = new WP_REST_Request( 'POST', '/saddle/v1/oauth/register' );
		$client->set_body_params(
			array(
				'client_name'   => 'ChatGPT',
				'redirect_uris' => array( 'https://chatgpt.com/connector_callback' ),
			)
		);
		$registered = rest_get_server()->dispatch( $client );
		$this->assertSame( 201, $registered->get_status() );

		$verifier = str_repeat( 'a', 64 );

		$request = new WP_REST_Request( 'GET', '/saddle/v1/oauth/authorize' );
		$request->set_query_params(
			array_merge(
				array(
					'client_id'             => $registered->get_data()['client_id'],
					'redirect_uri'          => 'https://chatgpt.com/connector_callback',
					'response_type'         => 'code',
					'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
					'code_challenge_method' => 'S256',
					'state'                 => 'xyz',
					'resource'              => Saddle_OAuth::resource_id(),
				),
				$params
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 302, $response->get_status() );

		$headers = $response->get_headers();
		parse_str( (string) wp_parse_url( $headers['Location'], PHP_URL_QUERY ), $query );

		$pending = Saddle_OAuth_Store::get_request( $query['saddle_req'] );
		$this->assertNotNull( $pending );

		return (string) $pending['scope'];
	}

	/**
	 * Mint a grant plus a live access token, and make the request look like it
	 * is carrying that token to the MCP endpoint.
	 *
	 * @param string $scope Granted scope.
	 * @return string The grant id.
	 */
	private function connect( $scope ) {
		$grant_id = Saddle_OAuth::random_secret( 16 );
		$token    = Saddle_OAuth::random_secret( 32 );

		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => $grant_id,
				'client_id'   => 'https://chatgpt.com/client.json',
				'client_name' => 'ChatGPT',
				'user_id'     => $this->admin,
				'scope'       => $scope,
				'resource'    => Saddle_OAuth::resource_id(),
			)
		);

		Saddle_OAuth_Store::save_token(
			'access',
			$token,
			array(
				'grant_id'  => $grant_id,
				'client_id' => 'https://chatgpt.com/client.json',
				'user_id'   => $this->admin,
				'scope'     => $scope,
				'resource'  => Saddle_OAuth::resource_id(),
			)
		);

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$_SERVER['REQUEST_URI']        = '/wp-json/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE;

		return $grant_id;
	}

	/**
	 * The effective tier a request carrying that token would run at.
	 *
	 * @return string
	 */
	private function tier_now() {
		Saddle_OAuth_Bearer::resolve( false );

		return Saddle_Capabilities::get_tier();
	}

	/* ------------------------------------------------------------------
	 * What the site advertises
	 * --------------------------------------------------------------- */

	public function test_the_scope_for_a_tier_is_cumulative() {
		$this->assertSame( 'saddle:read', Saddle_OAuth::tier_to_scope( 'read' ) );
		$this->assertSame( 'saddle:read saddle:write', Saddle_OAuth::tier_to_scope( 'write' ) );
		$this->assertSame( 'saddle:read saddle:write saddle:admin', Saddle_OAuth::tier_to_scope( 'admin' ) );

		// An unknown name is not an invitation to guess upward.
		$this->assertSame( 'saddle:read', Saddle_OAuth::tier_to_scope( 'superuser' ) );
	}

	public function test_the_challenge_advertises_what_this_site_would_grant() {
		Saddle_Capabilities::set_tier( 'admin' );

		$response = new WP_REST_Response( array(), 401 );
		$request  = new WP_REST_Request( 'POST', '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE );
		$request->set_route( '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE );

		$challenged = Saddle_OAuth_Bearer::challenge( $response, rest_get_server(), $request );
		$headers    = $challenged->get_headers();

		$this->assertStringContainsString(
			'scope="saddle:read saddle:write saddle:admin"',
			$headers['WWW-Authenticate'],
			'Naming the read scope on a site set to admin is what told every spec-following client to ask for read-only.'
		);
	}

	public function test_the_challenge_still_says_read_on_a_read_site() {
		Saddle_Capabilities::set_tier( 'read' );

		$response = new WP_REST_Response( array(), 401 );
		$request  = new WP_REST_Request( 'POST', '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE );
		$request->set_route( '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE );

		$challenged = Saddle_OAuth_Bearer::challenge( $response, rest_get_server(), $request );
		$headers    = $challenged->get_headers();

		$this->assertStringContainsString( 'scope="saddle:read"', $headers['WWW-Authenticate'] );
	}

	/* ------------------------------------------------------------------
	 * What the authorize endpoint parks for consent
	 * --------------------------------------------------------------- */

	/**
	 * The bug itself. ChatGPT sends no `scope`, so the least-privilege fallback
	 * pinned it to read on a site the owner had set to admin, with no screen
	 * anywhere able to raise it afterwards.
	 */
	public function test_a_client_that_asks_for_no_scope_is_offered_the_sites_own_level() {
		Saddle_Capabilities::set_tier( 'admin' );

		$this->assertSame(
			'saddle:read saddle:write saddle:admin',
			$this->pending_scope(),
			'A client that expressed no preference must reach consent proposing what this site actually allows.'
		);
	}

	public function test_a_client_that_asks_for_no_scope_on_a_read_site_still_gets_read() {
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertSame( 'saddle:read', $this->pending_scope() );
	}

	/**
	 * The guardrail on the fix. Widening a request the client actually made
	 * would be a spec violation, and some clients compare the scope they get
	 * back against the one they asked for.
	 */
	public function test_an_explicitly_narrow_request_is_never_widened() {
		Saddle_Capabilities::set_tier( 'admin' );

		$this->assertSame(
			'saddle:read',
			$this->pending_scope( array( 'scope' => 'saddle:read' ) ),
			'An app that deliberately asked for read-only must be taken at its word.'
		);
	}

	public function test_scopes_saddle_does_not_grant_are_dropped_without_widening() {
		Saddle_Capabilities::set_tier( 'admin' );

		// Asking for something unrecognized is a preference, just not one we can
		// honour — it must not be treated as "asked for nothing".
		$this->assertSame(
			'saddle:read',
			$this->pending_scope( array( 'scope' => 'openid profile' ) )
		);
	}

	/* ------------------------------------------------------------------
	 * What the consent screen grants
	 * --------------------------------------------------------------- */

	public function test_the_consent_screen_offers_nothing_above_the_site_tier() {
		Saddle_Capabilities::set_tier( 'write' );

		$choices = new ReflectionMethod( 'Saddle_OAuth_Consent', 'level_choices' );
		$choices->setAccessible( true );

		$this->assertSame(
			array( 'read', 'write' ),
			array_keys( $choices->invoke( null, 'write' ) ),
			'Offering a level the tier system would then refuse is worse than offering no choice at all.'
		);
	}

	public function test_the_consent_screen_offers_every_level_on_an_admin_site() {
		$choices = new ReflectionMethod( 'Saddle_OAuth_Consent', 'level_choices' );
		$choices->setAccessible( true );

		$this->assertSame( array( 'read', 'write', 'admin' ), array_keys( $choices->invoke( null, 'admin' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Changing a connection that already exists
	 * --------------------------------------------------------------- */

	public function test_raising_a_connections_level_takes_effect_on_the_next_request() {
		Saddle_Capabilities::set_tier( 'admin' );
		$grant_id = $this->connect( 'saddle:read' );

		$this->assertSame( 'read', $this->tier_now(), 'The reported symptom: admin site, read connection.' );

		Saddle_OAuth_Store::set_grant_scope( $grant_id, Saddle_OAuth::tier_to_scope( 'write' ) );

		$this->assertSame(
			'write',
			$this->tier_now(),
			'Rewriting only the grant would leave this at read until the access token expired an hour later.'
		);
	}

	public function test_lowering_a_connections_level_is_immediate_even_before_its_token_is_reissued() {
		Saddle_Capabilities::set_tier( 'admin' );
		$grant_id = $this->connect( 'saddle:read saddle:write saddle:admin' );

		$this->assertSame( 'admin', $this->tier_now() );

		// Only the grant, as a stale or partially-applied write would leave it:
		// the token still carries admin. The lower of the two has to win.
		$post_id = ( new ReflectionMethod( 'Saddle_OAuth_Store', 'find' ) );
		$post_id->setAccessible( true );
		update_post_meta( $post_id->invoke( null, 'grant', $grant_id ), '_saddle_scope', 'saddle:read' );

		$this->assertSame( 'read', $this->tier_now() );
	}

	public function test_a_scope_still_cannot_exceed_the_site_tier() {
		Saddle_Capabilities::set_tier( 'read' );
		$this->connect( 'saddle:read saddle:write saddle:admin' );

		$this->assertSame(
			'read',
			$this->tier_now(),
			'A scope narrows what the site allows. It has never been able to widen it, and must not start now.'
		);
	}

	public function test_the_rest_route_refuses_a_level_above_the_site_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		$grant_id = $this->connect( 'saddle:read' );

		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth-connections/' . $grant_id );
		$request->set_body_params( array( 'level' => 'admin' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'saddle_oauth_above_site_tier', $response->get_data()['code'] );
		$this->assertSame( 'saddle:read', Saddle_OAuth_Store::get_grant( $grant_id )['scope'] );
	}

	public function test_the_rest_route_changes_the_level_and_every_token_with_it() {
		Saddle_Capabilities::set_tier( 'admin' );
		$grant_id = $this->connect( 'saddle:read' );

		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth-connections/' . $grant_id );
		$request->set_body_params( array( 'level' => 'write' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'write', $response->get_data()['level'] );
		$this->assertSame( 'saddle:read saddle:write', Saddle_OAuth_Store::get_grant( $grant_id )['scope'] );
		$this->assertSame( 'write', $this->tier_now() );
	}

	public function test_the_rest_route_is_administrators_only() {
		Saddle_Capabilities::set_tier( 'admin' );
		$grant_id = $this->connect( 'saddle:read' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth-connections/' . $grant_id );
		$request->set_body_params( array( 'level' => 'admin' ) );

		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'saddle:read', Saddle_OAuth_Store::get_grant( $grant_id )['scope'] );
	}

	public function test_changing_an_unknown_connection_is_a_404() {
		Saddle_Capabilities::set_tier( 'admin' );

		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth-connections/' . str_repeat( 'a', 32 ) );
		$request->set_body_params( array( 'level' => 'write' ) );

		$this->assertSame( 404, rest_get_server()->dispatch( $request )->get_status() );
	}

	/* ------------------------------------------------------------------
	 * What the agent is then offered
	 * --------------------------------------------------------------- */

	/**
	 * The end of the chain, and the thing the owner actually noticed: tools/list
	 * is filtered to what the credential could call, so a connection pinned to
	 * read makes every write tool disappear rather than fail loudly.
	 */
	public function test_raising_the_level_puts_the_write_tools_back_in_the_list() {
		Saddle_Capabilities::set_tier( 'admin' );
		$grant_id = $this->connect( 'saddle:read' );
		$this->tier_now();

		$this->assertFalse( Saddle_Capabilities::is_callable_now( 'saddle/create-post' ) );
		$this->assertTrue( Saddle_Capabilities::is_callable_now( 'saddle/list-posts' ) );

		Saddle_OAuth_Store::set_grant_scope( $grant_id, Saddle_OAuth::tier_to_scope( 'write' ) );
		$this->tier_now();

		$this->assertTrue( Saddle_Capabilities::is_callable_now( 'saddle/create-post' ) );
	}
}
