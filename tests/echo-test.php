<?php
/**
 * The applied-vs-ignored echo on Gutenberg writes.
 *
 * What must hold: an attribute the editor will never act on — an unknown
 * attribute key, a preset slug this site doesn't define, a style group
 * outside the style-engine vocabulary — comes back as a warning ON THE WRITE
 * RESPONSE, while the write itself still lands. And no warnings on writes
 * that are fully applied: an echo that cries wolf gets ignored too.
 *
 * @package Saddle
 */

class Saddle_Echo_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );

		// A dynamic block with a known schema, so the unknown-key check is
		// deterministic regardless of which core blocks this WP registers
		// server-side.
		register_block_type(
			'saddletest/echo-dyn',
			array(
				'attributes'      => array(
					'known' => array( 'type' => 'string' ),
				),
				'render_callback' => '__return_empty_string',
			)
		);
	}

	public function tear_down() {
		unregister_block_type( 'saddletest/echo-dyn' );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	private function page( $content = '' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
	}

	/* -------- the checks themselves -------- */

	public function test_unknown_attribute_key_warns_and_known_keys_do_not() {
		$warnings = Saddle_Blocks_Echo::check_attrs(
			'saddletest/echo-dyn',
			array(
				'known'     => 'fine',
				'className' => 'fine-too',
				'bogusKey'  => 'ignored by the editor',
			),
			'2.1'
		);

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'bogusKey', $warnings[0] );
		$this->assertStringContainsString( '2.1', $warnings[0] );
	}

	public function test_unregistered_types_are_not_judged() {
		$warnings = Saddle_Blocks_Echo::check_attrs(
			'saddletest/never-registered',
			array( 'anything' => 'goes — the server cannot know' ),
			'0'
		);
		$this->assertSame( array(), $warnings );
	}

	public function test_unknown_style_group_warns_known_groups_do_not() {
		$warnings = Saddle_Blocks_Echo::check_attrs(
			'saddletest/echo-dyn',
			array(
				'style' => array(
					'typografy' => array( 'fontSize' => '3rem' ), // The classic typo.
					'spacing'   => array( 'padding' => array( 'top' => '96px' ) ),
				),
			),
			'0'
		);

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'typografy', $warnings[0] );
	}

	public function test_unknown_preset_slug_warns_and_real_slug_does_not() {
		$tokens = Saddle_Blocks_Schema::design_tokens();
		if ( empty( $tokens['colors'] ) || empty( $tokens['colors'][0]['slug'] ) ) {
			$this->markTestSkipped( 'The test theme exposes no theme.json palette to validate slugs against.' );
		}
		$real = (string) $tokens['colors'][0]['slug'];

		$bad = Saddle_Blocks_Echo::check_attrs( 'saddletest/echo-dyn', array( 'backgroundColor' => 'not-a-real-slug-xyz' ), '0' );
		$this->assertStringContainsString( 'matches no preset', implode( "\n", $bad ) );
		$this->assertStringContainsString( 'not-a-real-slug-xyz', implode( "\n", $bad ) );

		// A real slug passes clean. (backgroundColor isn't in the block's own
		// schema, so ignore the unknown-key warning this test doesn't target.)
		$good = Saddle_Blocks_Echo::check_attrs( 'saddletest/echo-dyn', array( 'backgroundColor' => $real ), '0' );
		foreach ( $good as $warning ) {
			$this->assertStringNotContainsString( 'matches no preset', $warning );
		}
	}

	/* -------- warnings ride the write responses -------- */

	public function test_set_blocks_echoes_ignored_attrs_but_still_writes() {
		$id     = $this->page();
		$result = $this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array( 'type' => 'core/paragraph', 'content' => 'Hello' ),
					array(
						'type'  => 'saddletest/echo-dyn',
						'attrs' => array( 'known' => 'x', 'bogusKey' => 'y' ),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertStringContainsString( 'bogusKey', $result['warnings'][0] );
		$this->assertStringContainsString( 'node 1', $result['warnings'][0] );
		// The write landed anyway — echo warns, it never blocks.
		$this->assertStringContainsString( 'Hello', get_post( $id )->post_content );
	}

	public function test_fully_applied_writes_carry_no_warnings_key() {
		$id     = $this->page();
		$result = $this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array( 'type' => 'core/paragraph', 'content' => 'Clean' ),
					array( 'type' => 'saddletest/echo-dyn', 'attrs' => array( 'known' => 'x' ) ),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'warnings', $result );
	}

	public function test_edit_block_echoes_ignored_patch_keys() {
		$id = $this->page( '<!-- wp:saddletest/echo-dyn {"known":"x"} /-->' );

		$result = $this->run_ability(
			'edit-block',
			array(
				'post_id' => $id,
				'address' => '0',
				'attrs'   => array( 'someTypo' => 'value' ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertStringContainsString( 'someTypo', $result['warnings'][0] );
	}

	public function test_add_block_echoes_at_the_landed_address() {
		$id = $this->page( '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->' );

		$result = $this->run_ability(
			'add-block',
			array(
				'post_id' => $id,
				'node'    => array(
					'type'  => 'saddletest/echo-dyn',
					'attrs' => array( 'bogusKey' => 'y' ),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '1', $result['added'] );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertStringContainsString( 'node 1', $result['warnings'][0] );
	}
}
