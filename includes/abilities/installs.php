<?php
/**
 * The WordPress.org directory: finding a plugin by name or slug.
 *
 * Decided 2026-10-12 (#347): Saddle installs a plugin or theme only from the
 * WordPress.org directory, by slug, through WordPress's own installer. Every
 * read here goes through core's own `plugins_api()`, the same request
 * Plugins → Add New makes, so the answer is the directory's and the request
 * is one the owner's WordPress already makes on that screen.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the directory abilities. Hooked to `wp_abilities_api_init`.
 *
 * The capability is `activate_plugins`, as for list-updates: searching
 * reads a public directory and changes nothing, and `install_plugins` maps to
 * `do_not_allow` on a site that forbids file changes, where knowing what a
 * plugin is called is still useful.
 */
function saddle_register_install_abilities() {
	wp_register_ability(
		'saddle/search-plugins',
		array(
			'label'               => __( 'Search the plugin directory', 'saddle' ),
			'description'         => __( 'Searches the WordPress.org plugin directory, the same search as Plugins → Add New, or looks up one plugin by its exact slug. Pass "search" (words) or "slug", not both. Returns up to 10 plugins, each with its slug, name, author, version, active installs, rating (0 to 100) and number of ratings, the date it was last updated, the WordPress version it was tested up to, the WordPress and PHP versions it requires, and whether it is installed and active on this site (with its plugin file when installed). Read-only. Results are cached on this site for an hour.', 'saddle' ),
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
	 * The directory fields Saddle asks for, and the heavy ones it turns off.
	 * Pinned by a test against a recorded WordPress.org answer.
	 */
	const FIELDS = array(
		'active_installs'   => true,
		'last_updated'      => true,
		'tested'            => true,
		'requires'          => true,
		'requires_php'      => true,
		'requires_plugins'  => true,
		'rating'            => true,
		'num_ratings'       => true,
		'downloadlink'      => true,
		'short_description' => false,
		'description'       => false,
		'sections'          => false,
		'icons'             => false,
		'banners'           => false,
		'screenshots'       => false,
		'tags'              => false,
		'versions'          => false,
		'contributors'      => false,
		'ratings'           => false,
		'reviews'           => false,
		'donate_link'       => false,
		'homepage'          => false,
		'added'             => false,
	);

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
			$slug = self::clean_slug( $slug );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			$found = self::cached(
				'plugin_information',
				array( 'slug' => $slug ),
				static function ( $api ) {
					return array(
						'total' => 1,
						'rows'  => array( Saddle_Install_Abilities::row( $api ) ),
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
						$rows[] = Saddle_Install_Abilities::row( $plugin );
					}
					return array(
						'total' => isset( $api->info['results'] ) ? (int) $api->info['results'] : count( $rows ),
						'rows'  => $rows,
					);
				}
			);
		}

		if ( is_wp_error( $found ) ) {
			return self::directory_error( $found, $slug );
		}

		// Installed and active are this site's, so they are never cached.
		$results = array();
		foreach ( $found['rows'] as $row ) {
			$results[] = self::here( $row );
		}

		return array(
			'results' => $results,
			'total'   => $found['total'],
			'source'  => 'wordpress.org',
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * One directory entry, as the agent sees it.
	 *
	 * @param object|array $api One plugin from plugins_api().
	 * @return array
	 */
	public static function row( $api ) {
		$api      = (array) $api;
		$text     = static function ( $key ) use ( $api ) {
			return empty( $api[ $key ] ) ? null : (string) $api[ $key ];
		};
		$readable = static function ( $value ) {
			return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );
		};
		$updated  = empty( $api['last_updated'] ) ? false : strtotime( (string) $api['last_updated'] );

		return array(
			'slug'            => (string) $api['slug'],
			'name'            => $readable( isset( $api['name'] ) ? $api['name'] : $api['slug'] ),
			'author'          => $readable( isset( $api['author'] ) ? $api['author'] : '' ),
			'version'         => (string) $text( 'version' ),
			'active_installs' => isset( $api['active_installs'] ) ? (int) $api['active_installs'] : 0,
			'rating'          => isset( $api['rating'] ) ? (int) $api['rating'] : 0,
			'num_ratings'     => isset( $api['num_ratings'] ) ? (int) $api['num_ratings'] : 0,
			'last_updated'    => $updated ? gmdate( 'Y-m-d', $updated ) : null,
			'tested'          => $text( 'tested' ),
			'requires'        => $text( 'requires' ),
			'requires_php'    => $text( 'requires_php' ),
		);
	}

	/**
	 * Add whether a directory entry is installed and active here, by core's
	 * own rule on Plugins → Add New.
	 *
	 * @param array $row Row from row().
	 * @return array
	 */
	private static function here( array $row ) {
		self::load_install_api();
		$status = install_plugin_install_status(
			(object) array(
				'slug'    => $row['slug'],
				'version' => $row['version'],
			)
		);
		$file   = empty( $status['file'] ) ? null : (string) $status['file'];

		$row['installed'] = 'install' !== $status['status'];
		$row['active']    = $file ? is_plugin_active( $file ) : false;
		$row['plugin']    = $file;
		return $row;
	}

	/**
	 * Ask the directory through plugins_api(), or answer from the cache.
	 * Only a good answer is cached, and only after it is mapped.
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

		$api = self::directory( $action, $request );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$found = $map( $api );
		set_transient( $key, $found, HOUR_IN_SECONDS );
		return $found;
	}

	/**
	 * One plugins_api() call with Saddle's fields.
	 *
	 * @param string $action  'query_plugins' or 'plugin_information'.
	 * @param array  $request Request arguments, without fields.
	 * @return object|WP_Error
	 */
	public static function directory( $action, array $request ) {
		self::load_install_api();
		$api = plugins_api( $action, $request + array( 'fields' => self::FIELDS ) );
		if ( ! is_wp_error( $api ) && ! is_object( $api ) ) {
			$api = new WP_Error( 'plugins_api_failed', __( 'WordPress.org sent an answer Saddle could not read.', 'saddle' ) );
		}
		return $api;
	}

	/**
	 * Name what went wrong with a directory request.
	 *
	 * plugins_api() reports both a refused request and the directory's own
	 * "Plugin not found." / "closed" as `plugins_api_failed`. Only a failed
	 * request carries the transport's message as error data.
	 *
	 * @param WP_Error $error From plugins_api().
	 * @param string   $slug  The slug asked for, if any.
	 * @return WP_Error
	 */
	public static function directory_error( WP_Error $error, $slug = '' ) {
		if ( $error->get_error_data() || '' === $slug ) {
			return new WP_Error(
				'saddle_directory_unreachable',
				sprintf(
					/* translators: %s: the error WordPress reported. */
					__( 'WordPress could not get an answer from the WordPress.org directory: %s', 'saddle' ),
					wp_strip_all_tags( (string) ( $error->get_error_data() ? $error->get_error_data() : $error->get_error_message() ) )
				),
				array( 'status' => 502 )
			);
		}
		if ( 'closed' === $error->get_error_message() ) {
			return new WP_Error(
				'saddle_plugin_closed',
				/* translators: %s: plugin slug. */
				sprintf( __( 'WordPress.org has closed the plugin "%s", so it cannot be installed.', 'saddle' ), $slug ),
				array( 'status' => 404 )
			);
		}
		return new WP_Error(
			'saddle_not_in_directory',
			/* translators: %s: plugin slug. */
			sprintf( __( 'The WordPress.org directory has no plugin with the slug "%s". Use search-plugins with "search" to find the right slug.', 'saddle' ), $slug ),
			array( 'status' => 404 )
		);
	}

	/**
	 * A directory slug: lowercase letters, digits and hyphens.
	 *
	 * @param string $raw User input.
	 * @return string|WP_Error
	 */
	public static function clean_slug( $raw ) {
		$slug = strtolower( trim( (string) $raw, " /\t\n" ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
			return new WP_Error( 'saddle_invalid_slug', __( 'A directory slug has only lowercase letters, digits and hyphens, as in wordpress.org/plugins/<slug>/.', 'saddle' ), array( 'status' => 400 ) );
		}
		return $slug;
	}

	/**
	 * Load the admin plugin-install API, which a REST or cron request lacks.
	 */
	private static function load_install_api() {
		Saddle_Update_Runner::load_update_api();
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
	}
}
