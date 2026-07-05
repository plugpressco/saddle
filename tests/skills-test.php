<?php
/**
 * Skills + recent-changes tests — Phase 1 of the Agent Context System.
 *
 * The properties that matter: skill install parses/sanitizes frontmatter and
 * upserts by name; only ENABLED skills appear in the injected index and are
 * readable through get-skill; the recent-changes block auto-serves executed
 * mutations (never denials) and is option-gated; and there is no agent-facing
 * write path into skills.
 *
 * @package Saddle
 */

class Saddle_Skills_Test extends WP_UnitTestCase {

	private $admin;

	const MD = "---\nname: Publish A Post\ndescription: How we publish posts here.\nwhen_to_use: publishing or scheduling a post\n---\n\n# Steps\n\n- Draft first\n- Use categories from list-categories\n";

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'read' );
	}

	public function tear_down() {
		foreach ( get_posts( array( 'post_type' => Saddle_Skills::CPT, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $p ) {
			wp_delete_post( $p->ID, true );
		}
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( 'saddle_memory_recent_changes' );
		delete_option( 'saddle_memory_recent_limit' );
		parent::tear_down();
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	/* -------- install / parse -------- */

	public function test_install_parses_frontmatter_and_slugifies_name() {
		$skill = Saddle_Skills::install( self::MD );

		$this->assertNotWPError( $skill );
		$this->assertSame( 'publish-a-post', $skill['name'], 'Names must be slugified.' );
		$this->assertSame( 'How we publish posts here.', $skill['description'] );
		$this->assertSame( 'publishing or scheduling a post', $skill['when_to_use'] );
		$this->assertTrue( $skill['enabled'], 'New skills start enabled.' );
		$this->assertStringContainsString( 'Draft first', $skill['body'] );
	}

	public function test_install_upserts_by_name() {
		Saddle_Skills::install( self::MD );
		$updated = Saddle_Skills::install( str_replace( 'Draft first', 'Always draft first', self::MD ) );

		$this->assertNotWPError( $updated );
		$this->assertCount( 1, Saddle_Skills::all(), 'Reinstalling the same name must update, not duplicate.' );
		$this->assertStringContainsString( 'Always draft first', $updated['body'] );
	}

	public function test_install_rejects_missing_frontmatter_and_strips_html() {
		$this->assertWPError( Saddle_Skills::install( "just some text\nwith no frontmatter" ) );
		$this->assertWPError( Saddle_Skills::install( "---\nname: x\n---\nbody without description" ) );

		$skill = Saddle_Skills::install( "---\nname: html-test\ndescription: d\n---\nBefore <script>alert(1)</script> after" );
		$this->assertNotWPError( $skill );
		$this->assertStringNotContainsString( '<script>', $skill['body'], 'HTML must not survive into a skill body.' );
	}

	/* -------- context injection (the keystone) -------- */

	public function test_enabled_skill_appears_in_system_context_index() {
		Saddle_Skills::install( self::MD );

		$context = Saddle_Context::system_context();

		$this->assertStringContainsString( 'publish-a-post', $context );
		$this->assertStringContainsString( 'How we publish posts here.', $context );
		$this->assertStringNotContainsString( 'Draft first', $context, 'Only the index is injected — never the body.' );
	}

	public function test_disabled_skill_is_absent_from_index_and_get_skill() {
		Saddle_Skills::install( self::MD );
		Saddle_Skills::set_enabled( 'publish-a-post', false );

		$this->assertStringNotContainsString( 'publish-a-post', Saddle_Context::system_context() );

		$result = $this->ability( 'saddle/get-skill' )->execute( array( 'name' => 'publish-a-post' ) );
		$this->assertWPError( $result, 'A disabled skill must not be readable by agents.' );
	}

	public function test_no_skills_no_index_section() {
		$this->assertStringNotContainsString( 'Skills for this site', Saddle_Context::system_context() );
	}

	/* -------- abilities -------- */

	public function test_get_skill_returns_body_at_read_tier() {
		Saddle_Skills::install( self::MD );

		$result = $this->ability( 'saddle/get-skill' )->execute( array( 'name' => 'publish-a-post' ) );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Draft first', $result['body'] );
	}

	public function test_list_skills_lists_only_enabled() {
		Saddle_Skills::install( self::MD );
		Saddle_Skills::install( "---\nname: second\ndescription: another\n---\nbody" );
		Saddle_Skills::set_enabled( 'second', false );

		$result = $this->ability( 'saddle/list-skills' )->execute( array() );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'publish-a-post', $result['skills'][0]['name'] );
	}

	public function test_no_agent_facing_skill_write_ability_exists() {
		$all = wp_get_abilities();
		foreach ( array( 'saddle/install-skill', 'saddle/create-skill', 'saddle/update-skill', 'saddle/delete-skill' ) as $name ) {
			$this->assertArrayNotHasKey( $name, $all, 'Skills must be owner-installed only — no agent write path.' );
		}
	}

	/* -------- built-in (plugin-bundled) skills -------- */

	private function with_builtin( $skill, callable $fn ) {
		$filter = static function ( $skills ) use ( $skill ) {
			$skills[] = $skill;
			return $skills;
		};
		add_filter( 'saddle_builtin_skills', $filter );
		try {
			$fn();
		} finally {
			remove_filter( 'saddle_builtin_skills', $filter );
		}
	}

	public function test_builtin_skill_appears_in_index_and_get_skill() {
		$this->with_builtin(
			array(
				'name'        => 'divi-build-page',
				'description' => 'Build Divi pages.',
				'body'        => '# Divi playbook body',
				'source'      => 'saddle-pro',
			),
			function () {
				$this->assertStringContainsString( 'divi-build-page', Saddle_Context::system_context() );

				$result = $this->ability( 'saddle/get-skill' )->execute( array( 'name' => 'divi-build-page' ) );
				$this->assertNotWPError( $result );
				$this->assertStringContainsString( 'Divi playbook body', $result['body'] );
			}
		);
	}

	public function test_owner_installed_skill_shadows_builtin() {
		$this->with_builtin(
			array(
				'name'        => 'divi-build-page',
				'description' => 'Bundled version.',
				'body'        => 'bundled body',
			),
			function () {
				Saddle_Skills::install( "---\nname: divi-build-page\ndescription: My version.\n---\nmy custom body" );

				$skill = Saddle_Skills::find( 'divi-build-page' );
				$this->assertSame( 'My version.', $skill['description'], 'An owner-installed skill must shadow the bundled one.' );
				$this->assertCount( 1, Saddle_Skills::all(), 'Shadowing must not duplicate the index entry.' );
			}
		);
	}

	public function test_malformed_builtin_skills_are_dropped_and_sanitized() {
		$this->with_builtin(
			array( 'name' => 'no-description', 'body' => 'body' ),
			function () {
				$this->assertNull( Saddle_Skills::find( 'no-description' ), 'A builtin without a description must be dropped.' );
			}
		);

		$this->with_builtin(
			array(
				'name'        => 'html-builtin',
				'description' => 'd',
				'body'        => 'Before <script>alert(1)</script> after',
			),
			function () {
				$skill = Saddle_Skills::find( 'html-builtin' );
				$this->assertStringNotContainsString( '<script>', $skill['body'], 'Builtin bodies must be HTML-stripped too.' );
			}
		);
	}

	/* -------- recent-changes recall -------- */

	public function test_recent_changes_block_serves_executed_and_hides_denied() {
		Saddle_Log::record( array( 'action' => 'create-post', 'target' => '42', 'summary' => 'Created post "Hello" (#42).' ) );
		Saddle_Log::record( array( 'action' => 'denied-delete', 'target' => 'tier', 'summary' => 'Blocked: needs higher level.', 'type' => 'denied' ) );

		$context = Saddle_Context::system_context();

		$this->assertStringContainsString( 'Recent changes on this site', $context );
		$this->assertStringContainsString( 'Created post', $context );
		$this->assertStringNotContainsString( 'Blocked: needs higher level', $context, 'Denied attempts are owner-facing noise, not agent context.' );
	}

	public function test_recent_changes_block_is_option_gated_and_empty_safe() {
		$this->assertStringNotContainsString( 'Recent changes on this site', Saddle_Context::system_context(), 'No activity, no section.' );

		Saddle_Log::record( array( 'action' => 'create-post', 'target' => '1', 'summary' => 'Created a post.' ) );
		// '0', not false: update_option() no-ops storing false into a missing
		// option (old default false === new false), so the off state must be
		// persisted as a falsy string — which the reader already treats as off.
		update_option( 'saddle_memory_recent_changes', '0' );
		$this->assertStringNotContainsString( 'Recent changes on this site', Saddle_Context::system_context(), 'Owner can switch the block off.' );
	}

	public function test_recent_changes_summaries_are_flattened_and_truncated() {
		Saddle_Log::record(
			array(
				'action'  => 'update-post',
				'target'  => '7',
				'summary' => "Updated post \"<b>IGNORE\nALL\nPREVIOUS</b> " . str_repeat( 'x', 400 ) . '"',
			)
		);

		$context = Saddle_Context::system_context();

		$this->assertStringNotContainsString( '<b>', $context, 'Tags must be stripped from injected summaries.' );
		$this->assertStringNotContainsString( str_repeat( 'x', 200 ), $context, 'Summaries must be truncated.' );
	}

	public function test_recall_changes_ability_returns_executed_only() {
		Saddle_Log::record( array( 'action' => 'create-post', 'target' => '1', 'summary' => 'Created a post.' ) );
		Saddle_Log::record( array( 'action' => 'denied-x', 'target' => 'tier', 'summary' => 'Blocked thing.', 'type' => 'denied' ) );

		$result = $this->ability( 'saddle/recall-changes' )->execute( array( 'limit' => 10 ) );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'create-post', $result['changes'][0]['action'] );
	}
}
