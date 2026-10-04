<?php
/**
 * saddle/undo-changes: reverse logged changes, previewed and confirmed.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the undo ability. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_undo_abilities() {
	wp_register_ability(
		'saddle/undo-changes',
		array(
			'label'               => __( 'Undo changes', 'saddle' ),
			'description'         => __( 'Reverses changes from Saddle\'s activity log. Pass the "id" of one or more entries from saddle/recall-changes (its "undo" field says "available" when there is something to reverse). Puts back what the change replaced: a post or page\'s earlier content, title, status, fields and categories; untrashes what it trashed; trashes what it created; and restores settings, the active theme and plugin activation. Entries are undone newest first and whole. An entry is skipped, with the reason, if what it changed has been edited again since, if it permanently deleted something, or if it predates undo. Plugin and theme updates cannot be undone. Two steps: the first call returns a preview of every entry and a confirm_token; call again with the same entries plus that token to undo. The undo is logged like any change and can itself be undone.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'entries' ),
				'properties' => array(
					'entries'       => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems'    => 1,
						'maxItems'    => Saddle_Undo::MAX_ENTRIES,
						'description' => __( 'Activity-log entry ids from saddle/recall-changes, in any order (1–20).', 'saddle' ),
					),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'The single-use token returned by the preview call. Omit on the first call to receive a preview.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Undo_Abilities', 'undo_changes' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'undo-changes' ),
			'meta'                => saddle_ability_meta( false, true, false, 'write' ),
		)
	);
}

/**
 * Execute callback for saddle/undo-changes.
 */
class Saddle_Undo_Abilities {

	/**
	 * saddle/undo-changes. Nothing to reverse returns the reasons directly; any
	 * reversal goes through the approval gate, bound to the exact entry set.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function undo_changes( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$ids   = isset( $input['entries'] ) ? array_filter( array_map( 'absint', (array) $input['entries'] ) ) : array();
		$ids   = array_slice( array_values( array_unique( $ids ) ), 0, Saddle_Undo::MAX_ENTRIES );
		sort( $ids );
		if ( ! $ids ) {
			return new WP_Error( 'saddle_empty', __( 'Pass "entries": one or more ids from saddle/recall-changes.', 'saddle' ), array( 'status' => 400 ) );
		}

		$plan    = Saddle_Undo::plan( $ids );
		$unknown = array_values( array_diff( $ids, wp_list_pluck( $plan, 'id' ) ) );
		$ready   = count( wp_list_filter( $plan, array( 'status' => 'ready' ) ) );

		// A supplied token always goes through the gate, so a used or foreign
		// token is refused rather than answered with "nothing to do".
		if ( ! $ready && empty( $input['confirm_token'] ) ) {
			return array(
				'undone'  => 0,
				'entries' => $plan,
				'unknown' => $unknown,
				'note'    => __( 'Nothing to undo: every entry was skipped (reasons above) or is not an activity-log entry this account can see.', 'saddle' ),
			);
		}

		return Saddle_Approval::gate(
			array(
				'action'  => 'undo-changes',
				// The target is the entry set, so a token previewed for these
				// entries can't confirm a different set. It is also what lets
				// undoing this undo clear the entries' "undone" marks.
				'target'  => implode( ',', $ids ),
				'summary' => sprintf(
					/* translators: %d: number of log entries. */
					_n( 'Undo %d logged change.', 'Undo %d logged changes.', $ready, 'saddle' ),
					$ready
				),
				'done'    => static function ( $result ) {
					$undone = is_array( $result ) && isset( $result['undone'] ) ? (int) $result['undone'] : 0;
					if ( ! $undone ) {
						return __( 'Undo ran, but nothing could be reversed. Each entry was skipped.', 'saddle' );
					}
					return sprintf(
						/* translators: %d: number of log entries. */
						_n( 'Undid %d logged change.', 'Undid %d logged changes.', $undone, 'saddle' ),
						$undone
					);
				},
				'preview' => array(
					'entries' => $plan,
					'unknown' => $unknown,
				),
				'input'   => $input,
				'execute' => static function () use ( $ids, $unknown ) {
					$done = Saddle_Undo::apply( $ids );
					return array(
						'undone'  => count( wp_list_filter( $done, array( 'status' => 'undone' ) ) ),
						'entries' => $done,
						'unknown' => $unknown,
					);
				},
			)
		);
	}
}
