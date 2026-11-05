<?php
/**
 * Run without asking: one agent's changes skip every preview and Needs your OK.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The owner's per-agent switch on Saddle → Agent setup. Fahim, 2026-11-06:
 * "after manage mode on, saddle every change need approved mode.. so about
 * add bypass mode also if it own no need approved manually from admin?", then
 * "bypass mode on ai agent exclitly add/update/delete anythig no approved
 * needed".
 *
 * For one connection set to Manage the site, nothing waits. A tool in Ask
 * first runs as if it were Allow (`Saddle_Tool_Modes::run()`), and a change
 * that would preview first runs on the first call, as if the agent had come
 * back with a valid token (`Saddle_Approval::gate()`), logged the same way.
 *
 * What it never changes: a tool set to Off is refused, the role is the
 * ceiling, the pause, rehearsal and drafts only apply (a publish under drafts
 * only still previews), delete-post and delete-page still move to the Trash
 * unless the agent passes `force`, and every change is logged and can be
 * undone.
 *
 * Off by default, for every agent. Only the owner turns it on, in wp-admin:
 * the option is protected from every tool (`Saddle_Settings_Guard`), and the
 * REST route refuses a request made with an agent's sign-in. It is cleared
 * when the agent's role drops below Manage the site or the agent is
 * disconnected, and ignored while the role, or a ceiling on the request, is
 * below it.
 *
 * Its own class rather than part of `Saddle_Access`, which is past its size
 * budget: one option, its clean-up and one question, "does this agent skip
 * the asking?".
 */
class Saddle_Run_Without_Asking {

	/**
	 * Option holding the connection ids (`key:<uuid>`, `oauth:<grant>`) the
	 * owner turned it on for. Small and read on every changing call, so
	 * autoloaded.
	 */
	const OPTION = 'saddle_run_without_asking';

	/**
	 * At most this many connections are kept. Each entry is one agent the
	 * owner chose by hand; stale ones are dropped on every change.
	 */
	const MAX = 100;

	/**
	 * The role an agent must hold.
	 */
	const ROLE = 'admin';

	/**
	 * Wire the clean-up for a deleted key. A revoked grant is cleaned up by
	 * `Saddle_OAuth_Store::revoke_grant()`, a lowered role by
	 * `Saddle_Access::set_role()`.
	 */
	public static function register() {
		add_action( 'wp_delete_application_password', array( __CLASS__, 'forget_key' ), 10, 2 );
	}

	/**
	 * Whether the owner turned it on for a connection, whatever its role now.
	 *
	 * @param string $connection_id `key:…` or `oauth:…`.
	 * @return bool
	 */
	public static function is_on( $connection_id ) {
		return '' !== (string) $connection_id && in_array( (string) $connection_id, self::ids(), true );
	}

	/**
	 * Whether a connection's changes run without a preview and without
	 * waiting for the owner: it is on, and the connection holds Manage the
	 * site. For the calling connection, its tier on this request must be
	 * Manage the site too, so a ceiling (Saddle Cloud, a lowered token) also
	 * turns it off.
	 *
	 * @param string $connection_id `key:…`, `oauth:…`, or '' for a browser
	 *                              session, which is never asked anyway.
	 * @return bool
	 */
	public static function applies( $connection_id ) {
		$connection_id = (string) $connection_id;
		if ( ! self::is_on( $connection_id ) || self::ROLE !== Saddle_Access::role_for( $connection_id ) ) {
			return false;
		}

		return Saddle_Access::current_connection() !== $connection_id || self::ROLE === Saddle_Capabilities::get_tier();
	}

	/**
	 * Turn it on or off for one connection. Only the owner does this, in
	 * wp-admin; no tool can.
	 *
	 * @param string $connection_id `key:…` or `oauth:…`.
	 * @param bool   $on            On or off.
	 * @return true|WP_Error A WP_Error when turning it on for an agent that
	 *                       does not hold Manage the site.
	 */
	public static function set( $connection_id, $on ) {
		$connection_id = (string) $connection_id;
		if ( ! preg_match( '/^(key|oauth):[A-Za-z0-9-]+$/', $connection_id ) ) {
			return new WP_Error( 'saddle_unknown_connection', __( 'That connection no longer exists.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( $on && self::ROLE !== Saddle_Access::role_for( $connection_id ) ) {
			return new WP_Error( 'saddle_run_without_asking_role', __( 'Only an agent set to Manage the site can run without asking.', 'saddle' ), array( 'status' => 409 ) );
		}

		// Keep only agents that can still use it, so the list stays small.
		$ids = array_values(
			array_filter(
				self::ids(),
				static function ( $id ) use ( $connection_id ) {
					return $id !== $connection_id && self::ROLE === Saddle_Access::role_for( $id );
				}
			)
		);
		if ( $on ) {
			$ids[] = $connection_id;
		}

		self::save( array_slice( $ids, -self::MAX ) );

		return true;
	}

	/**
	 * Turn it off for a connection: its role dropped below Manage the site,
	 * or it was disconnected.
	 *
	 * @param string $connection_id `key:…` or `oauth:…`.
	 */
	public static function forget( $connection_id ) {
		$ids = self::ids();
		if ( in_array( (string) $connection_id, $ids, true ) ) {
			self::save( array_values( array_diff( $ids, array( (string) $connection_id ) ) ) );
		}
	}

	/**
	 * Core's `wp_delete_application_password` action: a deleted key keeps
	 * nothing.
	 *
	 * @param int   $user_id User the key belonged to.
	 * @param array $item    The deleted key.
	 */
	public static function forget_key( $user_id, $item ) {
		unset( $user_id );
		if ( is_array( $item ) && ! empty( $item['uuid'] ) ) {
			self::forget( 'key:' . $item['uuid'] );
		}
	}

	/**
	 * The stored connection ids, with anything malformed dropped.
	 *
	 * @return string[]
	 */
	private static function ids() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * Store the list, or remove the option once it is empty.
	 *
	 * @param string[] $ids Connection ids.
	 */
	private static function save( array $ids ) {
		if ( $ids ) {
			update_option( self::OPTION, $ids, true );
		} else {
			delete_option( self::OPTION );
		}
	}
}
