<?php
/**
 * verify-page with check_public: does the live page serve what was saved (#221)?
 *
 * The loopback is faked at pre_http_request, so each test states exactly
 * what the "public page" returned.
 *
 * @package Saddle
 */

class Saddle_Verify_Public_Test extends WP_UnitTestCase {

	const PASSAGE = 'Saddle lets your AI work on live WordPress sites without handing it the keys.';

	/**
	 * Requests the loopback made, with their args.
	 *
	 * @var array[]
	 */
	private $requests = array();

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'permalink_structure', '/%postname%/' );
		$this->requests = array();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_shortcode( 'saddle_test_greet' );
		delete_option( 'permalink_structure' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function page( $content, $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_content' => $content,
			)
		);
	}

	/**
	 * The "public page" returns this body (or error).
	 *
	 * @param string|WP_Error $body HTML, or an error for a failed fetch.
	 * @param int             $code HTTP status.
	 */
	private function public_page_serves( $body, $code = 200 ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body, $code ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				if ( is_wp_error( $body ) ) {
					return $body;
				}
				return array(
					'body'     => $body,
					'response' => array( 'code' => $code ),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	private function verify( $id, $check_public = true ) {
		$result = wp_get_ability( 'saddle/verify-page' )->execute(
			array(
				'post_id'      => $id,
				'check_public' => $check_public,
			)
		);
		$this->assertNotWPError( $result );
		return $result;
	}

	public function test_a_page_serving_the_saved_content_reports_served() {
		$id = $this->page( '<!-- wp:paragraph --><p>' . self::PASSAGE . '</p><!-- /wp:paragraph -->' );
		$this->public_page_serves( '<html><body><header>Site</header><main><p>' . esc_html( self::PASSAGE ) . '</p></main></body></html>' );

		$result = $this->verify( $id );

		$this->assertSame( 'served', $result['public']['status'] );
		$this->assertSame( array(), $result['public']['missing'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( get_permalink( $id ), $this->requests[0]['url'] );
		$this->assertSame( array(), $this->requests[0]['args']['cookies'], 'Fetched as a visitor: no session cookie.' );
	}

	/**
	 * The complaint this exists for: the write read back fine and the public
	 * page still served the old copy.
	 */
	public function test_a_cached_old_copy_is_reported_stale_never_passed() {
		$id = $this->page( '<!-- wp:paragraph --><p>' . self::PASSAGE . '</p><!-- /wp:paragraph -->' );
		$this->public_page_serves( '<html><body><p>Yesterday\'s headline, still in the page cache.</p></body></html>' );

		$result = $this->verify( $id );

		$this->assertSame( 'stale', $result['public']['status'] );
		$this->assertNotEmpty( $result['public']['missing'] );
		$this->assertStringContainsString( 'flush-cache', $result['note'] );
		$this->assertArrayHasKey( 'score', $result, 'The score is still reported; staleness does not change it.' );
	}

	public function test_it_is_off_unless_asked() {
		$id = $this->page( '<!-- wp:paragraph --><p>' . self::PASSAGE . '</p><!-- /wp:paragraph -->' );
		$this->public_page_serves( '<p>anything</p>' );

		$result = $this->verify( $id, false );

		$this->assertArrayNotHasKey( 'public', $result );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_draft_is_skipped_with_the_reason() {
		$id = $this->page( '<!-- wp:paragraph --><p>' . self::PASSAGE . '</p><!-- /wp:paragraph -->', 'draft' );
		$this->public_page_serves( '<p>anything</p>' );

		$result = $this->verify( $id );

		$this->assertFalse( $result['public']['checked'] );
		$this->assertNotEmpty( $result['public']['reason'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_failed_fetch_is_unreachable_not_stale() {
		$id = $this->page( '<!-- wp:paragraph --><p>' . self::PASSAGE . '</p><!-- /wp:paragraph -->' );
		$this->public_page_serves( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );

		$this->assertSame( 'unreachable', $this->verify( $id )['public']['status'] );
	}

	public function test_an_error_status_is_unreachable() {
		$id = $this->page( '<!-- wp:paragraph --><p>' . self::PASSAGE . '</p><!-- /wp:paragraph -->' );
		$this->public_page_serves( '<p>Service Unavailable</p>', 503 );

		$this->assertSame( 'unreachable', $this->verify( $id )['public']['status'] );
	}

	/**
	 * The saved page is rendered as a visitor. A greeting only a signed-in
	 * user sees must not become a passage the public page "lacks".
	 */
	public function test_signed_in_only_content_does_not_read_as_stale() {
		add_shortcode(
			'saddle_test_greet',
			static function () {
				return is_user_logged_in() ? '<p>Welcome back to the members area, dear friend of the site.</p>' : '';
			}
		);
		$id = $this->page( '<!-- wp:paragraph --><p>' . self::PASSAGE . '</p><!-- /wp:paragraph -->[saddle_test_greet]' );
		$this->public_page_serves( '<main><p>' . esc_html( self::PASSAGE ) . '</p></main>' );

		$result = $this->verify( $id );

		$this->assertSame( 'served', $result['public']['status'] );
		$this->assertTrue( is_user_logged_in(), 'The caller is signed back in afterwards.' );
	}

	public function test_the_fetch_refuses_any_host_but_this_site() {
		$this->public_page_serves( '<p>anything</p>' );
		$home = wp_parse_url( home_url() );

		$this->assertSame( 'saddle_http_not_own_site', Saddle_HTTP::fetch_own_page( 'https://evil.example/' )->get_error_code() );
		$this->assertSame(
			'saddle_http_not_own_site',
			Saddle_HTTP::fetch_own_page( $home['scheme'] . '://' . $home['host'] . '@evil.example/' )->get_error_code(),
			'Userinfo that looks like this site is still another host.'
		);
		$this->assertSame( array(), $this->requests, 'Refused before any request is made.' );
	}
}
