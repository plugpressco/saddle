<?php
/**
 * What connected apps actually sent, and what they got back.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A record of MCP traffic, kept so "my app connected but says it can't do
 * anything" can be answered from the dashboard instead of a round trip.
 *
 * Two very different failures produce that same sentence, and they are
 * indistinguishable from the outside: a `tools/list` refused before it reaches
 * the tools handler (an error, with a status and a code), and a `tools/list`
 * that succeeds and returns nothing (a 200, with a count of zero). The whole
 * point of what is recorded here is that those two read differently at a
 * glance — and that a request which never arrived at all leaves no row, which
 * is the one thing no server-side log can otherwise tell us.
 *
 * Two stores, deliberately separate:
 *
 * - **Health** is always on and costs nothing. It is written once, when the MCP
 *   server is built, and only through update_option — which no-ops on an
 *   unchanged value, so a healthy site never writes at all.
 * - **The trace** is off by default, time-boxed when on, and capped. It is a
 *   debugging instrument, not a log.
 *
 * Not Saddle_Log: that is a CPT recording executed mutations, and reads stay
 * silent by design. Four post rows per tools/list would invert the cheapest
 * path in the plugin and break that contract in the Activity UI.
 */
class Saddle_MCP_Diagnostics {

	/**
	 * Option holding the last server-construction health record.
	 */
	const HEALTH_OPTION = 'saddle_mcp_health';

	/**
	 * Option holding the trace ring buffer.
	 */
	const TRACE_OPTION = 'saddle_mcp_trace';

	/**
	 * Whether the adapter path is running without Saddle_MCP_Compat.
	 *
	 * Set during transport setup and merged into the health record by
	 * {@see self::record_health()}. It is request state, not stored state: the
	 * next request re-derives it from whether the class loaded.
	 *
	 * @var bool
	 */
	private static $compat_missing = false;

	/**
	 * Note that the adapter is serving requests without its compatibility shim.
	 *
	 * This is a build fault, not a configuration one — the shim was excluded
	 * from every zip for a month while the adapter path stayed reachable through
	 * the official MCP Adapter plugin, and the only outward symptom was a client
	 * connecting and then finding no callable tools (#111).
	 */
	public static function note_compat_missing() {
		self::$compat_missing = true;
	}

	/**
	 * Option holding the unix timestamp recording stops at.
	 */
	const RECORDING_OPTION = 'saddle_mcp_trace_until';

	/**
	 * How many requests the ring buffer keeps.
	 *
	 * Was 25, which sounds ample and was not: paired with the panel recording
	 * its own 5-second poll (see targets_mcp()), the buffer turned over every
	 * ~125 seconds. A customer's capture of a failing connection spanned 114
	 * seconds and held two real rows among twenty-three of the panel watching
	 * itself. The poll is fixed; this is the margin, so a trace outlives the
	 * round trip of someone reading it, copying it and sending it on.
	 */
	const MAX_ENTRIES = 100;

	/**
	 * Default recording window, in minutes.
	 */
	const DEFAULT_WINDOW = 60;

