<?php
/**
 * A module's pages inside its sections (M1, M2 in planning/MODULE-LAYOUT.md):
 * the descriptor's `subtabs` and `subtab_icons` are cleaned, `&sub=` is
 * resolved and written into every address, and the admin app and the
 * `/modules` route get each section's pages.
 *
 * @package Saddle
 */

class Saddle_Module_Nav_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->reset_menus();
	}

	public function tear_down() {
		remove_all_filters( 'saddle_modules' );
		$this->reset_menus();
		unset( $_GET['page'], $_GET['tab'], $_GET['sub'], $_GET['view'] );
		wp_dequeue_script( 'saddle-admin' );
		wp_deregister_script( 'saddle-admin' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function reset_menus() {
		foreach ( array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages' ) as $global ) {
			$GLOBALS[ $global ] = array();
		}
	}

	/**
	 * Rank, shaped as MODULE-LAYOUT.md maps it, with a few bad entries.
	 *
	 * @param array $overrides Descriptor keys to replace.
	 */
	private function rank( array $overrides = array() ) {
		add_filter(
			'saddle_modules',
			static function ( $modules ) use ( $overrides ) {
				$modules['rank'] = array_merge(
					array(
						'title'        => 'Rank',
						'tabs'         => array(
							'overview'   => 'Overview',
							'visibility' => 'AI visibility',
							'links'      => 'Links',
							'settings'   => 'Settings',
						),
						'subtabs'      => array(
							'overview'   => array(
								'summary' => 'Summary',
								'audit'   => 'Site audit',
							),
							'visibility' => array(
								'answers'  => 'Answer scores',
								'Traffic'  => 'Bot traffic',
								'mentions' => '',
								'crawlers' => array( 'not a label' ),
							),
							'links'      => array( 'broken' => 'Broken links' ),
							'missing'    => array(
								'a' => 'A',
								'b' => 'B',
							),
						),
						'subtab_icons' => array(
							'overview'   => array(
								'summary' => 'dashboard-dots',
								'audit'   => 'clipboard-check',
							),
							'visibility' => array(
								'answers' => 'quote-message',
								'traffic' => 'no-such-icon',
							),
						),
					),
					$overrides
				);
				return $modules;
			}
		);
	}

	private function saddle_data( $page ) {
		Saddle_Settings::register_menu();
		Saddle_Settings::order_submenu();
		$_GET['page'] = $page;
		Saddle_Settings::enqueue_assets( get_plugin_page_hookname( $page, 'saddle' ) );

		preg_match( '/window\.saddleData = (\{.*\});/s', implode( "\n", wp_scripts()->get_data( 'saddle-admin', 'before' ) ), $match );

		return json_decode( $match[1], true );
	}

	/* ---------------------------------------------------------- normalising */

	public function test_pages_are_cleaned_and_a_one_page_section_has_none() {
		$this->rank();
		$rank = Saddle_Modules::modules()['rank'];

		$this->assertSame(
			array(
				'overview'   => array(
					'summary' => 'Summary',
					'audit'   => 'Site audit',
				),
				'visibility' => array(
					'answers' => 'Answer scores',
					'traffic' => 'Bot traffic',
				),
			),
			$rank['subtabs'],
			'Keys are sanitized, empty and non-string labels dropped, a one-page section and an unknown section ignored.'
		);
	}

	public function test_page_icons_are_checked_and_filled_by_key() {
		$this->rank(
			array(
				'subtabs' => array(
					'overview'   => array(
						'summary' => 'Summary',
						'audit'   => 'Site audit',
					),
					'visibility' => array(
						'answers' => 'Answer scores',
						'traffic' => 'Bot traffic',
					),
					'settings'   => array(
						'settings' => 'Features',
						'schedule' => 'Schedule',
					),
				),
			)
		);
		$rank = Saddle_Modules::modules()['rank'];

		$this->assertSame(
			array(
				'overview'   => array(
					'summary' => 'dashboard-dots',
					'audit'   => 'clipboard-check',
				),
				'visibility' => array( 'answers' => 'quote-message' ),
				'settings'   => array( 'settings' => 'settings' ),
			),
			$rank['subtab_icons'],
			'An unknown icon draws nothing; a page keyed settings gets the settings icon.'
		);
	}

	public function test_bad_shapes_are_ignored() {
		$this->rank(
			array(
				'subtabs'      => 'overview',
				'subtab_icons' => 'eye',
			)
		);
		$rank = Saddle_Modules::modules()['rank'];

		$this->assertSame( array(), $rank['subtabs'] );
		$this->assertSame( array(), $rank['subtab_icons'] );
	}

	public function test_core_pages_have_no_pages() {
		foreach ( Saddle_Modules::areas() as $key => $area ) {
			$this->assertSame( array(), $area['subtabs'], $key );
			$this->assertSame( array(), $area['subtab_icons'], $key );
		}
	}

	public function test_the_settings_tab_core_adds_can_have_pages() {
		add_filter(
			'saddle_modules',
			static function ( $modules ) {
				$modules['demo'] = array(
					'title'    => 'Demo',
					'tabs'     => array( 'overview' => 'Overview' ),
					'subtabs'  => array(
						'settings' => array(
							'features' => 'Features',
							'import'   => 'Import',
						),
					),
					'settings' => static function () {
						return array(
							'store'  => array( 'option' => 'demo_nav_settings' ),
							'fields' => array(
								'on' => array(
									'type'    => 'boolean',
									'default' => true,
									'label'   => 'On',
								),
							),
						);
					},
				);
				return $modules;
			}
		);
		$demo = Saddle_Modules::modules()['demo'];

		$this->assertArrayHasKey( 'settings', $demo['tabs'] );
		$this->assertSame(
			array(
				'features' => 'Features',
				'import'   => 'Import',
			),
			$demo['subtabs']['settings']
		);
	}

	/* --------------------------------------------------------------- resolving */

	public function test_an_empty_or_unknown_page_lands_on_the_first() {
		$this->rank();

		$this->assertSame( 'traffic', Saddle_Modules::resolve_sub( 'rank', 'visibility', 'traffic' ) );
		$this->assertSame( 'answers', Saddle_Modules::resolve_sub( 'rank', 'visibility', '' ) );
		$this->assertSame( 'answers', Saddle_Modules::resolve_sub( 'rank', 'visibility', 'nope' ) );
		$this->assertSame( '', Saddle_Modules::resolve_sub( 'rank', 'links', 'broken' ), 'A section with no pages has none.' );
		$this->assertSame( '', Saddle_Modules::resolve_sub( 'nope', 'visibility', 'traffic' ) );
		$this->assertSame( '', Saddle_Modules::resolve_sub( 'home', 'overview', 'x' ) );
	}

	/* ------------------------------------------------------------------- urls */

	public function test_urls_leave_the_first_tab_and_the_first_page_out() {
		$this->rank();
		$base = admin_url( 'admin.php?page=saddle-rank' );

		$this->assertSame( $base, Saddle_Modules::url( 'rank' ) );
		$this->assertSame( $base, Saddle_Modules::url( 'rank', 'overview', 'summary' ) );
		$this->assertSame( $base . '&sub=audit', Saddle_Modules::url( 'rank', 'overview', 'audit' ) );
		$this->assertSame( $base . '&sub=audit', Saddle_Modules::url( 'rank', '', 'audit' ), 'No tab is the first tab.' );
		$this->assertSame( $base . '&tab=visibility', Saddle_Modules::url( 'rank', 'visibility' ) );
		$this->assertSame( $base . '&tab=visibility', Saddle_Modules::url( 'rank', 'visibility', 'answers' ) );
		$this->assertSame( $base . '&tab=visibility&sub=traffic', Saddle_Modules::url( 'rank', 'visibility', 'traffic' ) );
		$this->assertSame( $base . '&tab=visibility', Saddle_Modules::url( 'rank', 'visibility', 'nope' ) );
		$this->assertSame( $base . '&tab=links', Saddle_Modules::url( 'rank', 'links', 'broken' ) );
		$this->assertSame( $base, Saddle_Modules::url( 'rank', 'nope', 'traffic' ), 'A page of another section is not carried to the first.' );
		$this->assertSame( admin_url( 'admin.php?page=saddle-connections' ), Saddle_Modules::url( 'connections', 'apps', 'x' ) );
	}

	public function test_a_field_can_name_its_page() {
		$this->rank();
		$field = array( 'screen' => 'rank/visibility/traffic' );

		$this->assertSame(
			admin_url( 'admin.php?page=saddle-rank&tab=visibility&sub=traffic' ) . '#saddle-field-rank-bots',
			Saddle_Settings_View::admin_url( 'rank', 'bots', $field )
		);
		$this->assertSame(
			admin_url( 'admin.php?page=saddle-rank&tab=visibility' ) . '#saddle-field-rank-bots',
			Saddle_Settings_View::admin_url( 'rank', 'bots', array( 'screen' => 'rank/visibility' ) )
		);
	}

	/* ------------------------------------------------------------ the route */

	/**
	 * @dataProvider routes
	 */
	public function test_the_page_reaches_the_root( $tab, $sub, $expected_tab, $expected_sub ) {
		$this->rank();
		$_GET['page'] = 'saddle-rank';
		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}
		if ( null !== $sub ) {
			$_GET['sub'] = $sub;
		}

		ob_start();
		Saddle_Settings::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-tab="' . $expected_tab . '"', $html );
		$this->assertStringContainsString( 'data-sub="' . $expected_sub . '"', $html );
	}

	public function routes() {
		return array(
			'a named page'                => array( 'visibility', 'traffic', 'visibility', 'traffic' ),
			'no page is the first'        => array( 'visibility', null, 'visibility', 'answers' ),
			'an unknown page'             => array( 'visibility', 'nope', 'visibility', 'answers' ),
			'no tab, a page of it'        => array( null, 'audit', 'overview', 'audit' ),
			'a section without pages'     => array( 'links', 'broken', 'links', '' ),
			'markup in the page is inert' => array( 'visibility', '"><script>', 'visibility', 'answers' ),
		);
	}

	/**
	 * A module is one page only when it says exactly 'page'; anything else,
	 * and every Core page, keeps the sections.
	 *
	 * @dataProvider layouts
	 *
	 * @param mixed  $layout   The descriptor's value, or null for none.
	 * @param string $expected The layout Core keeps.
	 */
	public function test_layout_is_page_or_sections( $layout, $expected ) {
		$this->rank( null === $layout ? array() : array( 'layout' => $layout ) );

		$this->assertSame( $expected, Saddle_Modules::modules()['rank']['layout'] );
		$this->assertSame( 'sections', Saddle_Modules::areas()['home']['layout'] );
		$this->assertSame( 'sections', Saddle_Modules::areas()['settings']['layout'] );
	}

	public function layouts() {
		return array(
			'none'     => array( null, 'sections' ),
			'page'     => array( 'page', 'page' ),
			'sections' => array( 'sections', 'sections' ),
			'unknown'  => array( 'grid', 'sections' ),
			'wrong'    => array( array( 'page' ), 'sections' ),
		);
	}

	public function test_the_app_gets_each_pages_layout() {
		$this->rank( array( 'layout' => 'page' ) );
		$saddle = $this->saddle_data( 'saddle-rank' );
		$areas  = array_combine( array_column( $saddle['areas'], 'key' ), $saddle['areas'] );

		$this->assertSame( 'page', $areas['rank']['layout'] );
		$this->assertSame( 'sections', $areas['home']['layout'] );
		$this->assertSame( 'sections', $areas['connections']['layout'] );
	}

	public function test_the_app_gets_the_page_and_each_sections_pages() {
		$this->rank();
		$_GET['tab'] = 'visibility';
		$_GET['sub'] = 'traffic';
		$saddle      = $this->saddle_data( 'saddle-rank' );

		$this->assertSame( 'rank', $saddle['area'] );
		$this->assertSame( 'visibility', $saddle['tab'] );
		$this->assertSame( 'traffic', $saddle['sub'] );
		$this->assertStringContainsString( rawurlencode( 'tab=visibility&sub=traffic' ), $saddle['loginUrl'] );

		$rank = $saddle['areas'][1];
		$this->assertSame( 'rank', $rank['key'] );
		$tabs = array_combine( array_column( $rank['tabs'], 'key' ), $rank['tabs'] );

		$this->assertSame(
			array(
				array(
					'key'   => 'answers',
					'label' => 'Answer scores',
					'url'   => admin_url( 'admin.php?page=saddle-rank&tab=visibility' ),
					'icon'  => 'quote-message',
				),
				array(
					'key'   => 'traffic',
					'label' => 'Bot traffic',
					'url'   => admin_url( 'admin.php?page=saddle-rank&tab=visibility&sub=traffic' ),
					'icon'  => '',
				),
			),
			$tabs['visibility']['subtabs']
		);
		$this->assertSame( array( 'key', 'label', 'url', 'icon' ), array_keys( $tabs['overview']['subtabs'][1] ) );
		$this->assertSame( array(), $tabs['links']['subtabs'] );
		$this->assertSame( array(), $tabs['settings']['subtabs'] );
		$this->assertSame( array(), $saddle['areas'][0]['tabs'][0]['subtabs'], 'Home has no pages.' );
	}

	public function test_a_core_page_has_no_sub() {
		$_GET['sub'] = 'anything';
		$saddle      = $this->saddle_data( 'saddle-settings' );

		$this->assertSame( 'settings', $saddle['area'] );
		$this->assertSame( '', $saddle['sub'] );
	}

	/* ---------------------------------------------------------- setup tasks */

	public function test_a_setup_action_can_name_a_page_a_view_and_args() {
		$this->rank(
			array(
				'setup' => static function () {
					return array(
						array(
							'id'     => 'bots',
							'title'  => 'Let AI crawlers in',
							'action' => array(
								'label' => 'Open',
								'tab'   => 'visibility',
								'sub'   => 'traffic',
								'view'  => 'Bot',
								'args'  => array(
									'bot'  => 'gpt bot',
									'page' => 'evil',
									'sub'  => 'evil',
									'list' => array( 1, 2 ),
								),
							),
						),
						array(
							'id'     => 'plain',
							'title'  => 'Read the docs',
							'action' => array(
								'label' => 'Docs',
								'url'   => 'https://example.com/docs',
							),
						),
					);
				},
			)
		);
		$tasks = Saddle_Modules::setup( 'rank' );

		$this->assertSame( admin_url( 'admin.php?page=saddle-rank&tab=visibility&sub=traffic&view=bot&bot=gpt%20bot' ), $tasks[0]['action']['url'] );
		$this->assertSame(
			array(
				'tab'  => 'visibility',
				'sub'  => 'traffic',
				'view' => 'bot',
				'args' => array( 'bot' => 'gpt bot' ),
			),
			$tasks[0]['action']['route']
		);
		$this->assertArrayNotHasKey( 'route', $tasks[1]['action'], 'A plain URL has no in-app route.' );
	}

	/* ----------------------------------------------------------- /modules */

	public function test_the_modules_route_lists_each_sections_pages() {
		$this->rank();
		$rank = Saddle_Modules_View::one( 'rank' );
		$tabs = array_combine( array_column( $rank['tabs'], 'key' ), $rank['tabs'] );

		$this->assertSame(
			array(
				array(
					'key'       => 'summary',
					'label'     => 'Summary',
					'admin_url' => admin_url( 'admin.php?page=saddle-rank' ),
				),
				array(
					'key'       => 'audit',
					'label'     => 'Site audit',
					'admin_url' => admin_url( 'admin.php?page=saddle-rank&sub=audit' ),
				),
			),
			$tabs['overview']['subtabs']
		);
		$this->assertSame( array(), $tabs['links']['subtabs'] );
	}
}
