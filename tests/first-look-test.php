<?php
/**
 * The first-run summary (#269): what Saddle says about a site before any app
 * is connected. It must stay local (no HTTP request, no update refresh), stay
 * behind manage_options, and leave a new install at the read tier.
 *
 * @package Saddle
 */

class Saddle_First_Look_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function get() {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/saddle/v1/first-look' ) );
	}

	private function image( $alt = null ) {
		$id = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		if ( null !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	public function test_an_editor_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->get()->get_status() );
	}

	public function test_the_summary_has_a_stable_shape() {
		$data = $this->get()->get_data();

		$this->assertSame( array( 'site', 'content', 'seo', 'updates', 'findings' ), array_keys( $data ) );
		$this->assertSame( array( 'name', 'wp_version', 'theme', 'builder' ), array_keys( $data['site'] ) );
		$this->assertContains( $data['site']['builder'], array( 'divi5', 'blocks', 'classic' ) );
		$this->assertSame( array( 'pages', 'posts', 'media' ), array_keys( $data['content'] ) );
		$this->assertSame( array( 'plugins', 'themes' ), array_keys( $data['updates'] ) );
		$this->assertSame( array( 'missing_alt', 'missing_description' ), array_keys( $data['findings'] ) );
	}

	public function test_it_counts_published_pages_and_posts_only() {
		$before = $this->get()->get_data()['content'];

		self::factory()->post->create( array( 'post_type' => 'page' ) );
		self::factory()->post->create();
		self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$after = $this->get()->get_data()['content'];
		$this->assertSame( $before['pages'] + 1, $after['pages'] );
		$this->assertSame( $before['posts'] + 1, $after['posts'], 'A draft is not counted.' );
	}

	public function test_it_counts_images_with_missing_or_empty_alt() {
		$before = $this->get()->get_data()['findings']['missing_alt'];

		$this->image();
		$this->image( '' );
		$this->image( 'A saddle on a fence' );

		$this->assertSame( $before + 2, $this->get()->get_data()['findings']['missing_alt'] );
	}

	public function test_descriptions_are_counted_from_the_active_seo_plugins_key() {
		foreach ( array( 'yoast' => '_yoast_wpseo_metadesc', 'rank-math' => 'rank_math_description' ) as $seo => $key ) {
			$before = Saddle_First_Look::count_without_description( $seo );

			update_post_meta( self::factory()->post->create(), $key, 'Written by hand.' );
			update_post_meta( self::factory()->post->create(), $key, '' );
			self::factory()->post->create();

			$this->assertSame( $before + 2, Saddle_First_Look::count_without_description( $seo ), "{$seo}: empty and missing count, written does not." );
		}
	}

	public function test_descriptions_are_not_guessed_without_a_supported_seo_plugin() {
		$this->assertNull( Saddle_First_Look::count_without_description( null ) );
		$this->assertNull( Saddle_First_Look::count_without_description( 'aioseo' ) );
	}

	public function test_it_makes_no_http_request_and_does_not_refresh_updates() {
		$requests = 0;
		$count    = static function ( $pre ) use ( &$requests ) {
			++$requests;
			return $pre;
		};
		add_filter( 'pre_http_request', $count );
		$checked = get_site_transient( 'update_plugins' );

		$this->assertSame( 200, $this->get()->get_status() );

		remove_filter( 'pre_http_request', $count );
		$this->assertSame( 0, $requests );
		$this->assertEquals( $checked, get_site_transient( 'update_plugins' ) );
	}

	public function test_finishing_first_run_leaves_a_new_install_at_read() {
		delete_option( 'saddle_access_tier' );
		delete_option( Saddle_Onboarding::OPTION );

		$req = new WP_REST_Request( 'POST', '/saddle/v1/preferences' );
		$req->set_body_params( array( 'onboarded' => true ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $req )->get_status() );

		$this->assertTrue( Saddle_Onboarding::is_finished() );
		$this->assertFalse( get_option( 'saddle_onboarded', false ) );
		$this->assertSame( 'read', Saddle_Capabilities::get_site_tier() );
	}
}
