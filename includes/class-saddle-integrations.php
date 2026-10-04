<?php
/**
 * Integrations — other plugins' abilities, wrapped in Saddle's safety model.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free Saddle's integration catalog, wired into the shared
 * {@see Saddle_Integration_Engine}. All wrap/executor safety logic lives in
 * the engine — this class supplies the catalog, the filter names, the owner's
 * approval, and the system-context shape.
 *
 * The catalog is an open API: any plugin enrols through the
 * `saddle_integrations` filter (see docs/integrating.md). PlugPress's own
 * plugins, listed in {@see self::FIRST_PARTY}, are live as soon as they are
 * active. Every other plugin's tools stay off until the site owner switches
 * them on under Services — Saddle can't vouch for a stranger's
 * annotations, and even a read tool can expose data the owner didn't expect
 * an agent to see (non-negotiable #2).
 */
class Saddle_Integrations {

	/**
	 * Slugs Saddle trusts without the owner's approval. Decided here, never by
	 * the catalog entry, so a third-party plugin cannot label itself PlugPress.
	 *
	 * `rank` is Saddle Rank, Waggle renamed in its 2.0 (it enrols itself as
	 * saddle-rank/*). `waggle` stays for the sites still running Waggle.
	 *
	 * @var string[]
	 */
	const FIRST_PARTY = array( 'waggle', 'mailyard', 'analytics', 'rank', 'crm' );

	/**
	 * Option holding the third-party slugs the owner has switched on.
	 *
	 * @var string
	 */
	const APPROVED_OPTION = 'saddle_enabled_integrations';

	/**
	 * The plugins Saddle edits natively: tool prefix => { name, class whose
	 * is_active() says whether the plugin is here }. Their tools register on
	 * every site, and each refuses by name when its plugin is missing, but
	 * they are offered only where the plugin is active (D3). Each prefix is a
	 * reserved slug, so no enrolled plugin can claim it.
	 *
	 * @var array<string,string[]>
	 */
	const NATIVE_PLUGINS = array(
		'divi'      => array( 'Divi 5', 'Saddle_Divi' ),
		'yoast'     => array( 'Yoast SEO', 'Saddle_Yoast' ),
		'rank-math' => array( 'Rank Math', 'Saddle_Rank_Math' ),
		'aioseo'    => array( 'AIOSEO', 'Saddle_Aioseo' ),
		'wc'        => array( 'WooCommerce', 'Saddle_WC' ),
	);

	/**
	 * The shared engine, configured for the free catalog.
	 *
	 * @var Saddle_Integration_Engine|null
	 */
	private static $engine = null;

	/**
	 * The engine instance (lazily built so filters registered late still see
	 * a fresh catalog read on every call).
	 *
	 * @return Saddle_Integration_Engine
	 */
	private static function engine() {
		if ( null === self::$engine ) {
			self::$engine = new Saddle_Integration_Engine(
				array(
					'waggle' => array(
						'prefix'      => 'waggle/',
						'title'       => 'Waggle',
						'description' => __( 'SEO and AEO tools from the Waggle plugin.', 'saddle' ),
					),
				),
				/**
				 * Filter the Saddle integration catalog. The public API for any
				 * plugin that wants its abilities offered through Saddle.
				 * Documented here; applied inside the engine on every read.
				 *
				 * @param array $integrations slug => { prefix, title, description?, author?, url?, force_destructive? }.
				 */
				'saddle_integrations',
				/**
				 * Filter whether one integration is enabled. Can only switch an
				 * integration off: third-party ones also need the owner's approval.
				 *
				 * @param bool   $enabled Default true.
				 * @param string $slug    Integration slug.
				 */
				'saddle_integration_enabled',
				array( __CLASS__, 'is_approved' )
			);
		}
		return self::$engine;
	}

	/**
	 * The integration catalog: slug => definition, invalid entries removed.
	 *
	 * @return array<string,array{prefix:string,title:string}>
	 */
	public static function integrations() {
		return self::engine()->integrations();
	}

