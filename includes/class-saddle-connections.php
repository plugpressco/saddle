<?php
/**
 * Which credentials have reached the MCP endpoint, and what they did first.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The connection registry: one small record per credential that has reached
 * the MCP endpoint, so the admin can say "Claude connected" and "Claude made
 * its first tool call" instead of guessing from a key's `last_used`.
 *
 * Nothing else answers those two questions. Core stamps an Application
 * Password's `last_used` on the handshake as well as on a tool call (and only
 * once a day), reads are never logged, and the client's own name was captured
 * only while the opt-in trace recorded.
 *
 * A record is keyed by the credential: `key:<Application Password uuid>` or
 * `oauth:<grant id>`. It holds when the credential was first and last seen,
 * the client's self-reported name, the first successful tool call, and the
 * names of the last few tools called. It never holds arguments or results.
 * This is connection state, not a log, so "reads stay silent" still holds:
 * no `saddle_log` row, no row per call.
 *
 * Bounded twice: at most MAX_RECORDS records, and a record for a credential
 * that no longer exists is dropped on revoke and by the hourly sweep.
 */
class Saddle_Connections {

	/**
	 * Option holding the records, keyed by connection id. Autoload off: only
	 * the MCP route and the admin read it.
	 */
	const OPTION = 'saddle_connections';

	/**
	 * How many records are kept. The least recently seen goes first.
	 */
	const MAX_RECORDS = 50;

	/**
	 * How many distinct tool names a record remembers.
	 */
	const RECENT_TOOLS = 5;

	/**
	 * Seconds between routine writes for one connection. The same throttle as
	 * {@see Saddle_OAuth_Store::touch_grant()}: a busy agent must not turn
	 * every call into a database write.
	 */
	const THROTTLE = 60;

	/**
	 * Wire the observer and the clean-up.
	 */
	public static function register() {
		// One hook covers both transports: the adapter and the built-in
		// JSON-RPC both answer on this route. Priority 12, behind the OAuth
		// challenge (10), the trace and the compat shim (11), so the response
		// read here is the one the client receives.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe' ), 12, 3 );

		// Core fires this for every Application Password it deletes, including
		// one removed from the user's profile screen rather than from Saddle.
		add_action( 'wp_delete_application_password', array( __CLASS__, 'forget_key' ), 10, 2 );

		add_action( Saddle_Approval::GC_HOOK, array( __CLASS__, 'sweep' ) );
	}

	/**
	 * Record what an authenticated MCP request says about its connection.
	 *
	 * @param WP_HTTP_Response $response The response about to be served.
	 * @param WP_REST_Server   $server   The REST server (unused).
	 * @param WP_REST_Request  $request  The request.
	 * @return WP_HTTP_Response Unchanged.
	 */
	public static function observe( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		if ( ! Saddle_MCP_Diagnostics::targets_mcp( $request ) ) {
			return $response;
		}

		$credential = self::credential();
		if ( null !== $credential ) {
			self::record( $credential, self::facts( $request, $response ) );
		}

		return $response;
	}

	/**
	 * Every record, keyed by connection id.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_filter( $stored, 'is_array' );
	}

	/**
	 * Drop one connection's record.
	 *
	 * @param string $id Connection id, e.g. `oauth:<grant id>`.
	 */
	public static function forget( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return;
		}

