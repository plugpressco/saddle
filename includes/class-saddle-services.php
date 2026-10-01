<?php
/**
 * Services — one list of what this site's AI apps can reach beyond core
 * content: an outside account (Unsplash), the plugins Saddle edits natively,
 * and the plugins that add tools.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The services registry (saddle#291). One record shape, three sources:
 * Unsplash, the four native plugins, and `saddle_integrations` enrolments.
 * A plugin adds a record of its own through the `saddle_services` filter, for
 * anything that sends data off the site or holds a credential.
 *
 * A record never carries a key. `credential` says whether one is set and its
 * last four characters, nothing else.
 */
class Saddle_Services {

	const KINDS    = array( 'account', 'plugin', 'addon' );
	const SOURCES  = array( 'built-in', 'plugpress', 'third-party' );
	const STATUSES = array( 'ready', 'needs_key', 'detected', 'active', 'off' );

	/**
	 * Lines the agent context spends on services.
	 */
	const CONTEXT_LINES = 8;

	/**
	 * Every record, accounts first, then plugins, then add-ons.
	 *
	 * @return array[]
	 */
	public static function all() {
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		$records   = array_merge( array( self::unsplash() ), self::natives(), self::addons() );
		$records   = self::merge_extra( $records );

		// A tool belongs to the record with the longest matching key, so
		// `rank-math-*` is Rank Math's and not Saddle Rank's.
		$keys = wp_list_pluck( $records, 'key' );
		foreach ( $records as $i => $record ) {
			$records[ $i ]['tools'] = self::tools_of( $record['key'], $keys, $abilities );
			if ( 'off' !== $record['status'] || $records[ $i ]['tool_count'] < 1 ) {
				$records[ $i ]['tool_count'] = count( $records[ $i ]['tools'] );
			}
		}

		// Accounts first, then plugins, then add-ons; stable on PHP 7.4.
		$ordered = array();
		foreach ( self::KINDS as $kind ) {
			foreach ( $records as $record ) {
				if ( $kind === $record['kind'] ) {
					$ordered[] = $record;
				}
			}
		}
		$records = $ordered;

		return $records;
	}

	/**
	 * One record by key, or null.
	 *
	 * @param string $key Service key.
	 * @return array|null
	 */
	public static function get( $key ) {
		foreach ( self::all() as $record ) {
			if ( $record['key'] === $key ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * False only for a tool owned by an account that has no key yet. Feeds
	 * Saddle_Capabilities::is_callable_now() and denial_reason(), so it stays
	 * cheap: it runs once per tool on every tools/list.
	 *
	 * @param string $ability_name Full ability id or its short name.
	 * @return bool
	 */
	public static function has_tools_available( $ability_name ) {
		return '' === self::unavailable_account( $ability_name );
	}

	/**
	 * The name of the account that is not set up and owns this tool, or ''.
	 *
	 * @param string $ability_name Full ability id or its short name.
	 * @return string
	 */
	public static function unavailable_account( $ability_name ) {
		$short = 0 === strpos( (string) $ability_name, 'saddle/' ) ? substr( (string) $ability_name, 7 ) : (string) $ability_name;

		foreach ( self::accounts() as $key => $account ) {
			if ( 'needs_key' === $account['status'] && 0 === strpos( $short, $key . '-' ) ) {
				return $account['name'];
			}
		}

		return '';
	}

	/**
	 * The agent's Services section. Runs on `saddle_context_sections`.
	 *
	 * @param array[] $sections Sections so far.
	 * @return array[]
	 */
	public static function context_section( $sections ) {
		$lines = array();
		foreach ( self::all() as $record ) {
			if ( $record['tool_count'] < 1 ) {
				continue;
			}
			$prefix = 'saddle-' . $record['key'] . '-*';
			if ( 'off' === $record['status'] ) {
				/* translators: %s: plugin name. */
				$lines[] = sprintf( __( '- %s: switched off by the owner. Don\'t ask for it.', 'saddle' ), $record['name'] );
			} elseif ( 'needs_key' === $record['status'] ) {
				/* translators: %s: service name. */
				$lines[] = sprintf( __( '- %s: not set up; ask the owner to add a key under Saddle → Services.', 'saddle' ), $record['name'] );
			} elseif ( 'ready' === $record['status'] ) {
				/* translators: 1: service name, 2: what it does. */
				$lines[] = sprintf( __( '- %1$s: ready (%2$s).', 'saddle' ), $record['name'], '' !== $record['agent'] ? $record['agent'] : $record['description'] );
			} else {
				/* translators: 1: plugin name, 2: tool-name pattern such as saddle-yoast-*. */
				$lines[] = sprintf( 'detected' === $record['status'] ? __( '- %1$s: detected; use the %2$s tools.', 'saddle' ) : __( '- %1$s: active; use the %2$s tools.', 'saddle' ), $record['name'], $prefix );
			}
		}

		if ( ! $lines ) {
			return $sections;
		}

		$sections[] = array(
			'id'       => 'services',
			'title'    => __( 'Services on this site', 'saddle' ),
			'lines'    => array_slice( $lines, 0, self::CONTEXT_LINES ),
			'priority' => 42,
		);

		return $sections;
	}

	/* ------------------------------------------------------------ sources */

	/**
	 * Unsplash, the one outside account Saddle ships.
	 *
	 * @return array
	 */
	private static function unsplash() {
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
	private static function natives() {
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
	private static function addons() {
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
	private static function accounts() {
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
	private static function merge_extra( array $records ) {
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
		if ( '' === $key || '' === $name || ! in_array( $kind, self::KINDS, true ) ) {
			return null;
		}

		$source     = isset( $item['source'] ) && in_array( $item['source'], self::SOURCES, true ) ? $item['source'] : 'third-party';
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
		$status  = isset( $item['status'] ) && in_array( $item['status'], self::STATUSES, true ) ? $item['status'] : $default[ $kind ];

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

	/**
	 * The tools a record owns: `saddle/{key}-*` abilities, each with its
	 * tier as `role` and the ability's label as `title`.
	 *
	 * @param string   $key       Record key.
	 * @param string[] $all_keys  Every record key, for the longest-match rule.
	 * @param array    $abilities Registered abilities.
	 * @return array[]
	 */
	private static function tools_of( $key, array $all_keys, array $abilities ) {
		$tools = array();
		foreach ( $abilities as $name => $ability ) {
			$name = is_string( $name ) ? $name : $ability->get_name();
			if ( 0 !== strpos( $name, 'saddle/' . $key . '-' ) ) {
				continue;
			}
			$short = substr( $name, 7 );
			foreach ( $all_keys as $other ) {
				if ( strlen( $other ) > strlen( $key ) && 0 === strpos( $short, $other . '-' ) ) {
					continue 2;
				}
			}
			$meta    = $ability->get_meta();
			$tools[] = array(
				'name'  => $short,
				'title' => $ability->get_label(),
				'role'  => isset( $meta['saddle']['tier'] ) ? (string) $meta['saddle']['tier'] : 'read',
			);
		}

		return $tools;
	}
}
