<?php
/**
 * The change journal: what a Saddle tool changed, recorded while it runs.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records the before-state of everything a saddle/* ability changes, so the
 * activity-log entry for that call can be undone later (#181).
 *
 * Why hooks rather than a call in each tool: every tool, the Divi and SEO ones
 * included, writes through the same WordPress APIs, and a journal listening to
 * those APIs can't be forgotten by the next tool someone adds. It opens when a
 * saddle/* ability starts (core's wp_before_execute_ability) and
 * Saddle_Log::record() takes it when the tool logs its change. Recording only;
 * Saddle_Undo does the restoring.
 *
 * Kept: post fields (content through a revision, or inline when the post type
 * keeps none), post meta, post terms, created and permanently deleted posts,
 * created terms, allowlisted options, the active theme, plugin activation.
 * Not kept, so reported as not undoable: Saddle's own records, plugin and
 * theme updates, and values too large to store in the log.
 */
class Saddle_Journal {

	/** Log-entry meta key holding the journal (JSON). */
	const META = '_saddle_undo';

	/** Largest value kept inline, in bytes. */
	const MAX_VALUE = 65536;

	/** Most items one journal keeps; past it the entry is not undoable. */
	const MAX_ITEMS = 200;

	/** Post types never journaled: core bookkeeping. Saddle's own are skipped by prefix. */
	const SKIP_TYPES = array( 'revision', 'oembed_cache', 'customize_changeset', 'user_request' );

	/** Meta keys never journaled: editor locks and core's bookkeeping. */
	const SKIP_META = array( '_edit_lock', '_edit_last', '_encloseme', '_pingme', '_wp_old_slug', '_wp_old_date' );

	/** Options journaled beyond the update-option allowlist. */
	const EXTRA_OPTIONS = array( 'auto_update_plugins', 'auto_update_themes' );

	/**
	 * Whether a saddle/* ability has started in this request.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Items recorded since the last take(), in the order they happened.
	 *
	 * @var array[]
	 */
	private static $items = array();

	/**
	 * Keys already recorded, so each object keeps its first (oldest) state.
	 *
	 * @var true[]
	 */
	private static $seen = array();

	/**
	 * Whether more than MAX_ITEMS changes happened.
	 *
	 * @var bool
	 */
	private static $overflow = false;

