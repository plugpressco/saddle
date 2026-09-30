<?php
/**
 * Core's own settings, described as one schema.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scope `saddle` of the settings registry: the fourteen options Core already
 * keeps, given one shape so the admin draws them, `/preferences/saddle`
 * serves them and `saddle/get-module-settings` explains them.
 *
 * It describes storage; it moves none. Each field is stored exactly where it
 * always was, through the setter that already owned it. Every field is
 * `agent: read`: the access switches, sign-in, the key and the memory limits
 * are the owner's to change (ADMIN-SYSTEM.md 6.3).
 *
 * Its own class so the registry stays the generic part, and so the OAuth
 * readiness check has one home that the old `/oauth-settings` route and the
 * new settings route both call.
 */
class Saddle_Core_Settings {

	/** Scope name of Core in the settings registry. */
	const SCOPE = 'saddle';

	/** Option names of the four Advanced fields that had no screen before. */
	const OPTION_RECENT_CHANGES = 'saddle_memory_recent_changes';
	const OPTION_RECENT_LIMIT   = 'saddle_memory_recent_limit';

	/**
	 * The schema, in the registry's shape.
	 *
	 * @return array
	 */
	public static function schema() {
		return array(
			'title'  => __( 'Saddle', 'saddle' ),
			'store'  => array(
				'get' => array( __CLASS__, 'get' ),
				'set' => array( __CLASS__, 'set' ),
			),
			'fields' => self::fields(),
		);
	}

