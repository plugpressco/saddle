<?php
/**
 * Shared outbound-HTTP guard.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one place Saddle decides whether an outbound request is safe to make.
 *
 * Saddle makes very few outbound requests, and every one of them targets a host
 * somebody else chose: a media URL an agent asked to sideload, or the metadata
 * document a connecting OAuth client presents as its own identity. Both are
 * textbook server-side request forgery shapes, so both go through the same
 * pre-flight rather than each growing its own near-copy of it.
 *
 * This class was extracted from `Saddle_Abilities::source_url_is_safe()` when
 * OAuth client metadata needed the identical check; that method is now a thin
 * delegate, so the media path's behaviour and its `saddle_source_url_is_safe`
 * escape hatch are unchanged.
 */
class Saddle_HTTP {

	/**
	 * Redirects fetch_own_page() follows, each one checked to stay on the site.
	 */
	const OWN_PAGE_REDIRECTS = 3;

	/**
	 * Whether a URL resolves somewhere it is safe to fetch from.
	 *
	 * Resolves the host and refuses if any resulting address is private or
	 * reserved — link-local (169.254.0.0/16, where cloud metadata services live),
	 * loopback, and the RFC 1918 ranges.
	 *
	 * Fails CLOSED when the host resolves to nothing. Some locked-down hosts
	 * disable `dns_get_record()`/`gethostbyname()` while still allowing HTTP, and
	 * an internal name that only resolves at fetch time would otherwise walk
	 * straight past this pre-flight. Refusing an unverifiable name is the safe
	 * default; a trusted NAT'd environment can override via the filter.
	 *
	 * The residual DNS-rebinding race on names that DO resolve is acknowledged
	 * and accepted — closing it needs resolve-then-connect-to-the-same-IP, which
	 * WordPress's HTTP API does not expose.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function url_is_safe( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$host = trim( $host, '[]' ); // Strip IPv6 brackets.

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			foreach ( array( DNS_A, DNS_AAAA ) as $dns_type ) {
				// Silenced by design: this is an SSRF pre-flight resolving a
				// caller-supplied host to reject internal targets. A lookup
				// failure just means "no records" — we must not warn or throw on
				// an attacker-chosen name, only fall through to refusal.
				$records = @dns_get_record( $host, $dns_type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see comment above.
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) {
						if ( ! empty( $record['ip'] ) ) {
							$ips[] = $record['ip'];
						}
						if ( ! empty( $record['ipv6'] ) ) {
							$ips[] = $record['ipv6'];
						}
					}
				}
			}
			if ( empty( $ips ) ) {
				$resolved = gethostbyname( $host );
				if ( $resolved && $resolved !== $host ) {
					$ips[] = $resolved;
				}
			}
		}

		$safe = ! empty( $ips );
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$safe = false;
				break;
			}
		}

		/**
		 * Filter whether an outbound URL resolves somewhere safe to fetch.
		 *
		 * Lets a trusted environment override the private/reserved-IP block —
		 * e.g. a NAT'd or proxied host where legitimate external names resolve to
		 * private ranges. Only override if you understand the SSRF implications.
		 *
		 * @param bool     $safe Whether the URL passed the internal-address check.
		 * @param string   $url  The candidate URL.
		 * @param string[] $ips  The resolved IPs that were checked.
		 */
		return (bool) apply_filters( 'saddle_url_is_safe', $safe, $url, $ips );
	}

	/**
	 * Fetch a small JSON document from a third-party origin.
	 *
	 * Deliberately strict, because the caller does not choose the host:
	 *
	 *   - HTTPS only, and only after {@see self::url_is_safe()} clears it.
	 *   - **No redirects at all.** A redirect is the standard way to walk an
	 *     SSRF guard: the pre-flight validates a public host, the redirect sends
	 *     the fetch somewhere internal. A metadata document has no legitimate
	 *     reason to redirect.
	 *   - A hard size cap, checked against `Content-Length` and enforced again on
	 *     the body actually received.
	 *   - Certificates verified. This is a third party, not our own loopback.
	 *
	 * @param string $url      Absolute HTTPS URL.
	 * @param int    $max_bytes Response size cap.
	 * @return array|WP_Error { @type array $data, @type array $headers }
	 */
	public static function fetch_json( $url, $max_bytes = 65536 ) {
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'saddle_http_scheme', __( 'Only HTTPS URLs can be fetched.', 'saddle' ) );
		}

		if ( ! self::url_is_safe( $url ) ) {
			return new WP_Error( 'saddle_http_blocked', __( 'That address resolves to a private or unreachable network.', 'saddle' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => (int) $max_bytes,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'saddle_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'That address answered with HTTP %d.', 'saddle' ),
					$code
				)
			);
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( 0 !== stripos( $type, 'application/json' ) ) {
			return new WP_Error( 'saddle_http_content_type', __( 'That address did not return JSON.', 'saddle' ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > (int) $max_bytes ) {
			return new WP_Error( 'saddle_http_too_large', __( 'That document is too large to read.', 'saddle' ) );
		}

		$data = json_decode( $body, true, 8 );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'saddle_http_invalid_json', __( 'That document is not valid JSON.', 'saddle' ) );
		}

		return array(
			'data'    => $data,
			'headers' => array(
				'cache-control' => (string) wp_remote_retrieve_header( $response, 'cache-control' ),
				'expires'       => (string) wp_remote_retrieve_header( $response, 'expires' ),
			),
		);
	}

	/**
	 * Fetch one of this site's own public pages, as a logged-out visitor sees it.
	 *
	 * The one fetch here whose target nobody else chose: the URL must be on this
	 * site's own scheme and host, which the caller gets from get_permalink(), so
	 * it is never agent input. That is why url_is_safe() does not apply. It
	 * would refuse the site's own address on every host that resolves itself to
	 * a private IP, which is most of them behind a load balancer. No cookies are
	 * sent, so what comes back is what a visitor (and a page cache) serves.
	 *
	 * A permalink may redirect (a trailing slash, http to https), so up to
	 * three redirects are followed, but by hand: each one must stay on this
	 * site, and the first that leaves it ends the fetch. Core would follow a
	 * redirect anywhere.
	 *
	 * @param string $url       Absolute URL on this site.
	 * @param int    $max_bytes Response size cap.
	 * @return array|WP_Error { @type int $status, @type string $body }
	 */
	public static function fetch_own_page( $url, $max_bytes = 2097152 ) {
		if ( ! self::is_own_address( (string) $url, false ) ) {
			return new WP_Error( 'saddle_http_not_own_site', __( 'Only this site\'s own pages can be fetched this way.', 'saddle' ) );
		}

		$current = (string) $url;
		for ( $hop = 0; $hop <= self::OWN_PAGE_REDIRECTS; $hop++ ) {
			$response = wp_remote_get(
				$current,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					// Local and staging sites often serve a self-signed certificate,
					// and this reads our own public HTML, as the connection probe does.
					'sslverify'           => false,
					'cookies'             => array(),
					'limit_response_size' => (int) $max_bytes,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( $status < 300 || $status >= 400 || '' === $location ) {
				return array(
					'status' => $status,
					'body'   => (string) wp_remote_retrieve_body( $response ),
				);
			}

			$next = WP_Http::make_absolute_url( $location, $current );
			if ( ! self::is_own_address( $next, true ) ) {
				$host = (string) wp_parse_url( $next, PHP_URL_HOST );
				return new WP_Error(
					'saddle_http_offsite_redirect',
					sprintf(
						/* translators: %s: the other site's host name. */
						__( 'The public page redirects to another site (%s), so Saddle did not follow it.', 'saddle' ),
						'' !== $host ? $host : $next
					)
				);
			}
			$current = $next;
		}

		return new WP_Error(
			'saddle_http_too_many_redirects',
			__( 'The public page redirected more than three times, so Saddle stopped.', 'saddle' )
		);
	}

	/**
	 * Whether a URL points at this site: the same host and port as home_url().
	 *
	 * @param string $url        Absolute URL.
	 * @param bool   $any_scheme True to accept http or https, as a redirect
	 *                           between them stays on the site; false to
	 *                           require home_url()'s own scheme.
	 * @return bool
	 */
	private static function is_own_address( $url, $any_scheme ) {
		$home = wp_parse_url( home_url() );
		$want = wp_parse_url( (string) $url );

		if ( ! is_array( $home ) || ! is_array( $want ) || empty( $want['host'] ) || empty( $home['host'] ) ) {
			return false;
		}

		$scheme = strtolower( isset( $want['scheme'] ) ? (string) $want['scheme'] : '' );
		$wanted = $any_scheme ? array( 'http', 'https' ) : array( strtolower( isset( $home['scheme'] ) ? (string) $home['scheme'] : '' ) );

		return strtolower( $want['host'] ) === strtolower( $home['host'] )
			&& in_array( $scheme, $wanted, true )
			&& ( isset( $want['port'] ) ? (int) $want['port'] : 0 ) === ( isset( $home['port'] ) ? (int) $home['port'] : 0 );
	}

	/**
	 * How long a fetched document may be cached, from its own HTTP headers.
	 *
	 * @param array $headers  Headers as returned by {@see self::fetch_json()}.
	 * @param int   $fallback Used when the origin says nothing usable.
	 * @param int   $min      Lower clamp.
	 * @param int   $max      Upper clamp.
	 * @return int Seconds.
	 */
	public static function cache_ttl( array $headers, $fallback = 3600, $min = 300, $max = 86400 ) {
		$ttl = (int) $fallback;

		if ( ! empty( $headers['cache-control'] ) && preg_match( '/max-age\s*=\s*(\d+)/i', $headers['cache-control'], $m ) ) {
			$ttl = (int) $m[1];
		} elseif ( ! empty( $headers['expires'] ) ) {
			$expires = strtotime( $headers['expires'] );
			if ( $expires ) {
				$ttl = $expires - time();
			}
		}

		return (int) max( $min, min( $max, $ttl ) );
	}
}
