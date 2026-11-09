<?php
/**
 * The add-ons Saddle's own pages offer (#367 Saddle Analytics, #409 Saddle
 * Rank): where each stands, who may add it, and that only the two fixed
 * slugs are ever named.
 *
 * @package Saddle
 */

class Saddle_Addon_Offer_Test extends WP_UnitTestCase {

	/**
	 * Calls a private offer method.
	 *
	 * @param string $method analytics_offer or rank_offer.
	 * @return array
	 */
	private function offer( $method ) {
		$ref = new ReflectionMethod( Saddle_Settings::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_rank_is_offered_to_an_owner_when_it_is_missing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() || file_exists( WP_PLUGIN_DIR . '/saddle-rank/saddle-rank.php' ) ) {
			$this->markTestSkipped( 'Needs a single site without Saddle Rank installed.' );
		}

		$offer = $this->offer( 'rank_offer' );

		$this->assertSame( 'missing', $offer['state'] );
		$this->assertTrue( $offer['canAdd'] );
		$this->assertStringContainsString( 'page=saddle-rank', $offer['url'] );
		$this->assertStringContainsString( 's=saddle-rank', $offer['manualUrl'] );
	}

	public function test_nothing_is_offered_to_someone_who_cannot_activate_plugins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse( $this->offer( 'rank_offer' )['canAdd'] );
		$this->assertFalse( $this->offer( 'analytics_offer' )['canAdd'] );
	}

	public function test_analytics_still_names_its_own_slug() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$offer = $this->offer( 'analytics_offer' );

		$this->assertStringContainsString( 'page=saddle-analytics', $offer['url'] );
		$this->assertStringContainsString( 's=saddle-analytics', $offer['manualUrl'] );
	}
}
