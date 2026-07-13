<?php
/**
 * Unsplash abilities — search/import through the real ability path, the HTTP
 * error matrix, the dedupe-before-download behavior (#60), download-tracking
 * guideline compliance, and the settings REST key handling (which must never
 * leak the key).
 *
 * All outbound HTTP is mocked via `pre_http_request` and recorded, so every
 * test can also assert what was — and, for dedupe, was NOT — requested.
 *
 * @package Saddle
 */

class Saddle_Unsplash_Test extends WP_UnitTestCase {

	const KEY = 'testAccessKey_1234567890abcdef';

	/**
	 * Recorded outbound requests: [ [ 'url' => …, 'args' => … ], … ].
	 *
	 * @var array
	 */
	private $requests = array();

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		// The SSRF guard fails closed when DNS is unavailable — the documented
		// trusted-environment escape hatch keeps these tests deterministic.
		add_filter( 'saddle_source_url_is_safe', '__return_true' );
	}

	public function tear_down() {
		delete_option( Saddle_Unsplash::OPTION );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'saddle_source_url_is_safe' );
		$this->requests = array();
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	/**
	 * Install a recording HTTP mock. $fixtures maps a URL substring to either a
	 * canned response array or a callable( $args, $url ). First match wins, in
	 * insertion order; anything unmatched errors loudly.
	 *
	 * @param array $fixtures URL-substring => response|callable.
	 */
	private function mock_http( array $fixtures = array() ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $fixtures ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				foreach ( $fixtures as $needle => $fixture ) {
					if ( false !== strpos( $url, $needle ) ) {
						return is_callable( $fixture ) ? $fixture( $args, $url ) : $fixture;
					}
				}
				return new WP_Error( 'unexpected_http', 'Unexpected HTTP request to ' . $url );
			},
			10,
			3
		);
	}

	private static function json_response( $code, $body, $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
		);
	}

	private function requested_urls( $needle = '' ) {
		$urls = wp_list_pluck( $this->requests, 'url' );
		if ( '' === $needle ) {
			return $urls;
		}
		return array_values(
			array_filter(
				$urls,
				static function ( $url ) use ( $needle ) {
					return false !== strpos( $url, $needle );
				}
			)
		);
	}

	private static function photo_fixture( $id = 'PHOTO123' ) {
		return array(
			'id'              => $id,
			'description'     => null,
			'alt_description' => 'A misty mountain at sunrise',
			'width'           => 4000,
			'height'          => 3000,
			'urls'            => array(
				'raw'   => 'https://images.unsplash.com/photo-' . $id . '?ixid=fixture',
				'full'  => 'https://images.unsplash.com/photo-' . $id . '?ixid=fixture&q=85',
				'small' => 'https://images.unsplash.com/photo-' . $id . '?ixid=fixture&w=400',
			),
			'links'           => array(
				'download_location' => 'https://api.unsplash.com/photos/' . $id . '/download?ixid=fixture',
				'html'              => 'https://unsplash.com/photos/' . $id . '-page',
			),
			'user'            => array(
				'name'  => 'Jane Photographer',
				'links' => array( 'html' => 'https://unsplash.com/@janephoto' ),
			),
			'tags'            => array(
				array(
					'type'  => 'search',
					'title' => 'mountain',
				),
				array(
					'type'  => 'landing_page',
					'title' => 'nature',
				),
				array(
					'type'  => 'search',
					'title' => 'sunrise',
				),
			),
		);
	}

	/**
	 * Fixture callable for the image file itself: download_url() streams to
	 * $args['filename'], so the mock must write real JPEG bytes there.
	 */
	private static function image_stream_fixture() {
		return static function ( $args ) {
			$jpeg = base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Embedded 1x1 JPEG test fixture.
				'/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q=='
			);
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $jpeg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing to download_url()'s own temp file.
			}
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
			);
		};
	}

	/**
	 * Fixtures for a complete, successful import of PHOTO123. The download
	 * ping needle is listed before the photo-detail needle on purpose — both
	 * substrings occur in the ping URL and first match wins.
	 */
	private function import_fixtures() {
		return array(
			'/photos/PHOTO123/download' => self::json_response( 200, array( 'url' => 'https://images.unsplash.com/photo-PHOTO123' ) ),
			'/photos/PHOTO123'          => self::json_response( 200, self::photo_fixture() ),
			'images.unsplash.com'       => self::image_stream_fixture(),
		);
	}

	/* -------- no key configured -------- */

	public function test_search_without_key_errors_actionably_and_makes_no_http() {
		Saddle_Capabilities::set_tier( 'read' );
		$this->mock_http();

		$result = $this->ability( 'saddle/unsplash-search' )->execute( array( 'query' => 'mountains' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unsplash_not_configured', $result->get_error_code() );
		$this->assertStringContainsString( 'unsplash.com/developers', $result->get_error_message() );
		$this->assertCount( 0, $this->requests, 'No key must mean no outbound HTTP at all.' );
	}

	/* -------- search -------- */

	public function test_search_returns_trimmed_results_and_sends_client_id() {
		Saddle_Capabilities::set_tier( 'read' );
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http(
			array(
				'/search/photos' => self::json_response(
					200,
					array(
						'total'       => 1,
						'total_pages' => 1,
						'results'     => array( self::photo_fixture() ),
					)
				),
			)
		);

		$result = $this->ability( 'saddle/unsplash-search' )->execute( array( 'query' => 'mountains' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['results'] );

		$hit = $result['results'][0];
		$this->assertSame( 'PHOTO123', $hit['id'] );
		$this->assertSame( 'A misty mountain at sunrise', $hit['alt_description'] );
		$this->assertSame( 'Jane Photographer', $hit['photographer'] );
		$this->assertStringContainsString( 'utm_source=saddle', $hit['photographer_url'] );
		$this->assertFalse( $hit['in_library'] );
		$this->assertNull( $hit['media_id'] );
		$this->assertArrayNotHasKey( 'urls', $hit, 'Raw Unsplash payload must not leak to the agent.' );
		$this->assertArrayNotHasKey( 'links', $hit );

		$this->assertSame( 'Client-ID ' . self::KEY, $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_search_clamps_per_page_to_thirty() {
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http(
			array(
				'/search/photos' => self::json_response(
					200,
					array(
						'total'       => 0,
						'total_pages' => 0,
						'results'     => array(),
					)
				),
			)
		);

		Saddle_Unsplash_Abilities::search(
			array(
				'query'    => 'x',
				'per_page' => 100,
			)
		);

		$this->assertStringContainsString( 'per_page=30', $this->requests[0]['url'] );
	}

	public function test_search_flags_photos_already_in_the_library() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http( $this->import_fixtures() );

		$imported = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );
		$this->assertNotWPError( $imported );

		remove_all_filters( 'pre_http_request' );
		$this->mock_http(
			array(
				'/search/photos' => self::json_response(
					200,
					array(
						'total'       => 2,
						'total_pages' => 1,
						'results'     => array( self::photo_fixture(), self::photo_fixture( 'OTHER456' ) ),
					)
				),
			)
		);

		$result = $this->ability( 'saddle/unsplash-search' )->execute( array( 'query' => 'mountains' ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['results'][0]['in_library'], 'An already-imported photo must be flagged.' );
		$this->assertSame( $imported['id'], $result['results'][0]['media_id'] );
		$this->assertFalse( $result['results'][1]['in_library'] );
	}

	/* -------- HTTP error matrix -------- */

	public function test_invalid_key_maps_to_agent_actionable_error() {
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http( array( 'api.unsplash.com' => self::json_response( 401, array( 'errors' => array( 'OAuth error' ) ) ) ) );

		$result = Saddle_Unsplash::request( '/search/photos', array( 'query' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unsplash_invalid_key', $result->get_error_code() );
	}

	public function test_rate_limit_maps_to_do_not_retry_error() {
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http(
			array(
				'api.unsplash.com' => self::json_response(
					403,
					array( 'errors' => array( 'Rate Limit Exceeded' ) ),
					array( 'x-ratelimit-remaining' => '0' )
				),
			)
		);

		$result = Saddle_Unsplash::request( '/search/photos', array( 'query' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unsplash_rate_limited', $result->get_error_code() );
		$this->assertStringContainsString( 'do NOT retry', $result->get_error_message() );
	}

	public function test_transport_failure_maps_to_unreachable() {
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http(); // Everything unmatched returns a WP_Error.

		$result = Saddle_Unsplash::request( '/search/photos', array( 'query' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unsplash_unreachable', $result->get_error_code() );
	}

	/* -------- import -------- */

	public function test_import_sideloads_attributes_and_pings_download_tracking() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http( $this->import_fixtures() );

		$before = Saddle_Log::query( 100, 1 )['total'];
		$result = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'id', $result );
		$id = (int) $result['id'];

		// Provenance + defaults.
		$this->assertSame( 'PHOTO123', get_post_meta( $id, Saddle_Unsplash::META_ID, true ) );
		$this->assertSame( 'Jane Photographer', get_post_meta( $id, Saddle_Unsplash::META_PHOTOGRAPHER, true ) );
		$this->assertSame( 'A misty mountain at sunrise', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'A misty mountain at sunrise', get_post( $id )->post_title, 'Null description must fall back to alt_description.' );

		// Attribution caption with the UTM params Unsplash requires.
		$caption = get_post( $id )->post_excerpt;
		$this->assertStringContainsString( 'Jane Photographer', $caption );
		$this->assertStringContainsString( 'on <a', $caption );
		$this->assertStringContainsString( 'utm_source=saddle', $caption );

		// Unsplash guideline: the download_location ping must have fired.
		$this->assertCount( 1, $this->requested_urls( '/photos/PHOTO123/download' ), 'The download-tracking endpoint must be requested exactly once.' );

		// The file fetch asked for a bounded, decodable rendition.
		$file_requests = $this->requested_urls( 'images.unsplash.com' );
		$this->assertCount( 1, $file_requests );
		$this->assertStringContainsString( 'fm=jpg', $file_requests[0] );

		// Executed mutation is logged.
		$this->assertSame( $before + 1, Saddle_Log::query( 100, 1 )['total'] );
	}

	public function test_import_dedupes_without_force_and_makes_zero_http() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http( $this->import_fixtures() );

		$first = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );
		$this->assertNotWPError( $first );

		$this->requests = array();
		$again          = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );

		$this->assertNotWPError( $again );
		$this->assertSame( $first['id'], $again['id'], 'The existing attachment must be reused.' );
		$this->assertTrue( $again['already_in_library'] );
		$this->assertCount( 0, $this->requests, 'A dedupe hit must download nothing and call nothing.' );
	}

	public function test_import_with_force_downloads_a_fresh_copy() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http( $this->import_fixtures() );

		$first = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );
		$this->assertNotWPError( $first );

		$this->requests = array();
		$forced         = $this->ability( 'saddle/unsplash-import' )->execute(
			array(
				'photo_id' => 'PHOTO123',
				'force'    => true,
			)
		);

		$this->assertNotWPError( $forced );
		$this->assertNotSame( $first['id'], $forced['id'], 'force=true must create a new attachment.' );
		$this->assertNotEmpty( $this->requests, 'force=true must actually re-download.' );
	}

	public function test_import_denied_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http();

		$result = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );

		$this->assertWPError( $result, 'A write ability must be denied while the site is read-only.' );
		$this->assertCount( 0, $this->requests );
	}

	public function test_find_existing_many_is_not_shadowed_by_forced_duplicates() {
		// Three copies of one photo (as force=true creates) must not crowd a
		// different photo id out of the lookup and mislabel it as absent.
		foreach ( range( 1, 3 ) as $i ) {
			$dupe = self::factory()->attachment->create_object( "unsplash-dupe-{$i}.jpg", 0, array( 'post_mime_type' => 'image/jpeg' ) );
			update_post_meta( $dupe, Saddle_Unsplash::META_ID, 'PHOTO123' );
		}
		$other = self::factory()->attachment->create_object( 'unsplash-other.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $other, Saddle_Unsplash::META_ID, 'OTHER456' );

		$map = Saddle_Unsplash::find_existing_many( array( 'PHOTO123', 'OTHER456' ) );

		$this->assertArrayHasKey( 'PHOTO123', $map );
		$this->assertSame( $other, $map['OTHER456'], 'A photo id must be found even when another id has many duplicates.' );
	}

	/* -------- media tags taxonomy + provenance -------- */

	public function test_media_tag_taxonomy_registered_for_attachments_only() {
		$this->assertTrue( taxonomy_exists( Saddle_Unsplash::TAXONOMY ) );

		$tax = get_taxonomy( Saddle_Unsplash::TAXONOMY );
		$this->assertSame( array( 'attachment' ), $tax->object_type );
		$this->assertFalse( $tax->hierarchical );
		$this->assertFalse( is_taxonomy_viewable( $tax ), 'No front-end archives may exist for media tags.' );
		$this->assertTrue( $tax->show_admin_column );
		$this->assertFalse( $tax->show_in_rest );
		$this->assertSame( '_update_generic_term_count', $tax->update_count_callback, 'Unattached (inherit-status) media must still count.' );
	}

	public function test_term_count_counts_unattached_attachments() {
		$att = self::factory()->attachment->create_object( 'count.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_set_object_terms( $att, array( 'countable' ), Saddle_Unsplash::TAXONOMY );

		$term = get_term_by( 'name', 'countable', Saddle_Unsplash::TAXONOMY );
		$this->assertSame( 1, $term->count, 'The generic counter must count unattached inherit-status attachments.' );
	}

	public function test_import_auto_tags_and_stores_provenance() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http( $this->import_fixtures() );

		$result = $this->ability( 'saddle/unsplash-import' )->execute(
			array(
				'photo_id' => 'PHOTO123',
				'tags'     => array( ' alpine hero ', '<script>x</script>hero', 'Mountain' ),
			)
		);
		$this->assertNotWPError( $result );
		$id = (int) $result['id'];

		// Agent tags survive (sanitized: script tags AND their content are
		// stripped), Unsplash's own tags added, and the case-insensitive
		// duplicate folded — first spelling wins ("Mountain" over "mountain").
		$names = wp_get_object_terms( $id, Saddle_Unsplash::TAXONOMY, array( 'fields' => 'names' ) );
		sort( $names );
		$this->assertSame( array( 'Mountain', 'alpine hero', 'hero', 'nature', 'sunrise' ), $names );

		// Provenance URLs stored from the photo detail.
		$this->assertSame( 'https://unsplash.com/photos/PHOTO123-page', get_post_meta( $id, Saddle_Unsplash::META_URL, true ) );
		$this->assertSame( 'https://unsplash.com/@janephoto', get_post_meta( $id, Saddle_Unsplash::META_PHOTOGRAPHER_URL, true ) );
	}

	public function test_import_caps_unsplash_tags_at_eight() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Unsplash::set_key( self::KEY );

		$photo         = self::photo_fixture();
		$photo['tags'] = array();
		foreach ( range( 1, 10 ) as $i ) {
			$photo['tags'][] = array( 'title' => "topic-{$i}" );
		}
		$this->mock_http(
			array(
				'/photos/PHOTO123/download' => self::json_response( 200, array( 'url' => 'x' ) ),
				'/photos/PHOTO123'          => self::json_response( 200, $photo ),
				'images.unsplash.com'       => self::image_stream_fixture(),
			)
		);

		$result = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );
		$this->assertNotWPError( $result );

		$names = wp_get_object_terms( (int) $result['id'], Saddle_Unsplash::TAXONOMY, array( 'fields' => 'names' ) );
		$this->assertCount( 8, $names, 'Unsplash-derived tags must cap at eight.' );
	}

	public function test_import_without_tags_key_sets_no_terms() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Unsplash::set_key( self::KEY );

		$photo = self::photo_fixture();
		unset( $photo['tags'] );
		$this->mock_http(
			array(
				'/photos/PHOTO123/download' => self::json_response( 200, array( 'url' => 'x' ) ),
				'/photos/PHOTO123'          => self::json_response( 200, $photo ),
				'images.unsplash.com'       => self::image_stream_fixture(),
			)
		);

		$result = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( array(), wp_get_object_terms( (int) $result['id'], Saddle_Unsplash::TAXONOMY, array( 'fields' => 'names' ) ) );
	}

	public function test_dedupe_import_appends_agent_tags_with_zero_http() {
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Unsplash::set_key( self::KEY );
		$this->mock_http( $this->import_fixtures() );

		$first = $this->ability( 'saddle/unsplash-import' )->execute( array( 'photo_id' => 'PHOTO123' ) );
		$this->assertNotWPError( $first );

		$this->requests = array();
		$again          = $this->ability( 'saddle/unsplash-import' )->execute(
			array(
				'photo_id' => 'PHOTO123',
				'tags'     => array( 'homepage hero' ),
			)
		);

		$this->assertNotWPError( $again );
		$this->assertTrue( $again['already_in_library'] );
		$this->assertCount( 0, $this->requests, 'A dedupe hit must stay HTTP-free even when tags are added.' );

		$names = wp_get_object_terms( (int) $first['id'], Saddle_Unsplash::TAXONOMY, array( 'fields' => 'names' ) );
		$this->assertContains( 'homepage hero', $names );
		$this->assertContains( 'mountain', $names, 'Original import tags must survive the append.' );
	}

	public function test_attachment_details_show_escaped_unsplash_provenance() {
		$att = self::factory()->attachment->create_object( 'prov.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $att, Saddle_Unsplash::META_ID, 'PHOTOZ' );
		update_post_meta( $att, Saddle_Unsplash::META_PHOTOGRAPHER, 'Jane <script>alert(1)</script>' );
		update_post_meta( $att, Saddle_Unsplash::META_PHOTOGRAPHER_URL, 'https://unsplash.com/@janephoto' );

		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $att ) );

		$this->assertArrayHasKey( 'saddle_unsplash', $fields );
		$this->assertSame( 'html', $fields['saddle_unsplash']['input'] );
		$this->assertTrue( $fields['saddle_unsplash']['show_in_modal'] );
		$html = $fields['saddle_unsplash']['html'];
		$this->assertStringContainsString( 'PHOTOZ', $html );
		$this->assertStringContainsString( 'View on Unsplash', $html );
		$this->assertStringNotContainsString( '<script>', $html, 'Photographer names must be escaped — core echoes this html raw.' );
	}

	public function test_attachment_details_untouched_for_non_unsplash_media() {
		$att = self::factory()->attachment->create_object( 'plainer.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );

		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $att ) );

		$this->assertArrayNotHasKey( 'saddle_unsplash', $fields );
	}

	public function test_source_column_renders_link_or_dash() {
		$columns = apply_filters( 'manage_media_columns', array( 'date' => 'Date' ) );
		$this->assertArrayHasKey( 'saddle_source', $columns );

		$import = self::factory()->attachment->create_object( 'src.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $import, Saddle_Unsplash::META_ID, 'PHOTOX' );
		update_post_meta( $import, Saddle_Unsplash::META_PHOTOGRAPHER, 'Jane Photographer' );
		update_post_meta( $import, Saddle_Unsplash::META_URL, 'https://unsplash.com/photos/PHOTOX-page' );

		ob_start();
		Saddle_Unsplash::render_source_column( 'saddle_source', $import );
		$cell = ob_get_clean();
		$this->assertStringContainsString( 'Unsplash', $cell );
		$this->assertStringContainsString( 'Jane Photographer', $cell );
		$this->assertStringContainsString( 'PHOTOX-page', $cell );

		$plain = self::factory()->attachment->create_object( 'plain2.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		ob_start();
		Saddle_Unsplash::render_source_column( 'saddle_source', $plain );
		$this->assertStringContainsString( '&#8212;', ob_get_clean() );
	}

	public function test_photo_page_url_falls_back_to_id_for_legacy_imports() {
		$att = self::factory()->attachment->create_object( 'legacy.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $att, Saddle_Unsplash::META_ID, 'LEGACY1' );

		$url = Saddle_Unsplash::photo_page_url( $att );

		$this->assertStringContainsString( 'unsplash.com/photos/LEGACY1', $url );
		$this->assertStringContainsString( 'utm_source=saddle', $url );
		$this->assertSame( '', Saddle_Unsplash::photo_page_url( 0 ), 'Non-imports must return an empty string.' );
	}

	public function test_list_media_tag_filter_matches_case_insensitively() {
		Saddle_Capabilities::set_tier( 'read' );
		$tagged = self::factory()->attachment->create_object( 'tagged.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$other  = self::factory()->attachment->create_object( 'other.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_set_object_terms( $tagged, array( 'misty mountain' ), Saddle_Unsplash::TAXONOMY );

		$result = $this->ability( 'saddle/list-media' )->execute( array( 'tag' => 'Misty Mountain' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $tagged, $result['items'][0]['id'] );
		$this->assertSame( array( 'misty mountain' ), $result['items'][0]['tags'] );
		$this->assertNotSame( $other, $result['items'][0]['id'] );
	}

	public function test_media_summary_omits_tags_when_untagged() {
		Saddle_Capabilities::set_tier( 'read' );
		self::factory()->attachment->create_object( 'untagged.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );

		$result = $this->ability( 'saddle/list-media' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'tags', $result['items'][0], 'Untagged media must not carry an empty tags key.' );
	}

	/* -------- list-media source filter -------- */

	public function test_list_media_source_unsplash_lists_only_imports() {
		Saddle_Capabilities::set_tier( 'read' );
		$plain    = self::factory()->attachment->create_object( 'plain.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$imported = self::factory()->attachment->create_object( 'unsplash-x.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $imported, Saddle_Unsplash::META_ID, 'PHOTOX' );

		$result = $this->ability( 'saddle/list-media' )->execute( array( 'source' => 'unsplash' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total'], 'Only the Unsplash import must be counted.' );
		$this->assertSame( $imported, $result['items'][0]['id'] );
		$this->assertNotSame( $plain, $result['items'][0]['id'] );
	}

	/* -------- settings REST: the key never leaks -------- */

	private function post_settings( array $body ) {
		$req = new WP_REST_Request( 'POST', '/saddle/v1/settings' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return Saddle_REST_Admin::update_settings( $req );
	}

	public function test_settings_set_mask_omit_and_clear_semantics() {
		// Unconfigured.
		$data = Saddle_REST_Admin::get_settings()->get_data();
		$this->assertFalse( $data['unsplash']['configured'] );
		$this->assertSame( '', $data['unsplash']['key_hint'] );

		// Set: configured, hinted, never echoed back.
		$res = $this->post_settings( array( 'unsplash_access_key' => self::KEY ) );
		$this->assertNotWPError( $res );
		$data = $res->get_data();
		$this->assertTrue( $data['unsplash']['configured'] );
		$this->assertSame( substr( self::KEY, -4 ), $data['unsplash']['key_hint'] );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $data ), 'The full key must never appear in a REST response.' );
		$this->assertSame( self::KEY, get_option( Saddle_Unsplash::OPTION ) );

		// Omitted from the body: untouched.
		$res = $this->post_settings( array( 'paused' => false ) );
		$this->assertNotWPError( $res );
		$this->assertSame( self::KEY, get_option( Saddle_Unsplash::OPTION ) );

		// Empty string: cleared.
		$res = $this->post_settings( array( 'unsplash_access_key' => '' ) );
		$this->assertNotWPError( $res );
		$this->assertFalse( get_option( Saddle_Unsplash::OPTION, false ) );
		$this->assertFalse( $res->get_data()['unsplash']['configured'] );
	}

	public function test_settings_rejects_malformed_key() {
		$res = $this->post_settings( array( 'unsplash_access_key' => 'nope!' ) );

		$this->assertWPError( $res );
		$this->assertSame( 'saddle_invalid_unsplash_key', $res->get_error_code() );
		$this->assertFalse( get_option( Saddle_Unsplash::OPTION, false ) );
	}
}
