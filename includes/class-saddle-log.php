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
	 * Recent log entries, newest first.
	 *
	 * @param int $per_page Entries per page (1–100).
	 * @param int $page     Page number.
	 * @return array{entries:array[],total:int,total_pages:int,page:int}
	 */
	public static function query( $per_page = 20, $page = 1 ) {
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$page     = max( 1, (int) $page );

		$q = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$entries = array();
		foreach ( $q->posts as $post ) {
			$user = $post->post_author ? get_userdata( $post->post_author ) : null;
			$type = (string) get_post_meta( $post->ID, '_saddle_type', true );
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
	 * Trim the log to a bounded number of entries (keeps it from growing without
	 * limit). Wired to the same hourly cron as the approval-token GC.
	 */
	public static function gc() {
		/**
		 * Filter the maximum number of activity log entries to retain.
		 *
		 * @param int $max Maximum entries. Default 1000.
		 */
		$max = (int) apply_filters( 'saddle_log_max_entries', 1000 );
		if ( $max < 1 ) {
			return;
		}

		$q = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'offset'         => $max,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $q->posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}
