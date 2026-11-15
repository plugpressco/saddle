<?php
/**
 * Saddle_HTTP::ip_is_public() refuses every range that never leads to a public
 * website, the same on every PHP version (#426). PHP's own two filter flags
 * leave out shared address space, benchmarking, protocol assignments,
 * documentation, multicast and an IPv4 address written inside an IPv6 one, so
 * each of those is named here with an address on each side of the range.
 *
 * Literal IPs only: nothing is looked up and no request is sent.
 *
 * @package Saddle
 */

class Saddle_HTTP_Address_Ranges_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'saddle_url_is_safe' );
		parent::tear_down();
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function not_public() {
		return array(
			'this network'                    => array( '0.1.2.3' ),
			'private 10'                      => array( '10.0.0.5' ),
			'shared space, first'             => array( '100.64.0.0' ),
			'shared space, a metadata server' => array( '100.100.100.200' ),
			'shared space, last'              => array( '100.127.255.255' ),
			'loopback'                        => array( '127.0.0.1' ),
			'link-local metadata'             => array( '169.254.169.254' ),
			'private 172, first'              => array( '172.16.0.1' ),
			'private 172, last'               => array( '172.31.255.255' ),
			'protocol assignments'            => array( '192.0.0.1' ),
			'documentation 1'                 => array( '192.0.2.5' ),
			'6to4 relay'                      => array( '192.88.99.1' ),
			'private 192.168'                 => array( '192.168.1.1' ),
			'benchmarking, first'             => array( '198.18.0.1' ),
			'benchmarking, last'              => array( '198.19.255.255' ),
			'documentation 2'                 => array( '198.51.100.7' ),
			'documentation 3'                 => array( '203.0.113.7' ),
			'multicast'                       => array( '224.0.0.1' ),
			'multicast, last'                 => array( '239.255.255.255' ),
			'reserved'                        => array( '240.0.0.1' ),
			'broadcast'                       => array( '255.255.255.255' ),
			'v6 unspecified'                  => array( '::' ),
			'v6 loopback'                     => array( '::1' ),
			'v6 loopback in brackets'         => array( '[::1]' ),
			'v6 local NAT64'                  => array( '64:ff9b:1::1' ),
			'v6 discard'                      => array( '100::1' ),
			'v6 teredo'                       => array( '2001::1' ),
			'v6 documentation'                => array( '2001:db8::1' ),
			'v6 6to4'                         => array( '2002:a00:1::1' ),
			'v6 unique local'                 => array( 'fd12:3456:789a::1' ),
			'v6 link-local'                   => array( 'fe80::1' ),
			'v6 multicast'                    => array( 'ff02::1' ),
			'mapped private'                  => array( '::ffff:10.0.0.1' ),
			'mapped loopback'                 => array( '::ffff:127.0.0.1' ),
			'mapped shared space'             => array( '::ffff:100.100.100.200' ),
			'NAT64 private'                   => array( '64:ff9b::a00:1' ),
			'NAT64 metadata'                  => array( '64:ff9b::a9fe:a9fe' ),
			'compatible private'              => array( '::10.0.0.1' ),
			'not an address'                  => array( 'example.com' ),
			'empty'                           => array( '' ),
			'a decimal number'                => array( '2130706433' ),
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function is_public() {
		return array(
			'a public resolver'            => array( '8.8.8.8' ),
			'another'                      => array( '1.1.1.1' ),
			'just below shared space'      => array( '100.63.255.255' ),
			'just above shared space'      => array( '100.128.0.0' ),
			'just below private 172'       => array( '172.15.255.255' ),
			'just above private 172'       => array( '172.32.0.0' ),
			'just above protocol block'    => array( '192.0.1.1' ),
			'just above documentation 1'   => array( '192.0.3.1' ),
			'just below benchmarking'      => array( '198.17.255.255' ),
			'just above benchmarking'      => array( '198.20.0.0' ),
			'just below multicast'         => array( '223.255.255.254' ),
			'v6 public'                    => array( '2606:4700:4700::1111' ),
			'v6 public in brackets'        => array( '[2001:4860:4860::8888]' ),
			'v6 just above the 2001 block' => array( '2001:200::1' ),
			'mapped public'                => array( '::ffff:8.8.8.8' ),
			'NAT64 public'                 => array( '64:ff9b::808:808' ),
		);
	}

	/**
	 * @dataProvider not_public
	 *
	 * @param string $ip The address.
	 */
	public function test_an_address_that_is_not_public_is_refused( $ip ) {
		$this->assertFalse( Saddle_HTTP::ip_is_public( $ip ) );
	}

	/**
	 * @dataProvider is_public
	 *
	 * @param string $ip The address.
	 */
	public function test_a_public_address_passes( $ip ) {
		$this->assertTrue( Saddle_HTTP::ip_is_public( $ip ) );
	}

	/**
	 * The symptom in #426: these passed the guard before, because PHP's two
	 * flags call them public.
	 */
	public function test_the_guard_refuses_what_the_two_php_flags_let_through() {
		$this->assertFalse( Saddle_HTTP::url_is_safe( 'https://100.100.100.200/latest/meta-data/' ) );
		$this->assertFalse( Saddle_HTTP::url_is_safe( 'https://198.18.0.1/x.jpg' ) );
		$this->assertFalse( Saddle_HTTP::url_is_safe( 'https://192.0.0.1/x.jpg' ) );
		$this->assertFalse( Saddle_HTTP::url_is_safe( 'https://224.0.0.1/x.jpg' ) );
		$this->assertFalse( Saddle_HTTP::url_is_safe( 'https://[::ffff:10.0.0.1]/x.jpg' ) );
		$this->assertFalse( Saddle_HTTP::url_is_safe( 'https://[64:ff9b::a00:1]/x.jpg' ) );
	}

	public function test_the_guard_still_passes_a_public_address() {
		$this->assertTrue( Saddle_HTTP::url_is_safe( 'https://8.8.8.8/x.jpg' ) );
		$this->assertTrue( Saddle_HTTP::url_is_safe( 'https://[2606:4700:4700::1111]/x.jpg' ) );
	}

	/**
	 * The way out for a trusted network is unchanged, and it is handed the
	 * addresses that were checked.
	 */
	public function test_the_filter_still_decides_last() {
		$seen = array();
		add_filter(
			'saddle_url_is_safe',
			static function ( $safe, $url, $ips ) use ( &$seen ) {
				$seen = array( $safe, $ips );
				return true;
			},
			10,
			3
		);

		$this->assertTrue( Saddle_HTTP::url_is_safe( 'https://100.64.0.9/x.jpg' ) );
		$this->assertSame( array( false, array( '100.64.0.9' ) ), $seen );
	}
}
