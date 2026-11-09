<?php
/**
 * Lets Saddle's add-ons find Saddle when its folder has another name.
 *
 * Saddle Pro, Saddle Analytics and Saddle Rank say `Requires Plugins: saddle`,
 * and WordPress matches that slug against plugin folder names only. A zip
 * that was unzipped and zipped again on the way (a Mac names a second copy
 * "saddle-2") installs Saddle as `saddle-2`. Saddle runs and WordPress.org
 * still updates it, but WordPress refuses to activate every add-on, saying a
 * required plugin is missing (saddle#405). While Saddle is active, this
 * points the `saddle` requirement at the folder Saddle really runs from.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers other plugins' `saddle` requirement for a renamed copy of Saddle.
 */
final class Saddle_Plugin_Folder {

	/**
	 * The slug the add-ons require: Saddle's WordPress.org slug.
	 */
	const SLUG = 'saddle';

	/**
	 * Hook the requirement filter. It only runs while Saddle is loaded, which
	 * is while it is active: exactly when the requirement is met.
	 */
	public static function register() {
		add_filter( 'wp_plugin_dependencies_slug', array( __CLASS__, 'dependency_slug' ) );
	}

	/**
	 * The folder this copy of Saddle runs from.
	 *
	 * @return string
	 */
	public static function folder() {
		return dirname( plugin_basename( SADDLE_FILE ) );
	}

	/**
	 * Filter callback for `wp_plugin_dependencies_slug`.
	 *
	 * @param mixed $slug A slug from another plugin's Requires Plugins header.
	 * @return mixed
	 */
	public static function dependency_slug( $slug ) {
		return self::slug_for( $slug, self::folder() );
	}

	/**
	 * The slug WordPress should look for, given the folder Saddle runs from.
	 *
	 * Only `saddle` changes. A folder WordPress cannot read as a slug (a
	 * space, capitals) is passed on as it is; WordPress then drops the
	 * requirement, which is right, because Saddle is active.
	 *
	 * @param mixed  $slug   The required slug.
	 * @param string $folder Saddle's folder, '.' when it sits loose in plugins/.
	 * @return mixed
	 */
	public static function slug_for( $slug, $folder ) {
		if ( self::SLUG !== $slug || '' === $folder || '.' === $folder ) {
			return $slug;
		}
		return $folder;
	}
}
