<?php
/**
 * Activity log — a record of every mutation an agent executed.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records executed write/delete actions to a private `saddle_log` CPT (the same
 * schema-free pattern as the approval token store). Reads are never logged —
 * only actions that changed something. Surfaced in the admin Activity view.
 */
class Saddle_Log {

	/**
	 * Private CPT used to persist log entries.
	 */
	const CPT = 'saddle_log';

	/**
	 * Register the log CPT. Hidden from every UI and export.
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'               => 'Saddle Activity',
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title', 'author' ),
			)
		);
	}

	/**
	 * Record a log entry. Silently no-ops on failure — logging must never break
	 * the action it is recording.
	 *
	 * @param array $args {
	 *     Log entry fields.
	 *
	 *     @type string $action  Short action key, e.g. 'create-post', 'delete-post'.
	 *     @type string $summary Human-readable one-line description.
	 *     @type string $target  Target identifier (e.g. post id). Optional.
	 *     @type string $type    'executed' (a mutation that happened) or 'denied'
	 *                           (an attempt that was refused). Default 'executed'.
	 * }
	 */
	public static function record( array $args ) {
		$summary = isset( $args['summary'] ) ? (string) $args['summary'] : '';
		$action  = isset( $args['action'] ) ? (string) $args['action'] : '';
		$target  = isset( $args['target'] ) ? (string) $args['target'] : '';
		$type    = ( isset( $args['type'] ) && 'denied' === $args['type'] ) ? 'denied' : 'executed';

		if ( '' === $summary && '' === $action ) {
			return;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $summary,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		update_post_meta( $post_id, '_saddle_action', $action );
		update_post_meta( $post_id, '_saddle_target', $target );
		update_post_meta( $post_id, '_saddle_type', $type );
	}

	/**
	 * Record a successful mutation from an ability, in the shape ability classes
	 * use. Thin convenience over record() so the four ability groups don't each
	 * repeat the same array-building wrapper. Reads never call this.
	 *
	 * @param string     $action  Short action key, e.g. 'create-post'.
	 * @param int|string $target  Target id.
	 * @param string     $summary Human-readable description.
	 */
	public static function record_action( $action, $target, $summary ) {
		self::record(
			array(
				'action'  => $action,
				'target'  => (string) $target,
				'summary' => $summary,
			)
		);
	}

	/**
	 * Recent log entries, newest first.
	 *
	 * @param int    $per_page Entries per page (1–100).
	 * @param int    $page     Page number.
	 * @param string $type     Optional filter: 'executed' | 'denied' | '' (all).
	 * @return array{entries:array[],total:int,total_pages:int,page:int}
	 */
	public static function query( $per_page = 20, $page = 1, $type = '' ) {
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$page     = max( 1, (int) $page );

		$args = array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Optional type filter. Entries predating the type meta are executed
		// mutations, so "executed" must also match rows with no meta at all.
		if ( 'denied' === $type ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded private CPT (GC'd at 1000 rows).
				array(
					'key'   => '_saddle_type',
					'value' => 'denied',
				),
			);
		} elseif ( 'executed' === $type ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded private CPT (GC'd at 1000 rows).
				'relation' => 'OR',
				array(
					'key'     => '_saddle_type',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_saddle_type',
					'value'   => 'denied',
					'compare' => '!=',
				),
			);
		}

		$q = new WP_Query( $args );

		$entries = array();
		foreach ( $q->posts as $post ) {
			$user      = $post->post_author ? get_userdata( $post->post_author ) : null;
			$type      = (string) get_post_meta( $post->ID, '_saddle_type', true );
			$entries[] = array(
				'date'    => $post->post_date_gmt,
				'action'  => (string) get_post_meta( $post->ID, '_saddle_action', true ),
				'target'  => (string) get_post_meta( $post->ID, '_saddle_target', true ),
				'summary' => $post->post_title,
				'user'    => $user ? $user->user_login : '',
				// Entries predating the type field are executed mutations.
				'type'    => ( 'denied' === $type ) ? 'denied' : 'executed',
			);
		}

		return array(
			'entries'     => $entries,
			'total'       => (int) $q->found_posts,
			'total_pages' => (int) $q->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Recent EXECUTED changes for agent context ("recent changes recall").
	 *
	 * Only executed mutations, never denials — blocked attempts are owner-facing
	 * noise, not orientation an agent needs. Recency-bounded so a dormant site
	 * serves nothing stale.
	 *
	 * Rows are filtered against the caller — see entry_is_visible(). Both
	 * consumers are read tier, so the filter belongs here rather than in either
	 * of them.
	 *
	 * @param int $limit Maximum entries (1–50).
	 * @param int $days  Recency window in days.
	 * @return array[] Entries: date, action, target, summary. Newest first.
	 */
	public static function recent_executed( $limit = 15, $days = 30 ) {
		$limit = max( 1, min( 50, (int) $limit ) );

		$q = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'date_query'     => array(
					array( 'after' => max( 1, (int) $days ) . ' days ago' ),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded private CPT (GC'd at 1000 rows).
					'relation' => 'OR',
					array(
						'key'     => '_saddle_type',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_saddle_type',
						'value'   => 'denied',
						'compare' => '!=',
					),
				),
				'no_found_rows'  => true,
			)
		);

		$entries = array();
		$targets = array();
		foreach ( $q->posts as $post ) {
			$target    = (string) get_post_meta( $post->ID, '_saddle_target', true );
			$entries[] = array(
				'date'    => $post->post_date_gmt,
				'action'  => (string) get_post_meta( $post->ID, '_saddle_action', true ),
				'target'  => $target,
				'summary' => $post->post_title,
			);
			if ( is_numeric( $target ) ) {
				$targets[ (int) $target ] = true;
			}
		}

		// Prime every post the log names in one query, so the per-row check
		// below is not a query in a loop. read_post resolves an attachment's
		// status through post_parent, so those get primed too.
		if ( $targets ) {
			$ids = array_keys( $targets );
			_prime_post_caches( $ids, false, false );
			$primed = array_filter( array_map( 'get_post', $ids ) );
			if ( $primed ) {
				update_post_parent_caches( $primed );
			}
		}

		return array_values( array_filter( $entries, array( __CLASS__, 'entry_is_visible' ) ) );
	}

	/**
	 * Whether one log row may be shown to the current user.
	 *
	 * `summary` carries the title of the thing that changed, so a row about a
	 * post discloses that post. Both consumers are read tier, and the read
	 * tier's capability is `read` — which every logged-in user holds, a
	 * Subscriber included. So each row is judged against its own object, the
	 * same way a listing is judged in Saddle_Abilities::collection().
	 *
	 * Two edges, both deliberate:
	 *
	 * - A row naming no post — a settings change, a plugin activation, a cache
	 *   flush — has no object to authorize against, and the action itself is
	 *   admin tier. It stays. Term ids are numeric and are judged as post ids;
	 *   the worst that costs is hiding a public taxonomy row from a
	 *   low-privilege connection, never disclosing anything.
	 * - A row whose post no longer exists is the record of a deletion, which is
	 *   the thing this log exists for. Dropping it would erase deletion history
	 *   from the owner's own record, so it survives for an account that could
	 *   have deleted content and is withheld from one that could not.
	 *
	 * @param array $entry One entry assembled by recent_executed().
	 * @return bool
	 */
	private static function entry_is_visible( array $entry ) {
		$target = isset( $entry['target'] ) ? $entry['target'] : '';
		if ( ! is_numeric( $target ) ) {
			return true;
		}

		$id = (int) $target;
		if ( ! get_post( $id ) ) {
			return current_user_can( 'delete_posts' );
		}

		return current_user_can( 'read_post', $id );
	}

	/**
	 * Trim the log to a bounded number of entries (keeps it from growing without
	 * limit). Wired to the same hourly cron as the approval-token GC.
	 *
	 * Denials and executed mutations are capped SEPARATELY: they share one
	 * CPT, and under a single cap a burst of denial noise (many tools × many
	 * reasons) could evict the security-relevant "what did the agent actually
	 * change" history.
	 */
	public static function gc() {
		/**
		 * Filter the maximum number of executed-mutation log entries to retain.
		 *
		 * @param int $max Maximum entries. Default 1000.
		 */
		self::trim( (int) apply_filters( 'saddle_log_max_entries', 1000 ), false );

		/**
		 * Filter the maximum number of denial log entries to retain.
		 *
		 * @param int $max Maximum denial entries. Default 300.
		 */
		self::trim( (int) apply_filters( 'saddle_log_max_denials', 300 ), true );
	}

	/**
	 * Delete one bucket's entries beyond its cap, oldest first.
	 *
	 * @param int  $max    Entries to retain; below 1 the bucket is left alone.
	 * @param bool $denied True to trim denial entries, false for executed ones.
	 */
	private static function trim( $max, $denied ) {
		if ( $max < 1 ) {
			return;
		}

		$q = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded GC batch trimming a private log CPT to its cap.
				'offset'         => $max,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $denied // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded private CPT; the type filter is the point of the split caps.
					? array(
						array(
							'key'   => '_saddle_type',
							'value' => 'denied',
						),
					)
					: array(
						'relation' => 'OR',
						array(
							'key'     => '_saddle_type',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_saddle_type',
							'value'   => 'denied',
							'compare' => '!=',
						),
					),
			)
		);

		foreach ( $q->posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}
