<?php
/**
 * MCP JSON-RPC transport.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes Saddle's registered abilities to MCP clients over a single
 * JSON-RPC 2.0 endpoint at /wp-json/saddle/v1/mcp.
 *
 * Authentication is delegated entirely to WordPress core: the route only
 * requires an authenticated user, and core resolves Basic-Auth Application
 * Passwords into the current user before our callback runs. Per-tool
 * authorization (tier + capability) and destructive-action confirmation are
 * enforced inside each ability via its permission_callback and the approval
 * gate — this transport is a thin dispatcher, not a second security layer.
 *
 * Only abilities namespaced `saddle/` are exposed; other plugins' abilities
 * registered with core are never surfaced here.
 */
class Saddle_MCP {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'saddle/v1';

	/**
	 * REST route (relative to the namespace).
	 */
	const ROUTE = '/mcp';

	/**
	 * MCP protocol revisions this transport understands, newest first. On
	 * `initialize` we echo back the client's requested version when it's one of
	 * these, otherwise we fall back to the newest we support — the same
	 * negotiation the official adapter performs, so the built-in fallback
	 * transport doesn't silently downgrade a modern client.
	 *
	 * @var string[]
	 */
	const SUPPORTED_PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2024-11-05' );

	/**
	 * Default protocol revision (newest supported) when the client requests one
	 * we don't recognize.
	 */
	const PROTOCOL_VERSION = '2025-11-25';

	/**
	 * Ability namespace prefix that gates which abilities are exposed.
	 */
	const ABILITY_PREFIX = 'saddle/';

	/**
	 * Server id used when registering with the MCP Adapter.
	 */
	const ADAPTER_SERVER_ID = 'saddle';

	/**
	 * Register Saddle's abilities as a custom server on the WordPress MCP
	 * Adapter. Hooked to `mcp_adapter_init`.
	 *
	 * Uses the same namespace/route as the built-in transport, so the endpoint
	 * URL (/wp-json/saddle/v1/mcp) is identical whether the adapter is present or
	 * not. Exposing our abilities as an explicit tool list (rather than relying
	 * on the adapter's default discover/execute meta-tools) surfaces all 19
	 * abilities as first-class MCP tools with their own schemas.
	 *
	 * @param object $adapter The McpAdapter instance passed by the action.
	 */
	public static function register_adapter_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$adapter->create_server(
			self::ADAPTER_SERVER_ID,
			self::REST_NAMESPACE,
			ltrim( self::ROUTE, '/' ),
			self::server_name(),
			__( 'Tiered, default-safe, approval-gated MCP access to posts, pages, and media.', 'saddle' ),
			SADDLE_VERSION,
			array( '\\WP\\MCP\\Transport\\HttpTransport' ),
			'\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
			'\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
			self::adapter_tool_names(),
			array(),
			array()
		);

