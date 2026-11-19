<?php
/**
 * saddle/divi-get-site-style and the bundle's "site_style": how the site's
 * own Divi pages are styled.
 *
 * @package Saddle
 */

class Saddle_Divi_Site_Style_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'saddle_divi_active', '__return_true' );
		add_filter(
			'saddle_divi_global_colors',
			static function () {
				return array(
					'gcid-primary-color'                        => '#2ea3f2',
					'gcid-f2f5be0f-42f6-4651-8a83-998db293876a' => '#0B0E1F',
				);
			}
		);
		Saddle_Divi_Site_Style::flush();
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		remove_all_filters( 'saddle_divi_global_colors' );
		remove_all_actions( 'pre_get_posts' );
		remove_all_filters( 'posts_search' );
		add_action( 'save_post', array( 'Saddle_Divi_Site_Style', 'saved' ), 10, 2 );
		update_option( 'show_on_front', 'posts' );
		Saddle_Divi_Site_Style::flush();
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private static function value( $value ) {
		return array( 'desktop' => array( 'value' => $value ) );
	}

	/**
	 * A stored block, its attrs encoded the way WordPress saves them (quotes
	 * as \u0022), so a $variable() token survives the save.
	 */
	private function block( $type, array $attrs, $inner = '' ) {
		return sprintf( '<!-- wp:%1$s %2$s-->%3$s<!-- /wp:%1$s -->', $type, $attrs ? serialize_block_attributes( $attrs ) . ' ' : '', $inner );
	}

	/**
	 * A section with a background, padding and modules in one row.
	 *
	 * @param string $background Background color, '' for none.
	 * @param array  $padding    Padding by breakpoint ({desktop: {top, bottom}, phone: …}).
	 * @param array  $modules    Module blocks.
	 */
	private function section( $background, array $padding, array $modules = array() ) {
		$module = array();
		if ( '' !== $background ) {
			$module['decoration']['background'] = self::value( array( 'color' => $background ) );
		}
		foreach ( $padding as $breakpoint => $sides ) {
			$module['decoration']['spacing'][ $breakpoint ]['value']['padding'] = $sides;
		}
		$row = $this->block( 'divi/row', array( 'module' => array( 'decoration' => array( 'sizing' => self::value( array( 'maxWidth' => '1200px' ) ) ) ) ), '<!-- wp:divi/column -->' . implode( '', $modules ) . '<!-- /wp:divi/column -->' );
		return $this->block( 'divi/section', $module ? array( 'module' => $module ) : array(), $row );
	}

	private function heading( $text, $level, $size, array $extra = array() ) {
		$font = array_replace( array( 'headingLevel' => $level ), null === $size ? array() : array( 'size' => $size ), $extra );
		return $this->block(
			'divi/heading',
			array(
				'title' => array(
					'innerContent' => self::value( $text ),
					'decoration'   => array( 'font' => array( 'font' => self::value( $font ) ) ),
				),
			)
		);
	}

	private function page( array $sections, array $args = array() ) {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					// wp_insert_post() unslashes, as a save from the editor does.
					'post_content' => wp_slash( '<!-- wp:divi/placeholder -->' . implode( '', $sections ) . '<!-- /wp:divi/placeholder -->' ),
				),
				$args
			)
		);
	}

	private function style() {
		return wp_get_ability( 'saddle/divi-get-site-style' )->execute( array() );
	}

	private static function map( $list ) {
		return (array) $list;
	}

	/* -------- tests -------- */

	public function test_values_are_picked_by_count_most_used_first() {
		$top = array(
			'desktop' => array(
				'top'    => '96px',
				'bottom' => '96px',
			),
		);
		$this->page(
			array(
				$this->section( '#FFFFFF', $top, array( $this->heading( 'One', 'h2', '40px' ) ) ),
				$this->section( '#0B0E1F', array( 'desktop' => array( 'top' => '64px' ) ), array( $this->heading( 'Two', 'h2', '32px' ) ) ),
			)
		);
		$this->page(
			array(
				$this->section( '#fff', $top, array( $this->heading( 'Three', 'h2', '40px' ) ) ),
				$this->section( '#ffffff', $top ),
			)
		);

		$style = $this->style();

		$this->assertSame(
			array(
				'#ffffff' => 3,
				'#0b0e1f' => 1,
			),
			self::map( $style['colors']['section_backgrounds'] ),
			'#FFFFFF, #fff and #ffffff are one color; the most used comes first.'
		);
		$this->assertSame(
			array(
				'96px' => 3,
				'64px' => 1,
			),
			self::map( $style['spacing']['section_padding_top'] )
		);
		$this->assertSame(
			array(
				'96px'    => 3,
				'default' => 1,
			),
			self::map( $style['spacing']['section_padding_bottom'] ),
			'Unset padding counts as "default".'
		);
		$this->assertSame( array( '1200px' => 4 ), self::map( $style['spacing']['row_max_width'] ) );
		$this->assertSame(
			array(
				'40px' => 2,
				'32px' => 1,
			),
			self::map( $style['type']['h2'] )
		);
		$this->assertCount( 2, $style['pages'] );
		$this->assertSame( array( 'id', 'title', 'link' ), array_keys( $style['pages'][0] ) );
		$this->assertStringContainsString( '"default"', $style['note'] );
	}

	public function test_a_global_color_token_is_matched() {
		$this->page(
			array(
				$this->section(
					'#0b0e1f',
					array(),
					array(
						$this->heading( 'Brand', 'h1', '56px', array( 'color' => 'var(--gcid-primary-color)' ) ),
						$this->heading( 'Token form', 'h3', null, array( 'color' => '$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$' ) ),
					)
				),
				$this->section( '#123456', array() ),
			)
		);

		$style = $this->style();

		$this->assertSame( 'var(--gcid-f2f5be0f-42f6-4651-8a83-998db293876a)', $style['tokens']['#0b0e1f'], 'A hand-set hex that matches a global color gets its token.' );
		$this->assertSame( array( '#2ea3f2' => 2 ), self::map( $style['colors']['headings'] ), 'Both token forms resolve to the palette value.' );
		$this->assertSame( 'var(--gcid-primary-color)', $style['tokens']['#2ea3f2'] );
		$this->assertArrayNotHasKey( '#123456', $style['tokens'], 'A color no global color matches has no token.' );
	}

	public function test_phone_values_follow_divis_inheritance() {
		$this->page(
			array(
				$this->section(
					'',
					array(
						'desktop' => array(
							'top'    => '96px',
							'bottom' => '96px',
						),
						'phone'   => array( 'top' => '48px' ),
					),
					array(
						$this->block(
							'divi/heading',
							array(
								'title' => array(
									'innerContent' => self::value( 'Hero' ),
									'decoration'   => array(
										'font' => array(
											'font' => array(
												'desktop' => array(
													'value' => array(
														'headingLevel' => 'h1',
														'size'         => '56px',
													),
												),
												'tablet'  => array( 'value' => array( 'size' => '40px' ) ),
											),
										),
									),
								),
							)
						),
						$this->block(
							'divi/text',
							array(
								'content' => array(
									'innerContent' => self::value( '<p>Body copy.</p>' ),
									'decoration'   => array( 'bodyFont' => array( 'body' => array( 'font' => self::value( array( 'size' => '18px' ) ) ) ) ),
								),
							)
						),
					)
				),
			)
		);

		$style = $this->style();

		$this->assertSame( array( '48px' => 1 ), self::map( $style['spacing']['section_padding_top@phone'] ) );
		$this->assertArrayNotHasKey( 'section_padding_bottom@phone', $style['spacing'], 'The phone bottom inherits 96px, as on desktop, so it is not repeated.' );
		$this->assertSame( array( '56px' => 1 ), self::map( $style['type']['h1'] ) );
		$this->assertSame( array( '40px' => 1 ), self::map( $style['type']['h1@phone'] ), 'A phone with no size of its own shows the tablet\'s.' );
		$this->assertSame( array( '18px' => 1 ), self::map( $style['type']['body'] ) );
		$this->assertArrayNotHasKey( 'body@phone', $style['type'] );
	}

	public function test_buttons_cards_fonts_and_presets() {
		$button = $this->block(
			'divi/button',
			array(
				'modulePreset' => array( 'preset-cta' ),
				'button'       => array(
					'innerContent' => self::value( array( 'text' => 'Start' ) ),
					'decoration'   => array(
						'button'     => self::value( array( 'enable' => 'on' ) ),
						'background' => self::value( array( 'color' => '#4C3FFF' ) ),
						'font'       => array(
							'font' => self::value(
								array(
									'color'  => '#ffffff',
									'family' => 'Inter',
									'weight' => '600',
								)
							),
						),
						'border'     => self::value(
							array(
								'radius' => array(
									'topLeft'     => '6px',
									'topRight'    => '6px',
									'bottomRight' => '6px',
									'bottomLeft'  => '6px',
								),
							)
						),
					),
				),
				'module'       => array(
					'decoration' => array(
						'spacing' => self::value(
							array(
								'padding' => array(
									'top'    => '12px',
									'right'  => '24px',
									'bottom' => '12px',
									'left'   => '24px',
								),
							)
						),
					),
				),
			)
		);
		$card = $this->block(
			'divi/group',
			array(
				'module' => array(
					'decoration' => array(
						'background' => self::value( array( 'color' => '#F4F1FB' ) ),
						'border'     => self::value( array( 'radius' => array( 'topLeft' => '12px' ) ) ),
						'boxShadow'  => self::value( array( 'style' => 'preset2' ) ),
					),
				),
			),
			$button
		);
		$this->page( array( $this->section( '', array(), array( $card, $button ) ) ) );

		$style = $this->style();

		$this->assertSame( array( '#4c3fff' => 2 ), self::map( $style['colors']['buttons'] ) );
		$this->assertSame( array( '#ffffff' => 2 ), self::map( $style['colors']['button_text'] ) );
		$this->assertSame( array( '6px' => 2 ), self::map( $style['buttons']['radius'] ), 'Four equal corners read as one value.' );
		$this->assertSame( array( '12px 24px 12px 24px' => 2 ), self::map( $style['buttons']['padding'] ), 'A divi/button keeps its padding on the module.' );
		$this->assertSame( array( 'changes' => 2 ), self::map( $style['buttons']['hover'] ), 'A preset or Divi\'s sliding icon changes a button on hover.' );
		$this->assertSame( array( '#f4f1fb' => 1 ), self::map( $style['colors']['card_backgrounds'] ) );
		$this->assertSame( array( '12px 0 0 0' => 1 ), self::map( $style['cards']['radius'] ) );
		$this->assertSame( array( 'preset2' => 1 ), self::map( $style['cards']['shadow'] ) );
		$this->assertSame( array( 'default' => 1 ), self::map( $style['cards']['padding'] ) );
		$this->assertSame( array( 'Inter 600' => 2 ), self::map( $style['type']['fonts'] ) );
		$this->assertSame(
			array(
				array(
					'id'   => 'preset-cta',
					'uses' => 2,
				),
			),
			$style['presets'],
			'Presets are counted by id; their names need Divi\'s preset data.'
		);
	}

	public function test_a_private_draft_or_password_page_is_never_read_for_a_reader() {
		$this->page( array( $this->section( '#111111', array() ) ), array( 'post_status' => 'draft' ) );
		$this->page( array( $this->section( '#222222', array() ) ), array( 'post_status' => 'private' ) );
		$locked = $this->page( array( $this->section( '#333333', array() ) ), array( 'post_password' => 'secret' ) );
		$open   = $this->page( array( $this->section( '#444444', array() ) ) );

		// The administrator asks first and fills the cache: they may edit the
		// password page, so they read it.
		$admin = $this->style();
		$this->assertEqualsCanonicalizing( array( $locked, $open ), wp_list_pluck( $admin['pages'], 'id' ) );
		$this->assertArrayHasKey( '#333333', self::map( $admin['colors']['section_backgrounds'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$style = $this->style();

		$this->assertSame( array( $open ), wp_list_pluck( $style['pages'], 'id' ) );
		$this->assertSame( array( '#444444' => 1 ), self::map( $style['colors']['section_backgrounds'] ) );
		$this->assertStringNotContainsString( '#333333', wp_json_encode( $style ), 'Nothing of the password page reaches the subscriber through the shared cache.' );
	}

	public function test_a_page_that_turns_private_drops_out_while_the_cache_is_full() {
		remove_action( 'save_post', array( 'Saddle_Divi_Site_Style', 'saved' ), 10 );
		$secret = $this->page( array( $this->section( '#111111', array() ) ) );
		$this->page( array( $this->section( '#222222', array() ) ) );
		$this->assertCount( 2, $this->style()['pages'] );

		wp_update_post(
			array(
				'ID'          => $secret,
				'post_status' => 'private',
			)
		);
		$this->assertContains( $secret, get_transient( Saddle_Divi_Site_Style::TRANSIENT )['ids'], 'The shared candidate list still names it.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$style = $this->style();
		$this->assertSame( array( '#222222' => 1 ), self::map( $style['colors']['section_backgrounds'] ), 'Each page is checked again on every call.' );
	}

	public function test_a_palette_change_is_never_served_stale() {
		$this->page( array( $this->section( 'var(--gcid-brand)', array() ) ) );
		remove_all_filters( 'saddle_divi_global_colors' );
		add_filter(
			'saddle_divi_global_colors',
			static function () {
				return array( 'gcid-brand' => '#111111' );
			}
		);
		$this->assertSame( array( '#111111' => 1 ), self::map( $this->style()['colors']['section_backgrounds'] ) );

		remove_all_filters( 'saddle_divi_global_colors' );
		add_filter(
			'saddle_divi_global_colors',
			static function () {
				return array( 'gcid-brand' => '#222222' );
			}
		);
		$style = $this->style();
		$this->assertSame( array( '#222222' => 1 ), self::map( $style['colors']['section_backgrounds'] ) );
		$this->assertSame( 'var(--gcid-brand)', $style['tokens']['#222222'] );
	}

	public function test_an_update_clears_the_cache() {
		$this->page( array( $this->section( '#111111', array() ) ) );
		$this->style();
		$this->assertNotFalse( has_action( 'upgrader_process_complete', array( 'Saddle_Divi_Site_Style', 'flush' ) ), 'An update of Divi, SaddleWP or anything else clears it.' );
		Saddle_Divi_Site_Style::flush();
		$this->assertFalse( get_transient( Saddle_Divi_Site_Style::TRANSIENT ) );
	}

	public function test_module_backgrounds_are_kept_apart_from_cards() {
		$text  = $this->block( 'divi/text', array( 'module' => array( 'decoration' => array( 'background' => self::value( array( 'color' => '#abcdef' ) ) ) ) ) );
		$plain = $this->block( 'divi/group', array( 'module' => array( 'decoration' => array( 'background' => self::value( array( 'color' => '#fedcba' ) ) ) ) ) );
		$face  = $this->block( 'saddletest/flip', array( 'back' => array( 'decoration' => array( 'background' => self::value( array( 'color' => '#0B0E1F' ) ) ) ) ) );
		$this->page( array( $this->section( '', array(), array( $text, $plain, $face ) ) ) );

		$style = $this->style();
		$this->assertSame(
			array(
				'#abcdef' => 1,
				'#fedcba' => 1,
				'#0b0e1f' => 1,
			),
			self::map( $style['colors']['module_backgrounds'] ),
			'A group with no radius or shadow is not a card; a flip card\'s face counts here too.'
		);
		$this->assertArrayNotHasKey( 'card_backgrounds', $style['colors'] );
		$this->assertStringNotContainsString( 'module_backgrounds', wp_json_encode( Saddle_Divi_Site_Style::for_bundle() ), 'The bundle leaves module backgrounds out.' );
	}

	public function test_every_button_element_counts() {
		$button = static function ( $color ) {
			return array(
				'decoration' => array(
					'button'     => self::value( array( 'enable' => 'on' ) ),
					'background' => self::value( array( 'color' => $color ) ),
				),
			);
		};
		$this->page(
			array(
				$this->section(
					'',
					array(),
					array(
						$this->block(
							'saddletest/hero',
							array(
								'buttonOne'      => $button( '#111111' ),
								'buttonTwo'      => $button( '#222222' ),
								'readMoreButton' => $button( '#111111' ),
							)
						),
					)
				),
			)
		);

		$style = $this->style();
		$this->assertSame(
			array(
				'#111111' => 2,
				'#222222' => 1,
			),
			self::map( $style['colors']['buttons'] )
		);
		$this->assertArrayNotHasKey( 'module_backgrounds', $style['colors'], 'A button fill is not a module background.' );
	}

	public function test_long_values_are_cut_and_junk_colors_skipped() {
		$this->page(
			array(
				$this->section(
					'not a color; background: url(x)',
					array(),
					array( $this->heading( 'Long', 'h2', '40px', array( 'family' => str_repeat( 'Family ', 60 ) ) ) )
				),
				$this->section( '#123456', array() ),
			)
		);

		$style = $this->style();
		$this->assertSame( array( '#123456' => 1 ), self::map( $style['colors']['section_backgrounds'] ), 'Text that is not a color is not counted.' );
		foreach ( array_keys( self::map( $style['type']['fonts'] ) ) as $font ) {
			$this->assertLessThanOrEqual( Saddle_Divi_Style_Tally::VALUE_MAX, mb_strlen( (string) $font ) );
		}
		$this->assertLessThanOrEqual( Saddle_Divi_Site_Style::BUNDLE_BYTES, strlen( wp_json_encode( Saddle_Divi_Site_Style::for_bundle() ) ) );
	}

	public function test_a_search_filter_cannot_hide_the_pages() {
		$id = $this->page( array( $this->section( '#111111', array() ) ) );
		// A common snippet: keep pages out of search results.
		add_action(
			'pre_get_posts',
			static function ( $query ) {
				if ( $query->is_search() ) {
					$query->set( 'post_type', 'post' );
				}
			}
		);
		add_filter( 'posts_search', '__return_empty_string' );

		$this->assertSame( array( $id ), wp_list_pluck( $this->style()['pages'], 'id' ) );
	}

	public function test_the_front_page_comes_first() {
		$front = $this->page( array( $this->section( '#aaaaaa', array() ) ), array( 'post_modified' => '2020-01-01 00:00:00' ) );
		$this->page( array( $this->section( '#bbbbbb', array() ) ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$this->assertSame( $front, $this->style()['pages'][0]['id'] );
	}

	public function test_the_cache_is_used_and_cleared_when_a_divi_page_is_saved() {
		global $wpdb;
		$id = $this->page( array( $this->section( '#111111', array() ) ) );
		$this->assertSame( array( '#111111' => 1 ), self::map( $this->style()['colors']['section_backgrounds'] ) );
		$this->assertNotFalse( get_transient( Saddle_Divi_Site_Style::TRANSIENT ) );

		// Changed behind WordPress's back: same modified date, so the cache answers.
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<!-- wp:divi/placeholder -->' . $this->section( '#222222', array() ) . '<!-- /wp:divi/placeholder -->' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$this->assertSame( array( '#111111' => 1 ), self::map( $this->style()['colors']['section_backgrounds'] ) );

		// Saving any Divi page clears it.
		$other = $this->page( array( $this->section( '#333333', array() ) ), array( 'post_status' => 'draft' ) );
		Saddle_Divi_Site_Style::flush();
		$this->style();
		wp_update_post(
			array(
				'ID'         => $other,
				'post_title' => 'Still a draft',
			)
		);
		$this->assertFalse( get_transient( Saddle_Divi_Site_Style::TRANSIENT ), 'save_post on a Divi page clears the cache.' );
		$this->assertSame( array( '#222222' => 1 ), self::map( $this->style()['colors']['section_backgrounds'] ) );
	}

	public function test_saving_a_page_that_is_not_divi_keeps_the_cache() {
		$this->page( array( $this->section( '#111111', array() ) ) );
		$this->style();
		self::factory()->post->create( array( 'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) );
		$this->assertNotFalse( get_transient( Saddle_Divi_Site_Style::TRANSIENT ) );
	}

	public function test_the_bundle_holds_its_budget_on_a_page_with_many_sections() {
		$colors = array();
		for ( $i = 0; $i < 240; $i++ ) {
			$colors[ 'gcid-' . wp_generate_uuid4() ] = sprintf( '#%06x', $i * 4097 );
		}
		remove_all_filters( 'saddle_divi_global_colors' );
		add_filter(
			'saddle_divi_global_colors',
			static function () use ( $colors ) {
				return $colors;
			}
		);
		$ids      = array_keys( $colors );
		$token    = static function ( $n ) use ( $ids ) {
			return 'var(--' . $ids[ $n ] . ')';
		};
		$sections = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$px      = static function ( $base ) use ( $i ) {
				return ( $base + $i ) . 'px';
			};
			$button  = $this->block(
				'divi/button',
				array(
					'modulePreset' => array( 'preset-' . wp_generate_uuid4() ),
					'button'       => array(
						'decoration' => array(
							'button'     => self::value( array( 'enable' => 'on' ) ),
							'background' => self::value( array( 'color' => $token( 40 + $i ) ) ),
							'font'       => array(
								'font' => self::value(
									array(
										'color'  => $token( 80 + $i ),
										'family' => 'Font ' . $i,
										'weight' => (string) ( 100 + $i ),
									)
								),
							),
							'border'     => self::value( array( 'radius' => array( 'topLeft' => $px( 1 ) ) ) ),
							'spacing'    => self::value( array( 'padding' => array( 'top' => $px( 2 ) ) ) ),
						),
					),
				)
			);
			$text    = $this->block(
				'divi/text',
				array(
					'content' => array(
						'innerContent' => self::value( '<p>Copy</p>' ),
						'decoration'   => array(
							'bodyFont' => array(
								'body' => array(
									'font' => array(
										'desktop' => array(
											'value' => array(
												'size'  => $px( 14 ),
												'color' => $token( 120 + $i ),
											),
										),
										'phone'   => array( 'value' => array( 'size' => $px( 12 ) ) ),
									),
								),
							),
						),
					),
				)
			);
			$heading = $this->block(
				'divi/heading',
				array(
					'title' => array(
						'innerContent' => self::value( 'Heading ' . $i ),
						'decoration'   => array(
							'font' => array(
								'font' => array(
									'desktop' => array(
										'value' => array(
											'headingLevel' => 'h' . ( 1 + $i % 6 ),
											'size'         => $px( 20 ),
											'color'        => $token( 160 + $i ),
										),
									),
									'phone'   => array( 'value' => array( 'size' => $px( 10 ) ) ),
								),
							),
						),
					),
				)
			);
			$card    = $this->block(
				'divi/group',
				array(
					'module' => array(
						'decoration' => array(
							'background' => self::value( array( 'color' => $token( 200 + $i ) ) ),
							'border'     => self::value( array( 'radius' => array( 'topLeft' => $px( 3 ) ) ) ),
							'boxShadow'  => self::value( array( 'style' => 'preset' . $i ) ),
							'spacing'    => self::value( array( 'padding' => array( 'top' => $px( 4 ) ) ) ),
						),
					),
				),
				$heading . $text . $button
			);

			$sections[] = $this->section(
				$token( $i ),
				array(
					'desktop' => array(
						'top'    => $px( 40 ),
						'bottom' => $px( 41 ),
					),
					'phone'   => array(
						'top'    => $px( 10 ),
						'bottom' => $px( 11 ),
					),
				),
				array( $card )
			);
		}
		for ( $p = 0; $p < 6; $p++ ) {
			$this->page( $sections );
		}

		$bundle = Saddle_Divi_Bundle_Abilities::context_bundle();
		$style  = $bundle['site_style'];
		$this->assertLessThanOrEqual( Saddle_Divi_Site_Style::BUNDLE_BYTES, strlen( wp_json_encode( $style ) ) );
		$this->assertNotEmpty( $style['colors']['section_backgrounds'] );
		$this->assertNotEmpty( $style['tokens'], 'Colors and their tokens always stay.' );
		$this->assertCount( 6, $style['pages'] );
		$this->assertIsInt( $style['pages'][0], 'The bundle lists page ids only.' );

		$tool = $this->style();
		$this->assertCount( Saddle_Divi_Site_Style::TOOL_LIMIT, self::map( $tool['colors']['section_backgrounds'] ), 'The tool keeps longer lists.' );
		$this->assertCount( Saddle_Divi_Site_Style::TOOL_LIMIT, $tool['presets'] );
		$this->assertArrayHasKey( 'h1@phone', $tool['type'] );
	}

	public function test_the_bundle_drops_whole_parts_before_it_goes_over_budget() {
		$this->page(
			array(
				$this->section(
					'#0b0e1f',
					array(),
					array_merge(
						// Junk sizes, each still 80 characters after the cut, on every level.
						array_map(
							function ( $level ) {
								return $this->block(
									'divi/heading',
									array(
										'title' => array(
											'innerContent' => self::value( 'Heading' ),
											'decoration'   => array(
												'font' => array(
													'font' => array(
														'desktop' => array(
															'value' => array(
																'headingLevel' => 'h' . $level,
																'size'         => str_repeat( 'd', 100 ),
															),
														),
														'phone'   => array( 'value' => array( 'size' => str_repeat( 'p', 100 ) ) ),
													),
												),
											),
										),
									)
								);
							},
							range( 1, 6 )
						),
						array( $this->block( 'divi/group', array( 'modulePreset' => array( 'preset-one' ) ) ) )
					)
				),
			)
		);

		$style = Saddle_Divi_Site_Style::for_bundle();

		$this->assertLessThanOrEqual( Saddle_Divi_Site_Style::BUNDLE_BYTES, strlen( wp_json_encode( $style ) ) );
		$this->assertArrayHasKey( 'cut', $style );
		$this->assertArrayNotHasKey( 'type', $style, 'The part too long to fit goes.' );
		$this->assertArrayNotHasKey( 'presets', $style, 'Presets go first.' );
		$this->assertSame( array( '#0b0e1f' => 1 ), self::map( $style['colors']['section_backgrounds'] ), 'Colors always stay.' );
		$this->assertArrayHasKey( 'spacing', $style, 'Parts are dropped only until it fits.' );
	}

	public function test_the_bundle_cache_never_holds_the_style() {
		$this->page( array( $this->section( '#111111', array() ) ) );
		Saddle_Divi_Bundle::flush();

		$bundle = Saddle_Divi_Bundle_Abilities::context_bundle();

		$this->assertArrayHasKey( 'site_style', $bundle );
		$this->assertArrayNotHasKey( 'site_style', get_transient( Saddle_Divi_Bundle::TRANSIENT ), 'The bundle cache is shared by every account; the style depends on what each may read.' );
	}

	public function test_no_divi_pages_gives_an_empty_explained_answer() {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Not Divi.</p><!-- /wp:paragraph -->',
			)
		);

		$style = $this->style();

		$this->assertSame( array(), $style['pages'] );
		$this->assertSame( array( 'pages', 'note' ), array_keys( $style ) );
		$this->assertStringContainsString( 'No published Divi page', $style['note'] );
	}

	public function test_divi_must_be_active_and_the_tool_needs_read_tier_only() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		$this->assertSame( 'saddle_no_divi', $this->style()->get_error_code() );
		add_filter( 'saddle_divi_active', '__return_true' );

		Saddle_Capabilities::set_tier( 'read' );
		$this->assertTrue( wp_get_ability( 'saddle/divi-get-site-style' )->check_permissions( array() ) );

		wp_set_current_user( 0 );
		$this->assertNotTrue( wp_get_ability( 'saddle/divi-get-site-style' )->check_permissions( array() ), 'Refused without a signed-in account.' );
	}
}
