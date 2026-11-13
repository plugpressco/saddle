<?php
/**
 * The post status Saddle's internal records are stored under.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One owner for how Saddle's five storage CPTs are saved.
 *
 * Approvals, the activity log, OAuth records, skills and memory are posts of
 * private post types. Until 1.6.3 they were saved as `publish`, and other
 * plugins listen for "a post was published" without asking whether the post
 * type is public: AIOSEO's IndexNow add-on sent `?post_type=saddle_oauth&p=N`
 * URLs to Bing (#386). A record now gets its own status, which no plugin
 * treats as published content.
 *
 * Why not `private`: WP_Query applies read_private_posts filtering to it, so a
 * record created for a lower-privilege user would look missing. This status is
 * neither public, protected nor private, so WP_Query applies no capability
 * filter when it is asked for by name.
 *
 * Reads ask for both statuses ({@see self::statuses()}): rows written before
 * the update stay `publish` until {@see self::migrate()} moves them, and the
 * OAuth bearer lookup can run before `init`, when the move has not happened
 * yet on that request.
 */
class Saddle_Records {

	/**
	 * The status every internal record is written with. Fits the 20-character
	 * `post_status` column.
	 */
	const STATUS = 'saddle_record';

	/**
	 * The status records were written with before 1.6.3.
	 */
	const LEGACY_STATUS = 'publish';

	/**
	 * Option holding the version of the last record migration that ran.
	 */
	const VERSION_OPTION = 'saddle_records_version';

	/**
	 * Current migration version.
	 */
	const VERSION = 1;

	/**
	 * Wire the status and the one-time move.
	 */
	public static function register() {
		// Before the CPTs (priority 10) and before anything that queries them.
		add_action( 'init', array( __CLASS__, 'register_status' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 15 );
	}

	/**
	 * The storage post types this status applies to.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		return array(
			Saddle_Approval::CPT,
			Saddle_Log::CPT,
			Saddle_OAuth_Store::CPT,
			Saddle_Skills::CPT,
			Saddle_Memory::CPT,
		);
	}

	/**
	 * Register the status. Hidden from every admin list; WP_Query only honours
	 * a status that is registered, so this runs early on `init`.
	 */
	public static function register_status() {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => 'SaddleWP record',
				'public'                    => false,
				'internal'                  => true,
				'protected'                 => false,
				'private'                   => false,
				// False on purpose: `post_status => 'any'` (uninstall's sweep)
				// skips statuses that are excluded from search.
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	/**
	 * The statuses a read asks for: the current one, then the legacy one.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( self::STATUS, self::LEGACY_STATUS );
	}

	/**
	 * Run the move once per version. One autoloaded option read when there is
	 * nothing to do.
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		self::migrate();
	}

	/**
	 * Move every record still saved as `publish` to the record status.
	 *
	 * One UPDATE over the five post types, so no plugin hook fires for it: a
	 * status change through wp_update_post() would itself look like a
	 * "post updated" event to the plugins this exists to avoid. Idempotent.
	 */
	public static function migrate() {
		global $wpdb;

		$types = self::post_types();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Once, on update, and it must not fire post hooks (see above). The post types are spread into the IN list's placeholders, which the sniff cannot count.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE post_status = %s AND post_type IN ( ' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ' )',
				$wpdb->posts,
				self::LEGACY_STATUS,
				...$types
			)
		);

		if ( $ids ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET post_status = %s WHERE post_status = %s AND post_type IN ( ' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ' )',
					$wpdb->posts,
					self::STATUS,
					self::LEGACY_STATUS,
					...$types
				)
			);
		}
		// phpcs:enable

		if ( $ids ) {
			// A persistent object cache would otherwise keep serving the old
			// status for these posts, and cached queries their old results.
			wp_cache_delete_multiple( array_map( 'intval', $ids ), 'posts' );
			wp_cache_set_posts_last_changed();
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}
}
