<?php
/**
 * Undo: reverse logged changes from their journals.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plans and applies the reversal of activity-log entries (#181), using the
 * journal Saddle_Journal kept for each one.
 *
 * Entries are undone newest first and whole: an entry either reverses every
 * change it recorded or none. Before touching anything, each object's current
 * state is compared with the state the entry left behind; if someone changed
 * it since, the entry is skipped with the reason, never overwritten. Within
 * one request the comparison chains, so undoing an edit and the edit before
 * it on the same page works as a set.
 *
 * The undo itself runs as a saddle/* ability, so it is journaled and logged
 * like any change, and can be undone in turn.
 */
class Saddle_Undo {

	/** Most entries one call may undo. */
	const MAX_ENTRIES = 20;

	/** Log-entry meta marking an entry as undone (a timestamp). */
	const UNDONE_META = '_saddle_undone';

	/**
	 * What undoing these entries would do, touching nothing.
	 *
	 * @param int[] $ids Log entry ids.
	 * @return array[] One report per entry, newest first.
	 */
	public static function plan( array $ids ) {
		return self::run( $ids, false );
	}

	/**
	 * Undo these entries. Stops at the first restore that fails.
	 *
	 * @param int[] $ids Log entry ids.
	 * @return array[] One report per entry, newest first.
	 */
	public static function apply( array $ids ) {
		return self::run( $ids, true );
	}

	/**
	 * Plan, and optionally apply, entry by entry.
	 *
	 * @param int[] $ids   Log entry ids.
	 * @param bool  $apply Whether to restore.
	 * @return array[]
	 */
	private static function run( array $ids, $apply ) {
		$entries = self::load( $ids );
		$virtual = array();
		$reports = array();
		$halted  = false;

		foreach ( $entries as $entry ) {
			$report = array(
				'id'      => $entry['id'],
				'action'  => $entry['action'],
				'summary' => $entry['summary'],
				'date'    => $entry['date'],
				'status'  => 'ready',
				'steps'   => array(),
				'reasons' => array(),
			);

			if ( $halted ) {
				$report['status']    = 'skipped';
				$report['reasons'][] = __( 'Not attempted: an earlier undo in this call failed.', 'saddle' );
				$reports[]           = $report;
				continue;
			}

			$report = self::check( $entry, $virtual, $report );
			if ( 'ready' === $report['status'] ) {
				foreach ( $entry['items'] as $item ) {
					$virtual[ Saddle_Journal::object_key( $item ) ] = $item['before'];
				}
				if ( $apply ) {
					$error = self::restore( $entry );
					if ( is_wp_error( $error ) ) {
						$report['status']    = 'failed';
						$report['reasons'][] = $error->get_error_message();
						$halted              = true;
					} else {
						$report['status'] = 'undone';
						self::mark( $entry );
					}
				}
			}
			$reports[] = $report;
		}
		return $reports;
	}

	/**
	 * Decide whether one entry can be undone, and describe it.
	 *
	 * @param array $entry   Loaded entry.
	 * @param array $virtual Object key → expected state after newer undos.
	 * @param array $report  Report to fill.
	 * @return array
	 */
	private static function check( array $entry, array $virtual, array $report ) {
		$reasons = array();

		if ( null === $entry['journal'] ) {
			$reasons[] = in_array( $entry['action'], array( 'update-plugin', 'update-theme' ), true )
				? __( 'Plugin and theme updates can’t be undone: WordPress removes its backup once an update succeeds.', 'saddle' )
				: __( 'Nothing was recorded to undo for this change: it predates undo, or it changed nothing Saddle can restore.', 'saddle' );
		} elseif ( ! empty( $entry['journal']['overflow'] ) ) {
			$reasons[] = sprintf(
				/* translators: %d: item limit. */
				__( 'This change touched more than %d things, too many to record for undo.', 'saddle' ),
				Saddle_Journal::MAX_ITEMS
			);
		}

		if ( ! $reasons ) {
			foreach ( $entry['items'] as $item ) {
				$key     = Saddle_Journal::object_key( $item );
				$current = array_key_exists( $key, $virtual ) ? $virtual[ $key ] : Saddle_Journal::state( $item );
				$problem = Saddle_Undo_Steps::problem( $item, $current, $entry['items'] );
				if ( '' !== $problem ) {
					$reasons[] = $problem;
					continue;
				}
				$step = Saddle_Undo_Steps::describe( $item );
				if ( '' !== $step ) {
					$report['steps'][] = $step;
				}
			}
		}

		if ( $reasons ) {
			if ( $entry['undone'] ) {
				array_unshift(
					$reasons,
					sprintf(
						/* translators: %s: date and time. */
						__( 'Already undone on %s.', 'saddle' ),
						wp_date( 'Y-m-d H:i', $entry['undone'] )
					)
				);
			}
			$report['status']  = 'skipped';
			$report['steps']   = array();
			$report['reasons'] = array_values( array_unique( $reasons ) );
		}
		return $report;
	}

