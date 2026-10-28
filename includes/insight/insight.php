<?php
/**
 * PlugPress Insight SDK. Load it from your plugin's main file:
 *
 *     require __DIR__ . '/insight/insight.php';
 *     plugpress_insight(
 *         array(
 *             'file'    => __FILE__,
 *             'slug'    => 'my-plugin',
 *             'name'    => 'My Plugin',
 *             'version' => '1.2.0',
 *             'page'    => 'my-plugin', // Optional: the ?page= that shows the card.
 *         )
 *     );
 *
 * Every PlugPress plugin on a site carries its own copy. Only the newest copy
 * runs, and one answer on the card covers all of them. README.md has the rest.
 *
 * @package PlugPress_Insight
 */

defined( 'ABSPATH' ) || exit;

// Keep the newest copy. It loads once every plugin has loaded.
if ( empty( $GLOBALS['plugpress_insight_sdk'] ) || version_compare( '1.0.0', $GLOBALS['plugpress_insight_sdk']['version'], '>' ) ) {
	$GLOBALS['plugpress_insight_sdk'] = array(
		'version' => '1.0.0',
		'dir'     => __DIR__,
	);
}

if ( ! function_exists( 'plugpress_insight' ) ) {
	/**
	 * Add a plugin to Insight. Call it from the plugin's main file.
	 *
	 * @param array $product file, slug, name, version; optionally page, news and color.
	 */
	function plugpress_insight( array $product ) {
		$GLOBALS['plugpress_insight_products'][] = $product;
	}

	/**
	 * Load the newest copy and start it with every plugin that called plugpress_insight().
	 */
	function plugpress_insight_load() {
		if ( empty( $GLOBALS['plugpress_insight_products'] ) || class_exists( 'PlugPress_Insight' ) ) {
			return;
		}
		require_once $GLOBALS['plugpress_insight_sdk']['dir'] . '/class-plugpress-insight.php';
		PlugPress_Insight::start( $GLOBALS['plugpress_insight_products'] );
	}

	add_action( 'plugins_loaded', 'plugpress_insight_load', 1 );
}
