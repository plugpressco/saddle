<?php
/**
 * saddle/divi-add-interaction: the entry, the two marks, removal by id.
 *
 * The stored shape is the one the Divi 5.10.1 Visual Builder writes and
 * Module::render() reads (see Saddle_Divi_Interactions). Most tests use two
 * fixture modules, one declaring the interactions group and one not; the
 * last one runs on Divi's own module.json when a local Divi 5 is present.
 *
 * @package Saddle
 */

class Saddle_Divi_Add_Interaction_Test extends WP_UnitTestCase {

	private static $fixture_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$fixture_dir = get_temp_dir() . 'saddle-interaction-fixture-modules';
		$modules           = array(
			'clicker' => array( 'module' => array( 'settings' => array( 'decoration' => array( 'background' => array(), 'interactions' => array() ) ) ) ),
			'plain'   => array( 'module' => array( 'settings' => array( 'decoration' => array( 'background' => array() ) ) ) ),
		);
		foreach ( $modules as $name => $attributes ) {
			$dir = self::$fixture_dir . '/' . $name;
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			file_put_contents(
				$dir . '/module.json',
				wp_json_encode(
					array(
						'name'       => 'saddletest/' . $name,
						'title'      => ucfirst( $name ),
						'category'   => 'module',
						'attributes' => $attributes,
					)
				)
			);
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
		remove_filter( 'saddle_divi_active', '__return_true' );
		delete_transient( Saddle_Divi_Schema::INDEX_TRANSIENT );
		Saddle_Divi_Schema::index( true );
		delete_option( Saddle_Capabilities::OPTION );
		parent::tear_down();
	}

	/* -------- helpers -------- */

