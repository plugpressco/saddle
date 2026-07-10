<?php
/**
 * The render engine + the saddle/render-node ability.
 *
 * Pins the agent's eyes (https://github.com/plugpressco/saddle/issues/24):
 * effective styles resolved from persisted attrs, rendered HTML capped and
 * sanitized, whole-page reads bounded to a section outline, and builder
 * pages resolved through the one accessor filter — exactly the lint pattern.
 *
 * @package Saddle
 */

class Saddle_Render_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_render_accessor' );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function page( $content ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
	}

	private function run_ability( array $input ) {
		$ability = wp_get_ability( 'saddle/render-node' );
		$this->assertNotNull( $ability, 'saddle/render-node must be registered.' );
		return $ability->execute( $input );
	}

	private function hero_markup() {
		return '<!-- wp:group {"style":{"color":{"background":"#0a2540"},"spacing":{"padding":{"top":"96px","bottom":"96px"}}}} -->'
			. '<div class="wp-block-group">'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Ship faster</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph {"style":{"color":{"text":"#ffffff"},"typography":{"fontSize":"18px"}}} --><p>Do the thing.</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
			. '<!-- wp:paragraph --><p>Second section.</p><!-- /wp:paragraph -->';
	}

	/* -------- registration + tier -------- */

	public function test_render_node_is_read_tier_and_readonly() {
		$meta = wp_get_ability( 'saddle/render-node' )->get_meta();
		$this->assertSame( 'read', $meta['saddle']['tier'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
	}

	/* -------- one node: styles + html -------- */

	public function test_node_view_resolves_effective_styles_and_renders_html() {
		$id     = $this->page( $this->hero_markup() );
		$result = $this->run_ability(
			array(
				'post_id' => $id,
				'address' => '0.1',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '0.1', $result['address'] );
		$this->assertSame( 'core/paragraph', $result['type'] );
		$this->assertSame( '#ffffff', $result['styles']['color'] );
		$this->assertSame( '18px', $result['styles']['fontSize'] );
		$this->assertStringContainsString( 'Do the thing.', $result['html'] );
		$this->assertSame( 'in-process', $result['fidelity'] );
	}

	public function test_include_styles_only_omits_html() {
		$id     = $this->page( $this->hero_markup() );
		$result = $this->run_ability(
			array(
				'post_id' => $id,
				'address' => '0.1',
				'include' => array( 'styles' ),
			)
		);

		$this->assertArrayHasKey( 'styles', $result );
		$this->assertArrayNotHasKey( 'html', $result );
		$this->assertArrayNotHasKey( 'fidelity', $result );
	}

	public function test_html_is_capped_and_stripped_of_script() {
		$long = str_repeat( 'All work and no play makes an agent a dull tool. ', 200 );
		$id   = $this->page(
			'<!-- wp:paragraph --><p><script>alert(1)</script>' . $long . '</p><!-- /wp:paragraph -->'
		);

		$result = $this->run_ability(
			array(
				'post_id' => $id,
				'address' => '0',
			)
		);

		$this->assertStringNotContainsString( '<script', $result['html'] );
		$this->assertStringContainsString( '[truncated]', $result['html'] );
		$this->assertLessThanOrEqual( Saddle_Render::HTML_CAP + 32, strlen( $result['html'] ) );
	}

	public function test_unknown_address_is_a_clean_error() {
		$id     = $this->page( $this->hero_markup() );
		$result = $this->run_ability(
			array(
				'post_id' => $id,
				'address' => '9.9.9',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_render_no_node', $result->get_error_code() );
	}

	/* -------- whole page: the outline -------- */

	public function test_whole_page_returns_a_bounded_outline() {
		$id     = $this->page( $this->hero_markup() );
		$result = $this->run_ability( array( 'post_id' => $id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'native', $result['builder'] );
		$this->assertSame( 2, $result['count'] );

		$hero = $result['outline'][0];
		$this->assertSame( '0', $hero['address'] );
		$this->assertSame( 'core/group', $hero['type'] );
		$this->assertSame( 2, $hero['children'] );
		$this->assertSame( 'Ship faster', $hero['text'] );
		$this->assertSame( '#0a2540', $hero['styles']['background'] );

		// The outline never carries node HTML — that is what drilling in is for.
		$this->assertArrayNotHasKey( 'html', $hero );
	}

	public function test_single_wrapper_pages_outline_their_children() {
		$id     = $this->page(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:heading --><h2 class="wp-block-heading">A</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);
		$result = $this->run_ability( array( 'post_id' => $id ) );

		$this->assertSame( 2, $result['count'], 'A lone root wrapper outlines its children, like the lint rules.' );
		$this->assertSame( '0.0', $result['outline'][0]['address'] );
	}

	/* -------- builder resolution -------- */

	public function test_builder_page_without_accessor_is_refused() {
		$id     = $this->page( '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' );
		$result = $this->run_ability( array( 'post_id' => $id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_render_unsupported', $result->get_error_code() );
	}

	public function test_builder_page_uses_the_accessor_an_integration_provides() {
		add_filter(
			'saddle_render_accessor',
			static function ( $accessor, $builder ) {
				return 'Divi 5' === $builder ? new Saddle_Render_Gutenberg_Accessor() : $accessor;
			},
			10,
			2
		);

		$id     = $this->page( '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' );
		$result = $this->run_ability( array( 'post_id' => $id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Divi 5', $result['builder'] );
	}

	public function test_missing_post_is_not_found() {
		$result = $this->run_ability( array( 'post_id' => 999999 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_found', $result->get_error_code() );
	}
}
