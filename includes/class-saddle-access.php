<?php
/**
 * Per-app access: which role the calling connection holds.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A role is a tier (`read`, `write`, `admin`) held by one connection, not by
 * the whole site. Every tool already declares the tier it needs, so nothing
 * about the tools changes: only where the tier comes from.
 *
 * - `key:<uuid>` (a sign-in key) keeps its role in the `saddle_key_roles`
 *   option. A key with no entry is `read`.
 * - `oauth:<grant>` keeps it as the grant's own scope, the single source the
 *   consent screen and the admin both write.
 * - No connection (a browser cookie session, the test suite) falls back to the
 *   legacy site tier, which no screen sets any more.
 *
 * WordPress capabilities still apply underneath, so an app never exceeds the
 * WordPress user it acts as. No tool can change a role: both options are in
 * {@see Saddle_Settings_Guard::PROTECTED_OPTIONS}.
 */
class Saddle_Access {

	/**
	 * Option holding `[ key uuid => role ]`. Autoloaded: read on every call.
	 */
	const KEY_ROLES_OPTION = 'saddle_key_roles';

	/**
	 * Option holding the version of the last access migration that ran.
	 */
	const VERSION_OPTION = 'saddle_access_version';

	/**
	 * Current migration version.
	 */
	const VERSION = 1;

	/**
	 * When this install moved to per-app roles (Unix time). A key created
	 * before it existed under the site-wide tier (see legacy_role()).
	 */
	const LEGACY_BEFORE_OPTION = 'saddle_access_legacy_before';

	/**
	 * The site-wide tier at that moment: what every key could do then.
	 */
	const LEGACY_TIER_OPTION = 'saddle_access_legacy_tier';

	/**
	 * The roles, weakest first. They are the tier names.
	 */
	const ROLES = array( 'read', 'write', 'admin' );

