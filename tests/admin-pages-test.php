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
		unset( $_GET['page'], $_GET['tab'], $_GET['view'] );
		delete_option( Saddle_Onboarding::OPTION );
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
	 * The Saddle submenu as [ slug => label ], in order. A title is an icon
	 * and the label; the label is what is left without the markup.
	 *
	 * @return array
	 */
	private function saddle_submenu() {
		global $submenu;

		$items = array();
		foreach ( isset( $submenu['saddle'] ) ? $submenu['saddle'] : array() as $item ) {
			$items[ $item[2] ] = wp_strip_all_tags( $item[0] );
		}

		return $items;
	}

	/**
	 * The Saddle submenu's raw items, keyed by slug.
	 *
	 * @return array
	 */
	private function saddle_items() {
		global $submenu;

		$items = array();
		foreach ( isset( $submenu['saddle'] ) ? $submenu['saddle'] : array() as $item ) {
			$items[ $item[2] ] = $item;
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

	public function test_core_has_four_pages_and_get_pro_under_one_menu() {
		$this->build_menu();

		$this->assertSame(
			array(
				'saddle'             => 'Home',
				'saddle-connections' => 'Agent setup',
				'saddle-context'     => 'Context',
				'saddle-settings'    => 'Settings',
				'saddle-plans'       => 'Get Pro',
			),
			$this->saddle_submenu()
		);
	}

	/**
	 * Get Pro (#373) is the one place free Saddle names its paid plans: it
	 * goes while Saddle Pro is active (the filter stands in for its
	 * constant here), and no agent ever reads about it.
	 */
	public function test_get_pro_hides_with_saddle_pro_and_from_agents() {
		$this->assertContains( 'plans', Saddle_Modules::RESERVED );
		$this->assertNotContains( 'plans', wp_list_pluck( Saddle_Modules_View::all(), 'key' ) );
		$this->assertStringNotContainsString( 'Get Pro', Saddle_Context::system_context() );

		add_filter( 'saddle_show_get_pro', '__return_false' );
		$this->build_menu();
		$areas = Saddle_Modules::areas();
		remove_filter( 'saddle_show_get_pro', '__return_false' );

		$this->assertArrayNotHasKey( 'saddle-plans', $this->saddle_submenu() );
		$this->assertArrayNotHasKey( 'plans', $areas );
	}

	public function test_a_module_sits_between_home_and_connections() {
		$this->analytics_module();
		$this->build_menu();

		$this->assertSame(
			array( 'saddle', 'saddle-analytics', 'saddle-connections', 'saddle-context', 'saddle-settings', 'saddle-plans' ),
			array_keys( $this->saddle_submenu() )
		);
		$this->assertSame( 'Analytics', $this->saddle_submenu()['saddle-analytics'] );
	}

	/**
	 * Saddle Rank adds its own submenu at priority 20, after Core's items. It
	 * still lands between Home and AI apps.
	 */
	public function test_a_sibling_that_adds_its_own_item_still_lands_before_connections() {
		Saddle_Settings::register_menu();
		add_submenu_page( 'saddle', 'SEO', 'SEO', 'manage_options', 'saddle-rank', '__return_null' );
		Saddle_Settings::order_submenu();

		$this->assertSame(
			array( 'saddle', 'saddle-rank', 'saddle-connections', 'saddle-context', 'saddle-settings', 'saddle-plans' ),
			array_keys( $this->saddle_submenu() )
		);
	}

	/* ------------------------------------------------------ labels and the hairline */

	public function test_each_menu_title_is_the_label_alone() {
		$this->analytics_module( array( 'icon' => 'graph-up' ) );
		$this->build_menu();

		$labels = array(
			'saddle'             => 'Home',
			'saddle-analytics'   => 'Analytics',
			'saddle-connections' => 'Agent setup',
			'saddle-context'     => 'Context',
			'saddle-settings'    => 'Settings',
			'saddle-plans'       => 'Get Pro',
		);
		foreach ( $this->saddle_items() as $slug => $item ) {
			$this->assertSame( $labels[ $slug ], $item[0], 'No icon before the label (#369): ' . $slug );
		}
		$this->assertSame( 'Analytics ‹ Saddle', $this->saddle_items()['saddle-analytics'][3] );
	}

	public function test_a_label_is_escaped() {
		$this->analytics_module( array( 'title' => 'Q&A <b>' ) );
		$this->build_menu();

		$this->assertSame( 'Q&amp;A &lt;b&gt;', $this->saddle_items()['saddle-analytics'][0] );
	}

	public function test_the_group_line_needs_a_product_under_home() {
		$this->build_menu();
		foreach ( $this->saddle_items() as $slug => $item ) {
			$this->assertFalse( isset( $item[4] ) && false !== strpos( (string) $item[4], 'saddle-menu-group' ), $slug );
		}

		$this->reset_menus();
		$this->analytics_module();
		$this->build_menu();
		$items = $this->saddle_items();
		$this->assertSame( 'saddle-menu-group', $items['saddle-connections'][4], 'The first of Core\'s pages after the products.' );
		foreach ( array( 'saddle', 'saddle-analytics', 'saddle-context', 'saddle-settings', 'saddle-plans' ) as $slug ) {
			$this->assertArrayNotHasKey( 4, $items[ $slug ], $slug );
		}

		// Settled twice (another plugin re-running the order), the class is
		// still there once.
		Saddle_Settings::order_submenu();
		$this->assertSame( 'saddle-menu-group', $this->saddle_items()['saddle-connections'][4] );
	}

	public function test_a_siblings_own_item_also_draws_the_line() {
		Saddle_Settings::register_menu();
		add_submenu_page( 'saddle', 'SEO', 'SEO', 'manage_options', 'saddle-rank', '__return_null' );
		Saddle_Settings::order_submenu();

		$this->assertSame( 'saddle-menu-group', $this->saddle_items()['saddle-connections'][4] );
	}

	public function test_the_menu_stylesheet_is_printed_for_the_menus_users_only() {
		ob_start();
		Saddle_Settings::menu_styles();
		$css = ob_get_clean();
		$this->assertStringContainsString( '<style id="saddle-menu-css">', $css );
		$this->assertStringContainsString( 'li.saddle-menu-group', $css );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		Saddle_Settings::menu_styles();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_register_menu_hooks_the_stylesheet() {
		remove_all_actions( 'admin_head' );
		$this->build_menu();
		$this->assertSame( 10, has_action( 'admin_head', array( 'Saddle_Settings', 'menu_styles' ) ) );
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
			array( 'home', 'rank', 'crm', 'connections', 'context', 'settings', 'plans' ),
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

	public function test_the_view_reaches_the_root_and_the_app() {
		$_GET['page'] = 'saddle';
		$_GET['view'] = 'Review"><b>';

		ob_start();
		Saddle_Settings::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-view="reviewb"', $html );
		unset( $_GET['view'] );

		ob_start();
		Saddle_Settings::render_page();
		$this->assertStringContainsString( 'data-view=""', ob_get_clean() );
	}

	/* ---------------------------------------------------------- setup tasks */

	public function test_a_modules_setup_tasks_reach_its_page() {
		add_filter(
			'saddle_modules',
			static function ( $modules ) {
				$modules['analytics'] = array(
					'title' => 'Analytics',
					'tabs'  => array( 'overview' => 'Overview' ),
					'setup' => static function () {
						return array(
							array(
								'id'     => 'tracker',
								'title'  => 'Turn tracking on',
								'done'   => false,
								'line'   => 'No visit counted yet.',
								'action' => array(
									'label' => 'Open settings',
									'tab'   => 'settings',
								),
							),
						);
					},
				);
				return $modules;
			}
		);
		$this->build_menu();
		$_GET['page'] = 'saddle-analytics';
		Saddle_Settings::enqueue_assets( get_plugin_page_hookname( 'saddle-analytics', 'saddle' ) );

		$data = implode( "\n", wp_scripts()->get_data( 'saddle-admin', 'before' ) );
		preg_match( '/window\.saddleData = (\{.*\});/s', $data, $match );
		$saddle = json_decode( $match[1], true );

		$this->assertSame( 'tracker', $saddle['setup'][0]['id'] );
		$this->assertFalse( $saddle['setup'][0]['done'] );
		$this->assertSame( 'No visit counted yet.', $saddle['setup'][0]['line'] );
		wp_deregister_script( 'saddle-admin' );
	}

	public function test_core_pages_carry_no_setup_tasks() {
		$this->build_menu();
		$_GET['page'] = 'saddle';
		Saddle_Settings::enqueue_assets( get_plugin_page_hookname( 'saddle', 'saddle' ) );
		$data = implode( "\n", wp_scripts()->get_data( 'saddle-admin', 'before' ) );
		preg_match( '/window\.saddleData = (\{.*\});/s', $data, $match );
		$this->assertSame( array(), json_decode( $match[1], true )['setup'] );
		wp_deregister_script( 'saddle-admin' );
	}

	/* ---------------------------------------------- Saddle Analytics (#367) */

	/**
	 * `saddleData.analytics` on Home, for whoever is signed in.
	 *
	 * @return array
	 */
	private function analytics_offer() {
		$this->build_menu();
		$_GET['page'] = 'saddle';
		Saddle_Settings::enqueue_assets( get_plugin_page_hookname( 'saddle', 'saddle' ) );
		$data = implode( "\n", wp_scripts()->get_data( 'saddle-admin', 'before' ) );
		preg_match( '/window\.saddleData = (\{.*\});/s', $data, $match );
		wp_deregister_script( 'saddle-admin' );
		return json_decode( $match[1], true )['analytics'];
	}

	public function test_an_owner_is_offered_saddle_analytics_while_it_is_not_on() {
		// Installed or not depends on the test site's plugins folder; either
		// way an administrator may add it.
		$offer = $this->analytics_offer();
		$this->assertContains( $offer['state'], array( 'missing', 'inactive' ) );
		$this->assertTrue( $offer['canAdd'] );
		$this->assertStringContainsString( 'page=saddle-analytics', $offer['url'] );
	}

	public function test_saddle_analytics_is_not_offered_once_it_is_on() {
		update_option( 'active_plugins', array( 'saddle-analytics/saddle-analytics.php' ) );
		$offer = $this->analytics_offer();
		$this->assertSame( 'active', $offer['state'] );
		$this->assertFalse( $offer['canAdd'] );
	}

	public function test_saddle_analytics_is_not_offered_to_someone_who_cannot_add_plugins() {
		$editor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		// Saddle's pages need manage_options; an editor given only that may
		// still not install or turn on plugins.
		$editor->add_cap( 'manage_options' );
		wp_set_current_user( $editor->ID );
		$this->assertFalse( $this->analytics_offer()['canAdd'] );
	}

	/* -------------------------------------------------------------- palette */

	public function test_the_palette_is_registered_but_not_enqueued_until_asked() {
		Saddle_Modules::register_palette();
		$this->assertTrue( wp_style_is( 'saddle-palette', 'registered' ) );
		$this->assertFalse( wp_style_is( 'saddle-palette', 'enqueued' ) );
	}

	public function test_enqueue_palette_adds_the_sheet_and_the_body_classes() {
		$this->assertTrue( Saddle_Modules::enqueue_palette() );
		$this->assertTrue( wp_style_is( 'saddle-palette', 'enqueued' ) );

		set_current_screen( 'dashboard' );
		$classes = apply_filters( 'admin_body_class', '' );
		$this->assertStringContainsString( 'saddle-palette', $classes );
		$this->assertStringNotContainsString( 'pp-scope', $classes, 'Another plugin kit widgets must keep their own palette.' );
		// Only the tokens: no frame rules, no wp-admin overrides.
		$css = file_get_contents( SADDLE_DIR . 'admin/build/palette.css' );
		$this->assertStringContainsString( '--saddle-chart-ink', $css );
		$this->assertStringNotContainsString( '#wpcontent', $css );
		$this->assertStringNotContainsString( '.saddle-header', $css );
		$this->assertStringNotContainsString( '.pp-scope', $css );
		$this->assertStringNotContainsString( '.pp-app', $css );
	}

	public static function routes() {
		return array(
			'Home'                       => array( 'saddle', null, 'home', 'overview' ),
			'an old Activity tab link'   => array( 'saddle', 'activity', 'home', 'overview' ),
			'AI apps'                    => array( 'saddle-connections', null, 'connections', 'apps' ),
			'AI apps, an old Permissions link' => array( 'saddle-connections', 'permissions', 'connections', 'apps' ),
			'Context'                    => array( 'saddle-context', null, 'context', 'overview' ),
			'Settings'                   => array( 'saddle-settings', null, 'settings', 'general' ),
			'an old Advanced link'       => array( 'saddle-settings', 'advanced', 'settings', 'general' ),
			'an unknown tab falls back'  => array( 'saddle-connections', 'nope', 'connections', 'apps' ),
			'markup in the tab is inert' => array( 'saddle', '"><script>', 'home', 'overview' ),
		);
	}

	public function test_urls_leave_the_first_tab_out() {
		$this->assertSame( admin_url( 'admin.php?page=saddle' ), Saddle_Modules::url( 'home' ) );
		$this->assertSame( admin_url( 'admin.php?page=saddle' ), Saddle_Modules::url( 'home', 'overview' ) );
		$this->assertSame( admin_url( 'admin.php?page=saddle' ), Saddle_Modules::url( 'home', 'activity' ), 'The Activity tab is Home now (#309).' );
		$this->assertSame( admin_url( 'admin.php?page=saddle-connections' ), Saddle_Modules::url( 'connections', 'permissions' ) );
		$this->assertSame( admin_url( 'admin.php?page=saddle-context' ), Saddle_Modules::url( 'context', 'nope' ) );
	}

	public function test_an_old_permissions_link_goes_to_ai_apps() {
		$this->assertSame( Saddle_Modules::url( 'connections' ), Saddle_Settings::legacy_target( 'saddle-connections', 'permissions' ) );
		$this->assertSame( '', Saddle_Settings::legacy_target( 'saddle-connections', '' ) );
		$this->assertSame( '', Saddle_Settings::legacy_target( 'saddle', 'permissions' ) );
	}

	/* --------------------------------------------------------------- assets */

	public function test_assets_load_on_every_saddle_page_and_nowhere_else() {
		$this->build_menu();

		Saddle_Settings::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_script_is( 'saddle-admin', 'enqueued' ) );

		$_GET['page'] = 'saddle-connections';
		$_GET['tab']  = 'activity';
		Saddle_Settings::enqueue_assets( get_plugin_page_hookname( 'saddle-connections', 'saddle' ) );
		$this->assertTrue( wp_script_is( 'saddle-admin', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'saddle-admin', 'before' );
		$this->assertIsArray( $data );
		$this->assertMatchesRegularExpression( '/window\.saddleData = (\{.*\});/s', implode( "\n", $data ) );
		preg_match( '/window\.saddleData = (\{.*\});/s', implode( "\n", $data ), $match );
		$saddle = json_decode( $match[1], true );

		$this->assertSame( 'connections', $saddle['area'] );
		$this->assertSame( 'apps', $saddle['tab'] );
		$this->assertSame( array( 'home', 'connections', 'context', 'settings', 'plans' ), array_column( $saddle['areas'], 'key' ) );
		$this->assertSame( 2, $saddle['shellVersion'] );

		$connections = $saddle['areas'][1];
		$this->assertSame( array( 'apps' ), array_column( $connections['tabs'], 'key' ) );
		$this->assertSame( 'Agent setup', $connections['title'] );
		$this->assertSame( array( 'overview' ), array_column( $saddle['areas'][0]['tabs'], 'key' ) );
		$this->assertSame( 'Home', $saddle['areas'][0]['title'] );
		$this->assertSame(
			array( 'home-simple-door', 'sparks', 'brain', 'settings', 'star' ),
			array_column( $saddle['areas'], 'icon' )
		);
	}

	public function test_the_app_gets_an_icon_per_page_and_per_tab() {
		wp_deregister_script( 'saddle-admin' );
		$this->analytics_module(
			array(
				'icon'      => 'graph-up',
				'tabs'      => array(
					'overview' => 'Overview',
					'reports'  => 'Reports',
					'people'   => 'People',
					'settings' => 'Settings',
				),
				'tab_icons' => array(
					'reports' => 'reports',
					'people'  => 'no-such-icon',
				),
			)
		);
		$this->build_menu();
		$_GET['page'] = 'saddle-analytics';
		Saddle_Settings::enqueue_assets( get_plugin_page_hookname( 'saddle-analytics', 'saddle' ) );

		preg_match( '/window\.saddleData = (\{.*\});/s', implode( "\n", wp_scripts()->get_data( 'saddle-admin', 'before' ) ), $match );
		$saddle    = json_decode( $match[1], true );
		$analytics = $saddle['areas'][1];

		$this->assertSame( 'analytics', $analytics['key'] );
		$this->assertSame( 'graph-up', $analytics['icon'] );
		$this->assertSame(
			array(
				'overview' => 'dashboard-dots',
				'reports'  => 'reports',
				'people'   => '',
				'settings' => 'settings',
			),
			array_combine( array_column( $analytics['tabs'], 'key' ), array_column( $analytics['tabs'], 'icon' ) )
		);
		$this->assertSame( array( 'Overview', 'Reports', 'People', 'Settings' ), array_column( $analytics['tabs'], 'label' ) );
		wp_deregister_script( 'saddle-admin' );
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

		Saddle_Onboarding::apply( array( 'event' => 'first_run.skip' ) );
		$links = Saddle_Settings::action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertStringContainsString( 'page=saddle-settings', $links[0] );
	}

	public function test_the_plugins_row_link_needs_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( array( 'deactivate' => 'x' ), Saddle_Settings::action_links( array( 'deactivate' => 'x' ) ) );
	}
}