	/**
	 * Facts captured on the way in, held until the response is known.
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Wire the recorder.
	 */
	public static function register() {
		// Priority 8: ahead of the OAuth confine (9), the credential scoping
		// (10) and the compat shim (11), so what is recorded is what the client
		// actually sent rather than what Saddle reshaped it into.
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'snapshot' ), 8, 3 );
		// Priority 11: behind the OAuth challenge (10), so the status recorded
		// is the one the client receives.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'complete' ), 11, 3 );
	}

	/**
	 * Register the dashboard's read/control endpoints.
	 */
	public static function register_routes() {
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/mcp-diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/mcp-diagnostics',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_post' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
				'args'                => array(
					'recording' => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'clear'     => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------- health */

	/**
	 * Compare the tools we asked for against the tools the server ended up with.
	 *
	 * The adapter skips any ability it fails to convert and logs it to
	 * error_log, one at a time — so a server can come up short, or empty, with
	 * nothing said to anyone who would notice. This names them.
	 *
	 * @param string[] $expected   Ability names handed to the server.
	 * @param string[] $registered Tool names the server actually holds.
	 * @return array
	 */
	public static function assess( array $expected, array $registered ) {
		// The adapter sanitizes slashes out of ability names when it converts
		// them, so compare on the sanitized form or every tool looks missing.
		$wanted = array();
		foreach ( $expected as $name ) {
			$wanted[] = str_replace( '/', '-', (string) $name );
		}

		$missing = array_values( array_diff( $wanted, $registered ) );

		return array(
			'expected'   => count( $wanted ),
			'registered' => count( $registered ),
			'missing'    => array_slice( $missing, 0, 20 ),
			'degraded'   => ! empty( $missing ) || empty( $registered ),
		);
	}

	/**
	 * Store the health record, plus the ordering facts that explain an empty one.
	 *
	 * @param array $facts Result of assess(), plus any extra context.
	 */
	public static function record_health( array $facts ) {
		$facts['recorded_at'] = time();
		$facts['init_fired']  = did_action( 'init' ) > 0;

		// Merged into every write rather than recorded on its own, because this
		// option is replaced wholesale and the adapter writes it later in the
		// request than the point where the shim's absence is discovered.
		if ( self::$compat_missing ) {
			$facts['compat_missing'] = true;
			$facts['degraded']       = true;
		}

		$previous = get_option( self::HEALTH_OPTION );
		if ( is_array( $previous ) ) {
			// Ignore the timestamp when deciding whether anything changed, or a
			// healthy site would write this option on every single request.
			$a = $previous;
			$b = $facts;
			unset( $a['recorded_at'], $b['recorded_at'] );
			if ( $a === $b ) {
				return;
			}
		}

		update_option( self::HEALTH_OPTION, $facts, false );
	}

	/**
	 * The last health record.
	 *
	 * @return array
	 */
	public static function health() {
		$health = get_option( self::HEALTH_OPTION );

		return is_array( $health ) ? $health : array();
	}

	/**
	 * Whether the MCP server came up short.
	 *
	 * @return bool
	 */
	public static function is_degraded() {
		$health = self::health();

		return ! empty( $health['degraded'] );
	}

	/* ----------------------------------------------------------------- trace */

	/**
	 * Whether traffic is being recorded right now.
	 *
	 * @return bool
	 */
	public static function is_recording() {
		return (int) get_option( self::RECORDING_OPTION, 0 ) > time();
	}

	/**
	 * Start recording for a bounded window.
	 *
	 * Time-boxed rather than a plain on/off switch so it cannot be left running
	 * on a production site by someone who forgot they turned it on.
	 *
	 * @param int $minutes Window length.
	 */
	public static function start_recording( $minutes = self::DEFAULT_WINDOW ) {
		$minutes = max( 1, min( 24 * 60, (int) $minutes ) );

		update_option( self::RECORDING_OPTION, time() + ( $minutes * MINUTE_IN_SECONDS ), false );
	}

	/**
	 * Stop recording.
	 */
	public static function stop_recording() {
		delete_option( self::RECORDING_OPTION );
	}

	/**
	 * Capture the request exactly as it arrived.
	 *
	 * @param WP_REST_Response|WP_Error|null $response Short-circuit response, if any.
	 * @param array                          $handler  Route handler.
	 * @param WP_REST_Request                $request  The request.
	 * @return WP_REST_Response|WP_Error|null
	 */
	public static function snapshot( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! self::targets_mcp( $request ) ) {
			return $response;
		}

		if ( ! self::is_recording() ) {
			return $response;
		}

		$session = $request->get_header( 'Mcp-Session-Id' );
		$body    = $request->get_json_params();
		$methods = array();

		if ( is_array( $body ) ) {
			$messages = isset( $body[0] ) ? $body : array( $body );
			foreach ( $messages as $message ) {
				if ( is_array( $message ) && isset( $message['method'] ) ) {
					$methods[] = sanitize_text_field( (string) $message['method'] );
				}
			}
		}

		// 'scheme' and 'auth' are the pair that answers "was this refused because
		// the credential was rejected, or because none arrived?" — the question
		// a 401 cannot answer on its own, and the one a host that strips the
		// Authorization header turns into a fortnight of guesswork. Both report
		// the SHAPE of the credential and never a byte of it; see
		// tests/mcp-diagnostics-test.php.
		//
		// 'method' matters because a row with no MCP method in it is ambiguous
		// between a GET, a POST with the wrong content type, and an empty body.
		// Those need different answers and used to look identical here.
		// credential_scheme() returns '' for "nothing arrived" and 'unknown' only
		// when Saddle_Connection is somehow absent. Those are different answers
		// and neither is "present" — collapsing them would put a confident word
		// on the row that decides whether the owner goes to their host.
		$scheme = class_exists( 'Saddle_Connection' ) ? Saddle_Connection::credential_scheme() : 'unknown';
		if ( '' === $scheme ) {
			$scheme = 'none';
			$auth   = 'absent';
		} elseif ( 'unknown' === $scheme ) {
			$auth = 'unknown';
		} else {
			$auth = 'present';
		}

		self::$pending = array(
			'time'     => time(),
			'method'   => $request->get_method(),
			'methods'  => $methods,
			'session'  => is_string( $session ) && '' !== $session ? 'sent' : 'absent',
			'protocol' => self::header_or_absent( $request, 'Mcp-Protocol-Version' ),
			'scheme'   => $scheme,
			'auth'     => $auth,
			'user'     => get_current_user_id(),
			'client'   => self::client_name( $request, $body ),
		);

		return $response;
	}

	/**
	 * Record what the client was actually served.
	 *
	 * @param WP_REST_Response $response Response about to be served.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  The request.
	 * @return WP_REST_Response
	 */
	public static function complete( $response, $server, $request ) {
		if ( empty( self::$pending ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$entry         = self::$pending;
		self::$pending = array();

		$entry['status'] = (int) $response->get_status();

		$data = json_decode( wp_json_encode( $response->get_data() ), true );
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['code'] ) ) {
				$entry['error'] = (int) $data['error']['code'];
			}
			if ( isset( $data['result']['tools'] ) && is_array( $data['result']['tools'] ) ) {
				// The number that separates "refused before the handler" from
				// "answered, with nothing to offer".
				$entry['tools'] = count( $data['result']['tools'] );
			}
		}

		$entries   = self::entries();
		$entries[] = $entry;

		update_option( self::TRACE_OPTION, array_slice( $entries, -self::MAX_ENTRIES ), false );

		return $response;
	}

	/**
	 * The recorded requests, oldest first.
	 *
	 * @return array
	 */
	public static function entries() {
		$entries = get_option( self::TRACE_OPTION );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Discard the recorded requests.
	 */
	public static function clear() {
		delete_option( self::TRACE_OPTION );
	}

	/* ------------------------------------------------------------- endpoints */

	/**
	 * Read the health record and the trace.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get() {
		return rest_ensure_response(
			array(
				'health'    => self::health(),
				'recording' => self::is_recording(),
				'until'     => (int) get_option( self::RECORDING_OPTION, 0 ),
				'entries'   => array_reverse( self::entries() ),
				'report'    => self::report(),
			)
		);
	}

	/**
	 * Start/stop recording, or clear what was recorded.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function rest_post( $request ) {
		if ( $request->get_param( 'clear' ) ) {
			self::clear();
		}

		$recording = $request->get_param( 'recording' );
		if ( null !== $recording ) {
			if ( $recording ) {
				self::start_recording();
			} else {
				self::stop_recording();
			}
		}

		return self::rest_get();
	}

	/**
	 * A plain-text summary, for pasting into a support reply.
	 *
	 * @return string
	 */
	public static function report() {
		$health = self::health();

		$lines = array(
			'Saddle MCP diagnostics',
			'Site: ' . home_url( '/' ),
			'Saddle: ' . ( defined( 'SADDLE_VERSION' ) ? SADDLE_VERSION : '?' ) . ' | WP: ' . get_bloginfo( 'version' ) . ' | PHP: ' . PHP_VERSION,
			'Transport: ' . self::transport_description(),
			'Endpoint: ' . rest_url( ltrim( Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE, '/' ) ),
		);

		if ( $health ) {
			$lines[] = sprintf(
				'Tools: %d registered of %d expected%s | init had fired: %s',
				isset( $health['registered'] ) ? (int) $health['registered'] : 0,
				isset( $health['expected'] ) ? (int) $health['expected'] : 0,
				empty( $health['missing'] ) ? '' : ' | missing: ' . implode( ', ', $health['missing'] ),
				empty( $health['init_fired'] ) ? 'no' : 'yes'
			);
		}

		$entries = self::entries();
		if ( ! $entries ) {
			$lines[] = 'No requests recorded.';

			return implode( "\n", $lines );
		}

		$lines[] = '';
		$lines[] = 'Recent requests (newest first):';

		// auth + scheme come BEFORE session and protocol, because on a refused
		// row they are the answer and the other two are trivia. Their absence
		// from this line is the whole reason a customer had to ask us what his
		// own trace already knew.
		foreach ( array_reverse( $entries ) as $entry ) {
			$lines[] = sprintf(
				'%s  %-6s %-28s auth:%-8s scheme:%-8s session:%-7s protocol:%-11s status:%-4s%s%s  %s',
				gmdate( 'Y-m-d H:i:s', isset( $entry['time'] ) ? (int) $entry['time'] : 0 ),
				isset( $entry['method'] ) ? $entry['method'] : '?',
				implode( ',', isset( $entry['methods'] ) ? $entry['methods'] : array() ),
				isset( $entry['auth'] ) ? $entry['auth'] : '?',
				isset( $entry['scheme'] ) ? ( '' === $entry['scheme'] ? 'none' : $entry['scheme'] ) : '?',
				isset( $entry['session'] ) ? $entry['session'] : '?',
				isset( $entry['protocol'] ) ? $entry['protocol'] : '?',
				isset( $entry['status'] ) ? $entry['status'] : '?',
				isset( $entry['error'] ) ? ' rpc:' . $entry['error'] : '',
				isset( $entry['tools'] ) ? ' tools:' . $entry['tools'] : '',
				isset( $entry['client'] ) ? $entry['client'] : ''
			);
		}

		return implode( "\n", $lines );
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * Which transport is serving the endpoint, and where it came from.
	 *
	 * Read off the adapter class itself rather than the library's own global
	 * constants: the class is the thing that actually decides the code path, it
	 * carries its own version, and a reflected path tells us whether the copy in
	 * play is ours or another plugin's — which the constants cannot, once a
	 * build ships without the bundle.
	 *
	 * @return string
	 */
	private static function transport_description() {
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return 'Saddle built-in JSON-RPC';
		}

		$version = defined( '\\WP\\MCP\\Core\\McpAdapter::VERSION' ) ? \WP\MCP\Core\McpAdapter::VERSION : '?';
		$source  = 'another plugin';

		try {
			$file = ( new ReflectionClass( '\\WP\\MCP\\Core\\McpAdapter' ) )->getFileName();
			if ( is_string( $file ) && defined( 'SADDLE_DIR' ) && 0 === strpos( $file, SADDLE_DIR ) ) {
				$source = 'bundled';
			}
		} catch ( ReflectionException $e ) {
			$source = 'unknown';
		}

		return sprintf( 'MCP Adapter %s (%s)', $version, $source );
	}

	/**
	 * Whether the request is aimed at the MCP endpoint.
	 *
	 * The route, or something below it — never merely something that starts
	 * with the same characters. This was a bare strpos() prefix test, and the
	 * admin API shares the `saddle/v1` namespace, so `/saddle/v1/mcp-diagnostics`
	 * matched: the panel below recorded its own 5-second poll as MCP traffic and
	 * pushed the real rows out of the ring buffer within about two minutes. A
	 * customer's capture of a failing connection came back twenty-three parts
	 * panel to two parts evidence.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	private static function targets_mcp( $request ) {
		$mcp   = '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE;
		$route = (string) $request->get_route();

		return $route === $mcp || 0 === strpos( $route, $mcp . '/' );
	}

	/**
	 * A header's value, or the string 'absent'.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $name    Header name.
	 * @return string
	 */
	private static function header_or_absent( $request, $name ) {
		$value = $request->get_header( $name );

		if ( ! is_string( $value ) || '' === $value ) {
			return 'absent';
		}

		return substr( sanitize_text_field( $value ), 0, 40 );
	}

	/**
	 * Who is calling, as far as the request admits.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param mixed           $body    Decoded JSON body.
	 * @return string
	 */
	private static function client_name( $request, $body ) {
		if ( is_array( $body ) && isset( $body['params']['clientInfo']['name'] ) ) {
			return substr( sanitize_text_field( (string) $body['params']['clientInfo']['name'] ), 0, 60 );
		}

		$agent = $request->get_header( 'user-agent' );

		return is_string( $agent ) ? substr( sanitize_text_field( $agent ), 0, 60 ) : '';
	}
}
