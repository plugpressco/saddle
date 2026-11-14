<?php
/**
 * The rules of the loop tools, divi-apply-dynamic-content and
 * divi-add-interaction hold for every way a value reaches a saved page:
 * raw attrs, fields, a whole page, or a direct save. Values a page already
 * holds stay editable around.
 *
 * @package Saddle
 */

require_once __DIR__ . '/divi-test-paths.php';

class Saddle_Divi_Write_Checks_Test extends WP_UnitTestCase {

	private $author;

	public function set_up() {
		parent::set_up();
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
		Saddle_Capabilities::set_tier( 'write' );
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		Saddle_Divi_Schema::index( true );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		parent::tear_down();
	}

	private function page( array $blurb_attrs = array(), $column_attrs = array() ) {
		$column = $column_attrs ? ' ' . wp_json_encode( $column_attrs ) : '';
		// Made the way an administrator or the Visual Builder saves it: an
		// author's own insert would run the content through kses.
		$author = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_author'  => $this->author,
				'post_content' => wp_slash(
					implode(
						"\n",
						array(
							'<!-- wp:divi/section -->',
							'<!-- wp:divi/row -->',
							'<!-- wp:divi/column' . $column . ' -->',
							'<!-- wp:divi/blurb ' . wp_json_encode( array_replace_recursive( array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Keep me' ) ) ) ), $blurb_attrs ) ) . ' /-->',
							'<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Second</p>"}}}} /-->',
							'<!-- /wp:divi/column -->',
							'<!-- /wp:divi/row -->',
							'<!-- /wp:divi/section -->',
						)
					)
				),
			)
		);
		wp_set_current_user( $author );
		return $id;
	}

	private function edit( $id, array $input, $address = '0.0.0.0' ) {
		return wp_get_ability( 'saddle/divi-edit-module' )->execute( array_merge( array( 'post_id' => $id, 'address' => $address ), $input ) );
	}

	private function assert_refused( $result, $code, $before, $id ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertStringContainsString( 'Nothing was saved.', $result->get_error_message() );
		$this->assertSame( $before, get_post( $id )->post_content, 'Nothing was saved.' );
	}

	private static function token( $name, array $settings = array() ) {
		return Saddle_Divi_Dynamic::token( $name, $settings );
	}

	private static function user_loop() {
		return array(
			'enable'    => 'on',
			'queryType' => 'user_roles',
			'subTypes'  => array(
				array(
					'value' => 'administrator',
					'label' => 'Administrator',
				),
			),
		);
	}

	private static function onmouseover() {
		return array(
			'interactions' => array(
				array(
					'id'                => 'abc',
					'enableInteraction' => 'on',
					'trigger'           => 'load',
					'effect'            => 'addAttribute',
					'triggerClass'      => 'et-interaction-trigger-abc',
					'target'            => array(
						'targetClass' => 'et-interaction-target-abc',
						'targetType'  => 'module',
					),
					'attributeName'     => 'onmouseover',
					'attributeValue'    => 'x',
				),
			),
		);
	}

	/* -------- loops -------- */

	public function test_a_user_loop_written_as_attrs_needs_an_account_that_can_list_users() {
		$this->assertFalse( current_user_can( 'list_users' ) );
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		$result = $this->edit( $id, array( 'attrs' => array( 'module.advanced.loop.desktop.value' => self::user_loop() ) ) );

		$this->assert_refused( $result, 'saddle_loop_needs_list_users', $before, $id );
		$this->assertStringContainsString( 'Node 0.0.0.0:', $result->get_error_message() );
		$this->assertSame( '0.0.0.0', $result->get_error_data()['address'] );
	}

	public function test_a_loop_written_as_attrs_may_only_name_post_types_a_visitor_can_view() {
		register_post_type( 'saddle_private_type', array( 'public' => false ) );
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		$result = $this->edit(
			$id,
			array(
				'attrs' => array(
					'module.advanced.loop.desktop.value' => array(
						'enable'    => 'on',
						'queryType' => 'post_types',
						'subTypes'  => array(
							array(
								'value' => 'saddle_private_type',
								'label' => 'X',
							),
						),
					),
				),
			)
		);

		$this->assert_refused( $result, 'saddle_bad_sub_type', $before, $id );
		unregister_post_type( 'saddle_private_type' );
	}

