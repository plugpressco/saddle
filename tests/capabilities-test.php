<?php
/**
 * Tier system tests — the default-safe guarantee and permission wiring.
 *
 * @package Saddle
 */

class Saddle_Capabilities_Test extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::DISABLED_OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		delete_option( Saddle_Capabilities::TIER_DOMAIN_OPTION );
		parent::tear_down();
	}

	/** Fresh install must default to `read` — verified against the DB, not just the getter. */
	public function test_activation_defaults_to_read_tier_in_db() {
		delete_option( Saddle_Capabilities::OPTION );
		Saddle::activate();

		$raw = get_option( Saddle_Capabilities::OPTION );
		$this->assertSame( 'read', $raw, 'Fresh install must persist the read tier.' );
	}

	/** Activation must never downgrade an already-configured site. */
	public function test_activation_does_not_overwrite_existing_tier() {
		update_option( Saddle_Capabilities::OPTION, 'admin' );
		Saddle::activate();

		$this->assertSame( 'admin', get_option( Saddle_Capabilities::OPTION ) );
	}

	/** With no option set at all, the getter still reports the safe default. */
	public function test_get_tier_defaults_to_read_when_unset() {
		delete_option( Saddle_Capabilities::OPTION );
		$this->assertSame( 'read', Saddle_Capabilities::get_tier() );
	}

	public function test_set_tier_accepts_known_tiers() {
		$this->assertTrue( Saddle_Capabilities::set_tier( 'write' ) );
		$this->assertSame( 'write', Saddle_Capabilities::get_tier() );
	}

	public function test_set_tier_rejects_unknown_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		$this->assertFalse( Saddle_Capabilities::set_tier( 'superuser' ) );
		$this->assertSame( 'write', Saddle_Capabilities::get_tier(), 'A rejected tier must not change state.' );
	}

	/** An out-of-set value living in the DB must be coerced back to the safe default. */
	public function test_get_tier_coerces_invalid_stored_value() {
		update_option( Saddle_Capabilities::OPTION, 'garbage' );
		$this->assertSame( 'read', Saddle_Capabilities::get_tier() );
	}

	public function test_tier_ordering() {
		Saddle_Capabilities::set_tier( 'read' );
		$this->assertTrue( Saddle_Capabilities::tier_allows( 'read' ) );
		$this->assertFalse( Saddle_Capabilities::tier_allows( 'write' ) );
		$this->assertFalse( Saddle_Capabilities::tier_allows( 'admin' ) );

		Saddle_Capabilities::set_tier( 'write' );
		$this->assertTrue( Saddle_Capabilities::tier_allows( 'read' ) );
		$this->assertTrue( Saddle_Capabilities::tier_allows( 'write' ) );
		$this->assertFalse( Saddle_Capabilities::tier_allows( 'admin' ) );

		Saddle_Capabilities::set_tier( 'admin' );
		$this->assertTrue( Saddle_Capabilities::tier_allows( 'read' ) );
		$this->assertTrue( Saddle_Capabilities::tier_allows( 'write' ) );
		$this->assertTrue( Saddle_Capabilities::tier_allows( 'admin' ) );
	}

	public function test_tier_allows_rejects_unknown_required_tier() {
		Saddle_Capabilities::set_tier( 'admin' );
		$this->assertFalse( Saddle_Capabilities::tier_allows( 'superuser' ) );
	}

	/* -------- permission() closure -------- */

	public function test_permission_denies_logged_out_user() {
		wp_set_current_user( 0 );
		Saddle_Capabilities::set_tier( 'admin' );
		$cb = Saddle_Capabilities::permission( 'read', 'read' );
		$this->assertFalse( $cb() );
	}

	public function test_permission_denies_user_without_capability() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		Saddle_Capabilities::set_tier( 'write' );

		// Subscribers lack edit_posts.
		$cb = Saddle_Capabilities::permission( 'write', 'edit_posts' );
		$this->assertFalse( $cb() );
	}

	public function test_permission_denies_when_site_tier_too_low() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		Saddle_Capabilities::set_tier( 'read' );

		// Editor holds edit_posts, but the site is only at read tier.
		$cb = Saddle_Capabilities::permission( 'write', 'edit_posts' );
		$this->assertFalse( $cb(), 'Capability alone must not grant a write-tier ability when the site is read-only.' );
	}

	public function test_permission_allows_capable_user_at_sufficient_tier() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		Saddle_Capabilities::set_tier( 'write' );

		$cb = Saddle_Capabilities::permission( 'write', 'edit_posts' );
		$this->assertTrue( $cb() );
	}

	/* -------- per-ability disable, orthogonal to tier -------- */

	public function test_disabled_abilities_defaults_to_empty() {
		$this->assertSame( array(), Saddle_Capabilities::disabled_abilities() );
	}

	public function test_set_disabled_abilities_persists_and_dedupes() {
		$saved = Saddle_Capabilities::set_disabled_abilities( array( 'delete-media', 'delete-media', 'Delete-Post' ) );

		$this->assertSame( array( 'delete-media', 'delete-post' ), $saved, 'Values are sanitized (sanitize_key) and deduped.' );
		$this->assertSame( array( 'delete-media', 'delete-post' ), Saddle_Capabilities::disabled_abilities() );
	}

	public function test_is_ability_enabled_reflects_disabled_list() {
		Saddle_Capabilities::set_disabled_abilities( array( 'delete-media' ) );

		$this->assertFalse( Saddle_Capabilities::is_ability_enabled( 'delete-media' ) );
		$this->assertTrue( Saddle_Capabilities::is_ability_enabled( 'list-media' ) );
	}

	public function test_permission_denies_disabled_ability_even_at_sufficient_tier() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_disabled_abilities( array( 'create-post' ) );

		$cb = Saddle_Capabilities::permission( 'write', 'edit_posts', 'create-post' );
		$this->assertFalse( $cb(), 'A disabled ability must be denied even when the tier and capability both allow it.' );
	}

	public function test_permission_without_short_name_ignores_disabled_list() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_disabled_abilities( array( 'create-post' ) );

		// No short name passed — this callback has no ability to check against.
		$cb = Saddle_Capabilities::permission( 'write', 'edit_posts' );
		$this->assertTrue( $cb() );
	}

	/* -------- pause, independent of tier -------- */

	public function test_is_paused_defaults_to_false() {
		$this->assertFalse( Saddle_Capabilities::is_paused() );
	}

	public function test_set_paused_persists() {
		Saddle_Capabilities::set_paused( true );
		$this->assertTrue( Saddle_Capabilities::is_paused() );

		Saddle_Capabilities::set_paused( false );
		$this->assertFalse( Saddle_Capabilities::is_paused() );
	}

	public function test_permission_denies_everything_when_paused() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		Saddle_Capabilities::set_tier( 'admin' );
		Saddle_Capabilities::set_paused( true );

		$cb = Saddle_Capabilities::permission( 'read', 'read' );
		$this->assertFalse( $cb(), 'Pausing must deny even a read-tier check for a fully-capable admin.' );
	}

	public function test_unpausing_restores_the_configured_tier() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		Saddle_Capabilities::set_tier( 'write' );
		Saddle_Capabilities::set_paused( true );
		Saddle_Capabilities::set_paused( false );

		$cb = Saddle_Capabilities::permission( 'write', 'edit_posts' );
		$this->assertTrue( $cb(), 'Resuming must restore access without needing the tier re-configured.' );
	}

	/* -------- domain-of-record, warning-only -------- */

	public function test_domain_matches_recorded_true_when_nothing_recorded() {
		Saddle_Capabilities::set_tier( 'write' );
		delete_option( Saddle_Capabilities::TIER_DOMAIN_OPTION );

		$this->assertTrue( Saddle_Capabilities::domain_matches_recorded(), 'No baseline yet means nothing to warn about.' );
	}

	public function test_domain_matches_recorded_true_at_read_tier_regardless() {
		Saddle_Capabilities::set_tier( 'read' );
		update_option( Saddle_Capabilities::TIER_DOMAIN_OPTION, 'stale.example.test' );

		$this->assertTrue( Saddle_Capabilities::domain_matches_recorded(), 'Read tier grants nothing extra, so a domain mismatch is not worth warning about.' );
	}

	public function test_set_tier_to_write_records_the_current_domain() {
		delete_option( Saddle_Capabilities::TIER_DOMAIN_OPTION );
		Saddle_Capabilities::set_tier( 'write' );

		$this->assertSame( Saddle_Capabilities::current_domain(), Saddle_Capabilities::recorded_tier_domain() );
	}

	public function test_set_tier_to_read_does_not_record_a_domain() {
		delete_option( Saddle_Capabilities::TIER_DOMAIN_OPTION );
		Saddle_Capabilities::set_tier( 'read' );

		$this->assertSame( '', Saddle_Capabilities::recorded_tier_domain() );
	}

	public function test_domain_mismatch_is_detected_at_write_tier() {
		Saddle_Capabilities::set_tier( 'write' );
		update_option( Saddle_Capabilities::TIER_DOMAIN_OPTION, 'old-domain.example.test' );

		$this->assertNotSame( 'old-domain.example.test', Saddle_Capabilities::current_domain() );
		$this->assertFalse( Saddle_Capabilities::domain_matches_recorded() );
	}

	public function test_re_saving_the_tier_clears_a_stale_domain_warning() {
		Saddle_Capabilities::set_tier( 'write' );
		update_option( Saddle_Capabilities::TIER_DOMAIN_OPTION, 'old-domain.example.test' );
		$this->assertFalse( Saddle_Capabilities::domain_matches_recorded() );

		Saddle_Capabilities::set_tier( 'write' );

		$this->assertTrue( Saddle_Capabilities::domain_matches_recorded(), 'Re-confirming the tier on the current domain must clear the warning.' );
	}
}
