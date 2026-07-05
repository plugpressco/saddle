<?php
/**
 * Site-management abilities — the WP-CLI-equivalent surface (Phase 2, item 3).
 *
 * PHP-native dispatch only. There is deliberately NO raw code execution, NO
 * shell WP-CLI passthrough, and NO direct database access here — see the scope
 * lock in MVP-PLAN.md and CLAUDE.md. Every operation calls a first-party
 * WordPress PHP API (activate_plugin(), switch_theme(), update_option(),
 * wp_cache_flush(), …), so an agent gets the practical outcome of a WP-CLI
 * command without Saddle ever becoming a code-execution endpoint.
 *
 * These are the highest-power abilities Saddle exposes, so they sit at the
 * `admin` tier: a site owner has to explicitly turn on the "manage the site"
 * level before any of them is reachable. Reads that expose configuration
 * (option values, installed inventory) sit at `admin` too, not `read` — the
 * inventory itself is sensitive. Option get/update is confined to an
 * allowlist; a hard blocklist (siteurl/home, auth keys/salts, active_plugins,
 * roles/registration) always wins, even over the extension filter. The one
 * irreversible operation — overwriting an option value — routes through the
 * approval gate, with the new value bound into the confirm token.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the site-management abilities. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_site_abilities() {

	/*
	 * ---------------------------------------------------------------------
	 * Plugins
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/list-plugins',
		array(
			'label'               => __( 'List plugins', 'saddle' ),
			'description'         => __( 'Lists every installed plugin with its file, name, version, and whether it is currently active (and network-active on multisite). Read-only. Use the returned "plugin" file value to activate or deactivate a plugin.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive' ),
						'default'     => 'all',
						'description' => __( 'Filter by activation status.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'list_plugins' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'list-plugins' ),
			'meta'                => saddle_ability_meta( true, false, true, 'admin' ),
		)
	);

	wp_register_ability(
		'saddle/activate-plugin',
		array(
			'label'               => __( 'Activate plugin', 'saddle' ),
			'description'         => __( 'Activates an installed plugin. Provide its "plugin" file (e.g. "akismet/akismet.php") or its folder slug (e.g. "akismet"). Reversible with deactivate-plugin. Returns the plugin that was activated. A plugin that fatals on activation returns an error and stays inactive.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'plugin' ),
				'properties' => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => __( 'Plugin file ("dir/file.php") or folder slug.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'activate_plugin' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'activate-plugin' ),
			'meta'                => saddle_ability_meta( false, false, false, 'admin' ),
		)
	);

	wp_register_ability(
		'saddle/deactivate-plugin',
		array(
			'label'               => __( 'Deactivate plugin', 'saddle' ),
			'description'         => __( 'Deactivates an active plugin. Provide its "plugin" file or folder slug. Reversible with activate-plugin. Refuses to deactivate Saddle itself. Returns the plugin that was deactivated.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'plugin' ),
				'properties' => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => __( 'Plugin file ("dir/file.php") or folder slug.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'deactivate_plugin' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'deactivate-plugin' ),
			'meta'                => saddle_ability_meta( false, false, false, 'admin' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Themes
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/list-themes',
		array(
			'label'               => __( 'List themes', 'saddle' ),
			'description'         => __( 'Lists every installed theme with its stylesheet (directory), name, version, and whether it is the active theme. Read-only. Use the returned "stylesheet" value to activate a theme.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'list_themes' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'switch_themes', 'list-themes' ),
			'meta'                => saddle_ability_meta( true, false, true, 'admin' ),
		)
	);

	wp_register_ability(
		'saddle/activate-theme',
		array(
			'label'               => __( 'Activate theme', 'saddle' ),
			'description'         => __( 'Switches the active theme. Provide its "stylesheet" (theme directory name, e.g. "twentytwentyfour"). Reversible by activating the previous theme. Refuses a broken theme (missing files). Returns the newly active theme.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'stylesheet' ),
				'properties' => array(
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __( 'Theme directory name.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'activate_theme' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'switch_themes', 'activate-theme' ),
			'meta'                => saddle_ability_meta( false, false, false, 'admin' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Options (allowlisted)
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/list-options',
		array(
			'label'               => __( 'List site settings', 'saddle' ),
			'description'         => __( 'Lists the WordPress settings Saddle can read and change, grouped by the admin Settings page they live on (general, writing, reading, discussion, permalinks) — e.g. site title and tagline (general), front-page display and posts-per-page (reading), and the permalink structure (permalinks). Each entry includes its current value. Read-only. Pass "page" to list just one page. This is the exact set get-option and update-option accept; sensitive keys (site URL, security keys, user roles, admin email) are never included.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'page' => array(
						'type'        => 'string',
						'enum'        => array( 'general', 'writing', 'reading', 'discussion', 'permalinks' ),
						'description' => __( 'Optional: limit to one Settings page.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'list_options' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'manage_options', 'list-options' ),
			'meta'                => saddle_ability_meta( true, false, true, 'admin' ),
		)
	);

	wp_register_ability(
		'saddle/get-option',
		array(
			'label'               => __( 'Get a site setting', 'saddle' ),
			'description'         => __( 'Returns the current value of a single WordPress setting (e.g. "blogname" for the site title, "permalink_structure", "posts_per_page"). Read-only. Only keys from list-options are readable; any other key is refused.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'name' ),
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => __( 'Option name (must be allowlisted — see list-options).', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'get_option' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'manage_options', 'get-option' ),
			'meta'                => saddle_ability_meta( true, false, true, 'admin' ),
		)
	);

	wp_register_ability(
		'saddle/update-option',
		array(
			'label'               => __( 'Update a site setting', 'saddle' ),
			'description'         => __( 'Changes a single WordPress setting — for example the site title ("blogname"), tagline ("blogdescription"), permalink structure ("permalink_structure"), front page ("show_on_front"/"page_on_front"), or posts per page ("posts_per_page"). Only keys from list-options are writable; any other key is refused, and values are validated (ids must point to real published pages/categories, enums and numbers are range-checked). To use a static homepage, set "page_on_front" to a published page FIRST, then set "show_on_front" to "page". Changing the permalink structure rebuilds the site\'s links automatically, just like saving the Permalinks page. Because the old value is not recoverable, this previews first: the first call returns the current value, the proposed value, and a confirm_token — call again with the same "name", "value", and that token to apply the change.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'name', 'value' ),
				'properties' => array(
					'name'          => array(
						'type'        => 'string',
						'description' => __( 'Option name (must be allowlisted — see list-options).', 'saddle' ),
					),
					'value'         => array(
						'type'        => array( 'string', 'number', 'integer', 'boolean' ),
						'description' => __( 'New value (scalar only).', 'saddle' ),
					),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'Token from the preview step, required to apply the change.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'update_option' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'manage_options', 'update-option' ),
			'meta'                => saddle_ability_meta( false, true, false, 'admin' ),
		)
	);

	/*
	 * ---------------------------------------------------------------------
	 * Maintenance
	 * ---------------------------------------------------------------------
	 */

	wp_register_ability(
		'saddle/flush-cache',
		array(
			'label'               => __( 'Flush object cache', 'saddle' ),
			'description'         => __( 'Flushes the WordPress object cache. Harmless and reversible — the cache simply repopulates. Useful after bulk changes or when stale data is suspected. Returns whether the flush ran.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Site_Abilities', 'flush_cache' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'manage_options', 'flush-cache' ),
			'meta'                => saddle_ability_meta( false, false, true, 'admin' ),
		)
	);
}

