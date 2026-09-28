<?php
/**
 * Accessibility and basic SEO lint (#183): link and button names, vague link
 * text, the title length and a published post's excerpt — through lint-page
 * and into verify-page's score.
 *
 * @package Saddle
 */

class Saddle_Lint_A11y_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function lint( array $post ) {
		$id = self::factory()->post->create( $post + array( 'post_type' => 'page' ) );
		return wp_get_ability( 'saddle/lint-page' )->execute( array( 'post_id' => $id ) );
	}

	/** Violations of one rule, as "address severity". */
	private function of( array $result, $rule ) {
		$out = array();
		foreach ( $result['violations'] as $violation ) {
			if ( $rule === $violation['rule'] ) {
				$out[] = $violation['address'] . ' ' . $violation['severity'];
			}
		}
		return $out;
	}

	private function paragraph( $html ) {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
	}

	public function test_an_unnamed_link_is_an_error_and_named_ones_pass() {
		$result = $this->lint(
			array(
				'post_content' => $this->paragraph( 'Go <a href="/a"></a>' )
					. $this->paragraph( '<a href="/b" aria-label="Open the menu"><svg></svg></a>' )
					. $this->paragraph( '<a href="/c"><img src="x.png" alt="Company logo"/></a>' )
					. $this->paragraph( '<a href="/d">Pricing guide</a>' )
					. $this->paragraph( '<a>placeholder, not a link</a>' ),
			)
		);

		$this->assertSame( array( '0 error' ), $this->of( $result, 'link-text' ) );
	}

	public function test_an_empty_button_is_an_error() {
		$result = $this->lint(
			array(
				'post_content' => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/x"></a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
			)
		);

		$this->assertSame( array( '0.0 error' ), $this->of( $result, 'link-text' ) );
		$this->assertStringContainsString( 'Button', $result['violations'][0]['message'] );
	}

	public function test_vague_link_text_is_a_warning() {
		$result = $this->lint(
			array(
				'post_content' => $this->paragraph( 'Our plans. <a href="/p">Read more…</a>' )
					. $this->paragraph( 'Or <a href="/q">click here</a>.' ),
			)
		);

		$this->assertSame( array( '0 warn', '1 warn' ), $this->of( $result, 'link-text' ) );
	}

	public function test_a_long_title_is_a_page_level_warning() {
		$result = $this->lint(
			array(
				'post_title'   => str_repeat( 'Long title ', 7 ),
				'post_content' => $this->paragraph( 'Body.' ),
			)
		);

		$this->assertSame( array( ' warn' ), $this->of( $result, 'long-title' ) );
	}

	public function test_only_a_published_post_needs_an_excerpt() {
		// The test factory fills post_excerpt unless told otherwise.
		$published = $this->lint(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_content' => $this->paragraph( 'Body.' ),
			)
		);
		$this->assertSame( array( ' warn' ), $this->of( $published, 'missing-excerpt' ) );

		$with_excerpt = $this->lint(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_excerpt' => 'A summary.',
				'post_content' => $this->paragraph( 'Body.' ),
			)
		);
		$draft        = $this->lint(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_excerpt' => '',
				'post_content' => $this->paragraph( 'Body.' ),
			)
		);
		$page         = $this->lint(
			array(
				'post_excerpt' => '',
				'post_content' => $this->paragraph( 'Body.' ),
			)
		);

		$this->assertSame( array(), $this->of( $with_excerpt, 'missing-excerpt' ) );
		$this->assertSame( array(), $this->of( $draft, 'missing-excerpt' ) );
		$this->assertSame( array(), $this->of( $page, 'missing-excerpt' ) );
	}

	public function test_an_unnamed_link_costs_verify_points_until_fixed() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $this->paragraph( 'Go <a href="/a"></a>' ),
			)
		);
		$verify = wp_get_ability( 'saddle/verify-page' );

		$broken = $verify->execute( array( 'post_id' => $id ) );
		$this->assertLessThan( 100, $broken['score'] );

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->paragraph( 'Go <a href="/a">to the pricing guide</a>' ),
			)
		);
		$this->assertSame( 100, $verify->execute( array( 'post_id' => $id ) )['score'] );
	}
}
