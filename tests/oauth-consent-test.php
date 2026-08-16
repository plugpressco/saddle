<?php
/**
 * The consent screen has to be reachable.
 *
 * This exists because of a real failure: the screen was first registered under
 * Saddle's own menu and then hidden with `remove_submenu_page()`, which looks
 * correct and is not. WordPress derives an admin page's registration key from
 * its parent, and removing the submenu entry destroys the only thing
 * `get_admin_page_parent()` uses to rediscover that parent — so the page ends up
 * registered under `saddle_page_saddle-authorize` and looked up under
 * `admin_page_saddle-authorize`, and an administrator arriving mid-OAuth-flow is
 * told "Sorry, you are not allowed to access this page."
 *
 * The whole OAuth flow is worthless if the one screen with a human in it 403s,
 * and nothing else in the suite would have caught it.
 *
 * @package Saddle
 */

class Saddle_OAuth_Consent_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();

		// plugin.php only. Including menu.php would execute WordPress's own
		// menu-assembly at require time, which needs a full admin bootstrap;
		// every function under test lives here.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		add_filter( 'saddle_oauth_enabled', '__return_true' );

		$GLOBALS['menu']              = array();
		$GLOBALS['submenu']           = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['admin_page_hooks']  = array();

		Saddle_Settings::register_menu();
		Saddle_OAuth_Consent::register_page();
	}

	public function tear_down() {
		unset(
			$GLOBALS['menu'],
			$GLOBALS['submenu'],
			$GLOBALS['_registered_pages'],
			$GLOBALS['admin_page_hooks'],
			$GLOBALS['plugin_page'],
			$GLOBALS['parent_file']
		);

		parent::tear_down();
	}

	/**
	 * Ask WordPress the same question `admin.php` asks before rendering.
	 *
	 * @return bool
	 */
	private function admin_can_reach_consent_screen() {
		$GLOBALS['plugin_page'] = Saddle_OAuth::AUTHORIZE_PAGE;
		$GLOBALS['pagenow']     = 'admin.php';
		$GLOBALS['parent_file'] = '';

		return user_can_access_admin_page();
	}

	public function test_an_administrator_can_open_the_consent_screen() {
		$this->assertTrue(
			$this->admin_can_reach_consent_screen(),
			'An administrator arriving from an OAuth redirect must be able to see the consent screen.'
		);
	}

	public function test_the_page_is_registered_under_the_name_it_is_looked_up_by() {
		// The exact mismatch that produced the original bug: registration key
		// and lookup key have to agree, and they only do when the parent stays
		// discoverable in $submenu.
		$hookname = get_plugin_page_hookname( Saddle_OAuth::AUTHORIZE_PAGE, get_admin_page_parent() );

		$this->assertArrayHasKey(
			$hookname,
			$GLOBALS['_registered_pages'],
			'The consent screen must be registered under the same hookname WordPress derives when resolving it.'
		);
	}

	public function test_the_consent_screen_is_not_in_saddles_visible_menu() {
		$slugs = array();
		foreach ( (array) ( isset( $GLOBALS['submenu'][ Saddle_Settings::PAGE_SLUG ] ) ? $GLOBALS['submenu'][ Saddle_Settings::PAGE_SLUG ] : array() ) as $item ) {
			$slugs[] = $item[2];
		}

		// Reachable by URL, absent from the sidebar — a screen that only makes
		// sense mid-flow shouldn't sit in the menu permanently.
		$this->assertNotContains( Saddle_OAuth::AUTHORIZE_PAGE, $slugs );
	}

	public function test_the_consent_screen_requires_the_authorize_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse(
			$this->admin_can_reach_consent_screen(),
			'Someone without the authorize capability must not be able to approve an app.'
		);
	}
}
