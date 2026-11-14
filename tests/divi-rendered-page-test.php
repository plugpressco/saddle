<?php
/**
 * saddle/divi-get-rendered-page: the site reads its own signed preview and
 * keeps the content area plus Divi's CSS.
 *
 * The page fixture follows the structure Divi 5.10.1 printed for a preview
 * on WordPress Playground (2026-11-14): Divi's style blocks in the head
 * (customizer, the :root color and font variables, the module design styles
 * forced inline for a preview) and the content inside div.et-l.et-l--post.
 * The request itself is answered through pre_http_request, so these tests
 * also pin which address is asked for.
 *
 * @package Saddle
 */

class Saddle_Divi_Rendered_Page_Test extends WP_UnitTestCase {

	private $requested = array();

	private $answer;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
		$this->answer = $this->page_html();
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		remove_filter( 'saddle_divi_active', '__return_true' );
		parent::tear_down();
	}

	public function answer( $preempt, $args, $url ) {
		$this->requested[] = $url;
		if ( is_wp_error( $this->answer ) ) {
			return $this->answer;
		}
		if ( 'echo' === $this->answer ) {
			// Divi prints the request's own address into a contact form's
			// action and a login form's redirect.
			$region = '<div class="et-l et-l--post"><form class="et_pb_contact_form" method="post" action="' . esc_url( $url ) . '"></form>'
				. '<a class="et_pb_login_link" href="https://example.org/wp-login.php?redirect_to=' . rawurlencode( $url ) . '">Log in</a></div>';
			$body   = str_replace( 'body{font-size:14px}', 'body{background:url(' . $url . ')}', $this->page_html( $region ) );
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		if ( is_int( $this->answer ) ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => $this->answer,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return array(
			'headers'  => array(),
			'body'     => $this->answer,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function page_html( $region = null ) {
		$region = null !== $region ? $region : '<div class="et-l et-l--post">'
			. '<div class="et_builder_inner_content">'
			. '<div class="et_pb_section_0 et_pb_section et_section_regular et_block_section"><div class="et_pb_row_0 et_pb_row et_block_row">'
			. '<div class="et_pb_column_0 et_pb_column et_pb_column_4_4 et-last-child et_block_column">'
			. '<div class="et_pb_heading_0 et_pb_heading et_pb_module et_block_module"><div class="et_pb_heading_container"><h1 class="et_pb_module_header">Rooms that feel like home</h1></div></div>'
			. '<script type="application/json">{"loop":1}</script>'
			. '<div class="et_pb_module et_pb_button_module_wrapper et_pb_button_0_wrapper"><a class="et_pb_button_0 et_pb_button et_block_module" href="#">Book a visit</a></div>'
			. '</div></div></div></div></div>';
		return '<!DOCTYPE html><html><head>'
			. "<link rel='stylesheet' id='divi-style-css' href='https://example.org/style.css' media='all' />"
			. '<style id="wp-block-library-inline-css">.wp-block-button{color:red}</style>'
			. '<style id="et-divi-customizer-preview-cached-inline-styles">body{font-size:14px}.et_pb_section{padding:54px 0}</style>'
			. '<style class="et-vb-global-data et-vb-global-colors">:root{--gcid-primary-color: #2ea3f2;}</style>'
			. '<style id="et-builder-module-design-preview-9-cached-inline-styles">.et_pb_section_0.et_pb_section{padding-top:96px}.et_pb_heading_0 .et_pb_heading_container h1{color:#ffcc00!important;font-size:52px}</style>'
			. '<script>var et = {};</script>'
			. '</head><body><header id="main-header"><div class="container">Site menu</div></header>'
			. '<div id="main-content"><article><div class="entry-content">' . $region . '</div></article></div>'
			. '<footer id="main-footer"><div>Footer text</div></footer></body></html>';
	}

	private function run_tool( array $input ) {
		return wp_get_ability( 'saddle/divi-get-rendered-page' )->execute( $input );
	}

	/* -------- extraction -------- */

	public function test_keeps_the_content_area_and_divis_css_only() {
		$out = Saddle_Divi_Rendered_Page::extract( $this->page_html() );

		$this->assertSame( 'et-l--post', $out['region'] );
		$this->assertStringStartsWith( '<div class="et-l et-l--post">', $out['html'] );
		$this->assertStringEndsWith( '</div>', $out['html'] );
		$this->assertStringContainsString( 'Rooms that feel like home', $out['html'] );
		$this->assertStringContainsString( 'et_pb_button_0', $out['html'], 'The tag balancing keeps the whole region.' );
		$this->assertStringNotContainsString( 'Site menu', $out['html'] );
		$this->assertStringNotContainsString( 'Footer text', $out['html'] );
		$this->assertStringNotContainsString( '<script', $out['html'] );
		$this->assertSame( array( 'scripts' => 1 ), $out['removed'] );

		$this->assertSame(
			array( 'et-divi-customizer-preview-cached-inline-styles', 'et-vb-global-data et-vb-global-colors', 'et-builder-module-design-preview-9-cached-inline-styles' ),
			wp_list_pluck( $out['css'], 'source' ),
			'Divi\'s blocks in page order; WordPress\'s own block CSS is left out.'
		);
		$this->assertStringContainsString( 'color:#ffcc00', $out['css'][2]['css'] );
		$this->assertSame( array( 'divi-style-css' ), $out['linked_stylesheets'] );
		$this->assertFalse( $out['html_truncated'] );
		$this->assertFalse( $out['css_truncated'] );
	}

	public function test_caps_cut_at_boundaries_and_module_styles_come_first() {
		$big = '<div class="et-l et-l--post">' . str_repeat( '<p class="x">' . str_repeat( 'word ', 40 ) . '</p>', 60 ) . '</div>';
		$out = Saddle_Divi_Rendered_Page::extract( $this->page_html( $big ), 1, 1 );
		$this->assertTrue( $out['html_truncated'] );
		$this->assertLessThanOrEqual( 1024, strlen( $out['html'] ) );
		$this->assertStringEndsWith( '>', $out['html'] );
		$this->assertGreaterThan( 10, $out['html_kb'] );

		$css = str_repeat( '.a{color:red}', 100 );
		$out = Saddle_Divi_Rendered_Page::extract(
			str_replace( 'body{font-size:14px}', $css, $this->page_html() ),
			48,
			1
		);
		$this->assertTrue( $out['css_truncated'] );
		$sources = wp_list_pluck( $out['css'], 'source' );
		$this->assertContains( 'et-builder-module-design-preview-9-cached-inline-styles', $sources, 'The page\'s own styles get the budget first.' );
		foreach ( $out['css'] as $block ) {
			$this->assertStringEndsWith( '}', $block['css'] );
		}
	}

	public function test_falls_back_to_entry_content_and_refuses_without_a_region() {
		$plain = $this->page_html( '<p>Classic content.</p>' );
		$out   = Saddle_Divi_Rendered_Page::extract( $plain );
		$this->assertSame( 'entry-content', $out['region'] );
		$this->assertStringContainsString( 'Classic content.', $out['html'] );

		$none = Saddle_Divi_Rendered_Page::extract( '<html><body><p>Nothing here</p></body></html>' );
		$this->assertWPError( $none );
		$this->assertSame( 'saddle_render_no_region', $none->get_error_code() );
	}

	/* -------- the ability -------- */

	public function test_reads_a_draft_through_its_own_signed_preview() {
		$id  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$out = $this->run_tool( array( 'post_id' => $id ) );

		$this->assertNotWPError( $out );
		$this->assertSame( $id, $out['id'] );
		$this->assertSame( 'et-l--post', $out['region'] );
		$this->assertCount( 1, $this->requested, 'One request, to the site itself.' );
		$url = $this->requested[0];
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $url, PHP_URL_HOST ) );
		$this->assertStringContainsString( 'preview=1', $url );
		$this->assertStringContainsString( Saddle_Preview::QUERY_ARG . '=', $url );
		$this->assertArrayNotHasKey( 'url', $out, 'The signed address never leaves the request.' );
	}

	public function test_a_published_post_is_asked_for_at_its_permalink() {
		$this->set_permalink_structure( '/%postname%/' );
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'about-us',
			)
		);
		$this->assertNotWPError( $this->run_tool( array( 'post_id' => $id ) ) );
		$this->assertStringContainsString( '/about-us/', $this->requested[0], 'The canonical redirect would drop preview=1 from a page_id link.' );
		$this->assertStringContainsString( 'preview=1', $this->requested[0] );
		$this->assertStringNotContainsString( 'page_id', $this->requested[0] );
	}

	public function test_a_blocked_request_to_itself_is_an_honest_error() {
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$out          = $this->run_tool( array( 'post_id' => $id ) );
		$this->assertWPError( $out );
		$this->assertSame( 'saddle_render_unreachable', $out->get_error_code() );
		$this->assertStringContainsString( 'get-preview-url', $out->get_error_message() );
		$this->assertStringContainsString( 'timed out', $out->get_error_message() );

		$this->answer = 403;
		$out          = $this->run_tool( array( 'post_id' => $id ) );
		$this->assertSame( 'saddle_render_unreachable', $out->get_error_code() );
		$this->assertStringContainsString( '403', $out->get_error_message() );
	}

	public function test_a_draft_needs_edit_rights_and_divi() {
		$draft = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$out = $this->run_tool( array( 'post_id' => $draft ) );
		$this->assertWPError( $out );
		$this->assertSame( array(), $this->requested, 'Refused before any request.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_filter( 'saddle_divi_active', '__return_true' );
		add_filter( 'saddle_divi_active', '__return_false' );
		$out = $this->run_tool( array( 'post_id' => $draft ) );
		remove_filter( 'saddle_divi_active', '__return_false' );
		$this->assertSame( 'saddle_no_divi', $out->get_error_code() );
	}

	public function test_the_signed_token_is_taken_out_of_the_returned_page() {
		$id           = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$this->answer = 'echo';

		$out = $this->run_tool( array( 'post_id' => $id ) );

		$this->assertNotWPError( $out );
		parse_str( (string) wp_parse_url( $this->requested[0], PHP_URL_QUERY ), $sent );
		$token = $sent[ Saddle_Preview::QUERY_ARG ];
		$all   = $out['html'] . wp_json_encode( $out['css'] );
		$this->assertStringNotContainsString( $token, $all );
		$this->assertStringNotContainsString( Saddle_Preview::QUERY_ARG, $all );
		$this->assertStringNotContainsString( 'preview=1', $all );
		$this->assertStringNotContainsString( 'preview%3D1', $all );
		$this->assertStringContainsString( 'action="' . esc_url( home_url( '/?page_id=' . $id ) ) . '"', $out['html'], 'The rest of the address stays.' );
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( home_url( '/?page_id=' . $id ) ) . '"', $out['html'] );
	}

	public function test_scrub_handles_every_argument_position() {
		$token = '1700000000.abc123';
		$cases = array(
			'https://e.org/?preview=1&saddle_preview=' . $token . '&p=5' => 'https://e.org/?p=5',
			'https://e.org/a/?preview=1&#038;saddle_preview=' . $token    => 'https://e.org/a/',
			'https://e.org/?p=5&amp;preview=1&amp;saddle_preview=' . $token . '#top' => 'https://e.org/?p=5#top',
			'x?r=https%3A%2F%2Fe.org%2F%3Fpreview%3D1%26saddle_preview%3D' . rawurlencode( $token ) . '%26p%3D5' => 'x?r=https%3A%2F%2Fe.org%2F%3Fp%3D5',
		);
		foreach ( $cases as $in => $want ) {
			$this->assertSame( '<a href="' . $want . '">', Saddle_Divi_Rendered_Page::scrub( '<a href="' . $in . '">', $token ), $in );
		}
		$this->assertSame( '<p>preview=1 is text</p>', Saddle_Divi_Rendered_Page::scrub( '<p>preview=1 is text</p>', $token ), 'Text without the token is left alone.' );
	}

	public function test_every_call_needs_edit_rights_on_the_post() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertTrue( current_user_can( 'read_post', $id ) );

		$out = $this->run_tool( array( 'post_id' => $id ) );

		$this->assertWPError( $out );
		$this->assertSame( 'saddle_forbidden', $out->get_error_code() );
		$this->assertSame( array(), $this->requested, 'Refused before any request.' );
	}

	public function test_one_call_at_a_time_per_account() {
		$id   = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$lock = Saddle_Divi_Extra_Abilities::RENDER_LOCK . get_current_user_id();

		set_transient( $lock, 1, 60 );
		$busy = $this->run_tool( array( 'post_id' => $id ) );
		$this->assertWPError( $busy );
		$this->assertSame( 'saddle_render_busy', $busy->get_error_code() );
		$this->assertSame( array(), $this->requested );
		delete_transient( $lock );

		$this->assertNotWPError( $this->run_tool( array( 'post_id' => $id ) ) );
		$this->assertFalse( get_transient( $lock ), 'The lock is released after a call.' );

		$this->answer = new WP_Error( 'http_request_failed', 'down' );
		$this->assertWPError( $this->run_tool( array( 'post_id' => $id ) ) );
		$this->assertFalse( get_transient( $lock ), 'and after a call that failed.' );
	}

	public function test_registered_as_a_read_tool() {
		$ability = wp_get_ability( 'saddle/divi-get-rendered-page' );
		$this->assertSame( 'read', $ability->get_meta()['saddle']['tier'] );
		$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
		$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );

		wp_set_current_user( 0 );
		$this->assertFalse( $ability->check_permissions( array( 'post_id' => 1 ) ), 'A visitor with no account is refused.' );
	}
}
