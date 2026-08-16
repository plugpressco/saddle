<?php
/**
 * Self-hosted update client.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves plugin updates for the self-hosted build of Saddle.
 *
 * THIS FILE DOES NOT SHIP IN THE WORDPRESS.ORG BUILD. A plugin in the .org
 * directory must not fetch its own updates from a third-party host, so the
 * build task drops this file (and its require in saddle.php degrades to a
 * no-op) for the `wporg` channel. Its absence — not a constant, not a setting
 * — is what makes a build .org-safe, so there is nothing to remember to
 * toggle and nothing to get wrong.
 *
 * That also makes the migration self-retiring: once the same install updates
 * to a .org-built zip, this file is gone and WordPress takes over natively.
 *
 * What leaves the site: the plugin slug and the installed version, to one
 * fixed PlugPress endpoint, at most once every six hours. No site URL, no user
 * data, no license, no telemetry, no content. Nothing is sent on a normal page
 * load — only when WordPress runs its own update check.
 */
class Saddle_Updater {

	/**
	 * The update endpoint. Fixed host, never user-supplied.
	 */
	const ENDPOINT = 'https://updates.plugpress.co/v1/update';

	/**
	 * Product slug in the release bucket.
	 */
	const SLUG = 'saddle';

	/**
	 * Transient holding the last response.
	 */
	const CACHE_KEY = 'saddle_update_payload';

	/**
	 * How long a response is reused before asking again.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Wire the update hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	/**
	 * This plugin's `folder/file.php` identifier.
	 *
	 * @return string
	 */
	private static function basename() {
		return plugin_basename( SADDLE_FILE );
	}

	/**
	 * Add Saddle to WordPress's update list when a newer version exists.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = self::payload();
		if ( ! $remote ) {
			return $transient;
		}

		$basename = self::basename();
		$item     = (object) array(
			'id'           => self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => $basename,
			'new_version'  => $remote['version'],
			'url'          => isset( $remote['homepage'] ) ? $remote['homepage'] : '',
			'package'      => isset( $remote['package'] ) ? $remote['package'] : '',
			'icons'        => isset( $remote['icons'] ) ? (array) $remote['icons'] : array(),
			'banners'      => isset( $remote['banners'] ) ? (array) $remote['banners'] : array(),
			'requires'     => isset( $remote['requires'] ) ? $remote['requires'] : '',
			'requires_php' => isset( $remote['requires_php'] ) ? $remote['requires_php'] : '',
			'tested'       => isset( $remote['tested'] ) ? $remote['tested'] : '',
		);

		// An update is only offered when the remote version is genuinely newer
		// AND a package URL came back. A manifest without a package (or a
		// download the worker refused to sign) must never surface as an update
		// the user can click — that produces "Download failed" at the worst
		// possible moment.
		if ( $item->package && version_compare( $remote['version'], SADDLE_VERSION, '>' ) ) {
			$transient->response[ $basename ] = $item;
			unset( $transient->no_update[ $basename ] );
		} else {
			// Populating no_update is what lets WordPress show the auto-update
			// toggle for this plugin on the Plugins screen.
			$transient->no_update[ $basename ] = $item;
			unset( $transient->response[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Fill the "View version details" modal.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$remote = self::payload();
		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => isset( $remote['name'] ) ? $remote['name'] : 'Saddle',
			'slug'          => self::SLUG,
			'version'       => $remote['version'],
			'author'        => isset( $remote['author'] ) ? $remote['author'] : 'PlugPress',
			'homepage'      => isset( $remote['homepage'] ) ? $remote['homepage'] : '',
			'requires'      => isset( $remote['requires'] ) ? $remote['requires'] : '',
			'requires_php'  => isset( $remote['requires_php'] ) ? $remote['requires_php'] : '',
			'tested'        => isset( $remote['tested'] ) ? $remote['tested'] : '',
			'download_link' => isset( $remote['package'] ) ? $remote['package'] : '',
			'sections'      => isset( $remote['sections'] ) ? (array) $remote['sections'] : array(),
			'banners'       => isset( $remote['banners'] ) ? (array) $remote['banners'] : array(),
			'icons'         => isset( $remote['icons'] ) ? (array) $remote['icons'] : array(),
		);
	}

	/**
	 * The cached update payload, fetching it at most once per CACHE_TTL.
	 *
	 * @return array|false
	 */
	private static function payload() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : false;
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'slug'    => self::SLUG,
					'version' => SADDLE_VERSION,
				),
				self::ENDPOINT
			),
			array(
				'timeout'    => 8,
				'user-agent' => 'Saddle/' . SADDLE_VERSION . '; ' . self::SLUG,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so a down endpoint can't add 8 seconds
			// to every update check on the site.
			set_site_transient( self::CACHE_KEY, array( 'failed' => true ), 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['version'] ) ) {
			set_site_transient( self::CACHE_KEY, array( 'failed' => true ), 15 * MINUTE_IN_SECONDS );
			return false;
		}

		set_site_transient( self::CACHE_KEY, $body, self::CACHE_TTL );

		return $body;
	}

	/**
	 * Drop the cached payload after any upgrade, so the Plugins screen reflects
	 * the newly-installed version immediately instead of up to six hours later.
	 */
	public static function flush() {
		delete_site_transient( self::CACHE_KEY );
	}
}