	/**
	 * Wire the listeners. They return at once unless a saddle/* ability ran.
	 */
	public static function init() {
		add_action( 'wp_before_execute_ability', array( __CLASS__, 'open' ) );
		add_action( 'wp_after_execute_ability', array( __CLASS__, 'close' ) );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'on_post_save' ), 10, 2 );
		add_action( 'pre_post_update', array( __CLASS__, 'on_post_update' ) );
		add_action( 'wp_insert_post', array( __CLASS__, 'on_post_insert' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_delete' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_post_delete' ) );
		add_filter( 'add_post_metadata', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_object_terms' ), 10, 6 );
		add_action( 'created_term', array( __CLASS__, 'on_term_created' ), 10, 3 );
		add_action( 'update_option', array( __CLASS__, 'on_option_update' ), 10, 2 );
		add_action( 'add_option', array( __CLASS__, 'on_option_add' ) );
		add_action( 'delete_option', array( __CLASS__, 'on_option_delete' ) );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme' ), 10, 3 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ) );
	}

	/**
	 * Start a fresh journal when a saddle/* ability starts. Anything left from
	 * an earlier call that failed before logging is dropped, never attributed
	 * to this one. No saddle/* ability runs another, so there is no nesting.
	 *
	 * @param string $ability_name Ability being executed.
	 */
	public static function open( $ability_name ) {
		if ( 0 !== strpos( (string) $ability_name, 'saddle/' ) ) {
			return;
		}
		self::$active   = true;
		self::$items    = array();
		self::$seen     = array();
		self::$overflow = false;
	}

	/**
	 * Stop recording when a saddle/* ability returns. Core skips this hook when
	 * the ability returns an error; the next open() resets the journal then.
	 *
	 * @param string $ability_name Ability that finished.
	 */
	public static function close( $ability_name ) {
		if ( 0 === strpos( (string) $ability_name, 'saddle/' ) ) {
			self::$active   = false;
			self::$items    = array();
			self::$seen     = array();
			self::$overflow = false;
		}
	}

	/**
	 * Hand over what was recorded, with each object's state right now (the
	 * "after" undo compares against), and start over. Null when nothing was.
	 *
	 * @return array|null
	 */
	public static function take() {
		if ( ! self::$active || ( ! self::$items && ! self::$overflow ) ) {
			return null;
		}
		$items = array();
		foreach ( self::$items as $item ) {
			$item['after'] = self::state( $item );
			$items[]       = $item;
		}
		$journal = array(
			'v'     => 1,
			'items' => $items,
		);
		if ( self::$overflow ) {
			$journal['overflow'] = true;
		}
		self::$items    = array();
		self::$seen     = array();
		self::$overflow = false;
		return $journal;
	}

	/*
	---------------------------------------------------------------------
	 * Listeners (thin: each hands off to a recorder)
	 * -------------------------------------------------------------------
	 */

	/**
	 * At the start of wp_insert_post(), before core touches anything. This is
	 * the snapshot point: by pre_post_update, a post on its way to the trash
	 * already has "__trashed" on its slug. Filter; the value passes through.
	 *
	 * @param bool  $maybe_empty Whether core considers the post empty.
	 * @param array $postarr     The post data; an update carries its ID.
	 * @return bool
	 */
	public static function on_post_save( $maybe_empty, $postarr ) {
		if ( self::$active && ! empty( $postarr['ID'] ) ) {
			self::note_post( (int) $postarr['ID'] );
		}
		return $maybe_empty;
	}

	/**
	 * Before a post update: the fallback for an update that skipped the
	 * empty-content check.
	 *
	 * @param int $post_id Post being updated.
	 */
	public static function on_post_update( $post_id ) {
		if ( self::$active ) {
			self::note_post( (int) $post_id );
		}
	}

	/**
	 * After a post insert: a new post is undone by trashing it.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this was an update.
	 */
	public static function on_post_insert( $post_id, $post, $update ) {
		if ( ! self::$active || $update || ! $post instanceof WP_Post || 'auto-draft' === $post->post_status || ! self::journals_type( $post->post_type ) ) {
			return;
		}
		$key = 'post:' . (int) $post_id;
		if ( isset( self::$seen[ $key ] ) ) {
			return;
		}
		self::$seen[ $key ] = true;
		self::add(
			array(
				'type'   => 'created',
				'id'     => (int) $post_id,
				'ptype'  => $post->post_type,
				'before' => 'absent',
			)
		);
	}

	/**
	 * Before a permanent delete: recorded so undo can say it can't bring it back.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_post_delete( $post_id ) {
		$post = self::$active ? get_post( (int) $post_id ) : null;
		if ( ! $post || ! self::journals_type( $post->post_type ) ) {
			return;
		}
		self::add(
			array(
				'type'   => 'deleted',
				'id'     => (int) $post_id,
				'title'  => $post->post_title,
				'before' => self::post_hash( $post ),
			)
		);
	}

	/**
	 * Before any post-meta add, update or delete. Short-circuit filter: the
	 * value passes through untouched.
	 *
	 * @param mixed  $check     Short-circuit value.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 * @return mixed
	 */
	public static function on_meta( $check, $object_id, $meta_key ) {
		if ( self::$active ) {
			self::note_meta( (int) $object_id, (string) $meta_key );
		}
		return $check;
	}

	/**
	 * After a post's terms are set; core hands over the previous ones.
	 *
	 * @param int    $object_id  Post id.
	 * @param array  $terms      Terms set.
	 * @param array  $tt_ids     New term-taxonomy ids.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether appended.
	 * @param array  $old_tt_ids Previous term-taxonomy ids.
	 */
	public static function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( ! self::$active || ! self::journals_post( (int) $object_id ) ) {
			return;
		}
		$key = 'terms:' . (int) $object_id . ':' . $taxonomy;
		if ( isset( self::$seen[ $key ] ) ) {
			return;
		}
		self::$seen[ $key ] = true;

		$ids = array();
		foreach ( (array) $old_tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		sort( $ids );
		self::add(
			array(
				'type'     => 'terms',
				'id'       => (int) $object_id,
				'taxonomy' => (string) $taxonomy,
				'terms'    => $ids,
				'before'   => self::hash( $ids ),
			)
		);
	}

	/**
	 * After a term is created.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term-taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_term_created( $term_id, $tt_id, $taxonomy ) {
		if ( ! self::$active || 0 === strpos( (string) $taxonomy, 'saddle' ) ) {
			return;
		}
		self::add(
			array(
				'type'     => 'term',
				'id'       => (int) $term_id,
				'taxonomy' => (string) $taxonomy,
				'before'   => 'absent',
			)
		);
	}

	/**
	 * Before an option update.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Its value before the update.
	 */
	public static function on_option_update( $option, $old_value ) {
		if ( self::$active ) {
			self::note_option( (string) $option, true, $old_value );
		}
	}

	/**
	 * Before an option is added (it did not exist).
	 *
	 * @param string $option Option name.
	 */
	public static function on_option_add( $option ) {
		if ( self::$active ) {
			self::note_option( (string) $option, false, null );
		}
	}

	/**
	 * Before an option is deleted.
	 *
	 * @param string $option Option name.
	 */
	public static function on_option_delete( $option ) {
		if ( self::$active ) {
			self::note_option( (string) $option, true, get_option( $option ) );
		}
	}

	/**
	 * After the theme is switched.
	 *
	 * @param string   $new_name  New theme name.
	 * @param WP_Theme $new_theme New theme.
	 * @param WP_Theme $old_theme Previous theme.
	 */
	public static function on_theme( $new_name, $new_theme, $old_theme ) {
		if ( ! self::$active || ! $old_theme instanceof WP_Theme || isset( self::$seen['theme'] ) ) {
			return;
		}
		self::$seen['theme'] = true;
		self::add(
			array(
				'type'       => 'theme',
				'stylesheet' => $old_theme->get_stylesheet(),
				'before'     => $old_theme->get_stylesheet(),
			)
		);
	}

	/**
	 * After a plugin is activated.
	 *
	 * @param string $plugin Plugin file.
	 */
	public static function on_plugin_activated( $plugin ) {
		self::note_plugin( (string) $plugin, 'inactive' );
	}

	/**
	 * After a plugin is deactivated.
	 *
	 * @param string $plugin Plugin file.
	 */
	public static function on_plugin_deactivated( $plugin ) {
		self::note_plugin( (string) $plugin, 'active' );
	}

	/*
	---------------------------------------------------------------------
	 * Recorders
	 * -------------------------------------------------------------------
	 */

	/**
	 * Record a post's fields before its first change in this call. Content
	 * goes into a revision where the post type keeps them, so the log never
	 * holds a second copy of every page.
	 *
	 * @param int $post_id Post id.
	 */
	private static function note_post( $post_id ) {
		$post = get_post( $post_id );
		$key  = 'post:' . $post_id;
		if ( ! $post || isset( self::$seen[ $key ] ) || ! self::journals_type( $post->post_type ) ) {
			return;
		}
		self::$seen[ $key ] = true;

		$item = array(
			'type'   => 'post',
			'id'     => $post_id,
			'fields' => self::post_fields( $post ),
			'before' => self::post_hash( $post ),
		);

		$revision = self::snapshot_revision( $post );
		if ( $revision ) {
			$item['revision'] = $revision;
		} elseif ( strlen( $post->post_content ) <= self::MAX_VALUE ) {
			$item['content'] = $post->post_content;
		} else {
			$item['lost'] = 'content';
		}
		self::add( $item );
	}

	/**
	 * Record a meta key's values before its first change in this call.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 */
	private static function note_meta( $post_id, $key ) {
		$seen = 'meta:' . $post_id . ':' . $key;
		if ( isset( self::$seen[ $seen ] ) || in_array( $key, self::SKIP_META, true ) || 0 === strpos( $key, '_saddle_' ) || ! self::journals_post( $post_id ) ) {
			return;
		}
		self::$seen[ $seen ] = true;

		$values = get_post_meta( $post_id, $key, false );
		$stored = array_map( 'maybe_serialize', $values );
		$item   = array(
			'type'   => 'meta',
			'id'     => $post_id,
			'key'    => $key,
			'before' => self::hash( $values ),
		);
		$flat   = implode( '', $stored );
		if ( strlen( $flat ) > self::MAX_VALUE || self::holds_object( $flat ) ) {
			$item['lost'] = 'value';
		} else {
			$item['values'] = $stored;
		}
		self::add( $item );
	}

	/**
	 * Record an allowlisted option before its first change in this call.
	 *
	 * @param string $name   Option name.
	 * @param bool   $exists Whether it existed before.
	 * @param mixed  $value  Its value before (ignored when it did not exist).
	 */
	private static function note_option( $name, $exists, $value ) {
		$key = 'option:' . $name;
		if ( isset( self::$seen[ $key ] ) || ! self::journals_option( $name ) ) {
			return;
		}
		self::$seen[ $key ] = true;

		$item = array(
			'type'   => 'option',
			'name'   => $name,
			'exists' => $exists,
			'before' => $exists ? self::hash( $value ) : 'absent',
		);
		if ( $exists ) {
			$stored = maybe_serialize( $value );
			if ( strlen( (string) $stored ) > self::MAX_VALUE || self::holds_object( (string) $stored ) ) {
				$item['lost'] = 'value';
			} else {
				$item['value'] = $stored;
			}
		}
		self::add( $item );
	}

	/**
	 * Record a plugin's activation state before its change.
	 *
	 * @param string $plugin Plugin file.
	 * @param string $before 'active' or 'inactive'.
	 */
	private static function note_plugin( $plugin, $before ) {
		$key = 'plugin:' . $plugin;
		if ( ! self::$active || isset( self::$seen[ $key ] ) ) {
			return;
		}
		self::$seen[ $key ] = true;
		self::add(
			array(
				'type'   => 'plugin',
				'plugin' => $plugin,
				'before' => $before,
			)
		);
	}

	/**
	 * Append an item, or mark the journal overflowed.
	 *
	 * @param array $item Journal item.
	 */
	private static function add( array $item ) {
		if ( count( self::$items ) >= self::MAX_ITEMS ) {
			self::$overflow = true;
			return;
		}
		self::$items[] = $item;
	}

	/*
	---------------------------------------------------------------------
	 * State: one fingerprint per object, shared with Saddle_Undo
	 * -------------------------------------------------------------------
	 */

	/**
	 * The current fingerprint of the object an item describes. Undo compares
	 * it with the item's "after": equal means nothing changed it since.
	 *
	 * @param array $item Journal item.
	 * @return string
	 */
	public static function state( array $item ) {
		switch ( $item['type'] ) {
			case 'post':
			case 'created':
			case 'deleted':
				$post = get_post( (int) $item['id'] );
				return $post ? self::post_hash( $post ) : 'absent';
			case 'meta':
				return get_post( (int) $item['id'] ) ? self::hash( get_post_meta( (int) $item['id'], $item['key'], false ) ) : 'absent';
			case 'terms':
				$ids = wp_get_object_terms( (int) $item['id'], $item['taxonomy'], array( 'fields' => 'ids' ) );
				$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
				sort( $ids );
				return self::hash( $ids );
			case 'term':
				return term_exists( (int) $item['id'], $item['taxonomy'] ) ? 'exists' : 'absent';
			case 'option':
				$missing = new stdClass();
				$value   = get_option( $item['name'], $missing );
				return $value === $missing ? 'absent' : self::hash( $value );
			case 'theme':
				return get_stylesheet();
			case 'plugin':
				if ( ! function_exists( 'is_plugin_active' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				return is_plugin_active( $item['plugin'] ) ? 'active' : 'inactive';
		}
		return '';
	}

	/**
	 * The object key an item describes, for chaining state across entries.
	 *
	 * @param array $item Journal item.
	 * @return string
	 */
	public static function object_key( array $item ) {
		switch ( $item['type'] ) {
			case 'meta':
				return 'meta:' . $item['id'] . ':' . $item['key'];
			case 'terms':
				return 'terms:' . $item['id'] . ':' . $item['taxonomy'];
			case 'term':
				return 'term:' . $item['id'];
			case 'option':
				return 'option:' . $item['name'];
			case 'theme':
				return 'theme';
			case 'plugin':
				return 'plugin:' . $item['plugin'];
		}
		return 'post:' . $item['id'];
	}

	/**
	 * The post fields undo restores, content aside.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function post_fields( WP_Post $post ) {
		return array(
			'post_title'    => $post->post_title,
			'post_excerpt'  => $post->post_excerpt,
			'post_status'   => $post->post_status,
			'post_name'     => $post->post_name,
			'post_parent'   => (int) $post->post_parent,
			'menu_order'    => (int) $post->menu_order,
			'post_password' => $post->post_password,
		);
	}

	/**
	 * A post's fingerprint. Text fields are whitespace-normalised the way core
	 * compares revisions, so content restored from a revision still matches.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function post_hash( WP_Post $post ) {
		$fields                 = self::post_fields( $post );
		$fields['post_content'] = $post->post_content;
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			$fields[ $field ] = normalize_whitespace( (string) $fields[ $field ] );
		}
		return md5( (string) wp_json_encode( $fields ) );
	}

	/**
	 * Fingerprint of any value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function hash( $value ) {
		return md5( serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A fingerprint, never stored or unserialized.
	}

	/*
	---------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------
	 */

	/**
	 * A revision holding the post as it is now: a new one when the latest
	 * differs, otherwise the latest. 0 when the type keeps no revisions.
	 *
	 * @param WP_Post $post Post.
	 * @return int
	 */
	private static function snapshot_revision( WP_Post $post ) {
		if ( ! wp_revisions_enabled( $post ) ) {
			return 0;
		}
		$saved = wp_save_post_revision( $post->ID );
		if ( $saved && ! is_wp_error( $saved ) ) {
			return (int) $saved;
		}
		foreach ( wp_get_post_revisions( $post->ID ) as $revision ) {
			// The latest real revision, not an autosave (core's own test).
			if ( str_contains( $revision->post_name, "{$revision->post_parent}-revision" ) ) {
				return (int) $revision->ID;
			}
		}
		return 0;
	}

	/**
	 * Whether a post id names a post whose changes are journaled and that was
	 * not created in this same call (undoing a creation trashes the post).
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function journals_post( $post_id ) {
		if ( $post_id <= 0 ) {
			return false;
		}
		foreach ( self::$items as $item ) {
			if ( 'created' === $item['type'] && $item['id'] === $post_id ) {
				return false;
			}
		}
		$type = get_post_type( $post_id );
		return $type && self::journals_type( $type );
	}

	/**
	 * Whether a post type is journaled.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	private static function journals_type( $type ) {
		return ! in_array( $type, self::SKIP_TYPES, true ) && 0 !== strpos( (string) $type, 'saddle_' );
	}

	/**
	 * Whether an option is journaled: the ones Saddle's tools write.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function journals_option( $name ) {
		if ( in_array( $name, self::EXTRA_OPTIONS, true ) ) {
			return true;
		}
		return class_exists( 'Saddle_Site_Abilities' ) && in_array( $name, Saddle_Site_Abilities::allowlist(), true );
	}

	/**
	 * Whether a serialized value holds a PHP object, which the log won't keep:
	 * restoring one means unserializing a class, and undo never does.
	 *
	 * @param string $serialized Serialized value(s).
	 * @return bool
	 */
	private static function holds_object( $serialized ) {
		return (bool) preg_match( '/(^|[;{}])[OC]:\d+:"/', $serialized );
	}
}
