<?php
/**
 * Compatibility shims for the vendored MCP adapter.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Workarounds that exist only because `includes/lib/wp-mcp/` is vendored and
 * must stay byte-identical.
 *
 * The adapter requires an `Mcp-Session-Id` header on every request except
 * `initialize`, and hard-rejects any `Mcp-Protocol-Version` it does not know.
 * Both are stricter than the MCP specification, where the session header is
 * optional and stateless servers are legal — and both present to the owner as
 * an app that connects successfully and then reports that the site has no
 * callable actions, because `tools/list` never reaches the tools handler.
 *
 * So this class reshapes the request on its way in, before the adapter sees it.
 * It changes two headers and nothing else: it cannot alter a permission
 * outcome, and it never runs for a caller WordPress could not authenticate.
 *
 * Everything here is meant to be deleted in one commit the day the upstream fix
 * is vendored, which is why it lives in its own file rather than growing
 * {@see Saddle_MCP}.
 */
class Saddle_MCP_Compat {

	/**
	 * Marks the sessions this class minted, so we reuse our own rather than
	 * borrowing one a real client is relying on.
	 */
	const SESSION_TAG = 'saddle-stateless';

	/**
	 * Safety margin, in seconds, on the adapter's inactivity timeout. A session
	 * this close to expiring is treated as already gone, so it cannot lapse
	 * between our check and the adapter's.
	 */
	const EXPIRY_MARGIN = 60;

	/**
	 * The session id injected into the current request, if any.
	 *
	 * @var string
	 */
	private static $injected = '';

