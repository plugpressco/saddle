<?php
/**
 * saddle/upload-media with the file sent inline as base64 (#220).
 *
 * A write-capable client with no public URL for its file (a generated image,
 * one on the user's computer) was stranded: upload-media only took
 * source_url. These drive the ability end to end; the bytes go through core's
 * own attachment controller, so the content checks are core's.
 *
 * @package Saddle
 */

class Saddle_Upload_Media_Inline_Test extends WP_UnitTestCase {

	/**
	 * A valid 1x1 PNG.
	 */
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * Attachments created, so their files leave the uploads dir too.
	 *
	 * @var int[]
	 */
	private $created = array();

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
	}

	public function tear_down() {
		foreach ( $this->created as $id ) {
			wp_delete_attachment( $id, true );
		}
		delete_option( Saddle_Capabilities::OPTION );
		remove_all_filters( 'saddle_max_upload_bytes' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function upload( array $input ) {
		$result = wp_get_ability( 'saddle/upload-media' )->execute( $input );
		if ( is_array( $result ) && isset( $result['id'] ) ) {
			$this->created[] = (int) $result['id'];
		}
		return $result;
	}

	public function test_an_inline_png_lands_in_the_library() {
		$result = $this->upload(
			array(
				'data'     => self::PNG,
				'filename' => 'pixel.png',
				'alt'      => 'A single pixel',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'A single pixel', get_post_meta( $result['id'], '_wp_attachment_image_alt', true ) );
		$this->assertFileExists( get_attached_file( $result['id'] ) );
	}

	public function test_a_data_uri_prefix_is_accepted() {
		$result = $this->upload(
			array(
				'data'     => 'data:image/png;base64,' . self::PNG,
				'filename' => 'pixel.png',
			)
		);

		$this->assertNotWPError( $result );
	}

	public function test_it_attaches_to_a_post_the_caller_can_edit() {
		$post = self::factory()->post->create();

		$result = $this->upload(
			array(
				'data'     => self::PNG,
				'filename' => 'pixel.png',
				'post_id'  => $post,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $post, (int) get_post( $result['id'] )->post_parent );
	}

	/**
	 * The content check is core's, and it is the one that matters: a PHP
	 * payload with an image extension must never reach the uploads dir as
	 * that image.
	 */
	public function test_core_refuses_content_that_does_not_match_the_extension() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building the test payload.
		$result = $this->upload(
			array(
				'data'     => base64_encode( '<?php echo "not an image";' ),
				'filename' => 'pixel.png',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_upload_failed', $result->get_error_code() );
	}

	public function test_a_type_the_site_does_not_allow_is_refused_before_upload() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building the test payload.
		$result = $this->upload(
			array(
				'data'     => base64_encode( '<?php phpinfo();' ),
				'filename' => 'shell.php',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_file_type_not_allowed', $result->get_error_code() );
	}

	public function test_bad_input_is_refused_with_a_reason() {
		$this->assertSame( 'saddle_missing_filename', $this->upload( array( 'data' => self::PNG ) )->get_error_code() );
		$this->assertSame(
			'saddle_invalid_data',
			$this->upload(
				array(
					'data'     => 'not base64 !!',
					'filename' => 'pixel.png',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'saddle_missing_source_url',
			$this->upload(
				array(
					'data'       => self::PNG,
					'filename'   => 'pixel.png',
					'source_url' => 'https://example.com/pixel.png',
				)
			)->get_error_code(),
			'Exactly one of source_url or data.'
		);
		$this->assertSame( 'saddle_missing_source_url', $this->upload( array() )->get_error_code() );
	}

	public function test_the_size_cap_applies_to_inline_files() {
		add_filter(
			'saddle_max_upload_bytes',
			static function () {
				return 10;
			}
		);

		$result = $this->upload(
			array(
				'data'     => self::PNG,
				'filename' => 'pixel.png',
			)
		);

		$this->assertSame( 'saddle_file_too_large', $result->get_error_code() );
	}

	public function test_it_is_refused_below_the_write_tier() {
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertWPError(
			$this->upload(
				array(
					'data'     => self::PNG,
					'filename' => 'pixel.png',
				)
			)
		);
	}
}
