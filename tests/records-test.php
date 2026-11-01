<?php
/**
 * Internal records are never "published" posts (#386).
 *
 * The symptom: AIOSEO's IndexNow add-on sent `?post_type=saddle_oauth&p=N`
 * URLs to Bing, because its check (`isValidPost()`) only asks whether a post's
 * status is `publish`. These tests pin that no store writes `publish`, that
 * rows written before the update keep working, and that the one-time move
 * leaves everyone else's posts alone.
 *
 * @package Saddle
 */

class Saddle_Records_Test extends WP_UnitTestCase {

	/**
	 * Statuses seen on Saddle's post types by `transition_post_status`.
	 *
	 * @var string[]
	 */
	private $seen = array();

	public function set_up() {
		parent::set_up();
		$this->seen = array();
		add_action( 'transition_post_status', array( $this, 'watch' ), 10, 3 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		remove_action( 'transition_post_status', array( $this, 'watch' ), 10 );
		delete_option( Saddle_Records::VERSION_OPTION );
		parent::tear_down();
	}

	/**
	 * Record each status a Saddle record moves into.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       The post.
	 */
	public function watch( $new_status, $old_status, $post ) {
		if ( in_array( $post->post_type, Saddle_Records::post_types(), true ) ) {
			$this->seen[] = $post->post_type . ':' . $new_status;
		}
	}

	/**
	 * The newest row of a post type, straight from the table.
	 *
	 * @param string $type Post type.
	 * @return object|null `ID` and `post_status`.
	 */
	private function newest( $type ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID DESC LIMIT 1", $type ) );
	}

	/**
	 * Put a row back the way 1.6.2 and earlier wrote it.
	 *
	 * @param int $id Post id.
	 */
	private function make_legacy( $id ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => (int) $id ) );
		clean_post_cache( (int) $id );
	}

	/**
	 * One record from each of the five stores.
	 */
	private function write_one_of_each() {
		Saddle_Approval::gate(
			array(
				'action'  => 'delete_post',
				'target'  => '42',
				'summary' => 'Delete post #42',
				'preview' => array( 'id' => 42 ),
				'input'   => array(),
				'execute' => '__return_true',
			)
		);
		Saddle_Log::record(
			array(
				'action'  => 'update-post',
				'summary' => 'An app changed a post',
			)
		);
		Saddle_OAuth_Store::save_token( 'access', 'records-test-token', array( 'grant_id' => 'g1' ) );
		Saddle_Skills::install( "---\nname: Records Test\ndescription: A test skill.\nwhen_to_use: never\n---\n\nBody\n" );
		Saddle_Memory::remember( array( 'key' => 'records-test', 'text' => 'A test memory.' ) );
	}

	public function test_the_status_is_registered_and_not_public() {
		$status = get_post_status_object( Saddle_Records::STATUS );

		$this->assertNotNull( $status );
		$this->assertFalse( $status->public );
		$this->assertFalse( $status->private );
		$this->assertFalse( $status->protected );
		$this->assertFalse( $status->show_in_admin_all_list );
		$this->assertLessThanOrEqual( 20, strlen( Saddle_Records::STATUS ), 'post_status is varchar(20).' );
	}

	public function test_no_store_saves_a_record_as_published() {
		$this->write_one_of_each();

		foreach ( Saddle_Records::post_types() as $type ) {
			$row = $this->newest( $type );
			$this->assertNotNull( $row, "$type wrote nothing." );
			$this->assertSame( Saddle_Records::STATUS, $row->post_status, "$type was saved as {$row->post_status}." );
		}

		$this->assertNotEmpty( $this->seen );
		foreach ( $this->seen as $transition ) {
			$this->assertStringEndsWith( ':' . Saddle_Records::STATUS, $transition, 'A plugin listening for published posts would see this record.' );
		}
	}

	public function test_updating_a_legacy_skill_moves_it_off_publish() {
		$skill = Saddle_Skills::install( "---\nname: Old Skill\ndescription: First.\nwhen_to_use: never\n---\n\nOne\n" );
		$this->assertNotWPError( $skill );
		$row = $this->newest( Saddle_Skills::CPT );
		$this->make_legacy( $row->ID );

		Saddle_Skills::install( "---\nname: Old Skill\ndescription: Second.\nwhen_to_use: never\n---\n\nTwo\n" );

		$this->assertSame( Saddle_Records::STATUS, get_post_status( (int) $row->ID ) );
		$this->assertNotContains( Saddle_Skills::CPT . ':publish', $this->seen );
	}

	public function test_a_legacy_access_token_still_signs_in() {
		Saddle_OAuth_Store::save_token( 'access', 'legacy-token', array( 'grant_id' => 'g2' ) );
		$this->make_legacy( $this->newest( Saddle_OAuth_Store::CPT )->ID );

		$record = Saddle_OAuth_Store::get_access_token( 'legacy-token' );

		$this->assertIsArray( $record, 'A token issued before the update must keep working until it is moved.' );
		$this->assertSame( 'g2', $record['grant_id'] );
	}

	public function test_a_legacy_confirm_token_still_confirms_once() {
		$calls = 0;
		$args  = array(
			'action'  => 'delete_post',
			'target'  => '7',
			'summary' => 'Delete post #7',
			'preview' => array( 'id' => 7 ),
			'input'   => array(),
			'execute' => function () use ( &$calls ) {
				$calls++;
				return array( 'executed' => true );
			},
		);
		$token = Saddle_Approval::gate( $args )['confirm_token'];
		$this->make_legacy( $this->newest( Saddle_Approval::CPT )->ID );

		$args['input'] = array( 'confirm_token' => $token );
		Saddle_Approval::gate( $args );
		$again = Saddle_Approval::gate( $args );

		$this->assertSame( 1, $calls );
		$this->assertWPError( $again );
	}

	public function test_the_move_converts_saddle_rows_and_nothing_else() {
		$this->write_one_of_each();
		$ids = array();
		foreach ( Saddle_Records::post_types() as $type ) {
			$ids[ $type ] = (int) $this->newest( $type )->ID;
			$this->make_legacy( $ids[ $type ] );
		}
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		delete_option( Saddle_Records::VERSION_OPTION );
		$this->seen = array();
		Saddle_Records::maybe_migrate();

		foreach ( $ids as $type => $id ) {
			$this->assertSame( Saddle_Records::STATUS, get_post_status( $id ), "$type was not moved." );
		}
		$this->assertSame( 'publish', get_post_status( $page ), "Someone else's post must keep its status." );
		$this->assertSame( Saddle_Records::VERSION, (int) get_option( Saddle_Records::VERSION_OPTION ) );
		$this->assertSame( array(), $this->seen, 'The move must not fire post hooks.' );
	}

	public function test_the_move_runs_once() {
		update_option( Saddle_Records::VERSION_OPTION, Saddle_Records::VERSION );
		Saddle_Log::record(
			array(
				'action'  => 'update-post',
				'summary' => 'Left alone',
			)
		);
		$id = (int) $this->newest( Saddle_Log::CPT )->ID;
		$this->make_legacy( $id );

		Saddle_Records::maybe_migrate();

		$this->assertSame( 'publish', get_post_status( $id ), 'With the version stored, nothing runs.' );
	}

	public function test_the_uninstall_sweep_still_finds_records() {
		$this->write_one_of_each();

		$found = get_posts(
			array(
				'post_type'      => Saddle_Records::post_types(),
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		foreach ( Saddle_Records::post_types() as $type ) {
			$this->assertContains( (int) $this->newest( $type )->ID, array_map( 'intval', $found ), "uninstall.php would leave $type behind." );
		}
	}

	public function test_the_submitted_url_is_still_a_404() {
		Saddle_OAuth_Store::save_token( 'access', 'url-token', array( 'grant_id' => 'g3' ) );
		$id = (int) $this->newest( Saddle_OAuth_Store::CPT )->ID;

		$this->go_to( home_url( '/?post_type=' . Saddle_OAuth_Store::CPT . '&p=' . $id ) );

		$this->assertTrue( is_404() );
	}
}
