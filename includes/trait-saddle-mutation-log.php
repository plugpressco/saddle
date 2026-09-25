<?php
/**
 * Shared mutation-logging for the integration and builder ability classes.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records an executed mutation to Saddle's activity log. A class that
 * mutates design-system state (colors, variables, presets) also invalidates the
 * cached context bundle here — the one chokepoint every such write passes
 * through — by declaring `const FLUSH_BUNDLE_ON_LOG = true;`.
 */
trait Saddle_Mutation_Log {

	/**
	 * Log an executed mutation (and, for design-system writers, flush the
	 * cached context bundle).
	 *
	 * @param string     $action  Ability id without the namespace, e.g. 'yoast-edit-post-seo'.
	 * @param string|int $target  The affected object (post id, color id, …).
	 * @param string     $summary Human-readable summary for the activity log.
	 */
	protected static function log( $action, $target, $summary ) {
		if ( class_exists( 'Saddle_Log' ) ) {
			Saddle_Log::record(
				array(
					'action'  => $action,
					'target'  => (string) $target,
					'summary' => $summary,
				)
			);
		}

		if ( defined( static::class . '::FLUSH_BUNDLE_ON_LOG' )
			&& static::FLUSH_BUNDLE_ON_LOG
			&& class_exists( 'Saddle_Divi_Bundle' ) ) {
			Saddle_Divi_Bundle::flush();
		}
	}
}
