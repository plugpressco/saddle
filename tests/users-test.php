<?php
/**
 * Read-only user ability tests.
 *
 * Drives the real wp_get_ability()->execute() path so the read tier, the
 * `list_users` capability gate, the personally-identifying-field split, and the
 * pagination/search shaping are all proven end to end.
 *
 * @package Saddle
 */

class Saddle_Users_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		// These abilities are read-tier; a fresh install is `read`, so this
		// mirrors the real default rather than escalating for the test.
		Saddle_Capabilities::set_tier( 'read' );
	}

	public function tear_down() {
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		parent::tear_down();
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	/* -------- registration -------- */

	public function test_user_abilities_are_registered() {
		$this->assertNotNull( wp_get_ability( 'saddle/list-users' ) );
		$this->assertNotNull( wp_get_ability( 'saddle/get-user' ) );
	}

	public function test_user_abilities_are_read_tier_and_readonly() {
		foreach ( array( 'saddle/list-users', 'saddle/get-user' ) as $name ) {
			$meta = $this->ability( $name )->get_meta();
			$this->assertSame( 'read', $meta['saddle']['tier'], "{$name} must be read tier." );
			$this->assertTrue( $meta['annotations']['readonly'], "{$name} must be readonly." );
			$this->assertFalse( $meta['annotations']['destructive'], "{$name} must not be destructive." );
		}
	}

	/* -------- capability gate -------- */

	public function test_admin_can_list_at_read_tier() {
		$result = $this->ability( 'saddle/list-users' )->execute( array() );
		$this->assertIsArray( $result, 'An admin at the read tier can list users.' );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
	}

	public function test_subscriber_without_list_users_is_denied() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertFalse( current_user_can( 'list_users' ), 'Precondition: a subscriber lacks list_users.' );

		$list = $this->ability( 'saddle/list-users' )->execute( array() );
		$this->assertWPError( $list, 'list-users must be denied without the list_users capability.' );

		$get = $this->ability( 'saddle/get-user' )->execute( array( 'id' => $this->admin ) );
		$this->assertWPError( $get, 'get-user must be denied without the list_users capability.' );
	}

	/* -------- PII split -------- */

	public function test_admin_sees_pii() {
		$target = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'author@example.test',
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			)
		);

		$result = $this->ability( 'saddle/get-user' )->execute( array( 'id' => $target ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'author@example.test', $result['email'] );
		$this->assertSame( 'Ada', $result['first_name'] );
		$this->assertSame( 'Lovelace', $result['last_name'] );
	}

	public function test_list_users_hides_pii_without_edit_users() {
		$target = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'secret@example.test',
			)
		);

		// A caller who can list_users but NOT edit_users: an editor with the
		// one cap added. The directory is visible; email is not.
		$viewer     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$viewer_obj = get_user_by( 'id', $viewer );
		$viewer_obj->add_cap( 'list_users' );
		wp_set_current_user( $viewer );

		$this->assertTrue( current_user_can( 'list_users' ) );
		$this->assertFalse( current_user_can( 'edit_users' ), 'Precondition: viewer cannot edit users.' );

		$result = $this->ability( 'saddle/list-users' )->execute( array( 'per_page' => 100 ) );
		$this->assertIsArray( $result );

		$row = null;
		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === (int) $target ) {
				$row = $item;
				break;
			}
		}
		$this->assertNotNull( $row, 'The target user must appear in the directory.' );
		$this->assertArrayHasKey( 'name', $row, 'Public fields are present.' );
		$this->assertArrayHasKey( 'roles', $row );
		$this->assertArrayNotHasKey( 'email', $row, 'Email must be hidden from a caller who cannot edit users.' );
		$this->assertArrayNotHasKey( 'login', $row );
		$this->assertArrayNotHasKey( 'first_name', $row );
	}

	/* -------- shaping: get / not found / role filter / pagination -------- */

	public function test_get_user_not_found() {
		$result = $this->ability( 'saddle/get-user' )->execute( array( 'id' => 999999 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_found', $result->get_error_code() );
	}

	public function test_get_user_rejects_non_positive_id() {
		// The input schema (id: required, minimum 1) guards this before the
		// callback, so a zero id is refused as invalid input; the callback's
		// own saddle_missing_id check is defense-in-depth behind that schema.
		$result = $this->ability( 'saddle/get-user' )->execute( array( 'id' => 0 ) );
		$this->assertWPError( $result, 'A non-positive id must be refused.' );
	}

	public function test_role_filter() {
		self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->user->create( array( 'role' => 'author' ) );

		$result = $this->ability( 'saddle/list-users' )->execute(
			array(
				'role'     => 'author',
				'per_page' => 100,
			)
		);

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $item ) {
			$this->assertContains( 'author', $item['roles'], 'The role filter must only return that role.' );
		}
	}

	public function test_pagination_envelope() {
		self::factory()->user->create_many( 5, array( 'role' => 'subscriber' ) );
		// The admin holds list_users; page through everyone in pages of 2.
		wp_set_current_user( $this->admin );

		$page1 = $this->ability( 'saddle/list-users' )->execute(
			array(
				'per_page' => 2,
				'page'     => 1,
			)
		);

		$this->assertCount( 2, $page1['items'], 'per_page bounds the page size.' );
		$this->assertSame( 1, $page1['page'] );
		$this->assertGreaterThanOrEqual( 6, $page1['total'], 'Total counts every user, not just the page.' );
		$this->assertSame( (int) ceil( $page1['total'] / 2 ), $page1['total_pages'] );
	}
}
