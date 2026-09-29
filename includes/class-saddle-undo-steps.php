<?php
/**
 * Undo, one journal item at a time: check, describe, restore.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The per-item half of undo (#181). Saddle_Undo decides entry by entry; this
 * class knows, for each kind of recorded change, whether it can still be
 * reversed, how to say what reversing it does, and how to do it.
 *
 * Every restore goes through WordPress's own APIs (wp_update_post,
 * wp_untrash_post, update_option, switch_theme, …), never raw SQL, and each
 * item is checked against the capability that API would need plus, for
 * site-wide settings, the admin tier.
 */
class Saddle_Undo_Steps {

	/**
	 * Why an item can't be reversed, or '' when it can.
	 *
	 * @param array   $item     Journal item.
	 * @param string  $current  The object's state now (or after newer undos).
	 * @param array[] $siblings The other items of the same entry.
	 * @return string
	 */
	public static function problem( array $item, $current, array $siblings = array() ) {
		$label = self::label( $item );

		if ( 'deleted' === $item['type'] ) {
			/* translators: %s: post label. */
			return sprintf( __( '%s was permanently deleted, so it can’t be restored.', 'saddle' ), $label );
		}
		if ( 'created' === $item['type'] && 'attachment' === $item['ptype'] ) {
			/* translators: %s: media label. */
			return sprintf( __( '%s is an upload: remove it with delete-media, not undo.', 'saddle' ), $label );
		}
		if ( isset( $item['lost'] ) ) {
			/* translators: %s: object label. */
			return sprintf( __( 'The earlier state of %s was too large to keep for undo.', 'saddle' ), $label );
		}
		if ( $current !== $item['after'] ) {
			/* translators: %s: object label. */
			return sprintf( __( '%s has changed since, so it was left alone.', 'saddle' ), $label );
		}

		$denied = self::denied( $item );
		if ( '' !== $denied ) {
			return $denied;
		}

		switch ( $item['type'] ) {
			case 'post':
				if ( isset( $item['revision'] ) && null === self::revision_content( $item ) ) {
					/* translators: %s: post label. */
					return sprintf( __( 'The saved revision of %s is gone (WordPress keeps a limited number).', 'saddle' ), $label );
				}
				break;
			case 'term':
				$used = self::term_users( $item, $siblings );
				if ( $used ) {
					/* translators: 1: term label, 2: how many posts use it. */
					return sprintf( _n( '%1$s is now used by %2$d post.', '%1$s is now used by %2$d posts.', $used, 'saddle' ), $label, $used );
				}
				break;
			case 'theme':
				if ( ! wp_get_theme( $item['stylesheet'] )->exists() ) {
					/* translators: %s: theme folder. */
					return sprintf( __( 'The theme %s is no longer installed.', 'saddle' ), $item['stylesheet'] );
				}
				break;
			case 'plugin':
				self::load_plugin_api();
				if ( is_wp_error( validate_plugin( $item['plugin'] ) ) ) {
					/* translators: %s: plugin file. */
					return sprintf( __( 'The plugin %s is no longer installed.', 'saddle' ), $item['plugin'] );
				}
				break;
		}
		return '';
	}

	/**
	 * What reversing an item does, in a sentence.
	 *
	 * @param array $item Journal item.
	 * @return string
	 */
	public static function describe( array $item ) {
		$label = self::label( $item );
		switch ( $item['type'] ) {
			case 'post':
				$post = get_post( (int) $item['id'] );
				if ( $post && $post->post_status !== $item['fields']['post_status'] ) {
					/* translators: 1: post label, 2: post status. */
					return sprintf( __( 'Restore %1$s to its earlier version and set its status back to %2$s.', 'saddle' ), $label, $item['fields']['post_status'] );
				}
				/* translators: %s: post label. */
				return sprintf( __( 'Restore %s to its earlier version.', 'saddle' ), $label );
			case 'created':
				/* translators: %s: post label. */
				return sprintf( __( 'Move %s, which this change created, to the trash.', 'saddle' ), $label );
			case 'meta':
				/* translators: 1: field name, 2: post label. */
				return sprintf( __( 'Restore the %1$s field on %2$s.', 'saddle' ), $item['key'], self::post_label( (int) $item['id'] ) );
			case 'terms':
				/* translators: 1: taxonomy, 2: post label. */
				return sprintf( __( 'Restore the %1$s on %2$s.', 'saddle' ), $item['taxonomy'], self::post_label( (int) $item['id'] ) );
			case 'term':
				/* translators: %s: term label. */
				return sprintf( __( 'Delete %s, which this change created.', 'saddle' ), $label );
			case 'option':
				return $item['exists']
					/* translators: 1: option name, 2: value. */
					? sprintf( __( 'Set %1$s back to “%2$s”.', 'saddle' ), $item['name'], self::short( self::value( $item['value'] ) ) )
					/* translators: %s: option name. */
					: sprintf( __( 'Remove %s, which this change added.', 'saddle' ), $item['name'] );
			case 'theme':
				/* translators: %s: theme name. */
				return sprintf( __( 'Switch the theme back to %s.', 'saddle' ), wp_get_theme( $item['stylesheet'] )->get( 'Name' ) );
			case 'plugin':
				return 'active' === $item['before']
					/* translators: %s: plugin file. */
					? sprintf( __( 'Reactivate the plugin %s.', 'saddle' ), $item['plugin'] )
					/* translators: %s: plugin file. */
					: sprintf( __( 'Deactivate the plugin %s.', 'saddle' ), $item['plugin'] );
		}
		return '';
	}

