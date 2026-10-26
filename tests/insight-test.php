<?php
/**
 * Opt-in usage data, Saddle news and deactivation feedback (Saddle_Insight):
 * nothing leaves the site without a click, each click sends only what it
 * says, and the switch off is honoured.
 *
 * All outbound HTTP is mocked via `pre_http_request` and recorded, so each
 * test can assert what was, and was NOT, sent.
 *
 * @package Saddle
 */

class Saddle_Insight_Test extends WP_UnitTestCase {

	/**
	 * Recorded outbound requests: [ [ 'url' => …, 'body' => array ], … ].
	 *
	 * @var array
	 */
	private $requests = array();

	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user(
			self::factory()->user->create(
				array(
					'role'         => 'administrator',
					'display_name' => 'Ada Owner',
					'user_email'   => 'ada@example.com',
				)
			)
		);
		// A fixed plugin list: two of the family and one that is not.
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'saddle/saddle.php'           => array(
						'Name'    => 'Saddle',
						'Version' => '1.6.2',
					),
					'saddle-rank/saddle-rank.php' => array(
						'Name'    => 'Saddle Rank',
						'Version' => '1.0.0',
					),
					'akismet/akismet.php'         => array(
						'Name'    => 'Akismet',
						'Version' => '5.4',
					),
				),
			),
			'plugins'
		);
		update_option( 'active_plugins', array( 'saddle/saddle.php', 'saddle-rank/saddle-rank.php' ) );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array(
					'url'  => $url,
					'body' => json_decode( (string) $args['body'], true ),
				);
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 204,
						'message' => 'No Content',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'saddle_insight_enabled' );
		delete_option( Saddle_Insight::OPTION );
		delete_option( Saddle_Insight_Payload::SITE_ID_OPTION );
		wp_clear_scheduled_hook( Saddle_Insight::HOOK );
		wp_cache_delete( 'plugins', 'plugins' );
		$this->requests = array();
		parent::tear_down();
	}

	private function call( $route, array $body ) {
		$request = new WP_REST_Request( 'POST', '/saddle/v1' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	private function sent( $route ) {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $r ) use ( $route ) {
					return Saddle_Insight::ENDPOINT . $route === $r['url'];
				}
			)
		);
	}

	/* -------- nothing without a click -------- */

	public function test_nothing_is_sent_before_the_owner_answers() {
		Saddle_Insight::schedule();
		Saddle_Insight::weekly();
		Saddle_Insight::for_app();

		$this->assertSame( array(), $this->requests );
		$this->assertFalse( wp_next_scheduled( Saddle_Insight::HOOK ) );
		$this->assertSame( '', Saddle_Insight::for_app()['state'], 'the card asks' );
	}

	public function test_skip_sends_nothing_and_hides_the_card() {
		$response = $this->call( '/insight', array( 'choice' => 'skip' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'out', $response->get_data()['state'] );
		$this->assertSame( array(), $this->requests );
	}

	/* -------- allow -------- */

	public function test_allow_sends_the_parts_left_on_and_the_family() {
		$this->call(
			'/insight',
			array(
				'choice' => 'allow',
				'parts'  => array(
					'site'    => true,
					'plugins' => true,
					'profile' => false,
				),
			)
		);

		$optin = $this->sent( 'optin' );
		$this->assertCount( 1, $optin );
		$body = $optin[0]['body'];

		$this->assertTrue( wp_is_uuid( $body['site']['id'], 4 ) );
		$this->assertSame( home_url( '/' ), $body['site']['url'] );
		$this->assertSame( array( 'saddle', 'saddle-rank', 'akismet' ), wp_list_pluck( $body['site']['plugins'], 'slug' ) );
		$this->assertArrayNotHasKey( 'admin', $body['site'], 'the name and email part was off' );
		$this->assertArrayNotHasKey( 'news', $body );
		$this->assertSame(
			array(
				array(
					'slug'    => 'saddle',
					'version' => '1.6.2',
					'active'  => true,
				),
				array(
					'slug'    => 'saddle-rank',
					'version' => '1.0.0',
					'active'  => true,
				),
			),
			$body['products'],
			'only the family, never Akismet'
		);
		$this->assertNotFalse( wp_next_scheduled( Saddle_Insight::HOOK ) );
	}

	public function test_a_part_switched_off_is_not_sent() {
		Saddle_Insight::answer(
			'allow',
			array(
				'site'    => false,
				'plugins' => false,
				'profile' => true,
			)
		);
		$site = $this->sent( 'optin' )[0]['body']['site'];

		$this->assertArrayNotHasKey( 'url', $site );
		$this->assertArrayNotHasKey( 'title', $site );
		$this->assertArrayNotHasKey( 'plugins', $site );
		$this->assertArrayNotHasKey( 'theme', $site );
		$this->assertSame(
			array(
				'name'  => 'Ada Owner',
				'email' => 'ada@example.com',
			),
			$site['admin']
		);
	}

	public function test_news_goes_with_the_opt_in_only_when_asked() {
		Saddle_Insight::answer( 'allow', array(), 'Me@Example.com' );
		$this->assertSame(
			array(
				'list'  => 'saddle',
				'email' => 'Me@Example.com',
			),
			$this->sent( 'optin' )[0]['body']['news']
		);
	}

	public function test_news_alone_sends_no_usage_data() {
		$response = $this->call(
			'/insight',
			array(
				'choice' => 'news',
				'email'  => 'me@example.com',
			)
		);

		$this->assertTrue( $response->get_data()['news'] );
		$this->assertSame( array( Saddle_Insight::ENDPOINT . 'news' ), wp_list_pluck( $this->requests, 'url' ) );
		$this->assertSame(
			array(
				'list'  => 'saddle',
				'email' => 'me@example.com',
			),
			$this->requests[0]['body']
		);
		$this->assertSame( '', Saddle_Insight::state()['state'], 'news is not an opt-in' );
	}

	/* -------- the weekly update and off -------- */

	public function test_the_weekly_update_sends_only_when_something_changed() {
		Saddle_Insight::answer( 'allow' );
		Saddle_Insight::weekly();
		Saddle_Insight::weekly();
		$this->assertCount( 1, $this->sent( 'update' ), 'the second week changed nothing' );

		update_option( 'active_plugins', array( 'saddle/saddle.php' ) );
		Saddle_Insight::weekly();
		$this->assertCount( 2, $this->sent( 'update' ) );
	}

	public function test_off_tells_plugpress_to_forget_the_site_and_stops_the_update() {
		Saddle_Insight::answer( 'allow' );
		$id = Saddle_Insight_Payload::site_id();

		$this->call( '/insight', array( 'choice' => 'off' ) );

		$this->assertSame(
			array(
				'site'     => array( 'id' => $id ),
				'products' => array( 'saddle', 'saddle-rank' ),
			),
			$this->sent( 'optout' )[0]['body']
		);
		$this->assertFalse( wp_next_scheduled( Saddle_Insight::HOOK ) );

		Saddle_Insight::weekly();
		$this->assertCount( 0, $this->sent( 'update' ) );
	}

	/* -------- deactivation -------- */

	public function test_send_and_deactivate_sends_only_what_the_dialog_lists() {
		Saddle_Insight::answer( 'allow' );
		$this->requests = array();

		$response = $this->call(
			'/insight/deactivate',
			array(
				'plugin'  => 'saddle-rank/saddle-rank.php',
				'reason'  => 'broke_something',
				'comment' => "The sitemap\nstopped working.",
				'email'   => 'reply@example.com',
			)
		);

		$this->assertTrue( $response->get_data()['sent'] );
		$body = $this->sent( 'deactivate' )[0]['body'];
		$this->assertSame( array( 'id', 'wp', 'php', 'language', 'multisite', 'environment' ), array_keys( $body['site'] ), 'never the address, title or plugins' );
		$this->assertSame(
			array(
				'slug'    => 'saddle-rank',
				'version' => '1.0.0',
			),
			$body['product']
		);
		$this->assertSame( 'broke_something', $body['reason'] );
		$this->assertSame( "The sitemap\nstopped working.", $body['comment'] );
		$this->assertSame( 'reply@example.com', $body['email'] );
	}

	public function test_the_dialog_answers_only_for_the_family() {
		$this->assertFalse( Saddle_Insight::deactivation( 'akismet/akismet.php', 'other' ) );
		$this->assertFalse( Saddle_Insight::deactivation( 'saddle-rank/saddle-rank.php', 'meh' ) );
		$this->assertSame( array(), $this->requests );
	}

	/* -------- the switch off and the gates -------- */

	public function test_switched_off_nothing_is_offered_or_sent() {
		add_filter( 'saddle_insight_enabled', '__return_false' );

		Saddle_Insight::answer( 'allow', array(), 'me@example.com' );
		Saddle_Insight::subscribe( 'me@example.com' );
		Saddle_Insight::deactivation( 'saddle-rank/saddle-rank.php', 'other' );

		$this->assertSame( array(), $this->requests );
		$this->assertFalse( Saddle_Insight::for_app()['enabled'] );
		$this->assertSame( '', Saddle_Insight::state()['state'] );
	}

	public function test_the_routes_need_the_right_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->call( '/insight', array( 'choice' => 'allow' ) )->get_status() );
		$this->assertSame(
			403,
			$this->call(
				'/insight/deactivate',
				array(
					'plugin' => 'saddle/saddle.php',
					'reason' => 'other',
				)
			)->get_status()
		);
		$this->assertSame( array(), $this->requests );
	}

	public function test_no_agent_tool_may_write_the_answer() {
		$this->assertTrue( Saddle_Settings_Guard::is_protected_option( Saddle_Insight::OPTION ) );
		$this->assertTrue( Saddle_Settings_Guard::is_protected_option( Saddle_Insight_Payload::SITE_ID_OPTION ) );
	}
}