		unset( $all[ $id ] );
		self::save( $all );
	}

	/**
	 * Core's `wp_delete_application_password` action.
	 *
	 * @param int   $user_id User the key belonged to.
	 * @param array $item    The deleted key.
	 */
	public static function forget_key( $user_id, $item ) {
		unset( $user_id );

		if ( is_array( $item ) && ! empty( $item['uuid'] ) ) {
			self::forget( 'key:' . $item['uuid'] );
		}
	}

	/**
	 * Drop every record whose credential no longer exists.
	 *
	 * Revocation paths that don't pass through forget() end up here: a user
	 * deleted with their keys, a grant revoked for a replayed code, OAuth
	 * switched off. At most MAX_RECORDS lookups, once an hour.
	 */
	public static function sweep() {
		$all  = self::all();
		$kept = array();

		foreach ( $all as $id => $record ) {
			if ( self::credential_exists( $id, $record ) ) {
				$kept[ $id ] = $record;
			}
		}

		if ( count( $kept ) !== count( $all ) ) {
			self::save( $kept );
		}
	}

	/**
	 * The credential behind the current request, when it is one an app holds.
	 *
	 * A browser session is not a connection, so it returns null for one.
	 *
	 * @return array|null `id`, `kind` and `user`.
	 */
	private static function credential() {
		$user = get_current_user_id();
		if ( ! $user ) {
			return null;
		}

		$uuid = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
		if ( is_string( $uuid ) && '' !== $uuid ) {
			return array(
				'id'   => 'key:' . $uuid,
				'kind' => 'key',
				'user' => $user,
			);
		}

		$grant = class_exists( 'Saddle_OAuth_Bearer' ) ? Saddle_OAuth_Bearer::current_grant_id() : '';
		if ( '' !== $grant ) {
			return array(
				'id'   => 'oauth:' . $grant,
				'kind' => 'oauth',
				'user' => $user,
			);
		}

		return null;
	}

	/**
	 * What this exchange tells us: who the client says it is, and which tool
	 * calls succeeded.
	 *
	 * A tool call counts only when its answer is a result that is not an
	 * error. A refusal (`isError`) or a JSON-RPC error proves the connection
	 * reached WordPress, not that the app can work, so neither stamps
	 * `first_tool_at`. Batches are matched call to answer by id.
	 *
	 * @param WP_REST_Request  $request  The request.
	 * @param WP_HTTP_Response $response The response.
	 * @return array `client` (name/version or null), `agent`, `tools`.
	 */
	private static function facts( $request, $response ) {
		$client = null;
		$calls  = array();

		foreach ( self::as_list( $request->get_json_params() ) as $message ) {
			$method = is_array( $message ) && isset( $message['method'] ) ? $message['method'] : '';

			if ( 'initialize' === $method && isset( $message['params']['clientInfo']['name'] ) ) {
				$info = $message['params']['clientInfo'];
				$name = self::clip( $info['name'], 60 );
				if ( '' !== $name ) {
					$client = array(
						'name'    => $name,
						'version' => self::clip( isset( $info['version'] ) ? $info['version'] : '', 30 ),
					);
				}
			}

			if ( 'tools/call' === $method && isset( $message['id'], $message['params']['name'] ) && is_scalar( $message['id'] ) ) {
				$calls[ (string) $message['id'] ] = (string) $message['params']['name'];
			}
		}

		$tools = array();
		if ( $calls ) {
			// The adapter answers with DTOs; round-trip them to plain arrays.
			$data = json_decode( (string) wp_json_encode( $response->get_data() ), true );

			foreach ( self::as_list( $data ) as $answer ) {
				if ( ! is_array( $answer ) || ! isset( $answer['id'] ) || ! is_scalar( $answer['id'] ) ) {
					continue;
				}
				$id = (string) $answer['id'];
				if ( ! isset( $calls[ $id ] ) || ! isset( $answer['result'] ) || ! is_array( $answer['result'] ) || ! empty( $answer['result']['isError'] ) ) {
					continue;
				}
				$ability = Saddle_MCP::ability_name_for_tool( $calls[ $id ] );
				if ( '' !== $ability ) {
					$tools[] = $ability;
				}
			}
		}

		$agent = (string) $request->get_header( 'user-agent' );

		return array(
			'client' => $client,
			// The product token only ("claude-code/2.1.0 (…)" is "claude-code").
			'agent'  => preg_match( '#^[A-Za-z0-9._-]{1,40}#', trim( $agent ), $match ) ? $match[0] : '',
			'tools'  => $tools,
		);
	}

	/**
	 * Merge this exchange into the connection's record, and save it when the
	 * change matters.
	 *
	 * A write happens at once when the waiting screens need it: a new
	 * connection, a new client name, the first tool call, or a tool name not
	 * among the recent ones. Anything else (a repeat call, a tools/list, a
	 * ping) waits for the THROTTLE, so `last_seen_at` is at most a minute
	 * behind.
	 *
	 * @param array $credential From credential().
	 * @param array $facts      From facts().
	 */
	private static function record( array $credential, array $facts ) {
		$now    = time();
		$all    = self::all();
		$id     = $credential['id'];
		$urgent = ! isset( $all[ $id ] );

		$record = wp_parse_args(
			$urgent ? array() : $all[ $id ],
			array(
				'kind'           => $credential['kind'],
				'user'           => (int) $credential['user'],
				'client_name'    => '',
				'client_version' => '',
				'agent'          => '',
				'first_seen_at'  => $now,
				'last_seen_at'   => 0,
				'first_tool_at'  => 0,
				'last_tool_at'   => 0,
				'last_tool'      => '',
				'recent_tools'   => array(),
			)
		);

		if ( is_array( $facts['client'] ) && ( $facts['client']['name'] !== $record['client_name'] || $facts['client']['version'] !== $record['client_version'] ) ) {
			$record['client_name']    = $facts['client']['name'];
			$record['client_version'] = $facts['client']['version'];
			$urgent                   = true;
		}

		if ( '' !== $facts['agent'] && $facts['agent'] !== $record['agent'] ) {
			$record['agent'] = $facts['agent'];
			$urgent          = true;
		}

		foreach ( $facts['tools'] as $tool ) {
			$recent = (array) $record['recent_tools'];

			if ( empty( $record['first_tool_at'] ) ) {
				$record['first_tool_at'] = $now;
				$urgent                  = true;
			}
			if ( ! in_array( $tool, $recent, true ) ) {
				$urgent = true;
			}

			$record['recent_tools'] = array_slice( array_values( array_unique( array_merge( array( $tool ), $recent ) ) ), 0, self::RECENT_TOOLS );
			$record['last_tool']    = $tool;
			$record['last_tool_at'] = $now;
		}

		if ( ! $urgent && $now - (int) $record['last_seen_at'] < self::THROTTLE ) {
			return;
		}

		$record['last_seen_at'] = $now;
		$all[ $id ]             = $record;

		self::save( $all );
	}

	/**
	 * Store the records, keeping only the MAX_RECORDS most recently seen.
	 *
	 * @param array $all Records keyed by connection id.
	 */
	private static function save( array $all ) {
		if ( count( $all ) > self::MAX_RECORDS ) {
			uasort(
				$all,
				static function ( $a, $b ) {
					return (int) $b['last_seen_at'] - (int) $a['last_seen_at'];
				}
			);
			$all = array_slice( $all, 0, self::MAX_RECORDS, true );
		}

		if ( ! $all ) {
			delete_option( self::OPTION );
			return;
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether the key or grant behind a record still exists.
	 *
	 * @param string $id     Connection id.
	 * @param array  $record The record.
	 * @return bool
	 */
	private static function credential_exists( $id, array $record ) {
		list( $kind, $ref ) = array_pad( explode( ':', (string) $id, 2 ), 2, '' );

		if ( 'key' === $kind && class_exists( 'WP_Application_Passwords' ) ) {
			return (bool) WP_Application_Passwords::get_user_application_password( isset( $record['user'] ) ? (int) $record['user'] : 0, $ref );
		}

		if ( 'oauth' === $kind && class_exists( 'Saddle_OAuth_Store' ) ) {
			return (bool) Saddle_OAuth_Store::get_grant( $ref );
		}

		return false;
	}

	/**
	 * A JSON-RPC body as a list of messages: a batch as it is, a single
	 * message wrapped.
	 *
	 * @param mixed $body Decoded body.
	 * @return array
	 */
	private static function as_list( $body ) {
		if ( ! is_array( $body ) || array() === $body ) {
			return array();
		}

		return array_keys( $body ) === range( 0, count( $body ) - 1 ) ? $body : array( $body );
	}

	/**
	 * A client-supplied string, cleaned and cut to length.
	 *
	 * @param mixed $value  Value from the request.
	 * @param int   $length Maximum length.
	 * @return string
	 */
	private static function clip( $value, $length ) {
		return is_scalar( $value ) ? substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}
}
