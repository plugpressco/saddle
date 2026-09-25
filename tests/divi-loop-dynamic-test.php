<?php
/**
 * Loop + dynamic-content ability tests.
 *
 * The attribute shapes these write are verified against Divi 5.8's own
 * readers: a loop is {enable, queryType, subTypes, orderBy, order,
 * postPerPage, postOffset} at module.advanced.loop.desktop.value, and a
 * dynamic binding is the literal `$variable({"type":"content",...})$` token
 * at <field>.<breakpoint>.value. These tests pin both shapes plus the merge/
 * replace semantics and the guard rails.
 *
 * @package Saddle
 */

class Saddle_Divi_Loop_Dynamic_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Saddle_Capabilities::set_tier( 'write' );
		add_filter( 'saddle_divi_active', '__return_true' );
	}

	public function tear_down() {
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_option( Saddle_Capabilities::OPTION );
		delete_option( Saddle_Capabilities::PAUSED_OPTION );
		parent::tear_down();
	}

	private function divi_page() {
		return self::factory()->post->create(
			array(
				'post_content' => implode(
					"\n",
					array(
						'<!-- wp:divi/section -->',
						'<!-- wp:divi/row -->',
						'<!-- wp:divi/column -->',
						'<!-- wp:divi/blurb --><!-- /wp:divi/blurb -->',
						'<!-- /wp:divi/column -->',
						'<!-- /wp:divi/row -->',
						'<!-- /wp:divi/section -->',
					)
				),
			)
		);
	}

	private function module_attrs( $post_id, $address = '0.0.0.0' ) {
		$tree = Saddle_Divi_Tree::parse( get_post( $post_id )->post_content );
		$node = Saddle_Divi_Tree::get( $tree, $address );
		return is_array( $node['attrs'] ) ? $node['attrs'] : array();
	}

	private function ability( $name ) {
		$a = wp_get_ability( $name );
		$this->assertNotNull( $a, "Ability {$name} must be registered." );
		return $a;
	}

	/* -------- loops -------- */

	public function test_enable_loop_writes_the_divi_loop_shape() {
		$id = $this->divi_page();

		$result = $this->ability( 'saddle/divi-enable-loop' )->execute(
			array(
				'post_id'    => $id,
				'address'    => '0.0.0.0',
				'query_type' => 'post_types',
				'sub_types'  => array( 'post' ),
				'order_by'   => 'date',
				'order'      => 'desc',
				'per_page'   => 6,
			)
		);
		$this->assertNotWPError( $result );

		$loop = $this->module_attrs( $id )['module']['advanced']['loop']['desktop']['value'];
		$this->assertSame( 'on', $loop['enable'] );
		$this->assertSame( 'post_types', $loop['queryType'] );
		$this->assertSame( array( 'post' ), $loop['subTypes'] );
		$this->assertSame( 'date', $loop['orderBy'] );
		$this->assertSame( 'desc', $loop['order'] );
		$this->assertSame( 6, $loop['postPerPage'] );

		// Attr writes carry version + the changed node like the structural
		// writes do — they used to return neither, forcing a re-read.
		$this->assertArrayHasKey( 'version', $result );
		$this->assertNotEmpty( $result['version'] );
		$this->assertArrayHasKey( 'changed', $result );
		$this->assertSame( '0.0.0.0', $result['changed'][0]['address'] );
	}

	public function test_edit_loop_merges_and_requires_a_loop() {
		$id = $this->divi_page();

		$missing = $this->ability( 'saddle/divi-edit-loop' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'per_page' => 3 )
		);
		$this->assertWPError( $missing );
		$this->assertSame( 'saddle_no_loop', $missing->get_error_code() );

		$this->ability( 'saddle/divi-enable-loop' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'terms', 'sub_types' => array( 'category' ) )
		);
		$this->ability( 'saddle/divi-edit-loop' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'per_page' => 3 )
		);

		$loop = $this->module_attrs( $id )['module']['advanced']['loop']['desktop']['value'];
		$this->assertSame( 3, $loop['postPerPage'], 'Edit must apply the passed parameter.' );
		$this->assertSame( 'terms', $loop['queryType'], 'Edit must preserve parameters it was not given.' );
		$this->assertSame( array( 'category' ), $loop['subTypes'] );
	}

	public function test_disable_loop_keeps_config_and_requires_a_loop() {
		$id = $this->divi_page();

		$missing = $this->ability( 'saddle/divi-disable-loop' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0' )
		);
		$this->assertWPError( $missing );

		$this->ability( 'saddle/divi-enable-loop' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'users' )
		);
		$this->ability( 'saddle/divi-disable-loop' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0' )
		);

		$loop = $this->module_attrs( $id )['module']['advanced']['loop']['desktop']['value'];
		$this->assertSame( 'off', $loop['enable'] );
		$this->assertSame( 'users', $loop['queryType'], 'Disable must keep the query config for re-enabling.' );
	}

	public function test_enable_loop_rejects_unknown_query_type_and_clamps_per_page() {
		$id = $this->divi_page();

		$bad = $this->ability( 'saddle/divi-enable-loop' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'query_type' => 'evil_type' )
		);
		// Either layer may refuse it: core's Abilities API validates the enum
		// in the input schema (ability_invalid_input); the callback's own check
		// (saddle_bad_query_type) backstops transports that don't validate.
		$this->assertWPError( $bad );
		$this->assertContains( $bad->get_error_code(), array( 'ability_invalid_input', 'saddle_bad_query_type' ) );
		$this->assertArrayNotHasKey( 'module', $this->module_attrs( $id ), 'A rejected loop must write nothing.' );

		// Out-of-range per_page is refused by the declared schema (maximum 100)
		// before the callback runs — nothing is written.
		$excessive = $this->ability( 'saddle/divi-enable-loop' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'per_page' => 9999 )
		);
		$this->assertWPError( $excessive );
		$this->assertArrayNotHasKey( 'module', $this->module_attrs( $id ) );
	}

	public function test_loop_reference_tools_return_the_catalogs() {
		$types = $this->ability( 'saddle/divi-list-loop-query-types' )->execute( array() );
		$this->assertContains( 'menus', wp_list_pluck( $types['query_types'], 'type' ) );

		$sources = $this->ability( 'saddle/divi-list-dynamic-sources' )->execute( array() );
		$this->assertContains( 'post_title', $sources['post'] );
	}

	/* -------- dynamic content -------- */

	public function test_apply_dynamic_content_writes_the_variable_token() {
		$id = $this->divi_page();

		$result = $this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array(
				'post_id' => $id,
				'address' => '0.0.0.0',
				'field'   => 'title.innerContent',
				'source'  => 'post_title',
			)
		);
		$this->assertNotWPError( $result );

		$value = $this->module_attrs( $id )['title']['innerContent']['desktop']['value'];
		$this->assertMatchesRegularExpression( '/^\$variable\(.*\)\$$/s', $value );

		$json = json_decode( substr( $value, strlen( '$variable(' ), -2 ), true );
		$this->assertSame( 'content', $json['type'] );
		$this->assertSame( 'post_title', $json['value']['name'] );
	}

	public function test_apply_dynamic_content_refuses_protected_meta_key() {
		$id = $this->divi_page();

		$result = $this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array(
				'post_id'  => $id,
				'address'  => '0.0.0.0',
				'field'    => 'title.innerContent',
				'source'   => 'post_meta_key',
				'settings' => array( 'key' => '_secret_meta' ),
			)
		);

		$this->assertWPError( $result, 'Binding a protected meta key must be refused, not merely warned.' );
		$this->assertSame( 'saddle_protected_meta', $result->get_error_code() );

		// And nothing was written to the module field.
		$attrs = $this->module_attrs( $id );
		$this->assertArrayNotHasKey( 'innerContent', $attrs['title'] ?? array(), 'A refused binding must not touch the module.' );
	}

	public function test_apply_dynamic_content_allows_protected_key_via_filter() {
		$id = $this->divi_page();

		add_filter(
			'saddle_dynamic_meta_allowlist',
			static function () {
				return array( '_public_by_design' );
			}
		);

		$result = $this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array(
				'post_id'  => $id,
				'address'  => '0.0.0.0',
				'field'    => 'title.innerContent',
				'source'   => 'post_meta_key',
				'settings' => array( 'key' => '_public_by_design' ),
			)
		);

		remove_all_filters( 'saddle_dynamic_meta_allowlist' );

		$this->assertNotWPError( $result, 'An allowlisted protected key must bind successfully.' );
	}

	public function test_apply_dynamic_content_allows_plain_meta_key() {
		$id = $this->divi_page();

		$result = $this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array(
				'post_id'  => $id,
				'address'  => '0.0.0.0',
				'field'    => 'title.innerContent',
				'source'   => 'post_meta_key',
				'settings' => array( 'key' => 'subtitle' ),
			)
		);

		$this->assertNotWPError( $result, 'A non-protected meta key must bind unchanged.' );
	}

	/* -------- settings-blob shape validation (#22) -------- */

	public function test_apply_dynamic_content_refuses_nested_settings() {
		$id     = $this->divi_page();
		$result = $this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array(
				'post_id'  => $id,
				'address'  => '0.0.0.0',
				'field'    => 'title.innerContent',
				'source'   => 'post_date',
				'settings' => array( 'date_format' => array( 'nested' => 'nope' ) ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_nested_settings', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'innerContent', $this->module_attrs( $id )['title'] ?? array(), 'A refused settings blob must write nothing.' );
	}

	public function test_apply_dynamic_content_refuses_oversized_settings_value() {
		$id     = $this->divi_page();
		$result = $this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array(
				'post_id'  => $id,
				'address'  => '0.0.0.0',
				'field'    => 'title.innerContent',
				'source'   => 'post_date',
				'settings' => array( 'before' => str_repeat( 'x', 2001 ) ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_settings_too_long', $result->get_error_code() );
	}

	public function test_apply_dynamic_content_accepts_and_sanitizes_scalar_settings() {
		$id     = $this->divi_page();
		$result = $this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array(
				'post_id'  => $id,
				'address'  => '0.0.0.0',
				'field'    => 'title.innerContent',
				'source'   => 'post_date',
				'settings' => array( 'before' => 'Posted <script>x</script> on ', 'date_format' => 'F j, Y' ),
			)
		);
		$this->assertNotWPError( $result );

		$value = $this->module_attrs( $id )['title']['innerContent']['desktop']['value'];
		$json  = json_decode( substr( $value, strlen( '$variable(' ), -2 ), true );
		$this->assertSame( 'F j, Y', $json['value']['settings']['date_format'] );
		$this->assertStringNotContainsString( '<script>', $json['value']['settings']['before'], 'String settings must be sanitized.' );
	}

	public function test_set_display_conditions_refuses_nested_settings() {
		$id     = $this->divi_page();
		$result = $this->ability( 'saddle/divi-set-display-conditions' )->execute(
			array(
				'post_id'    => $id,
				'address'    => '0.0.0.0',
				'conditions' => array(
					array(
						'conditionName' => 'loggedInStatus',
						'settings'      => array( 'roles' => array( 'administrator' ) ),
					),
				),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_nested_settings', $result->get_error_code() );
	}

	public function test_clear_dynamic_content_strips_token_and_keeps_literal_text() {
		$id = $this->divi_page();
		$this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'field' => 'title.innerContent', 'source' => 'post_title' )
		);

		$cleared = $this->ability( 'saddle/divi-clear-dynamic-content' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'field' => 'title.innerContent' )
		);
		$this->assertNotWPError( $cleared );
		$this->assertStringNotContainsString( '$variable(', $this->module_attrs( $id )['title']['innerContent']['desktop']['value'] );

		// Explicit replacement value wins.
		$this->ability( 'saddle/divi-clear-dynamic-content' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'field' => 'title.innerContent', 'value' => 'Static again' )
		);
		$this->assertSame( 'Static again', $this->module_attrs( $id )['title']['innerContent']['desktop']['value'] );
	}

	public function test_field_path_is_validated() {
		$id = $this->divi_page();

		$bad = $this->ability( 'saddle/divi-apply-dynamic-content' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'field' => 'title/../evil', 'source' => 'post_title' )
		);
		$this->assertWPError( $bad );
		$this->assertSame( 'saddle_bad_field', $bad->get_error_code() );
	}

	/* -------- presets applied to a module + display conditions -------- */

	public function test_apply_global_preset_stacks_the_preset_id() {
		$id = $this->divi_page();

		$result = $this->ability( 'saddle/divi-apply-global-preset' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'preset_id' => '6488a1b2c3d4e' )
		);
		$this->assertNotWPError( $result );
		$this->assertSame( array( '6488a1b2c3d4e' ), $this->module_attrs( $id )['modulePreset'], 'modulePreset is a stack of ids.' );
	}

	public function test_list_condition_types_is_a_reference() {
		$out = $this->ability( 'saddle/divi-list-condition-types' )->execute( array() );
		$names = wp_list_pluck( $out['condition_types'], 'conditionName' );
		$this->assertContains( 'loggedInStatus', $names );
		$this->assertContains( 'postType', $names );
	}

	public function test_set_display_conditions_writes_the_divi_shape() {
		$id = $this->divi_page();

		$result = $this->ability( 'saddle/divi-set-display-conditions' )->execute(
			array(
				'post_id'    => $id,
				'address'    => '0.0.0.0',
				'conditions' => array(
					array( 'conditionName' => 'loggedInStatus', 'displayRule' => 'loggedIn' ),
				),
			)
		);
		$this->assertNotWPError( $result );

		$cond = $this->module_attrs( $id )['module']['decoration']['conditions']['desktop']['value'];
		$this->assertCount( 1, $cond );
		$this->assertSame( 'loggedInStatus', $cond[0]['conditionName'] );
		$this->assertSame( 'loggedIn', $cond[0]['conditionSettings']['displayRule'] );
		$this->assertSame( 'on', $cond[0]['conditionSettings']['enableCondition'] );
		$this->assertArrayHasKey( 'id', $cond[0] );
		$this->assertSame( 'OR', $cond[0]['operator'] );
	}

	public function test_set_display_conditions_rejects_a_condition_without_a_name() {
		$id = $this->divi_page();
		$result = $this->ability( 'saddle/divi-set-display-conditions' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'conditions' => array( array( 'displayRule' => 'x' ) ) )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'saddle_bad_condition', $result->get_error_code() );
	}

	public function test_empty_conditions_clears_them() {
		$id = $this->divi_page();
		$result = $this->ability( 'saddle/divi-set-display-conditions' )->execute(
			array( 'post_id' => $id, 'address' => '0.0.0.0', 'conditions' => array() )
		);
		$this->assertNotWPError( $result );
		$this->assertSame( array(), $this->module_attrs( $id )['module']['decoration']['conditions']['desktop']['value'] );
	}

	/* -------- tier gate -------- */

	public function test_loop_writes_denied_at_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );
		$id = $this->divi_page();

		$this->assertFalse(
			$this->ability( 'saddle/divi-enable-loop' )->check_permissions( array( 'post_id' => $id, 'address' => '0.0.0.0' ) ),
			'Loop writes must be denied at the read tier.'
		);
	}
}
