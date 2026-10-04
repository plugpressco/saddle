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
		$records   = array_merge( array( Saddle_Service_Sources::unsplash() ), Saddle_Service_Sources::natives(), Saddle_Service_Sources::addons() );
		$records   = Saddle_Service_Sources::merge_extra( $records );

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
	 * False for a tool owned by an account that has no key yet, or by a
	 * plugin Saddle edits natively that is not active here (D3). Feeds
	 * Saddle_Capabilities::is_callable_now() and hidden_tool_counts(), so it
	 * stays cheap: it runs once per tool on every tools/list.
	 *
	 * A tool hidden this way still refuses by name with its own reason:
	 * denial_reason() asks unavailable_account(), and a native tool's own
	 * callback says its plugin is not active.
	 *
	 * @param string $ability_name Full ability id or its short name.
	 * @return bool
	 */
	public static function has_tools_available( $ability_name ) {
		return '' === self::unavailable_account( $ability_name )
			&& ( ! class_exists( 'Saddle_Integrations' ) || '' === Saddle_Integrations::absent_plugin( $ability_name ) );
	}

	/**
	 * The name of the account that is not set up and owns this tool, or ''.
	 *
	 * @param string $ability_name Full ability id or its short name.
	 * @return string
	 */
	public static function unavailable_account( $ability_name ) {
		$short = 0 === strpos( (string) $ability_name, 'saddle/' ) ? substr( (string) $ability_name, 7 ) : (string) $ability_name;

		foreach ( Saddle_Service_Sources::accounts() as $key => $account ) {
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
			// A third-party add-on the owner hasn't switched on is already named,
			// with how to turn it on, under "Plugins connected to Saddle".
			if ( 'off' === $record['status'] ) {
				continue;
			}
			$prefix = 'saddle-' . $record['key'] . '-*';
			if ( 'needs_key' === $record['status'] ) {
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
