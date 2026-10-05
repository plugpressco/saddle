<?php
/**
 * Approval-gate tests — the two-step confirm that is the whole product.
 *
 * Mirrors BUILD-GUIDE Step 3 and the destructive-action rows of the CLAUDE.md
 * testing checklist: preview mutates nothing, a valid token executes exactly
 * once, and reused / expired / mismatched tokens all fail cleanly.
 *
 * @package Saddle
 */

class Saddle_Approval_Test extends WP_UnitTestCase {

	/**
	 * Build a gate() arg array with a spy executor whose call count we assert on.
	 *
	 * @param int  $calls   By-ref call counter, incremented on each execution.
	 * @param array $overrides Overrides merged over the defaults.
	 * @return array
	 */
	private function gate_args( &$calls, array $overrides = array() ) {
		$calls = 0;
		$defaults = array(
			'action'  => 'delete_post',
			'target'  => '42',
			'summary' => 'Delete post #42',
			'preview' => array( 'id' => 42 ),
			'input'   => array(),
			'execute' => function () use ( &$calls ) {
				$calls++;
				return array( 'executed' => true );
			},
		);
		return array_merge( $defaults, $overrides );
	}

	/**
	 * Locate the stored token CPT record so a test can tamper with its meta.
	 * Tokens are persisted under their SHA-256 hash, never the raw value, so we
	 * look up by that same digest.
	 */
	private function token_post_id( $token ) {
		$ids = get_posts(
			array(
				'post_type'   => Saddle_Approval::CPT,
				'title'       => hash( 'sha256', $token ),
				'post_status' => 'publish',
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/** The raw token must never appear in storage — only its hash. */
	public function test_raw_token_is_not_stored_in_plaintext() {
		$token = Saddle_Approval::gate( $this->gate_args( $calls ) )['confirm_token'];

		$raw = get_posts(
			array(
				'post_type'   => Saddle_Approval::CPT,
				'title'       => $token,
				'post_status' => 'publish',
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);

		$this->assertEmpty( $raw, 'The raw token must not be findable as a stored title.' );
		$this->assertNotSame( 0, $this->token_post_id( $token ), 'But its hash must be.' );
	}

	/* -------- dry run -------- */

	public function test_dry_run_returns_preview_and_does_not_execute() {
		$args   = $this->gate_args( $calls );
		$result = Saddle_Approval::gate( $args );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['requires_confirmation'] );
		$this->assertNotEmpty( $result['confirm_token'] );
		$this->assertSame( 'delete_post', $result['action'] );
		$this->assertSame( 0, $calls, 'A preview must mutate nothing.' );
	}

	/**
	 * Every app reads the preview, whatever it does with the server
	 * instructions. When it only said "to proceed, call again with the token",
	 * Claude Code took that as permission and confirmed in the same turn (#333).
	 */
	public function test_the_preview_tells_the_agent_to_ask_before_confirming() {
		$result = Saddle_Approval::gate( $this->gate_args( $calls ) );

		$this->assertStringContainsString( 'ask whether to go ahead', $result['instructions'] );
		$this->assertStringContainsString( 'Only after they agree', $result['instructions'] );
	}

	public function test_dry_run_issues_a_persisted_single_use_token() {
		$result   = Saddle_Approval::gate( $this->gate_args( $calls ) );
		$token    = $result['confirm_token'];
		$this->assertNotSame( 0, $this->token_post_id( $token ), 'The token must be persisted.' );
	}

	/* -------- confirm -------- */

	public function test_valid_token_executes_exactly_once() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls ) );
		$token   = $preview['confirm_token'];

		$args   = $this->gate_args( $calls, array( 'input' => array( 'confirm_token' => $token ) ) );
		$result = Saddle_Approval::gate( $args );

		$this->assertSame( array( 'executed' => true ), $result );
		$this->assertSame( 1, $calls, 'A confirmed action must execute exactly once.' );
		$this->assertSame( 0, $this->token_post_id( $token ), 'The token must be burned after use.' );
	}

