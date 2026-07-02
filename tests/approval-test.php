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
