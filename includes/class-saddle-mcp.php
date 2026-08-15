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
	 * on the adapter's default discover/execute meta-tools) surfaces every
	 * registered `saddle/` ability as a first-class MCP tool with its own schema.
	 *
	 * @param object $adapter The McpAdapter instance passed by the action.
	 */
	public static function register_adapter_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$names = self::adapter_tool_names();

		$created = $adapter->create_server(
			self::ADAPTER_SERVER_ID,
			self::REST_NAMESPACE,
			ltrim( self::ROUTE, '/' ),
			self::server_name(),
			__( 'Tiered, default-safe, approval-gated MCP access to posts, pages, and media.', 'saddle' ),
			SADDLE_VERSION,
			array( '\\WP\\MCP\\Transport\\HttpTransport' ),
			'\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
			'\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
			$names,
			array(),
			array(),
			// Transport permission callback. WITHOUT this the adapter falls back to
			// a bare `current_user_can('read')` (HttpTransport::check_permission),
			// which means Saddle's own gate — and the legible 401s it produces, and
			// the OAuth `WWW-Authenticate` challenge that hangs off them — would
			// only ever run on the fallback transport, i.e. never in practice.
			array( __CLASS__, 'authenticated' )
		);

		// Record what the server actually came up with. The adapter skips any
		// ability it fails to convert, one at a time, into error_log — so a
		// short or empty tool list is otherwise invisible until a customer
		// reports that their connected app has nothing to offer.
		self::record_server_health( $adapter, $created, $names );

		// Serve the full context in the initialize handshake, so a client that
		// surfaces `instructions` spares the agent a whole get-instructions
		// round trip — on shared hosts each round trip is a full WP boot.
		// Third-party hook: this filter belongs to the MCP Adapter plugin, so its
		// name is theirs and cannot carry a Saddle prefix. It only ever fires
		// when that plugin is the active transport.
		add_filter( 'mcp_adapter_initialize_response', array( __CLASS__, 'filter_adapter_initialize' ), 10, 2 );

		// Advertise only what this credential could actually call. Third-party
		// hook, same as the one above: it is the adapter's, fires inside
		// ToolsHandler::list_tools() at DISPATCH time — after authentication,
		// and after Saddle_OAuth_Bearer has set its scope ceiling on
		// determine_current_user — which is why the tier read here is the real
		// one. Registration above stays complete on purpose; see
		// adapter_tool_names().
		add_filter( 'mcp_adapter_tools_list', array( __CLASS__, 'filter_adapter_tools_list' ), 10, 2 );
	}

	/**
	 * Drop tools the current credential cannot call from the adapter's
	 * tools/list response.
	 *
	 * A default install sits at the `read` tier, where a third of the free
	 * toolset — and rather more of it once Pro and the integrations are on — is
	 * refused on every call. Listing those tools costs a schema each in the
	 * agent's context and buys a guaranteed refusal. What replaces them is one
	 * sentence in the instructions saying how many are withheld and who can
	 * unlock them, which is the part a user can act on.
	 *
	 * @param array  $tools  Tool DTOs the server holds.
	 * @param object $server The adapter's McpServer instance.
	 * @return array
	 */
	public static function filter_adapter_tools_list( $tools, $server = null ) {
		if ( ! is_array( $tools ) || ! class_exists( 'Saddle_Capabilities' ) ) {
			return $tools;
		}

		// Other servers may run on the same adapter; only ever filter ours.
		if ( is_object( $server ) && method_exists( $server, 'get_server_id' ) && self::ADAPTER_SERVER_ID !== $server->get_server_id() ) {
			return $tools;
		}

		$kept = array();
		foreach ( $tools as $tool ) {
			$name = is_object( $tool ) && method_exists( $tool, 'getName' ) ? (string) $tool->getName() : '';

			// Anything we can't identify is left alone rather than dropped: a
			// filter that silently eats an unrecognized tool is worse than one
			// that shows a tool too many.
			$ability = '' === $name ? '' : self::ability_name_for_tool( $name );
			if ( '' === $ability || Saddle_Capabilities::is_callable_now( $ability ) ) {
				$kept[] = $tool;
			}
		}

		return $kept;
	}

	/**
	 * Compare what we asked the server to expose against what it holds.
	 *
	 * @param object $adapter The McpAdapter instance.
	 * @param mixed  $created What create_server() returned — a WP_Error when it refused.
	 * @param array  $names   Ability names handed to the server.
	 */
	private static function record_server_health( $adapter, $created, array $names ) {
		if ( ! class_exists( 'Saddle_MCP_Diagnostics' ) ) {
			return;
		}

		if ( is_wp_error( $created ) ) {
			Saddle_MCP_Diagnostics::record_health(
				array(
					'expected'   => count( $names ),
					'registered' => 0,
					'missing'    => array(),
					'degraded'   => true,
					'error'      => $created->get_error_code(),
				)
			);

			return;
		}

		$registered = array();
		if ( method_exists( $adapter, 'get_server' ) ) {
			$server = $adapter->get_server( self::ADAPTER_SERVER_ID );
			if ( is_object( $server ) && method_exists( $server, 'get_tools' ) ) {
				$registered = array_keys( $server->get_tools() );
			}
		}

		Saddle_MCP_Diagnostics::record_health( Saddle_MCP_Diagnostics::assess( $names, $registered ) );
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

		// Don't advertise what we can't serve. The adapter hard-codes prompts
		// and resources into every handshake, but Saddle registers neither — so
		// a client dutifully follows up with resources/templates/list, which the
		// adapter's router doesn't implement, and gets a 404 back on a
		// capability we told it we had. That reads as a broken connector.
		if ( isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ) {
			if ( method_exists( $server, 'get_resources' ) && ! $server->get_resources() ) {
				unset( $data['capabilities']['resources'] );
			}
			if ( method_exists( $server, 'get_prompts' ) && ! $server->get_prompts() ) {
				unset( $data['capabilities']['prompts'] );
			}
		}

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
	 * Wired on BOTH transports — as the fallback route's `permission_callback`
	 * and as the adapter's `$transport_permission_callback` (see
	 * {@see self::register_adapter_server()}).
	 *
	 * A failed request can be here for three very different reasons that all look
	 * like a bare 401 to the caller — and each needs a different fix:
	 *
	 *   - A `Bearer` token was sent but didn't resolve: the OAuth access token
	 *     expired, was revoked, or is bound to another site. Fix = refresh, or
	 *     re-authorize the app.
	 *   - `Basic` credentials reached PHP but core rejected them: the Application
	 *     Password was revoked/deleted (or is wrong). Fix = reconnect the app.
	 *   - No credentials reached PHP at all: the host likely stripped the
	 *     `Authorization` header in transit. Fix = the connection self-check /
	 *     .htaccess forwarding rule.
	 *
	 * In practice core's own authenticator short-circuits a *rejected* Basic
	 * credential before the route runs (see {@see Saddle_Connection::explain_auth_error()},
	 * which relabels that 401); this gate distinguishes all three defensively.
	 *
	 * Note the adapter discards a WP_Error return and denies with core's generic
	 * message (HttpTransport::check_permission). {@see Saddle_OAuth_Bearer::challenge()}
	 * restores the legible body — and attaches the OAuth challenge — for both
	 * transports on the way out.
	 *
	 * @param WP_REST_Request|null $request Unused; the adapter passes the request.
	 * @return bool|WP_Error
	 */
	public static function authenticated( $request = null ) {
		// Accepted and discarded: the adapter hands its transport permission
		// callback the request, while the fallback route's permission_callback
		// passes nothing. Taking it optionally is what lets one gate serve both.
		unset( $request );

		if ( is_user_logged_in() ) {
			return true;
		}

		$scheme = class_exists( 'Saddle_Connection' ) ? Saddle_Connection::credential_scheme() : '';

		if ( 'bearer' === $scheme ) {
			return new WP_Error(
				'saddle_token_rejected',
				__( 'The access token was rejected — it has expired, been revoked, or was issued for a different site. Refresh it, or reconnect the app to authorize a new one.', 'saddle' ),
				array(
					'status' => 401,
					'reason' => 'token_rejected',
				)
			);
		}

		if ( 'basic' === $scheme ) {
			return new WP_Error(
				'saddle_credential_rejected',
				__( 'Your sign-in key was rejected — it was most likely revoked or removed. Reconnect the app from Saddle to issue a fresh key.', 'saddle' ),
				array(
					'status' => 401,
					'reason' => 'credential_rejected',
				)
			);
		}

		return new WP_Error(
			'saddle_no_credentials',
			__( 'The request arrived without a sign-in key. If you did connect one, your host may be stripping the Authorization header — open Saddle and run the connection check to confirm and fix it.', 'saddle' ),
			array(
				'status' => 401,
				'reason' => 'no_credentials',
			)
		);
	}

	/**
	 * Handle a JSON-RPC request (single or batch).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		// Stray output during dispatch — a PHP warning from any plugin, an
		// echo in a hook — would prepend to the JSON-RPC body and corrupt the
		// response. Buffer everything the handlers print and discard it; the
		// response object below is the only thing the client may receive.
		$ob_level = ob_get_level();
		ob_start();
		try {
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
		} finally {
			// Handlers may open/close buffers of their own — unwind exactly
			// back to where this method started, never past it.
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}
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
			// The handshake is not a way around the gates. It runs before any
			// ability's permission_callback — its only check is that someone is
			// logged in — so without this it served MORE than get-instructions
			// would (the whole context plus the owner's own instructions) with
			// FEWER checks: a paused site still answered, and every tier got the
			// same payload. Pause is the owner saying stop; honour it here too.
			if ( class_exists( 'Saddle_Capabilities' ) && Saddle_Capabilities::is_paused() ) {
				return __( 'Saddle is paused on this site, so no tools will run. The site owner can resume it in Saddle → Settings. Do not retry until they do.', 'saddle' );
			}

			// system_context() is already tier-aware, so what a session is told
			// tracks what it is allowed to do.
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
			// Same rule as the adapter path (filter_adapter_tools_list): only
			// advertise what this credential could call. On a WordPress.org
			// install this transport is the only one there is, so this is the
			// line that does the work for most sites.
			if ( class_exists( 'Saddle_Capabilities' ) && ! Saddle_Capabilities::is_callable_now( $name ) ) {
				continue;
			}

			$tool = array(
				'name'        => self::mcp_tool_name( $name ),
				'description' => $ability->get_description(),
				'inputSchema' => self::normalize_input_schema( $ability->get_input_schema() ),
			);

			$label = $ability->get_label();
			if ( '' !== $label ) {
				$tool['title'] = $label;
			}

			$annotations = self::tool_annotations( $ability, $label );
			if ( ! empty( $annotations ) ) {
				$tool['annotations'] = $annotations;
			}

			$tools[] = $tool;
		}

		return $tools;
	}

	/**
	 * The MCP tool name for an ability.
	 *
	 * Ability names are namespaced with a slash (`saddle/list-posts`), which MCP
	 * does not allow in a tool name and which OpenAI's clients reject outright
	 * (`^[a-zA-Z0-9_-]{1,64}$`) — a single bad name loses the whole tool list.
	 * Producing the same hyphenated form the official adapter produces keeps a
	 * tool's identity stable whichever transport served it.
	 *
	 * @param string $ability_name Full ability name.
	 * @return string
	 */
	private static function mcp_tool_name( $ability_name ) {
		return str_replace( '/', '-', (string) $ability_name );
	}

	/**
	 * Resolve a client-supplied tool name back to an ability name.
	 *
	 * Accepts both the hyphenated MCP form and the raw ability name, so a client
	 * that cached either one keeps working.
	 *
	 * @param string $tool_name Name as sent by the client.
	 * @return string Ability name, or '' when it isn't one of ours.
	 */
	private static function ability_name_for_tool( $tool_name ) {
		$tool_name = (string) $tool_name;

		if ( 0 === strpos( $tool_name, self::ABILITY_PREFIX ) ) {
			return $tool_name;
		}

		$prefix = self::mcp_tool_name( self::ABILITY_PREFIX );
		if ( 0 !== strpos( $tool_name, $prefix ) ) {
			return '';
		}

		// Only the namespace separator was rewritten, so restore that one
		// character — ability names may legitimately contain hyphens of their own.
		return self::ABILITY_PREFIX . substr( $tool_name, strlen( $prefix ) );
	}

	/**
	 * MCP behaviour hints for a tool.
	 *
	 * Every Saddle ability already declares whether it reads, destroys or is
	 * idempotent (see saddle_ability_meta()); this hands that to the client in
	 * MCP's own vocabulary so an agent can weigh a call before making it rather
	 * than discovering the answer from a refusal.
	 *
	 * @param WP_Ability $ability The ability.
	 * @param string     $label   Human-readable label, if any.
	 * @return array
	 */
	private static function tool_annotations( $ability, $label ) {
		$declared = $ability->get_meta_item( 'annotations' );
		if ( ! is_array( $declared ) ) {
			return array();
		}

		$annotations = array();

		if ( '' !== $label ) {
			$annotations['title'] = $label;
		}
		if ( isset( $declared['readonly'] ) ) {
			$annotations['readOnlyHint'] = (bool) $declared['readonly'];
		}
		if ( isset( $declared['destructive'] ) ) {
			$annotations['destructiveHint'] = (bool) $declared['destructive'];
		}
		if ( isset( $declared['idempotent'] ) ) {
			$annotations['idempotentHint'] = (bool) $declared['idempotent'];
		}

		return $annotations;
	}

	/**
	 * Coerce an ability's input schema into an MCP-compliant object schema.
	 *
	 * MCP requires every tool's `inputSchema` to be a JSON object whose
	 * `properties` map (when present) is itself a JSON object. PHP serializes an
	 * empty array as `[]`, so a no-argument ability that declares
	 * `'properties' => array()` — the shape some partner abilities wrapped via
	 * Saddle_Integrations arrive in — emits `"properties": []`. Strict clients
	 * reject the whole tools/list over that single mismatch, so we force the map
	 * to serialize as `{}` by casting it to an object. The official MCP Adapter
	 * transport already normalizes this; this keeps the built-in JSON-RPC
	 * fallback byte-for-byte compatible.
	 *
	 * @param mixed $schema Raw input schema from the ability.
	 * @return array MCP-compliant object schema.
	 */
	private static function normalize_input_schema( $schema ) {
		if ( ! is_array( $schema ) || empty( $schema ) ) {
			return array(
				'type'       => 'object',
				'properties' => (object) array(),
			);
		}

		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}

		// Force the properties map to serialize as a JSON object, never `[]`.
		if ( array_key_exists( 'properties', $schema ) && is_array( $schema['properties'] ) ) {
			$schema['properties'] = (object) $schema['properties'];
		}

		return $schema;
	}

	/**
	 * Execute a tools/call request.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param array $params Request params ({ name, arguments }).
	 * @return array Response envelope.
	 */
	private static function call_tool( $id, array $params ) {
		$requested = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$name      = self::ability_name_for_tool( $requested );

		if ( '' === $name ) {
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
		// permission pre-check is needed here. Verified against WP 6.9 core
		// (class-wp-ability.php: execute() calls check_permissions() first and
		// short-circuits unless it returns true) and pinned by
		// Saddle_MCP_Transport_Test::test_tools_call_enforces_tier_before_executing().
		$outcome = $ability->execute( $arguments );

		if ( is_wp_error( $outcome ) ) {
			$code    = $outcome->get_error_code();
			$message = $outcome->get_error_message();

			// Core's generic permission denial says nothing an agent can act
			// on. Reconstruct the actual gate (paused / tool off / access
			// level) so the agent stops retrying and tells the user the fix.
			if ( 'ability_invalid_permissions' === $code && class_exists( 'Saddle_Capabilities' ) ) {
				$reason = Saddle_Capabilities::denial_reason( $name );
				if ( $reason ) {
					$code    = $reason['code'];
					$message = $reason['message'];
				} else {
					$message .= ' ' . __( 'None of Saddle’s site-wide gates (pause, access level, per-tool toggles) blocked this — the connected WordPress account likely lacks a capability this tool requires, or the specific item is protected. Do not retry the same call.', 'saddle' );
				}
			}

			$data       = array( 'wp_error_code' => $code );
			$error_data = $outcome->get_error_data();
			if ( ! empty( $error_data ) ) {
				$data['details'] = $error_data;
			}

			// A refusal is a tool RESULT, not a protocol fault: the tool exists
			// and was called correctly, it just said no. MCP reserves JSON-RPC
			// errors for protocol-level problems and asks for execution failures
			// as `isError` results — and the distinction is load-bearing here,
			// because several clients treat a JSON-RPC error as a broken
			// transport and never show the model the message. Saddle's denial
			// text is written for the agent to act on ("the site is at the read
			// access level… do not retry"), so it has to reach the agent.
			return self::result_envelope(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $message,
						),
					),
					'isError' => true,
					'_meta'   => array( 'saddle' => $data ),
				)
			);
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
