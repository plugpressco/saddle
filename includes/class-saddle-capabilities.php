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
	 * Option key for opt-in domain-drift enforcement. Off by default (warn
	 * only). When on, write- and admin-tier abilities are refused while the
	 * current domain differs from the one the tier was granted on — a cloned
	 * or migrated database then lands read-only until the owner re-confirms
	 * the access level (set_tier re-records the domain).
	 */
	const ENFORCE_DOMAIN_OPTION = 'saddle_enforce_tier_domain';

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
	 * The enforced gate parameters per ability short name, recorded when
	 * permission() builds each closure. denial_reason() reads the SAME level
	 * and capability the closure enforces — never a re-derivation from meta,
	 * which could drift from what is actually checked.
	 *
	 * @var array<string,array{level:string,cap:string|null}>
	 */
	private static $gates = array();

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
		if ( $short_name ) {
			self::$gates[ (string) $short_name ] = array(
				'level' => (string) $level,
				'cap'   => $cap,
			);
		}
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

			if ( 'read' !== $level && self::is_domain_enforced() && ! self::domain_matches_recorded() ) {
				self::log_denial( $short_name, 'domain' );
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
	 * Explain, in agent-actionable terms, why a tool call would be refused
	 * right now — so an AI that hits a denial can tell the user exactly what
	 * to do instead of retrying blind.
	 *
	 * Core's Abilities API swallows WP_Error from permission callbacks
	 * (execute() substitutes a generic 'ability_invalid_permissions'), so the
	 * legible message can't come from permission() itself. Saddle's fallback
	 * transport calls this AFTER a generic denial to reconstruct the reason
	 * from current state, in the same order permission() checks it.
	 *
	 * @param string $ability_name Full ability id, e.g. 'saddle/create-post'.
	 * @return array|null { code, message } or null when nothing would block
	 *                    (e.g. the denial came from an object-level check
	 *                    inside the ability, not from Saddle's gates).
	 */
	public static function denial_reason( $ability_name ) {
		$short = 0 === strpos( (string) $ability_name, 'saddle/' )
			? substr( (string) $ability_name, strlen( 'saddle/' ) )
			: (string) $ability_name;

		if ( self::is_paused() ) {
			return array(
				'code'    => 'saddle_paused',
				'message' => __( 'The site owner has paused all AI access — every tool is refused until they resume it from Saddle → Settings. Do not retry; tell the user Saddle is paused.', 'saddle' ),
			);
		}

		if ( ! is_user_logged_in() ) {
			return array(
				'code'    => 'saddle_not_authenticated',
				'message' => __( 'The request is not authenticated. Reconnect the app from Saddle → Connections to issue a fresh sign-in key.', 'saddle' ),
			);
		}

		// From here on, mirror permission()'s exact order — capability,
		// disabled, domain, tier — reading the SAME level/cap it enforces
		// (recorded at closure build time), never a re-derivation from meta.
		$gate = isset( self::$gates[ $short ] ) ? self::$gates[ $short ] : null;

		if ( $gate && $gate['cap'] && ! current_user_can( $gate['cap'] ) ) {
			return array(
				'code'    => 'saddle_capability_denied',
				'message' => sprintf(
					/* translators: %s: tool name. */
					__( 'The connected WordPress account lacks the permission the "%s" tool needs. This is an account limitation, not a Saddle setting — reconnect as a user with more rights if this should be possible.', 'saddle' ),
					$short
				),
			);
		}

		if ( '' !== $short && ! self::is_ability_enabled( $short ) ) {
			return array(
				'code'    => 'saddle_tool_disabled',
				'message' => sprintf(
					/* translators: %s: tool name. */
					__( 'The site owner turned the "%s" tool off individually (Saddle → Permissions). Other tools still work — do not retry this one; tell the user it is disabled.', 'saddle' ),
					$short
				),
			);
		}

		$required = $gate ? (string) $gate['level'] : '';
		if ( '' === $required ) {
			// Wrappers or abilities registered before this process recorded a
			// gate (e.g. cross-process introspection): fall back to meta.
			$ability = function_exists( 'wp_get_ability' ) && isset( wp_get_abilities()[ $ability_name ] )
				? wp_get_ability( $ability_name )
				: null;
			if ( $ability ) {
				$meta     = $ability->get_meta();
				$required = isset( $meta['saddle']['tier'] ) ? (string) $meta['saddle']['tier'] : '';
			}
		}

		if ( 'read' !== $required && '' !== $required && self::is_domain_enforced() && ! self::domain_matches_recorded() ) {
			return array(
				'code'    => 'saddle_domain_drift',
				'message' => __( 'This site\'s domain changed since write access was granted, and the owner has domain enforcement on — write tools are refused until they re-confirm the access level (Saddle → Permissions). Do not retry; tell the user.', 'saddle' ),
			);
		}

		if ( '' !== $required && ! self::tier_allows( $required ) ) {
			return array(
				'code'    => 'saddle_tier_denied',
				'message' => sprintf(
					/* translators: 1: required access level, 2: current access level. */
					__( 'This tool needs the "%1$s" access level, but this site allows "%2$s". Only the site owner can raise it (Saddle → Permissions). Do not retry — ask the user to change the level if they want this done.', 'saddle' ),
					$required,
					self::get_tier()
				),
			);
		}

		return null;
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
	 * @param string      $reason     'paused' | 'capability' | 'disabled' | 'domain' | 'tier'.
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
			case 'domain':
				/* translators: %s: tool name. */
				$summary = sprintf( __( 'Blocked: the site domain changed since write access was granted — "%s" is refused until the access level is re-confirmed.', 'saddle' ), $tool );
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
	 * Whether domain-drift enforcement is on (see ENFORCE_DOMAIN_OPTION).
	 *
	 * @return bool
	 */
	public static function is_domain_enforced() {
		return (bool) get_option( self::ENFORCE_DOMAIN_OPTION, false );
	}

	/**
	 * Turn domain-drift enforcement on or off.
	 *
	 * @param bool $enforce Whether write/admin abilities require the recorded domain.
	 * @return bool
	 */
	public static function set_domain_enforcement( $enforce ) {
		return update_option( self::ENFORCE_DOMAIN_OPTION, (bool) $enforce );
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