/**
 * Execute callbacks for the site-management abilities.
 *
 * Every method assumes the permission_callback has already enforced the admin
 * tier, the WordPress capability, the pause switch, and the per-ability toggle.
 * These add the operation-specific validation (allowlist, plugin/theme
 * existence, self-protection) that the generic gate can't know about.
 */
class Saddle_Site_Abilities {

	/**
	 * Default set of options Saddle may read and write. Deliberately small and
	 * side-effect-light; extend via the `saddle_option_allowlist` filter. The
	 * blocklist below always wins, so the filter can never expose a secret.
	 */
	const SETTINGS = array(
		// Settings → General.
		'blogname'               => 'general',
		'blogdescription'        => 'general',
		'timezone_string'        => 'general',
		'gmt_offset'             => 'general',
		'date_format'            => 'general',
		'time_format'            => 'general',
		'start_of_week'          => 'general',
		// Settings → Writing.
		'default_category'       => 'writing',
		'default_post_format'    => 'writing',
		// Settings → Reading.
		'show_on_front'          => 'reading',
		'page_on_front'          => 'reading',
		'page_for_posts'         => 'reading',
		'posts_per_page'         => 'reading',
		'posts_per_rss'          => 'reading',
		'rss_use_excerpt'        => 'reading',
		'blog_public'            => 'reading',
		// Settings → Discussion.
		'default_comment_status' => 'discussion',
		'default_ping_status'    => 'discussion',
		'comment_moderation'     => 'discussion',
		'comments_per_page'      => 'discussion',
		// Settings → Permalinks.
		'permalink_structure'    => 'permalinks',
		'category_base'          => 'permalinks',
		'tag_base'               => 'permalinks',
	);

