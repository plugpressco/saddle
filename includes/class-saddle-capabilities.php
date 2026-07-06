<?php
/**
 * Tier system — the single source of truth for every ability's
 * permission_callback.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access tiers: read < write < admin.
 *
 * A site sits at exactly one tier (stored in a single option). An ability
 * declares the minimum tier it needs; a call is permitted only when the site's
 * tier is at least that high AND the resolved WordPress user holds the relevant
 * generic capability (this class, in the permission_callback).
 *
 * That generic check is necessary but NOT sufficient: core's post insert/update/
 * delete primitives do not apply map_meta_cap. So the per-object meta-capability
 * checks — `edit_post`/`delete_post`/`publish_posts`/`edit_others_posts` against
 * the specific target — are enforced inside each ability's execute callback (see
 * Saddle_Abilities::authorize_write() in includes/abilities/core-content.php).
 * Together they ensure an Application Password tied to a low-privilege account
 * cannot edit or delete content it doesn't own, even at the write/admin tier.
 */
class Saddle_Capabilities {

	/**
	 * Option key for the site-wide access tier.
	 */
	const OPTION = 'saddle_access_tier';

	/**
	 * Default tier for fresh installs. Default-safe: power is opt-in.
	 */
	const DEFAULT_TIER = 'read';

	/**
	 * Option key for the set of individually-disabled ability short names.
	 *
	 * Orthogonal to the tier: an owner can turn off one specific tool (e.g.
	 * "delete-media") without dropping the whole site to a lower tier. Empty by
	 * default — nothing is disabled beyond what the tier itself withholds.
	 */
	const DISABLED_OPTION = 'saddle_disabled_abilities';

	/**
	 * Option key for the pause switch. Independent of tier: pausing denies every
	 * ability instantly without losing the tier the owner configured, so
	 * resuming restores exactly what was on before.
	 */
	const PAUSED_OPTION = 'saddle_paused';

	/**
	 * Option key for the hostname recorded the last time the tier was raised to
	 * write or admin. Application Passwords aren't domain-locked, so a cloned or
	 * migrated database carries live write access to wherever it lands. This is
	 * used only to warn the owner, never to auto-revoke.
	 */
	const TIER_DOMAIN_OPTION = 'saddle_tier_domain';

	/**
	 * Tier name => numeric rank. Higher rank = more power.
	 *
	 * @var array<string,int>
	 */
	private static $levels = array(
		'read'  => 0,
		'write' => 1,
		'admin' => 2,
	);

	/**
	 * Ordered list of valid tier names.
	 *
	 * @return string[]
	 */
	public static function tiers() {
		return array_keys( self::$levels );
	}

	/**
	 * Current site tier, validated against the known set.
	 *
	 * @return string
	 */
	public static function get_tier() {
		$tier = get_option( self::OPTION, self::DEFAULT_TIER );
		return isset( self::$levels[ $tier ] ) ? $tier : self::DEFAULT_TIER;
	}

	/**
	 * Set the site tier. Rejects unknown tier names.
	 *
	 * @param string $tier Tier name.
	 * @return bool True on a valid, persisted change.
	 */
	public static function set_tier( $tier ) {
		if ( ! isset( self::$levels[ $tier ] ) ) {
			return false;
		}
		$saved = update_option( self::OPTION, $tier );

		// Record which domain this power level was granted on. Re-confirming a
		// tier choice on a new domain (e.g. after a deliberate migration) is
		// exactly the action that should clear any stale-domain warning.
		if ( in_array( $tier, array( 'write', 'admin' ), true ) ) {
			self::record_tier_domain();
		}

		return $saved;
	}

	/**
	 * Whether the current site tier satisfies a required tier.
	 *
	 * @param string $required Required tier name.
	 * @return bool
	 */
	public static function tier_allows( $required ) {
		if ( ! isset( self::$levels[ $required ] ) ) {
			return false;
		}
		return self::$levels[ self::get_tier() ] >= self::$levels[ $required ];
	}

	/**
	 * Build a permission_callback for an ability.
	 *
	 * Returns a closure suitable for `wp_register_ability()`'s
	 * `permission_callback`. It enforces, in order: an authenticated user, the
	 * underlying WordPress capability, the site tier, and (if a short name is
	 * given) that the ability hasn't been individually disabled.
	 *
	 * Returns a strict boolean, NOT a WP_Error. Verified against core
	 * (wp-includes/abilities-api/class-wp-ability.php): when a permission_callback
	 * returns a WP_Error, WP_Ability::execute() fires _doing_it_wrong() (which
	 * writes to debug.log on every denied call) and then discards the message,
	 * substituting a generic 'ability_invalid_permissions' error regardless.
	 * Returning false avoids the debug noise and produces the same clean denial.
	 *
	 * @param string      $level         Minimum tier required ('read'|'write'|'admin').
	 * @param string|null $cap           WordPress capability the user must hold, or
	 *                                   null to require only that the user is
	 *                                   authenticated.
	 * @param string|null $short_name    The ability id without the 'saddle/'
	 *                                   prefix, e.g. 'delete-media', so it can be
	 *                                   checked against the disabled-abilities
	 *                                   list. Omit only for abilities that must
	 *                                   never be individually disabled.
	 * @return callable
	 */
	public static function permission( $level, $cap = 'read', $short_name = null ) {
		return function () use ( $level, $cap, $short_name ) {
			$logged_in = is_user_logged_in();

			if ( self::is_paused() ) {
				// Only an authenticated caller is worth recording — anonymous
				// traffic is noise and a log-flood vector.
				if ( $logged_in ) {
					self::log_denial( $short_name, 'paused' );
				}
				return false;
			}

			if ( ! $logged_in ) {
				return false;
			}

			if ( $cap && ! current_user_can( $cap ) ) {
				self::log_denial( $short_name, 'capability' );
				return false;
			}

			if ( $short_name && ! self::is_ability_enabled( $short_name ) ) {
				self::log_denial( $short_name, 'disabled' );
				return false;
			}

			if ( ! self::tier_allows( $level ) ) {
				self::log_denial( $short_name, 'tier' );
				return false;
			}

			return true;
		};
	}