	public function test_a_loop_written_as_attrs_must_name_a_sub_type_divi_reads() {
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		foreach ( array( array(), array( '' ) ) as $sub_types ) {
			$result = $this->edit(
				$id,
				array(
					'attrs' => array(
						'module.advanced.loop.desktop.value' => array(
							'enable'    => 'on',
							'queryType' => 'post_types',
							'subTypes'  => $sub_types,
						),
					),
				)
			);
			$this->assert_refused( $result, 'saddle_loop_needs_sub_type', $before, $id );
		}
	}

	public function test_a_loop_condition_written_as_attrs_may_not_use_a_protected_custom_field() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		foreach ( array( 'metaKey', 'key' ) as $field ) {
			$result = $this->edit(
				$id,
				array(
					'attrs' => array(
						'module.advanced.loop.desktop.value' => array(
							'enable'    => 'on',
							'queryType' => 'post_types',
							'subTypes'  => array(
								array(
									'value' => 'post',
									'label' => 'Posts',
								),
							),
							'metaQuery' => array(
								array(
									$field    => '_secret',
									'metaValue' => '^a',
									'compare' => 'REGEXP',
								),
							),
						),
					),
				)
			);
			$this->assert_refused( $result, 'saddle_protected_meta', $before, $id );
		}
	}

	public function test_a_loop_written_as_attrs_may_not_include_a_post_the_account_cannot_read() {
		$private = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);
		$id      = $this->page();
		$before  = get_post( $id )->post_content;

		$result = $this->edit(
			$id,
			array(
				'attrs' => array(
					'module.advanced.loop.desktop.value' => array(
						'enable'               => 'on',
						'queryType'            => 'post_types',
						'subTypes'             => array(
							array(
								'value' => 'post',
								'label' => 'Posts',
							),
						),
						'includeSpecificPosts' => array(
							array(
								'value' => (string) $private,
								'label' => 'P',
							),
						),
					),
				),
			)
		);

		$this->assert_refused( $result, 'saddle_bad_loop_post', $before, $id );
	}

	public function test_a_loop_that_passes_the_rules_is_saved_from_attrs() {
		$id = $this->page();

		$result = $this->edit(
			$id,
			array(
				'attrs' => array(
					'module.advanced.loop.desktop.value' => array(
						'enable'    => 'on',
						'queryType' => 'post_types',
						'subTypes'  => array(
							array(
								'value' => 'post',
								'label' => 'Posts',
							),
						),
					),
				),
			)
		);

		$this->assertNotWPError( $result );
	}

	public function test_a_loop_that_is_off_is_not_checked() {
		$id   = $this->page();
		$loop = self::user_loop();

		$loop['enable'] = 'off';
		$this->assertNotWPError( $this->edit( $id, array( 'attrs' => array( 'module.advanced.loop.desktop.value' => $loop ) ) ) );
	}

	/* -------- dynamic content -------- */

	public function test_user_sources_written_as_fields_need_their_capabilities() {
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		foreach ( array( 'loop_user_email', 'loop_user_username' ) as $source ) {
			$result = $this->edit( $id, array( 'fields' => array( 'title' => self::token( $source ) ) ) );
			$this->assert_refused( $result, 'saddle_protected_source', $before, $id );
		}
	}

	public function test_a_protected_custom_field_is_refused_in_every_form() {
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		$forms = array(
			self::token( 'custom_meta__secret' ),
			self::token( 'custom_meta_manual_custom_field_value', array( 'meta_key' => '_secret' ) ),
			self::token( 'post_meta_key', array( 'select_meta_key' => 'custom_meta__secret' ) ),
			self::token( 'loop_post_meta_key_manual_custom_field', array( 'loop_meta_key' => '_secret', 'select_loop_meta_key' => 'loop_post_meta_key_manual_custom_field_value' ) ),
			'Before ' . self::token( 'post_title' ) . ' and ' . self::token( 'custom_meta__secret' ),
		);
		foreach ( $forms as $value ) {
			$result = $this->edit( $id, array( 'attrs' => array( 'content.innerContent' => $value ) ), '0.0.0.1' );
			$this->assert_refused( $result, 'saddle_protected_meta', $before, $id );
		}
	}

	public function test_a_token_with_entity_encoded_quotes_is_read_like_divi_reads_it() {
		$id     = $this->page();
		$before = get_post( $id )->post_content;
		$token  = str_replace( '"', '&quot;', self::token( 'custom_meta__secret' ) );

		$result = $this->edit( $id, array( 'attrs' => array( 'content.innerContent' => '<p>' . $token . '</p>' ) ), '0.0.0.1' );

		$this->assert_refused( $result, 'saddle_protected_meta', $before, $id );
	}

	public function test_raw_html_needs_an_account_that_may_post_unfiltered_html() {
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		$result = $this->edit( $id, array( 'fields' => array( 'title' => self::token( 'post_excerpt', array( 'enable_html' => 'on' ) ) ) ) );

		$this->assert_refused( $result, 'saddle_raw_html', $before, $id );
		$this->assertNotWPError( $this->edit( $id, array( 'fields' => array( 'title' => self::token( 'post_excerpt', array( 'enable_html' => 'off' ) ) ) ) ) );
	}

	public function test_page_sources_and_color_references_pass() {
		$id = $this->page();

		$this->assertNotWPError( $this->edit( $id, array( 'fields' => array( 'title' => self::token( 'post_title' ) ) ) ) );
		$this->assertNotWPError(
			$this->edit(
				$id,
				array( 'attrs' => array( 'module.decoration.background.color' => '$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$' ) )
			)
		);
	}

	public function test_the_default_custom_field_allowlist_holds_only_the_three_price_fields() {
		add_filter( 'saddle_wc_active', '__return_false' );
		$this->assertSame( array(), Saddle_Divi_Dynamic::meta_allowlist(), 'Empty without WooCommerce.' );
		remove_filter( 'saddle_wc_active', '__return_false' );

		add_filter( 'saddle_wc_active', '__return_true' );
		$allowed = Saddle_Divi_Dynamic::meta_allowlist();
		remove_filter( 'saddle_wc_active', '__return_true' );

		// The SKU and the stock status used to be allowed too; a store can
		// hide them, so only a site owner's filter adds them.
		$this->assertSame( array( '_price', '_regular_price', '_sale_price' ), $allowed );
	}

	/* -------- interactions -------- */

	public function test_an_interaction_written_as_attrs_may_not_set_an_event_handler() {
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		foreach ( array( 'onmouseover', ' OnClick ', 'href', 'style' ) as $name ) {
			$entries = self::onmouseover();

			$entries['interactions'][0]['attributeName'] = $name;
			$result = $this->edit( $id, array( 'attrs' => array( 'module.decoration.interactions.desktop.value' => $entries ) ) );
			$this->assert_refused( $result, 'saddle_denied_attribute', $before, $id );
		}

		$entries = self::onmouseover();

		$entries['interactions'][0]['attributeName'] = 'data-state';
		$this->assertNotWPError( $this->edit( $id, array( 'attrs' => array( 'module.decoration.interactions.desktop.value' => $entries ) ) ) );
	}

	/* -------- every path -------- */

	public function test_set_page_and_add_module_apply_the_same_rules() {
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		$set = wp_get_ability( 'saddle/divi-set-page' )->execute(
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'     => 'divi/section',
						'children' => array(
							array(
								'type'     => 'divi/row',
								'children' => array(
									array(
										'type'     => 'divi/column',
										'attrs'    => array( 'module.advanced.loop.desktop.value' => self::user_loop() ),
										'children' => array( array( 'type' => 'divi/text' ) ),
									),
								),
							),
						),
					),
				),
			)
		);
		$this->assert_refused( $set, 'saddle_loop_needs_list_users', $before, $id );

		$add = wp_get_ability( 'saddle/divi-add-module' )->execute(
			array(
				'post_id'        => $id,
				'parent_address' => '0.0.0',
				'node'           => array(
					'type'  => 'divi/text',
					'attrs' => array( 'module.decoration.interactions.desktop.value' => self::onmouseover() ),
				),
			)
		);
		$this->assert_refused( $add, 'saddle_denied_attribute', $before, $id );
	}

	public function test_a_direct_save_through_the_shared_path_applies_the_same_rules() {
		$id     = $this->page();
		$post   = get_post( $id );
		$tree   = Saddle_Divi_Tree::parse( $post->post_content );
		$before = $post->post_content;

		$tree[0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['module']['advanced']['loop']['desktop']['value'] = self::user_loop();

		$this->assert_refused( Saddle_Divi_Abilities::persist_public( $post, $tree ), 'saddle_loop_needs_list_users', $before, $id );
	}

	/* -------- values the page already holds -------- */

	public function test_values_already_on_the_page_do_not_block_an_unrelated_edit() {
		$loop = self::user_loop();

		$loop['metaQuery'] = array(
			array(
				'metaKey'   => '_secret',
				'metaValue' => 'a',
				'compare'   => '=',
			),
		);
		$id = $this->page(
			array(
				'title'  => array( 'innerContent' => array( 'desktop' => array( 'value' => self::token( 'loop_user_email' ) ) ) ),
				'module' => array( 'decoration' => array( 'interactions' => array( 'desktop' => array( 'value' => self::onmouseover() ) ) ) ),
			),
			array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => $loop ) ) ) ) )
		);

		// The column holding the loop and the blurb holding the binding and
		// the interaction are edited around: on another node, and on the
		// same node (key order of its groups is rewritten by the edit).
		$this->assertNotWPError( $this->edit( $id, array( 'fields' => array( 'content' => '<p>Changed</p>' ) ), '0.0.0.1' ) );
		$this->assertNotWPError( $this->edit( $id, array( 'attrs' => array( 'module.decoration.spacing.padding.top' => '8px' ) ), '0.0.0.0' ) );
		$this->assertNotWPError( $this->edit( $id, array( 'attrs' => array( 'module.decoration.spacing.padding.top' => '8px' ) ), '0.0.0' ) );
		$this->assertNotWPError( wp_get_ability( 'saddle/divi-move-module' )->execute( array( 'post_id' => $id, 'from_address' => '0.0.0.1', 'to_parent_address' => '0.0.0', 'position' => 0 ) ) );

		$this->assertStringContainsString( 'loop_user_email', get_post( $id )->post_content );
		$this->assertStringContainsString( 'onmouseover', get_post( $id )->post_content );
	}

	public function test_changing_a_value_the_page_holds_checks_it_in_full() {
		$id     = $this->page( array(), array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => self::user_loop() ) ) ) ) ) );
		$before = get_post( $id )->post_content;

		$result = $this->edit( $id, array( 'attrs' => array( 'module.advanced.loop.desktop.value.postPerPage' => 3 ) ), '0.0.0' );

		$this->assert_refused( $result, 'saddle_loop_needs_list_users', $before, $id );
	}

	/* -------- attrs that cannot be saved -------- */

	public function test_a_key_deeper_than_any_divi_setting_is_refused_by_name() {
		$id     = $this->page();
		$before = get_post( $id )->post_content;

		$result = $this->edit( $id, array( 'attrs' => array( 'module.decoration.zz' . str_repeat( '.a', 600 ) => 'x' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_key_too_deep', $result->get_error_code() );
		$this->assertStringContainsString( 'module.decoration.zz.a.a', $result->get_error_message() );
		$this->assertSame( $before, get_post( $id )->post_content );
	}

	public function test_attrs_too_deep_to_encode_are_refused_instead_of_saving_an_empty_module() {
		$id     = $this->page();
		$before = get_post( $id )->post_content;
		$value  = 'x';
		for ( $i = 0; $i < 600; $i++ ) {
			$value = array( 'a' => $value );
		}

		$result = $this->edit( $id, array( 'attrs' => array( 'module.decoration.zz' => $value ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_attrs_not_saved', $result->get_error_code() );
		$this->assertStringContainsString( '0.0.0.0', $result->get_error_message() );
		$this->assertSame( $before, get_post( $id )->post_content );
	}

	/* -------- the interaction ids -------- */

	public function test_the_interaction_ids_are_stored_as_plain_strings() {
		$components = Saddle_Test_Divi_Paths::components_or_skip();
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $components ) {
				return array( $components );
			}
		);
		Saddle_Divi_Schema::index( true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = $this->page();

		$result = $this->edit(
			$id,
			array(
				'attrs' => array(
					'module.decoration.interactionTrigger' => 'abc123defg',
					'module.decoration.interactionTarget'  => 'xyz789uvwq',
				),
			)
		);
		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'warnings', $result, isset( $result['warnings'] ) ? implode( ' | ', $result['warnings'] ) : '' );

		$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $id )->post_content ), '0.0.0.0' );
		$this->assertSame( 'abc123defg', $node['attrs']['module']['decoration']['interactionTrigger'] );
		$this->assertSame( 'xyz789uvwq', $node['attrs']['module']['decoration']['interactionTarget'] );

		$built = Saddle_Divi_Node_Attrs::build( 'divi/text', array( 'attrs' => array( 'module.decoration.interactionTarget' => 'id1' ) ), '0' );
		$this->assertSame( array( array( array( 'module', 'decoration', 'interactionTarget' ), 'id1' ) ), $built['writes'] );
	}
}
