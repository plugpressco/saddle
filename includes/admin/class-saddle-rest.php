<?php
/**
 * REST API powering the React admin UI.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only REST endpoints under `saddle/v1` for the settings UI:
 * the access tier, the connect URL, connected clients (core Application
 * Passwords filtered to Saddle's name prefix), and the audit log.
 *
 * These are distinct from the MCP transport: they require `manage_options`
 * and are consumed by the in-dashboard React app, not by MCP clients.
 */
class Saddle_REST_Admin {

	/**
	 * REST namespace (shared with the MCP transport, different routes).
	 */
	const REST_NAMESPACE = 'saddle/v1';

	/**
	 * Application Password name prefix Saddle issues and filters on.
	 */
	const CLIENT_PREFIX = Saddle_Connections::KEY_PREFIX;

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		// Some host WAFs intercept any REST path ending in the literal
		// `settings` segment before WordPress runs (20i's StackProtect answers
		// */settings itself with a non-JSON 401 — proven on a customer site;
		// its sibling routes oauth-settings/memory-settings pass, so the rule
		// is the exact segment). The dashboard therefore talks to
		// /preferences; /settings stays registered as an alias so an old
		// cached admin bundle keeps working through the ?rest_route= fallback.
		$settings_route = array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'tier'        => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => Saddle_Capabilities::tiers(),
					),
					'onboarded'   => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'paused'      => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'drafts_only' => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'rehearsal'   => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
			),
		);
		register_rest_route( self::REST_NAMESPACE, '/preferences', $settings_route );
		register_rest_route( self::REST_NAMESPACE, '/settings', $settings_route );

		register_rest_route(
			self::REST_NAMESPACE,
			'/first-look',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_first_look' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/capabilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_capabilities' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/abilities',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_disabled_abilities' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'disabled' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/integrations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_integrations' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_integration' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'slug'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/context',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_context' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_context' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'user' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/skills',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_skills' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'install_skill' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'md' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/skills/(?P<slug>[a-z0-9-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_skill' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_skill' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memory',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_memory' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_memory' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'text' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memory-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_memory_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memory-clear-agent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'clear_agent_memory' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memory/(?P<key>[a-z0-9-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_memory_entry' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_memory_entry' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/connect-url',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_connect_url' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/clients',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_clients' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_client' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/clients/(?P<uuid>[a-f0-9-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'revoke_client' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'uuid' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/clients/(?P<uuid>[a-f0-9-]+)/rotate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rotate_client' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'uuid' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/audit-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_audit_log' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					// Home's "This week" counts entries from this Unix time (UTC) on.
					'since' => array(
						'type'              => 'integer',
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					// Home's "Changes" counts only what apps did.
					'by'    => array(
						'type' => 'string',
						'enum' => array( 'app' ),
					),
				),
			)
		);

		// The owner's Undo on Home's Activity feed. The owner acts in wp-admin
		// with their cookie and the REST nonce, so this is an admin route, not
		// the agent's saddle/undo-changes. A call without a token previews and
		// changes nothing; the token it returns confirms that preview once.
		register_rest_route(
			self::REST_NAMESPACE,
			'/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'owner_undo' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'entries'       => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => Saddle_Undo::MAX_ENTRIES,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'confirm_token' => array(
						'type' => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth-settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_oauth_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_oauth_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'enabled' => array( 'type' => 'boolean' ),
						'dcr'     => array( 'type' => 'boolean' ),
						'cimd'    => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth-connections',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_oauth_connections' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth-connections/(?P<id>[a-f0-9]{32})',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'revoke_oauth_connection' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_oauth_connection' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'level' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => Saddle_Capabilities::tiers(),
						),
					),
				),
			)
		);
	}

	/**
	 * GET /oauth-settings — the switches plus whether this install can host an
	 * authorization server at all.
	 *
	 * The readiness facts are here rather than buried in a docs page because the
	 * two failure modes (plain permalinks, no HTTPS) are invisible from the
	 * client side: ChatGPT just says it couldn't connect.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_oauth_settings() {
		$readiness = Saddle_OAuth::readiness();

		return new WP_REST_Response(
			array(
				'enabled'    => Saddle_OAuth::is_enabled(),
				'dcr'        => (bool) get_option( Saddle_OAuth_Clients::DCR_OPTION, true ),
				'cimd'       => (bool) get_option( Saddle_OAuth_Clients::CIMD_OPTION, true ),
				'ready'      => $readiness['ready'],
				'permalinks' => $readiness['permalinks'],
				'ssl'        => $readiness['ssl'],
				'issuer'     => Saddle_OAuth::issuer(),
				'resource'   => Saddle_OAuth::resource_id(),
				// Whether the host-root discovery documents are actually
				// reachable. Only probed while OAuth is on — it is a loopback
				// HTTP request, and there is nothing to probe otherwise.
				'discovery'  => Saddle_OAuth::is_enabled() ? Saddle_OAuth_Discovery::probe_root() : 'unknown',
			),
			200
		);
	}

	/**
	 * POST /oauth-settings.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_oauth_settings( WP_REST_Request $request ) {
		if ( null !== $request->get_param( 'enabled' ) ) {
			$done = Saddle_Core_Settings::set_oauth_enabled( (bool) $request->get_param( 'enabled' ) );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}

		if ( null !== $request->get_param( 'dcr' ) ) {
			update_option( Saddle_OAuth_Clients::DCR_OPTION, $request->get_param( 'dcr' ) ? 1 : 0 );
		}

		if ( null !== $request->get_param( 'cimd' ) ) {
			update_option( Saddle_OAuth_Clients::CIMD_OPTION, $request->get_param( 'cimd' ) ? 1 : 0 );
		}

		return self::get_oauth_settings();
	}

	/**
	 * GET /oauth-connections — apps that completed a consent screen.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_oauth_connections() {
		$connections = array();

		foreach ( Saddle_OAuth_Store::list_grants() as $grant ) {
			$user = get_userdata( (int) $grant['user_id'] );

			$connections[] = array(
				'id'         => (string) $grant['grant_id'],
				'name'       => '' !== (string) $grant['client_name'] ? (string) $grant['client_name'] : (string) $grant['client_id'],
				'client_id'  => (string) $grant['client_id'],
				// Whether the app's identity was checked, or merely asserted.
				// The Connections screen says which, in as many words.
				'verified'   => 0 === stripos( (string) $grant['client_id'], 'https://' ),
				'scope'      => (string) $grant['scope'],
				'level'      => Saddle_OAuth::scope_to_tier( (string) $grant['scope'] ),
				'user_login' => $user ? $user->user_login : '',
				'created'    => (int) $grant['grant_created'],
				'last_used'  => (int) $grant['last_used'],
			);
		}

		return new WP_REST_Response( $connections, 200 );
	}

	/**
	 * DELETE /oauth-connections/{id}.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revoke_oauth_connection( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );

		if ( ! Saddle_OAuth_Store::revoke_grant( $id ) ) {
			return new WP_Error(
				'saddle_oauth_unknown_connection',
				__( 'That connection no longer exists. It may already be disconnected.', 'saddle' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'revoked' => true ), 200 );
	}

	/**
	 * POST /oauth-connections/{id} — change what a connected app may do.
	 *
	 * The repair path for a connection that was granted less than the owner
	 * wanted. Before this existed the only remedy was disconnect-and-reconnect,
	 * which did not help either: an app that requests no scope — ChatGPT — came
	 * back at the same level every time.
	 *
	 * Clamped to the site tier server-side. The route's `enum` only proves the
	 * value is a tier name; it says nothing about whether this site allows it.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_oauth_connection( WP_REST_Request $request ) {
		$id    = (string) $request->get_param( 'id' );
		$level = sanitize_key( (string) $request->get_param( 'level' ) );

		$grant = Saddle_OAuth_Store::get_grant( $id );
		if ( ! $grant ) {
			return new WP_Error(
				'saddle_oauth_unknown_connection',
				__( 'That connection no longer exists. It may already be disconnected.', 'saddle' ),
				array( 'status' => 404 )
			);
		}

		$scope = Saddle_OAuth::tier_to_scope( $level );
		$done  = Saddle_Access::set_role( 'oauth:' . $id, $level );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		if ( class_exists( 'Saddle_Log' ) ) {
			Saddle_Log::record(
				array(
					'action'  => 'oauth-level-changed',
					'target'  => (string) $grant['client_id'],
					'summary' => sprintf(
						/* translators: 1: app name, 2: its new access, e.g. "Edit content". */
						__( 'Set %1$s to “%2$s”', 'saddle' ),
						'' !== (string) $grant['client_name'] ? (string) $grant['client_name'] : (string) $grant['client_id'],
						Saddle_Access::labels()[ $level ]
					),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'id'    => $id,
				'scope' => $scope,
				'level' => $level,
			),
			200
		);
	}

	/**
	 * Capability gate for every admin route.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		return new WP_REST_Response(
			array(
				'tier'           => Saddle_Capabilities::get_site_tier(),
				'tiers'          => Saddle_Capabilities::tiers(),
				'default'        => Saddle_Capabilities::DEFAULT_TIER,
				'onboarded'      => Saddle_Onboarding::is_finished(),
				'paused'         => Saddle_Capabilities::is_paused(),
				'domain_warning' => ! Saddle_Capabilities::domain_matches_recorded(),
				'domain'         => array(
					'current'  => Saddle_Capabilities::current_domain(),
					'recorded' => Saddle_Capabilities::recorded_tier_domain(),
					'enforced' => Saddle_Capabilities::is_domain_enforced(),
				),
				'drafts_only'    => Saddle_Capabilities::is_drafts_only(),
				'rehearsal'      => Saddle_Capabilities::is_rehearsal(),
				// The key itself never leaves the server — only whether one is
				// set, plus a last-4 hint so the owner can recognize it.
				'unsplash'       => array(
					'configured' => Saddle_Unsplash::is_configured(),
					'key_hint'   => Saddle_Unsplash::key_hint(),
				),
			),
			200
		);
	}

	/**
	 * POST /settings. Accepts an optional tier, onboarded flag, and/or paused flag.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ) {
		// Every place a value could arrive, not a JSON body alone. Which keys are
		// *present* is load-bearing below — absent means "leave this alone", while
		// '' or null mean "clear it" — so this cannot collapse into get_param(),
		// which cannot tell those apart. Reading JSON only meant a form-encoded
		// `tier` passed the route's enum, passed can_manage, returned 200, and
		// saved nothing: indistinguishable, from the dashboard, from the setting
		// simply not sticking. This codebase already works around one host that
		// rewrites request bodies (see the /preferences alias above).
		$json   = $request->get_json_params();
		$params = array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params(),
			is_array( $json ) ? $json : array()
		);

		if ( array_key_exists( 'tier', $params ) ) {
			if ( ! Saddle_Capabilities::set_tier( $request->get_param( 'tier' ) ) ) {
				return new WP_Error( 'saddle_invalid_tier', __( 'Unknown access tier.', 'saddle' ), array( 'status' => 400 ) );
			}
		}

		if ( array_key_exists( 'onboarded', $params ) ) {
			Saddle_Onboarding::set_finished_flag( (bool) $request->get_param( 'onboarded' ) );
		}

		if ( array_key_exists( 'paused', $params ) ) {
			Saddle_Capabilities::set_paused( (bool) $request->get_param( 'paused' ) );
		}

		if ( array_key_exists( 'domain_enforced', $params ) ) {
			Saddle_Capabilities::set_domain_enforcement( (bool) $request->get_param( 'domain_enforced' ) );
		}

		if ( array_key_exists( 'drafts_only', $params ) ) {
			Saddle_Capabilities::set_drafts_only( (bool) $request->get_param( 'drafts_only' ) );
		}

		if ( array_key_exists( 'rehearsal', $params ) ) {
			Saddle_Capabilities::set_rehearsal( (bool) $request->get_param( 'rehearsal' ) );
		}

		// Key absent from the body ⇒ untouched; '' or null ⇒ cleared;
		// non-empty ⇒ validated and saved.
		if ( array_key_exists( 'unsplash_access_key', $params ) ) {
			$saved = Saddle_Unsplash::set_key( (string) $request->get_param( 'unsplash_access_key' ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return self::get_settings();
	}

	/**
	 * GET /first-look — what the first-run screen says about this site.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_first_look() {
		return new WP_REST_Response( Saddle_First_Look::summary(), 200 );
	}

	/**
	 * GET /context — the read-only system context plus the owner's instructions.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_context() {
		return new WP_REST_Response( Saddle_Context::all(), 200 );
	}

	/**
	 * POST /context — save the owner's instructions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_context( WP_REST_Request $request ) {
		Saddle_Context::set_user( (string) $request->get_param( 'user' ) );
		return self::get_context();
	}

	/**
	 * GET /skills — every installed skill, with bodies, for the Guidance UI.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_skills() {
		return new WP_REST_Response( array( 'skills' => Saddle_Skills::all( true ) ), 200 );
	}

	/**
	 * POST /skills — install (or update) a skill from raw SKILL.md text.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function install_skill( WP_REST_Request $request ) {
		$result = Saddle_Skills::install( (string) $request->get_param( 'md' ), 'owner-upload' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::get_skills();
	}

	/**
	 * POST /skills/{slug} — enable or disable a skill.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_skill( WP_REST_Request $request ) {
		$ok = Saddle_Skills::set_enabled(
			(string) $request->get_param( 'slug' ),
			(bool) $request->get_param( 'enabled' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'saddle_skill_not_found', __( 'No skill with that name.', 'saddle' ), array( 'status' => 404 ) );
		}
		return self::get_skills();
	}

	/**
	 * DELETE /skills/{slug} — remove a skill permanently.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_skill( WP_REST_Request $request ) {
		if ( ! Saddle_Skills::delete( (string) $request->get_param( 'slug' ) ) ) {
			return new WP_Error( 'saddle_skill_not_found', __( 'No skill with that name.', 'saddle' ), array( 'status' => 404 ) );
		}
		return self::get_skills();
	}

	/**
	 * GET /memory — every entry, the memory options, and a preview of the
	 * exact core block agents receive at session start (the owner's ground
	 * truth for "what does Saddle remember").
	 *
	 * @return WP_REST_Response
	 */
	public static function get_memory() {
		return new WP_REST_Response(
			array(
				'entries'  => Saddle_Memory::all(),
				'settings' => array(
					'autoinject_agent' => (bool) get_option( Saddle_Memory::OPTION_AUTOINJECT, false ),
					'core_budget'      => (int) get_option( Saddle_Memory::OPTION_CORE_BUDGET, Saddle_Memory::DEFAULT_CORE_BUDGET ),
					'max_entries'      => Saddle_Memory::max_entries(),
				),
				'preview'  => Saddle_Memory::core_block(),
			),
			200
		);
	}

	/**
	 * POST /memory — the owner saves (or updates) an entry. Owner-authored,
	 * so it participates in the injected core block by default.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_memory( WP_REST_Request $request ) {
		$result = Saddle_Memory::remember(
			array(
				'key'        => (string) $request->get_param( 'key' ),
				'text'       => (string) $request->get_param( 'text' ),
				'type'       => (string) $request->get_param( 'type' ),
				'tags'       => $request->get_param( 'tags' ),
				'importance' => $request->get_param( 'importance' ),
			),
			'owner'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::get_memory();
	}

	/**
	 * POST /memory/{key} — pin/unpin, importance, or content edits.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_memory_entry( WP_REST_Request $request ) {
		$fields = array();
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		foreach ( array( 'pinned', 'importance', 'text', 'type', 'tags' ) as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$fields[ $field ] = $params[ $field ];
			}
		}

		$result = Saddle_Memory::update_entry( (string) $request->get_param( 'key' ), $fields );
		if ( null === $result ) {
			return new WP_Error( 'saddle_memory_not_found', __( 'No memory entry with that key.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::get_memory();
	}

	/**
	 * DELETE /memory/{key}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_memory_entry( WP_REST_Request $request ) {
		if ( ! Saddle_Memory::forget( (string) $request->get_param( 'key' ) ) ) {
			return new WP_Error( 'saddle_memory_not_found', __( 'No memory entry with that key.', 'saddle' ), array( 'status' => 404 ) );
		}
		return self::get_memory();
	}

	/**
	 * POST /memory-settings — the master toggles (agent auto-inject, budget).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_memory_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		if ( array_key_exists( 'autoinject_agent', $params ) ) {
			update_option( Saddle_Memory::OPTION_AUTOINJECT, (bool) $params['autoinject_agent'] );
		}
		if ( array_key_exists( 'core_budget', $params ) ) {
			update_option( Saddle_Memory::OPTION_CORE_BUDGET, max( 200, min( 10000, (int) $params['core_budget'] ) ) );
		}

		return self::get_memory();
	}

	/**
	 * POST /memory-clear-agent — the one-click "clear agent memory".
	 *
	 * @return WP_REST_Response
	 */
	public static function clear_agent_memory() {
		$removed = Saddle_Memory::clear_agent();
		if ( $removed && class_exists( 'Saddle_Log' ) ) {
			Saddle_Log::record(
				array(
					'action'  => 'clear-agent-memory',
					'target'  => 'memory',
					'summary' => sprintf(
						/* translators: %d: entries removed. */
						__( 'Owner cleared all agent-written memory (%d entries).', 'saddle' ),
						$removed
					),
				)
			);
		}
		return self::get_memory();
	}

	/**
	 * Tool-name prefixes the single-tools list files under "Integrations".
	 *
	 * Derived from the live catalog rather than listed by hand. The hand-kept
	 * list silently dropped Mailyard's wrapped tools into "Other" the moment it
	 * started self-enrolling through `saddle_integrations` (issue #59), and any
	 * future plugin doing the same would land there too — a grouping bug nobody
	 * would think to look for in a REST controller.
	 *
	 * The literals stay as a floor so nothing regroups on sites where a
	 * contributor enrols later than this runs (Saddle Pro's `knovia-`), and
	 * `unsplash-` is here despite not being a wrapper at all: it is an external
	 * service from the owner's point of view, which is what this grouping is
	 * about.
	 *
	 * @return string[]
	 */
	private static function integration_prefixes() {
		$prefixes = array( 'waggle-', 'knovia-', 'unsplash-' );

		if ( class_exists( 'Saddle_Integrations' ) ) {
			foreach ( array_keys( Saddle_Integrations::integrations() ) as $slug ) {
				$prefixes[] = $slug . '-';
			}
		}

		// The native integrations — each only while its plugin is detected:
		// the panel says DETECTED, and a Yoast row on a site without Yoast
		// would be a lie.
		foreach ( self::native_prefixes() as $prefix => $probe ) {
			if ( is_callable( $probe ) && call_user_func( $probe ) ) {
				$prefixes[] = $prefix;
			}
		}

		/**
		 * Filter the prefixes grouped under "Integrations" in the Permissions UI.
		 *
		 * For a contributor that registers its wrappers through its own engine
		 * rather than free's catalog.
		 *
		 * @param string[] $prefixes Tool-name prefixes.
		 */
		return array_values( array_unique( (array) apply_filters( 'saddle_integration_ui_prefixes', $prefixes ) ) );
	}

	/**
	 * Saddle's own integration tools, by name prefix, with the probe that says
	 * whether the plugin behind them is active.
	 *
	 * @return array<string,callable>
	 */
	private static function native_prefixes() {
		return array(
			'yoast-'     => array( 'Saddle_Yoast', 'is_active' ),
			'rank-math-' => array( 'Saddle_Rank_Math', 'is_active' ),
			'aioseo-'    => array( 'Saddle_Aioseo', 'is_active' ),
			'wc-'        => array( 'Saddle_WC', 'is_active' ),
		);
	}

	/**
	 * Group an ability under a human-readable category so the Permissions UI can
	 * present ~55 free (plus any add-on) abilities as scannable groups instead of
	 * one flat wall of chips. First matching rule wins; add-ons refine their own
	 * abilities via the `saddle_ability_category` filter (e.g. Saddle Pro splits
	 * its `divi-*` abilities into Divi pages / Design system / Templates).
	 *
	 * @param string $short Ability id without the `saddle/` prefix.
	 * @param string $name  Full ability id.
	 * @return string Category label.
	 */
	private static function category_for( $short, $name ) {
		$category = 'Other';

		// Integrations match on the start of the name only, and before every
		// other rule: as a substring rule further down, a partner tool such as
		// acme-get-design-tokens was filed under "Design system".
		//
		// The longest matching prefix wins, native ones included whether or
		// not their plugin is active: Saddle Rank's `rank-` is a prefix of
		// Saddle's own `rank-math-`, and must not claim those tools — they are
		// Integrations only while Rank Math is detected.
		$listed  = self::integration_prefixes();
		$claimed = '';
		foreach ( array_merge( $listed, array_keys( self::native_prefixes() ) ) as $prefix ) {
			if ( 0 === strpos( $short, $prefix ) && strlen( $prefix ) > strlen( $claimed ) ) {
				$claimed = $prefix;
			}
		}
		if ( '' !== $claimed && in_array( $claimed, $listed, true ) ) {
			$category = 'Integrations';
		}

		$rules = array(
			// label => substrings (first hit wins).
			'Design system'   => array( 'design-system', 'design-tokens', 'bootstrap-design' ),
			'Divi'            => array( 'divi-' ),
			'Memory & skills' => array( 'remember', 'recall', 'forget', 'skill', 'instructions', 'context' ),
			'Blocks & layout' => array( 'block', 'render-node', 'verify-page', 'lint-page', 'preview', 'recipe' ),
			// AFTER 'Blocks & layout' on purpose: that rule matches 'block', so
			// list-block-patterns and insert-block-pattern already live there.
			// Putting 'pattern' ahead of it would silently move both to a screen
			// section the owner has never seen them in.
			'Site editor'     => array( 'template', 'global-styles', 'pattern' ),
			'Users'           => array( 'user' ),
			'Site & settings' => array( 'option', 'plugin', 'theme', 'cache', 'site-info', 'self-check' ),
			'Content'         => array( 'post', 'page', 'media', 'categor', 'tag', 'revision', 'search' ),
		);

		foreach ( 'Other' === $category ? $rules : array() as $label => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $short, $needle ) ) {
					$category = $label;
					break 2;
				}
			}
		}

		/**
		 * Filter an ability's Permissions-UI category label.
		 *
		 * @param string $category Derived category label.
		 * @param string $short    Ability id without the `saddle/` prefix.
		 * @param string $name     Full ability id.
		 */
		return (string) apply_filters( 'saddle_ability_category', $category, $short, $name );
	}

	/**
	 * GET /capabilities — the introspectable catalog powering the Capability Map.
	 *
	 * Returns every `saddle/` ability with its label, description, required tier,
	 * and behavioral flags, grouped into a "lane" (look / change / remove) so the
	 * UI can show, per tier, exactly what agents can and cannot do.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_capabilities() {
		$catalog   = array();
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		foreach ( $abilities as $key => $ability ) {
			$name = is_string( $key ) ? $key : $ability->get_name();
			if ( 0 !== strpos( $name, 'saddle/' ) ) {
				continue;
			}

			$meta        = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			$readonly    = ! empty( $annotations['readonly'] );
			$destructive = ! empty( $annotations['destructive'] );
			$tier        = isset( $meta['saddle']['tier'] ) ? $meta['saddle']['tier'] : ( $readonly ? 'read' : 'write' );

			// Lane: remove (destructive) > change (write, additive) > look (read).
			if ( $destructive ) {
				$lane = 'remove';
			} elseif ( $readonly ) {
				$lane = 'look';
			} else {
				$lane = 'change';
			}

			$short = substr( $name, strlen( 'saddle/' ) );

			$catalog[] = array(
				'name'        => $name,
				'short'       => $short,
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'tier'        => $tier,
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'lane'        => $lane,
				'category'    => self::category_for( $short, $name ),
				'enabled'     => Saddle_Capabilities::is_ability_enabled( $short ),
				// False when the tool cannot run on this site: its plugin is
				// not active, or its service has no key. Such a tool is also
				// left out of tools/list.
				'available'   => Saddle_Services::has_tools_available( $short ),
			);
		}

		return new WP_REST_Response(
			array(
				'capabilities' => $catalog,
				'current_tier' => Saddle_Capabilities::get_site_tier(),
				'tiers'        => Saddle_Capabilities::tiers(),
				'disabled'     => Saddle_Capabilities::disabled_abilities(),
			),
			200
		);
	}

	/**
	 * POST /abilities — persist the set of individually-disabled ability short
	 * names. Orthogonal to the tier; lets an owner turn off one specific tool
	 * without dropping the whole site to a lower tier.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_disabled_abilities( WP_REST_Request $request ) {
		$disabled = (array) $request->get_param( 'disabled' );
		$saved    = Saddle_Capabilities::set_disabled_abilities( $disabled );

		return new WP_REST_Response( array( 'disabled' => $saved ), 200 );
	}

	/**
	 * GET /integrations — every installed integration, for the Integrations
	 * screen: enrolled plugins (PlugPress and third-party) plus Saddle's
	 * built-in ones.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_integrations() {
		return new WP_REST_Response( array( 'integrations' => self::integration_rows() ), 200 );
	}

	/**
	 * POST /integrations — switch one third-party integration on or off.
	 *
	 * @param WP_REST_Request $request Request with `slug` and `enabled`.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_integration( WP_REST_Request $request ) {
		$result = self::switch_integration( (string) $request->get_param( 'slug' ), (bool) $request->get_param( 'enabled' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'integrations' => self::integration_rows() ), 200 );
	}

	/**
	 * Approve or switch off one third-party integration, and log it. The one
	 * path behind POST /integrations and POST /services/{key}/enabled.
	 *
	 * @param string $slug    Integration slug.
	 * @param bool   $enabled Whether to approve it.
	 * @return true|WP_Error
	 */
	public static function switch_integration( $slug, $enabled ) {
		$result = Saddle_Integrations::set_approved( $slug, $enabled );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$catalog = Saddle_Integrations::integrations();
		$title   = isset( $catalog[ $slug ] ) ? $catalog[ $slug ]['title'] : $slug;

		if ( class_exists( 'Saddle_Log' ) ) {
			Saddle_Log::record(
				array(
					'action'  => $enabled ? 'integration-enabled' : 'integration-disabled',
					'target'  => $slug,
					'summary' => sprintf(
						/* translators: %s: plugin name. */
						$enabled ? __( 'Switched on the %s integration', 'saddle' ) : __( 'Switched off the %s integration', 'saddle' ),
						$title
					),
				)
			);
		}

		return true;
	}

	/**
	 * The Services section's rows: enrolled integrations, then the
	 * built-in ones whose plugin is detected.
	 *
	 * @return array[]
	 */
	private static function integration_rows() {
		$rows  = Saddle_Integrations::listing();
		$names = function_exists( 'wp_get_abilities' ) ? array_keys( wp_get_abilities() ) : array();

		// Built in: tools Saddle ships itself, shown only while their plugin is
		// detected (a Yoast row on a site without Yoast would be a lie).
		$natives = array(
			'yoast'     => array( __( 'Yoast SEO', 'saddle' ), __( 'Reads and edits Yoast’s own SEO fields.', 'saddle' ), array( 'Saddle_Yoast', 'is_active' ) ),
			'rank-math' => array( __( 'Rank Math', 'saddle' ), __( 'Reads and edits Rank Math’s own SEO fields.', 'saddle' ), array( 'Saddle_Rank_Math', 'is_active' ) ),
			'aioseo'    => array( __( 'AIOSEO', 'saddle' ), __( 'Reads and edits AIOSEO’s own SEO fields.', 'saddle' ), array( 'Saddle_Aioseo', 'is_active' ) ),
			'wc'        => array( __( 'WooCommerce', 'saddle' ), __( 'Products and orders.', 'saddle' ), array( 'Saddle_WC', 'is_active' ) ),
			'unsplash'  => array( __( 'Unsplash', 'saddle' ), __( 'Stock-photo search and import, built into Saddle. Needs an Access Key under Saddle → Services.', 'saddle' ), null ),
		);
		foreach ( $natives as $slug => $native ) {
			list( $title, $description, $probe ) = $native;
			if ( null !== $probe && ! ( is_callable( $probe ) && call_user_func( $probe ) ) ) {
				continue;
			}
			$count = 0;
			foreach ( $names as $name ) {
				if ( 0 === strpos( (string) $name, 'saddle/' . $slug . '-' ) ) {
					++$count;
				}
			}
			if ( ! $count ) {
				continue;
			}
			$rows[] = array(
				'slug'        => $slug,
				'title'       => $title,
				'description' => $description,
				'author'      => '',
				'url'         => '',
				'source'      => 'built-in',
				'enabled'     => true,
				'tools'       => $count,
			);
		}

		/**
		 * Filter the Services section's rows.
		 *
		 * For an add-on that wraps tools through its own engine rather than
		 * free's catalog, so its integrations can appear here too.
		 *
		 * @param array[] $rows Rows: slug, title, description, author, url,
		 *                      source, enabled, tools.
		 */
		return array_values( (array) apply_filters( 'saddle_integration_listing', $rows ) );
	}

	/**
	 * GET /connect-url?name=...
	 *
	 * Builds a URL to WordPress core's Authorize Application screen. On approval
	 * core redirects back to the Saddle admin page with the issued credentials.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_connect_url( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			$name = __( 'MCP Client', 'saddle' );
		}

		$app_name    = self::CLIENT_PREFIX . $name;
		$success_url = admin_url( 'admin.php?page=saddle&connected=1' );
		$reject_url  = admin_url( 'admin.php?page=saddle&rejected=1' );

		$url = add_query_arg(
			array(
				'app_name'    => rawurlencode( $app_name ),
				'success_url' => rawurlencode( $success_url ),
				'reject_url'  => rawurlencode( $reject_url ),
			),
			admin_url( 'authorize-application.php' )
		);

		return new WP_REST_Response( array( 'url' => $url ), 200 );
	}

	/**
	 * POST /clients — issue a Saddle credential directly.
	 *
	 * Creates a core Application Password for the current, already-authenticated
	 * admin (the user initiated this themselves from the dashboard, so the
	 * Authorize Application consent screen adds no information — and skipping it
	 * keeps the secret out of any URL). The raw password is returned exactly once
	 * in this response and never stored or shown again; core stores only its hash.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_client( WP_REST_Request $request ) {
		$user = wp_get_current_user();

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'saddle_app_passwords_unavailable', __( 'Application Passwords are not available on this site.', 'saddle' ), array( 'status' => 500 ) );
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error( 'saddle_app_passwords_unavailable', __( 'Application Passwords are off on this site. Serve the site over HTTPS, or turn them back on in your security plugin.', 'saddle' ), array( 'status' => 400 ) );
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			$name = __( 'AI app', 'saddle' );
		}

		// Core rejects duplicate names; suffix a counter so "Claude Code" can be
		// connected on a second machine without the user inventing a new name.
		$app_name = self::CLIENT_PREFIX . $name;
		$suffix   = 2;
		while ( WP_Application_Passwords::application_name_exists_for_user( $user->ID, $app_name ) ) {
			$app_name = self::CLIENT_PREFIX . $name . ' ' . $suffix;
			++$suffix;
		}

		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => $app_name )
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		list( $raw_password, $item ) = $created;

		// Remember the key's last four characters (card-last4 style) so the
		// Connect tab can say WHICH key a row is. Core stores only a hash, and
		// this is the single moment the raw secret exists — never persisted
		// whole, and four characters of a 24-char random secret enable nothing.
		$hint  = substr( str_replace( ' ', '', $raw_password ), -4 );
		$hints = get_user_meta( $user->ID, 'saddle_client_hints', true );
		$hints = is_array( $hints ) ? $hints : array();

		$hints[ $item['uuid'] ] = $hint;
		update_user_meta( $user->ID, 'saddle_client_hints', $hints );

		// The immutable ownership marker credential scoping keys on — the
		// display name alone is user-editable and can't be trusted for it.
		Saddle_Connection::mark_issued( $user->ID, $item['uuid'] );

		// A new app starts read-only; the owner raises it per app.
		Saddle_Access::set_role( 'key:' . $item['uuid'], 'read' );

		return new WP_REST_Response(
			array(
				'uuid'       => $item['uuid'],
				'name'       => $app_name,
				'label'      => trim( substr( $app_name, strlen( self::CLIENT_PREFIX ) ) ),
				'password'   => $raw_password,
				'user_login' => $user->user_login,
				'created'    => isset( $item['created'] ) ? (int) $item['created'] : 0,
				'hint'       => $hint,
			),
			201
		);
	}

	/**
	 * GET /clients — Saddle-issued Application Passwords for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_clients() {
		$user_id = get_current_user_id();
		$clients = array();

		if ( class_exists( 'WP_Application_Passwords' ) ) {
			$hints = get_user_meta( $user_id, 'saddle_client_hints', true );
			$hints = is_array( $hints ) ? $hints : array();

			$passwords = WP_Application_Passwords::get_user_application_passwords( $user_id );
			foreach ( (array) $passwords as $item ) {
				// Marker first (rename-proof), name prefix for legacy keys.
				if ( empty( $item['uuid'] ) || ! Saddle_Connection::is_saddle_issued( $user_id, $item['uuid'] ) ) {
					continue;
				}
				$name      = isset( $item['name'] ) ? (string) $item['name'] : '';
				$clients[] = array(
					'uuid'      => $item['uuid'],
					'name'      => $name,
					'label'     => 0 === strpos( $name, self::CLIENT_PREFIX ) ? trim( substr( $name, strlen( self::CLIENT_PREFIX ) ) ) : $name,
					'created'   => isset( $item['created'] ) ? (int) $item['created'] : 0,
					'last_used' => isset( $item['last_used'] ) ? $item['last_used'] : null,
					'last_ip'   => isset( $item['last_ip'] ) ? $item['last_ip'] : null,
					// Last four of the key, captured at issuance; null for
					// credentials from before this feature existed.
					'hint'      => isset( $hints[ $item['uuid'] ] ) ? (string) $hints[ $item['uuid'] ] : null,
				);
			}
		}

		return new WP_REST_Response( array( 'clients' => $clients ), 200 );
	}

	/**
	 * DELETE /clients/{uuid} — revoke a Saddle-issued Application Password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revoke_client( WP_REST_Request $request ) {
		$uuid    = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		$user_id = get_current_user_id();

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'saddle_app_passwords_unavailable', __( 'Application Passwords are not available on this site.', 'saddle' ), array( 'status' => 500 ) );
		}

		// Only allow revoking a Saddle-issued password, so this endpoint can't
		// be used to delete unrelated credentials. Checked via the immutable
		// marker (with legacy name-prefix fallback), so a renamed key can
		// still be revoked here.
		$item = WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
		if ( ! $item || ! Saddle_Connection::is_saddle_issued( $user_id, $uuid ) ) {
			return new WP_Error( 'saddle_client_not_found', __( 'No Saddle client with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}

		$result = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Saddle_Connection::unmark_issued( $user_id, $uuid );

		// Drop the stored last-4 hint along with the credential.
		$hints = get_user_meta( $user_id, 'saddle_client_hints', true );
		if ( is_array( $hints ) && isset( $hints[ $uuid ] ) ) {
			unset( $hints[ $uuid ] );
			if ( $hints ) {
				update_user_meta( $user_id, 'saddle_client_hints', $hints );
			} else {
				delete_user_meta( $user_id, 'saddle_client_hints' );
			}
		}

		return new WP_REST_Response(
			array(
				'revoked' => true,
				'uuid'    => $uuid,
			),
			200
		);
	}

	/**
	 * POST /clients/{uuid}/rotate — revoke a Saddle credential and issue a
	 * fresh one under the exact same name, in one step.
	 *
	 * The old key stops working the moment this returns; the new raw password
	 * appears once in the response (like create_client) and is never stored
	 * whole. If issuing the replacement fails after the old key is gone, the
	 * error says so plainly — two live keys are never left behind.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rotate_client( WP_REST_Request $request ) {
		$uuid = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		$user = wp_get_current_user();

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'saddle_app_passwords_unavailable', __( 'Application Passwords are not available on this site.', 'saddle' ), array( 'status' => 500 ) );
		}

		// Same guard as revoke_client: only Saddle-issued credentials.
		$item = WP_Application_Passwords::get_user_application_password( $user->ID, $uuid );
		if ( ! $item || ! Saddle_Connection::is_saddle_issued( $user->ID, $uuid ) ) {
			return new WP_Error( 'saddle_client_not_found', __( 'No Saddle client with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}

		$app_name = (string) $item['name'];
		$role     = Saddle_Access::role_for( 'key:' . $uuid );

		// Old key first — its name must be free so the replacement can keep it,
		// and a rotation must never leave two live keys.
		$deleted = WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => $app_name )
		);
		if ( is_wp_error( $created ) ) {
			return new WP_Error(
				'saddle_rotate_failed',
				__( 'The old key was removed, but issuing its replacement failed. Connect the app again from the Connections screen.', 'saddle' ),
				array( 'status' => 500 )
			);
		}

		list( $raw_password, $new_item ) = $created;

		// Swap the last-4 hint and the issued marker: old uuid out, new one in.
		$hint  = substr( str_replace( ' ', '', $raw_password ), -4 );
		$hints = get_user_meta( $user->ID, 'saddle_client_hints', true );
		$hints = is_array( $hints ) ? $hints : array();
		unset( $hints[ $uuid ] );
		$hints[ $new_item['uuid'] ] = $hint;
		update_user_meta( $user->ID, 'saddle_client_hints', $hints );
		Saddle_Connection::unmark_issued( $user->ID, $uuid );
		Saddle_Connection::mark_issued( $user->ID, $new_item['uuid'] );

		// Rotating a key replaces the secret, not what the app may do.
		Saddle_Access::set_role( 'key:' . $new_item['uuid'], $role );

		return new WP_REST_Response(
			array(
				'uuid'       => $new_item['uuid'],
				'name'       => $app_name,
				'label'      => trim( substr( $app_name, strlen( self::CLIENT_PREFIX ) ) ),
				'password'   => $raw_password,
				'user_login' => $user->user_login,
				'created'    => isset( $new_item['created'] ) ? (int) $new_item['created'] : 0,
				'hint'       => $hint,
				'rotated'    => true,
			),
			201
		);
	}

	/**
	 * GET /audit-log — real entries from the Saddle_Log store.
	 *
	 * Optional `since` (Unix seconds, UTC) keeps only entries at or after it;
	 * Home reads `total` with it for the week's exact counts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_audit_log( WP_REST_Request $request ) {
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? $per_page : 20;
		$page     = (int) $request->get_param( 'page' );
		$page     = $page > 0 ? $page : 1;
		$type     = (string) $request->get_param( 'type' );
		$type     = in_array( $type, array( 'executed', 'denied', 'rehearsed' ), true ) ? $type : '';
		$since    = absint( $request->get_param( 'since' ) );
		$by       = 'app' === $request->get_param( 'by' ) ? 'app' : '';

		$result = class_exists( 'Saddle_Log' )
			? Saddle_Log::query( $per_page, $page, $type, $since, $by )
			: array(
				'entries'     => array(),
				'total'       => 0,
				'total_pages' => 0,
				'page'        => 1,
			);

		/**
		 * Filter the audit-log entries surfaced in the admin UI.
		 *
		 * @param array $entries List of log entries.
		 */
		$result['entries'] = array_values( (array) apply_filters( 'saddle_audit_log', $result['entries'] ) );
		$result['enabled'] = true;

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * How long the owner's undo preview can be confirmed, in seconds. The
	 * same window an app's preview gets.
	 */
	const UNDO_TOKEN_TTL = 900;

	/**
	 * POST /undo — the owner undoes logged changes from Home's Activity feed.
	 *
	 * Uses the journal saddle/undo-changes uses (Saddle_Undo) and refuses what
	 * it refuses, with its reasons: a change edited again since, a permanent
	 * delete, a plugin or theme update. Without `confirm_token` it returns the
	 * plan and, when something can come back, a token; nothing changes. With
	 * the token it undoes exactly what that preview showed, once. The undo is
	 * journaled and logged as the owner's own step, so Activity shows it and
	 * it can be undone in turn.
	 *
	 * @param WP_REST_Request $request Request with `entries` and an optional `confirm_token`.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function owner_undo( WP_REST_Request $request ) {
		// The owner's step only. An app undoes through its own tool, which is
		// tiered and gated; a Saddle key never reaches this route anyway
		// (Saddle_Connection::scope_credentials), and this holds if a filter
		// widens that.
		if ( '' !== Saddle_Access::current_connection() ) {
			return new WP_Error(
				'saddle_owner_only',
				__( 'Only the site owner can undo from here. Apps use their own undo tool.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'entries' ) ) ) ) );
		$ids = array_slice( $ids, 0, Saddle_Undo::MAX_ENTRIES );
		sort( $ids );
		if ( ! $ids ) {
			return new WP_Error( 'saddle_empty', __( 'Choose at least one change to undo.', 'saddle' ), array( 'status' => 400 ) );
		}

		// As the owner: a setting, theme or plugin change is the owner's to
		// undo here, whatever the old site-wide access level says.
		$plan    = Saddle_Undo_Steps::as_owner(
			static function () use ( $ids ) {
				return Saddle_Undo::plan( $ids );
			}
		);
		$unknown = array_values( array_diff( $ids, wp_list_pluck( $plan, 'id' ) ) );
		$ready   = count( wp_list_filter( $plan, array( 'status' => 'ready' ) ) );
		$token   = trim( (string) $request->get_param( 'confirm_token' ) );

		if ( '' === $token ) {
			$preview = array(
				'entries' => $plan,
				'unknown' => $unknown,
				'ready'   => $ready,
			);
			if ( $ready ) {
				$preview['confirm_token'] = self::issue_undo_token( $ids, $plan );
				$preview['expires_in']    = self::UNDO_TOKEN_TTL;
			}
			return new WP_REST_Response( $preview, 200 );
		}

		$valid = self::consume_undo_token( $token, $ids, $plan );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Journaled like the tool's own undo, so this undo can be undone too.
		$done   = array();
		$undone = array();
		Saddle_Journal::open( 'saddle/undo-changes' );
		try {
			$done   = Saddle_Undo_Steps::as_owner(
				static function () use ( $ids ) {
					return Saddle_Undo::apply( $ids );
				}
			);
			$undone = array_values( wp_list_filter( $done, array( 'status' => 'undone' ) ) );
			if ( $undone ) {
				Saddle_Log::record(
					array(
						'action'  => 'undo-changes',
						'target'  => implode( ',', $ids ),
						'summary' => self::undo_summary( $undone ),
					)
				);
			}
		} finally {
			Saddle_Journal::close( 'saddle/undo-changes' );
		}

		return new WP_REST_Response(
			array(
				'undone'  => count( $undone ),
				'entries' => $done,
				'unknown' => $unknown,
			),
			200
		);
	}

	/**
	 * The log line for the owner's undo: what came back.
	 *
	 * @param array[] $undone Reports with status `undone`.
	 * @return string
	 */
	private static function undo_summary( array $undone ) {
		if ( 1 === count( $undone ) ) {
			return sprintf(
				/* translators: %s: the change that was undone, as the activity log recorded it. */
				__( 'Undid this change: %s', 'saddle' ),
				$undone[0]['summary']
			);
		}
		return sprintf(
			/* translators: %d: number of changes undone. */
			_n( 'Undid %d change.', 'Undid %d changes.', count( $undone ), 'saddle' ),
			count( $undone )
		);
	}

	/**
	 * Issue the token that confirms one owner undo preview. One per owner at a
	 * time: a new preview replaces the last. Bound to the entries and to what
	 * the preview showed; only its hash is stored.
	 *
	 * @param int[]   $ids  Sorted entry ids.
	 * @param array[] $plan Saddle_Undo::plan() for them.
	 * @return string
	 */
	private static function issue_undo_token( array $ids, array $plan ) {
		$token = wp_generate_password( 32, false, false );
		set_transient(
			self::undo_token_key(),
			array(
				'hash' => hash( 'sha256', $token ),
				'ids'  => implode( ',', $ids ),
				'plan' => self::plan_fingerprint( $plan ),
			),
			self::UNDO_TOKEN_TTL
		);
		return $token;
	}

	/**
	 * Check and use up an owner undo token. Single use: it is gone after any
	 * attempt, matched or not.
	 *
	 * @param string  $token Token from the preview.
	 * @param int[]   $ids   Sorted entry ids.
	 * @param array[] $plan  The plan now.
	 * @return true|WP_Error
	 */
	private static function consume_undo_token( $token, array $ids, array $plan ) {
		$stored = get_transient( self::undo_token_key() );
		delete_transient( self::undo_token_key() );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) || ! hash_equals( (string) $stored['hash'], hash( 'sha256', $token ) )
			|| implode( ',', $ids ) !== (string) $stored['ids'] ) {
			return new WP_Error(
				'saddle_undo_token',
				__( 'That preview has expired or was already used. Choose Undo again.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		if ( ! hash_equals( (string) $stored['plan'], self::plan_fingerprint( $plan ) ) ) {
			return new WP_Error(
				'saddle_undo_changed',
				__( 'Something changed since the preview. Choose Undo again to see what comes back.', 'saddle' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * What a preview showed, as a digest: each entry's status and steps.
	 *
	 * @param array[] $plan Saddle_Undo::plan().
	 * @return string
	 */
	private static function plan_fingerprint( array $plan ) {
		$shown = array();
		foreach ( $plan as $report ) {
			$shown[] = array( $report['id'], $report['status'], $report['steps'] );
		}
		return hash( 'sha256', (string) wp_json_encode( $shown ) );
	}

	/**
	 * Where the current owner's undo token waits.
	 *
	 * @return string
	 */
	private static function undo_token_key() {
		return 'saddle_owner_undo_' . get_current_user_id();
	}
}
