<?php
/**
 * saddle/create-upload-link and the address it returns (#412).
 *
 * upload-media takes a public URL or base64 text, so an agent could not send
 * a local file of normal size. These drive the tool and the route end to end:
 * the link is made by a signed-in key, and the file arrives on a request that
 * carries no credential at all, as it does from curl.
 *
 * @package Saddle
 */

class Saddle_Upload_Link_Test extends WP_UnitTestCase {

	/**
	 * A valid 1x1 PNG, base64.
	 */
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/** @var int */
	private $admin;

	/** @var string The key's uuid. */
	private $uuid;

	/** @var string|null REQUEST_URI before a bearer sign-in. */
	private $request_uri = null;

	/** @var int[] Attachments created, so their files leave the uploads dir too. */
	private $created = array();

	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['wp_rest_application_password_uuid'] );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );

		list( , $item ) = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . 'Cursor' ) );
		Saddle_Connection::mark_issued( $this->admin, $item['uuid'] );
		$this->uuid = $item['uuid'];
		Saddle_Access::set_role( 'key:' . $this->uuid, 'write' );

		$this->sign_in();
	}

	public function tear_down() {
		foreach ( $this->created as $id ) {
			wp_delete_attachment( $id, true );
		}
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		remove_all_filters( 'saddle_max_upload_bytes' );
		Saddle_Capabilities::set_paused( false );
		Saddle_Capabilities::set_rehearsal( false );
		Saddle_Capabilities::set_disabled_abilities( array() );
		foreach ( array( Saddle_Access::KEY_ROLES_OPTION, Saddle_Access::VERSION_OPTION, Saddle_Capabilities::OPTION, Saddle_Connections::OPTION, Saddle_Tool_Modes::ASK_OPTION ) as $option ) {
			delete_option( $option );
		}
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * Look signed in with the key, as core leaves a request after
	 * authenticating an Application Password.
	 */
	private function sign_in() {
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_application_password_uuid'] = $this->uuid;
	}

	/**
	 * Look like curl: no user, no credential.
	 */
	private function sign_out() {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		wp_set_current_user( 0 );
	}

	private function png() {
		return base64_decode( self::PNG );
	}

	private function link( array $input = array() ) {
		return wp_get_ability( 'saddle/create-upload-link' )->execute(
			$input + array(
				'filename' => 'pixel.png',
				'size'     => strlen( $this->png() ),
			)
		);
	}

	/**
	 * Send bytes to a link's address with no sign-in, the way the command does.
	 *
	 * @return WP_REST_Response
	 */
	private function send( $url, $bytes, $method = 'PUT' ) {
		$this->sign_out();

		$route   = '/' . Saddle_MCP::REST_NAMESPACE . '/upload/' . $this->token( $url );
		$request = new WP_REST_Request( $method, $route );
		$request->set_body( $bytes );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		if ( is_array( $data ) && isset( $data['id'] ) && 200 === $response->get_status() ) {
			$this->created[] = (int) $data['id'];
		}

		return $response;
	}

	private function attachments() {
		return count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * The token at the end of a link, whichever permalink form the site uses
	 * (`/wp-json/…/<token>` or `?rest_route=/…/<token>`).
	 */
	private function token( $url ) {
		return preg_match( '/([A-Za-z0-9]+)$/', (string) $url, $m ) ? $m[1] : '';
	}

	/* ------------------------------------------------------- the happy path */

	public function test_a_link_takes_the_file_and_stores_it_as_the_agents_user() {
		$link = $this->link( array( 'alt' => 'A single pixel', 'title' => 'Pixel' ) );

		$this->assertNotWPError( $link );
		$this->assertStringStartsWith( rest_url( 'saddle/v1/upload/' ), $link['upload_url'] );
		$this->assertSame( 'PUT', $link['method'] );
		$this->assertSame( Saddle_Upload_Link::TTL, $link['expires_in'] );
		$this->assertStringContainsString( 'curl', $link['command'] );
		$this->assertStringContainsString( $link['upload_url'], $link['command'] );

		$response = $this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 200, $response->get_status() );
		$media = $response->get_data();
		$this->assertSame( 'image/png', get_post_mime_type( $media['id'] ) );
		$this->assertSame( $this->admin, (int) get_post_field( 'post_author', $media['id'] ) );
		$this->assertSame( 'Pixel', get_the_title( $media['id'] ) );
		$this->assertSame( 'A single pixel', get_post_meta( $media['id'], '_wp_attachment_image_alt', true ) );
		$this->assertFileExists( get_attached_file( $media['id'] ) );
	}

	public function test_the_request_that_delivers_the_file_stays_signed_out() {
		$link = $this->link();
		$this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 0, get_current_user_id(), 'The route acts as the link\'s user for the upload only.' );
	}

	public function test_the_upload_is_logged_under_the_agent_that_asked_for_the_link() {
		$link = $this->link();
		$this->send( $link['upload_url'], $this->png() );

		$entry = Saddle_Log::query( 1, 1 )['entries'][0];
		$this->assertSame( 'upload-media', $entry['action'] );
		$this->assertSame( 'Cursor', $entry['app'] );
	}

	public function test_post_works_as_well_as_put() {
		$link = $this->link();

		$this->assertSame( 200, $this->send( $link['upload_url'], $this->png(), 'POST' )->get_status() );
	}

	/* ------------------------------------------------------------ single use */

	public function test_a_link_works_once() {
		$link = $this->link();
		$this->send( $link['upload_url'], $this->png() );
		$before = $this->attachments();

		$again = $this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 403, $again->get_status() );
		$this->assertSame( 'saddle_upload_link_invalid', $again->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_an_expired_link_is_refused() {
		$link = $this->link();
		$key  = Saddle_Upload_Link::TRANSIENT . hash( 'sha256', $this->token( $link['upload_url'] ) );
		$row  = get_transient( $key );
		$this->assertIsArray( $row );
		$row['expires'] = time() - 1;
		set_transient( $key, $row, MINUTE_IN_SECONDS );
		$before = $this->attachments();

		$response = $this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'saddle_upload_link_invalid', $response->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_an_unknown_token_gets_the_same_answer_as_a_used_one() {
		$this->link();

		$response = $this->send( rest_url( 'saddle/v1/upload/' . str_repeat( 'a', Saddle_Upload_Link::TOKEN_LENGTH ) ), $this->png() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'saddle_upload_link_invalid', $response->get_data()['code'] );
	}

	public function test_only_a_hash_of_the_token_is_stored() {
		$link  = $this->link();
		$token = $this->token( $link['upload_url'] );

		$this->assertSame( Saddle_Upload_Link::TOKEN_LENGTH, strlen( $token ) );
		$this->assertFalse( get_transient( Saddle_Upload_Link::TRANSIENT . $token ) );
		$row = get_transient( Saddle_Upload_Link::TRANSIENT . hash( 'sha256', $token ) );
		$this->assertIsArray( $row );
		$this->assertStringNotContainsString( $token, (string) wp_json_encode( $row ) );
		$this->assertSame( 'key:' . $this->uuid, $row['connection'] );
	}

	/* --------------------------------------------------- what the file must be */

	public function test_a_body_of_another_size_is_refused_and_uses_the_link_up() {
		$link   = $this->link();
		$before = $this->attachments();

		$response = $this->send( $link['upload_url'], $this->png() . 'x' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'saddle_upload_size_mismatch', $response->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
		$this->assertSame( 403, $this->send( $link['upload_url'], $this->png() )->get_status() );
	}

	public function test_content_that_is_not_what_the_extension_says_is_refused_by_wordpress() {
		$php    = '<?php echo "not an image";';
		$link   = $this->link( array( 'size' => strlen( $php ) ) );
		$before = $this->attachments();

		$response = $this->send( $link['upload_url'], $php );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_no_link_is_made_for_a_type_the_site_does_not_allow() {
		$link = $this->link( array( 'filename' => 'run.exe' ) );

		$this->assertWPError( $link );
		$this->assertSame( 'saddle_file_type_not_allowed', $link->get_error_code() );
	}

	public function test_no_link_is_made_for_a_file_over_the_limit() {
		add_filter(
			'saddle_max_upload_bytes',
			static function () {
				return 10;
			}
		);

		$link = $this->link();

		$this->assertWPError( $link );
		$this->assertSame( 'saddle_file_too_large', $link->get_error_code() );
	}

	public function test_a_filename_and_a_size_are_required() {
		$this->assertWPError( wp_get_ability( 'saddle/create-upload-link' )->execute( array( 'filename' => 'pixel.png' ) ) );
		$this->assertWPError( wp_get_ability( 'saddle/create-upload-link' )->execute( array( 'size' => 10 ) ) );
	}

	/* --------------------------------------------------------------- the gate */

	public function test_the_tool_is_refused_below_the_write_tier() {
		Saddle_Access::set_role( 'key:' . $this->uuid, 'read' );

		$this->assertWPError( $this->link() );
	}

	public function test_a_link_made_before_the_tool_was_switched_off_uploads_nothing() {
		$link   = $this->link();
		$before = $this->attachments();
		Saddle_Capabilities::set_disabled_abilities( array( 'create-upload-link' ) );

		$response = $this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'saddle_upload_link_revoked', $response->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_a_link_made_before_the_agent_was_lowered_to_read_only_uploads_nothing() {
		$link   = $this->link();
		$before = $this->attachments();
		Saddle_Access::set_role( 'key:' . $this->uuid, 'read' );

		$response = $this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 'saddle_upload_link_revoked', $response->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_a_link_made_before_the_agent_was_disconnected_uploads_nothing() {
		$link   = $this->link();
		$before = $this->attachments();
		WP_Application_Passwords::delete_application_password( $this->admin, $this->uuid );

		$response = $this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 'saddle_upload_link_revoked', $response->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_a_link_made_before_saddle_was_paused_uploads_nothing() {
		$link   = $this->link();
		$before = $this->attachments();
		Saddle_Capabilities::set_paused( true );

		$response = $this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 'saddle_upload_link_revoked', $response->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_a_link_made_by_a_user_who_can_no_longer_upload_uploads_nothing() {
		$link   = $this->link();
		$before = $this->attachments();
		get_userdata( $this->admin )->set_role( 'subscriber' );

		$response = $this->send( $link['upload_url'], $this->png() );

		$this->assertSame( 'saddle_upload_link_revoked', $response->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_a_refused_upload_is_logged_as_denied() {
		$link = $this->link();
		Saddle_Capabilities::set_paused( true );
		$this->send( $link['upload_url'], $this->png() );

		$denied = Saddle_Log::query( 5, 1, 'denied' )['entries'];
		$this->assertNotEmpty( $denied );
		$this->assertSame( 'create-upload-link', $denied[0]['action'] );
	}

	public function test_ask_first_holds_the_link_until_the_owner_approves() {
		Saddle_Tool_Modes::set_mode( 'create-upload-link', Saddle_Tool_Modes::ASK );

		$result = $this->link();

		$this->assertFalse( is_array( $result ) && isset( $result['upload_url'] ), 'No link exists before the owner says yes.' );
	}

	public function test_rehearsal_makes_no_link() {
		Saddle_Capabilities::set_rehearsal( true );

		$result = $this->link();

		$this->assertFalse( is_array( $result ) && isset( $result['upload_url'] ) );
	}

	public function test_a_connection_cannot_pile_up_open_links() {
		for ( $i = 0; $i < Saddle_Upload_Link::MAX_OPEN; $i++ ) {
			$this->assertNotWPError( $this->link() );
		}

		$one_more = $this->link();

		$this->assertWPError( $one_more );
		$this->assertSame( 'saddle_too_many_upload_links', $one_more->get_error_code() );
	}

	public function test_a_used_link_frees_its_place() {
		for ( $i = 0; $i < Saddle_Upload_Link::MAX_OPEN; $i++ ) {
			$link = $this->link();
		}
		$this->send( $link['upload_url'], $this->png() );
		$this->sign_in();

		$this->assertNotWPError( $this->link() );
	}

	/* ------------------------------------------------------ an OAuth connection */

	/**
	 * Sign in with an OAuth grant, as the bearer resolver does on a request.
	 *
	 * @return string The grant id.
	 */
	private function sign_in_with_grant( $scope ) {
		Saddle_OAuth_Store::register_cpt();
		add_filter( 'saddle_oauth_enabled', '__return_true' );
		add_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		update_option( 'permalink_structure', '/%postname%/' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		$grant = Saddle_OAuth::random_secret( 16 );
		$token = Saddle_OAuth::random_secret( 32 );
		$args  = array(
			'grant_id'  => $grant,
			'client_id' => 'saddle_test',
			'user_id'   => $this->admin,
			'scope'     => $scope,
			'resource'  => Saddle_OAuth::resource_id(),
		);
		Saddle_OAuth_Store::save_grant( $args + array( 'client_name' => 'ChatGPT' ) );
		Saddle_OAuth_Store::save_token( 'access', $token, $args );

		$this->request_uri             = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$_SERVER['REQUEST_URI']        = '/wp-json/saddle/v1/mcp';
		wp_set_current_user( Saddle_OAuth_Bearer::resolve( false ) );

		return $grant;
	}

	/**
	 * Drop the bearer sign-in, so the next request carries no credential.
	 */
	private function sign_out_of_grant() {
		remove_filter( 'saddle_tier_ceiling', array( 'Saddle_OAuth_Bearer', 'tier_ceiling' ) );
		remove_filter( 'saddle_oauth_enabled', '__return_true' );
		remove_filter( 'saddle_oauth_readiness_ssl', '__return_true' );
		delete_option( 'permalink_structure' );

		foreach ( array(
			'token_record' => null,
			'grant_scope'  => '',
		) as $property => $empty ) {
			$reset = new ReflectionProperty( 'Saddle_OAuth_Bearer', $property );
			$reset->setAccessible( true );
			$reset->setValue( null, $empty );
		}

		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
	}

	public function test_a_link_made_over_oauth_uploads_and_is_refused_once_the_grant_is_revoked() {
		$grant  = $this->sign_in_with_grant( Saddle_OAuth::tier_to_scope( 'write' ) );
		$first  = $this->link();
		$second = $this->link();
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->sign_out_of_grant();

		$this->assertSame( 200, $this->send( $first['upload_url'], $this->png() )->get_status() );

		Saddle_OAuth_Store::revoke_grant( $grant );
		$before   = $this->attachments();
		$response = $this->send( $second['upload_url'], $this->png() );

		$this->assertSame( 'saddle_upload_link_revoked', $response->get_data()['code'] );
		$this->assertSame( $before, $this->attachments() );
	}

	/* ------------------------------------------------- upload-media unchanged */

	public function test_upload_media_with_data_still_lands_in_the_library() {
		$result = wp_get_ability( 'saddle/upload-media' )->execute(
			array(
				'data'     => self::PNG,
				'filename' => 'pixel.png',
			)
		);

		$this->assertNotWPError( $result );
		$this->created[] = (int) $result['id'];
		$this->assertSame( 'image/png', get_post_mime_type( $result['id'] ) );
	}
}