	private function page( array $modules ) {
		$markup = '';
		foreach ( $modules as $type => $attrs ) {
			$type    = preg_replace( '/#\d+$/', '', $type );
			$json    = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
			$markup .= '<!-- wp:' . $type . $json . ' --><!-- /wp:' . $type . ' -->';
		}
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column -->' . $markup . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->',
			)
		);
	}

	private function run_tool( array $input ) {
		return wp_get_ability( 'saddle/divi-add-interaction' )->execute( $input );
	}

	private function attrs( $post_id, $address ) {
		$node = Saddle_Divi_Tree::get( Saddle_Divi_Tree::parse( get_post( $post_id )->post_content ), $address );
		return $node['attrs'];
	}

	private function items( $post_id, $address ) {
		return Saddle_Divi_Interactions::items( $this->attrs( $post_id, $address ) );
	}

	/* -------- adding -------- */

	public function test_writes_the_entry_and_both_marks() {
		$id     = $this->page(
			array(
				'saddletest/clicker' => array(),
				'saddletest/plain'   => array( 'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Yearly prices' ) ) ) ) ),
			)
		);
		$result = $this->run_tool(
			array(
				'post_id'         => $id,
				'trigger_address' => '0.0.0.0',
				'target_address'  => '0.0.0.1',
				'delay'           => 300,
			)
		);
		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'warnings', $result, 'The echo raises nothing on what the tool writes.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-z]{10}$/', $result['interaction']['id'] );
		$this->assertSame( array( '0.0.0.0', '0.0.0.1' ), wp_list_pluck( $result['changed'], 'address' ) );
		$this->assertArrayHasKey( 'version', $result );

		$trigger = $this->attrs( $id, '0.0.0.0' )['module']['decoration'];
		$item    = $trigger['interactions']['desktop']['value']['interactions'][0];
		$this->assertSame( 'click', $item['trigger'] );
		$this->assertSame( 'toggleVisibility', $item['effect'] );
		$this->assertSame( 'on', $item['enableInteraction'] );
		$this->assertSame( '300ms', $item['timeDelay'] );
		$this->assertSame( 'et-interaction-trigger-' . $trigger['interactionTrigger'], $item['triggerClass'], 'The trigger mark is the id after the prefix.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-z]{10}$/', $trigger['interactionTrigger'] );
		$this->assertSame(
			array(
				'targetClass' => 'et-interaction-target-' . $this->attrs( $id, '0.0.0.1' )['module']['decoration']['interactionTarget'],
				'label'       => 'Yearly prices',
				'moduleId'    => '',
				'targetType'  => 'module',
			),
			$item['target']
		);
		$this->assertArrayNotHasKey( 'attributeName', $item );
	}

	public function test_a_second_interaction_is_appended_and_shares_the_trigger_mark() {
		$id    = $this->page(
			array(
				'saddletest/clicker' => array(),
				'saddletest/plain'   => array(),
				'saddletest/plain#2' => array(),
			)
		);
		$first = $this->run_tool(
			array(
				'post_id'         => $id,
				'trigger_address' => '0.0.0.0',
				'target_address'  => '0.0.0.1',
				'effect'          => 'removeVisibility',
			)
		);
		$this->assertNotWPError( $first );
		$second = $this->run_tool(
			array(
				'post_id'         => $id,
				'trigger_address' => '0.0.0.0',
				'target_address'  => '0.0.0.2',
				'effect'          => 'addVisibility',
				'trigger'         => 'mouseEnter',
			)
		);
		$this->assertNotWPError( $second );

		$items = $this->items( $id, '0.0.0.0' );
		$this->assertCount( 2, $items, 'Appended, never replaced.' );
		$this->assertSame( $first['interaction']['id'], $items[0]['id'] );
		$this->assertSame( 'removeVisibility', $items[0]['effect'] );
		$this->assertSame( $items[0]['triggerClass'], $items[1]['triggerClass'], 'One trigger id per module.' );
		$this->assertNotSame( $items[0]['target']['targetClass'], $items[1]['target']['targetClass'] );
		$this->assertNotSame( $items[0]['id'], $items[1]['id'] );
	}

	public function test_a_module_can_change_its_own_attribute_and_an_existing_target_id_is_kept() {
		$id     = $this->page( array( 'saddletest/clicker' => array( 'module' => array( 'decoration' => array( 'interactionTarget' => 'menuPanel' ) ) ) ) );
		$result = $this->run_tool(
			array(
				'post_id'         => $id,
				'trigger_address' => '0.0.0.0',
				'target_address'  => '0.0.0.0',
				'effect'          => 'toggleAttribute',
				'attribute_name'  => 'Class',
				'attribute_value' => 'is-open',
				'delay'           => '0.5s',
				'label'           => 'Open the menu',
			)
		);
		$this->assertNotWPError( $result );

		$decoration = $this->attrs( $id, '0.0.0.0' )['module']['decoration'];
		$item       = $decoration['interactions']['desktop']['value']['interactions'][0];
		$this->assertSame( 'menuPanel', $decoration['interactionTarget'], 'A stored target id is reused.' );
		$this->assertSame( 'et-interaction-target-menuPanel', $item['target']['targetClass'] );
		$this->assertArrayHasKey( 'interactionTrigger', $decoration );
		$this->assertSame( 'class', $item['attributeName'] );
		$this->assertSame( 'is-open', $item['attributeValue'] );
		$this->assertSame( '500ms', $item['timeDelay'] );
		$this->assertSame( 'Open the menu', $item['adminLabel'] );
		$this->assertSame( array( '0.0.0.0' ), wp_list_pluck( $result['changed'], 'address' ) );
	}

	/* -------- removing -------- */

	public function test_remove_by_id_clears_marks_only_when_nothing_uses_them() {
		$id  = $this->page(
			array(
				'saddletest/clicker' => array(),
				'saddletest/plain'   => array(),
			)
		);
		$one = $this->run_tool( array( 'post_id' => $id, 'trigger_address' => '0.0.0.0', 'target_address' => '0.0.0.1' ) );
		$two = $this->run_tool( array( 'post_id' => $id, 'trigger_address' => '0.0.0.0', 'target_address' => '0.0.0.1', 'trigger' => 'viewportEnter', 'effect' => 'scrollToElement' ) );

		$removed = $this->run_tool( array( 'post_id' => $id, 'remove_id' => $one['interaction']['id'] ) );
		$this->assertNotWPError( $removed );
		$this->assertSame( '0.0.0.0', $removed['trigger_address'] );
		$this->assertSame( array( $two['interaction']['id'] ), wp_list_pluck( $this->items( $id, '0.0.0.0' ), 'id' ) );
		$this->assertArrayHasKey( 'interactionTarget', $this->attrs( $id, '0.0.0.1' )['module']['decoration'], 'The other entry still points at the target.' );

		$this->assertNotWPError( $this->run_tool( array( 'post_id' => $id, 'remove_id' => $two['interaction']['id'] ) ) );
		$trigger = $this->attrs( $id, '0.0.0.0' );
		$this->assertArrayNotHasKey( 'interactions', isset( $trigger['module']['decoration'] ) ? $trigger['module']['decoration'] : array() );
		$this->assertArrayNotHasKey( 'interactionTrigger', isset( $trigger['module']['decoration'] ) ? $trigger['module']['decoration'] : array() );
		$target = $this->attrs( $id, '0.0.0.1' );
		$this->assertArrayNotHasKey( 'interactionTarget', isset( $target['module']['decoration'] ) ? $target['module']['decoration'] : array() );

		$gone = $this->run_tool( array( 'post_id' => $id, 'remove_id' => $two['interaction']['id'] ) );
		$this->assertWPError( $gone );
		$this->assertSame( 'saddle_no_interaction', $gone->get_error_code() );
	}

	/* -------- refusals -------- */

	public function test_refusals() {
		$id = $this->page(
			array(
				'saddletest/clicker' => array(),
				'saddletest/plain'   => array(),
				'saddletest/unknown' => array(),
			)
		);
		$base   = array(
			'post_id'         => $id,
			'trigger_address' => '0.0.0.0',
			'target_address'  => '0.0.0.1',
		);
		$before = get_post( $id )->post_content;

		$cases = array(
			'saddle_no_interactions'  => array( 'trigger_address' => '0.0.0.1' ),
			'saddle_no_interactions ' => array( 'trigger_address' => '0.0.0.2' ),
			'saddle_bad_address'      => array( 'target_address' => '0.0.0.9' ),
			'saddle_missing_address'  => array( 'target_address' => '' ),
			'ability_invalid_input'   => array( 'effect' => 'setVisibility' ),
			'ability_invalid_input '  => array( 'trigger' => 'hover' ),
			'saddle_bad_delay'        => array( 'delay' => '2 seconds' ),
			'saddle_bad_attribute'    => array( 'effect' => 'addAttribute', 'attribute_name' => 'class' ),
			'saddle_denied_attribute' => array( 'effect' => 'addAttribute', 'attribute_name' => 'onclick', 'attribute_value' => 'x()' ),
		);
		foreach ( $cases as $code => $patch ) {
			$result = $this->run_tool( array_merge( $base, $patch ) );
			$this->assertWPError( $result, $code );
			$this->assertSame( trim( $code ), $result->get_error_code() );
		}
		$this->assertSame( $before, get_post( $id )->post_content, 'A refusal changes nothing.' );

		// The same checks hold when the class is called without the schema.
		$this->assertSame( 'saddle_bad_effect', Saddle_Divi_Interactions::spec( array( 'effect' => 'setVisibility' ) )->get_error_code() );
		$this->assertSame( 'saddle_bad_trigger', Saddle_Divi_Interactions::spec( array( 'trigger' => 'hover' ) )->get_error_code() );
	}

	public function test_refused_at_the_read_tier() {
		Saddle_Capabilities::set_tier( 'read' );
		$id = $this->page( array( 'saddletest/clicker' => array() ) );
		$this->assertFalse(
			wp_get_ability( 'saddle/divi-add-interaction' )->check_permissions(
				array(
					'post_id'         => $id,
					'trigger_address' => '0.0.0.0',
					'target_address'  => '0.0.0.0',
				)
			)
		);
	}

	public function test_registered_as_a_write_tool_that_is_not_destructive() {
		$ability = wp_get_ability( 'saddle/divi-add-interaction' );
		$this->assertNotNull( $ability );
		$this->assertSame( 'write', $ability->get_meta()['saddle']['tier'] );
		$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
	}

	/* -------- on Divi's own modules -------- */

	public function test_the_echo_and_verify_raise_nothing_on_a_real_divi_button() {
		$theme      = getenv( 'SADDLE_DIVI_THEME_DIR' );
		$theme      = $theme ? $theme : dirname( __DIR__, 3 ) . '/wp/divi-dev/wp-content/themes/Divi';
		$components = rtrim( $theme, '/' ) . '/includes/builder-5/visual-builder/packages/module-library/src/components';
		if ( ! is_dir( $components ) ) {
			$this->markTestSkipped( 'No Divi 5 source on this machine.' );
		}
		remove_all_filters( 'saddle_divi_module_json_dirs' );
		add_filter(
			'saddle_divi_module_json_dirs',
			static function () use ( $components ) {
				return array( $components );
			}
		);
		Saddle_Divi_Schema::index( true );

		$this->assertTrue( Saddle_Divi_Interactions::supports( 'divi/button' ) );
		$this->assertFalse( Saddle_Divi_Interactions::supports( 'divi/map-pin' ), 'Divi\'s own map pin has no interactions group.' );

		$id     = $this->page(
			array(
				'divi/button' => array( 'button' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'text' => 'Show yearly prices' ) ) ) ) ),
				'divi/text'   => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Yearly</p>' ) ) ) ),
			)
		);
		$result = $this->run_tool(
			array(
				'post_id'         => $id,
				'trigger_address' => '0.0.0.0',
				'target_address'  => '0.0.0.1',
				'effect'          => 'addAttribute',
				'attribute_name'  => 'data-state',
				'attribute_value' => 'open',
			)
		);
		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'warnings', $result );

		$tree = Saddle_Divi_Tree::parse( get_post( $id )->post_content );
		foreach ( Saddle_Divi_Verify::findings( $tree ) as $finding ) {
			$this->assertStringNotContainsStringIgnoringCase( 'interaction', $finding['message'] );
		}
	}
}