	/**
	 * Wire the shims. Called from Saddle::setup_mcp_transport().
	 */
	public static function register() {
		// Priority 11: after Saddle_OAuth_Bearer::confine (9) and
		// Saddle_Connection::scope_credentials (10), both of which return a
		// WP_Error that short-circuits the request. A call that is about to be
		// refused must never cause a session write.
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'normalize_request' ), 11, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'echo_session_header' ), 11, 3 );
		add_filter( 'rest_exposed_cors_headers', array( __CLASS__, 'expose_session_header' ) );
	}

	/**
	 * Give the adapter the headers it insists on.
	 *
	 * @param WP_REST_Response|WP_Error|null $response Short-circuit response, if an earlier filter set one.
	 * @param array                          $handler  Route handler.
	 * @param WP_REST_Request                $request  The request.
	 * @return WP_REST_Response|WP_Error|null
	 */
	public static function normalize_request( $response, $handler, $request ) {
		if ( null !== $response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		if ( ! self::applies_to( $request ) ) {
			return $response;
		}

		self::neutralize_protocol_version( $request );

		// Never mint a session for a caller WordPress could not resolve: the
		// transport gate is about to refuse them, and an anonymous request that
		// could write user meta would be a free denial-of-service.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $response;
		}

		$resolved = self::resolve_session_id( $user_id, $request->get_header( 'Mcp-Session-Id' ) );

		if ( 'client' !== $resolved['source'] && '' !== $resolved['id'] ) {
			$request->set_header( 'Mcp-Session-Id', $resolved['id'] );
			self::$injected = $resolved['id'];
		}

		return $response;
	}

	/**
	 * Whether this request is one we should reshape.
	 *
	 * The adapter check is deliberately at the point of use rather than once at
	 * boot: the built-in fallback transport ignores these headers entirely, and
	 * a differently-versioned adapter from another plugin may not play by the
	 * rules we read here.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	private static function applies_to( $request ) {
		if ( 'POST' !== $request->get_method() ) {
			return false;
		}

		$route = '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE;
		if ( 0 !== strpos( (string) $request->get_route(), $route ) ) {
			return false;
		}

		return class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' );
	}

	/**
	 * Decide which session id this request should carry.
	 *
	 * Reuse before create, always: a session per request would churn the
	 * adapter's 32-entry FIFO continuously and write user meta on every call.
	 * Steady state here is one session per user per idle window.
	 *
	 * A stale or unknown id is healed rather than passed through to the
	 * adapter's 404. The alternative assumes the client responds to that by
	 * re-running `initialize`, which is exactly the behaviour we cannot assume
	 * of the clients this shim exists for.
	 *
	 * @param int    $user_id   Resolved user.
	 * @param string $presented The Mcp-Session-Id header as sent, if any.
	 * @return array{id: string, source: string} Source is client|reused|created|none.
	 */
	public static function resolve_session_id( $user_id, $presented ) {
		$sessions = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( (int) $user_id );

		$presented = is_string( $presented ) ? $presented : '';
		if ( '' !== $presented && isset( $sessions[ $presented ] ) && self::session_is_live( $sessions[ $presented ] ) ) {
			return array(
				'id'     => $presented,
				'source' => 'client',
			);
		}

		$newest = '';
		$seen   = 0;
		foreach ( $sessions as $id => $session ) {
			if ( ! self::is_ours( $session ) || ! self::session_is_live( $session ) ) {
				continue;
			}

			$activity = isset( $session['last_activity'] ) ? (int) $session['last_activity'] : 0;
			if ( $activity >= $seen ) {
				$seen   = $activity;
				$newest = (string) $id;
			}
		}

		if ( '' !== $newest ) {
			return array(
				'id'     => $newest,
				'source' => 'reused',
			);
		}

		$created = \WP\MCP\Transport\Infrastructure\SessionManager::create_session(
			(int) $user_id,
			array( 'saddle' => self::SESSION_TAG )
		);

		if ( ! is_string( $created ) || '' === $created ) {
			// Nothing more to do: the adapter will refuse the call as it does
			// today, which is the behaviour this shim was improving on rather
			// than replacing.
			return array(
				'id'     => '',
				'source' => 'none',
			);
		}

		return array(
			'id'     => $created,
			'source' => 'created',
		);
	}

	/**
	 * Whether a stored session was minted by this class.
	 *
	 * @param array $session Stored session record.
	 * @return bool
	 */
	private static function is_ours( $session ) {
		return isset( $session['client_params']['saddle'] ) && self::SESSION_TAG === $session['client_params']['saddle'];
	}

	/**
	 * Whether a stored session will still be valid when the adapter checks it.
	 *
	 * Reads the adapter's own timeout filter so a site that tunes one tunes
	 * both, and subtracts a margin so the session cannot expire in the gap
	 * between this check and the adapter's.
	 *
	 * @param array $session Stored session record.
	 * @return bool
	 */
	private static function session_is_live( $session ) {
		if ( ! isset( $session['last_activity'] ) ) {
			return false;
		}

		/** This filter is documented in includes/lib/wp-mcp/includes/Transport/Infrastructure/SessionManager.php */
		$timeout = (int) apply_filters( 'mcp_adapter_session_inactivity_timeout', DAY_IN_SECONDS );

		return ( (int) $session['last_activity'] + $timeout - self::EXPIRY_MARGIN ) > time();
	}

	/**
	 * Drop an `Mcp-Protocol-Version` the adapter would refuse.
	 *
	 * Dropped rather than rewritten: an absent header is already accepted, and
	 * is the honest representation of a client that predates the header.
	 * Rewriting it would assert on the client's behalf that it speaks a
	 * revision it never claimed.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string The value as sent, for diagnostics. Empty when absent.
	 */
	private static function neutralize_protocol_version( $request ) {
		$sent = $request->get_header( 'Mcp-Protocol-Version' );
		if ( ! is_string( $sent ) || '' === $sent ) {
			return '';
		}

		if ( ! class_exists( '\WP\MCP\Core\McpVersionNegotiator' ) ) {
			return $sent;
		}

		if ( ! \WP\MCP\Core\McpVersionNegotiator::is_supported( $sent ) ) {
			$request->remove_header( 'Mcp-Protocol-Version' );
		}

		return $sent;
	}

	/**
	 * Hand an injected session id back to the client.
	 *
	 * A client that honours it graduates to the untouched path on its next
	 * call. Nothing depends on this arriving — the shim works on the request
	 * side precisely because a custom response header may not survive the
	 * caching and security layers in front of a customer's WordPress.
	 *
	 * @param WP_REST_Response $response Response about to be served.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  The request.
	 * @return WP_REST_Response
	 */
	public static function echo_session_header( $response, $server, $request ) {
		if ( '' === self::$injected || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$injected       = self::$injected;
		self::$injected = '';

		$route = '/' . Saddle_MCP::REST_NAMESPACE . Saddle_MCP::ROUTE;
		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( (string) $request->get_route(), $route ) ) {
			return $response;
		}

		$response->header( 'Mcp-Session-Id', $injected );

		return $response;
	}

	/**
	 * Let browser-based MCP clients read the session header.
	 *
	 * @param array $headers Exposed header names.
	 * @return array
	 */
	public static function expose_session_header( $headers ) {
		$headers   = is_array( $headers ) ? $headers : array();
		$headers[] = 'Mcp-Session-Id';

		return array_values( array_unique( $headers ) );
	}
}
