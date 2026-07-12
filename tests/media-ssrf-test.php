<?php
/**
 * SSRF guard for saddle/upload-media: source_url_is_safe() must reject internal
 * targets and — since #38 — fail CLOSED when the host resolves to nothing, with
 * the `saddle_source_url_is_safe` filter as the trusted-environment escape hatch.
 *
 * These exercise the guard method directly (it is the unit #38 changed); the
 * public upload_media() entry additionally runs core's wp_http_validate_url(),
 * which is a separate gate the Saddle filter deliberately does not override.
 * Cases use literal IPs and an RFC 6761 `.invalid` host so resolution is
 * deterministic and no network is touched.
 *
 * @package Saddle
 */

class Saddle_Media_SSRF_Test extends WP_UnitTestCase {

	private function is_safe( $url ) {
		$method = new ReflectionMethod( 'Saddle_Abilities', 'source_url_is_safe' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $url );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_source_url_is_safe' );
		parent::tear_down();
	}

	public function test_link_local_metadata_ip_is_unsafe() {
		$this->assertFalse( $this->is_safe( 'http://169.254.169.254/latest/meta-data/' ) );
	}

	public function test_private_range_ip_is_unsafe() {
		$this->assertFalse( $this->is_safe( 'http://10.0.0.5/x.jpg' ) );
	}

	public function test_loopback_is_unsafe() {
		$this->assertFalse( $this->is_safe( 'http://127.0.0.1/x.jpg' ) );
	}

	/**
	 * The #38 fix: a host that resolves to no IPs (here an RFC 6761 `.invalid`
	 * name that can never resolve) is now unsafe rather than waved through.
	 */
	public function test_unresolvable_host_fails_closed() {
		$this->assertFalse( $this->is_safe( 'http://nonexistent.invalid/x.jpg' ) );
	}

	/**
	 * The escape hatch: a trusted environment can allow an otherwise-refused
	 * source (including the fail-closed unresolvable case) via the filter.
	 */
	public function test_filter_can_override_the_block() {
		add_filter( 'saddle_source_url_is_safe', '__return_true' );
		$this->assertTrue( $this->is_safe( 'http://nonexistent.invalid/x.jpg' ) );
	}

	/**
	 * A public literal IP passes the internal-address guard.
	 */
	public function test_public_ip_is_safe() {
		$this->assertTrue( $this->is_safe( 'http://93.184.216.34/x.jpg' ) );
	}

	/**
	 * A missing host is unsafe.
	 */
	public function test_hostless_url_is_unsafe() {
		$this->assertFalse( $this->is_safe( 'not-a-url' ) );
	}
}
