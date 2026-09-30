<?php
/**
 * Notices (#276): the filter and its validation, server-side resolution,
 * severity order, screens, dismissal, the dismiss route and the Plugins-screen
 * notice.
 *
 * @package Saddle
 */

class Saddle_Notices_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		delete_option( 'saddle_onboarded' );
		delete_option( Saddle_Notices::STORE );
		rest_get_server();
	}

	public function tear_down() {
		remove_all_filters( 'saddle_notices' );
		delete_option( 'saddle_onboarded' );
		delete_option( Saddle_Notices::STORE );
		unset( $_GET['notice'], $_REQUEST['_wpnonce'], $_GET['_wpnonce'] );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function add( $notice ) {
		add_filter(
			'saddle_notices',
			static function ( $all ) use ( $notice ) {
				$all[] = $notice;
				return $all;
			}
		);
	}

	private function notice( $id, $extra = array() ) {
		return array_merge(
			array(
				'id'       => $id,
				'module'   => 'analytics',
				'severity' => 'warning',
				'message'  => 'Message ' . $id,
			),
			$extra
		);
	}

	private function ids( $where = 'saddle', $screen = 'home/overview' ) {
		return wp_list_pluck( Saddle_Notices::for_screen( $where, $screen ), 'id' );
	}

	public function test_a_valid_notice_arrives_with_defaults_and_no_callables() {
		$this->add( $this->notice( 'a', array( 'action' => array( 'label' => 'Fix', 'url' => 'https://example.com/fix' ), 'resolved' => '__return_false' ) ) );

		$notices = Saddle_Notices::for_screen( 'saddle', 'home/overview' );

		$this->assertSame(
			array(
				'id'       => 'a',
				'module'   => 'analytics',
				'severity' => 'warning',
				'message'  => 'Message a',
				'action'   => array( 'label' => 'Fix', 'url' => 'https://example.com/fix' ),
				'dismiss'  => false,
			),
			$notices[0]
		);
	}

	public function test_malformed_notices_are_dropped() {
		$this->add( 'not an array' );
		$this->add( $this->notice( '' ) );
		$this->add( $this->notice( 'has space' ) );
		$this->add( $this->notice( 'no-message', array( 'message' => '' ) ) );
		$this->add( $this->notice( 'bad-severity', array( 'severity' => 'loud' ) ) );
		$this->add( $this->notice( 'bad-where', array( 'where' => 'frontend' ) ) );
		$this->add( $this->notice( 'bad-dismiss', array( 'dismiss' => 'forever' ) ) );
		$this->add( $this->notice( 'fine' ) );

		$this->assertSame( array( 'fine' ), $this->ids() );
	}

	public function test_a_malformed_action_becomes_null_not_a_dropped_notice() {
		$this->add( $this->notice( 'a', array( 'action' => array( 'label' => 'No url' ) ) ) );

		$this->assertNull( Saddle_Notices::for_screen( 'saddle', 'home/overview' )[0]['action'] );
	}

	public function test_a_filter_that_returns_junk_is_survived() {
		add_filter( 'saddle_notices', '__return_null' );

		$this->assertSame( array(), $this->ids() );
	}

	public function test_the_first_notice_with_an_id_wins() {
		$this->add( $this->notice( 'a', array( 'message' => 'first' ) ) );
		$this->add( $this->notice( 'a', array( 'message' => 'second' ) ) );

		$this->assertSame( 'first', Saddle_Notices::for_screen( 'saddle', 'home/overview' )[0]['message'] );
	}

	public function test_resolved_drops_the_notice() {
		$this->add( $this->notice( 'fixed', array( 'resolved' => '__return_true' ) ) );
		$this->add( $this->notice( 'broken', array( 'resolved' => '__return_false' ) ) );

		$this->assertSame( array( 'broken' ), $this->ids() );
	}

	public function test_a_throwing_resolved_keeps_the_notice_and_breaks_nothing() {
		$this->add(
			$this->notice(
				'boom',
				array(
					'resolved' => static function () {
						throw new Error( 'nope' );
					},
				)
			)
		);

		$this->assertSame( array( 'boom' ), $this->ids() );
	}

	public function test_severity_order_is_stable() {
		$this->add( $this->notice( 'ok', array( 'severity' => 'success' ) ) );
		$this->add( $this->notice( 'warn-1', array( 'severity' => 'warning' ) ) );
		$this->add( $this->notice( 'info', array( 'severity' => 'info' ) ) );
		$this->add( $this->notice( 'err', array( 'severity' => 'error' ) ) );
		$this->add( $this->notice( 'warn-2', array( 'severity' => 'warning' ) ) );

		$this->assertSame( array( 'err', 'warn-1', 'warn-2', 'info', 'ok' ), $this->ids() );
	}

	public function test_screens_filter_by_tab_or_whole_area() {
		$this->add( $this->notice( 'tab', array( 'screens' => array( 'connections/apps' ) ) ) );
		$this->add( $this->notice( 'area', array( 'screens' => array( 'settings' ) ) ) );
		$this->add( $this->notice( 'everywhere' ) );

		$this->assertSame( array( 'tab', 'everywhere' ), $this->ids( 'saddle', 'connections/apps' ) );
		$this->assertSame( array( 'everywhere' ), $this->ids( 'saddle', 'connections/permissions' ) );
		$this->assertSame( array( 'area', 'everywhere' ), $this->ids( 'saddle', 'settings/advanced' ) );
	}

	public function test_where_keeps_plugins_and_saddle_notices_apart() {
		$this->add( $this->notice( 'p', array( 'where' => 'plugins' ) ) );
		$this->add( $this->notice( 's' ) );

		$this->assertSame( array( 's' ), $this->ids( 'saddle' ) );
		$this->assertContains( 'p', $this->ids( 'plugins' ) );
		$this->assertNotContains( 's', $this->ids( 'plugins' ) );
	}

	public function test_user_dismissal_is_for_that_user_only() {
		$this->add( $this->notice( 'u', array( 'dismiss' => 'user' ) ) );

		$this->assertTrue( Saddle_Notices::dismiss( 'u' ) );
		$this->assertSame( array(), $this->ids() );
		$this->assertSame( array( 'u' ), get_user_meta( $this->admin, Saddle_Notices::STORE, true ) );
		$this->assertFalse( get_option( Saddle_Notices::STORE ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( array( 'u' ), $this->ids() );
	}

	public function test_site_dismissal_is_for_everyone_and_not_autoloaded() {
		$this->add( $this->notice( 's', array( 'dismiss' => 'site' ) ) );

		$this->assertTrue( Saddle_Notices::dismiss( 's' ) );
		$this->assertSame( array( 's' ), get_option( Saddle_Notices::STORE ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( array(), $this->ids() );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Saddle_Notices::STORE ) );
		$this->assertNotContains( $autoload, array( 'yes', 'on', 'auto-on' ) );
	}

	public function test_dismissal_keeps_only_the_newest_hundred() {
		add_filter(
			'saddle_notices',
			static function ( $all ) {
				for ( $i = 1; $i <= 105; $i++ ) {
					$all[] = array(
						'id'       => 'n' . $i,
						'severity' => 'info',
						'message'  => 'm',
						'dismiss'  => 'user',
					);
				}
				return $all;
			}
		);

		for ( $i = 1; $i <= 105; $i++ ) {
			Saddle_Notices::dismiss( 'n' . $i );
		}

		$stored = get_user_meta( $this->admin, Saddle_Notices::STORE, true );
		$this->assertCount( 100, $stored );
		$this->assertSame( 'n6', $stored[0] );
		$this->assertSame( 'n105', $stored[99] );
	}

	public function test_dismissing_unknown_or_undismissible_ids_is_a_clear_error() {
		$this->add( $this->notice( 'stuck' ) );

		$unknown = Saddle_Notices::dismiss( 'nope' );
		$this->assertWPError( $unknown );
		$this->assertSame( 'saddle_unknown_notice', $unknown->get_error_code() );

		$stuck = Saddle_Notices::dismiss( 'stuck' );
		$this->assertWPError( $stuck );
		$this->assertSame( 'saddle_notice_not_dismissible', $stuck->get_error_code() );
	}

	// The dismiss route.

	private function dismiss_request( $id ) {
		return rest_do_request( new WP_REST_Request( 'POST', '/saddle/v1/notices/' . $id . '/dismiss' ) );
	}

	public function test_the_route_dismisses_for_an_admin() {
		$this->add( $this->notice( 'r', array( 'dismiss' => 'user' ) ) );

		$response = $this->dismiss_request( 'r' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'dismissed' => true,
				'id'        => 'r',
			),
			$response->get_data()
		);
		$this->assertSame( array(), $this->ids() );
	}

	public function test_the_route_refuses_anyone_who_cannot_manage_options() {
		$this->add( $this->notice( 'r', array( 'dismiss' => 'user' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->dismiss_request( 'r' )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->dismiss_request( 'r' )->get_status() );
	}

	public function test_the_route_answers_bad_ids_clearly() {
		$this->add( $this->notice( 'stuck' ) );

		$unknown = $this->dismiss_request( 'nope' );
		$this->assertSame( 404, $unknown->get_status() );
		$this->assertSame( 'saddle_unknown_notice', $unknown->get_data()['code'] );

		$stuck = $this->dismiss_request( 'stuck' );
		$this->assertSame( 400, $stuck->get_status() );
		$this->assertSame( 'saddle_notice_not_dismissible', $stuck->get_data()['code'] );

		$this->assertSame( 404, $this->dismiss_request( 'bad%20id' )->get_status() );
	}

	// The Plugins-screen notice.

	private function plugins_notice_html() {
		set_current_screen( 'plugins' );
		ob_start();
		Saddle_Notices_Admin::render_plugins_notice();
		return ob_get_clean();
	}

	public function test_the_plugins_notice_shows_to_an_admin_before_first_run() {
		$html = $this->plugins_notice_html();

		$this->assertStringContainsString( 'Saddle is installed. Connect your AI (2 minutes).', $html );
		$this->assertStringContainsString( 'Get started', $html );
		$this->assertStringContainsString( 'action=saddle_dismiss_notice', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_the_plugins_notice_is_gone_once_first_run_is_finished() {
		update_option( 'saddle_onboarded', true );

		$this->assertSame( '', $this->plugins_notice_html() );
	}

	public function test_the_plugins_notice_is_not_for_non_admins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->plugins_notice_html() );
	}

	public function test_the_plugins_notice_only_appears_on_the_plugins_screen() {
		set_current_screen( 'dashboard' );
		ob_start();
		Saddle_Notices_Admin::render_plugins_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_plugins_notice_never_reaches_the_app() {
		$this->assertNotContains( Saddle_Notices::INSTALLED_ID, $this->ids( 'saddle' ) );
	}

	public function test_the_dismiss_link_hides_the_notice() {
		$_GET['notice'] = Saddle_Notices::INSTALLED_ID;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Saddle_Notices_Admin::ACTION . '_' . Saddle_Notices::INSTALLED_ID );

		$url = Saddle_Notices_Admin::dismiss_and_url();

		$this->assertStringContainsString( 'plugins.php', $url );
		$this->assertSame( '', $this->plugins_notice_html() );
	}

	public function test_the_dismiss_link_needs_a_valid_nonce() {
		$_GET['notice']       = Saddle_Notices::INSTALLED_ID;
		$_REQUEST['_wpnonce'] = 'bogus';

		$this->expectException( WPDieException::class );
		try {
			Saddle_Notices_Admin::dismiss_and_url();
		} finally {
			$this->assertNotSame( '', $this->plugins_notice_html() );
		}
	}

	public function test_the_dismiss_link_needs_the_nonce_for_that_notice() {
		$_GET['notice']       = Saddle_Notices::INSTALLED_ID;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Saddle_Notices_Admin::ACTION . '_some-other-notice' );

		$this->expectException( WPDieException::class );
		Saddle_Notices_Admin::dismiss_and_url();
	}

	public function test_the_dismiss_link_refuses_a_non_admin_even_with_a_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_GET['notice']       = Saddle_Notices::INSTALLED_ID;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Saddle_Notices_Admin::ACTION . '_' . Saddle_Notices::INSTALLED_ID );

		$this->expectException( WPDieException::class );
		Saddle_Notices_Admin::dismiss_and_url();
	}
}