	/**
	 * Reverse one item.
	 *
	 * @param array $item Journal item.
	 * @return true|WP_Error
	 */
	public static function restore( array $item ) {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		switch ( $item['type'] ) {
			case 'post':
				return self::restore_post( $item );
			case 'created':
				return wp_trash_post( $id ) ? true : self::failed( $item );
			case 'meta':
				delete_post_meta( $id, $item['key'] );
				foreach ( $item['values'] as $stored ) {
					add_post_meta( $id, $item['key'], wp_slash( self::value( $stored ) ) );
				}
				return true;
			case 'terms':
				$set = wp_set_object_terms( $id, array_map( 'intval', $item['terms'] ), $item['taxonomy'] );
				return is_wp_error( $set ) ? $set : true;
			case 'term':
				$deleted = wp_delete_term( $id, $item['taxonomy'] );
				return is_wp_error( $deleted ) ? $deleted : ( $deleted ? true : self::failed( $item ) );
			case 'option':
				if ( $item['exists'] ) {
					update_option( $item['name'], self::value( $item['value'] ) );
				} else {
					delete_option( $item['name'] );
				}
				return true;
			case 'theme':
				switch_theme( $item['stylesheet'] );
				return true;
			case 'plugin':
				self::load_plugin_api();
				if ( 'active' === $item['before'] ) {
					$activated = activate_plugin( $item['plugin'] );
					return is_wp_error( $activated ) ? $activated : true;
				}
				deactivate_plugins( $item['plugin'] );
				return true;
		}
		return self::failed( $item );
	}

