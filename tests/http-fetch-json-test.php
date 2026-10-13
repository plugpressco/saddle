<?php
/**
 * Saddle_HTTP::fetch_json() refuses a host it cannot clear, and says which of
 * two things went wrong (#335): the host could not be looked up at all (PHP on
 * WordPress Playground and Studio has no DNS), or it points inside a private
 * network. Both stay refused; only the message differs.
 *
 * Literal IPs and an RFC 6761 `.invalid` host keep resolution deterministic, and
 * no request is sent: the guard refuses before any fetch.
 *
 * @package Saddle
 */

class Saddle_HTTP_Fetch_JSON_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'saddle_url_is_safe' );
		parent::tear_down();
	}

	/**
	 * The symptom in #335: an outside host that this server cannot look up was
	 * reported as "resolves to a private or unreachable network".
	 */
	public function test_a_host_that_cannot_be_looked_up_says_so_and_names_it() {
		$result = Saddle_HTTP::fetch_json( 'https://claude-app.invalid/oauth/client.json' );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_http_unresolved', $result->get_error_code() );
		$this->assertStringContainsString( 'claude-app.invalid', $result->get_error_message() );
		$this->assertStringContainsString( 'key', $result->get_error_message() );
		$this->assertStringNotContainsString( 'private', $result->get_error_message() );
	}

	public function test_a_private_address_keeps_the_private_network_message() {
		$result = Saddle_HTTP::fetch_json( 'https://10.0.0.5/client.json' );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_http_blocked', $result->get_error_code() );
		$this->assertStringContainsString( 'private', $result->get_error_message() );
	}

	public function test_link_local_metadata_address_is_still_refused() {
		$result = Saddle_HTTP::fetch_json( 'https://169.254.169.254/latest/meta-data/' );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_http_blocked', $result->get_error_code() );
	}

	/**
	 * Failing closed stays: the clearer message does not let an unresolvable
	 * host through, and only the filter can.
	 */
	public function test_an_unresolvable_host_is_still_refused_by_the_guard() {
		$this->assertFalse( Saddle_HTTP::url_is_safe( 'https://claude-app.invalid/x' ) );
	}

	/**
	 * The sign-in flow wraps the reason, so the owner sees it on the authorize
	 * page in place of the old misleading one.
	 */
	public function test_the_sign_in_flow_passes_the_clear_reason_through() {
		add_filter( 'saddle_oauth_enabled', '__return_true' );
		$result = Saddle_OAuth_Clients::resolve( 'https://claude-app.invalid/oauth/client.json' );
		remove_filter( 'saddle_oauth_enabled', '__return_true' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'could not look up claude-app.invalid', $result->get_error_message() );
	}
}