	public function test_reused_token_is_rejected_and_does_not_re_execute() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls ) );
		$token   = $preview['confirm_token'];

		// First confirm consumes the token.
		Saddle_Approval::gate( $this->gate_args( $calls, array( 'input' => array( 'confirm_token' => $token ) ) ) );

		// Second confirm with the same token must fail without executing.
		$args   = $this->gate_args( $calls, array( 'input' => array( 'confirm_token' => $token ) ) );
		$result = Saddle_Approval::gate( $args );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_invalid_token', $result->get_error_code() );
		$this->assertSame( 0, $calls, 'A reused token must never re-execute the action.' );
	}

	public function test_unknown_token_is_rejected() {
		$args   = $this->gate_args( $calls, array( 'input' => array( 'confirm_token' => 'deadbeef' . str_repeat( '0', 24 ) ) ) );
		$result = Saddle_Approval::gate( $args );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_invalid_token', $result->get_error_code() );
		$this->assertSame( 0, $calls );
	}

	public function test_expired_token_is_rejected() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls ) );
		$token   = $preview['confirm_token'];

		// Force the stored token into the past.
		update_post_meta( $this->token_post_id( $token ), '_saddle_expires', time() - 10 );

		$args   = $this->gate_args( $calls, array( 'input' => array( 'confirm_token' => $token ) ) );
		$result = Saddle_Approval::gate( $args );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_expired', $result->get_error_code() );
		$this->assertSame( 0, $calls );
	}

	public function test_token_bound_to_action_rejects_different_action() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls ) ); // action delete_post
		$token   = $preview['confirm_token'];

		$args   = $this->gate_args(
			$calls,
			array(
				'action' => 'delete_page',
				'input'  => array( 'confirm_token' => $token ),
			)
		);
		$result = Saddle_Approval::gate( $args );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_mismatch', $result->get_error_code() );
		$this->assertSame( 0, $calls );
	}

	public function test_token_bound_to_target_rejects_different_target() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls ) ); // target 42
		$token   = $preview['confirm_token'];

		$args   = $this->gate_args(
			$calls,
			array(
				'target' => '99',
				'input'  => array( 'confirm_token' => $token ),
			)
		);
		$result = Saddle_Approval::gate( $args );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_target_mismatch', $result->get_error_code() );
		$this->assertSame( 0, $calls );
	}

	/**
	 * A token previewed for a reversible action (trash) must not confirm the
	 * irreversible version (permanent delete). Regression: the token bound only
	 * action + target, so adding force=true on confirm silently escalated a
	 * "recoverable" preview into a permanent, unrecoverable deletion.
	 */
	public function test_token_bound_to_recoverability_rejects_more_destructive_confirm() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls, array( 'bind' => 'trash' ) ) );
		$token   = $preview['confirm_token'];

		$args   = $this->gate_args(
			$calls,
			array(
				'bind'  => 'permanent',
				'input' => array( 'confirm_token' => $token ),
			)
		);
		$result = Saddle_Approval::gate( $args );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_bind_mismatch', $result->get_error_code() );
		$this->assertSame( 0, $calls, 'A trash preview must never confirm a permanent delete.' );
	}

	/** A confirm whose recoverability matches the preview still executes. */
	public function test_matching_recoverability_confirms() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls, array( 'bind' => 'permanent' ) ) );
		$token   = $preview['confirm_token'];

		$args   = $this->gate_args(
			$calls,
			array(
				'bind'  => 'permanent',
				'input' => array( 'confirm_token' => $token ),
			)
		);
		$result = Saddle_Approval::gate( $args );

		$this->assertSame( array( 'executed' => true ), $result );
		$this->assertSame( 1, $calls, 'A matching-recoverability confirm must execute exactly once.' );
	}

	/** Even a failed lookup burns the record — a mismatched token can't be retried. */
	public function test_mismatched_token_is_still_single_use() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls ) );
		$token   = $preview['confirm_token'];

		Saddle_Approval::gate(
			$this->gate_args(
				$calls,
				array(
					'action' => 'delete_page',
					'input'  => array( 'confirm_token' => $token ),
				)
			)
		);

		$this->assertSame( 0, $this->token_post_id( $token ), 'A token must be burned even on a mismatch.' );
	}

	public function test_gate_rejects_misconfiguration() {
		$result = Saddle_Approval::gate( array( 'action' => '', 'execute' => null ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_gate_misconfigured', $result->get_error_code() );
	}

	/**
	 * With several agents on one site (separate users / app passwords), a
	 * token previewed by one user must never be confirmable by another.
	 */
	public function test_token_bound_to_user_rejects_a_different_user() {
		$issuer = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $issuer );
		$token = Saddle_Approval::gate( $this->gate_args( $calls ) )['confirm_token'];

		wp_set_current_user( $other );
		$result = Saddle_Approval::gate( $this->gate_args( $calls, array( 'input' => array( 'confirm_token' => $token ) ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_token_user_mismatch', $result->get_error_code() );
		$this->assertSame( 0, $calls, 'Another user must never confirm a token they did not preview.' );

		// The refusal does not spend the issuer's token.
		wp_set_current_user( $issuer );
		$own = Saddle_Approval::gate( $this->gate_args( $calls, array( 'input' => array( 'confirm_token' => $token ) ) ) );
		$this->assertSame( array( 'executed' => true ), $own );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Two confirms with one token race: both find the token, and only the one
	 * whose delete removes the row may run. Simulated by deleting the row from
	 * under this request just before its own delete, as a concurrent request
	 * that got there first would. Regression: the delete's result was ignored,
	 * so the losing request ran the action a second time.
	 */
	public function test_a_token_spent_by_a_concurrent_request_does_not_run_again() {
		$token   = Saddle_Approval::gate( $this->gate_args( $calls ) )['confirm_token'];
		$post_id = $this->token_post_id( $token );

		$winner = static function ( $id ) use ( $post_id ) {
			global $wpdb;
			if ( (int) $id === $post_id ) {
				$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test: the concurrent request's DELETE.
			}
		};
		add_action( 'delete_post', $winner );
		$result = Saddle_Approval::gate( $this->gate_args( $calls, array( 'input' => array( 'confirm_token' => $token ) ) ) );
		remove_action( 'delete_post', $winner );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_invalid_token', $result->get_error_code() );
		$this->assertSame( 0, $calls, 'The request that lost the race must not run the action.' );
	}

	/* -------- audit logging -------- */

	/**
	 * A confirmed destructive execution that returns WP_Error must still leave
	 * an audit entry — the executor may have partially mutated before failing.
	 */
	public function test_failed_confirmed_execution_is_still_logged() {
		$before = Saddle_Log::query( 100, 1 )['total'];

		$token  = Saddle_Approval::gate( $this->gate_args( $calls ) )['confirm_token'];
		$result = Saddle_Approval::gate(
			$this->gate_args(
				$calls,
				array(
					'input'   => array( 'confirm_token' => $token ),
					'execute' => static function () {
						return new WP_Error( 'saddle_test_partial', 'Exploded halfway.' );
					},
				)
			)
		);

		$this->assertWPError( $result );
		$log = Saddle_Log::query( 100, 1 );
		$this->assertSame( $before + 1, $log['total'], 'A failed confirmed destructive call must be logged.' );
		$this->assertStringContainsString( 'Failed after confirmation', $log['entries'][0]['summary'] );
		$this->assertStringContainsString( 'Exploded halfway.', $log['entries'][0]['summary'] );
	}

	/**
	 * A confirmed call logs what happened, in the past tense, rather than the
	 * preview's request ("Move post #5 to the trash"). Regression: the log
	 * replayed the preview sentence for every confirmed change.
	 */
	public function test_confirmed_call_logs_the_done_line_not_the_preview() {
		$args  = $this->gate_args( $calls, array( 'done' => 'Deleted post #42.' ) );
		$token = Saddle_Approval::gate( $args )['confirm_token'];

		$args['input'] = array( 'confirm_token' => $token );
		Saddle_Approval::gate( $args );

		$entry = Saddle_Log::query( 1, 1 )['entries'][0];
		$this->assertSame( 'delete_post', $entry['action'] );
		$this->assertSame( 'Deleted post #42.', $entry['summary'] );
	}

	/** A callable `done` reads the executor's result, for a result the preview could not know. */
	public function test_a_callable_done_line_receives_the_result() {
		$seen  = null;
		$args  = $this->gate_args(
			$calls,
			array(
				'done' => static function ( $result ) use ( &$seen ) {
					$seen = $result;
					return 'Nothing changed.';
				},
			)
		);
		$token = Saddle_Approval::gate( $args )['confirm_token'];

		$args['input'] = array( 'confirm_token' => $token );
		Saddle_Approval::gate( $args );

		$this->assertSame( array( 'executed' => true ), $seen );
		$this->assertSame( 'Nothing changed.', Saddle_Log::query( 1, 1 )['entries'][0]['summary'] );
	}

	/** Callers that predate `done` (Saddle Pro, the integrations) keep logging the summary. */
	public function test_without_done_the_log_keeps_the_summary() {
		$args  = $this->gate_args( $calls );
		$token = Saddle_Approval::gate( $args )['confirm_token'];

		$args['input'] = array( 'confirm_token' => $token );
		Saddle_Approval::gate( $args );

		$this->assertSame( 'Delete post #42', Saddle_Log::query( 1, 1 )['entries'][0]['summary'] );
	}

	/** A `done` string is a line of text, never a function name to call. */
	public function test_a_done_string_is_never_called() {
		$args  = $this->gate_args( $calls, array( 'done' => 'time' ) );
		$token = Saddle_Approval::gate( $args )['confirm_token'];

		$args['input'] = array( 'confirm_token' => $token );
		Saddle_Approval::gate( $args );

		$this->assertSame( 'time', Saddle_Log::query( 1, 1 )['entries'][0]['summary'] );
	}

	/** The preview still shows the request, not the past tense. */
	public function test_the_preview_keeps_the_summary() {
		$preview = Saddle_Approval::gate( $this->gate_args( $calls, array( 'done' => 'Deleted post #42.' ) ) );

		$this->assertSame( 'Delete post #42', $preview['summary'] );
		$this->assertArrayNotHasKey( 'done', $preview );
	}

	/** The real path: a confirmed trash logs "Moved post #N … to the trash." */
	public function test_confirmed_trash_through_the_tool_logs_what_happened() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		$id      = self::factory()->post->create( array( 'post_title' => 'Spring sale' ) );
		$ability = wp_get_ability( 'saddle/delete-post' );

		$preview = $ability->execute( array( 'id' => $id ) );
		$this->assertStringContainsString( 'Move post', $preview['summary'] );
		$ability->execute(
			array(
				'id'            => $id,
				'confirm_token' => $preview['confirm_token'],
			)
		);

		$entry = Saddle_Log::query( 1, 1 )['entries'][0];
		$this->assertSame( sprintf( 'Moved post #%d "Spring sale" to the trash.', $id ), $entry['summary'] );

		delete_option( Saddle_Capabilities::OPTION );
	}

	/* -------- garbage collection -------- */

	public function test_gc_removes_only_expired_tokens() {
		$fresh   = Saddle_Approval::gate( $this->gate_args( $calls, array( 'target' => '1' ) ) )['confirm_token'];
		$expired = Saddle_Approval::gate( $this->gate_args( $calls, array( 'target' => '2' ) ) )['confirm_token'];
		update_post_meta( $this->token_post_id( $expired ), '_saddle_expires', time() - 10 );

		Saddle_Approval::gc();

		$this->assertNotSame( 0, $this->token_post_id( $fresh ), 'A live token must survive GC.' );
		$this->assertSame( 0, $this->token_post_id( $expired ), 'An expired token must be collected.' );
	}
}