	/**
	 * Wire the migration and the clean-up.
	 */
	public static function register() {
		// After the OAuth post types are registered (priority 10), before any
		// REST request reaches a permission check.
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 20 );
		add_action( 'wp_delete_application_password', array( __CLASS__, 'forget_key' ), 10, 2 );
	}

	/**
	 * Plain-language role names, as the admin shows them.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'read'  => __( 'Read only', 'saddle' ),
			'write' => __( 'Edit content', 'saddle' ),
			'admin' => __( 'Manage the site', 'saddle' ),
		);
	}

	/**
	 * The connection behind this request: `key:<uuid>`, `oauth:<grant>`, or ''.
	 *
	 * @return string
	 */
	public static function current_connection() {
		$credential = Saddle_Connections::credential();

		return null === $credential ? '' : (string) $credential['id'];
	}

	/**
	 * The role a connection holds.
	 *
	 * @param string $connection_id `key:…`, `oauth:…`, or '' for no connection.
	 * @return string One of ROLES.
	 */
	public static function role_for( $connection_id ) {
		list( $kind, $ref ) = array_pad( explode( ':', (string) $connection_id, 2 ), 2, '' );

		if ( 'key' === $kind && '' !== $ref ) {
			$roles = self::key_roles();
			if ( isset( $roles[ $ref ] ) ) {
				return $roles[ $ref ];
			}

			$legacy = self::legacy_role( $ref );

			return '' !== $legacy ? $legacy : 'read';
		}

		if ( 'oauth' === $kind && '' !== $ref ) {
			$grant = class_exists( 'Saddle_OAuth_Store' ) ? Saddle_OAuth_Store::get_grant( $ref ) : null;

			return $grant ? Saddle_OAuth::scope_to_tier( (string) $grant['scope'] ) : 'read';
		}

		if ( '' === (string) $connection_id ) {
			return Saddle_Capabilities::get_site_tier();
		}

		// A connection id of a kind we do not know is not trusted with anything.
		return 'read';
	}

	/**
	 * The role an Application Password from before the move to per-app roles
	 * keeps, or '' when it is not one.
	 *
	 * Legacy support (Fahim, 2026-10-04). In 1.3.0 every Application Password
	 * reached Saddle at the site tier, including one the owner made by hand
	 * under Users → Profile, and 1.3.0 kept no record of which keys used
	 * Saddle. migrate() can only give roles to the keys it can tell were
	 * Saddle's. Any other key that existed then keeps the old site tier the
	 * first time it calls Saddle, so an update never takes away what an app
	 * could do, and that becomes its stored role, which the owner can change on
	 * AI apps. A key made after the update starts at Read only, as every new
	 * key does. This never widens anything: the key could do exactly this
	 * before the update.
	 *
	 * Only the calling user's own key is looked up: that is the case on an
	 * app's request, and a key that has called once has its role stored.
	 *
	 * @param string $uuid Application Password UUID.
	 * @return string One of ROLES, or ''.
	 */
	private static function legacy_role( $uuid ) {
		$before = (int) get_option( self::LEGACY_BEFORE_OPTION, 0 );
		$tier   = (string) get_option( self::LEGACY_TIER_OPTION, '' );
		$user   = get_current_user_id();
		if ( $before <= 0 || ! in_array( $tier, self::ROLES, true ) || ! $user || ! class_exists( 'WP_Application_Passwords' ) ) {
			return '';
		}

		$item = WP_Application_Passwords::get_user_application_password( $user, (string) $uuid );
		if ( ! $item || empty( $item['created'] ) || (int) $item['created'] >= $before ) {
			return '';
		}

		$roles          = self::key_roles();
		$roles[ $uuid ] = $tier;
		update_option( self::KEY_ROLES_OPTION, $roles, true );

		return $tier;
	}

	/**
	 * Give a connection a role.
	 *
	 * @param string $connection_id `key:…` or `oauth:…`.
	 * @param string $role          One of ROLES.
	 * @return true|WP_Error
	 */
	public static function set_role( $connection_id, $role ) {
		if ( ! in_array( $role, self::ROLES, true ) ) {
			return new WP_Error( 'saddle_unknown_role', __( 'That is not an access role Saddle knows.', 'saddle' ), array( 'status' => 400 ) );
		}

		list( $kind, $ref ) = array_pad( explode( ':', (string) $connection_id, 2 ), 2, '' );

		if ( 'key' === $kind && '' !== $ref ) {
			$roles         = self::key_roles();
			$roles[ $ref ] = $role;
			update_option( self::KEY_ROLES_OPTION, $roles, true );
			self::confirm_domain( $role );

			return true;
		}

		if ( 'oauth' === $kind && '' !== $ref && class_exists( 'Saddle_OAuth_Store' ) ) {
			if ( ! Saddle_OAuth_Store::set_grant_scope( $ref, Saddle_OAuth::tier_to_scope( $role ) ) ) {
				return new WP_Error( 'saddle_unknown_connection', __( 'That connection no longer exists.', 'saddle' ), array( 'status' => 404 ) );
			}
			self::confirm_domain( $role );

			return true;
		}

		return new WP_Error( 'saddle_unknown_connection', __( 'That connection no longer exists.', 'saddle' ), array( 'status' => 404 ) );
	}

	/**
	 * Choosing write or admin for an app records the domain it was granted on,
	 * exactly as choosing the site tier used to.
	 *
	 * @param string $role The role just set.
	 */
	private static function confirm_domain( $role ) {
		if ( 'read' !== $role ) {
			Saddle_Capabilities::record_tier_domain();
		}
	}

	/**
	 * Whether anything on this site can write: the legacy site tier, a key, or
	 * an OAuth grant above read. The domain check warns only when this is true.
	 *
	 * @return bool
	 */
	public static function elevated_anywhere() {
		if ( 'read' !== Saddle_Capabilities::get_site_tier() || array_diff( self::key_roles(), array( 'read' ) ) ) {
			return true;
		}

		foreach ( class_exists( 'Saddle_OAuth_Store' ) ? Saddle_OAuth_Store::list_grants() : array() as $grant ) {
			if ( 'read' !== Saddle_OAuth::scope_to_tier( (string) $grant['scope'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Core's `wp_delete_application_password` action: a deleted key keeps no role.
	 *
	 * @param int   $user_id User the key belonged to.
	 * @param array $item    The deleted key.
	 */
	public static function forget_key( $user_id, $item ) {
		unset( $user_id );

		$roles = self::key_roles();
		if ( is_array( $item ) && ! empty( $item['uuid'] ) && isset( $roles[ $item['uuid'] ] ) ) {
			unset( $roles[ $item['uuid'] ] );
			update_option( self::KEY_ROLES_OPTION, $roles, true );
		}
	}

	/**
	 * Run the access migration once per version. One autoloaded option read
	 * when there is nothing to do.
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		self::migrate();
	}

	/**
	 * Move an install from one site-wide tier to per-app roles without widening
	 * anything (R3).
	 *
	 * Every Saddle-issued key, and every key the connection registry has seen,
	 * gets the current site tier as its role, unless it already has one. Every
	 * OAuth grant is rewritten to the lower of the site tier and its own level,
	 * which is exactly what it could do before. `saddle_access_tier` is left in
	 * place: it is the rollback and the no-connection fallback. Idempotent.
	 */
	public static function migrate() {
		$site  = Saddle_Capabilities::get_site_tier();
		$roles = self::key_roles();

		// The moment and the tier, once: a forced re-run never moves the line.
		add_option( self::LEGACY_BEFORE_OPTION, time(), '', true );
		add_option( self::LEGACY_TIER_OPTION, $site, '', true );

		$seen = array();
		foreach ( array_keys( Saddle_Connections::all() ) as $id ) {
			if ( 0 === strpos( (string) $id, 'key:' ) ) {
				$seen[ substr( (string) $id, 4 ) ] = true;
			}
		}

		if ( class_exists( 'WP_Application_Passwords' ) ) {
			$users = get_users(
				array(
					'meta_key' => '_application_passwords', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- once, on update.
					'fields'   => 'ID',
					'number'   => 1000,
				)
			);
			foreach ( $users as $user_id ) {
				foreach ( (array) WP_Application_Passwords::get_user_application_passwords( (int) $user_id ) as $item ) {
					$uuid = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
					if ( '' === $uuid || isset( $roles[ $uuid ] ) ) {
						continue;
					}
					if ( isset( $seen[ $uuid ] ) || Saddle_Connection::is_saddle_issued( (int) $user_id, $uuid ) ) {
						$roles[ $uuid ] = $site;
					}
				}
			}
		}

		update_option( self::KEY_ROLES_OPTION, $roles, true );

		if ( class_exists( 'Saddle_OAuth_Store' ) ) {
			foreach ( Saddle_OAuth_Store::list_grants() as $grant ) {
				$level = Saddle_OAuth::scope_to_tier( (string) $grant['scope'] );
				if ( Saddle_Capabilities::rank( $level ) > Saddle_Capabilities::rank( $site ) ) {
					Saddle_OAuth_Store::set_grant_scope( (string) $grant['grant_id'], Saddle_OAuth::tier_to_scope( $site ) );
				}
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * The stored key roles, with anything unrecognised dropped.
	 *
	 * @return array<string,string>
	 */
	private static function key_roles() {
		$stored = get_option( self::KEY_ROLES_OPTION, array() );

		return is_array( $stored ) ? array_filter( $stored, static fn( $role ) => in_array( $role, self::ROLES, true ) ) : array();
	}
}