	/**
	 * Options that regenerate rewrite rules when changed (Permalinks page).
	 */
	const REWRITE_OPTIONS = array( 'permalink_structure', 'category_base', 'tag_base' );

	/* ---------------------------------------------------------------------
	 * Plugins
	 * ------------------------------------------------------------------- */

	/**
	 * saddle/list-plugins.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public static function list_plugins( $input = null ) {
		$input  = is_array( $input ) ? $input : array();
		$status = isset( $input['status'] ) ? (string) $input['status'] : 'all';

		self::load_plugin_api();
		$all     = get_plugins();
		$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();

		$plugins = array();
		foreach ( $all as $file => $data ) {
			$active = is_plugin_active( $file );
			if ( 'active' === $status && ! $active ) {
				continue;
			}
			if ( 'inactive' === $status && $active ) {
				continue;
			}
			$plugins[] = array(
				'plugin'         => $file,
				'name'           => $data['Name'],
				'version'        => $data['Version'],
				'active'         => $active,
				'network_active' => isset( $network[ $file ] ),
			);
		}

		return array(
			'plugins' => $plugins,
			'count'   => count( $plugins ),
		);
	}

	/**
	 * saddle/activate-plugin.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function activate_plugin( $input = null ) {
		$file = self::resolve_plugin( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( is_plugin_active( $file ) ) {
			return array(
				'activated' => false,
				'plugin'    => $file,
				'note'      => __( 'That plugin is already active.', 'saddle' ),
			);
		}

		// activate_plugin() loads and runs the plugin's activation hooks; a fatal
		// there surfaces as WP_Error rather than taking the request down.
		$result = activate_plugin( $file, '', false, false );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'saddle_activate_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		self::log( 'activate-plugin', $file, sprintf( /* translators: %s: plugin file. */ __( 'Activated plugin %s.', 'saddle' ), $file ) );

		return array(
			'activated' => true,
			'plugin'    => $file,
		);
	}

	/**
	 * saddle/deactivate-plugin.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function deactivate_plugin( $input = null ) {
		$file = self::resolve_plugin( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Never let an agent switch off the very plugin serving the request.
		if ( defined( 'SADDLE_FILE' ) && plugin_basename( SADDLE_FILE ) === $file ) {
			return new WP_Error( 'saddle_self_deactivate', __( 'Saddle cannot deactivate itself.', 'saddle' ), array( 'status' => 400 ) );
		}

		if ( ! is_plugin_active( $file ) ) {
			return array(
				'deactivated' => false,
				'plugin'      => $file,
				'note'        => __( 'That plugin is already inactive.', 'saddle' ),
			);
		}

		deactivate_plugins( $file, false );

		self::log( 'deactivate-plugin', $file, sprintf( /* translators: %s: plugin file. */ __( 'Deactivated plugin %s.', 'saddle' ), $file ) );

		return array(
			'deactivated' => true,
			'plugin'      => $file,
		);
	}

	/* ---------------------------------------------------------------------
	 * Themes
	 * ------------------------------------------------------------------- */

	/**
	 * saddle/list-themes.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public static function list_themes( $input = null ) {
		$active = get_stylesheet();
		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'active'     => $stylesheet === $active,
			);
		}

		return array(
			'themes' => $themes,
			'count'  => count( $themes ),
		);
	}

	/**
	 * saddle/activate-theme.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function activate_theme( $input = null ) {
		$input      = is_array( $input ) ? $input : array();
		$stylesheet = isset( $input['stylesheet'] ) ? trim( (string) $input['stylesheet'] ) : '';
		if ( '' === $stylesheet ) {
			return new WP_Error( 'saddle_missing_stylesheet', __( 'A "stylesheet" (theme directory name) is required.', 'saddle' ), array( 'status' => 400 ) );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'saddle_theme_not_found', __( 'No installed theme with that stylesheet. Use list-themes to see valid values.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( ! $theme->is_allowed() ) {
			return new WP_Error( 'saddle_theme_not_allowed', __( 'That theme is not allowed on this site.', 'saddle' ), array( 'status' => 403 ) );
		}
		$errors = $theme->errors();
		if ( is_wp_error( $errors ) ) {
			return new WP_Error( 'saddle_theme_broken', __( 'That theme has errors and cannot be activated: ', 'saddle' ) . $errors->get_error_message(), array( 'status' => 400 ) );
		}

		if ( get_stylesheet() === $stylesheet ) {
			return array(
				'activated'  => false,
				'stylesheet' => $stylesheet,
				'note'       => __( 'That theme is already active.', 'saddle' ),
			);
		}

		switch_theme( $stylesheet );

		self::log( 'activate-theme', $stylesheet, sprintf( /* translators: %s: theme name. */ __( 'Switched active theme to %s.', 'saddle' ), $theme->get( 'Name' ) ) );

		return array(
			'activated'  => true,
			'stylesheet' => $stylesheet,
			'name'       => $theme->get( 'Name' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Options
	 * ------------------------------------------------------------------- */

	/**
	 * saddle/list-options.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public static function list_options( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$page  = isset( $input['page'] ) ? sanitize_key( (string) $input['page'] ) : '';

		$options = array();
		foreach ( self::allowlist() as $name ) {
			$option_page = self::settings_page( $name );
			if ( '' !== $page && $page !== $option_page ) {
				continue;
			}
			$options[] = array(
				'name'  => $name,
				'page'  => $option_page,
				'value' => get_option( $name ),
			);
		}

		return array(
			'options' => $options,
			'count'   => count( $options ),
			'pages'   => array( 'general', 'writing', 'reading', 'discussion', 'permalinks' ),
		);
	}

	/**
	 * saddle/get-option.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_option( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$name  = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';

		$guard = self::guard_option( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return array(
			'name'  => $name,
			'value' => get_option( $name ),
		);
	}

	/**
	 * saddle/update-option. Overwriting an option value is not recoverable, so
	 * this routes through the approval gate with the new value bound to the
	 * token — a preview for one value can't be confirmed into a different one.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_option( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$name  = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';

		$guard = self::guard_option( $name );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! array_key_exists( 'value', $input ) ) {
			return new WP_Error( 'saddle_missing_value', __( 'A "value" is required.', 'saddle' ), array( 'status' => 400 ) );
		}
		$value = $input['value'];
		if ( ! is_scalar( $value ) ) {
			return new WP_Error( 'saddle_bad_value', __( 'Option value must be a scalar (string, number, or boolean).', 'saddle' ), array( 'status' => 400 ) );
		}

		// Reject values that would break a constrained setting, before the gate.
		$valid = self::validate_setting( $name, $value );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$current  = get_option( $name );
		$page     = self::settings_page( $name );
		$rewrites = in_array( $name, self::REWRITE_OPTIONS, true );

		return Saddle_Approval::gate(
			array(
				'action'  => 'update-option',
				'target'  => $name,
				// Bind the proposed value: a preview shown for one value must not
				// be confirmable into writing a different one.
				'bind'    => substr( hash( 'sha256', wp_json_encode( $value ) ), 0, 16 ),
				// Record old → new in the summary so the activity log is a real
				// audit trail, not just "a setting changed".
				'summary' => sprintf(
					/* translators: 1: option name, 2: settings page, 3: old value, 4: new value. */
					__( 'Change "%1$s" (Settings → %2$s) from "%3$s" to "%4$s". The previous value is not recoverable.', 'saddle' ),
					$name,
					ucfirst( $page ),
					self::scalarize( $current ),
					self::scalarize( $value )
				),
				'preview' => array(
					'name'          => $name,
					'page'          => $page,
					'current_value' => $current,
					'new_value'     => $value,
				),
				'input'   => $input,
				'execute' => static function () use ( $name, $value, $current, $rewrites ) {
					if ( $current === $value ) {
						return array(
							'updated' => false,
							'name'    => $name,
							'value'   => $value,
							'note'    => __( 'The option already holds that value.', 'saddle' ),
						);
					}
					update_option( $name, $value );

					// A permalink change only takes effect once rewrite rules are
					// regenerated — exactly what saving Settings → Permalinks does.
					if ( $rewrites ) {
						global $wp_rewrite;
						if ( $wp_rewrite instanceof WP_Rewrite ) {
							$wp_rewrite->init();
							flush_rewrite_rules();
						}
					}

					return array(
						'updated'          => true,
						'name'             => $name,
						'value'            => $value,
						'rewrite_flushed'  => $rewrites,
					);
				},
			)
		);
	}

	/**
	 * Validate the constrained core settings whose bad values would break the
	 * site: enums, booleans, whole-number bounds, referential ids (a front-page
	 * id must be a PUBLISHED page; a default category must exist), a valid
	 * timezone, and the static-front-page interdependency. These are stricter
	 * and more reliable than a generic schema check, and — crucially — they
	 * never false-reject a valid value. The stored value is then sanitized by
	 * WordPress core on write: update_option() runs it through sanitize_option()
	 * and the setting's own registered sanitize_option_{$name} callback.
	 *
	 * @param string $name  Option name.
	 * @param scalar $value Proposed value.
	 * @return true|WP_Error
	 */
	private static function validate_setting( $name, $value ) {
		$enums = array(
			'show_on_front'          => array( 'posts', 'page' ),
			'default_comment_status' => array( 'open', 'closed' ),
			'default_ping_status'    => array( 'open', 'closed' ),
			'default_post_format'    => array( 'standard', 'aside', 'chat', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio' ),
		);
		if ( isset( $enums[ $name ] ) && ! in_array( (string) $value, $enums[ $name ], true ) ) {
			return new WP_Error(
				'saddle_bad_setting_value',
				sprintf(
					/* translators: 1: option name, 2: allowed values. */
					__( '"%1$s" must be one of: %2$s.', 'saddle' ),
					$name,
					implode( ', ', $enums[ $name ] )
				),
				array( 'status' => 400 )
			);
		}

		// Booleans stored as 0/1, and bounded integers.
		$bools = array( 'blog_public', 'rss_use_excerpt', 'comment_moderation' );
		if ( in_array( $name, $bools, true ) && ! in_array( (string) $value, array( '0', '1' ), true ) ) {
			return new WP_Error( 'saddle_bad_setting_value', sprintf( /* translators: %s: option name. */ __( '"%s" must be 0 or 1.', 'saddle' ), $name ), array( 'status' => 400 ) );
		}

		$positive_ints = array( 'posts_per_page', 'posts_per_rss', 'comments_per_page' );
		if ( in_array( $name, $positive_ints, true ) && ( ! is_numeric( $value ) || (int) $value < 1 || (float) $value !== (float) (int) $value ) ) {
			return new WP_Error( 'saddle_bad_setting_value', sprintf( /* translators: %s: option name. */ __( '"%s" must be a positive whole number.', 'saddle' ), $name ), array( 'status' => 400 ) );
		}

		if ( 'start_of_week' === $name && ( ! is_numeric( $value ) || (int) $value < 0 || (int) $value > 6 ) ) {
			return new WP_Error( 'saddle_bad_setting_value', __( '"start_of_week" must be 0 (Sunday) through 6 (Saturday).', 'saddle' ), array( 'status' => 400 ) );
		}

		// A valid timezone identifier only — an invalid one breaks date output.
		if ( 'timezone_string' === $name && '' !== (string) $value && ! in_array( (string) $value, timezone_identifiers_list(), true ) ) {
			return new WP_Error( 'saddle_bad_setting_value', __( '"timezone_string" must be a valid timezone identifier, e.g. "Europe/London". See list-options for the current value.', 'saddle' ), array( 'status' => 400 ) );
		}

		// Referential integrity: a front-page / posts-page id must point to a
		// PUBLISHED page. This blocks two real breakages — pointing the homepage
		// at a non-existent id (blank homepage) or at a draft/private page
		// (exposing unpublished content publicly) — neither of which the format
		// checks above would catch.
		if ( in_array( $name, array( 'page_on_front', 'page_for_posts' ), true ) && (int) $value > 0 ) {
			$page = get_post( (int) $value );
			if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
				return new WP_Error(
					'saddle_bad_setting_value',
					sprintf(
						/* translators: %s: option name. */
						__( '"%s" must be the id of a PUBLISHED page. Create or publish the page first, then set it here.', 'saddle' ),
						$name
					),
					array( 'status' => 400 )
				);
			}
		}

		// default_category must be a real category term, or new posts break.
		if ( 'default_category' === $name && ( (int) $value < 1 || ! term_exists( (int) $value, 'category' ) ) ) {
			return new WP_Error( 'saddle_bad_setting_value', __( '"default_category" must be the id of an existing category. See list-categories.', 'saddle' ), array( 'status' => 400 ) );
		}

		// Interdependency: switching the homepage to a static page is only valid
		// if a published page is already assigned — otherwise the homepage goes
		// blank. Enforce the order (set page_on_front first) rather than allow a
		// broken intermediate state.
		if ( 'show_on_front' === $name && 'page' === (string) $value ) {
			$front = (int) get_option( 'page_on_front' );
			$page  = $front ? get_post( $front ) : null;
			if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
				return new WP_Error(
					'saddle_setting_dependency',
					__( 'To use a static front page, first set "page_on_front" to a published page, then set "show_on_front" to "page". No valid front page is assigned yet.', 'saddle' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Flatten a scalar option value to a short string for audit summaries.
	 *
	 * @param mixed $value Option value.
	 * @return string
	 */
	private static function scalarize( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		$str = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		$str = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $str ) ) );
		return '' === $str ? '(empty)' : mb_substr( $str, 0, 80 );
	}

	/* ---------------------------------------------------------------------
	 * Maintenance
	 * ------------------------------------------------------------------- */

	/**
	 * saddle/flush-cache.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public static function flush_cache( $input = null ) {
		$flushed = wp_cache_flush();

		self::log( 'flush-cache', 'object-cache', __( 'Flushed the object cache.', 'saddle' ) );

		return array(
			'flushed' => (bool) $flushed,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * The effective option allowlist: the default set plus any keys added via
	 * the `saddle_option_allowlist` filter, minus anything the hard blocklist
	 * rejects. The blocklist runs last so a filter can never widen the surface
	 * to a security-sensitive key.
	 *
	 * @return string[]
	 */
	public static function allowlist() {
		/**
		 * Filter the option keys Saddle may read and write.
		 *
		 * @param string[] $keys Allowlisted option names.
		 */
		$keys = apply_filters( 'saddle_option_allowlist', array_keys( self::SETTINGS ) );
		$keys = array_filter(
			(array) $keys,
			static function ( $key ) {
				return is_string( $key ) && '' !== $key && ! self::is_blocked_option( $key );
			}
		);
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Which Settings admin page an option belongs to, for grouping.
	 *
	 * @param string $name Option name.
	 * @return string general|writing|reading|discussion|permalinks|other.
	 */
	private static function settings_page( $name ) {
		return isset( self::SETTINGS[ $name ] ) ? self::SETTINGS[ $name ] : 'other';
	}

	/**
	 * Whether an option key is always off-limits, regardless of the allowlist.
	 * Covers site-takeover keys (siteurl/home), security material (auth keys,
	 * salts, secrets, nonces, tokens, passwords), the plugin/theme switches that
	 * have their own gated abilities, and privilege-escalation keys (roles,
	 * open registration, the admin email).
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	private static function is_blocked_option( $key ) {
		$key = strtolower( $key );

		$exact = array(
			'siteurl',
			'home',
			'active_plugins',
			'template',
			'stylesheet',
			'current_theme',
			'default_role',
			'users_can_register',
			'admin_email',
			'new_admin_email',
		);
		if ( in_array( $key, $exact, true ) ) {
			return true;
		}

		// Security-material families and role storage, wherever they appear.
		return (bool) preg_match( '/(secret|salt|nonce|token|password|auth_key|_key$|^key$|user_roles|capabilities)/', $key );
	}

	/**
	 * Validate an option name for get/update: present and on the allowlist.
	 *
	 * @param string $name Option name.
	 * @return true|WP_Error
	 */
	private static function guard_option( $name ) {
		if ( '' === $name ) {
			return new WP_Error( 'saddle_missing_name', __( 'An option "name" is required.', 'saddle' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $name, self::allowlist(), true ) ) {
			return new WP_Error(
				'saddle_option_not_allowed',
				__( 'That option is not on the list Saddle is allowed to touch. Use list-options to see the editable keys; sensitive keys (site URL, security keys, roles) are never editable.', 'saddle' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Resolve a plugin identifier (file or folder slug) to an installed plugin
	 * file. Accepts "dir/file.php" as-is when installed, or a bare slug matched
	 * against plugin directory names.
	 *
	 * @param mixed $input Ability input.
	 * @return string|WP_Error Plugin file, or an error.
	 */
	private static function resolve_plugin( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['plugin'] ) ? trim( (string) $input['plugin'] ) : '';
		if ( '' === $id ) {
			return new WP_Error( 'saddle_missing_plugin', __( 'A "plugin" file or folder slug is required.', 'saddle' ), array( 'status' => 400 ) );
		}

		self::load_plugin_api();
		$all = get_plugins();

		if ( isset( $all[ $id ] ) ) {
			return $id;
		}

		// Bare slug: match the plugin whose folder is that slug.
		$slug = trim( $id, '/' );
		foreach ( array_keys( $all ) as $file ) {
			if ( strpos( $file, '/' ) !== false && dirname( $file ) === $slug ) {
				return $file;
			}
			// Single-file plugin (no folder), e.g. "hello.php".
			if ( $file === $slug || $file === $slug . '.php' ) {
				return $file;
			}
		}

		return new WP_Error( 'saddle_plugin_not_found', __( 'No installed plugin matches that file or slug. Use list-plugins to see valid values.', 'saddle' ), array( 'status' => 404 ) );
	}

	/**
	 * Load the admin plugin API (get_plugins/activate_plugin/etc.), which is not
	 * present on front-end / REST requests.
	 */
	private static function load_plugin_api() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Record a successful mutation to the activity log.
	 *
	 * @param string     $action  Short action key.
	 * @param int|string $target  Target id.
	 * @param string     $summary Human-readable description.
	 */
	private static function log( $action, $target, $summary ) {
		if ( class_exists( 'Saddle_Log' ) ) {
			Saddle_Log::record(
				array(
					'action'  => $action,
					'target'  => (string) $target,
					'summary' => $summary,
				)
			);
		}
	}
}
