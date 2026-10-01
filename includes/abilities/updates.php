<?php
/**
 * Updates and health — what WordPress offers to update, applying it through
 * core's own updater, the auto-update switch, and Site Health.
 *
 * Decided 2026-09-27: Saddle applies plugin and theme updates that WordPress
 * already offers. It never writes a file and never picks a package URL; the
 * confirmed items are queued for {@see Saddle_Update_Runner}, which hands
 * each one to {@see WP_Automatic_Updater::update()} out of band, with core's
 * temporary backup, fatal check and rollback. No install, no delete, no core
 * update here. Everything sits at the `admin` tier, and the two abilities
 * that change files route through the approval gate with the exact from → to
 * set bound into the token.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the update and health abilities. Hooked to `wp_abilities_api_init`.
 *
 * The WordPress capabilities are `activate_plugins` / `switch_themes`, not
 * `update_plugins` / `update_themes`: core maps the latter to `do_not_allow`
 * whenever file changes are forbidden (DISALLOW_FILE_MODS), which would refuse
 * before Saddle can say why. The handlers name the reason instead, and
 * list-updates stays readable on such a site so the agent learns it.
 */
function saddle_register_update_abilities() {
	$item_schema = static function ( $key, $what ) {
		return array(
			'type'       => 'object',
			'default'    => (object) array(),
			'properties' => array(
				$key            => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => $what,
				),
				'all'           => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Update everything that has an update offered. Refused when that is more than 10 items; pass a subset instead.', 'saddle' ),
				),
				'confirm_token' => array(
					'type'        => 'string',
					'description' => __( 'Token from the preview step, required to queue the run.', 'saddle' ),
				),
			),
		);
	};

	wp_register_ability(
		'saddle/list-updates',
		array(
			'label'               => __( 'List available updates', 'saddle' ),
			'description'         => __( 'Lists the plugin, theme, WordPress core and translation updates this site has been offered, each with the installed and new version, whether the item is active, whether WordPress auto-updates it, and a changelog link. Also reports anything that would stop updates from being applied here (a host that forbids file changes, a version-control checkout, missing filesystem access) and the results of recent runs queued by update-plugin or update-theme, including any rollback. Read-only. Pass "refresh": true to make WordPress check for updates again instead of using its cached answer.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'refresh' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Check for updates again now.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Update_Abilities', 'list_updates' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'list-updates' ),
			'meta'                => saddle_ability_meta( true, false, true, 'admin' ),
		)
	);

	wp_register_ability(
		'saddle/update-plugin',
		array(
			'label'               => __( 'Update plugins', 'saddle' ),
			'description'         => __( 'Applies plugin updates that WordPress has already offered (see list-updates), at most 10 per call. The first call returns a preview of every item with its installed and new version plus a confirm_token; call again with the same "plugins" and that token to queue the run. The updates then run in the background through WordPress\'s own updater: it keeps a backup, and if an active plugin causes a fatal error after updating, WordPress restores the previous version on its own. Check list-updates a minute later for the result of each item (applied, rolled back, failed or skipped). Refuses items with no update offered, more than 10 items, and sites where WordPress cannot change files. Never installs or deletes a plugin.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => $item_schema( 'plugins', __( 'Plugin files ("dir/file.php") or folder slugs, from list-updates.', 'saddle' ) ),
			'execute_callback'    => array( 'Saddle_Update_Abilities', 'update_plugin' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'update-plugin' ),
			'meta'                => saddle_ability_meta( false, true, false, 'admin', true ),
		)
	);

	wp_register_ability(
		'saddle/update-theme',
		array(
			'label'               => __( 'Update themes', 'saddle' ),
			'description'         => __( 'Applies theme updates that WordPress has already offered (see list-updates), at most 10 per call. Same preview → confirm_token → background run as update-plugin. WordPress keeps a backup of the previous version; it does not check themes for fatal errors, so update the active theme with that in mind. Check list-updates a minute later for each item\'s result. Never installs or deletes a theme.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => $item_schema( 'themes', __( 'Theme directory names, from list-updates.', 'saddle' ) ),
			'execute_callback'    => array( 'Saddle_Update_Abilities', 'update_theme' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'switch_themes', 'update-theme' ),
			'meta'                => saddle_ability_meta( false, true, false, 'admin', true ),
		)
	);

	wp_register_ability(
		'saddle/set-auto-update',
		array(
			'label'               => __( 'Turn auto-updates on or off for a plugin or theme', 'saddle' ),
			'description'         => __( 'Switches WordPress\'s own automatic updates on or off for one plugin or theme, the same switch as the "Enable auto-updates" link on the Plugins screen. WordPress then applies future updates on its own schedule. Previews first and needs a confirm_token. Reversible by calling again with the opposite value.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'type', 'item', 'enabled' ),
				'properties' => array(
					'type'          => array(
						'type' => 'string',
						'enum' => array( 'plugin', 'theme' ),
					),
					'item'          => array(
						'type'        => 'string',
						'description' => __( 'Plugin file or folder slug, or theme directory name.', 'saddle' ),
					),
					'enabled'       => array( 'type' => 'boolean' ),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'Token from the preview step, required to apply the change.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Update_Abilities', 'set_auto_update' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'set-auto-update' ),
			'meta'                => saddle_ability_meta( false, true, false, 'admin' ),
		)
	);

	wp_register_ability(
		'saddle/get-site-health',
		array(
			'label'               => __( 'Get Site Health', 'saddle' ),
			'description'         => __( 'Runs WordPress\'s own Site Health checks (Tools → Site Health) and returns each one with its status: good, recommended or critical. Covers the WordPress, PHP and database versions, HTTPS, scheduled events, auto-updates, disk space for updates, autoloaded options, and file uploads. Checks that need a live HTTP request (REST availability, loopback) are listed by name but not run here. Read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(),
			),
			'execute_callback'    => array( 'Saddle_Update_Abilities', 'get_site_health' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'view_site_health_checks', 'get-site-health' ),
			'meta'                => saddle_ability_meta( true, false, true, 'admin' ),
		)
	);
}

/**
 * Execute callbacks for the update and health abilities.
 */
class Saddle_Update_Abilities {

	/**
	 * saddle/list-updates.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public static function list_updates( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$refresh = ! empty( $input['refresh'] );
		Saddle_Update_Runner::load_update_api();

		$plugins = array();
		foreach ( self::offers( 'plugin', $refresh ) as $file => $offer ) {
			$plugins[] = self::describe( 'plugin', $file, $offer );
		}
		$themes = array();
		foreach ( self::offers( 'theme', $refresh ) as $stylesheet => $offer ) {
			$themes[] = self::describe( 'theme', $stylesheet, $offer );
		}

		$core = array(
			'version'     => get_bloginfo( 'version' ),
			'new_version' => null,
		);
		if ( $refresh ) {
			wp_version_check( array(), true );
		}
		foreach ( (array) get_core_updates() as $update ) {
			if ( isset( $update->response ) && 'upgrade' === $update->response ) {
				$core['new_version'] = $update->current;
				break;
			}
		}

		return array(
			'plugins'      => $plugins,
			'themes'       => $themes,
			'core'         => $core,
			'translations' => count( (array) wp_get_translation_updates() ),
			'blocked_by'   => Saddle_Update_Runner::blocked_by( 'plugin' ),
			'runs'         => Saddle_Update_Runner::runs( 5 ),
			'checked'      => (int) get_site_transient( 'update_plugins' )->last_checked,
		);
	}

	/**
	 * saddle/update-plugin.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_plugin( $input = null ) {
		return self::update( 'plugin', is_array( $input ) ? $input : array() );
	}

	/**
	 * saddle/update-theme.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_theme( $input = null ) {
		return self::update( 'theme', is_array( $input ) ? $input : array() );
	}

	/**
	 * Preview, then queue, a bounded set of offered updates.
	 *
	 * @param string $type  'plugin' or 'theme'.
	 * @param array  $input Ability input.
	 * @return array|WP_Error
	 */
	private static function update( $type, array $input ) {
		Saddle_Update_Runner::load_update_api();

		// Refuse before a preview exists: a token must never be issued for a
		// run this site cannot perform.
		$blocked = Saddle_Update_Runner::blocked_by( $type );
		if ( ! empty( $blocked ) ) {
			return new WP_Error( 'saddle_updates_unavailable', implode( ' ', $blocked ), array( 'status' => 409 ) );
		}

		$items = self::resolve_items( $type, $input );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$ids    = wp_list_pluck( $items, 'id' );
		$action = 'theme' === $type ? 'update-theme' : 'update-plugin';
		$names  = array_map(
			static function ( $item ) {
				return sprintf( '%s %s → %s', $item['name'], $item['from'], $item['to'] );
			},
			$items
		);
		// Bind the exact versions: a preview shown for 1.1 must not be
		// confirmable into applying a 1.2 that was offered in between.
		$bind = substr( hash( 'sha256', wp_json_encode( array_combine( $ids, wp_list_pluck( $items, 'to' ) ) ) ), 0, 16 );

		return Saddle_Approval::gate(
			array(
				'action'  => $action,
				'target'  => implode( ',', $ids ),
				'bind'    => $bind,
				'summary' => sprintf(
					/* translators: 1: count, 2: comma-separated "Name old → new" list. */
					_n( 'Update %1$d item in the background: %2$s. WordPress keeps a backup and restores an active plugin that causes a fatal error.', 'Update %1$d items in the background: %2$s. WordPress keeps a backup and restores an active plugin that causes a fatal error.', count( $items ), 'saddle' ),
					count( $items ),
					implode( ', ', $names )
				),
				'preview' => array(
					'type'               => $type,
					'items'              => $items,
					'runs_in_background' => true,
					'rollback'           => 'plugin' === $type
						? __( 'An active plugin that causes a fatal error after updating is restored by WordPress.', 'saddle' )
						: __( 'WordPress keeps a backup of the previous version but does not check themes for fatal errors.', 'saddle' ),
				),
				'input'   => $input,
				'execute' => static function () use ( $type, $items, $ids, $action, $names ) {
					$run_id = Saddle_Update_Runner::queue( $type, $items );
					Saddle_Log::record_action(
						$action,
						implode( ',', $ids ),
						sprintf(
							/* translators: %s: comma-separated "Name old → new" list. */
							__( 'Queued update of %s.', 'saddle' ),
							implode( ', ', $names )
						)
					);
					return array(
						'queued'     => true,
						'run_id'     => $run_id,
						'type'       => $type,
						'items'      => $items,
						'check_with' => 'list-updates',
						'note'       => __( 'The run starts within about a minute and each item reports applied, rolled_back, failed or skipped under "runs" in list-updates.', 'saddle' ),
					);
				},
			)
		);
	}

	/**
	 * saddle/set-auto-update.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_auto_update( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$type    = ( isset( $input['type'] ) && 'theme' === $input['type'] ) ? 'theme' : 'plugin';
		$enabled = ! empty( $input['enabled'] );
		Saddle_Update_Runner::load_update_api();

		$id = self::resolve_id( $type, isset( $input['item'] ) ? (string) $input['item'] : '' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$option  = "auto_update_{$type}s";
		$current = in_array( $id, (array) get_site_option( $option, array() ), true );
		$note    = wp_is_auto_update_enabled_for_type( $type )
			? ''
			: __( 'A plugin or a host setting has turned automatic updates off for this site, so this switch has no effect until that changes.', 'saddle' );

		return Saddle_Approval::gate(
			array(
				'action'  => 'set-auto-update',
				'target'  => $type . ':' . $id,
				'bind'    => $enabled ? 'on' : 'off',
				'summary' => sprintf(
					/* translators: 1: "on" or "off", 2: plugin file or theme name. */
					__( 'Turn WordPress automatic updates %1$s for %2$s.', 'saddle' ),
					$enabled ? __( 'on', 'saddle' ) : __( 'off', 'saddle' ),
					$id
				),
				'preview' => array(
					'type'    => $type,
					'item'    => $id,
					'current' => $current,
					'enabled' => $enabled,
					'note'    => $note,
				),
				'input'   => $input,
				'execute' => static function () use ( $type, $id, $enabled, $option ) {
					$list = array_values( array_unique( (array) get_site_option( $option, array() ) ) );
					$list = array_values( array_diff( $list, array( $id ) ) );
					if ( $enabled ) {
						$list[] = $id;
					}
					update_site_option( $option, $list );
					Saddle_Log::record_action(
						'set-auto-update',
						$type . ':' . $id,
						sprintf(
							/* translators: 1: "on" or "off", 2: plugin file or theme name. */
							__( 'Turned automatic updates %1$s for %2$s.', 'saddle' ),
							$enabled ? __( 'on', 'saddle' ) : __( 'off', 'saddle' ),
							$id
						)
					);
					return array(
						'type'    => $type,
						'item'    => $id,
						'enabled' => $enabled,
					);
				},
			)
		);
	}

	/**
	 * saddle/get-site-health.
	 *
	 * @param mixed $input Ability input (unused; the ability takes no arguments).
	 * @return array
	 */
	public static function get_site_health( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed ability-callback signature.
		Saddle_Update_Runner::load_update_api();
		if ( ! function_exists( 'wp_get_active_and_valid_plugins' ) || ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$health  = WP_Site_Health::get_instance();
		$tests   = WP_Site_Health::get_tests();
		$results = array();
		$summary = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);

		foreach ( (array) $tests['direct'] as $name => $test ) {
			$callback = isset( $test['test'] ) ? $test['test'] : null;
			if ( is_string( $callback ) && method_exists( $health, 'get_test_' . $callback ) ) {
				$callback = array( $health, 'get_test_' . $callback );
			}
			if ( ! is_callable( $callback ) ) {
				continue;
			}
			try {
				$result = call_user_func( $callback );
			} catch ( Throwable $e ) {
				$result = array(
					'label'       => isset( $test['label'] ) ? $test['label'] : $name,
					'status'      => 'error',
					'description' => $e->getMessage(),
				);
			}
			if ( ! is_array( $result ) ) {
				continue;
			}
			$status = isset( $result['status'] ) ? (string) $result['status'] : 'unknown';
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
			$results[] = array(
				'name'        => $name,
				'label'       => isset( $result['label'] ) ? wp_strip_all_tags( $result['label'] ) : $name,
				'status'      => $status,
				'badge'       => isset( $result['badge']['label'] ) ? wp_strip_all_tags( $result['badge']['label'] ) : '',
				'description' => self::plain( isset( $result['description'] ) ? $result['description'] : '' ),
				'actions'     => self::plain( isset( $result['actions'] ) ? $result['actions'] : '' ),
			);
		}

		$async = array();
		foreach ( (array) $tests['async'] as $name => $test ) {
			$async[] = array(
				'name'  => $name,
				'label' => isset( $test['label'] ) ? wp_strip_all_tags( $test['label'] ) : $name,
			);
		}

		return array(
			'summary' => $summary,
			'tests'   => $results,
			'not_run' => $async,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * The update offers WordPress holds for a type, keyed by plugin file or
	 * theme directory, as objects.
	 *
	 * @param string $type    'plugin' or 'theme'.
	 * @param bool   $refresh Force WordPress to check again.
	 * @return object[]
	 */
	private static function offers( $type, $refresh = false ) {
		if ( 'theme' === $type ) {
			if ( $refresh ) {
				wp_clean_themes_cache( true );
			}
			wp_update_themes();
			$transient = get_site_transient( 'update_themes' );
		} else {
			if ( $refresh ) {
				wp_clean_plugins_cache( true );
			}
			wp_update_plugins();
			$transient = get_site_transient( 'update_plugins' );
		}
		$offers = array();
		if ( is_object( $transient ) && ! empty( $transient->response ) ) {
			foreach ( (array) $transient->response as $id => $offer ) {
				$offers[ $id ] = is_array( $offer ) ? (object) $offer : $offer;
			}
		}
		return $offers;
	}

	/**
	 * One offer, described for the agent.
	 *
	 * @param string $type  'plugin' or 'theme'.
	 * @param string $id    Plugin file or theme directory.
	 * @param object $offer Offer from the transient.
	 * @return array
	 */
	private static function describe( $type, $id, $offer ) {
		if ( 'theme' === $type ) {
			$theme  = wp_get_theme( $id );
			$name   = $theme->exists() ? $theme->get( 'Name' ) : $id;
			$from   = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
			$active = get_stylesheet() === $id || get_template() === $id;
			$key    = 'theme';
		} else {
			$all    = Saddle_Context::get_plugins_quietly();
			$name   = isset( $all[ $id ]['Name'] ) ? $all[ $id ]['Name'] : $id;
			$from   = isset( $all[ $id ]['Version'] ) ? (string) $all[ $id ]['Version'] : '';
			$active = is_plugin_active( $id );
			$key    = 'plugin';
		}
		$url = isset( $offer->url ) ? (string) $offer->url : '';
		return array(
			'id'            => $id,
			$key            => $id,
			'name'          => $name,
			'from'          => $from,
			'to'            => isset( $offer->new_version ) ? (string) $offer->new_version : '',
			'active'        => $active,
			'auto_update'   => in_array( $id, (array) get_site_option( "auto_update_{$type}s", array() ), true ),
			'changelog_url' => $url ? ( 0 === strpos( $url, 'https://wordpress.org/' ) ? untrailingslashit( $url ) . '/#developers' : $url ) : null,
			'tested_up_to'  => isset( $offer->tested ) ? (string) $offer->tested : null,
			'requires_php'  => isset( $offer->requires_php ) ? (string) $offer->requires_php : null,
		);
	}

	/**
	 * Resolve the requested items against the offers: known, offered, and no
	 * more than the cap. Refuses rather than truncating or silently dropping.
	 *
	 * @param string $type  'plugin' or 'theme'.
	 * @param array  $input Ability input.
	 * @return array[]|WP_Error Items {id, name, from, to, active}.
	 */
	private static function resolve_items( $type, array $input ) {
		$key    = 'theme' === $type ? 'themes' : 'plugins';
		$offers = self::offers( $type, false );

		if ( ! empty( $input['all'] ) ) {
			$ids = array_keys( $offers );
			if ( empty( $ids ) ) {
				return new WP_Error( 'saddle_no_update_offered', __( 'WordPress is not offering any updates of that kind right now.', 'saddle' ), array( 'status' => 409 ) );
			}
		} else {
			$ids = array();
			foreach ( (array) ( isset( $input[ $key ] ) ? $input[ $key ] : array() ) as $raw ) {
				$id = self::resolve_id( $type, (string) $raw );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$ids[] = $id;
			}
			$ids = array_values( array_unique( $ids ) );
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'saddle_missing_items', sprintf( /* translators: %s: input key. */ __( 'Pass "%s" (a list from list-updates) or "all": true.', 'saddle' ), $key ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > Saddle_Update_Runner::MAX_ITEMS ) {
			return new WP_Error(
				'saddle_too_many_items',
				sprintf(
					/* translators: 1: requested count, 2: the cap. */
					__( '%1$d items requested; at most %2$d run per call. Pass a subset and call again for the rest.', 'saddle' ),
					count( $ids ),
					Saddle_Update_Runner::MAX_ITEMS
				),
				array( 'status' => 400 )
			);
		}

		$missing = array_values( array_diff( $ids, array_keys( $offers ) ) );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'saddle_no_update_offered',
				sprintf(
					/* translators: %s: comma-separated ids. */
					__( 'No update is offered for: %s. Use list-updates to see what WordPress is offering.', 'saddle' ),
					implode( ', ', $missing )
				),
				array( 'status' => 409 )
			);
		}

		$items = array();
		foreach ( $ids as $id ) {
			$d       = self::describe( $type, $id, $offers[ $id ] );
			$items[] = array(
				'id'     => $id,
				'name'   => $d['name'],
				'from'   => $d['from'],
				'to'     => $d['to'],
				'active' => $d['active'],
			);
		}
		return $items;
	}

	/**
	 * Resolve one plugin file/slug or theme directory to an installed id.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param string $raw  User input.
	 * @return string|WP_Error
	 */
	private static function resolve_id( $type, $raw ) {
		$raw = trim( $raw );
		if ( 'theme' === $type ) {
			if ( '' === $raw ) {
				return new WP_Error( 'saddle_missing_theme', __( 'A theme directory name is required.', 'saddle' ), array( 'status' => 400 ) );
			}
			if ( ! wp_get_theme( $raw )->exists() ) {
				return new WP_Error( 'saddle_theme_not_found', __( 'No installed theme has that directory name. Use list-themes to see valid values.', 'saddle' ), array( 'status' => 404 ) );
			}
			return $raw;
		}
		return Saddle_Site_Abilities::resolve_plugin( array( 'plugin' => $raw ) );
	}

	/**
	 * HTML from Site Health to one line of plain text.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private static function plain( $html ) {
		$text = wp_strip_all_tags( (string) $html, true );
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}
}
