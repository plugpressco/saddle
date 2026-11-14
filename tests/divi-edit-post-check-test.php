<?php
/**
 * The saddle_divi_edit_post_check filter: the last say on whether a Divi
 * tool may write a post, asked after the edit_post check has passed.
 *
 * @package Saddle
 */

class Saddle_Divi_Edit_Post_Check_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		remove_all_filters( 'saddle_divi_edit_post_check' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		parent::tear_down();
	}

	private function divi_page() {
		return self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Keep</p>"}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->',
			)
		);
	}

	private function refuse_post( $refused_id ) {
		add_filter(
			'saddle_divi_edit_post_check',
			static function ( $allowed, $post ) use ( $refused_id ) {
				return (int) $post->ID === (int) $refused_id ? new WP_Error( 'test_locked', 'This page is locked.' ) : $allowed;
			},
			10,
			2
		);
	}

	public function test_the_filter_gets_true_and_the_post() {
		$id   = $this->divi_page();
		$seen = array();
		add_filter(
			'saddle_divi_edit_post_check',
			static function ( $allowed, $post ) use ( &$seen ) {
				$seen[] = array( $allowed, $post );
				return $allowed;
			},
			10,
			2
		);

		$post = Saddle_Divi::editable_divi5_post( $id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertCount( 1, $seen );
		$this->assertTrue( $seen[0][0] );
		$this->assertSame( $id, $seen[0][1]->ID );
	}

	public function test_a_wp_error_refuses_with_its_own_message() {
		$id = $this->divi_page();
		$this->refuse_post( $id );

		$result = Saddle_Divi::editable_divi5_post( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'test_locked', $result->get_error_code() );
		$this->assertSame( 'This page is locked.', $result->get_error_message() );
	}

	public function test_any_other_value_lets_the_edit_through() {
		$id = $this->divi_page();
		add_filter( 'saddle_divi_edit_post_check', '__return_false' );

		$this->assertInstanceOf( WP_Post::class, Saddle_Divi::editable_divi5_post( $id ) );
	}

	public function test_the_filter_runs_only_after_the_capability_check() {
		$id     = $this->divi_page();
		$called = false;
		add_filter(
			'saddle_divi_edit_post_check',
			static function ( $allowed ) use ( &$called ) {
				$called = true;
				return $allowed;
			}
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = Saddle_Divi::editable_divi5_post( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_denied', $result->get_error_code() );
		$this->assertFalse( $called );
	}

	public function test_every_divi_write_ability_asks_the_filter() {
		$id = $this->divi_page();
		$this->refuse_post( $id );
		$before = get_post( $id )->post_content;

		$calls = array(
			'saddle/divi-edit-module'         => array(
				'post_id' => $id,
				'address' => '0.0.0.0',
				'fields'  => array( 'content' => '<p>Changed</p>' ),
			),
			'saddle/divi-add-module'          => array(
				'post_id'        => $id,
				'parent_address' => '0.0.0',
				'node'           => array( 'type' => 'divi/text' ),
			),
			'saddle/divi-set-page'            => array(
				'post_id' => $id,
				'nodes'   => array( array( 'type' => 'divi/section' ) ),
			),
			'saddle/divi-set-page-settings'   => array(
				'post_id'    => $id,
				'show_title' => false,
			),
			'saddle/divi-enable-loop'         => array(
				'post_id'    => $id,
				'address'    => '0.0.0',
				'query_type' => 'post_types',
				'sub_types'  => array( 'post' ),
			),
			'saddle/divi-add-interaction'     => array(
				'post_id'         => $id,
				'trigger_address' => '0.0.0.0',
				'target_address'  => '0.0.0.0',
			),
			'saddle/divi-remove-module'       => array(
				'post_id' => $id,
				'address' => '0.0.0.0',
			),
			'saddle/divi-apply-global-preset' => array(
				'post_id'   => $id,
				'address'   => '0.0.0.0',
				'preset_id' => 'any',
			),
		);
		foreach ( $calls as $name => $input ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$result = $ability->execute( $input );
			$this->assertWPError( $result, $name . ' must ask the filter.' );
			$this->assertSame( 'test_locked', $result->get_error_code(), $name );
		}

		$this->assertSame( $before, get_post( $id )->post_content );
		$this->assertSame( '', (string) get_post_meta( $id, '_et_pb_show_title', true ) );
	}

	public function test_another_post_stays_editable() {
		$locked = $this->divi_page();
		$open   = $this->divi_page();
		$this->refuse_post( $locked );

		$result = wp_get_ability( 'saddle/divi-edit-module' )->execute(
			array(
				'post_id' => $open,
				'address' => '0.0.0.0',
				'fields'  => array( 'content' => '<p>Changed</p>' ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Changed', get_post( $open )->post_content );
	}
}