		// Serve the full context in the initialize handshake, so a client that
		// surfaces `instructions` spares the agent a whole get-instructions
		// round trip — on shared hosts each round trip is a full WP boot.
		add_filter( 'mcp_adapter_initialize_response', array( __CLASS__, 'filter_adapter_initialize' ), 10, 2 );
	}

	/**
	 * Replace the adapter's default initialize `instructions` (the one-line
	 * server description) with the same full context get-instructions returns.
	 *
	 * @param object $result Initialize result DTO (toArray/fromArray).
	 * @param object $server The adapter's McpServer instance.
	 * @return object
	 */
	public static function filter_adapter_initialize( $result, $server ) {
		if (
			! is_object( $server )
			|| ! method_exists( $server, 'get_server_id' )
			|| self::ADAPTER_SERVER_ID !== $server->get_server_id()
			|| ! is_object( $result )
			|| ! method_exists( $result, 'toArray' )
		) {
			return $result;
		}

		$data                 = $result->toArray();
		$data['instructions'] = self::server_instructions();

		$class = get_class( $result );
		return $class::fromArray( $data );
	}

	/**
	 * The list of `saddle/` ability names to expose as adapter tools.
	 *
	 * Calling wp_get_abilities() here lazily triggers `wp_abilities_api_init`,
	 * so Saddle's abilities are registered and resolvable even though this runs
	 * during `mcp_adapter_init`.
	 *
	 * @return string[]
	 */
	private static function adapter_tool_names() {
		$names = array();

		$all = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		foreach ( $all as $key => $ability ) {
			$name = is_string( $key ) ? $key : ( method_exists( $ability, 'get_name' ) ? $ability->get_name() : '' );
			if ( '' !== $name && 0 === strpos( $name, self::ABILITY_PREFIX ) ) {
				$names[] = $name;
			}
		}

		sort( $names );
		return $names;
	}

	/**
	 * Register the JSON-RPC route (built-in transport; fallback when the MCP
	 * Adapter is not installed).
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'authenticated' ),
			)
		);
	}

	/**
	 * Transport-level gate: an authenticated WordPress user must be resolved.
	 *
	 * @return bool|WP_Error
	 */
	public static function authenticated() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'saddle_not_authenticated',
			__( 'Authentication required. Connect with a Saddle Application Password.', 'saddle' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Handle a JSON-RPC request (single or batch).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) || array() === $body ) {
			return new WP_REST_Response( self::error_envelope( null, -32700, __( 'Parse error: request body is not valid JSON-RPC.', 'saddle' ) ), 200 );
		}

		// A JSON array (sequential integer keys) is a batch of requests.
		$is_batch = array_keys( $body ) === range( 0, count( $body ) - 1 );

		if ( $is_batch ) {
			$responses = array();
			foreach ( $body as $single ) {
				$result = is_array( $single ) ? self::dispatch( $single ) : self::error_envelope( null, -32600, __( 'Invalid request.', 'saddle' ) );
				if ( null !== $result ) {
					$responses[] = $result;
				}
			}
			// All-notification batches yield no responses; reply with 204-equivalent empty body.
			return new WP_REST_Response( empty( $responses ) ? null : $responses, 200 );
		}

		$response = self::dispatch( $body );
		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Route a single JSON-RPC request object to its handler.
	 *
	 * @param array $req Decoded request object.
	 * @return array|null Response envelope, or null for notifications.
	 */
	private static function dispatch( array $req ) {
		$id     = array_key_exists( 'id', $req ) ? $req['id'] : null;
		$method = isset( $req['method'] ) && is_string( $req['method'] ) ? $req['method'] : '';
		$params = ( isset( $req['params'] ) && is_array( $req['params'] ) ) ? $req['params'] : array();

		switch ( $method ) {
			case 'initialize':
				$requested  = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
				$negotiated = in_array( $requested, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSION;

				return self::result_envelope(
					$id,
					array(
						'protocolVersion' => $negotiated,
						'capabilities'    => array( 'tools' => (object) array() ),
						'serverInfo'      => array(
							'name'    => self::server_name(),
							'version' => SADDLE_VERSION,
						),
						// MCP's standard steering channel. Populated so a client
						// that surfaces it gives the agent Saddle's scope + safety
						// rules up front, rather than depending on the agent to
						// call get-instructions unprompted.
						'instructions'    => self::server_instructions(),
					)
				);

			case 'ping':
				return self::result_envelope( $id, (object) array() );

			case 'tools/list':
				return self::result_envelope( $id, array( 'tools' => self::list_tools() ) );

			case 'tools/call':
				return self::call_tool( $id, $params );

			default:
				// Notifications (e.g. notifications/initialized) carry no id and
				// expect no response.
				if ( null === $id && 0 === strpos( $method, 'notifications/' ) ) {
					return null;
				}
				return self::error_envelope(
					$id,
					-32601,
					sprintf(
						/* translators: %s: JSON-RPC method name. */
						__( 'Method not found: %s', 'saddle' ),
						$method
					)
				);
		}
	}

	/**
	 * A per-site MCP server slug: "saddle-plugpress".
	 *
	 * Used as the server key in every client config the connect wizard
	 * generates, so a person connecting five Saddle sites sees five distinct
	 * entries ("saddle-plugpress", "saddle-divitorque", …) instead of a name
	 * collision — the same pattern claude.ai uses ("claude.ai Gmail").
	 *
	 * @return string
	 */
	public static function server_slug() {
		$base = sanitize_title( (string) get_bloginfo( 'name' ) );
		if ( '' === $base ) {
			$base = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}

		$slug = '' === $base ? 'saddle' : 'saddle-' . $base;

		/**
		 * Filter the per-site MCP server slug used in generated client configs.
		 *
		 * @param string $slug Slug, e.g. "saddle-plugpress".
		 */
		return (string) apply_filters( 'saddle_server_slug', $slug );
	}

	/**
	 * Human server name for the MCP initialize handshake: "Saddle (PlugPress)".
	 *
	 * @return string
	 */
	public static function server_name() {
		$site = trim( (string) get_bloginfo( 'name' ) );
		return '' === $site ? 'Saddle' : sprintf( 'Saddle (%s)', $site );
	}

	/**
	 * Concise steering delivered in the `initialize` result's `instructions`
	 * field. Reuses the same context Saddle exposes via the get-instructions
	 * ability, so the two never drift.
	 *
	 * @return string
	 */
	private static function server_instructions() {
		if ( class_exists( 'Saddle_Context' ) ) {
			$text = Saddle_Context::system_context();
			$user = Saddle_Context::user();
			if ( '' !== $user ) {
				$text .= "\n## Site owner's instructions\n\n" . $user . "\n";
			}
			return $text;
		}
		return __( 'Saddle exposes tiered, approval-gated access to this site\'s posts, pages, and media. Call saddle/get-instructions for the current scope and safety rules before acting.', 'saddle' );
	}

	/**
	 * Build the tools/list payload from registered `saddle/` abilities.
	 *
	 * @return array
	 */
	private static function list_tools() {
		$tools = array();

		foreach ( self::saddle_abilities() as $name => $ability ) {
			$schema = $ability->get_input_schema();
			if ( empty( $schema ) ) {
				$schema = array(
					'type'       => 'object',
					'properties' => (object) array(),
				);
			}

			$tools[] = array(
				'name'        => $name,
				'description' => $ability->get_description(),
				'inputSchema' => $schema,
			);
		}

		return $tools;
	}

	/**
	 * Execute a tools/call request.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param array $params Request params ({ name, arguments }).
	 * @return array Response envelope.
	 */
	private static function call_tool( $id, array $params ) {
		$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';

		if ( '' === $name || 0 !== strpos( $name, self::ABILITY_PREFIX ) ) {
			return self::error_envelope( $id, -32602, __( 'Invalid params: a Saddle tool name is required.', 'saddle' ) );
		}

		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			return self::error_envelope(
				$id,
				-32601,
				sprintf(
					/* translators: %s: tool name. */
					__( 'Tool not found: %s', 'saddle' ),
					$name
				)
			);
		}

		$arguments = ( isset( $params['arguments'] ) && is_array( $params['arguments'] ) ) ? $params['arguments'] : array();

		// Per the Abilities API contract, WP_Ability::execute() normalizes and
		// validates input, runs the permission_callback, and returns WP_Error on
		// denial or failure before invoking the execute_callback — so no separate
		// permission pre-check is needed here. (Build Guide Step 1 calls for
		// confirming this against the installed 6.9 core; the contract is
		// documented, but verify on first boot).
		$outcome = $ability->execute( $arguments );

		if ( is_wp_error( $outcome ) ) {
			$data       = array( 'wp_error_code' => $outcome->get_error_code() );
			$error_data = $outcome->get_error_data();
			if ( ! empty( $error_data ) ) {
				$data['details'] = $error_data;
			}
			return self::error_envelope( $id, -32000, $outcome->get_error_message(), $data );
		}

		return self::result_envelope(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $outcome ),
					),
				),
				'isError' => false,
			)
		);
	}

	/**
	 * All registered abilities in the `saddle/` namespace.
	 *
	 * @return array<string,WP_Ability>
	 */
	private static function saddle_abilities() {
		$all = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		$out = array();

		foreach ( $all as $key => $ability ) {
			// wp_get_abilities() keys the array by ability name; prefer the key
			// and fall back to the object accessor if the shape differs.
			$name = is_string( $key ) ? $key : ( method_exists( $ability, 'get_name' ) ? $ability->get_name() : '' );
			if ( '' !== $name && 0 === strpos( $name, self::ABILITY_PREFIX ) ) {
				$out[ $name ] = $ability;
			}
		}

		ksort( $out );
		return $out;
	}

	/**
	 * Build a JSON-RPC success envelope.
	 *
	 * @param mixed $id     Request id.
	 * @param mixed $result Result payload.
	 * @return array
	 */
	private static function result_envelope( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Build a JSON-RPC error envelope.
	 *
	 * @param mixed      $id      Request id.
	 * @param int        $code    JSON-RPC error code.
	 * @param string     $message Error message.
	 * @param array|null $data    Optional structured error data.
	 * @return array
	 */
	private static function error_envelope( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}
}
