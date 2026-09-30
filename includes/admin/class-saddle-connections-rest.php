<?php
/**
 * REST routes for the connection registry.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * `GET saddle/v1/connections` and `GET saddle/v1/connections/pulse`.
 *
 * The first is every way an app can reach the site, in one list: Saddle's
 * keys and the OAuth grants, each joined to its registry record. Before it,
 * the admin read keys from `/clients` and grants from `/oauth-connections`,
 * and the Dashboard read only the keys, so a site connected by address alone
 * said "Connect your first app".
 *
 * The second is what a waiting screen polls: only the connections with
 * something new since the time it passes back, and the apps that have asked
 * to connect but not been approved yet (`pending`).
 */
class Saddle_Connections_REST {

	/**
	 * Register the routes. Both are `manage_options`, like every admin route.
	 */
	public static function register_routes() {
		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/connections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_connections' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
			)
		);

		register_rest_route(
			Saddle_REST_Admin::REST_NAMESPACE,
			'/connections/pulse',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_pulse' ),
				'permission_callback' => array( 'Saddle_REST_Admin', 'can_manage' ),
				'args'                => array(
					'since' => array(
						'type'              => 'integer',
						'minimum'           => 0,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /connections — keys and grants, most recently seen first.
	 *
	 * Keys: every Saddle-issued key of an administrator (only they can mint
	 * one), used or not, plus any other key the registry has seen reach the
	 * MCP endpoint. Grants: every OAuth grant.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_connections() {
		$records = Saddle_Connections::all();
		$rows    = array();

		if ( class_exists( 'WP_Application_Passwords' ) ) {
			$users = get_users(
				array(
					'capability' => 'manage_options',
					'fields'     => 'ID',
					'number'     => 100,
				)
			);
			foreach ( $records as $record ) {
				if ( isset( $record['kind'], $record['user'] ) && 'key' === $record['kind'] ) {
					$users[] = (int) $record['user'];
				}
			}

			foreach ( array_unique( array_map( 'intval', $users ) ) as $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					continue;
				}
				foreach ( (array) WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
					if ( empty( $item['uuid'] ) ) {
						continue;
					}
					$id = 'key:' . $item['uuid'];
					if ( ! isset( $records[ $id ] ) && ! Saddle_Connection::is_saddle_issued( $user_id, $item['uuid'] ) ) {
						continue;
					}
					$rows[] = self::row( $id, $records, self::key_credential( $user, $item ) );
				}
			}
		}

		if ( class_exists( 'Saddle_OAuth_Store' ) ) {
			foreach ( Saddle_OAuth_Store::list_grants() as $grant ) {
				$rows[] = self::row( 'oauth:' . $grant['grant_id'], $records, self::grant_credential( $grant ) );
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return max( $b['last_seen_at'], $b['created_at'] ) - max( $a['last_seen_at'], $a['created_at'] );
			}
		);

		return new WP_REST_Response( array( 'connections' => $rows ), 200 );
	}

	/**
	 * GET /connections/pulse?since= — connections seen, or first seen, after
	 * `since`.
	 *
	 * Reads the registry option and looks up only the connections that
	 * changed, which on a waiting screen is none or one. Pass the returned
	 * `now` back as the next `since`: it is the server's clock, so a browser
	 * clock that is off cannot hide a connection.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function get_pulse( WP_REST_Request $request ) {
		$since   = (int) $request->get_param( 'since' );
		$records = Saddle_Connections::all();
		$rows    = array();

		foreach ( $records as $id => $record ) {
			if ( empty( $record['last_seen_at'] ) || (int) $record['last_seen_at'] <= $since ) {
				continue;
			}
			$credential = self::lookup( $id, $record );
			if ( $credential ) {
				$rows[] = self::row( $id, $records, $credential );
			}
		}

		return new WP_REST_Response(
			array(
				'now'         => time(),
				'connections' => $rows,
				'pending'     => self::pending( $since ),
			),
			200
		);
	}

	/**
	 * Apps that have registered themselves but not been approved yet.
	 *
	 * A registered client (dynamic registration) with no grant is an app
	 * that asked to connect and is waiting for the owner on the consent
	 * screen. First run says so, instead of "waiting" while the owner has
	 * a screen open they have not noticed.
	 *
	 * @param int $since Only clients registered after this time.
	 * @return array[] `client_id`, `client_name`, `app`, `registered_at`.
	 */
	private static function pending( $since ) {
		if ( ! class_exists( 'Saddle_OAuth_Store' ) ) {
			return array();
		}

		$granted = array();
		foreach ( Saddle_OAuth_Store::list_grants() as $grant ) {
			$granted[ (string) $grant['client_id'] ] = true;
		}

		$pending = array();
		foreach ( Saddle_OAuth_Store::list_clients() as $client ) {
			$id = isset( $client['client_id'] ) ? (string) $client['client_id'] : '';
			if ( '' === $id || isset( $granted[ $id ] ) ) {
				continue;
			}
			if ( 'dcr' !== ( isset( $client['client_source'] ) ? $client['client_source'] : 'dcr' ) ) {
				continue;
			}
			$registered = isset( $client['client_created'] ) ? (int) $client['client_created'] : 0;
			if ( $registered <= $since ) {
				continue;
			}

			$name      = isset( $client['client_name'] ) ? (string) $client['client_name'] : '';
			$pending[] = array(
				'client_id'     => $id,
				'client_name'   => $name,
				'app'           => Saddle_Connection_Apps::detect(
					array(
						'urls'        => isset( $client['redirect_uris'] ) ? (array) $client['redirect_uris'] : array(),
						'client_name' => $name,
						'name'        => $name,
					)
				),
				'registered_at' => $registered,
			);
		}

		return $pending;
	}

	/**
	 * One connection as the admin sees it.
	 *
	 * @param string $id         Connection id.
	 * @param array  $records    Every registry record.
	 * @param array  $credential From key_credential() or grant_credential().
	 * @return array
	 */
	private static function row( $id, array $records, array $credential ) {
		$record = isset( $records[ $id ] ) ? $records[ $id ] : array();
		$field  = static function ( $key, $fallback = '' ) use ( $record ) {
			return isset( $record[ $key ] ) ? $record[ $key ] : $fallback;
		};

		$app = Saddle_Connection_Apps::detect(
			array(
				'urls'        => $credential['urls'],
				'client_name' => $field( 'client_name' ),
				'agent'       => $field( 'agent' ),
				'name'        => $credential['name'],
			)
		);

		return array(
			'id'            => $id,
			'kind'          => $credential['kind'],
			'app'           => $app,
			'name'          => $credential['name'],
			// What the app calls itself, e.g. "claude-ai 0.1.0". The admin shows
			// it when no app was detected.
			'client'        => trim( $field( 'client_name' ) . ' ' . $field( 'client_version' ) ),
			'user_login'    => $credential['user_login'],
			'created_at'    => (int) $credential['created_at'],
			'first_seen_at' => (int) $field( 'first_seen_at', 0 ),
			'first_tool_at' => (int) $field( 'first_tool_at', 0 ),
			'last_seen_at'  => (int) $field( 'last_seen_at', 0 ),
			'last_tool_at'  => (int) $field( 'last_tool_at', 0 ),
			'last_tool'     => (string) $field( 'last_tool' ),
			'recent_tools'  => array_values( (array) $field( 'recent_tools', array() ) ),
		) + $credential['extra'];
	}

	/**
	 * The key or grant behind a registry record, or null when it is gone.
	 *
	 * @param string $id     Connection id.
	 * @param array  $record The record.
	 * @return array|null
	 */
	private static function lookup( $id, array $record ) {
		list( $kind, $ref ) = array_pad( explode( ':', (string) $id, 2 ), 2, '' );

		if ( 'key' === $kind && class_exists( 'WP_Application_Passwords' ) ) {
			$user = get_userdata( isset( $record['user'] ) ? (int) $record['user'] : 0 );
			$item = $user ? WP_Application_Passwords::get_user_application_password( $user->ID, $ref ) : null;

			return $item ? self::key_credential( $user, $item ) : null;
		}

		if ( 'oauth' === $kind && class_exists( 'Saddle_OAuth_Store' ) ) {
			$grant = Saddle_OAuth_Store::get_grant( $ref );

			return $grant ? self::grant_credential( $grant ) : null;
		}

		return null;
	}

	/**
	 * What a key says about its connection.
	 *
	 * @param WP_User $user Key owner.
	 * @param array   $item Core's Application Password record.
	 * @return array
	 */
	private static function key_credential( $user, array $item ) {
		$name  = isset( $item['name'] ) ? (string) $item['name'] : '';
		$hints = get_user_meta( $user->ID, 'saddle_client_hints', true );
		$uuid  = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';

		return array(
			'kind'       => 'key',
			// The app picked in the wizard, without the "Saddle: " prefix.
			'name'       => 0 === strpos( $name, Saddle_REST_Admin::CLIENT_PREFIX ) ? trim( substr( $name, strlen( Saddle_REST_Admin::CLIENT_PREFIX ) ) ) : $name,
			'user_login' => $user->user_login,
			'created_at' => isset( $item['created'] ) ? (int) $item['created'] : 0,
			'urls'       => array(),
			'extra'      => array(
				// The key's last four characters, as the Apps tab shows them.
				'hint' => is_array( $hints ) && isset( $hints[ $uuid ] ) ? (string) $hints[ $uuid ] : null,
			),
		);
	}

	/**
	 * What an OAuth grant says about its connection.
	 *
	 * @param array $grant The grant.
	 * @return array
	 */
	private static function grant_credential( array $grant ) {
		$client_id = (string) $grant['client_id'];
		$client    = Saddle_OAuth_Store::get_client( $client_id );
		$user      = get_userdata( (int) $grant['user_id'] );

		return array(
			'kind'       => 'oauth',
			'name'       => '' !== (string) $grant['client_name'] ? (string) $grant['client_name'] : $client_id,
			'user_login' => $user ? $user->user_login : '',
			'created_at' => (int) $grant['grant_created'],
			// The client id is the metadata URL for a CIMD client; the redirect
			// URIs name the app for a registered one.
			'urls'       => array_merge( array( $client_id ), $client && isset( $client['redirect_uris'] ) ? (array) $client['redirect_uris'] : array() ),
			'extra'      => array(
				'level' => Saddle_OAuth::scope_to_tier( (string) $grant['scope'] ),
			),
		);
	}
}
