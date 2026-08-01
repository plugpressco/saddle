<?php
/**
 * OAuth client identity — Client ID Metadata Documents and dynamic registration.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * How an app that has never met this site proves what it is.
 *
 * MCP clients and MCP servers have no prior relationship, so the client needs
 * some way to obtain a `client_id`. The specification offers two, and ChatGPT
 * uses either depending on how it was configured — so Saddle supports both:
 *
 *   - **Client ID Metadata Documents** (preferred, and the newer of the two).
 *     The `client_id` IS an HTTPS URL, and that URL serves a JSON document
 *     describing the client. Saddle fetches it and checks that the document
 *     claims the same URL as its own identity. That is a genuinely meaningful
 *     check — whoever controls `https://chatgpt.com/…/client.json` is whoever
 *     controls that domain — which is why the consent screen can say the app was
 *     verified, and can name where it was verified from.
 *
 *   - **Dynamic Client Registration** (RFC 7591, deprecated but still in wide
 *     use). The app posts its own description and gets an opaque id back.
 *     Nothing is verified, and the consent screen says so.
 *
 * Neither mechanism grants anything. A client record is inert metadata: no
 * token, no user, no access. The only thing registering buys is the right to
 * *ask*, by starting an authorization request that an administrator must then
 * approve on a consent screen. That is the sentence to keep in mind when reading
 * the unauthenticated registration endpoint below.
 */
class Saddle_OAuth_Clients {

	/**
	 * Option key for the self-registration switch.
	 */
	const DCR_OPTION = 'saddle_oauth_dcr_enabled';

	/**
	 * Option key for the client-metadata-document switch.
	 */
	const CIMD_OPTION = 'saddle_oauth_cimd_enabled';

	/**
	 * Registrations allowed per IP per hour.
	 */
	const RATE_PER_IP = 5;

	/**
	 * Registrations allowed site-wide per hour.
	 */
	const RATE_GLOBAL = 60;

	/**
	 * Largest registration request body accepted, in bytes.
	 */
	const MAX_BODY = 8192;

	/**
	 * Whether self-registration is available.
	 *
	 * Defaults ON when OAuth itself is on, because a meaningful share of ChatGPT
	 * connector deployments still register this way and turning it off would
	 * simply make them fail to connect.
	 *
	 * @return bool
	 */
	public static function dcr_enabled() {
		return Saddle_OAuth::is_enabled() && (bool) get_option( self::DCR_OPTION, true );
	}

	/**
	 * Whether client metadata documents may be fetched and trusted.
	 *
	 * @return bool
	 */
	public static function cimd_enabled() {
		return Saddle_OAuth::is_enabled() && (bool) get_option( self::CIMD_OPTION, true );
	}