	/**
	 * The fields. Defaults equal what the code uses when the option is unset.
	 *
	 * @return array<string,array>
	 */
	public static function fields() {
		$permissions = 'connections/permissions';
		$apps        = 'connections/apps';

		return array(
			'tier'                    => array(
				'type'    => 'string',
				'enum'    => Saddle_Capabilities::tiers(),
				'default' => Saddle_Capabilities::DEFAULT_TIER,
				'label'   => __( 'Access level', 'saddle' ),
				'help'    => __( 'What connected apps may do: read only, write content, or administer the site.', 'saddle' ),
				'screen'  => $permissions,
				'control' => 'custom',
			),
			'drafts_only'             => array(
				'type'    => 'boolean',
				'default' => false,
				'label'   => __( 'Drafts only', 'saddle' ),
				'help'    => __( 'Apps can write, but nothing they make goes live until you publish it.', 'saddle' ),
				'screen'  => $permissions,
				'control' => 'custom',
			),
			'rehearsal'               => array(
				'type'    => 'boolean',
				'default' => false,
				'label'   => __( 'Rehearsal mode', 'saddle' ),
				'help'    => __( 'Apps see what each change would do, and nothing is saved.', 'saddle' ),
				'screen'  => $permissions,
				'control' => 'custom',
			),
			'paused'                  => array(
				'type'    => 'boolean',
				'default' => false,
				'label'   => __( 'Pause Saddle', 'saddle' ),
				'help'    => __( 'Every app is refused until you turn this off. Nothing else changes.', 'saddle' ),
				'screen'  => $permissions,
				'control' => 'custom',
			),
			'oauth_enabled'           => array(
				'type'    => 'boolean',
				'default' => false,
				'label'   => __( 'Sign in with your address', 'saddle' ),
				'help'    => __( 'Apps connect from the site address and you approve them on a consent screen.', 'saddle' ),
				'screen'  => $apps,
				'control' => 'custom',
			),
			'oauth_dcr'               => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Let apps register themselves', 'saddle' ),
				'help'    => __( 'Apps that support it can ask to connect. They get no access until you approve.', 'saddle' ),
				'screen'  => $apps,
				'control' => 'custom',
			),
			'oauth_cimd'              => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Let apps identify themselves by address', 'saddle' ),
				'help'    => __( 'Apps that publish a client document can ask to connect. They get no access until you approve.', 'saddle' ),
				'screen'  => $apps,
				'control' => 'custom',
			),
			'unsplash_key'            => array(
				'type'    => 'secret',
				'default' => '',
				'label'   => __( 'Unsplash Access Key', 'saddle' ),
				'help'    => __( 'Lets apps search and import Unsplash photos.', 'saddle' ),
				'screen'  => $permissions,
				'control' => 'custom',
			),
			'memory_autoinject_agent' => array(
				'type'    => 'boolean',
				'default' => false,
				'label'   => __( 'Share what apps remember', 'saddle' ),
				'help'    => __( 'Put notes that apps wrote into every new session, not only yours.', 'saddle' ),
				'screen'  => 'context/overview',
				'control' => 'custom',
			),
			'enforce_tier_domain'     => array(
				'type'    => 'boolean',
				'default' => false,
				'label'   => __( 'Block writes after a domain change', 'saddle' ),
				'help'    => __( 'If the site address changes, refuse writes until you confirm the access level again.', 'saddle' ),
				'screen'  => 'settings/advanced',
				'level'   => 'advanced',
			),
			'memory_max_entries'      => array(
				'type'    => 'integer',
				'minimum' => 10,
				'maximum' => 1000,
				'default' => Saddle_Memory::DEFAULT_MAX_ENTRIES,
				'label'   => __( 'Most memory entries kept', 'saddle' ),
				'help'    => __( 'The oldest, least used entries are dropped past this number.', 'saddle' ),
				'screen'  => 'settings/advanced',
				'level'   => 'advanced',
			),
			'memory_core_budget'      => array(
				'type'    => 'integer',
				'minimum' => 200,
				'maximum' => 10000,
				'default' => Saddle_Memory::DEFAULT_CORE_BUDGET,
				'label'   => __( 'Memory in each session', 'saddle' ),
				'help'    => __( 'The most characters of memory handed to an app at the start of a session.', 'saddle' ),
				'screen'  => 'settings/advanced',
				'level'   => 'advanced',
			),
			'memory_recent_changes'   => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Tell apps about recent changes', 'saddle' ),
				'help'    => __( 'New sessions start with a short list of what was changed lately.', 'saddle' ),
				'screen'  => 'settings/advanced',
				'level'   => 'advanced',
			),
			'memory_recent_limit'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 50,
				'default' => 15,
				'label'   => __( 'How many recent changes', 'saddle' ),
				'help'    => __( 'The length of that list.', 'saddle' ),
				'screen'  => 'settings/advanced',
				'level'   => 'advanced',
			),
		);
	}

	/**
	 * The current value of every field.
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		return array(
			'tier'                    => Saddle_Capabilities::get_site_tier(),
			'drafts_only'             => Saddle_Capabilities::is_drafts_only(),
			'rehearsal'               => Saddle_Capabilities::is_rehearsal(),
			'paused'                  => Saddle_Capabilities::is_paused(),
			'oauth_enabled'           => Saddle_OAuth::is_enabled(),
			'oauth_dcr'               => (bool) get_option( Saddle_OAuth_Clients::DCR_OPTION, true ),
			'oauth_cimd'              => (bool) get_option( Saddle_OAuth_Clients::CIMD_OPTION, true ),
			'unsplash_key'            => Saddle_Unsplash::get_key(),
			'memory_autoinject_agent' => (bool) get_option( Saddle_Memory::OPTION_AUTOINJECT, false ),
			'enforce_tier_domain'     => Saddle_Capabilities::is_domain_enforced(),
			'memory_max_entries'      => (int) get_option( Saddle_Memory::OPTION_MAX_ENTRIES, Saddle_Memory::DEFAULT_MAX_ENTRIES ),
			'memory_core_budget'      => (int) get_option( Saddle_Memory::OPTION_CORE_BUDGET, Saddle_Memory::DEFAULT_CORE_BUDGET ),
			'memory_recent_changes'   => (bool) get_option( self::OPTION_RECENT_CHANGES, true ),
			'memory_recent_limit'     => (int) get_option( self::OPTION_RECENT_LIMIT, 15 ),
		);
	}

	/**
	 * Store the given fields, each through the setter that owned it.
	 *
	 * @param array<string,mixed> $values Already validated values by field.
	 * @return true|WP_Error The first failure stops the rest.
	 */
	public static function set( array $values ) {
		foreach ( $values as $key => $value ) {
			$done = true;
			switch ( $key ) {
				case 'tier':
					$done = Saddle_Capabilities::set_tier( $value )
						? true
						: new WP_Error( 'saddle_invalid_tier', __( 'Unknown access tier.', 'saddle' ), array( 'status' => 400 ) );
					break;
				case 'drafts_only':
					Saddle_Capabilities::set_drafts_only( (bool) $value );
					break;
				case 'rehearsal':
					Saddle_Capabilities::set_rehearsal( (bool) $value );
					break;
				case 'paused':
					Saddle_Capabilities::set_paused( (bool) $value );
					break;
				case 'oauth_enabled':
					$done = self::set_oauth_enabled( (bool) $value );
					break;
				case 'oauth_dcr':
					update_option( Saddle_OAuth_Clients::DCR_OPTION, $value ? 1 : 0 );
					break;
				case 'oauth_cimd':
					update_option( Saddle_OAuth_Clients::CIMD_OPTION, $value ? 1 : 0 );
					break;
				case 'unsplash_key':
					$done = Saddle_Unsplash::set_key( (string) $value );
					break;
				case 'memory_autoinject_agent':
					update_option( Saddle_Memory::OPTION_AUTOINJECT, (bool) $value );
					break;
				case 'enforce_tier_domain':
					Saddle_Capabilities::set_domain_enforcement( (bool) $value );
					break;
				case 'memory_max_entries':
					update_option( Saddle_Memory::OPTION_MAX_ENTRIES, (int) $value );
					break;
				case 'memory_core_budget':
					update_option( Saddle_Memory::OPTION_CORE_BUDGET, (int) $value );
					break;
				case 'memory_recent_changes':
					update_option( self::OPTION_RECENT_CHANGES, (bool) $value );
					break;
				case 'memory_recent_limit':
					update_option( self::OPTION_RECENT_LIMIT, (int) $value );
					break;
			}
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}

		return true;
	}

	/**
	 * Turn sign-in with OAuth on or off.
	 *
	 * The one place that does it, for `POST /oauth-settings` and for the
	 * settings registry alike. Turning it on needs HTTPS and pretty
	 * permalinks, because a token sent over plain HTTP can be read in transit.
	 * Turning it off disconnects the apps rather than leaving live tokens
	 * waiting for it to come back on: "off" should mean off.
	 *
	 * @param bool $enabled Whether sign-in should be on.
	 * @return true|WP_Error A 409 when the site cannot host it.
	 */
	public static function set_oauth_enabled( $enabled ) {
		$readiness = Saddle_OAuth::readiness();
		if ( $enabled && ! $readiness['ready'] ) {
			return new WP_Error(
				'saddle_oauth_not_ready',
				$readiness['permalinks']
					? __( 'Sign-in with OAuth needs your site to be served over HTTPS — an access token sent over plain HTTP can be read in transit.', 'saddle' )
					: __( 'Sign-in with OAuth needs pretty permalinks. Go to Settings → Permalinks and choose any option other than Plain, then try again.', 'saddle' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $enabled && Saddle_OAuth::is_enabled() ) {
			Saddle_OAuth_Store::purge();
		}

		Saddle_OAuth::set_enabled( $enabled );

		return true;
	}
}