	/**
	 * Record a refused tool call to the activity log so a site owner can see when
	 * a connected agent tried something its access level, the pause switch, or a
	 * per-tool toggle blocked.
	 *
	 * This runs inside the permission_callback, which the MCP layer invokes ONLY
	 * on a real tools/call (never when merely listing tools) — so an entry here
	 * always reflects a genuine attempt, not discovery. It is throttled per
	 * (user, tool, reason) so an agent retrying a blocked call in a loop can't
	 * flood the log, and it never fires for anonymous requests.
	 *
	 * @param string|null $short_name Ability id without the 'saddle/' prefix.
	 * @param string      $reason     'paused' | 'capability' | 'disabled' | 'tier'.
	 */
	private static function log_denial( $short_name, $reason ) {
		if ( ! class_exists( 'Saddle_Log' ) ) {
			return;
		}

		$tool = $short_name ? (string) $short_name : 'a tool';

		// Throttle: at most one entry per user + tool + reason per 10 minutes.
		$key = 'saddle_deny_' . md5( get_current_user_id() . '|' . $tool . '|' . $reason );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );

		switch ( $reason ) {
			case 'paused':
				/* translators: %s: tool name. */
				$summary = sprintf( __( 'Blocked: Saddle is paused — "%s" was refused.', 'saddle' ), $tool );
				break;
			case 'capability':
				/* translators: %s: tool name. */
				$summary = sprintf( __( 'Blocked: your account lacks permission for "%s".', 'saddle' ), $tool );
				break;
			case 'disabled':
				/* translators: %s: tool name. */
				$summary = sprintf( __( 'Blocked: the tool "%s" is turned off.', 'saddle' ), $tool );
				break;
			case 'tier':
			default:
				/* translators: %s: tool name. */
				$summary = sprintf( __( 'Blocked: "%s" needs a higher access level than is enabled.', 'saddle' ), $tool );
				break;
		}

		Saddle_Log::record(
			array(
				'action'  => 'denied-' . $tool,
				'target'  => $reason,
				'summary' => $summary,
				'type'    => 'denied',
			)
		);
	}

	/**
	 * Whether Saddle is currently paused — every ability denied regardless of
	 * tier, without discarding the configured tier.
	 *
	 * @return bool
	 */
	public static function is_paused() {
		return (bool) get_option( self::PAUSED_OPTION, false );
	}

	/**
	 * Set the pause state.
	 *
	 * @param bool $paused Whether to pause.
	 * @return bool
	 */
	public static function set_paused( $paused ) {
		return update_option( self::PAUSED_OPTION, (bool) $paused );
	}

	/**
	 * The site's current hostname, for comparison against the recorded one.
	 *
	 * @return string
	 */
	public static function current_domain() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * The hostname recorded the last time the tier was raised to write/admin,
	 * or '' if never recorded (e.g. a site that has always been read-only, or
	 * one that upgraded from a Saddle version predating this check).
	 *
	 * @return string
	 */
	public static function recorded_tier_domain() {
		return (string) get_option( self::TIER_DOMAIN_OPTION, '' );
	}

	/**
	 * Stamp the current hostname as the domain the active tier was granted on.
	 */
	public static function record_tier_domain() {
		update_option( self::TIER_DOMAIN_OPTION, self::current_domain() );
	}

	/**
	 * Whether the site's current domain matches the one write/admin access was
	 * last granted on. Always true if nothing has been recorded yet, or if the
	 * site isn't currently at write/admin — there's nothing to warn about.
	 *
	 * @return bool
	 */
	public static function domain_matches_recorded() {
		if ( ! in_array( self::get_tier(), array( 'write', 'admin' ), true ) ) {
			return true;
		}
		$recorded = self::recorded_tier_domain();
		if ( '' === $recorded ) {
			return true;
		}
		return self::current_domain() === $recorded;
	}

	/**
	 * The set of ability short names an owner has individually turned off,
	 * regardless of tier.
	 *
	 * @return string[]
	 */
	public static function disabled_abilities() {
		$stored = get_option( self::DISABLED_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'sanitize_key', $stored ) ) );
	}

	/**
	 * Whether a specific ability is currently enabled (not on the disabled list).
	 *
	 * @param string $short_name Ability id without the 'saddle/' prefix.
	 * @return bool
	 */
	public static function is_ability_enabled( $short_name ) {
		return ! in_array( sanitize_key( $short_name ), self::disabled_abilities(), true );
	}

	/**
	 * Persist the set of individually-disabled ability short names.
	 *
	 * @param string[] $short_names Ability ids without the 'saddle/' prefix.
	 * @return string[] The sanitized, stored value.
	 */
	public static function set_disabled_abilities( array $short_names ) {
		$clean = array_values( array_unique( array_map( 'sanitize_key', $short_names ) ) );
		update_option( self::DISABLED_OPTION, $clean );
		return $clean;
	}
}
