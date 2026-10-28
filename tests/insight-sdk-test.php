<?php
/**
 * PlugPress Insight as Saddle carries it (includes/insight/): Saddle is
 * registered, the news bar shows once on Overview, quick feedback prints only
 * on the Plugins screen, "Stop sharing" shows only while site info is shared,
 * and the weekly update sends only then, and only when something changed.
 * pre_http_request answers every call, so nothing reaches the network.
 *
 * @package Saddle
 */

class Saddle_Insight_SDK_Test extends WP_UnitTestCase {

	/**
	 * Calls the SDK made: url and decoded body.
	 *
	 * @var array
	 */
	private $sent = array();

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( PlugPress_Insight::OPTION );
		$this->sent = array();
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		delete_option( PlugPress_Insight::OPTION );
		unset( $_GET['page'] );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function capture( $pre, $args, $url ) {
		$this->sent[] = array(
			'url'  => $url,
			'body' => json_decode( $args['body'], true ),
		);
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 204,
				'message' => 'No Content',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function bar() {
		ob_start();
		PlugPress_Insight::bar();
		return trim( ob_get_clean() );
	}

	private function dialog() {
		ob_start();
		PlugPress_Insight::dialog();
		return trim( ob_get_clean() );
	}

	private function share( $usage ) {
		update_option(
			PlugPress_Insight::OPTION,
			array(
				'answered' => time(),
				'usage'    => $usage,
			)
		);
	}

	public function test_saddle_is_registered() {
		$this->assertTrue( class_exists( 'PlugPress_Insight' ), 'plugins_loaded loads the newest copy.' );
		$this->assertContains( 'saddle', wp_list_pluck( $GLOBALS['plugpress_insight_products'], 'slug' ) );
	}

	public function test_bar_shows_once_on_overview() {
		set_current_screen( 'toplevel_page_saddle' );
		$_GET['page'] = 'saddle';
		$html         = $this->bar();
		$this->assertStringContainsString( 'News from Saddle', $html );
		$this->assertStringContainsString( '--plugpress-insight-accent:#d000c4', $html );

		$this->share( false );
		$this->assertSame( '', $this->bar(), 'Answered once, never again.' );
	}

	public function test_bar_needs_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_current_screen( 'toplevel_page_saddle' );
		$_GET['page'] = 'saddle';
		$this->assertSame( '', $this->bar() );
	}

	public function test_bar_stays_off_other_pages() {
		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->bar() );
	}

	public function test_dialog_prints_only_on_the_plugins_screen() {
		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->dialog() );

		set_current_screen( 'plugins' );
		$html = $this->dialog();
		$this->assertStringContainsString( 'class="plugpress-insight-dialog"', $html );
		$this->assertSame( 7, substr_count( $html, 'name="reason"' ) );
		$this->assertStringNotContainsString( ' checked', $html, 'No reason and no reply box comes ticked.' );
	}

	public function test_stop_sharing_shows_only_while_shared() {
		$basename = plugin_basename( SADDLE_FILE );
		$this->assertSame( array(), PlugPress_Insight::row_meta( array(), $basename ) );

		$this->share( true );
		$this->assertCount( 1, PlugPress_Insight::row_meta( array(), $basename ) );
		$this->assertSame( array(), PlugPress_Insight::row_meta( array(), 'other/other.php' ) );
	}

	public function test_weekly_sends_only_when_shared_and_changed() {
		PlugPress_Insight::weekly();
		$this->assertCount( 0, $this->sent, 'Not shared: nothing goes.' );

		$this->share( true );
		PlugPress_Insight::weekly();
		$this->assertCount( 1, $this->sent );
		$this->assertStringEndsWith( '/api/v1/update', $this->sent[0]['url'] );
		$this->assertContains( 'saddle', wp_list_pluck( $this->sent[0]['body']['products'], 'slug' ) );
		$this->assertArrayNotHasKey( 'admin', $this->sent[0]['body']['site'], 'The admin is never sent.' );

		PlugPress_Insight::weekly();
		$this->assertCount( 1, $this->sent, 'Nothing changed, so nothing goes.' );
	}
}
