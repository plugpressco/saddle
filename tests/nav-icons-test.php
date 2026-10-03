<?php
/**
 * The family's Iconoir icons (K1, K2 in planning/NAV-STRUCTURE.md): the
 * allowlist is the folder, a descriptor's names are cleaned and checked
 * against it, defaults fill by tab key, and the markup is ready for the menu.
 *
 * @package Saddle
 */

class Saddle_Nav_Icons_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'saddle_modules' );
		parent::tear_down();
	}

	/**
	 * Every name in the manifest (scripts/icons.mjs) and every import of the
	 * generated module has its file, and the folder holds nothing else.
	 */
	public function test_every_manifest_name_has_a_file() {
		$names  = Saddle_Nav_Icons::names();
		$script = file_get_contents( SADDLE_DIR . 'scripts/icons.mjs' );
		$module = file_get_contents( SADDLE_DIR . 'admin/src/icons/iconoir.js' );

		$this->assertMatchesRegularExpression( '/const NAV = \[(.*?)\];/s', $script );
		preg_match( '/const NAV = \[(.*?)\];/s', $script, $nav );
		preg_match( '/const UI = \{(.*?)\};/s', $script, $ui );
		preg_match_all( "/'([a-z0-9-]+)'/", $nav[1], $nav_names );
		preg_match_all( "/:\s*'([a-z0-9-]+)'/", $ui[1], $ui_names );
		$manifest = array_unique( array_merge( $nav_names[1], $ui_names[1] ) );
		sort( $manifest );

		$this->assertNotEmpty( $manifest );
		$this->assertSame( $manifest, $names, 'assets/icons/ holds exactly the manifest. Run npm run icons.' );

		preg_match_all( '#assets/icons/([a-z0-9-]+)\.svg#', $module, $imports );
		$this->assertSame( $names, $imports[1], 'admin/src/icons/iconoir.js imports every file. Run npm run icons.' );

		foreach ( $names as $name ) {
			$svg = file_get_contents( SADDLE_DIR . 'assets/icons/' . $name . '.svg' );
			$this->assertStringStartsWith( '<svg ', $svg, $name );
			$this->assertStringContainsString( 'viewBox="0 0 24 24"', $svg, $name . ' keeps its viewBox.' );
			$this->assertDoesNotMatchRegularExpression( '/<svg[^>]*\s(width|height)=/', $svg, $name . ' has no fixed size.' );
		}
	}

	public function test_core_and_the_briefs_icons_are_in_the_allowlist() {
		$names = Saddle_Nav_Icons::names();
		foreach ( array( 'home-simple-door', 'graph-up', 'search-engine', 'send-mail', 'sparks', 'puzzle', 'brain', 'settings', 'dashboard-dots', 'reports', 'eye', 'search-window', 'multiple-pages', 'link', 'mail-out', 'group', 'nav-arrow-left', 'bell' ) as $name ) {
			$this->assertContains( $name, $names );
		}
		foreach ( Saddle_Modules::core_areas() as $key => $area ) {
			$this->assertContains( $area['icon'], $names, $key );
		}
	}

	public function test_svg_is_sized_hidden_and_classed() {
		$svg = Saddle_Nav_Icons::svg( 'graph-up' );

		$this->assertStringStartsWith( '<svg class="saddle-nav-icon" width="16" height="16" aria-hidden="true" focusable="false"', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 24 24"', $svg );
		$this->assertStringContainsString( 'currentColor', $svg );
		$this->assertSame( 1, substr_count( $svg, 'width="' ) - substr_count( $svg, 'stroke-width="' ) );

		$this->assertStringStartsWith( '<svg class="saddle-nav-icon" width="20" height="20"', Saddle_Nav_Icons::svg( 'bell', 20 ) );
	}

	public function test_an_unknown_or_unsafe_name_draws_nothing() {
		foreach ( array( '', 'no-such-icon', '../brand/mark', '../../saddle', array( 'settings' ), null, 42 ) as $name ) {
			$this->assertSame( '', Saddle_Nav_Icons::svg( $name ), wp_json_encode( $name ) );
			$this->assertSame( '', Saddle_Nav_Icons::pick( $name ), wp_json_encode( $name ) );
		}
	}

	public function test_a_menu_slot_keeps_the_label_edge_without_an_icon() {
		$this->assertStringStartsWith( '<svg class="saddle-nav-icon"', Saddle_Nav_Icons::menu_slot( 'settings' ) );
		$this->assertSame( '<span class="saddle-nav-icon" aria-hidden="true"></span>', Saddle_Nav_Icons::menu_slot( '' ) );
		$this->assertSame( '<span class="saddle-nav-icon" aria-hidden="true"></span>', Saddle_Nav_Icons::menu_slot( 'no-such-icon' ) );
	}

	public function test_pick_cleans_the_name() {
		$this->assertSame( 'graph-up', Saddle_Nav_Icons::pick( 'Graph-Up' ) );
		$this->assertSame( 'graph-up', Saddle_Nav_Icons::pick( 'graph-up' ) );
	}

	public function test_tab_icons_take_the_descriptor_then_the_defaults() {
		$tabs = array(
			'overview' => 'Overview',
			'reports'  => 'Reports',
			'people'   => 'People',
			'settings' => 'Settings',
		);

		$this->assertSame(
			array(
				'overview' => 'dashboard-dots',
				'reports'  => 'reports',
				'settings' => 'settings',
			),
			Saddle_Nav_Icons::tab_icons( $tabs, array( 'reports' => 'reports' ) )
		);

		// An unknown name is ignored: the default for the key stands, and a
		// tab with no default gets no icon.
		$this->assertSame(
			array(
				'overview' => 'dashboard-dots',
				'settings' => 'settings',
			),
			Saddle_Nav_Icons::tab_icons(
				$tabs,
				array(
					'overview' => 'nope',
					'people'   => '../x',
				)
			)
		);

		// A module may choose a different icon for a default key.
		$this->assertSame( 'eye', Saddle_Nav_Icons::tab_icons( $tabs, array( 'overview' => 'eye' ) )['overview'] );

		// Entries for tabs the module does not have are dropped.
		$this->assertArrayNotHasKey( 'ghost', Saddle_Nav_Icons::tab_icons( $tabs, array( 'ghost' => 'eye' ) ) );
		$this->assertSame( array(), Saddle_Nav_Icons::tab_icons( array( 'people' => 'People' ), 'not an array' ) );
	}

	public function test_a_descriptor_carries_icon_and_tab_icons() {
		add_filter(
			'saddle_modules',
			static function ( $modules ) {
				$modules['analytics'] = array(
					'title'     => 'Analytics',
					'icon'      => 'graph-up',
					'tabs'      => array(
						'overview' => 'Overview',
						'reports'  => 'Reports',
					),
					'tab_icons' => array( 'reports' => 'reports' ),
				);
				$modules['crm']       = array(
					'title'     => 'CRM',
					'icon'      => 'not-an-icon',
					'tabs'      => array( 'campaigns' => 'Campaigns' ),
					'tab_icons' => 'campaigns',
				);
				return $modules;
			}
		);

		$modules = Saddle_Modules::modules();
		$this->assertSame( 'graph-up', $modules['analytics']['icon'] );
		$this->assertSame(
			array(
				'overview' => 'dashboard-dots',
				'reports'  => 'reports',
			),
			$modules['analytics']['tab_icons']
		);
		$this->assertSame( array( 'overview' => 'Overview', 'reports' => 'Reports' ), $modules['analytics']['tabs'], 'Labels stay plain strings.' );

		$this->assertSame( '', $modules['crm']['icon'], 'An unknown icon is no icon, not an error.' );
		$this->assertSame( array(), $modules['crm']['tab_icons'] );
	}

	public function test_a_descriptor_without_icons_still_registers() {
		add_filter(
			'saddle_modules',
			static function ( $modules ) {
				$modules['rank'] = array( 'title' => 'SEO' );
				return $modules;
			}
		);

		$areas = Saddle_Modules::areas();
		$this->assertSame( '', $areas['rank']['icon'] );
		$this->assertSame( array( 'overview' => 'dashboard-dots' ), $areas['rank']['tab_icons'] );
		$this->assertSame( 'home-simple-door', $areas['home']['icon'] );
		$this->assertSame( 'settings', $areas['settings']['icon'] );
	}

	public function test_menu_css_draws_the_icon_and_the_hairline() {
		$css = Saddle_Nav_Icons::menu_css();
		$this->assertStringContainsString( '#adminmenu .saddle-nav-icon{', $css );
		$this->assertStringContainsString( 'width:16px;height:16px', $css );
		$this->assertStringContainsString( 'margin-inline-end:6px', $css );
		$this->assertStringContainsString( '#adminmenu li.saddle-menu-group{box-shadow:inset 0 1px 0 rgba(128,128,128,.3);margin-top:6px;padding-top:6px}', $css );
	}
}