	/**
	 * Whether a slug is one of PlugPress's own plugins.
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public static function is_first_party( $slug ) {
		return in_array( (string) $slug, self::FIRST_PARTY, true );
	}

	/**
	 * Whether an integration's tools may register: always for first-party,
	 * otherwise only once the owner has switched it on.
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public static function is_approved( $slug ) {
		return self::is_first_party( $slug ) || in_array( (string) $slug, self::approved(), true );
	}

	/**
	 * The third-party slugs the owner has switched on.
	 *
	 * @return string[]
	 */
	private static function approved() {
		return array_values( array_filter( array_map( 'strval', (array) get_option( self::APPROVED_OPTION, array() ) ) ) );
	}

	/**
	 * Switch one third-party integration on or off.
	 *
	 * Only a slug that is enrolled and installed right now can be switched on;
	 * switching off always works, so an owner can clear an approval for a
	 * plugin that has since been removed.
	 *
	 * @param string $slug Integration slug.
	 * @param bool   $on   Whether to approve it.
	 * @return true|WP_Error
	 */
	public static function set_approved( $slug, $on ) {
		$slug = sanitize_key( (string) $slug );

		if ( self::is_first_party( $slug ) ) {
			return new WP_Error(
				'saddle_integration_first_party',
				__( 'PlugPress integrations are always on. Turn individual tools off under Saddle → Settings → Advanced.', 'saddle' ),
				array( 'status' => 400 )
			);
		}

		$approved = self::approved();

		if ( $on ) {
			$available = self::engine()->available_counts();
			if ( empty( $available[ $slug ] ) ) {
				return new WP_Error(
					'saddle_integration_unknown',
					__( 'No installed plugin offers that integration.', 'saddle' ),
					array( 'status' => 404 )
				);
			}
			$approved[] = $slug;
		} else {
			$approved = array_diff( $approved, array( $slug ) );
		}

		$approved = array_values( array_unique( $approved ) );
		sort( $approved );
		update_option( self::APPROVED_OPTION, $approved );

		return true;
	}

	/**
	 * Every installed integration, for Saddle → Services.
	 *
	 * `tools` counts the partner's own abilities, so an integration that is
	 * still off shows what switching it on would add.
	 *
	 * @return array[]
	 */
	public static function listing() {
		$available = self::engine()->available_counts();
		$rows      = array();

		foreach ( self::integrations() as $slug => $def ) {
			if ( empty( $available[ $slug ] ) ) {
				continue;
			}
			$rows[] = array(
				'slug'        => $slug,
				'title'       => $def['title'],
				'description' => $def['description'],
				'author'      => $def['author'],
				'url'         => $def['url'],
				'source'      => self::is_first_party( $slug ) ? 'plugpress' : 'third-party',
				'enabled'     => self::is_approved( $slug ),
				'tools'       => (int) $available[ $slug ],
			);
		}

		return $rows;
	}

	/**
	 * The name of the plugin a native tool needs when that plugin is not
	 * active here, or '' (the plugin is active, or the tool is not one of
	 * the native integrations'). Feeds Saddle_Services::has_tools_available(),
	 * so it runs once per tool on every tools/list: only a matching prefix
	 * pays for the plugin check.
	 *
	 * @param string $ability_name Full ability id or its short name.
	 * @return string
	 */
	public static function absent_plugin( $ability_name ) {
		$short = 0 === strpos( (string) $ability_name, 'saddle/' ) ? substr( (string) $ability_name, 7 ) : (string) $ability_name;

		foreach ( self::NATIVE_PLUGINS as $prefix => $plugin ) {
			if ( 0 === strpos( $short, $prefix . '-' ) ) {
				return self::native_is_active( $plugin[1] ) ? '' : $plugin[0];
			}
		}

		return '';
	}

	/**
	 * The native plugins that are not active here, by name.
	 *
	 * @return string[]
	 */
	public static function absent_plugins() {
		$names = array();
		foreach ( self::NATIVE_PLUGINS as $plugin ) {
			if ( ! self::native_is_active( $plugin[1] ) ) {
				$names[] = $plugin[0];
			}
		}

		return $names;
	}

