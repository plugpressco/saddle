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
	const CLIENT_PREFIX = 'Saddle: ';

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
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
						'tier'      => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => Saddle_Capabilities::tiers(),
						),
						'onboarded' => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'paused'    => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'theme'     => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => array( 'system', 'light', 'dark' ),
						),
					),
				),
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
			)
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
	 * The current admin's saved dashboard theme preference.
	 *
	 * @return string One of: system, light, dark.
	 */
	private static function admin_theme() {
		$theme = (string) get_user_meta( get_current_user_id(), 'saddle_admin_theme', true );
		return '' !== $theme ? $theme : 'system';
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		return new WP_REST_Response(
			array(
				'tier'           => Saddle_Capabilities::get_tier(),
				'tiers'          => Saddle_Capabilities::tiers(),
				'default'        => Saddle_Capabilities::DEFAULT_TIER,
				'onboarded'      => (bool) get_option( 'saddle_onboarded', false ),
				'paused'         => Saddle_Capabilities::is_paused(),
				'theme'          => self::admin_theme(),
				'domain_warning' => ! Saddle_Capabilities::domain_matches_recorded(),
				'domain'         => array(
					'current'  => Saddle_Capabilities::current_domain(),
					'recorded' => Saddle_Capabilities::recorded_tier_domain(),
					'enforced' => Saddle_Capabilities::is_domain_enforced(),
				),
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
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( array_key_exists( 'tier', $params ) ) {
			if ( ! Saddle_Capabilities::set_tier( $request->get_param( 'tier' ) ) ) {
				return new WP_Error( 'saddle_invalid_tier', __( 'Unknown access tier.', 'saddle' ), array( 'status' => 400 ) );
			}
		}

		if ( array_key_exists( 'onboarded', $params ) ) {
			update_option( 'saddle_onboarded', (bool) $request->get_param( 'onboarded' ) );
		}

		// Theme is a personal preference, not site state — user meta, and
		// "system" (the default) simply clears it.
		if ( array_key_exists( 'theme', $params ) ) {
			$theme = (string) $request->get_param( 'theme' );
			if ( 'system' === $theme ) {
				delete_user_meta( get_current_user_id(), 'saddle_admin_theme' );
			} else {
				update_user_meta( get_current_user_id(), 'saddle_admin_theme', $theme );
			}
		}

		if ( array_key_exists( 'paused', $params ) ) {
			Saddle_Capabilities::set_paused( (bool) $request->get_param( 'paused' ) );
		}

		if ( array_key_exists( 'domain_enforced', $params ) ) {
			Saddle_Capabilities::set_domain_enforcement( (bool) $request->get_param( 'domain_enforced' ) );
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
		$rules = array(
			// label => substrings (first hit wins).
			'Design system'   => array( 'design-system', 'design-tokens', 'bootstrap-design' ),
			'Divi'            => array( 'divi-' ),
			'Integrations'    => array( 'waggle-', 'knovia-', 'unsplash-' ),
			'Memory & skills' => array( 'remember', 'recall', 'forget', 'skill', 'instructions' ),
			'Blocks & layout' => array( 'block', 'render-node', 'verify-page', 'lint-page', 'preview', 'recipe' ),
			'Users'           => array( 'user' ),
			'Site & settings' => array( 'option', 'plugin', 'theme', 'cache', 'site-info' ),
			'Content'         => array( 'post', 'page', 'media', 'categor', 'tag', 'revision', 'search' ),
		);

		$category = 'Other';
		foreach ( $rules as $label => $needles ) {
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
			);
		}

		return new WP_REST_Response(
			array(
				'capabilities' => $catalog,
				'current_tier' => Saddle_Capabilities::get_tier(),
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
			return new WP_Error( 'saddle_app_passwords_unavailable', __( 'Application Passwords are turned off on this site — often because it isn’t served over HTTPS, or a security plugin disabled them.', 'saddle' ), array( 'status' => 400 ) );
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
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_audit_log( WP_REST_Request $request ) {
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? $per_page : 20;
		$page     = (int) $request->get_param( 'page' );
		$page     = $page > 0 ? $page : 1;
		$type     = (string) $request->get_param( 'type' );
		$type     = in_array( $type, array( 'executed', 'denied' ), true ) ? $type : '';

		$result = class_exists( 'Saddle_Log' )
			? Saddle_Log::query( $per_page, $page, $type )
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
}
