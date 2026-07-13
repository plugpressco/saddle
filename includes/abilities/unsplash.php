<?php
/**
 * Unsplash stock-photo abilities — search the Unsplash library and import
 * photos into the media library (https://github.com/plugpressco/saddle/issues/60).
 *
 * Token-thrifty by design: search results carry an `in_library` annotation so
 * agents reuse photos already imported, and unsplash-import refuses to
 * re-download a photo the library already has unless `force` is passed.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Unsplash abilities. Hooked to `wp_abilities_api_init`.
 *
 * Registered even when no Access Key is configured — the not-configured error
 * tells the agent exactly what the owner must do, and the Permissions UI
 * should show the tools exist.
 */
function saddle_register_unsplash_abilities() {

	wp_register_ability(
		'saddle/unsplash-search',
		array(
			'label'               => __( 'Search Unsplash', 'saddle' ),
			'description'         => __( 'Searches the Unsplash free stock-photo library. Results are previews only. Each result includes in_library/media_id: when in_library is true the photo is ALREADY in this site\'s media library — reuse that media_id instead of importing again. Otherwise call unsplash-import with the photo id. Never sideload the preview URLs yourself; that skips Unsplash\'s required attribution and download tracking. The search query is sent to the Unsplash API using the site owner\'s own key. Rate-limited (free keys: 50 requests/hour), so search deliberately, not iteratively.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'query' ),
				'properties' => array(
					'query'       => array(
						'type'        => 'string',
						'description' => __( 'Search keywords, e.g. "mountain sunrise".', 'saddle' ),
					),
					'page'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Result page.', 'saddle' ),
					),
					'per_page'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 30,
						'default'     => 10,
						'description' => __( 'Results per page (1–30).', 'saddle' ),
					),
					'orientation' => array(
						'type'        => 'string',
						'enum'        => array( 'landscape', 'portrait', 'squarish' ),
						'description' => __( 'Only photos of this orientation.', 'saddle' ),
					),
					'color'       => array(
						'type'        => 'string',
						'enum'        => array( 'black_and_white', 'black', 'white', 'yellow', 'orange', 'red', 'purple', 'magenta', 'green', 'teal', 'blue' ),
						'description' => __( 'Only photos with this dominant color.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Unsplash_Abilities', 'search' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'unsplash-search' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/unsplash-import',
		array(
			'label'               => __( 'Import Unsplash photo', 'saddle' ),
			'description'         => __( 'Downloads one Unsplash photo into the media library by its photo id (from unsplash-search) and returns the new media item. Handles Unsplash\'s required download tracking, and defaults the alt text to Unsplash\'s description and the caption to the photographer attribution. Dedupe-safe: if the photo was already imported it returns the existing media item WITHOUT downloading again — pass force=true only if the user explicitly wants a duplicate copy. Additive and non-destructive.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'photo_id' ),
				'properties' => array(
					'photo_id' => array(
						'type'        => 'string',
						'description' => __( 'The Unsplash photo id from unsplash-search results.', 'saddle' ),
					),
					'title'    => array(
						'type'        => 'string',
						'description' => __( 'Media title. Defaults to the photo\'s Unsplash description.', 'saddle' ),
					),
					'alt'      => array(
						'type'        => 'string',
						'description' => __( 'Alt text. Defaults to Unsplash\'s accessibility description.', 'saddle' ),
					),
					'caption'  => array(
						'type'        => 'string',
						'description' => __( 'Caption. Defaults to the photographer attribution Unsplash requires — only override with the user\'s explicit intent.', 'saddle' ),
					),
					'post_id'  => array(
						'type'        => 'integer',
						'description' => __( 'Optional post/page to attach the media to.', 'saddle' ),
					),
					'force'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Re-download even if this photo is already in the media library.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Unsplash_Abilities', 'import' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'upload_files', 'unsplash-import' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);
}

/**
 * Execute callbacks for the Unsplash abilities.
 */
class Saddle_Unsplash_Abilities {

	/**
	 * saddle/unsplash-search.
	 *
	 * @param mixed $input { query, page, per_page, orientation, color }.
	 * @return array|WP_Error
	 */
	public static function search( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$query = isset( $input['query'] ) && is_string( $input['query'] ) ? trim( $input['query'] ) : '';
		if ( '' === $query ) {
			return new WP_Error( 'saddle_missing_query', __( 'A "query" is required.', 'saddle' ), array( 'status' => 400 ) );
		}

		$args = array(
			'query'    => $query,
			'page'     => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			'per_page' => isset( $input['per_page'] ) ? min( 30, max( 1, (int) $input['per_page'] ) ) : 10,
		);
		if ( ! empty( $input['orientation'] ) && in_array( $input['orientation'], array( 'landscape', 'portrait', 'squarish' ), true ) ) {
			$args['orientation'] = $input['orientation'];
		}
		if ( ! empty( $input['color'] ) && is_string( $input['color'] ) ) {
			$args['color'] = sanitize_key( $input['color'] );
		}

		$body = Saddle_Unsplash::request( '/search/photos', $args );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$hits     = isset( $body['results'] ) && is_array( $body['results'] ) ? $body['results'] : array();
		$existing = Saddle_Unsplash::find_existing_many( wp_list_pluck( $hits, 'id' ) );

		$results = array();
		foreach ( $hits as $photo ) {
			if ( empty( $photo['id'] ) ) {
				continue;
			}
			$photo_id  = (string) $photo['id'];
			$results[] = array(
				'id'               => $photo_id,
				'description'      => sanitize_text_field( (string) ( $photo['description'] ?? '' ) ),
				'alt_description'  => sanitize_text_field( (string) ( $photo['alt_description'] ?? '' ) ),
				'width'            => (int) ( $photo['width'] ?? 0 ),
				'height'           => (int) ( $photo['height'] ?? 0 ),
				'preview_url'      => esc_url_raw( (string) ( $photo['urls']['small'] ?? '' ) ),
				'photographer'     => sanitize_text_field( (string) ( $photo['user']['name'] ?? '' ) ),
				'photographer_url' => esc_url_raw( Saddle_Unsplash::photographer_url( $photo ) ),
				'in_library'       => isset( $existing[ $photo_id ] ),
				'media_id'         => isset( $existing[ $photo_id ] ) ? $existing[ $photo_id ] : null,
			);
		}

		return array(
			'results'     => $results,
			'total'       => (int) ( $body['total'] ?? count( $results ) ),
			'total_pages' => (int) ( $body['total_pages'] ?? 1 ),
			'page'        => $args['page'],
		);
	}

	/**
	 * saddle/unsplash-import.
	 *
	 * @param mixed $input { photo_id, title, alt, caption, post_id, force }.
	 * @return array|WP_Error
	 */
	public static function import( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$photo_id = isset( $input['photo_id'] ) && is_string( $input['photo_id'] ) ? trim( $input['photo_id'] ) : '';
		if ( '' === $photo_id || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $photo_id ) ) {
			return new WP_Error( 'saddle_invalid_photo_id', __( 'A valid "photo_id" from unsplash-search is required.', 'saddle' ), array( 'status' => 400 ) );
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You do not have permission to attach media to that item.', 'saddle' ), array( 'status' => 403 ) );
		}

		// Dedupe first — zero HTTP, zero downloads, unless the caller forces it.
		$force    = ! empty( $input['force'] );
		$existing = Saddle_Unsplash::find_existing( $photo_id );
		if ( $existing > 0 && ! $force ) {
			$media = Saddle_Abilities::get_media( array( 'id' => $existing ) );
			if ( ! is_wp_error( $media ) ) {
				$media['already_in_library'] = true;
				$media['note']               = __( 'This Unsplash photo is already in the media library — nothing was downloaded. Reuse this media item, or pass force=true only if the user explicitly wants a duplicate copy.', 'saddle' );
				return $media;
			}
		}

		$photo = Saddle_Unsplash::request( '/photos/' . rawurlencode( $photo_id ) );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		// Unsplash API guideline: a real download must ping download_location.
		// Fire-and-forget, before the file fetch so the normal path always
		// tracks; a failed ping never blocks the import.
		Saddle_Unsplash::trigger_download( (string) ( $photo['links']['download_location'] ?? '' ) );

		// `raw` always carries imgix query args, so appending with & is right.
		// fm=jpg guarantees a decodable type; w=2400 keeps the file sane.
		$file_url = '';
		if ( ! empty( $photo['urls']['raw'] ) ) {
			$file_url = (string) $photo['urls']['raw'] . '&w=2400&fit=max&q=85&fm=jpg';
		} elseif ( ! empty( $photo['urls']['full'] ) ) {
			$file_url = (string) $photo['urls']['full'];
		}
		if ( '' === $file_url ) {
			return new WP_Error( 'saddle_unsplash_bad_response', __( 'Unsplash did not return a downloadable file URL for that photo.', 'saddle' ), array( 'status' => 502 ) );
		}

		// The URL path has no extension — the explicit filename is required or
		// wp_check_filetype() rejects the sideload.
		$attachment_id = Saddle_Abilities::sideload_url_to_library( $file_url, 'unsplash-' . sanitize_file_name( $photo_id ) . '.jpg', $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$description = sanitize_text_field( (string) ( $photo['description'] ?? '' ) );
		$alt_desc    = sanitize_text_field( (string) ( $photo['alt_description'] ?? '' ) );

		$title = isset( $input['title'] ) && is_string( $input['title'] ) && '' !== trim( $input['title'] )
			? sanitize_text_field( $input['title'] )
			: ( '' !== $description ? $description : ( '' !== $alt_desc ? $alt_desc : sprintf(
				/* translators: %s: Unsplash photo id. */
				__( 'Unsplash photo %s', 'saddle' ),
				$photo_id
			) ) );

		$alt = isset( $input['alt'] ) && is_string( $input['alt'] ) && '' !== trim( $input['alt'] )
			? sanitize_text_field( $input['alt'] )
			: $alt_desc;

		$caption = isset( $input['caption'] ) && is_string( $input['caption'] ) && '' !== trim( $input['caption'] )
			? wp_kses_post( $input['caption'] )
			: wp_kses_post( Saddle_Unsplash::attribution_caption( $photo ) );

		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_title'   => $title,
				'post_excerpt' => $caption,
			)
		);
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
		update_post_meta( $attachment_id, Saddle_Unsplash::META_ID, $photo_id );
		if ( ! empty( $photo['user']['name'] ) ) {
			update_post_meta( $attachment_id, Saddle_Unsplash::META_PHOTOGRAPHER, sanitize_text_field( $photo['user']['name'] ) );
		}

		Saddle_Log::record_action(
			'unsplash-import',
			$attachment_id,
			sprintf(
				/* translators: 1: Unsplash photo id, 2: attachment id. */
				__( 'Imported Unsplash photo %1$s as media #%2$d', 'saddle' ),
				$photo_id,
				$attachment_id
			)
		);

		return Saddle_Abilities::get_media( array( 'id' => $attachment_id ) );
	}
}
