<?php
/**
 * Cookbook tests.
 *
 * The cookbook is prose that makes promises about what the product can do, and
 * prose does not fail loudly when the product moves underneath it. These tests
 * exist so a renamed tool, a new group, or a recipe written above the level it
 * claims breaks the suite instead of reaching a customer as a prompt that gets
 * refused.
 *
 * @package Saddle
 */

class Saddle_Cookbook_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	/**
	 * The whole point of the `uses` field. A tool rename must fail here rather
	 * than leave the cookbook promising something Saddle no longer does.
	 *
	 * Pro recipes are skipped when Pro is absent: their abilities live in the
	 * other plugin, and asserting them in free's suite would fail for the wrong
	 * reason.
	 */
	public function test_every_recipe_leans_only_on_abilities_that_exist() {
		$registered = array_keys( wp_get_abilities() );
		$this->assertNotEmpty( $registered, 'No abilities registered; the test cannot prove anything.' );

		$has_pro = defined( 'SADDLE_PRO_VERSION' );
		$checked = 0;

		foreach ( Saddle_Cookbook::recipes() as $recipe ) {
			if ( ! empty( $recipe['pro'] ) && ! $has_pro ) {
				continue;
			}

			foreach ( $recipe['uses'] as $ability ) {
				$this->assertContains(
					$ability,
					$registered,
					sprintf( 'Cookbook recipe "%s" names %s, which is not registered.', $recipe['title'], $ability )
				);
				++$checked;
			}
		}

		$this->assertGreaterThan( 0, $checked );
	}

	/**
	 * A recipe claiming `read` that actually needs `write` is the failure mode
	 * that makes the cookbook untrustworthy: the user pastes it at the level we
	 * told them was enough, gets refused, and reads it as a bug.
	 *
	 * Checked against each ability's own declared tier, so the recipe's claim
	 * has to be at least as high as the most demanding tool it leans on.
	 */
	public function test_no_recipe_claims_a_lower_level_than_its_tools_need() {
		$has_pro = defined( 'SADDLE_PRO_VERSION' );

		foreach ( Saddle_Cookbook::recipes() as $recipe ) {
			if ( ! empty( $recipe['pro'] ) && ! $has_pro ) {
				continue;
			}

			foreach ( $recipe['uses'] as $name ) {
				$ability = wp_get_ability( $name );
				if ( ! $ability ) {
					continue;
				}

				$meta     = $ability->get_meta();
				$required = isset( $meta['saddle']['tier'] ) ? (string) $meta['saddle']['tier'] : '';
				if ( '' === $required ) {
					continue;
				}

				$this->assertGreaterThanOrEqual(
					Saddle_Capabilities::rank( $required ),
					Saddle_Capabilities::rank( $recipe['tier'] ),
					sprintf(
						'Cookbook recipe "%s" claims %s, but %s needs %s.',
						$recipe['title'],
						$recipe['tier'],
						$name,
						$required
					)
				);
			}
		}
	}

	public function test_every_recipe_is_complete_and_well_formed() {
		$groups = Saddle_Cookbook::groups();
		$this->assertNotEmpty( $groups );

		foreach ( Saddle_Cookbook::recipes() as $recipe ) {
			foreach ( array( 'group', 'title', 'prompt', 'tier', 'expect', 'uses', 'pro' ) as $key ) {
				$this->assertArrayHasKey( $key, $recipe, 'A recipe is missing ' . $key );
			}

			$this->assertArrayHasKey(
				$recipe['group'],
				$groups,
				sprintf( 'Recipe "%s" is in group "%s", which does not exist.', $recipe['title'], $recipe['group'] )
			);

			$this->assertContains( $recipe['tier'], Saddle_Capabilities::tiers() );
			$this->assertIsBool( $recipe['pro'] );
			$this->assertNotEmpty( $recipe['uses'] );

			// A prompt short enough to be a heading is a heading, not something
			// anyone can paste and run.
			$this->assertGreaterThan(
				30,
				strlen( $recipe['prompt'] ),
				sprintf( 'Recipe "%s" has a prompt too short to be pasteable.', $recipe['title'] )
			);
		}
	}

	/**
	 * The house voice rule, checkable rather than remembered.
	 */
	public function test_no_em_dashes_in_anything_a_visitor_reads() {
		foreach ( Saddle_Cookbook::recipes() as $recipe ) {
			foreach ( array( 'title', 'prompt', 'expect' ) as $field ) {
				$this->assertStringNotContainsString(
					"\xe2\x80\x94",
					$recipe[ $field ],
					sprintf( 'Recipe "%s" has an em dash in its %s.', $recipe['title'], $field )
				);
			}
		}
	}

	/**
	 * Pro recipes stay in the payload when Pro is absent, on purpose: hiding
	 * them makes the cookbook look thinner than the product is, and a Divi user
	 * evaluating Saddle should be able to see what Pro would add. The UI labels
	 * them; it does not pretend they do not exist.
	 */
	public function test_pro_recipes_are_labelled_not_hidden() {
		$payload = Saddle_Cookbook::payload();
		$pro     = array_filter( $payload['recipes'], static fn( $r ) => $r['pro'] );

		$this->assertNotEmpty( $pro, 'No Pro recipes present; this test no longer proves anything.' );
		$this->assertArrayHasKey( 'has_pro', $payload );
		$this->assertIsBool( $payload['has_pro'] );
	}

	/**
	 * The payload reports the SITE tier, never the ceiling a credential happens
	 * to carry — this is a screen describing configuration, and the two are
	 * different questions.
	 */
	public function test_payload_reports_the_site_tier() {
		Saddle_Capabilities::set_tier( 'write' );

		$payload = Saddle_Cookbook::payload();

		$this->assertSame( 'write', $payload['site_tier'] );
		$this->assertSame( Saddle_Capabilities::get_site_tier(), $payload['site_tier'] );
	}
}