	/**
	 * Restore every item of an entry, newest change first.
	 *
	 * @param array $entry Loaded entry.
	 * @return true|WP_Error
	 */
	private static function restore( array $entry ) {
		foreach ( array_reverse( $entry['items'] ) as $item ) {
			$done = Saddle_Undo_Steps::restore( $item );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		return true;
	}

	/**
	 * Mark an entry undone. Undoing an undo clears the marks it set, so the
	 * original entries read as live again.
	 *
	 * @param array $entry Loaded entry.
	 */
	private static function mark( array $entry ) {
		update_post_meta( $entry['id'], self::UNDONE_META, time() );
		if ( 'undo-changes' === $entry['action'] ) {
			foreach ( self::ids_from_target( $entry['target'] ) as $id ) {
				delete_post_meta( $id, self::UNDONE_META );
			}
		}
	}

	/**
	 * Load entries the current user may act on, newest first. Unknown ids,
	 * refusals and non-log posts are dropped here; the caller reports them.
	 *
	 * @param int[] $ids Log entry ids.
	 * @return array[]
	 */
	public static function load( array $ids ) {
		$entries = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$post   = get_post( $id );
			$target = (string) get_post_meta( $id, '_saddle_target', true );
			// The same row visibility recall-changes applies: an entry about a
			// post this account can't read is not disclosed here either.
			if ( ! $post || Saddle_Log::CPT !== $post->post_type || 'denied' === get_post_meta( $id, '_saddle_type', true )
				|| ! Saddle_Log::entry_is_visible( array( 'target' => $target ) ) ) {
				continue;
			}
			$journal   = json_decode( (string) get_post_meta( $id, Saddle_Journal::META, true ), true );
			$journal   = is_array( $journal ) && isset( $journal['items'] ) && is_array( $journal['items'] ) ? $journal : null;
			$entries[] = array(
				'id'      => $id,
				'date'    => $post->post_date_gmt,
				'action'  => (string) get_post_meta( $id, '_saddle_action', true ),
				'target'  => $target,
				'summary' => $post->post_title,
				'journal' => $journal,
				'items'   => $journal ? $journal['items'] : array(),
				'undone'  => (int) get_post_meta( $id, self::UNDONE_META, true ),
			);
		}
		usort(
			$entries,
			static function ( $a, $b ) {
				return array( $b['date'], $b['id'] ) <=> array( $a['date'], $a['id'] );
			}
		);
		return $entries;
	}

	/**
	 * Whether an entry has something recorded to undo, for recall-changes.
	 *
	 * @param int $id Log entry id.
	 * @return string 'available', 'undone' or 'not-recorded'.
	 */
	public static function availability( $id ) {
		if ( get_post_meta( $id, self::UNDONE_META, true ) ) {
			return 'undone';
		}
		return '' !== (string) get_post_meta( $id, Saddle_Journal::META, true ) ? 'available' : 'not-recorded';
	}

	/**
	 * The entry ids an undo-changes log entry acted on (its target).
	 *
	 * @param string $target Comma-separated ids.
	 * @return int[]
	 */
	public static function ids_from_target( $target ) {
		return array_values( array_filter( array_map( 'intval', explode( ',', (string) $target ) ) ) );
	}
}
