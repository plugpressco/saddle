<?php
/**
 * Saddle's keys go with Saddle (#319).
 *
 * Found in the 1.5.0 release QA: while Saddle runs, scope_credentials()
 * confines the keys it issued to the MCP route. Once Saddle was deactivated
 * nothing did, and a Read only app's key read the admin's email, listed
 * plugins and created a post through wp/v2. Deactivation and uninstall now
 * delete the keys Saddle issued and leave the owner's own keys alone.
 *
 * @package Saddle
 */

class Saddle_Revoke_Keys_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		Saddle::activate(); // Deactivation unschedules the token clean-up.
		parent::tear_down();
	}

	private function key( $user_id, $name, $mark_issued ) {
		$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );
		$this->assertNotWPError( $created );
		if ( $mark_issued ) {
			Saddle_Connection::mark_issued( $user_id, $created[1]['uuid'] );
		}
		return $created[1]['uuid'];
	}

	private function names( $user_id ) {
		return wp_list_pluck( WP_Application_Passwords::get_user_application_passwords( $user_id ), 'name' );
	}

	public function test_deactivating_saddle_deletes_the_keys_it_issued() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->key( $this->admin, Saddle_Connections::KEY_PREFIX . 'Claude Code', true );
		$this->key( $editor, Saddle_Connections::KEY_PREFIX . 'Cursor', true );
		// A key from before Saddle recorded what it issued: known by its name.
		$this->key( $this->admin, Saddle_Connections::KEY_PREFIX . 'Older key', false );
		// The owner's own keys are not Saddle's.
		$this->key( $this->admin, 'Deploy script', false );
		$this->key( $editor, 'Mobile app', false );

		Saddle::deactivate();

		$this->assertSame( array( 'Deploy script' ), array_values( $this->names( $this->admin ) ) );
		$this->assertSame( array( 'Mobile app' ), array_values( $this->names( $editor ) ) );
		$this->assertSame( '', get_user_meta( $this->admin, Saddle_Connection::ISSUED_META, true ) );
	}

	public function test_a_revoked_key_keeps_no_role() {
		$uuid = $this->key( $this->admin, Saddle_Connections::KEY_PREFIX . 'Claude Code', true );
		Saddle_Access::set_role( 'key:' . $uuid, 'write' );

		$this->assertSame( 1, Saddle_Connection::revoke_issued_keys() );
		$this->assertSame( 'read', Saddle_Access::role_for( 'key:' . $uuid ) );
	}

	public function test_nothing_to_revoke_is_not_an_error() {
		$this->key( $this->admin, 'Deploy script', false );

		$this->assertSame( 0, Saddle_Connection::revoke_issued_keys() );
		$this->assertSame( array( 'Deploy script' ), array_values( $this->names( $this->admin ) ) );
	}
}
