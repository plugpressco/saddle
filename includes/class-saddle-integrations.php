<?php
/**
 * First-party integrations — PlugPress plugins' abilities, wrapped in
 * Saddle's safety model.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The free, first-party integration catalog, wired into the shared
 * {@see Saddle_Integration_Engine}. All wrap/executor safety logic lives in
 * the engine — this class supplies only the catalog, the filter names, and
 * the system-context shape.
 *
 * First-party PlugPress namespaces integrate FREE (decided 2026-07): the
 * catalog below is the free tier's list; Saddle Pro adds third-party
 * integrations through the same engine with its own catalog.
 */
class Saddle_Integrations {

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
						'prefix' => 'waggle/',
						'title'  => 'Waggle',
					),
				),
				/**
				 * Filter the free Saddle integration catalog (first-party
				 * PlugPress namespaces). Documented here; applied inside the
				 * engine on every read.
				 *
				 * @param array $integrations slug => { prefix, title, force_destructive? }.
				 */
				'saddle_integrations',
				/**
				 * Filter whether one free integration is enabled.
				 *
				 * @param bool   $enabled Default true.
				 * @param string $slug    Integration slug.
				 */
				'saddle_integration_enabled'
			);
		}
		return self::$engine;
	}

	/**
	 * The free, first-party integration catalog: slug => definition.
	 *
	 * @return array<string,array{prefix:string,title:string}>
	 */
	public static function integrations() {
		return self::engine()->integrations();
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
		$lines = array();
		foreach ( self::engine()->active_counts() as $slug => $active ) {
			$lines[] = sprintf(
				'- %1$s is installed: use the saddle/%2$s-* tools (%3$d available) for its features instead of improvising with generic tools.',
				$active['title'],
				$slug,
				$active['count']
			);
		}

		if ( ! $lines ) {
			return $context;
		}

		return rtrim( (string) $context ) . "\n\nFirst-party integrations:\n" . implode( "\n", $lines ) . "\n";
	}
}
