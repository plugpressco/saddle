<?php
/**
 * Activity-log tests — mutations are recorded, reads never are.
 *
 * @package Saddle
 */

class Saddle_Log_Test extends WP_UnitTestCase {

	private function total() {
		return Saddle_Log::query( 100, 1 )['total'];
	}

	public function test_record_creates_a_queryable_entry() {
		$before = $this->total();
		Saddle_Log::record(
			array(
				'action'  => 'delete-post',
				'target'  => '7',
				'summary' => 'Deleted post #7',
			)
		);

		$result = Saddle_Log::query( 100, 1 );
		$this->assertSame( $before + 1, $result['total'] );

		$entry = $result['entries'][0];
		$this->assertSame( 'delete-post', $entry['action'] );
		$this->assertSame( '7', $entry['target'] );
		$this->assertSame( 'Deleted post #7', $entry['summary'] );
		$this->assertSame( 'executed', $entry['type'], 'A plain record defaults to the executed type.' );
	}

	/**
	 * Every connection shares the owner's WordPress user, so the activity log
	 * said "via admin" for changes Claude Code made. The entry now names the
	 * app, and keeps the name after the key is revoked.
	 */
	public function test_record_names_the_app_that_made_the_change() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $admin, array( 'name' => Saddle_REST_Admin::CLIENT_PREFIX . 'Claude Code' ) );
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid'];

		Saddle_Log::record_action( 'update-post', 1, 'Updated post #1' );
		WP_Application_Passwords::delete_application_password( $admin, $item['uuid'] );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		$entry = Saddle_Log::query( 1, 1 )['entries'][0];
		$this->assertSame( 'Claude Code', $entry['app'] );
	}

	public function test_a_change_from_the_owners_browser_names_no_app() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Saddle_Log::record_action( 'set-role', 'key:x', 'Set Claude Code to "Edit content"' );

		$this->assertSame( '', Saddle_Log::query( 1, 1 )['entries'][0]['app'] );
	}

	public function test_record_can_mark_an_entry_as_denied() {
		Saddle_Log::record(
			array(
				'action'  => 'denied-create-post',
				'summary' => 'Blocked: create-post',
				'type'    => 'denied',
			)
		);

		$this->assertSame( 'denied', Saddle_Log::query( 1, 1 )['entries'][0]['type'] );
	}

	public function test_record_ignores_empty_entries() {
		$before = $this->total();
		Saddle_Log::record( array( 'action' => '', 'summary' => '' ) );
		$this->assertSame( $before, $this->total(), 'An empty record must be a no-op.' );
	}

	public function test_gc_trims_to_the_bounded_maximum() {
		add_filter( 'saddle_log_max_entries', array( $this, 'cap_at_five' ) );

		for ( $i = 0; $i < 9; $i++ ) {
			Saddle_Log::record( array( 'action' => 'create-post', 'summary' => "entry {$i}" ) );
		}
		Saddle_Log::gc();

		remove_filter( 'saddle_log_max_entries', array( $this, 'cap_at_five' ) );
		$this->assertLessThanOrEqual( 5, $this->total(), 'GC must bound the log to the configured maximum.' );
	}

	public function cap_at_five() {
		return 5;
	}

	/**
	 * Denials and executed mutations are capped as separate buckets, so a
	 * flood of denial noise can never evict real change history.
	 */
	public function test_gc_caps_denials_and_mutations_independently() {
		add_filter( 'saddle_log_max_entries', array( $this, 'cap_at_five' ) );
		add_filter( 'saddle_log_max_denials', array( $this, 'cap_at_three' ) );

		for ( $i = 0; $i < 9; $i++ ) {
			Saddle_Log::record( array( 'action' => "mutation-{$i}", 'summary' => "mutation {$i}" ) );
			Saddle_Log::record( array( 'action' => "denied-{$i}", 'summary' => "denial {$i}", 'type' => 'denied' ) );
		}
		Saddle_Log::gc();

		remove_filter( 'saddle_log_max_entries', array( $this, 'cap_at_five' ) );
		remove_filter( 'saddle_log_max_denials', array( $this, 'cap_at_three' ) );

		$this->assertSame( 5, Saddle_Log::query( 100, 1, 'executed' )['total'], 'Executed history must keep its own cap.' );
		$this->assertSame( 3, Saddle_Log::query( 100, 1, 'denied' )['total'], 'Denials must be trimmed to their own, smaller cap.' );
	}

	public function cap_at_three() {
		return 3;
	}
}
