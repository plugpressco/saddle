<?php
/**
 * Dynamic content bindings: sub-field paths, the settings Divi reads, the
 * custom field guard, and warnings for a source that will not resolve.
 *
 * Fixture module.json files copy the content shapes of Divi 5.10.1's image,
 * button and heading modules. What Divi reads was found by reading its
 * source (DynamicContentUtils, DynamicContentOption* classes, the block
 * parser); no page is rendered here.
 *
 * @package Saddle
 */

class Saddle_Divi_Dynamic_Binding_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-dynamic-fixture-modules';

		$loop    = array( 'advanced' => array( 'loop' => (object) array() ) );
		$modules = array(
			'column'  => array(
				'name'       => 'divi/column',
				'attributes' => array( 'module' => array( 'settings' => $loop ) ),
			),
			'photo'   => array(
				'name'       => 'saddletest/photo',
				'attributes' => array(
					'module' => array( 'settings' => $loop ),
					'image'  => array(
						'elementType' => 'image',
						'settings'    => array(
							'innerContent' => array(
								'groupType' => 'group-items',
								'items'     => array(
									'src'     => array( 'subName' => 'src' ),
									'linkUrl' => array( 'subName' => 'linkUrl' ),
								),
							),
						),
					),
				),
			),
			'cta'     => array(
				'name'       => 'saddletest/cta',
				'attributes' => array(
					'module' => array( 'settings' => $loop ),
					'button' => array(
						'elementType' => 'button',
						'settings'    => array(
							'innerContent' => array(
								'groups' => array(
									'text' => array( 'item' => array( 'subName' => 'text' ) ),
									'link' => array( 'item' => array( 'attrName' => 'button.innerContent' ) ),
								),
							),
						),
					),
				),
			),
			'heading' => array(
				'name'       => 'saddletest/heading',
				'attributes' => array(
					'module' => array( 'settings' => $loop ),
					'title'  => array(
						'elementType' => 'heading',
						'settings'    => array( 'innerContent' => array( 'item' => array( 'label' => 'Title' ) ) ),
					),
				),
			),
		);
		foreach ( $modules as $dir => $json ) {
			$path = self::$fixture_dir . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				mkdir( $path, 0755, true );
			}
			file_put_contents( $path . '/module.json', wp_json_encode( $json ) );
		}
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );
		add_filter( 'saddle_divi_active', '__return_true' );
		$dir = self::$fixture_dir;
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
	}

	public function tear_down() {
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		remove_all_filters( 'saddle_dynamic_meta_allowlist' );
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		parent::tear_down();
	}

	/**
	 * Section > row > column > [photo, cta, heading].
	 *
	 * @return int
	 */
	private function page() {
		$photo = array( 'image' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'src' => 'https://example.com/a.jpg', 'alt' => 'A photo' ) ) ) ) );
		$cta   = array( 'button' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'text' => 'Read', 'linkUrl' => '/read' ) ) ) ) );
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:saddletest/photo ' . wp_json_encode( $photo ) . ' /-->',
						'<!-- wp:saddletest/cta ' . wp_json_encode( $cta ) . ' /-->',
						'<!-- wp:saddletest/heading /-->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);
	}

	private function attrs( $post_id, $address ) {
		$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $post_id )->post_content ), $address );
		return is_array( $node['attrs'] ) ? $node['attrs'] : array();
	}

	private function bind( array $input ) {
		return wp_get_ability( 'saddle/divi-apply-dynamic-content' )->execute( $input );
	}

	private function token( $value ) {
		$this->assertIsString( $value );
		$this->assertMatchesRegularExpression( '/^\$variable\(.*\)\$$/s', $value );
		return json_decode( substr( $value, strlen( '$variable(' ), -2 ), true )['value'];
	}

	/* -------- sub-field binding -------- */

	public function test_image_source_binds_without_replacing_the_rest_of_the_image() {
		$id     = $this->page();
		$result = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.0', 'field' => 'image.innerContent.src', 'source' => 'post_featured_image' ) );
		$this->assertNotWPError( $result );

		$value = $this->attrs( $id, '0.0.0.0' )['image']['innerContent']['desktop']['value'];
		$this->assertSame( 'post_featured_image', $this->token( $value['src'] )['name'] );
		$this->assertSame( 'A photo', $value['alt'], 'Binding the source keeps the alt text.' );
		$this->assertSame( 'image.innerContent.desktop.value.src', $result['field'] );
	}

	public function test_button_text_and_link_bind_separately() {
		$id = $this->page();
		$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.1', 'field' => 'button.innerContent.text', 'source' => 'post_title' ) );
		$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.1', 'field' => 'button.innerContent.linkUrl', 'source' => 'post_link_url' ) );

		$value = $this->attrs( $id, '0.0.0.1' )['button']['innerContent']['desktop']['value'];
		$this->assertSame( 'post_title', $this->token( $value['text'] )['name'] );
		$this->assertSame( 'post_link_url', $this->token( $value['linkUrl'] )['name'] );
	}

	public function test_binding_a_whole_object_field_is_refused_with_the_parts() {
		$id     = $this->page();
		$result = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.0', 'field' => 'image.innerContent', 'source' => 'post_featured_image' ) );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'image.innerContent.src', $result->get_error_message() );
		$this->assertSame( 'https://example.com/a.jpg', $this->attrs( $id, '0.0.0.0' )['image']['innerContent']['desktop']['value']['src'] );
	}

	public function test_unknown_part_and_a_part_of_a_text_field_are_refused() {
		$id = $this->page();

		$part = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.0', 'field' => 'image.innerContent.caption', 'source' => 'post_title' ) );
		$this->assertWPError( $part );
		$this->assertStringContainsString( 'src', $part->get_error_message() );

		$text = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent.text', 'source' => 'post_title' ) );
		$this->assertWPError( $text );
	}

	public function test_text_field_binding_keeps_working() {
		$id     = $this->page();
		$result = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'post_title' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'post_title', $this->token( $this->attrs( $id, '0.0.0.2' )['title']['innerContent']['desktop']['value'] )['name'] );
	}

	public function test_breakpoint_and_full_paths_place_the_binding() {
		$id = $this->page();
		$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'site_title', 'breakpoint' => 'tablet' ) );
		$this->assertSame( 'site_title', $this->token( $this->attrs( $id, '0.0.0.2' )['title']['innerContent']['tablet']['value'] )['name'] );

		$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'module.advanced.link.desktop.value.url', 'source' => 'post_link_url' ) );
		$this->assertSame( 'post_link_url', $this->token( $this->attrs( $id, '0.0.0.2' )['module']['advanced']['link']['desktop']['value']['url'] )['name'] );

		$bad = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'site_title', 'breakpoint' => 'watch' ) );
		$this->assertWPError( $bad );
	}

	public function test_clear_undoes_one_part_or_every_part() {
		$id = $this->page();
		$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.1', 'field' => 'button.innerContent.text', 'source' => 'post_title' ) );
		$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.1', 'field' => 'button.innerContent.linkUrl', 'source' => 'post_link_url' ) );

		$clear = wp_get_ability( 'saddle/divi-clear-dynamic-content' );
		$clear->execute( array( 'post_id' => $id, 'address' => '0.0.0.1', 'field' => 'button.innerContent.text', 'value' => 'Read more' ) );
		$value = $this->attrs( $id, '0.0.0.1' )['button']['innerContent']['desktop']['value'];
		$this->assertSame( 'Read more', $value['text'] );
		$this->assertStringContainsString( '$variable(', $value['linkUrl'], 'Clearing one part leaves the other bound.' );

		$clear->execute( array( 'post_id' => $id, 'address' => '0.0.0.1', 'field' => 'button.innerContent' ) );
		$value = $this->attrs( $id, '0.0.0.1' )['button']['innerContent']['desktop']['value'];
		$this->assertSame( array( 'text' => 'Read more', 'linkUrl' => '' ), $value, 'Clearing the whole field removes every binding in it and keeps the object.' );

		$refused = $clear->execute( array( 'post_id' => $id, 'address' => '0.0.0.1', 'field' => 'button.innerContent', 'value' => 'x' ) );
		$this->assertWPError( $refused, 'A static value replaces one part, never the whole object.' );
	}

	/* -------- B4: custom fields -------- */

	public function test_custom_field_binding_writes_the_meta_key_setting_divi_reads() {
		$id = $this->page();
		$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'post_meta_key', 'settings' => array( 'key' => 'subtitle' ) ) );

		$settings = $this->token( $this->attrs( $id, '0.0.0.2' )['title']['innerContent']['desktop']['value'] )['settings'];
		$this->assertSame( 'subtitle', $settings['meta_key'] );
		$this->assertSame( 'custom_meta_manual_custom_field_value', $settings['select_meta_key'] );
		$this->assertArrayNotHasKey( 'key', $settings, 'Divi never reads settings.key.' );
	}

	public function test_loop_custom_field_binding_writes_the_loop_settings_divi_reads() {
		$id = $this->page();
		foreach ( array( 'loop_post_meta_key', 'loop_manual_custom_field', 'loop_post_meta_key_manual_custom_field' ) as $source ) {
			$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => $source, 'settings' => array( 'meta_key' => 'price' ) ) );

			$token = $this->token( $this->attrs( $id, '0.0.0.2' )['title']['innerContent']['desktop']['value'] );
			$this->assertSame( 'loop_post_meta_key_manual_custom_field', $token['name'], $source );
			$this->assertSame( 'price', $token['settings']['loop_meta_key'] );
			$this->assertSame( 'loop_post_meta_key_manual_custom_field_value', $token['settings']['select_loop_meta_key'] );
		}
	}

	public function test_guards_every_meta_key_form() {
		$id    = $this->page();
		$forms = array(
			array( 'post_meta_key', array( 'key' => '_secret' ) ),
			array( 'post_meta_key', array( 'meta_key' => '_secret' ) ),
			array( 'post_meta_key', array( 'select_meta_key' => 'custom_meta__secret' ) ),
			array( 'custom_meta__secret', array() ),
			array( 'custom_meta_manual_custom_field_value', array( 'meta_key' => '_secret' ) ),
			array( 'loop_post_meta_key_manual_custom_field', array( 'loop_meta_key' => '_secret' ) ),
			array( 'loop_post_meta_key_manual_custom_field', array( 'meta_key' => '_secret' ) ),
			array( 'loop_post_meta_key_manual_custom_field', array( 'select_loop_meta_key' => 'loop_post_meta_key__secret' ) ),
			array( 'loop_term_meta_key_manual_custom_field', array( 'loop_meta_key' => '_secret' ) ),
			array( 'loop_post_meta_key__secret', array() ),
			array( 'loop_user_meta_key_manual_custom_field', array( 'loop_meta_key' => '_secret' ) ),
			array( 'loop_user_meta_key_manual_custom_field', array( 'loop_meta_key' => 'session_tokens' ) ),
			array( 'loop_user_meta_key_manual_custom_field', array( 'loop_meta_key' => 'wp_capabilities' ) ),
		);
		foreach ( $forms as $form ) {
			$result = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => $form[0], 'settings' => $form[1] ) );
			$this->assertWPError( $result, wp_json_encode( $form ) );
			$this->assertSame( 'saddle_protected_meta', $result->get_error_code(), wp_json_encode( $form ) );
		}
		$this->assertArrayNotHasKey( 'title', $this->attrs( $id, '0.0.0.2' ), 'A refused binding writes nothing.' );

		add_filter(
			'saddle_dynamic_meta_allowlist',
			static function () {
				return array( '_public_by_design' );
			}
		);
		$allowed = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'custom_meta__public_by_design' ) );
		$this->assertNotWPError( $allowed );

		$plain = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'loop_user_meta_key_manual_custom_field', 'settings' => array( 'loop_meta_key' => 'nickname' ) ) );
		$this->assertNotWPError( $plain, 'An administrator may bind a plain user field.' );
	}

	public function test_user_custom_fields_need_an_administrator() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$id     = $this->page();
		wp_set_current_user( $editor );

		$result = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'loop_user_meta_key_manual_custom_field', 'settings' => array( 'loop_meta_key' => 'nickname' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_protected_meta', $result->get_error_code() );
	}

	public function test_user_email_binding_needs_an_administrator() {
		$refused = Saddle_Divi_Dynamic::prepare_settings( 'loop_user_email', array() );
		$this->assertIsArray( $refused, 'An administrator may bind it.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$refused = Saddle_Divi_Dynamic::prepare_settings( 'loop_user_email', array() );
		$this->assertWPError( $refused );
		$this->assertSame( 'saddle_protected_source', $refused->get_error_code() );
	}

	public function test_numbers_and_booleans_in_settings_are_stored_as_strings() {
		$id = $this->page();
		$this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'post_excerpt', 'settings' => array( 'words' => 20, 'enable_html' => false ) ) );

		$settings = $this->token( $this->attrs( $id, '0.0.0.2' )['title']['innerContent']['desktop']['value'] )['settings'];
		$this->assertSame( '20', $settings['words'], 'Divi drops a setting that is not a string.' );
		$this->assertSame( 'off', $settings['enable_html'] );
	}

	/* -------- B3: where a source resolves -------- */

	private function loop_on_column( $id, array $input = array() ) {
		$result = wp_get_ability( 'saddle/divi-enable-loop' )->execute( array_merge( array( 'post_id' => $id, 'address' => '0.0.0' ), $input ) );
		$this->assertNotWPError( $result );
	}

	public function test_page_post_title_inside_a_loop_warns_to_use_loop_post_title() {
		$id = $this->page();
		$this->loop_on_column( $id );

		$result = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'post_title' ) );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'loop_post_title', $result['warnings'][0] );
		$this->assertStringContainsString( '0.0.0', $result['warnings'][0] );

		$per_item = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'loop_post_title' ) );
		$this->assertArrayNotHasKey( 'warnings', $per_item );
	}

	public function test_loop_source_outside_a_loop_warns() {
		$result = $this->bind( array( 'post_id' => $this->page(), 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'loop_post_title' ) );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'divi-enable-loop', $result['warnings'][0] );
	}

	public function test_loop_source_of_another_family_warns() {
		$id = $this->page();
		$this->loop_on_column( $id );

		$result = $this->bind( array( 'post_id' => $id, 'address' => '0.0.0.2', 'field' => 'title.innerContent', 'source' => 'loop_term_name' ) );
		$this->assertStringContainsString( 'post_taxonomies', $result['warnings'][0] );
	}
}
