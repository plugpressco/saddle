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
