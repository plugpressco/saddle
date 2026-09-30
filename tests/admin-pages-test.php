<?php
/**
 * The Saddle admin as WordPress pages (#274): one menu, a submenu per page,
 * modules between Home and the two configuration pages, the page and tab
 * handed to the app, and assets only on Saddle's own screens.
 *
 * @package Saddle
 */

class Saddle_Admin_Pages_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$this->reset_menus();
	}

	public function tear_down() {
		remove_all_filters( 'saddle_modules' );
		$this->reset_menus();
		unset( $_GET['page'], $_GET['tab'] );
		delete_option( 'saddle_onboarded' );
		wp_dequeue_script( 'saddle-admin' );
		wp_deregister_script( 'saddle-test-module' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function reset_menus() {
		foreach ( array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages' ) as $global ) {
			$GLOBALS[ $global ] = array();
		}
	}

	/**
	 * The Saddle submenu as [ slug => label ], in order.
	 *
	 * @return array
	 */
	private function saddle_submenu() {
		global $submenu;

		$items = array();
		foreach ( isset( $submenu['saddle'] ) ? $submenu['saddle'] : array() as $item ) {
			$items[ $item[2] ] = $item[0];
		}

		return $items;
	}

	private function build_menu() {
		Saddle_Settings::register_menu();
		Saddle_Settings::order_submenu();
	}

	private function analytics_module( array $overrides = array() ) {
		add_filter(
			'saddle_modules',
			static function ( $modules ) use ( $overrides ) {
				$modules['analytics'] = array_merge(
					array(
						'title'   => 'Analytics',
						'product' => 'Saddle Analytics',
						'version' => '1.0.0',
						'summary' => 'Cookieless visitor stats.',
						'tabs'    => array(
							'overview' => 'Overview',
							'ai'       => 'AI traffic',
						),
					),
					$overrides
				);
				return $modules;
			}
		);
	}

	/* ------------------------------------------------------------- the menu */

	public function test_core_has_four_pages_under_one_menu() {
		$this->build_menu();

		$this->assertSame(
			array(
				'saddle'             => 'Home',
				'saddle-connections' => 'Connections',
				'saddle-context'     => 'Context',
				'saddle-settings'    => 'Settings',
			),
			$this->saddle_submenu()
		);
	}

	public function test_a_module_sits_between_home_and_connections() {
		$this->analytics_module();
		$this->build_menu();

		$this->assertSame(
			array( 'saddle', 'saddle-analytics', 'saddle-connections', 'saddle-context', 'saddle-settings' ),
			array_keys( $this->saddle_submenu() )
		);
		$this->assertSame( 'Analytics', $this->saddle_submenu()['saddle-analytics'] );
	}

	/**
	 * Saddle Rank adds its own submenu at priority 20, after Core's items. It
	 * still lands between Home and Connections.
	 */
	public function test_a_sibling_that_adds_its_own_item_still_lands_before_connections() {
		Saddle_Settings::register_menu();
		add_submenu_page( 'saddle', 'SEO', 'SEO', 'manage_options', 'saddle-rank', '__return_null' );
		Saddle_Settings::order_submenu();

		$this->assertSame(
			array( 'saddle', 'saddle-rank', 'saddle-connections', 'saddle-context', 'saddle-settings' ),
			array_keys( $this->saddle_submenu() )
		);
	}

	public function test_a_module_without_nav_gets_no_page() {
		$this->analytics_module( array( 'nav' => false ) );
		$this->build_menu();

		$this->assertArrayNotHasKey( 'saddle-analytics', $this->saddle_submenu() );
	}

	public function test_modules_are_ordered_and_bad_descriptors_are_ignored() {
		add_filter(
			'saddle_modules',
			static function ( $modules ) {
				$modules['crm']       = array(
					'title' => 'CRM',
					'order' => 30,
				);
				$modules['rank']      = array(
					'title' => 'SEO',
					'order' => 20,
				);
				$modules['settings']  = array( 'title' => 'Takes a Core page' );
				$modules['untitled']  = array( 'order' => 1 );
				$modules['not-array'] = 'SEO';
				return $modules;
			}
		);

		$this->assertSame( array( 'rank', 'crm' ), array_keys( Saddle_Modules::modules() ) );
		$this->assertSame(
			array( 'home', 'rank', 'crm', 'connections', 'context', 'settings' ),
			array_keys( Saddle_Modules::areas() )
		);
		$this->assertSame( array( 'overview' => 'Overview' ), Saddle_Modules::modules()['crm']['tabs'], 'A module with no tabs gets one.' );
	}

	public function test_a_module_page_needs_the_capability_it_declares() {
		$this->analytics_module( array( 'capability' => 'edit_posts' ) );
		$this->build_menu();

		global $submenu;
		foreach ( $submenu['saddle'] as $item ) {
			if ( 'saddle-analytics' === $item[2] ) {
				$this->assertSame( 'edit_posts', $item[1] );
				return;
			}
		}
		$this->fail( 'The module page is missing.' );
	}

	/* ------------------------------------------------------------ the pages */

	/**
	 * @dataProvider routes
	 */
	public function test_each_page_names_its_area_and_tab( $page, $tab, $area, $expected_tab ) {
		$_GET['page'] = $page;
		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}

		ob_start();
		Saddle_Settings::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="saddle-root"', $html );
		$this->assertStringContainsString( 'data-area="' . $area . '"', $html );
		$this->assertStringContainsString( 'data-tab="' . $expected_tab . '"', $html );
	}

	public static function routes() {
		return array(
			'Home'                       => array( 'saddle', null, 'home', 'overview' ),
			'Home, Activity'             => array( 'saddle', 'activity', 'home', 'activity' ),
			'Connections'                => array( 'saddle-connections', null, 'connections', 'apps' ),
			'Connections, Permissions'   => array( 'saddle-connections', 'permissions', 'connections', 'permissions' ),
			'Context'                    => array( 'saddle-context', null, 'context', 'overview' ),
			'Settings'                   => array( 'saddle-settings', null, 'settings', 'general' ),
			'an unknown tab falls back'  => array( 'saddle-connections', 'nope', 'connections', 'apps' ),
			'markup in the tab is inert' => array( 'saddle', '"><script>', 'home', 'overview' ),
		);
	}

	public function test_urls_leave_the_first_tab_out() {
		$this->assertSame( admin_url( 'admin.php?page=saddle' ), Saddle_Modules::url( 'home' ) );
		$this->assertSame( admin_url( 'admin.php?page=saddle' ), Saddle_Modules::url( 'home', 'overview' ) );
		$this->assertSame( admin_url( 'admin.php?page=saddle-connections&tab=permissions' ), Saddle_Modules::url( 'connections', 'permissions' ) );
		$this->assertSame( admin_url( 'admin.php?page=saddle-context' ), Saddle_Modules::url( 'context', 'nope' ) );
	}

	/* --------------------------------------------------------------- assets */

	public function test_assets_load_on_every_saddle_page_and_nowhere_else() {
		$this->build_menu();

		Saddle_Settings::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_script_is( 'saddle-admin', 'enqueued' ) );

		$_GET['page'] = 'saddle-connections';
		$_GET['tab']  = 'permissions';
		Saddle_Settings::enqueue_assets( get_plugin_page_hookname( 'saddle-connections', 'saddle' ) );
		$this->assertTrue( wp_script_is( 'saddle-admin', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'saddle-admin', 'before' );
		$this->assertIsArray( $data );
		$this->assertMatchesRegularExpression( '/window\.saddleData = (\{.*\});/s', implode( "\n", $data ) );
		preg_match( '/window\.saddleData = (\{.*\});/s', implode( "\n", $data ), $match );
		$saddle = json_decode( $match[1], true );

		$this->assertSame( 'connections', $saddle['area'] );
		$this->assertSame( 'permissions', $saddle['tab'] );
		$this->assertSame( array( 'home', 'connections', 'context', 'settings' ), array_column( $saddle['areas'], 'key' ) );
		$this->assertSame( 2, $saddle['shellVersion'] );

		$connections = $saddle['areas'][1];
		$this->assertSame( array( 'apps', 'permissions' ), array_column( $connections['tabs'], 'key' ) );
		$this->assertSame( admin_url( 'admin.php?page=saddle-connections&tab=permissions' ), $connections['tabs'][1]['url'] );
	}

	public function test_a_modules_own_script_loads_on_its_page() {
		$this->analytics_module( array( 'script' => 'saddle-test-module' ) );
		wp_register_script( 'saddle-test-module', 'https://example.com/module.js', array( 'saddle-admin' ), '1', true );
		$this->build_menu();

		$_GET['page'] = 'saddle-analytics';
		Saddle_Settings::enqueue_assets( get_plugin_page_hookname( 'saddle-analytics', 'saddle' ) );

		$this->assertTrue( wp_script_is( 'saddle-test-module', 'enqueued' ) );
	}

	/* ----------------------------------------------------- the Plugins screen */

	public function test_the_plugins_row_says_get_started_until_first_run_is_done() {
		$links = Saddle_Settings::action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );
		$this->assertStringContainsString( 'Get started', $links[0] );
		$this->assertStringContainsString( 'page=saddle"', $links[0] );

		update_option( 'saddle_onboarded', true );
		$links = Saddle_Settings::action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertStringContainsString( 'page=saddle-settings', $links[0] );
	}

	public function test_the_plugins_row_link_needs_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( array( 'deactivate' => 'x' ), Saddle_Settings::action_links( array( 'deactivate' => 'x' ) ) );
	}
}