	/**
	 * Register the self-registration endpoint.
	 *
	 * Public by protocol design — a client that has no credentials is exactly
	 * the client that needs to register. See the class docblock for why that is
	 * safe, and {@see self::rate_limited()} for what stops it being abused.
	 */
	public static function register_routes() {
		if ( ! self::dcr_enabled() ) {
			return;
		}

		register_rest_route(
			Saddle_MCP::REST_NAMESPACE,
			Saddle_OAuth::ROUTE_PREFIX . '/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Resolve a `client_id` to a validated client record.
	 *
	 * An HTTPS `client_id` is a metadata document URL; anything else is an
	 * opaque id from a previous self-registration.
	 *
	 * @param string $client_id Client identifier from the authorization request.
	 * @return array|WP_Error Client record, or WP_Error explaining the refusal.
	 */
	public static function resolve( $client_id ) {
		$client_id = (string) $client_id;

		if ( '' === $client_id ) {
			return new WP_Error( 'invalid_request', __( 'No client_id was supplied.', 'saddle' ) );
		}

		if ( 0 === stripos( $client_id, 'https://' ) ) {
			return self::resolve_metadata_document( $client_id );
		}

		$record = Saddle_OAuth_Store::get_client( $client_id );
		if ( ! $record ) {
			return new WP_Error( 'invalid_client', __( 'That app is not registered with this site. It may need to register again.', 'saddle' ) );
		}

		return $record;
	}

	/**
	 * Fetch, validate, and cache a Client ID Metadata Document.
	 *
	 * The security property is one line: the document must claim the same
	 * `client_id` as the URL it was served from. Whoever controls that URL
	 * controls that identity, and nobody else can assert it.
	 *
	 * @param string $client_id The document URL, which is also the client id.
	 * @return array|WP_Error
	 */
	private static function resolve_metadata_document( $client_id ) {
		if ( ! self::cimd_enabled() ) {
			return new WP_Error( 'invalid_client', __( 'This site does not accept apps that identify themselves by URL.', 'saddle' ) );
		}

		$cached = Saddle_OAuth_Store::get_client( $client_id );
		if ( $cached ) {
			return $cached;
		}

		// A fragment would make the identity ambiguous — two different strings
		// fetching the same document and both claiming to be it.
		if ( wp_parse_url( $client_id, PHP_URL_FRAGMENT ) ) {
			return new WP_Error( 'invalid_client', __( 'An app identifier URL cannot contain a fragment.', 'saddle' ) );
		}

		$path = (string) wp_parse_url( $client_id, PHP_URL_PATH );
		if ( '' === $path || '/' === $path ) {
			return new WP_Error( 'invalid_client', __( 'An app identifier URL must include a path.', 'saddle' ) );
		}

		$fetched = Saddle_HTTP::fetch_json( $client_id );
		if ( is_wp_error( $fetched ) ) {
			return new WP_Error(
				'invalid_client',
				sprintf(
					/* translators: %s: reason the document could not be read. */
					__( 'Could not read that app’s identity document: %s', 'saddle' ),
					$fetched->get_error_message()
				)
			);
		}

		$document = $fetched['data'];

		// The whole point. Anything else is an app claiming to be a document it
		// does not control.
		if ( ! isset( $document['client_id'] ) || (string) $document['client_id'] !== $client_id ) {
			return new WP_Error( 'invalid_client', __( 'That app’s identity document does not match the address it was fetched from.', 'saddle' ) );
		}

		$metadata = self::sanitize_metadata( $document );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$metadata['client_source'] = 'cimd';

		$saved = Saddle_OAuth_Store::save_client(
			$client_id,
			$metadata,
			Saddle_HTTP::cache_ttl( $fetched['headers'] )
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return Saddle_OAuth_Store::get_client( $client_id );
	}

	/**
	 * Handle a self-registration request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_register( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return Saddle_OAuth::error_response( 'invalid_client_metadata', __( 'That registration request is too large.', 'saddle' ), 400 );
		}

		$limited = self::rate_limited();
		if ( $limited ) {
			$response = Saddle_OAuth::error_response( 'temporarily_unavailable', __( 'Too many registrations from here recently. Try again in an hour.', 'saddle' ), 429 );
			$response->header( 'Retry-After', (string) HOUR_IN_SECONDS );
			return $response;
		}

		$submitted = $request->get_json_params();
		if ( ! is_array( $submitted ) ) {
			$submitted = $request->get_body_params();
		}
		if ( ! is_array( $submitted ) ) {
			return Saddle_OAuth::error_response( 'invalid_client_metadata', __( 'The registration request was not valid JSON.', 'saddle' ), 400 );
		}

		$metadata = self::sanitize_metadata( $submitted );
		if ( is_wp_error( $metadata ) ) {
			return Saddle_OAuth::error_response( 'invalid_redirect_uri', $metadata->get_error_message(), 400 );
		}

		$client_id = Saddle_OAuth::random_secret( 16 );
		if ( is_wp_error( $client_id ) ) {
			return Saddle_OAuth::error_response( 'temporarily_unavailable', $client_id->get_error_message(), 503 );
		}
		$client_id = 'saddle_' . $client_id;

		$metadata['client_source'] = 'dcr';

		$saved = Saddle_OAuth_Store::save_client( $client_id, $metadata, 0 );
		if ( is_wp_error( $saved ) ) {
			return Saddle_OAuth::error_response( 'temporarily_unavailable', $saved->get_error_message(), 503 );
		}

		self::record_registration();

		$response = new WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_id_issued_at'        => time(),
				'client_name'                => $metadata['client_name'],
				'redirect_uris'              => $metadata['redirect_uris'],
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				// No secret is issued, and none is needed: every client here is a
				// public client with mandatory PKCE. Not issuing one removes a
				// whole class of storage-and-leak problems, and RFC 7591 permits
				// the server to decide the authentication method.
				'token_endpoint_auth_method' => 'none',
			),
			201
		);

		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Validate and normalize client metadata from either source.
	 *
	 * @param array $raw Submitted or fetched metadata.
	 * @return array|WP_Error
	 */
	private static function sanitize_metadata( array $raw ) {
		$redirect_uris = array();
		foreach ( (array) ( isset( $raw['redirect_uris'] ) ? $raw['redirect_uris'] : array() ) as $uri ) {
			if ( is_string( $uri ) && self::redirect_uri_is_valid( $uri ) ) {
				$redirect_uris[] = $uri;
			}
		}

		if ( empty( $redirect_uris ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				__( 'An app must supply at least one redirect address, over HTTPS or to localhost.', 'saddle' )
			);
		}

		$logo = isset( $raw['logo_uri'] ) ? (string) $raw['logo_uri'] : '';
		if ( '' !== $logo && 0 !== stripos( $logo, 'https://' ) ) {
			$logo = '';
		}

		return array(
			'client_name'                => self::clean_text( isset( $raw['client_name'] ) ? $raw['client_name'] : '' ),
			'client_uri'                 => isset( $raw['client_uri'] ) && 0 === stripos( (string) $raw['client_uri'], 'https://' ) ? esc_url_raw( (string) $raw['client_uri'] ) : '',
			'logo_uri'                   => '' === $logo ? '' : esc_url_raw( $logo ),
			'redirect_uris'              => array_values( array_unique( $redirect_uris ) ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Whether a redirect URI is one Saddle will send an authorization code to.
	 *
	 * HTTPS anywhere, or plain HTTP only to the loopback interface — the
	 * carve-out OAuth 2.1 §8.4.2 makes for native apps that spin up a local
	 * listener. Everything else, including `http://` to a real host, is refused:
	 * an authorization code in cleartext is an authorization code an observer
	 * can spend.
	 *
	 * @param string $uri Candidate redirect URI.
	 * @return bool
	 */
	private static function redirect_uri_is_valid( $uri ) {
		$parts = wp_parse_url( $uri );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		// A fragment is forbidden on a redirect URI (RFC 6749 §3.1.2) — the
		// authorization response appends its own.
		if ( ! empty( $parts['fragment'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( 'https' === $scheme ) {
			return true;
		}

		if ( 'http' === $scheme ) {
			return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
		}

		return false;
	}

	/**
	 * Clamp a client-supplied display string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_text( $value ) {
		$text = sanitize_text_field( (string) $value );

		return '' === $text ? '' : mb_substr( $text, 0, 120 );
	}

	/**
	 * Whether registration should be refused right now.
	 *
	 * Two counters, both hourly: one per source address so a single actor can't
	 * flood, and one site-wide so a distributed one can't either. Transients
	 * match the throttling idiom {@see Saddle_Capabilities::log_denial()} already
	 * uses.
	 *
	 * @return bool
	 */
	private static function rate_limited() {
		$window = (int) floor( time() / HOUR_IN_SECONDS );

		if ( (int) get_transient( 'saddle_oauth_reg_all_' . $window ) >= self::RATE_GLOBAL ) {
			return true;
		}

		return (int) get_transient( self::ip_key( $window ) ) >= self::RATE_PER_IP;
	}

	/**
	 * Count a successful registration against both rate windows.
	 */
	private static function record_registration() {
		$window = (int) floor( time() / HOUR_IN_SECONDS );

		$global = 'saddle_oauth_reg_all_' . $window;
		set_transient( $global, (int) get_transient( $global ) + 1, HOUR_IN_SECONDS );

		$per_ip = self::ip_key( $window );
		set_transient( $per_ip, (int) get_transient( $per_ip ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Transient key for the current caller's hourly registration count.
	 *
	 * `REMOTE_ADDR` only. `X-Forwarded-For` is caller-controlled, so trusting it
	 * by default would make the per-IP limit trivially bypassable; a site behind
	 * a reverse proxy can supply the real address through the filter.
	 *
	 * @param int $window Hour bucket.
	 * @return string
	 */
	private static function ip_key( $window ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the client IP used for OAuth registration rate limiting.
		 *
		 * @param string $ip Address from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'saddle_client_ip', $ip );

		return 'saddle_oauth_reg_' . md5( $ip . '|' . $window );
	}
}
