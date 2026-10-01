<?php
/**
 * Where the service records come from: Unsplash, the native plugins, the
 * enrolled add-ons and the `saddle_services` filter, each in one shape.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the records Saddle_Services lists. Tools are not looked up here;
 * Saddle_Services adds them from the abilities registry.
 */
class Saddle_Service_Sources {

	/**
	 * Unsplash, the one outside account Saddle ships.
	 *
	 * @return array
	 */
	public static function unsplash() {
		$configured = Saddle_Unsplash::is_configured();

		return self::record(
			array(
				'key'             => 'unsplash',
				'name'            => 'Unsplash',
				'description'     => __( 'Stock photos', 'saddle' ),
				'agent'           => __( 'search and import stock photos', 'saddle' ),
				'kind'            => 'account',
				'source'          => 'built-in',
				'status'          => $configured ? 'ready' : 'needs_key',
				'enabled'         => $configured,
				'credential'      => array(
					'configured' => $configured,
					'hint'       => Saddle_Unsplash::key_hint(),
					'connector'  => Saddle_Services_Connector::connector_id(),
				),
				'sends'           => array(
					array(
						'host' => 'api.unsplash.com',
						'what' => __( 'Your search words or a photo ID, and your key', 'saddle' ),
						'when' => __( 'When an app searches or imports', 'saddle' ),
					),
					array(
						'host' => 'images.unsplash.com',
						'what' => __( 'The photo file download', 'saddle' ),
						'when' => __( 'When an app imports a photo', 'saddle' ),
					),
				),
				'terms_url'       => 'https://unsplash.com/api-terms',
				'privacy_url'     => 'https://unsplash.com/privacy',
				'credentials_url' => 'https://unsplash.com/developers',
			)
		);
	}

	/**
	 * The native plugins, each only while its plugin is active.
	 *
	 * @return array[]
	 */
	public static function natives() {
		$defs = array(
			'yoast'     => array( 'Yoast SEO', __( 'Yoast’s own SEO fields, edited natively.', 'saddle' ), 'Saddle_Yoast' ),
			'rank-math' => array( 'Rank Math', __( 'Rank Math’s own SEO fields, edited natively.', 'saddle' ), 'Saddle_Rank_Math' ),
			'aioseo'    => array( 'AIOSEO', __( 'AIOSEO’s own SEO fields, edited natively.', 'saddle' ), 'Saddle_Aioseo' ),
			'wc'        => array( 'WooCommerce', __( 'Products and orders, handled natively.', 'saddle' ), 'Saddle_WC' ),
		);

		$records = array();
		foreach ( $defs as $key => $def ) {
			if ( ! is_callable( array( $def[2], 'is_active' ) ) || ! call_user_func( array( $def[2], 'is_active' ) ) ) {
				continue;
			}
			$records[] = self::record(
				array(
					'key'         => $key,
					'name'        => $def[0],
					'description' => $def[1],
					'kind'        => 'plugin',
					'source'      => 'built-in',
					'status'      => 'detected',
				)
			);
		}

		return $records;
	}

	/**
	 * The plugins enrolled through `saddle_integrations`.
	 *
	 * @return array[]
	 */
	public static function addons() {
		$records = array();
		foreach ( Saddle_Integrations::listing() as $row ) {
			$third = 'third-party' === $row['source'];
			$on    = (bool) $row['enabled'];

			$records[] = self::record(
				array(
					'key'         => $row['slug'],
					'name'        => $row['title'],
					'description' => $row['description'],
					'kind'        => 'addon',
					'source'      => $row['source'],
					'status'      => $on ? 'active' : 'off',
					'enabled'     => $on,
					'can_toggle'  => $third,
					'author'      => $row['author'],
					'url'         => $row['url'],
					'tool_count'  => (int) $row['tools'],
				)
			);
		}

		return $records;
	}

	/**
	 * Accounts by key, built without looking up tools, for the cheap check.
	 *
	 * @return array<string,array>
	 */
	public static function accounts() {
		$accounts = array( 'unsplash' => self::unsplash() );
		foreach ( self::extra() as $record ) {
			if ( 'account' === $record['kind'] && ! isset( $accounts[ $record['key'] ] ) ) {
				$accounts[ $record['key'] ] = $record;
			}
		}

		return $accounts;
	}