	/**
	 * Whether a native plugin is active, asked of its own detection class.
	 *
	 * @param string $detector Class with a static is_active().
	 * @return bool
	 */
	private static function native_is_active( $detector ) {
		return is_callable( array( $detector, 'is_active' ) ) && (bool) call_user_func( array( $detector, 'is_active' ) );
	}

	/**
	 * Say in the context which plugins' tools are hidden because the plugin
	 * is not here. tools/list leaves them out (D3), and an agent asked for
	 * one should say why instead of improvising. Added by context_section(),
	 * which is what runs on `saddle_context_sections`.
	 *
	 * @param array[] $sections Sections so far.
	 * @return array[]
	 */
	private static function absent_plugins_section( $sections ) {
		$absent = self::absent_plugins();
		if ( ! $absent ) {
			return $sections;
		}

		$sections[] = array(
			'id'       => 'absent-plugins',
			'title'    => __( 'Plugins not on this site', 'saddle' ),
			'lines'    => array(
				'- ' . sprintf(
					/* translators: %s: a list of plugin names, such as "Divi 5, Yoast SEO and WooCommerce". */
					_n(
						'Saddle has tools for %s, but that plugin is not active here, so its tools are not offered. If a request needs it, say it is not active on this site.',
						'Saddle has tools for %s, but those plugins are not active here, so their tools are not offered. If a request needs one, say that plugin is not active on this site.',
						count( $absent ),
						'saddle'
					),
					wp_sprintf_l( '%l', $absent )
				),
			),
			'priority' => 41,
		);

		return $sections;
	}

	/**
	 * Register wrapper abilities for every discovered source ability of every
	 * enabled integration. Hooked to `wp_abilities_api_init` at priority 30 —
	 * after source plugins (10) register their own abilities.
	 */
	public static function register_wrappers() {
		self::engine()->register_wrappers();
	}

	/**
	 * Tell agents the integration exists: one line per active integration on
	 * the system context (the `saddle_system_context` filter), e.g.
	 * "Waggle is installed: use the saddle/waggle-* tools…".
	 *
	 * @param string $context System context so far.
	 * @return string
	 */
	public static function append_context( $context ) {
		return $context;
	}

	/**
	 * Tell agents which plugins' tools are available, and which are installed
	 * but switched off, so an agent asked for one can say why it isn't there
	 * instead of improvising.
	 *
	 * Contributed as a section rather than appended as a string: this used to
	 * emit a bare "First-party integrations:" line with no heading, in a
	 * document where everything else is a `#` heading. Runs on
	 * `saddle_context_sections`, and adds the section naming the native
	 * plugins that are not here, whose tools tools/list leaves out.
	 *
	 * @param array[] $sections Sections so far.
	 * @return array[]
	 */
	public static function context_section( $sections ) {
		$lines = array();
		foreach ( self::engine()->active_counts() as $slug => $active ) {
			$lines[] = sprintf(
				/* translators: 1: plugin name, 2: tool-name prefix, 3: number of tools. */
				__( '- %1$s is installed: use the saddle/%2$s-* tools (%3$d available) for its features instead of improvising with generic tools.', 'saddle' ),
				$active['title'],
				$slug,
				$active['count']
			);
		}

		foreach ( self::listing() as $row ) {
			if ( $row['enabled'] ) {
				continue;
			}
			$lines[] = sprintf(
				/* translators: %s: plugin name. */
				__( '- %s is installed, but the site owner has not switched on its tools. If they are needed, tell the user they can turn it on under Saddle → Services.', 'saddle' ),
				$row['title']
			);
		}

		if ( $lines ) {
			$sections[] = array(
				'id'       => 'first-party-integrations',
				'title'    => __( 'Plugins connected to Saddle', 'saddle' ),
				'lines'    => $lines,
				'priority' => 40,
			);
		}

		return self::absent_plugins_section( $sections );
	}
}
