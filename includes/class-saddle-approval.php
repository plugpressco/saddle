<?php
/**
 * Two-step confirmation gate for destructive abilities.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The approval gate.
 *
 * Any destructive ability routes its mutation through {@see self::gate()}. The
 * first call (no token) performs a dry run: it returns a human-readable preview
 * and issues a single-use confirm token, mutating nothing. A second call that
 * echoes that token back executes the mutation exactly once. Tokens are
 * single-use, action-bound, and expire after 15 minutes.
 *
 * Tokens are stored as posts of the private `saddle_approval` CPT — the title
 * holds the token, post meta holds the bound action and the expiry timestamp.
 * Reusing the existing CPT pattern keeps Saddle schema-migration-free.
 */
class Saddle_Approval {

	/**
	 * Private CPT used to persist pending confirmation tokens.
	 */
	const CPT = 'saddle_approval';

	/**
	 * Token lifetime in seconds (15 minutes).
	 */
	const TOKEN_TTL = 900;

	/**
	 * Cron hook for expired-token garbage collection.
	 */
	const GC_HOOK = 'saddle_gc_tokens';

	/**
	 * Register the token-storage CPT. Hidden from every UI and export.
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'               => 'Saddle Approvals',
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * Gate a destructive action behind a preview + confirm-token handshake.
	 *
	 * @param array $args {
	 *     Gate arguments.
	 *
	 *     @type string   $action  Stable action identifier, e.g. 'delete_post'.
	 *                             The token is bound to this — a token issued for
	 *                             one action cannot confirm another.
	 *     @type string   $target  Item the action affects (e.g. post id). The
	 *                             token is bound to this too — a token previewed
	 *                             for one item cannot be replayed against another.
	 *     @type string   $bind    Optional. Any confirmation-relevant parameter
	 *                             that changes what the confirmed action does —
	 *                             most importantly recoverability (trash vs.
	 *                             permanent delete). Folded into the token
	 *                             identity so a preview shown for a reversible
	 *                             action can't be confirmed into an irreversible
	 *                             one. Keep it out of $action/$target so logging
	 *                             stays clean.
	 *     @type string   $summary One-line plain-language description of the
	 *                             effect, shown in the preview.
	 *     @type array    $preview Structured detail of what will change.
	 *     @type array    $input   The ability's input (read for `confirm_token`).
	 *     @type callable $execute Zero-arg callable that performs the mutation
	 *                             and returns the result (or WP_Error).
	 * }
	 * @return array|WP_Error Preview array (dry run), the execute() result
	 *                        (confirmed), or WP_Error on an invalid token.
	 */
	public static function gate( array $args ) {
		$action  = isset( $args['action'] ) ? (string) $args['action'] : '';
		$target  = isset( $args['target'] ) ? (string) $args['target'] : '';
		$bind    = isset( $args['bind'] ) ? (string) $args['bind'] : '';
		$input   = ( isset( $args['input'] ) && is_array( $args['input'] ) ) ? $args['input'] : array();
		$execute = isset( $args['execute'] ) ? $args['execute'] : null;

		if ( '' === $action || ! is_callable( $execute ) ) {
			return new WP_Error( 'saddle_gate_misconfigured', __( 'Internal error: approval gate was called without an action or executor.', 'saddle' ) );
		}

		$token = ( isset( $input['confirm_token'] ) && is_string( $input['confirm_token'] ) ) ? trim( $input['confirm_token'] ) : '';

		// Confirmation path: validate, consume, execute. The token is bound to
		// both the action AND the specific target, so a token previewed for one
		// item cannot be replayed to act on a different item.
		if ( '' !== $token ) {
			$ok = self::consume_token( $token, $action, $target, $bind );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$result = call_user_func( $execute );

			// Log the executed destructive action (not the preview). A WP_Error
			// result is logged too — the executor may have partially mutated
			// before failing, and a confirmed destructive call with no audit
			// trail is worse than a noisy one.
			if ( class_exists( 'Saddle_Log' ) ) {
				$summary = isset( $args['summary'] ) ? (string) $args['summary'] : '';
				if ( is_wp_error( $result ) ) {
					$summary = sprintf(
						/* translators: 1: original summary, 2: error message. */
						__( '%1$s — FAILED after confirmation: %2$s', 'saddle' ),
						$summary,
						$result->get_error_message()
					);
				}
				Saddle_Log::record(
					array(
						'action'  => $action,
						'target'  => $target,
						'summary' => $summary,
					)
				);
			}

			return $result;
		}

		// Dry-run path: issue a token, return a preview, mutate nothing.
		$new_token = self::issue_token( $action, $target, $bind );
		if ( is_wp_error( $new_token ) ) {
			return $new_token;
		}

		return array(
			'requires_confirmation' => true,
			'confirm_token'         => $new_token,
			'expires_in_seconds'    => self::TOKEN_TTL,
			'action'                => $action,
			'summary'               => isset( $args['summary'] ) ? (string) $args['summary'] : '',
			'preview'               => isset( $args['preview'] ) ? $args['preview'] : null,
			'instructions'          => __( 'This is a preview — nothing has changed. To proceed, call this tool again with the same arguments plus "confirm_token" set to the value above. The token is single-use and expires in 15 minutes.', 'saddle' ),
		);
	}

