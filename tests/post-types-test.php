<?php
/**
 * Custom post types across the content tools (#137).
 *
 * What must hold: the post tools reach a managed custom type through an
 * optional post_type, and without one they behave exactly as before; only
 * types shown in wp-admin and public or in REST are managed; each type's own
 * capabilities decide; hierarchy, custom taxonomies, search and the block
 * tools follow.
 *
 * @package Saddle
 */

class Saddle_Post_Types_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Saddle_Capabilities::set_tier( 'write' );

		register_post_type(
			'book',
			array(
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'hierarchical' => true,
				'label'        => 'Books',
				'supports'     => array( 'title', 'editor', 'page-attributes' ),
			)
		);
		register_taxonomy(
			'genre',
			'book',
			array(
				'show_ui' => true,
				'label'   => 'Genres',
			)
		);
		// Internal: no admin screen, so not managed.
		register_post_type( 'hidden_thing', array( 'public' => false ) );
		// Its own capabilities, which the administrator was never given.
		register_post_type(
			'ledger',
			array(
				'public'          => true,
				'show_ui'         => true,
				'capability_type' => 'ledger',
				'map_meta_cap'    => true,
			)
		);
	}

	public function tear_down() {
		_unregister_post_type( 'book' );
		_unregister_post_type( 'hidden_thing' );
		_unregister_post_type( 'ledger' );
		_unregister_taxonomy( 'genre' );
		Saddle_Capabilities::set_tier( 'read' );
		parent::tear_down();
	}

	private function run_ability( $name, array $input = array() ) {
		return wp_get_ability( 'saddle/' . $name )->execute( $input );
	}

	public function test_list_post_types_names_managed_types_only() {
		$result = $this->run_ability( 'list-post-types' );
		$names  = wp_list_pluck( $result['types'], 'name' );

		$this->assertContains( 'post', $names );
		$this->assertContains( 'page', $names );
		$this->assertContains( 'book', $names );
		$this->assertNotContains( 'hidden_thing', $names );
		$this->assertNotContains( 'attachment', $names );

		$book = wp_list_filter( $result['types'], array( 'name' => 'book' ) );
		$book = reset( $book );
		$this->assertTrue( $book['hierarchical'] );
		$this->assertTrue( $book['block_editor'] );
		$this->assertSame( 'genre', $book['taxonomies'][0]['name'] );
		$this->assertStringContainsString( 'post_type "book"', $book['use'] );
	}

	public function test_create_get_update_and_trash_an_item_of_a_custom_type() {
		$created = $this->run_ability(
			'create-post',
			array(
				'post_type' => 'book',
				'title'     => 'A Book',
				'status'    => 'publish',
			)
		);
		$this->assertNotWPError( $created );
		$id = (int) $created['id'];
		$this->assertSame( 'book', get_post_type( $id ) );

		$read = $this->run_ability(
			'get-post',
			array(
				'post_type' => 'book',
				'id'        => $id,
			)
		);
		$this->assertSame( 'A Book', $read['title'] );

		$this->run_ability(
			'update-post',
			array(
				'post_type' => 'book',
				'id'        => $id,
				'title'     => 'A Better Book',
			)
		);
		$this->assertSame( 'A Better Book', get_post( $id )->post_title );

		$preview = $this->run_ability(
			'delete-post',
			array(
				'post_type' => 'book',
				'id'        => $id,
			)
		);
		$this->run_ability(
			'delete-post',
			array(
				'post_type'     => 'book',
				'id'            => $id,
				'confirm_token' => $preview['confirm_token'],
			)
		);
		// Core's wp_delete_post( $id, false ) deletes any type but post and
		// page outright; the preview promised the trash.
		$this->assertSame( 'trash', get_post_status( $id ), 'A custom-type delete without force must trash, not destroy.' );
	}

	public function test_without_post_type_the_post_tools_behave_as_before() {
		$book = self::factory()->post->create( array( 'post_type' => 'book' ) );

		$result = $this->run_ability( 'get-post', array( 'id' => $book ) );

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_not_found', $result->get_error_code() );
	}

	/**
	 * get-post on a custom type's ID without post_type. Regression: it said
	 * "No post with that ID.", which an agent reads as "it does not exist".
	 */
	public function test_an_id_of_another_type_says_which_type_it_is() {
		register_post_type(
			'qa_event',
			array(
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'labels'       => array(
					'name'          => 'Events',
					'singular_name' => 'Event',
				),
			)
		);
		$event = self::factory()->post->create( array( 'post_type' => 'qa_event' ) );

		$result = $this->run_ability( 'get-post', array( 'id' => $event ) );

		$this->assertSame( 'saddle_not_found', $result->get_error_code() );
		$this->assertSame(
			sprintf( 'No post with that ID. ID %d belongs to the type Event ("qa_event"). Use the post tools with "post_type" set to "qa_event".', $event ),
			$result->get_error_message()
		);

		$post = self::factory()->post->create();
		$page = $this->run_ability( 'get-page', array( 'id' => $post ) );
		$this->assertSame( sprintf( 'No page with that ID. ID %d belongs to the type Post ("post"). Use the post tools.', $post ), $page->get_error_message() );

		_unregister_post_type( 'qa_event' );
	}

	/** The hint discloses nothing the caller could not read: an unmanaged type stays "not found". */
	public function test_an_id_of_an_unmanaged_type_stays_not_found() {
		$hidden = self::factory()->post->create( array( 'post_type' => 'hidden_thing' ) );

		$result = $this->run_ability( 'get-post', array( 'id' => $hidden ) );

		$this->assertSame( 'No post with that ID.', $result->get_error_message() );

		// Nor does a managed type's item the caller cannot read.
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'book',
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'No post with that ID.', $this->run_ability( 'get-post', array( 'id' => $draft ) )->get_error_message() );
	}

	public function test_an_unmanaged_type_is_refused_by_name() {
		$result = $this->run_ability(
			'list-posts',
			array( 'post_type' => 'hidden_thing' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'saddle_unknown_post_type', $result->get_error_code() );
	}

	public function test_the_types_own_capabilities_decide() {
		$result = $this->run_ability(
			'create-post',
			array(
				'post_type' => 'ledger',
				'title'     => 'Books balanced',
			)
		);

		$this->assertWPError( $result, 'The administrator holds no create_ledgers capability.' );
		$this->assertSame( 0, (int) wp_count_posts( 'ledger' )->draft );
	}

	public function test_hierarchy_listing_and_search_follow_the_type() {
		$parent = self::factory()->post->create(
			array(
				'post_type'  => 'book',
				'post_title' => 'Series',
			)
		);
		$child  = $this->run_ability(
			'create-post',
			array(
				'post_type' => 'book',
				'title'     => 'Volume One',
				'parent'    => $parent,
			)
		);
		$this->assertSame( $parent, (int) get_post( $child['id'] )->post_parent );

		$listed = $this->run_ability(
			'list-posts',
			array(
				'post_type' => 'book',
				'parent'    => $parent,
			)
		);
		$this->assertSame( array( (int) $child['id'] ), array_map( 'intval', wp_list_pluck( $listed['items'], 'id' ) ) );

		$found = $this->run_ability(
			'search-content',
			array(
				'query'     => 'Volume',
				'post_type' => 'book',
			)
		);
		$this->assertSame( array( (int) $child['id'] ), array_map( 'intval', wp_list_pluck( $found['items'], 'id' ) ) );
	}

	public function test_custom_taxonomy_terms_are_assigned_and_foreign_ones_reported() {
		$created = $this->run_ability(
			'create-post',
			array(
				'post_type' => 'book',
				'title'     => 'Tagged',
				'terms'     => array(
					'genre'    => array( 'Fiction' ),
					'category' => array( 1 ),
				),
			)
		);

		$this->assertSame( array( 'Fiction' ), wp_get_object_terms( $created['id'], 'genre', array( 'fields' => 'names' ) ) );
		$this->assertSame( array( 'category' ), $created['terms_denied'], 'Books don\'t use categories.' );
	}

	public function test_the_block_tools_take_a_custom_type_id() {
		$id = self::factory()->post->create( array( 'post_type' => 'book' ) );

		$set = $this->run_ability(
			'set-blocks',
			array(
				'post_id' => $id,
				'nodes'   => array(
					array(
						'type'    => 'core/paragraph',
						'content' => 'Chapter one.',
					),
				),
			)
		);
		$this->assertNotWPError( $set );

		$read = $this->run_ability( 'get-blocks', array( 'post_id' => $id ) );
		$this->assertNotWPError( $read );
		$this->assertStringContainsString( 'Chapter one.', get_post( $id )->post_content );
	}
}
