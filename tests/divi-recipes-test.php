<?php
/**
 * Section recipes + the divi-build-page skill body.
 *
 * The recipes must be exemplary — they are the blueprints agents copy: every
 * body expands and validates, purpose-built modules replace hand-stacks
 * (accordion for FAQ, pricing-tables for pricing, divi/cta for the band),
 * the hero models width-cap-AND-center, exactly one pricing plan is
 * featured, and buttons use the real {text, linkUrl} contract. The skill
 * body must teach that same contract (the shipped example used to teach a
 * broken one) and embed free's design numbers verbatim (single source).
 *
 * @package Saddle
 */

require_once __DIR__ . '/divi-test-paths.php';

class Saddle_Divi_Recipes_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_all_filters( 'saddle_divi_global_colors' );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	private const RECIPES = array( 'hero', 'features', 'pricing', 'testimonials', 'cta', 'faq' );

	public function test_every_recipe_expands_and_validates() {
		foreach ( self::RECIPES as $name ) {
			$nodes = Saddle_Divi_Recipes::tree( $name );
			$this->assertIsArray( $nodes, "Recipe {$name} must exist." );

			$tree = Saddle_Divi_Author::expand( $nodes );
			$this->assertNotWPError( $tree, "Recipe {$name} must expand." );
			$this->assertTrue( Saddle_Divi_Tree::validate( $tree ), "Recipe {$name} must validate." );
		}
	}

	public function test_recipes_use_purpose_built_modules() {
		$flat = static function ( $name ) {
			return wp_json_encode( Saddle_Divi_Recipes::tree( $name ), JSON_UNESCAPED_SLASHES );
		};

		$this->assertStringContainsString( 'divi/cta', $flat( 'cta' ), 'The CTA band is the purpose-built module, not a hand-stack.' );
		$this->assertStringContainsString( 'divi/accordion-item', $flat( 'faq' ), 'FAQ entries are real accordion items, editable as such.' );
		$this->assertStringContainsString( 'divi/pricing-table', $flat( 'pricing' ) );
		$this->assertStringContainsString( 'divi/testimonial', $flat( 'testimonials' ) );
		$this->assertStringContainsString( 'divi/blurb', $flat( 'features' ) );
		$this->assertStringNotContainsString( '<strong>$', $flat( 'pricing' ), 'No fake HTML price headings.' );
	}

	public function test_hero_models_the_centered_width_cap_and_button_contract() {
		$json = wp_json_encode( Saddle_Divi_Recipes::tree( 'hero' ), JSON_UNESCAPED_SLASHES );

		// The pinned-left regression: a capped column is ALSO centered.
		$this->assertStringContainsString( '"maxWidth":"640px"', $json );
		$this->assertStringContainsString( '"alignment":"center"', $json );

		// Primary filled (enable on, token background), secondary quiet.
		// Written in the desktop shorthand the playbook teaches (the full
		// ".desktop.value.enable" path was pinned here before the shorthand
		// existed).
		$this->assertStringContainsString( '"button.decoration.button":{"enable":"on"}', $json );
		$this->assertStringContainsString( 'var(--gcid-primary-color)', $json );

		// The real button content contract.
		$this->assertStringContainsString( '"linkUrl"', $json );
		$this->assertStringNotContainsString( '"url"', $json );

		// The catalog promise: primary + secondary call to action.
		$this->assertSame( 2, substr_count( $json, 'divi/button' ) );
	}

	/**
	 * Both hero buttons sat in one column and stacked. They now share a
	 * group laid out as a centred, wrapping row.
	 */
	public function test_hero_buttons_sit_side_by_side() {
		$column = Saddle_Divi_Recipes::tree( 'hero' )[0]['children'][0]['children'][0];
		$group  = end( $column['children'] );

		$this->assertSame( 'divi/group', $group['type'] );
		$this->assertSame( array( 'divi/button', 'divi/button' ), array_column( $group['children'], 'type' ) );
		$layout = $group['attrs']['module.decoration.layout'];
		$this->assertSame( 'flex', $layout['display'] );
		$this->assertSame( 'row', $layout['flexDirection'] );
		$this->assertSame( 'wrap', $layout['flexWrap'] );
		$this->assertSame( 'center', $layout['justifyContent'] );
	}

	/**
	 * A row of several columns with no widths stacks on desktop too: each
	 * column falls back to Divi's 24_24 full width. Every multi-column row
	 * in a recipe names its columns.
	 */
	public function test_every_multi_column_row_sets_its_widths() {
		$content = $this->content();
		foreach ( self::RECIPES as $name ) {
			foreach ( array( array(), $content ) as $c ) {
				$nodes = Saddle_Divi_Recipes::tree( $name, $c );
				foreach ( $this->rows( $nodes ) as $row ) {
					if ( count( $row['children'] ) > 1 ) {
						$this->assertArrayHasKey( 'columns', $row, "{$name}: a row of " . count( $row['children'] ) . ' columns must set "columns".' );
						$this->assertSame( count( $row['children'] ), $row['columns'] );
					}
				}
				$this->assertNotWPError( Saddle_Divi_Author::expand( $nodes ) );
				$this->assertSame( array(), Saddle_Divi_Author::notes(), "{$name}: SaddleWP added no widths of its own." );
			}
		}
	}

	public function test_hero_is_h1_and_every_other_section_heading_is_h2() {
		$levels = static function ( $nodes ) {
			preg_match_all( '/"headingLevel":"(h\d)"/', wp_json_encode( $nodes ), $m );
			return $m[1];
		};
		$this->assertSame( array( 'h1' ), $levels( Saddle_Divi_Recipes::tree( 'hero' ) ) );
		foreach ( array( 'features', 'pricing', 'testimonials', 'cta', 'faq' ) as $name ) {
			$found = $levels( Saddle_Divi_Recipes::tree( $name ) );
			$this->assertSame( 'h2', $found[0], "{$name} opens with an h2 (Divi's heading default is h1)." );
			$this->assertNotContains( 'h1', $found, $name );
		}
	}

	public function test_big_type_and_padding_get_a_phone_value() {
		foreach ( self::RECIPES as $name ) {
			$json = wp_json_encode( Saddle_Divi_Recipes::tree( $name ), JSON_UNESCAPED_SLASHES );
			$this->assertStringContainsString( '"module.decoration.spacing@phone":{"padding":{"top":"64px","bottom":"64px"}}', $json, $name );
		}
		$hero = wp_json_encode( Saddle_Divi_Recipes::tree( 'hero' ), JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '"title.decoration.font.font@phone":{"size":"40px"}', $hero );
		$pricing = wp_json_encode( Saddle_Divi_Recipes::tree( 'pricing' ), JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '"module.decoration.layout@phone":{"flexDirection":"column","rowGap":"32px"}', $pricing, 'Stacked plans on a phone get a gap (Divi prints 0px).' );
	}

	/* -------- content in place of placeholders -------- */

	public function test_content_replaces_the_placeholders() {
		$c = $this->content();

		$hero = wp_json_encode( Saddle_Divi_Recipes::tree( 'hero', $c ), JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '"title":"Make Your Site Work"', $hero );
		$this->assertStringContainsString( 'Built for <strong>busy</strong> owners', $hero );
		$this->assertStringContainsString( '"text":"Start Free","linkUrl":"https://example.com/start"', $hero );
		$this->assertStringContainsString( '"text":"Book a Demo","linkUrl":"/demo"', $hero );
		$this->assertStringNotContainsString( 'benefit-led', $hero );

		// Five items: rows of three and two, each card a blurb with its own
		// h3 title; the icon reaches the blurb's icon field.
		$features = Saddle_Divi_Recipes::tree( 'features', $c );
		$grid     = array_slice( $this->rows( $features ), 1 );
		$this->assertSame( array( 3, 2 ), array_map( 'count', array_column( $grid, 'children' ) ) );
		$json = wp_json_encode( $features, JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '"imageIcon":{"useIcon":"on","icon":{"type":"divi","weight":"400","unicode":"&#xe03c;"}}', $json );

		// The plan marked featured wins over the middle one; "$49" splits
		// into the currency and the number, and the currency and period
		// written on one plan hold for the other.
		$pricing = wp_json_encode( Saddle_Divi_Recipes::tree( 'pricing', $c ), JSON_UNESCAPED_SLASHES );
		$this->assertSame( 1, substr_count( $pricing, 'module.advanced.featured' ) );
		$this->assertStringContainsString( '"title":"Pro","currencyFrequency":{"currency":"$","per":"year"},"price":"99"', $pricing );
		$this->assertStringContainsString( '"price":"49"', $pricing );
		$this->assertStringContainsString( '<li>1 site</li>', $pricing );
		$this->assertStringContainsString( '"text":"Buy Pro","linkUrl":"/pro"', $pricing );

		$quotes = wp_json_encode( Saddle_Divi_Recipes::tree( 'testimonials', $c ), JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '"content":"Great.","author":"Ana"}', $quotes, 'No role or company placeholder under a real name.' );
		$this->assertStringContainsString( '"jobTitle":"CEO","company":{"text":"Acme"}', $quotes );

		$faq = wp_json_encode( Saddle_Divi_Recipes::tree( 'faq', $c ), JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '"title":"Is it free?","content":"Yes."', $faq );
		$this->assertSame( 1, substr_count( $faq, 'divi/accordion-item' ) );

		$cta = wp_json_encode( Saddle_Divi_Recipes::tree( 'cta', $c ), JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '"button":{"text":"Start Free","linkUrl":"https://example.com/start"}', $cta );
	}

	public function test_content_is_cleaned_and_capped() {
		$clean = Saddle_Recipe_Content::clean(
			array(
				'heading'   => '<script>x</script>Hello',
				'buttons'   => array(
					array(
						'text' => 'One',
						'url'  => 'javascript:alert(1)',
					),
					array( 'text' => 'Two' ),
					array( 'text' => 'Three' ),
				),
				'questions' => array( 'not an entry', array( 'nope' => 'x' ), array( 'question' => 'Q?' ) ),
				'plans'     => array( array( 'featured' => true ) ),
				'other'     => 'ignored',
			)
		);
		$this->assertSame( 'Hello', $clean['heading'] );
		$this->assertCount( 2, $clean['buttons'], 'At most two buttons.' );
		$this->assertSame( '', isset( $clean['buttons'][0]['url'] ) ? $clean['buttons'][0]['url'] : '', 'A script address is dropped.' );
		$this->assertSame( array( array( 'question' => 'Q?' ) ), $clean['questions'] );
		$this->assertArrayNotHasKey( 'plans', $clean, 'A plan with nothing but "featured" is no plan.' );
		$this->assertArrayNotHasKey( 'other', $clean );
		$this->assertSame( array(), Saddle_Recipe_Content::clean( array( 'name' => 'hero' ) ) );
	}

	/**
	 * Text on the accent must read. Divi's default primary (#2ea3f2) gives
	 * white text 2.75:1, so a light accent gets near-black text instead.
	 */
	public function test_text_on_the_accent_follows_the_sites_colour() {
		$this->assertSame( '#ffffff', Saddle_Divi_Recipe_Parts::on_accent(), 'Unknown accent: white.' );

		add_filter( 'saddle_divi_global_colors', array( $this, 'light_palette' ) );
		$cta = wp_json_encode( Saddle_Divi_Recipes::tree( 'cta' ), JSON_UNESCAPED_SLASHES );
		remove_filter( 'saddle_divi_global_colors', array( $this, 'light_palette' ) );

		$this->assertStringContainsString( '"content.decoration.bodyFont.body.font":{"color":"#111111"}', $cta );
		$this->assertStringContainsString( '"button.decoration.background":{"color":"#111111"}', $cta );
		$this->assertStringContainsString( '"button.decoration.font.font":{"color":"#ffffff"}', $cta );
	}

	/* -------- built, echoed and linted for real -------- */

	/**
	 * Every recipe, with and without content, built through the real author
	 * and validator against Divi's own module.json files, then echoed (as
	 * written and as saved) and linted under three palettes: no echo
	 * warning, no lint error or warning. Lint notes are advice and allowed.
	 */
	public function test_every_recipe_builds_clean_against_divis_module_json() {
		$this->use_divi_components();
		$notes = array();
		foreach ( array( '#2ea3f2', '#1d4ed8', '#f5d90a' ) as $accent ) {
			$this->accent = $accent;
			add_filter( 'saddle_divi_global_colors', array( $this, 'palette' ) );
			foreach ( self::RECIPES as $name ) {
				foreach ( array( 'placeholders' => array(), 'content' => $this->content() ) as $kind => $c ) {
					$label = "{$name} ({$kind}, accent {$accent})";
					$nodes = Saddle_Divi_Recipes::tree( $name, $c );
					$tree  = Saddle_Divi_Author::expand( $nodes );
					$this->assertNotWPError( $tree, $label );
					$this->assertTrue( Saddle_Divi_Tree::validate( $tree ), $label );
					$this->assertSame( array(), Saddle_Divi_Author::notes(), $label );
					$this->assertSame( array(), Saddle_Divi_Echo::check_nodes( Saddle_Divi_Author::echo_nodes( $nodes, '0' ), '0' ), $label . ': echo as written' );
					$this->assertSame( array(), Saddle_Divi_Verify::findings( $tree ), $label . ': echo as saved' );
					foreach ( Saddle_Lint::run( $tree, new Saddle_Divi_Lint_Accessor() ) as $v ) {
						if ( Saddle_Lint::SEVERITY_NOTE === $v['severity'] ) {
							$notes[] = $v['rule'];
							continue;
						}
						$this->fail( "{$label}: {$v['rule']} {$v['severity']} at {$v['address']}: {$v['message']}" );
					}
				}
			}
			remove_filter( 'saddle_divi_global_colors', array( $this, 'palette' ) );
		}
		$this->assertSame( array(), array_values( array_unique( $notes ) ), 'Notes found (advice only): ' . implode( ', ', array_unique( $notes ) ) );
	}

	public function test_the_featured_plan_is_a_checked_setting_when_module_json_is_there() {
		$this->use_divi_components();
		$json = wp_json_encode( Saddle_Divi_Recipes::tree( 'pricing' ), JSON_UNESCAPED_SLASHES );
		$this->assertStringContainsString( '"settings":{"module.advanced.featured":"on"}', $json );
	}

	/* -------- other plugins' recipes -------- */

	public function test_another_plugin_adds_a_divi_recipe() {
		$add = static function ( $recipes ) {
			$recipes['logo-strip'] = array(
				'title'       => 'Logo strip',
				'description' => 'One line of client logos.',
				'nodes'       => static function ( $content, $name ) {
					return array(
						array(
							'type'     => 'divi/section',
							'children' => array(
								array(
									'type'     => 'divi/row',
									'children' => array(
										array(
											'type'     => 'divi/column',
											'children' => array(
												array(
													'type'   => 'divi/heading',
													'fields' => array( 'title' => isset( $content['heading'] ) ? $content['heading'] : $name ),
												),
											),
										),
									),
								),
							),
						),
					);
				},
			);
			$recipes['hero'] = array(
				'title' => 'Not allowed',
				'nodes' => array(),
			);
			return $recipes;
		};
		add_filter( 'saddle_divi_section_recipes', $add );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$list = wp_get_ability( 'saddle/list-section-recipes' )->execute( array() );
		$this->assertContains( 'logo-strip', wp_list_pluck( $list['recipes'], 'name' ) );
		$this->assertSame( 'Hero', wp_list_pluck( $list['recipes'], 'title', 'name' )['hero'], 'A built-in name is never replaced.' );

		$got = wp_get_ability( 'saddle/get-section-recipe' )->execute(
			array(
				'name'    => 'logo-strip',
				'heading' => 'Trusted by teams',
			)
		);
		$this->assertSame( 'divi', $got['builder'] );
		$this->assertTrue( $got['content_applied'] );
		$this->assertStringContainsString( 'Trusted by teams', wp_json_encode( $got['nodes'] ) );

		// Off a Divi site the recipe is not offered at all.
		remove_filter( 'saddle_divi_active', '__return_true' );
		add_filter( 'saddle_divi_active', '__return_false' );
		$list = wp_get_ability( 'saddle/list-section-recipes' )->execute( array() );
		remove_filter( 'saddle_divi_active', '__return_false' );
		add_filter( 'saddle_divi_active', '__return_true' );
		remove_filter( 'saddle_divi_section_recipes', $add );
		$this->assertNotContains( 'logo-strip', wp_list_pluck( $list['recipes'], 'name' ) );
	}

	public function test_block_recipes_ignore_content_and_say_so() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_filter( 'saddle_divi_active', '__return_true' );
		add_filter( 'saddle_divi_active', '__return_false' );
		$plain = wp_get_ability( 'saddle/get-section-recipe' )->execute( array( 'name' => 'hero' ) );
		$given = wp_get_ability( 'saddle/get-section-recipe' )->execute(
			array(
				'name'    => 'hero',
				'heading' => 'Mine',
			)
		);
		remove_filter( 'saddle_divi_active', '__return_false' );
		add_filter( 'saddle_divi_active', '__return_true' );

		$this->assertSame( 'block-theme', $given['builder'] );
		$this->assertSame( $plain['nodes'], $given['nodes'], 'The block recipe is unchanged.' );
		$this->assertFalse( $given['content_applied'] );
		$this->assertArrayNotHasKey( 'content_applied', $plain, 'No content, no flag: the answer is what it was.' );

		$divi = wp_get_ability( 'saddle/get-section-recipe' )->execute(
			array(
				'name'    => 'hero',
				'heading' => 'Mine',
			)
		);
		$this->assertSame( 'divi', $divi['builder'] );
		$this->assertTrue( $divi['content_applied'] );
		$this->assertStringContainsString( '"title":"Mine"', wp_json_encode( $divi['nodes'] ) );
	}

	public function test_content_a_recipe_has_no_place_for_is_reported() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$get = static function ( array $input ) {
			return wp_get_ability( 'saddle/get-section-recipe' )->execute( $input );
		};

		// A hero used to answer content_applied true for items it has no place for.
		$hero = $get(
			array(
				'name'    => 'hero',
				'heading' => 'Mine',
				'items'   => array( array( 'title' => 'One' ) ),
			)
		);
		$this->assertFalse( $hero['content_applied'] );
		$this->assertSame( array( 'items' ), $hero['content_ignored'] );
		$this->assertStringContainsString( '"title":"Mine"', wp_json_encode( $hero['nodes'] ), 'What fits is still used.' );

		$cta = $get(
			array(
				'name'    => 'cta',
				'buttons' => array( array( 'text' => 'Go' ), array( 'text' => 'Later' ) ),
			)
		);
		$this->assertFalse( $cta['content_applied'] );
		$this->assertSame( array( 'buttons[1]' ), $cta['content_ignored'] );

		$over = $get(
			array(
				'name'    => 'hero',
				'buttons' => array( array( 'text' => 'A' ), array( 'text' => 'B' ), array( 'text' => 'C' ) ),
			)
		);
		$this->assertFalse( $over['content_applied'] );
		$this->assertSame( array( 'buttons[2]' ), $over['content_ignored'], 'A list entry past the limit is named too.' );

		$all = $get(
			array(
				'name'    => 'features',
				'heading' => 'Why us',
				'items'   => array( array( 'title' => 'Fast' ) ),
			)
		);
		$this->assertTrue( $all['content_applied'] );
		$this->assertArrayNotHasKey( 'content_ignored', $all );
	}

	public function test_a_recipe_filter_can_report_ignored_content() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$filter = static function () {
			return array(
				'builder'         => 'custom',
				'nodes'           => array( array( 'type' => 'core/paragraph' ) ),
				'content_applied' => false,
				'content_ignored' => array( 'quotes' ),
			);
		};
		add_filter( 'saddle_section_recipe', $filter, 5 );
		$got = wp_get_ability( 'saddle/get-section-recipe' )->execute(
			array(
				'name'   => 'hero',
				'quotes' => array( array( 'text' => 'Great' ) ),
			)
		);
		remove_filter( 'saddle_section_recipe', $filter, 5 );

		$this->assertFalse( $got['content_applied'] );
		$this->assertSame( array( 'quotes' ), $got['content_ignored'] );
	}

	/* -------- helpers -------- */

	/** @var string Accent the palette filter answers with. */
	public $accent = '#2ea3f2';

	public function palette() {
		return array(
			'gcid-primary-color'   => $this->accent,
			'gcid-secondary-color' => '#e09900',
			'gcid-heading-color'   => '#333333',
			'gcid-body-color'      => '#666666',
			'gcid-link-color'      => $this->accent,
		);
	}

	public function light_palette() {
		$this->accent = '#2ea3f2';
		return $this->palette();
	}

	private function use_divi_components() {
		$dir = Saddle_Test_Divi_Paths::components_or_skip();
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		Saddle_Divi_Schema::index( true );
	}

	private function content() {
		return Saddle_Recipe_Content::clean(
			array(
				'heading'   => 'Make Your Site Work',
				'text'      => 'Built for <strong>busy</strong> owners and teams.',
				'buttons'   => array(
					array(
						'text' => 'Start Free',
						'url'  => 'https://example.com/start',
					),
					array(
						'text' => 'Book a Demo',
						'url'  => '/demo',
					),
				),
				'items'     => array(
					array(
						'title' => 'Fast',
						'text'  => 'Loads quick.',
						'icon'  => array(
							'unicode' => '&#xe03c;',
							'type'    => 'divi',
							'weight'  => '400',
						),
					),
					array(
						'title' => 'Safe',
						'text'  => 'No surprises.',
					),
					array(
						'title' => 'Clear',
						'text'  => 'Plain words.',
					),
					array(
						'title' => 'Kind',
						'text'  => 'Real people answer.',
					),
					array(
						'title' => 'Open',
						'text'  => 'Your data stays yours.',
					),
				),
				'plans'     => array(
					array(
						'name'     => 'Go',
						'price'    => '$49',
						'period'   => 'year',
						'features' => array( '1 site' ),
					),
					array(
						'name'     => 'Pro',
						'price'    => 99,
						'featured' => true,
						'button'   => array(
							'text' => 'Buy Pro',
							'url'  => '/pro',
						),
					),
					array(
						'name'  => 'Team',
						'price' => 199,
					),
				),
				'quotes'    => array(
					array(
						'text' => 'Great.',
						'name' => 'Ana',
					),
					array(
						'text'    => 'Good.',
						'name'    => 'Bo',
						'role'    => 'CEO',
						'company' => 'Acme',
					),
				),
				'questions' => array(
					array(
						'question' => 'Is it free?',
						'answer'   => 'Yes.',
					),
				),
			)
		);
	}

	/**
	 * Every divi/row node in a recipe, depth first.
	 */
	private function rows( array $nodes ) {
		$rows = array();
		foreach ( $nodes as $node ) {
			if ( 'divi/row' === $node['type'] ) {
				$rows[] = $node;
			}
			if ( ! empty( $node['children'] ) ) {
				$rows = array_merge( $rows, $this->rows( $node['children'] ) );
			}
		}
		return $rows;
	}

	public function test_exactly_one_pricing_plan_is_featured() {
		$json = wp_json_encode( Saddle_Divi_Recipes::tree( 'pricing' ) );
		$this->assertSame( 1, substr_count( $json, 'featured' ), 'Feature ONE plan — never three identical cards.' );
	}

	public function test_sections_carry_the_shared_rhythm() {
		foreach ( self::RECIPES as $name ) {
			$json = wp_json_encode( Saddle_Divi_Recipes::tree( $name ) );
			$this->assertStringContainsString( '"top":"96px"', $json, "Recipe {$name} must carry the 96px section rhythm." );
		}
	}

	/* -------- the skill body -------- */

	private function skill_body() {
		$skills = apply_filters( 'saddle_builtin_skills', array() );
		foreach ( $skills as $skill ) {
			if ( 'divi-build-page' === $skill['name'] ) {
				return $skill['body'];
			}
		}
		$this->fail( 'The divi-build-page skill must be bundled while Divi is active.' );
	}

	public function test_skill_teaches_the_real_contracts() {
		$body = $this->skill_body();

		// The worked example used to teach fields {text, url} — a shape that
		// renders an empty button. It must teach the real one.
		$this->assertStringContainsString( '"button": { "text": "Shop Now", "linkUrl": "/shop" }', $body );
		$this->assertStringNotContainsString( '"url": "/shop"', $body );

		// The prefix is NOT optional.
		$this->assertStringNotContainsString( 'prefix optional', $body );
		$this->assertStringContainsString( 'never bare "text"', $body );

		// Placeholders survive to the agent (the wp_kses regression lives in
		// free; this pins the round-trip end to end).
		$this->assertStringContainsString( 'type=<module>', $body );
		$this->assertStringContainsString( 'post_id=<id>', $body );
	}

	/**
	 * The playbook said "0" is the first section. On a real page "0" is the
	 * root placeholder and the first section is "0.0" (the surgical tests
	 * address the first module as 0.0.0.0.0), so an agent counting from the
	 * playbook edited the wrong node.
	 */
	public function test_skill_addresses_match_the_tree() {
		$body = $this->skill_body();

		$this->assertStringNotContainsString( '"0" = first section', $body );
		$this->assertStringContainsString( '"0.0" the first section', $body );
	}

	public function test_skill_teaches_composition_and_the_stop_rebuilding_rule() {
		$body = $this->skill_body();

		$this->assertStringContainsString( '## Composition', $body );
		$this->assertStringContainsString( 'pinned-max-width', $body );
		$this->assertStringContainsString( 'single-column-flow', $body );
		$this->assertStringContainsString( 'NEVER set theme CSS classes', $body );
		$this->assertStringContainsString( 'FIRST build only', $body );
	}

	public function test_skill_embeds_frees_design_numbers_verbatim() {
		$body = $this->skill_body();

		// One source of truth: free's design_numbers() lines appear verbatim
		// (free ships the centering clause; drift between the two design
		// bars is what this pins against).
		$found = 0;
		foreach ( Saddle_Context::design_numbers() as $line ) {
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( false !== strpos( $body, $line ) ) {
				++$found;
			}
		}
		$this->assertGreaterThanOrEqual( 4, $found, 'The shared design bar must ride the skill verbatim.' );
		$this->assertStringContainsString( 'CENTER a width-capped text column', $body );
	}
}