	/**
	 * Records other plugins add through `saddle_services`, normalized.
	 *
	 * @return array[]
	 */
	private static function extra() {
		/**
		 * Add a service: anything that sends data off the site or holds a
		 * credential. Same shape as a record from Saddle_Services::all();
		 * `key`, `name` and `kind` are required. Tools come from the abilities
		 * registry under `saddle/{key}-*`, so a plugin enrols those through
		 * `saddle_integrations`. Invalid records are dropped.
		 *
		 * @param array[] $records Records added so far (none by default).
		 */
		$raw = apply_filters( 'saddle_services', array() );

		$records = array();
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$record = self::normalize( $item );
			if ( $record ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Add the filtered records after Saddle's own. A key already taken wins,
	 * so a plugin cannot replace Unsplash or an enrolled add-on.
	 *
	 * @param array[] $records Saddle's own records.
	 * @return array[]
	 */
	public static function merge_extra( array $records ) {
		$taken = wp_list_pluck( $records, 'key' );
		foreach ( self::extra() as $record ) {
			if ( ! in_array( $record['key'], $taken, true ) ) {
				$records[] = $record;
				$taken[]   = $record['key'];
			}
		}

		return $records;
	}

	/* ------------------------------------------------------------ shape */

	/**
	 * Check a record from the filter and fill its defaults. Null when invalid.
	 *
	 * @param mixed $item As supplied.
	 * @return array|null
	 */
	private static function normalize( $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$key  = isset( $item['key'] ) && is_string( $item['key'] ) ? sanitize_key( $item['key'] ) : '';
		$name = isset( $item['name'] ) && is_string( $item['name'] ) ? trim( wp_strip_all_tags( $item['name'] ) ) : '';
		$kind = isset( $item['kind'] ) && is_string( $item['kind'] ) ? $item['kind'] : '';
		if ( '' === $key || '' === $name || ! in_array( $kind, Saddle_Services::KINDS, true ) ) {
			return null;
		}

		$source     = isset( $item['source'] ) && in_array( $item['source'], Saddle_Services::SOURCES, true ) ? $item['source'] : 'third-party';
		$credential = null;
		if ( 'account' === $kind ) {
			$given      = isset( $item['credential'] ) && is_array( $item['credential'] ) ? $item['credential'] : array();
			$credential = array(
				'configured' => ! empty( $given['configured'] ),
				'hint'       => isset( $given['hint'] ) && is_string( $given['hint'] ) ? substr( wp_strip_all_tags( $given['hint'] ), -4 ) : '',
				'connector'  => null,
			);
		}

		$default = array(
			'account' => $credential && $credential['configured'] ? 'ready' : 'needs_key',
			'plugin'  => 'detected',
			'addon'   => 'active',
		);
		$status  = isset( $item['status'] ) && in_array( $item['status'], Saddle_Services::STATUSES, true ) ? $item['status'] : $default[ $kind ];

		return self::record(
			array(
				'key'             => $key,
				'name'            => $name,
				'description'     => isset( $item['description'] ) && is_string( $item['description'] ) ? wp_strip_all_tags( $item['description'] ) : '',
				'kind'            => $kind,
				'source'          => $source,
				'status'          => $status,
				'enabled'         => isset( $item['enabled'] ) ? (bool) $item['enabled'] : 'off' !== $status && 'needs_key' !== $status,
				'credential'      => $credential,
				'sends'           => self::sends( isset( $item['sends'] ) ? $item['sends'] : array() ),
				'terms_url'       => isset( $item['terms_url'] ) ? $item['terms_url'] : '',
				'privacy_url'     => isset( $item['privacy_url'] ) ? $item['privacy_url'] : '',
				'credentials_url' => isset( $item['credentials_url'] ) ? $item['credentials_url'] : '',
			)
		);
	}

	/**
	 * Fill every field of a record, so the shape never varies.
	 *
	 * @param array $record Partial record.
	 * @return array
	 */
	private static function record( array $record ) {
		$record = array_merge(
			array(
				'key'             => '',
				'name'            => '',
				'description'     => '',
				'agent'           => '',
				'kind'            => 'plugin',
				'source'          => 'built-in',
				'status'          => 'detected',
				'enabled'         => true,
				'can_toggle'      => false,
				'author'          => '',
				'url'             => '',
				'credential'      => null,
				'tools'           => array(),
				'tool_count'      => 0,
				'sends'           => array(),
				'terms_url'       => '',
				'privacy_url'     => '',
				'credentials_url' => '',
			),
			$record
		);

		if ( is_array( $record['credential'] ) ) {
			$credential           = $record['credential'];
			$record['credential'] = array(
				'method'         => 'api_key',
				'configured'     => ! empty( $credential['configured'] ),
				'hint'           => isset( $credential['hint'] ) ? (string) $credential['hint'] : '',
				'connector'      => isset( $credential['connector'] ) ? $credential['connector'] : null,
				'connectors_url' => ! empty( $credential['connector'] ) ? admin_url( 'options-connectors.php' ) : null,
			);
		}

		foreach ( array( 'terms_url', 'privacy_url', 'credentials_url', 'url' ) as $field ) {
			$url              = is_string( $record[ $field ] ) ? esc_url_raw( $record[ $field ], array( 'https', 'http' ) ) : '';
			$record[ $field ] = $url;
		}

		return $record;
	}

	/**
	 * Clean a `sends` list to { host, what, when } rows.
	 *
	 * @param mixed $sends As supplied.
	 * @return array[]
	 */
	private static function sends( $sends ) {
		$rows = array();
		foreach ( is_array( $sends ) ? $sends : array() as $row ) {
			$host = is_array( $row ) && isset( $row['host'] ) && is_string( $row['host'] ) ? strtolower( trim( $row['host'] ) ) : '';
			if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
				continue;
			}
			$rows[] = array(
				'host' => $host,
				'what' => isset( $row['what'] ) && is_string( $row['what'] ) ? wp_strip_all_tags( $row['what'] ) : '',
				'when' => isset( $row['when'] ) && is_string( $row['when'] ) ? wp_strip_all_tags( $row['when'] ) : '',
			);
		}

		return $rows;
	}
}