	/**
	 * Create and persist a single-use token bound to an action, a target, and
	 * the previewing user.
	 *
	 * @param string $action Action identifier.
	 * @param string $target Target identifier the token is bound to (e.g. post id).
	 * @param string $bind   Confirmation-relevant parameter bound to the token
	 *                       (e.g. permanent-vs-trash); '' when the action has none.
	 * @return string|WP_Error 32-char hex token, or WP_Error on failure.
	 */
	private static function issue_token( $action, $target = '', $bind = '' ) {
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'saddle_token_generation_failed', __( 'Could not generate a secure confirmation token.', 'saddle' ) );
		}

		// Stored as 'publish' on a non-public, non-queryable CPT. Using 'private'
		// would make WP_Query apply read_private_posts capability filtering, so a
		// valid token issued to a write-tier author/contributor would look
		// invalid on confirmation. The CPT is hidden from every surface, so
		// 'publish' here is not publicly exposed.
		//
		// Only the SHA-256 hash of the token is persisted, never the token
		// itself: a DB-read path (a backup, an over-broad plugin query) then sees
		// an unusable digest, not a live confirmation token. The raw token is
		// returned to the caller and never stored.
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_title'  => self::hash_token( $token ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error( 'saddle_token_persist_failed', __( 'Could not persist the confirmation token.', 'saddle' ) );
		}

		update_post_meta( $post_id, '_saddle_action', $action );
		update_post_meta( $post_id, '_saddle_target', $target );
		update_post_meta( $post_id, '_saddle_bind', $bind );
		// The token belongs to whoever saw the preview: with several agents on
		// one site (separate app passwords / users), agent A's preview must
		// not be confirmable by agent B.
		update_post_meta( $post_id, '_saddle_user', get_current_user_id() );
		update_post_meta( $post_id, '_saddle_expires', time() + self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Deterministic digest a token is stored and looked up under. SHA-256 is
	 * fine here: the token is already 128 bits of CSPRNG output, so there is no
	 * low-entropy input to protect against — the hash exists only so the value
	 * at rest can't be replayed as a live token.
	 *
	 * @param string $token Raw token.
	 * @return string 64-char hex digest.
	 */
	private static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Validate and consume a token. Single-use: the token record is deleted on
	 * lookup regardless of outcome, so even a mismatched/expired token cannot be
	 * retried. The consumer must be the same user the preview was issued to.
	 *
	 * @param string $token  Candidate token.
	 * @param string $action Action the token must be bound to.
	 * @param string $target Target the token must be bound to (e.g. post id).
	 * @param string $bind   Confirmation-relevant parameter the token must be
	 *                       bound to (e.g. permanent-vs-trash); '' when none.
	 * @return true|WP_Error
	 */
	public static function consume_token( $token, $action, $target = '', $bind = '' ) {
		// WP_Query's `title` parameter is an exact match (since WP 4.4), which is
		// the security property we need — a partial match would let a prefix of a
		// valid token confirm an action. We match on the token's hash, since only
		// the hash is stored (see issue_token()).
		$query = new WP_Query(
			array(
				'post_type'              => self::CPT,
				'title'                  => self::hash_token( $token ),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $query->posts ) ) {
			return new WP_Error(
				'saddle_invalid_token',
				__( 'Invalid or already-used confirmation token. Request a new preview to get a fresh token.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		$post_id       = (int) $query->posts[0];
		$stored_action = get_post_meta( $post_id, '_saddle_action', true );
		$stored_target = (string) get_post_meta( $post_id, '_saddle_target', true );
		$stored_bind   = (string) get_post_meta( $post_id, '_saddle_bind', true );
		$stored_user   = (int) get_post_meta( $post_id, '_saddle_user', true );
		$expires       = (int) get_post_meta( $post_id, '_saddle_expires', true );

		// Single-use: burn the token now, before any further branching.
		wp_delete_post( $post_id, true );

		if ( get_current_user_id() !== $stored_user ) {
			return new WP_Error(
				'saddle_token_user_mismatch',
				__( 'This confirmation token was issued to a different user. Preview the action yourself, then confirm with the token it returns.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		if ( $stored_action !== $action ) {
			return new WP_Error(
				'saddle_token_mismatch',
				__( 'This confirmation token was issued for a different action and cannot be used here.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		if ( $stored_target !== (string) $target ) {
			return new WP_Error(
				'saddle_token_target_mismatch',
				__( 'This confirmation token was issued for a different item. Preview the exact item you intend to change, then confirm with the token it returns.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		// A token previewed for a reversible action (e.g. move to trash) must not
		// be replayed to confirm an irreversible one (permanent delete). The
		// recoverability-relevant parameter is part of the bound identity.
		if ( $stored_bind !== (string) $bind ) {
			return new WP_Error(
				'saddle_token_bind_mismatch',
				__( 'This confirmation token was issued for a less destructive version of this action (for example, moving to trash rather than permanently deleting). Preview the exact action you intend, then confirm with the token it returns.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		if ( time() > $expires ) {
			return new WP_Error(
				'saddle_token_expired',
				__( 'This confirmation token has expired. Request a new preview to get a fresh token.', 'saddle' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Delete expired tokens. Wired to an hourly cron event.
	 */
	public static function gc() {
		$query = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Hourly GC over a tiny private token CPT; the meta filter is the point of the sweep.
				'meta_query'     => array(
					array(
						'key'     => '_saddle_expires',
						'value'   => time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}
