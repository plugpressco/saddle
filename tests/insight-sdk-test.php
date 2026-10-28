<?php
/**
 * PlugPress Insight as Saddle carries it (includes/insight/): Saddle is
 * registered as the saddle brand, the card on Overview asks for site info
 * first and then only for Saddle news, once each, a copy of the site at
 * another address starts over, quick feedback prints only on the Plugins
 * screen, "Stop sharing" shows only while site info is shared, and the weekly
 * update sends only then, and only when something changed.
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

	private function share( $usage, $asked = array(), $home = '', $by = 0 ) {
		update_option(
			PlugPress_Insight::OPTION,
			array(
				'usage' => $usage,
				'by'    => $by,
				'asked' => $asked,
				'home'  => $home,
			)
		);
	}

	public function test_saddle_is_registered() {
		$this->assertTrue( class_exists( 'PlugPress_Insight' ), 'plugins_loaded loads the newest copy.' );
		$this->assertContains( 'saddle', wp_list_pluck( $GLOBALS['plugpress_insight_products'], 'product' ) );
	}

	public function test_card_asks_for_site_info_then_news_once_each() {
		set_current_screen( 'toplevel_page_saddle' );
		$_GET['page'] = 'saddle';
		$html         = $this->bar();
		$this->assertStringContainsString( 'Get better support for Saddle', $html );
		$this->assertStringContainsString( 'Your profile', $html, 'What is shared names the profile.' );
		$this->assertStringContainsString( '>Allow<', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="news">/', $html, 'The news box starts unticked.' );
		$this->assertStringContainsString( '--plugpress-insight-accent:#d000c4', $html );
		$this->assertStringContainsString( 'data-slot=".saddle-app .pp-content:has(&gt; .saddle-tabpane)"', $html, 'The card opens Overview\'s content, not the welcome.' );

		$this->share( false );
		$html = $this->bar();
		$this->assertStringContainsString( 'Saddle news', $html, 'Site info answered: only the news is left to ask.' );
		$this->assertStringNotContainsString( '>Allow<', $html );

		$this->share( false, array( 'saddle' => time() ) );
		$this->assertSame( '', $this->bar(), 'Answered for Saddle, never again.' );
	}

	public function test_a_copy_at_another_address_starts_over() {
		update_option( PlugPress_Insight::SITE_ID, wp_generate_uuid4() );
		$this->share( true, array( 'saddle' => time() ), 'old.example' );
		PlugPress_Insight::start( $GLOBALS['plugpress_insight_products'] );
		$this->assertFalse( get_option( PlugPress_Insight::OPTION ), 'A new address asks again.' );
		$this->assertFalse( get_option( PlugPress_Insight::SITE_ID ), 'And reports under a new id.' );

		$home = strtolower( untrailingslashit( preg_replace( '#^https?://(www\.)?#i', '', get_option( 'home' ) ) ) );
		$this->share( true, array( 'saddle' => time() ), $home );
		PlugPress_Insight::start( $GLOBALS['plugpress_insight_products'] );
		$this->assertTrue( PlugPress_Insight::state()['usage'], 'The same address keeps its answers.' );
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

	public function test_card_is_a_plain_notice_on_the_plugins_screen() {
		set_current_screen( 'plugins' );
		$html = $this->bar();
		$this->assertStringContainsString( 'Get better support for Saddle', $html );
		$this->assertStringNotContainsString( 'data-slot', $html, 'Saddle\'s slot is for its own page only.' );
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

		$by = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Ada Owner',
				'user_email'   => 'ada@example.com',
			)
		);
		$this->share( true, array(), '', $by );
		PlugPress_Insight::weekly();
		$this->assertCount( 1, $this->sent );
		$this->assertSame(
			array(
				'name'  => 'Ada Owner',
				'email' => 'ada@example.com',
			),
			$this->sent[0]['body']['site']['admin'],
			'The person who clicked Allow, for support.'
		);
		$this->assertStringEndsWith( '/api/v1/update', $this->sent[0]['url'] );
		$this->assertSame( array( 'saddle' ), wp_list_pluck( $this->sent[0]['body']['products'], 'slug' ), 'One entry per brand.' );

		PlugPress_Insight::weekly();
		$this->assertCount( 1, $this->sent, 'Nothing changed, so nothing goes.' );
	}
}
