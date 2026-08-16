<?php
/**
 * The OAuth 2.1 authorization flow, end to end.
 *
 * What this pins: an app can only get a token by going all the way through
 * registration, an administrator's explicit approval, and a PKCE-verified code
 * exchange — and every shortcut around that is refused. These are the
 * properties that make it safe to expose unauthenticated OAuth endpoints on a
 * public WordPress site at all.
 *
 * @package Saddle
 */

class Saddle_OAuth_Flow_Test extends WP_UnitTestCase {

	private $admin;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Enable via filter rather than option so the state survives the
		// per-test transaction rollback, and register on the real hook before
		// the first REST server build — register_rest_route() warns when called
		// outside rest_api_init. Installing these before WP_UnitTestCase
		// snapshots the filter table means every test starts identically.
		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );

		add_action( 'rest_api_init', array( 'Saddle_OAuth_Endpoints', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'Saddle_OAuth_Clients', 'register_routes' ) );

		Saddle_OAuth_Store::register_cpt();

		// Re-fire the action on the EXISTING server rather than rebuilding one.
		// Another suite may already have built it, in which case rest_api_init
		// has fired and our routes would never register — but discarding the
		// server would also discard the MCP route, which the adapter only
		// registers once per process (on mcp_adapter_init).
		do_action( 'rest_api_init', rest_get_server() );
	}

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Re-added per test — see the note in set_up_before_class; the filter
		// table is restored to a process-wide snapshot after every test.
		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );

		// Pretty permalinks are a hard requirement — without them rest_url()
		// yields a query string, which cannot be a canonical resource identifier.
		update_option( 'permalink_structure', '/%postname%/' );

		Saddle_OAuth_Store::register_cpt();
	}

	public function tear_down() {
		delete_option( 'permalink_structure' );
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	private function register_client( $redirect = 'https://chatgpt.com/connector_callback' ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/register' );
		$request->set_body_params(
			array(
				'client_name'   => 'ChatGPT',
				'redirect_uris' => array( $redirect ),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );

		return $response->get_data();
	}

	private function verifier() {
		return str_repeat( 'a', 64 );
	}

	private function challenge_for( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Drive authorize → consent → allow, returning the authorization code.
	 *
	 * @param string $client_id Registered client id.
	 * @param string $challenge PKCE challenge.
	 * @param string $scope     Requested scope.
	 * @param string $redirect  Redirect URI.
	 * @return string Authorization code.
	 */
	private function authorize( $client_id, $challenge, $scope = 'saddle:read', $redirect = 'https://chatgpt.com/connector_callback' ) {
		$request = new WP_REST_Request( 'GET', '/saddle/v1/oauth/authorize' );
		$request->set_query_params(
			array(
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect,
				'response_type'         => 'code',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'scope'                 => $scope,
				'state'                 => 'xyz',
				'resource'              => Saddle_OAuth::resource_id(),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 302, $response->get_status() );

		$headers = $response->get_headers();
		parse_str( (string) wp_parse_url( $headers['Location'], PHP_URL_QUERY ), $query );
		$this->assertNotEmpty( $query['saddle_req'] );

		// Approve as the administrator would on the consent screen. The grant and
		// code are minted by the same store calls the handler makes.
		wp_set_current_user( $this->admin );
		$pending = Saddle_OAuth_Store::consume_request( $query['saddle_req'] );
		$this->assertNotNull( $pending );

		$grant_id = Saddle_OAuth::random_secret( 16 );
		$code     = Saddle_OAuth::random_secret( 32 );

		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => $grant_id,
				'client_id'   => $pending['client_id'],
				'client_name' => $pending['client_name'],
				'user_id'     => $this->admin,
				'scope'       => $pending['scope'],
				'resource'    => $pending['resource'],
			)
		);

		Saddle_OAuth_Store::save_code(
			$code,
			array(
				'grant_id'       => $grant_id,
				'client_id'      => $pending['client_id'],
				'redirect_uri'   => $pending['redirect_uri'],
				'scope'          => $pending['scope'],
				'resource'       => $pending['resource'],
				'code_challenge' => $pending['code_challenge'],
				'user_id'        => $this->admin,
			)
		);

		return $code;
	}

	private function exchange( $client_id, $code, $verifier, $redirect = 'https://chatgpt.com/connector_callback' ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client_id,
				'redirect_uri'  => $redirect,
				'code_verifier' => $verifier,
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/* ------------------------------------------------------------------
	 * Registration
	 * --------------------------------------------------------------- */

	public function test_registration_issues_a_public_client_with_no_secret() {
		$client = $this->register_client();

		$this->assertNotEmpty( $client['client_id'] );
		$this->assertSame( 'none', $client['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey(
			'client_secret',
			$client,
			'Public clients use PKCE; issuing a secret would create a leak surface for nothing.'
		);
	}

	public function test_registration_refuses_a_cleartext_redirect_to_a_real_host() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/register' );
		$request->set_body_params(
			array(
				'client_name'   => 'Evil',
				'redirect_uris' => array( 'http://evil.example.com/cb' ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_redirect_uri', $response->get_data()['error'] );
	}

	public function test_registration_allows_loopback_for_native_apps() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/register' );
		$request->set_body_params(
			array(
				'client_name'   => 'Local CLI',
				'redirect_uris' => array( 'http://127.0.0.1:8080/cb' ),
			)
		);

		$this->assertSame( 201, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_registration_alone_grants_nothing() {
		$client = $this->register_client();

		$this->assertSame(
			array(),
			Saddle_OAuth_Store::list_grants(),
			'Registering must not create any authorization — only a consent screen can.'
		);
		$this->assertNull( Saddle_OAuth_Store::get_access_token( $client['client_id'] ) );
	}

	/* ------------------------------------------------------------------
	 * Authorize
	 * --------------------------------------------------------------- */

	public function test_authorize_refuses_an_unregistered_redirect_without_redirecting() {
		$client = $this->register_client();

		$request = new WP_REST_Request( 'GET', '/saddle/v1/oauth/authorize' );
		$request->set_query_params(
			array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => 'https://attacker.example/steal',
				'response_type'         => 'code',
				'code_challenge'        => $this->challenge_for( $this->verifier() ),
				'code_challenge_method' => 'S256',
			)
		);

		// Refusing here must NOT redirect — bouncing an error to an unverified
		// address would make this endpoint an open redirector.
		$this->expectException( 'WPDieException' );
		rest_get_server()->dispatch( $request );
	}

	/**
	 * `/authorize` is unauthenticated by necessity, and every accepted request
	 * parks a record (post + ~10 postmeta) that the hourly GC only sweeps 200 at
	 * a time — so without a ceiling one registered client_id is enough to drive
	 * unbounded anonymous DB writes, and unbounded outbound client-metadata
	 * fetches with them. Pin the ceiling.
	 */
	public function test_authorize_is_rate_limited_per_ip() {
		$client = $this->register_client();

		// Spend this hour's per-IP allowance without going through the endpoint,
		// so the test doesn't depend on how many records a real run would write.
		$window = (int) floor( time() / HOUR_IN_SECONDS );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		set_transient(
			'saddle_oauth_authz_' . md5( (string) $ip ) . '_' . $window,
			Saddle_OAuth_Endpoints::AUTHORIZE_PER_IP,
			HOUR_IN_SECONDS
		);

		$request = new WP_REST_Request( 'GET', '/saddle/v1/oauth/authorize' );
		$request->set_query_params(
			array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => $client['redirect_uris'][0],
				'response_type'         => 'code',
				'code_challenge'        => $this->challenge_for( $this->verifier() ),
				'code_challenge_method' => 'S256',
			)
		);

		// Refused as a rendered page, never as a redirect — the same rule that
		// keeps this endpoint from being an open redirector applies to being
		// throttled.
		$this->expectException( 'WPDieException' );
		rest_get_server()->dispatch( $request );
	}

	/**
	 * The ceiling must be well clear of one person approving one app, or the
	 * throttle would break the flow it protects.
	 */
	public function test_authorize_allows_a_normal_approval_flow() {
		$client = $this->register_client();

		$request = new WP_REST_Request( 'GET', '/saddle/v1/oauth/authorize' );
		$request->set_query_params(
			array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => $client['redirect_uris'][0],
				'response_type'         => 'code',
				'code_challenge'        => $this->challenge_for( $this->verifier() ),
				'code_challenge_method' => 'S256',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 302, $response->get_status(), 'A first, ordinary authorize must not be throttled.' );
	}

	public function test_authorize_requires_pkce_s256() {
		$client = $this->register_client();

		foreach ( array( '', 'plain' ) as $method ) {
			$request = new WP_REST_Request( 'GET', '/saddle/v1/oauth/authorize' );
			$request->set_query_params(
				array(
					'client_id'             => $client['client_id'],
					'redirect_uri'          => 'https://chatgpt.com/connector_callback',
					'response_type'         => 'code',
					'code_challenge'        => 'whatever',
					'code_challenge_method' => $method,
				)
			);

			$response = rest_get_server()->dispatch( $request );
			$headers  = $response->get_headers();

			$this->assertSame( 302, $response->get_status() );
			$this->assertStringContainsString( 'error=invalid_request', $headers['Location'] );
		}
	}

	public function test_authorize_names_itself_in_the_response() {
		$client = $this->register_client();

		$request = new WP_REST_Request( 'GET', '/saddle/v1/oauth/authorize' );
		$request->set_query_params(
			array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => 'https://chatgpt.com/connector_callback',
				'response_type'         => 'token',
				'code_challenge'        => $this->challenge_for( $this->verifier() ),
				'code_challenge_method' => 'S256',
				'state'                 => 'xyz',
			)
		);

		$headers = rest_get_server()->dispatch( $request )->get_headers();

		// RFC 9207 — the client needs to know which server answered.
		$this->assertStringContainsString( 'iss=', $headers['Location'] );
		$this->assertStringContainsString( 'state=xyz', $headers['Location'] );
	}

	/* ------------------------------------------------------------------
	 * Token exchange
	 * --------------------------------------------------------------- */

	public function test_full_flow_issues_a_usable_token_pair() {
		$client   = $this->register_client();
		$verifier = $this->verifier();
		$code     = $this->authorize( $client['client_id'], $this->challenge_for( $verifier ) );

		$response = $this->exchange( $client['client_id'], $code, $verifier );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Bearer', $data['token_type'] );
		$this->assertSame( 'saddle:read', $data['scope'] );
		$this->assertNotEmpty( $data['access_token'] );
		$this->assertNotEmpty( $data['refresh_token'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_wrong_pkce_verifier_is_refused() {
		$client = $this->register_client();
		$code   = $this->authorize( $client['client_id'], $this->challenge_for( $this->verifier() ) );

		$response = $this->exchange( $client['client_id'], $code, str_repeat( 'b', 64 ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	public function test_short_verifier_is_refused_even_if_it_hashes_correctly() {
		$client = $this->register_client();
		$short  = 'abc';
		$code   = $this->authorize( $client['client_id'], $this->challenge_for( $short ) );

		// The digest would match, but RFC 7636 sets a 43-character floor and a
		// three-character verifier offers no protection at all.
		$response = $this->exchange( $client['client_id'], $code, $short );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	public function test_redirect_uri_must_match_the_one_authorized() {
		$client   = $this->register_client();
		$verifier = $this->verifier();
		$code     = $this->authorize( $client['client_id'], $this->challenge_for( $verifier ) );

		$response = $this->exchange( $client['client_id'], $code, $verifier, 'https://chatgpt.com/other' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	public function test_replaying_a_code_revokes_every_token_it_produced() {
		$client   = $this->register_client();
		$verifier = $this->verifier();
		$code     = $this->authorize( $client['client_id'], $this->challenge_for( $verifier ) );

		$first = $this->exchange( $client['client_id'], $code, $verifier );
		$this->assertSame( 200, $first->get_status() );
		$access = $first->get_data()['access_token'];
		$this->assertNotNull( Saddle_OAuth_Store::get_access_token( $access ) );

		$second = $this->exchange( $client['client_id'], $code, $verifier );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'invalid_grant', $second->get_data()['error'] );

		// Two parties held one code. There is no way to tell which one was the
		// thief, so the whole connection goes.
		$this->assertNull(
			Saddle_OAuth_Store::get_access_token( $access ),
			'A replayed code must revoke the tokens already issued from it.'
		);
	}

	public function test_refresh_rotates_and_reuse_revokes_the_connection() {
		$client   = $this->register_client();
		$verifier = $this->verifier();
		$code     = $this->authorize( $client['client_id'], $this->challenge_for( $verifier ) );

		$tokens  = $this->exchange( $client['client_id'], $code, $verifier )->get_data();
		$refresh = $tokens['refresh_token'];

		$again = new WP_REST_Request( 'POST', '/saddle/v1/oauth/token' );
		$again->set_body_params(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $client['client_id'],
			)
		);
		$rotated = rest_get_server()->dispatch( $again );

		$this->assertSame( 200, $rotated->get_status() );
		$this->assertNotSame( $refresh, $rotated->get_data()['refresh_token'], 'Refresh tokens must rotate.' );

		// Presenting the spent one means it leaked.
		$replay = rest_get_server()->dispatch( $again );
		$this->assertSame( 400, $replay->get_status() );
		$this->assertNull(
			Saddle_OAuth_Store::get_access_token( $rotated->get_data()['access_token'] ),
			'Reusing a refresh token must revoke the whole connection.'
		);
	}

	public function test_refresh_cannot_widen_scope() {
		$client   = $this->register_client();
		$verifier = $this->verifier();
		$code     = $this->authorize( $client['client_id'], $this->challenge_for( $verifier ) );
		$tokens   = $this->exchange( $client['client_id'], $code, $verifier )->get_data();

		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
				'client_id'     => $client['client_id'],
				'scope'         => 'saddle:admin',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_scope', $response->get_data()['error'] );
	}

	public function test_unsupported_grant_type_is_named_as_such() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/token' );
		$request->set_body_params( array( 'grant_type' => 'password' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unsupported_grant_type', $response->get_data()['error'] );
	}

	public function test_token_errors_never_challenge_with_basic() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/token' );
		$request->set_body_params( array( 'grant_type' => 'authorization_code', 'code' => 'nope' ) );

		$headers = rest_get_server()->dispatch( $request )->get_headers();

		// A Basic challenge would make a browser pop a credential dialog on a
		// URL people do sometimes open by hand.
		$this->assertStringNotContainsStringIgnoringCase( 'Basic', isset( $headers['WWW-Authenticate'] ) ? $headers['WWW-Authenticate'] : '' );
	}

	/* ------------------------------------------------------------------
	 * Revocation
	 * --------------------------------------------------------------- */

	public function test_revoking_a_token_kills_the_whole_connection() {
		$client   = $this->register_client();
		$verifier = $this->verifier();
		$code     = $this->authorize( $client['client_id'], $this->challenge_for( $verifier ) );
		$tokens   = $this->exchange( $client['client_id'], $code, $verifier )->get_data();

		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/revoke' );
		$request->set_body_params( array( 'token' => $tokens['access_token'] ) );

		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertNull( Saddle_OAuth_Store::get_access_token( $tokens['access_token'] ) );
		$this->assertSame( array(), Saddle_OAuth_Store::list_grants() );
	}

	public function test_revoking_an_unknown_token_still_answers_200() {
		$request = new WP_REST_Request( 'POST', '/saddle/v1/oauth/revoke' );
		$request->set_body_params( array( 'token' => 'never-existed' ) );

		// RFC 7009. An endpoint that distinguished "revoked" from "unknown"
		// would be a token oracle.
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}
}
