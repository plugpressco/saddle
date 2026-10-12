<?php
/**
 * The WordPress.org directory: finding a plugin, and installing one.
 *
 * Decided 2026-10-12 (#347): Saddle installs a plugin or theme only from the
 * WordPress.org directory, by slug, through WordPress's own installer, out of
 * band, after a confirmed preview. Every directory read goes through
 * {@see Saddle_Directory}, which asks core's own `plugins_api()`, the same
 * request Plugins → Add New makes. A confirmed install is queued for
 * {@see Saddle_Update_Runner}; the request that confirms never runs the
 * installer. A new install arrives inactive, and nothing here deletes.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the directory abilities. Hooked to `wp_abilities_api_init`.
 *
 * The permission capability is `activate_plugins`, as for update-plugin:
 * `install_plugins` maps to `do_not_allow` on a site that forbids file
 * changes, which would hide the tool behind a wrong "your account lacks the
 * permission" note. The install handler names that reason first, then checks
 * `install_plugins` itself.
 */
function saddle_register_install_abilities() {
	wp_register_ability(
		'saddle/search-plugins',
		array(
			'label'               => __( 'Search the plugin directory', 'saddle' ),
			'description'         => __( 'Searches the WordPress.org plugin directory, the same search as Plugins → Add New, or looks up one plugin by its exact slug. Pass "search" (words) or "slug", not both. Returns up to 10 plugins, each with its slug, name, author, version, active installs, rating (0 to 100) and number of ratings, the date it was last updated, the WordPress version it was tested up to, the WordPress and PHP versions it requires, and whether it is installed and active on this site (with its plugin file when installed). Install one with install-plugin and its slug. Read-only. Results are cached on this site for an hour.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Words to search the directory for, as typed into Plugins → Add New.', 'saddle' ),
					),
					'slug'     => array(
						'type'        => 'string',
						'description' => __( 'One exact plugin slug, as in wordpress.org/plugins/<slug>/.', 'saddle' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => Saddle_Install_Abilities::MAX_RESULTS,
						'default'     => 5,
						'description' => __( 'How many results to return, 1 to 10.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Install_Abilities', 'search_plugins' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'search-plugins' ),
			'meta'                => saddle_ability_meta( true, false, true, 'admin', true ),
		)
	);

	wp_register_ability(
		'saddle/install-plugin',
		array(
			'label'               => __( 'Install a plugin', 'saddle' ),
			'description'         => __( 'Installs one plugin from the WordPress.org directory, by its slug (find it with search-plugins). Nothing else can be installed: no zip, no URL, no file. It takes two calls. The first call changes nothing: it returns a preview with the plugin\'s name, author, version, active installs, rating and last update, the WordPress version it was tested up to against this site\'s, the PHP it needs against this site\'s, any warnings (not tested with this WordPress, not updated in two years, needs other plugins), and a confirm_token. Call again with the same "slug" and that token to queue the install. WordPress\'s own installer then runs it in the background, the way Plugins → Add New does; check list-updates a minute later for the result under "runs" (installed, skipped or failed). The plugin arrives INACTIVE: turn it on with activate-plugin, its own confirmed step. Refuses a plugin that is already installed, a slug WordPress.org does not have or has closed, a plugin that needs a newer PHP or WordPress, and sites where WordPress cannot change files. Installs cannot be undone from Saddle; the site owner removes a plugin in wp-admin under Plugins.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'slug' ),
				'properties' => array(
					'slug'          => array(
						'type'        => 'string',
						'description' => __( 'The plugin\'s WordPress.org slug, as in wordpress.org/plugins/<slug>/.', 'saddle' ),
					),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'Token from the preview step, required to queue the install.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Install_Abilities', 'install_plugin' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'install-plugin' ),
			'meta'                => saddle_ability_meta( false, true, false, 'admin', true ),
		)
	);
}

/**
 * Execute callbacks for the directory abilities.
 */
class Saddle_Install_Abilities {

	/**
	 * Most results one search returns.
	 */
	const MAX_RESULTS = 10;

	/**
	 * Transient prefix for cached directory answers. Uninstall removes every
	 * `saddle_` transient.
	 */
	const CACHE_PREFIX = 'saddle_plugin_search_';

	/**
	 * A plugin not updated for this long gets a warning in the preview.
	 */
	const STALE_DAYS = 730;

	/**
	 * saddle/search-plugins.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function search_plugins( $input = null ) {
		$input  = is_array( $input ) ? $input : array();
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		$slug   = isset( $input['slug'] ) ? trim( (string) $input['slug'] ) : '';

		if ( ( '' === $search ) === ( '' === $slug ) ) {
			return new WP_Error( 'saddle_search_or_slug', __( 'Pass "search" (words to search for) or "slug" (one exact plugin slug), not both.', 'saddle' ), array( 'status' => 400 ) );
		}

		if ( '' !== $slug ) {
			$slug = Saddle_Directory::clean_slug( $slug );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			$found = self::cached(
				'plugin_information',
				array( 'slug' => $slug ),
				static function ( $api ) {
					return array(
						'total' => 1,
						'rows'  => array( Saddle_Directory::row( $api ) ),
					);
				}
			);
		} else {
			$per_page = isset( $input['per_page'] ) ? max( 1, min( self::MAX_RESULTS, (int) $input['per_page'] ) ) : 5;
			$found    = self::cached(
				'query_plugins',
				array(
					'search'   => $search,
					'per_page' => $per_page,
					'page'     => 1,
				),
				static function ( $api ) {
					$rows = array();
					foreach ( isset( $api->plugins ) ? (array) $api->plugins : array() as $plugin ) {
						$rows[] = Saddle_Directory::row( $plugin );
					}
					return array(
						'total' => isset( $api->info['results'] ) ? (int) $api->info['results'] : count( $rows ),
						'rows'  => $rows,
					);
				}
			);
		}

		if ( is_wp_error( $found ) ) {
			return Saddle_Directory::error( $found, $slug );
		}

		// Installed and active are this site's, so they are never cached.
		$results = array();
		foreach ( $found['rows'] as $row ) {
			$here             = Saddle_Directory::plugin_status( $row['slug'] );
			$row['installed'] = $here['installed'];
			$row['active']    = $here['file'] ? is_plugin_active( $here['file'] ) : false;
			$row['plugin']    = $here['file'];
			$results[]        = $row;
		}

		return array(
			'results' => $results,
			'total'   => $found['total'],
			'source'  => 'wordpress.org',
		);
	}

	/**
	 * saddle/install-plugin.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function install_plugin( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		Saddle_Directory::load_api();

		// Refuse before a preview exists: a token must never be issued for an
		// install this site cannot perform.
		$blocked = Saddle_Update_Runner::blocked_by( 'plugin' );
		if ( ! empty( $blocked ) ) {
			return new WP_Error( 'saddle_updates_unavailable', implode( ' ', $blocked ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'saddle_cannot_install', __( 'The WordPress account this app is connected as cannot install plugins here (on a multisite network, only a network administrator can). No Saddle setting changes that.', 'saddle' ), array( 'status' => 403 ) );
		}

		$slug = Saddle_Directory::clean_slug( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		$api = Saddle_Directory::plugin( $slug );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$refusal = Saddle_Directory::refusal( $api );
		if ( $refusal ) {
			return $refusal;
		}

		$entry = Saddle_Directory::row( $api );
		$needs = isset( $api->requires_plugins ) ? array_values( array_map( 'strval', (array) $api->requires_plugins ) ) : array();
		$label = sprintf( '%s %s', $entry['name'], $entry['version'] );

		return Saddle_Approval::gate(
			array(
				'action'  => 'install-plugin',
				'target'  => $slug,
				// Bind the version: a preview shown for 1.1 must not be
				// confirmable into installing a 1.2 released in between.
				'bind'    => $entry['version'],
				'summary' => sprintf(
					/* translators: %s: plugin name and version. */
					__( 'Install %s from WordPress.org in the background. It arrives inactive.', 'saddle' ),
					$label
				),
				'preview' => array_merge(
					$entry,
					array(
						'type'               => 'plugin',
						'wordpress'          => get_bloginfo( 'version' ),
						'php'                => PHP_VERSION,
						'requires_plugins'   => $needs,
						'warnings'           => self::warnings( $entry, $needs ),
						'source'             => 'wordpress.org',
						'arrives_inactive'   => true,
						'runs_in_background' => true,
						'undo'               => __( 'Saddle cannot undo an install. To remove the plugin, delete it in wp-admin under Plugins.', 'saddle' ),
					)
				),
				// The gate logs the confirmed call with this line, once.
				'done'    => sprintf(
					/* translators: %s: plugin name and version. */
					__( 'Queued install of %s from WordPress.org.', 'saddle' ),
					$label
				),
				'input'   => $input,
				'execute' => static function () use ( $slug, $entry ) {
					$run_id = Saddle_Update_Runner::queue(
						'install-plugin',
						array(
							array(
								'id'   => $slug,
								'name' => $entry['name'],
								'from' => '',
								'to'   => $entry['version'],
							),
						)
					);
					return array(
						'queued'     => true,
						'run_id'     => $run_id,
						'type'       => 'plugin',
						'slug'       => $slug,
						'version'    => $entry['version'],
						'check_with' => 'list-updates',
						'next'       => 'activate-plugin',
						'note'       => __( 'The install starts within about a minute and reports installed, skipped or failed under "runs" in list-updates. The plugin arrives inactive; activate it with activate-plugin.', 'saddle' ),
					);
				},
			)
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * What the owner should know before saying yes, in plain words.
	 *
	 * "Untested" is the Add New list table's own test: WordPress newer than
	 * the version the author tested up to.
	 *
	 * @param array    $entry Row from Saddle_Directory::row().
	 * @param string[] $needs Slugs of plugins it requires.
	 * @return string[]
	 */
	private static function warnings( array $entry, array $needs ) {
		$warnings = array();
		$wp       = get_bloginfo( 'version' );
		if ( ! empty( $entry['tested'] ) && version_compare( $wp, $entry['tested'], '>' ) ) {
			$warnings[] = sprintf(
				/* translators: 1: version the plugin was tested up to, 2: this site's WordPress version. */
				__( 'Tested up to WordPress %1$s; this site runs %2$s.', 'saddle' ),
				$entry['tested'],
				$wp
			);
		}
		if ( $entry['last_updated'] && strtotime( $entry['last_updated'] ) < time() - self::STALE_DAYS * DAY_IN_SECONDS ) {
			$warnings[] = sprintf(
				/* translators: %s: date of the last update. */
				__( 'Not updated since %s, more than two years ago.', 'saddle' ),
				$entry['last_updated']
			);
		}
		if ( $needs ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated plugin slugs. */
				__( 'Needs these plugins active before it can be activated: %s.', 'saddle' ),
				implode( ', ', $needs )
			);
		}
		return $warnings;
	}

	/**
	 * Ask the directory, or answer from the cache. Only a good answer is
	 * cached, and only after it is mapped.
	 *
	 * @param string   $action  'query_plugins' or 'plugin_information'.
	 * @param array    $request Request arguments, without fields.
	 * @param callable $map     Maps core's answer to {total, rows}.
	 * @return array|WP_Error
	 */
	private static function cached( $action, array $request, callable $map ) {
		$key    = self::CACHE_PREFIX . md5( wp_json_encode( array( $action, $request ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
			return $cached;
		}

		$api = Saddle_Directory::plugins( $action, $request );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$found = $map( $api );
		set_transient( $key, $found, HOUR_IN_SECONDS );
		return $found;
	}
}
