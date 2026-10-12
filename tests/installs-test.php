<?php
/**
 * Directory and install ability tests.
 *
 * Drives the real wp_get_ability()->execute() path and core's real
 * plugins_api(). Nothing leaves the test: `pre_http_request` answers the
 * WordPress.org directory from answers recorded from the live API on
 * 2026-10-12 (`tests/fixtures/wporg/`), and refuses every other host. Those
 * recordings are what pin the directory's field names: if WordPress.org or
 * core renames one, re-record and this file goes red before a customer sees
 * an empty column.
 *
 * @package Saddle
 */

class Saddle_Installs_Test extends WP_UnitTestCase {

	private $admin;

	/** Every directory URL plugins_api() asked for. */
	private $requests = array();

	/** plugin_information answers by slug; anything else is "not found". */
	private $info = array();

	/** Plugin folders a test created, removed in tear_down. */
	private $dirs = array();

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'admin' );

		$this->info = array( 'classic-editor' => $this->fixture( 'plugin-information' ) );
		add_filter( 'pre_http_request', array( $this, 'serve_directory' ), 10, 3 );
	}

	public function tear_down() {
		global $wpdb;
		remove_filter( 'pre_http_request', array( $this, 'serve_directory' ), 10 );
		remove_all_filters( 'plugins_api' );
		foreach ( $this->dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%saddle_plugin_search_%'" );
		wp_cache_flush();
		wp_cache_delete( 'plugins', 'plugins' );
		delete_option( 'active_plugins' );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	/* -------- fixtures -------- */

	private function fixture( $name ) {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/wporg/' . $name . '.json' ), true );
	}

	public function serve_directory( $pre, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		$parts = wp_parse_url( $url );
		if ( 'api.wordpress.org' !== $parts['host'] || '/plugins/info/1.2/' !== $parts['path'] ) {
			return new WP_Error( 'saddle_test_offline', 'No outbound HTTP in tests: ' . $url );
		}
		$this->requests[] = $url;
		parse_str( $parts['query'], $query );

		if ( 'query_plugins' === $query['action'] ) {
			$body = $this->fixture( 'query-plugins' );
		} else {
			$slug = $query['request']['slug'];
			$body = isset( $this->info[ $slug ] ) ? $this->info[ $slug ] : array( 'error' => 'Plugin not found.' );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function write_plugin( $slug, $version ) {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		wp_mkdir_p( $dir );
		file_put_contents( "{$dir}/{$slug}.php", "<?php\n/**\n * Plugin Name: Test {$slug}\n * Version: {$version}\n */\n" );
		$this->dirs[] = $dir;
		wp_cache_delete( 'plugins', 'plugins' );
		return "{$slug}/{$slug}.php";
	}

	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$p = $dir . '/' . $entry;
			is_dir( $p ) ? $this->remove_dir( $p ) : unlink( $p );
		}
		rmdir( $dir );
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	private function search( array $input ) {
		return $this->ability( 'saddle/search-plugins' )->execute( $input );
	}

	/* -------- search-plugins -------- */

	public function test_search_plugins_requires_admin_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->assertWPError( $this->search( array( 'search' => 'classic editor' ) ) );
		$this->assertSame( array(), $this->requests, 'A refused call asks the directory nothing.' );
	}

	public function test_search_returns_the_directory_rows() {
		$result = $this->search( array( 'search' => 'classic editor' ) );
		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$this->assertSame( array( 'results', 'total', 'source' ), array_keys( $result ) );
		$this->assertSame( 'wordpress.org', $result['source'] );
		$this->assertSame( 4111, $result['total'] );
		$this->assertCount( 3, $result['results'] );

		$row = $result['results'][0];
		$this->assertSame(
			array( 'slug', 'name', 'author', 'version', 'active_installs', 'rating', 'num_ratings', 'last_updated', 'tested', 'requires', 'requires_php', 'installed', 'active', 'plugin' ),
			array_keys( $row )
		);
		$this->assertSame( 'classic-editor', $row['slug'] );
		$this->assertSame( 'Classic Editor', $row['name'] );
		$this->assertSame( 'WordPress.org', $row['author'], 'The author link is reduced to its text.' );
		$this->assertSame( '1.7.0', $row['version'] );
		$this->assertSame( 8000000, $row['active_installs'] );
		$this->assertSame( 98, $row['rating'] );
		$this->assertSame( 1248, $row['num_ratings'] );
		$this->assertSame( '2026-05-28', $row['last_updated'] );
		$this->assertSame( '7.0.7', $row['tested'] );
		$this->assertSame( '4.9', $row['requires'] );
		$this->assertSame( '5.2.4', $row['requires_php'] );
		$this->assertFalse( $row['installed'] );
		$this->assertFalse( $row['active'] );
		$this->assertNull( $row['plugin'] );
	}

	public function test_the_search_asks_wordpress_org_for_saddles_fields() {
		$this->search(
			array(
				'search'   => 'classic editor',
				'per_page' => 3,
			)
		);
		$this->assertCount( 1, $this->requests );

		$parts = wp_parse_url( $this->requests[0] );
		parse_str( $parts['query'], $query );
		$this->assertSame( 'api.wordpress.org', $parts['host'] );
		$this->assertSame( 'query_plugins', $query['action'] );
		$this->assertSame( 'classic editor', $query['request']['search'] );
		$this->assertSame( '3', $query['request']['per_page'] );
		foreach ( Saddle_Install_Abilities::FIELDS as $field => $wanted ) {
			$this->assertSame( $wanted ? '1' : '0', $query['request']['fields'][ $field ], "Field {$field}" );
		}
	}

	public function test_the_recorded_directory_answers_carry_the_fields_saddle_reads() {
		// The names Saddle reads, against what WordPress.org really sent.
		$read   = array( 'slug', 'name', 'author', 'version', 'active_installs', 'rating', 'num_ratings', 'last_updated', 'tested', 'requires', 'requires_php', 'download_link' );
		$search = $this->fixture( 'query-plugins' );
		$info   = $this->fixture( 'plugin-information' );
		$this->assertArrayHasKey( 'results', $search['info'] );
		foreach ( $read as $field ) {
			$this->assertArrayHasKey( $field, $search['plugins'][0], "query_plugins sends {$field}" );
			$this->assertArrayHasKey( $field, $info, "plugin_information sends {$field}" );
		}
		$this->assertStringStartsWith( 'https://downloads.wordpress.org/plugin/', $info['download_link'] );
		$this->assertArrayNotHasKey( 'sections', $info, 'The heavy fields stay off.' );
	}

	public function test_a_repeated_search_is_answered_from_the_cache() {
		$this->search( array( 'search' => 'classic editor' ) );
		$this->search( array( 'search' => 'classic editor' ) );
		$this->assertCount( 1, $this->requests, 'The second search must not ask WordPress.org again.' );

		$this->search( array( 'search' => 'something else' ) );
		$this->assertCount( 2, $this->requests, 'A different search is a different cache entry.' );
	}

	public function test_installed_and_active_are_this_sites_and_never_cached() {
		$first = $this->search( array( 'slug' => 'classic-editor' ) );
		$this->assertFalse( $first['results'][0]['installed'] );

		$file = $this->write_plugin( 'classic-editor', '1.7.0' );
		activate_plugin( $file );

		$again = $this->search( array( 'slug' => 'classic-editor' ) );
		$this->assertCount( 1, $this->requests, 'The directory answer came from the cache.' );
		$this->assertTrue( $again['results'][0]['installed'] );
		$this->assertTrue( $again['results'][0]['active'] );
		$this->assertSame( $file, $again['results'][0]['plugin'] );
	}

	public function test_slug_looks_up_one_plugin() {
		$result = $this->search( array( 'slug' => 'classic-editor' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'classic-editor', $result['results'][0]['slug'] );
		$this->assertStringContainsString( 'action=plugin_information', $this->requests[0] );
	}

	public function test_unknown_and_closed_slugs_are_named() {
		$result = $this->search( array( 'slug' => 'no-such-plugin' ) );
		$this->assertSame( 'saddle_not_in_directory', $result->get_error_code() );
		$this->assertStringContainsString( 'no-such-plugin', $result->get_error_message() );

		// The directory's shape for a closed plugin: an "error" of "closed".
		$this->info['gone-plugin'] = array(
			'error'  => 'closed',
			'name'   => 'Gone Plugin',
			'slug'   => 'gone-plugin',
			'closed' => true,
		);
		$result = $this->search( array( 'slug' => 'gone-plugin' ) );
		$this->assertSame( 'saddle_plugin_closed', $result->get_error_code() );
	}

	public function test_an_unreachable_directory_is_a_502_with_the_reason() {
		add_filter(
			'plugins_api',
			static function () {
				return new WP_Error( 'plugins_api_failed', 'An unexpected error occurred.', 'cURL error 6: Could not resolve host: api.wordpress.org' );
			}
		);
		$result = $this->search( array( 'search' => 'classic editor' ) );
		$this->assertSame( 'saddle_directory_unreachable', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'Could not resolve host', $result->get_error_message() );
	}

	public function test_exactly_one_of_search_or_slug() {
		$this->assertSame( 'saddle_search_or_slug', $this->search( array() )->get_error_code() );
		$both = $this->search(
			array(
				'search' => 'editor',
				'slug'   => 'classic-editor',
			)
		);
		$this->assertSame( 'saddle_search_or_slug', $both->get_error_code() );
		$this->assertSame( 'saddle_invalid_slug', $this->search( array( 'slug' => 'Not A Slug!' ) )->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_more_than_ten_results_is_refused() {
		$result = $this->search(
			array(
				'search'   => 'editor',
				'per_page' => 11,
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( array(), $this->requests );
	}
}