	/**
	 * Put a post's fields and content back, untrashing it first if needed so
	 * core restores its comments and trash bookkeeping too.
	 *
	 * @param array $item Journal item.
	 * @return true|WP_Error
	 */
	private static function restore_post( array $item ) {
		$id     = (int) $item['id'];
		$fields = $item['fields'];
		$target = $fields['post_status'];

		if ( 'trash' === get_post_status( $id ) && 'trash' !== $target ) {
			$status = static function () use ( $target ) {
				return $target;
			};
			add_filter( 'wp_untrash_post_status', $status );
			wp_untrash_post( $id );
			remove_filter( 'wp_untrash_post_status', $status );
		}

		$data = array_merge(
			$fields,
			array(
				'ID'           => $id,
				'post_content' => isset( $item['revision'] ) ? self::revision_content( $item ) : $item['content'],
			)
		);
		if ( 'trash' === $target ) {
			unset( $data['post_status'] );
		}
		$updated = wp_update_post( wp_slash( $data ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		if ( 'trash' === $target && 'trash' !== get_post_status( $id ) ) {
			wp_trash_post( $id );
		}
		return true;
	}

	/**
	 * The refusal for an item the current user or tier may not reverse, or ''.
	 *
	 * @param array $item Journal item.
	 * @return string
	 */
	private static function denied( array $item ) {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		switch ( $item['type'] ) {
			case 'post':
			case 'meta':
			case 'terms':
				$ok = current_user_can( 'edit_post', $id );
				break;
			case 'created':
				$ok = current_user_can( 'delete_post', $id );
				break;
			case 'term':
				$ok = current_user_can( 'delete_term', $id );
				break;
			case 'option':
				$ok = current_user_can( 'manage_options' ) && Saddle_Capabilities::tier_allows( 'admin' );
				break;
			case 'theme':
				$ok = current_user_can( 'switch_themes' ) && Saddle_Capabilities::tier_allows( 'admin' );
				break;
			case 'plugin':
				$ok = current_user_can( 'activate_plugins' ) && Saddle_Capabilities::tier_allows( 'admin' );
				break;
			default:
				$ok = false;
		}
		if ( $ok ) {
			return '';
		}
		return in_array( $item['type'], array( 'option', 'theme', 'plugin' ), true )
			/* translators: %s: object label. */
			? sprintf( __( 'Reversing %s needs the admin level, and a WordPress account that manages the site.', 'saddle' ), self::label( $item ) )
			/* translators: %s: object label. */
			: sprintf( __( 'This WordPress account may not change %s.', 'saddle' ), self::label( $item ) );
	}

	/**
	 * How many posts will still use a created term once this entry is undone:
	 * posts whose terms the same entry puts back without it don't count.
	 *
	 * @param array   $item     A 'term' item.
	 * @param array[] $siblings The other items of the same entry.
	 * @return int
	 */
	private static function term_users( array $item, array $siblings ) {
		$objects = get_objects_in_term( (int) $item['id'], $item['taxonomy'] );
		if ( is_wp_error( $objects ) ) {
			return 0;
		}
		$released = array();
		foreach ( $siblings as $sibling ) {
			if ( 'terms' === $sibling['type'] && $sibling['taxonomy'] === $item['taxonomy'] && ! in_array( (int) $item['id'], $sibling['terms'], true ) ) {
				$released[] = (int) $sibling['id'];
			}
		}
		return count( array_diff( array_map( 'intval', $objects ), $released ) );
	}

	/**
	 * The recorded content of a post item's revision, or null when it is gone.
	 *
	 * @param array $item Journal item.
	 * @return string|null
	 */
	private static function revision_content( array $item ) {
		$revision = get_post( (int) $item['revision'] );
		if ( ! $revision || 'revision' !== $revision->post_type || (int) $revision->post_parent !== (int) $item['id'] ) {
			return null;
		}
		return $revision->post_content;
	}

	/**
	 * A stored value as it was: unserialized without ever building an object
	 * (the journal refuses to store one in the first place).
	 *
	 * @param string $stored Stored value.
	 * @return mixed
	 */
	private static function value( $stored ) {
		if ( is_serialized( $stored ) ) {
			return unserialize( trim( $stored ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Saddle's own journal; classes refused.
		}
		return $stored;
	}

	/**
	 * A readable name for the object an item describes.
	 *
	 * @param array $item Journal item.
	 * @return string
	 */
	private static function label( array $item ) {
		switch ( $item['type'] ) {
			case 'post':
			case 'created':
			case 'meta':
			case 'terms':
				return self::post_label( (int) $item['id'] );
			case 'deleted':
				/* translators: 1: post title, 2: post id. */
				return sprintf( __( '“%1$s” (#%2$d)', 'saddle' ), $item['title'], (int) $item['id'] );
			case 'term':
				$term = get_term( (int) $item['id'], $item['taxonomy'] );
				/* translators: 1: taxonomy, 2: term name. */
				return sprintf( __( 'the %1$s “%2$s”', 'saddle' ), $item['taxonomy'], $term instanceof WP_Term ? $term->name : (int) $item['id'] );
			case 'option':
				/* translators: %s: option name. */
				return sprintf( __( 'the setting %s', 'saddle' ), $item['name'] );
			case 'theme':
				return __( 'the active theme', 'saddle' );
			case 'plugin':
				/* translators: %s: plugin file. */
				return sprintf( __( 'the plugin %s', 'saddle' ), $item['plugin'] );
		}
		return '';
	}

	/**
	 * “Title” (#id), or #id when the post is gone.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	private static function post_label( $id ) {
		$post = get_post( $id );
		/* translators: 1: post title, 2: post id. */
		return $post ? sprintf( __( '“%1$s” (#%2$d)', 'saddle' ), $post->post_title, $id ) : sprintf( '#%d', $id );
	}

	/**
	 * A value shortened for a one-line description.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function short( $value ) {
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '…' : $text;
	}

	/**
	 * validate_plugin(), activate_plugin() and deactivate_plugins() live in an
	 * admin include that an MCP request does not load.
	 */
	private static function load_plugin_api() {
		if ( ! function_exists( 'validate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * A restore WordPress declined without saying why.
	 *
	 * @param array $item Journal item.
	 * @return WP_Error
	 */
	private static function failed( array $item ) {
		return new WP_Error(
			'saddle_undo_failed',
			/* translators: %s: object label. */
			sprintf( __( 'WordPress would not restore %s.', 'saddle' ), self::label( $item ) )
		);
	}
}
