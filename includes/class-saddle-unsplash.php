<?php
/**
 * Unsplash integration service — owner-provided API key, the HTTP client for
 * api.unsplash.com, import dedupe, and the Media-library "Unsplash imports"
 * filter (see https://github.com/plugpressco/saddle/issues/60).
 *
 * Custody note (non-negotiable #1): the site talks directly to Unsplash with
 * the owner's own Access Key. There is no proxy and no shared key — the only
 * data that leaves the site is the search query / photo id plus that key, and
 * nothing happens at all until the owner configures one.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owner key storage, Unsplash API client, and import bookkeeping.
 */
class Saddle_Unsplash {

	/**
	 * Option holding the owner's Unsplash Access Key. Autoload is off — the
	 * key is only needed while serving an Unsplash ability call.
	 */
	const OPTION = 'saddle_unsplash_access_key';

	/**
	 * Attachment meta: the Unsplash photo id an attachment was imported from.
	 * This is the dedupe primitive — one photo id maps to one attachment.
	 */
	const META_ID = '_saddle_unsplash_id';

	/**
	 * Attachment meta: the photographer's name, kept for provenance.
	 */
	const META_PHOTOGRAPHER = '_saddle_unsplash_photographer';

	/**
	 * Attachment meta: the photo's page on unsplash.com — the "View on
	 * Unsplash" link in the media library.
	 */
	const META_URL = '_saddle_unsplash_url';

	/**
	 * Attachment meta: the photographer's unsplash.com profile URL.
	 */
	const META_PHOTOGRAPHER_URL = '_saddle_unsplash_photographer_url';

	/**
	 * The media-tags taxonomy. Registered on attachments so imports arrive
	 * pre-categorized (Unsplash's own tags + whatever the agent searched for)
	 * and any media can be organized the same way.
	 */
	const TAXONOMY = 'saddle_media_tag';

	/**
	 * Unsplash REST API base.
	 */
	const API_BASE = 'https://api.unsplash.com';

	/*
	=========================================================================
	 * Key storage
	 * =========================================================================
	 */

	/**
	 * The configured Access Key, or '' when none is set.
	 *
	 * @return string
	 */
	public static function get_key() {
		return trim( (string) get_option( self::OPTION, '' ) );
	}

	/**
	 * Whether an Access Key is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get_key();
	}

	/**
	 * Last four characters of the key, for the settings UI. Never expose more.
	 *
	 * @return string
	 */
	public static function key_hint() {
		$key = self::get_key();
		return '' === $key ? '' : substr( $key, -4 );
	}

	/**
	 * Store, replace, or clear the Access Key.
	 *
	 * @param string $key New key; an empty string clears the option.
	 * @return true|WP_Error
	 */
	public static function set_key( $key ) {
		$key = trim( (string) $key );

		if ( '' === $key ) {
			delete_option( self::OPTION );
			return true;
		}

		if ( ! preg_match( '/^[A-Za-z0-9_\-]{16,128}$/', $key ) ) {
			return new WP_Error(
				'saddle_invalid_unsplash_key',
				__( 'That does not look like an Unsplash Access Key. Copy the "Access Key" (not the Secret Key) from your app at unsplash.com/developers.', 'saddle' ),
				array( 'status' => 400 )
			);
		}

		update_option( self::OPTION, $key, false );
		return true;
	}

	/*
	=========================================================================
	 * HTTP client
	 * =========================================================================
	 */

	/**
	 * Perform an authenticated GET against the Unsplash API and decode the
	 * JSON body. Every failure mode maps to an agent-actionable WP_Error.
	 *
	 * @param string $path  API path beginning with '/', e.g. '/search/photos'.
	 * @param array  $query Query arguments.
	 * @return array|WP_Error Decoded response body.
	 */
	public static function request( $path, $query = array() ) {
		$key = self::get_key();
		if ( '' === $key ) {
			return new WP_Error(
				'saddle_unsplash_not_configured',
				__( 'Unsplash is not set up on this site. The site owner must add an Unsplash Access Key on the Saddle Permissions screen — keys are free at unsplash.com/developers. Do not retry until it is configured.', 'saddle' ),
				array( 'status' => 400 )
			);
		}

		$url = self::API_BASE . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization'  => 'Client-ID ' . $key,
					'Accept-Version' => 'v1',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'saddle_unsplash_unreachable',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach the Unsplash API: %s. This is usually transient — one retry may help.', 'saddle' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $status ) {
			return new WP_Error(
				'saddle_unsplash_invalid_key',
				__( 'Unsplash rejected the configured Access Key. The site owner should re-check it on the Saddle Permissions screen. Do not retry until it is fixed.', 'saddle' ),
				array( 'status' => 502 )
			);
		}

		if ( 403 === $status ) {
			$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			return new WP_Error(
				'saddle_unsplash_rate_limited',
				sprintf(
					/* translators: %s: remaining request quota reported by Unsplash, or '0'. */
					__( 'Unsplash rate limit reached (free/demo keys allow 50 requests per hour; %s remaining). Wait before trying again — do NOT retry in a loop.', 'saddle' ),
					'' !== (string) $remaining ? (string) $remaining : '0'
				),
				array( 'status' => 429 )
			);
		}

		if ( 404 === $status ) {
			return new WP_Error(
				'saddle_unsplash_not_found',
				__( 'Unsplash has no photo or result at that address. Check the photo id (use unsplash-search to find valid ids).', 'saddle' ),
				array( 'status' => 404 )
			);
		}

		if ( 200 !== $status ) {
			$detail = is_array( $body ) && ! empty( $body['errors'][0] ) ? (string) $body['errors'][0] : '';
			return new WP_Error(
				'saddle_unsplash_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail from Unsplash (may be empty). */
					__( 'Unsplash returned an unexpected HTTP %1$d response. %2$s', 'saddle' ),
					$status,
					$detail
				),
				array( 'status' => 502 )
			);
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'saddle_unsplash_bad_response',
				__( 'Unexpected (non-JSON) response from Unsplash.', 'saddle' ),
				array( 'status' => 502 )
			);
		}

		return $body;
	}

	/**
	 * Fire the photo's download_location ping (Unsplash API guideline: every
	 * actual download must trigger this endpoint). Fire-and-forget — a failed
	 * ping never blocks the import itself.
	 *
	 * @param string $download_location The photo's links.download_location URL.
	 */
	public static function trigger_download( $download_location ) {
		$download_location = (string) $download_location;
		if ( '' === $download_location || 0 !== strpos( $download_location, self::API_BASE . '/' ) ) {
			return;
		}

		wp_remote_get(
			$download_location,
			array(
				'timeout' => 5,
				'headers' => array(
					'Authorization'  => 'Client-ID ' . self::get_key(),
					'Accept-Version' => 'v1',
				),
			)
		);
	}

	/*
	=========================================================================
	 * Dedupe + provenance
	 * =========================================================================
	 */

	/**
	 * The attachment previously imported from a photo id, or 0.
	 *
	 * @param string $photo_id Unsplash photo id.
	 * @return int Attachment ID or 0.
	 */
	public static function find_existing( $photo_id ) {
		$map = self::find_existing_many( array( (string) $photo_id ) );
		return isset( $map[ (string) $photo_id ] ) ? $map[ (string) $photo_id ] : 0;
	}

	/**
	 * Map photo ids to already-imported attachment ids, in one query. Used to
	 * annotate search results so agents reuse library photos instead of
	 * re-importing them.
	 *
	 * @param string[] $photo_ids Unsplash photo ids.
	 * @return array<string,int> photo id => attachment ID (only found ones).
	 */
	public static function find_existing_many( $photo_ids ) {
		$photo_ids = array_values( array_filter( array_map( 'strval', (array) $photo_ids ) ) );
		if ( empty( $photo_ids ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				// All matches, not count($photo_ids): force-imported duplicates
				// share one photo id and would otherwise crowd other ids out of
				// the window, mislabeling them as not-in-library.
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded lookup on a Saddle-owned key.
					array(
						'key'     => self::META_ID,
						'value'   => $photo_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		$map = array();
		foreach ( $query->posts as $post ) {
			$photo_id = (string) get_post_meta( $post->ID, self::META_ID, true );
			if ( '' !== $photo_id && ! isset( $map[ $photo_id ] ) ) {
				$map[ $photo_id ] = (int) $post->ID;
			}
		}

		return $map;
	}

	/**
	 * How many attachments were imported from Unsplash.
	 *
	 * @return int
	 */
	public static function import_count() {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cheap COUNT for an admin filter label.
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT pm.post_id ) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'attachment'",
				self::META_ID
			)
		);
	}

	/**
	 * The attribution caption Unsplash's guidelines require: photographer and
	 * Unsplash links carrying utm_source.
	 *
	 * @param array $photo Photo object from the API.
	 * @return string HTML caption.
	 */
	public static function attribution_caption( $photo ) {
		$name        = isset( $photo['user']['name'] ) ? sanitize_text_field( $photo['user']['name'] ) : __( 'an Unsplash photographer', 'saddle' );
		$profile_url = isset( $photo['user']['links']['html'] ) ? esc_url_raw( $photo['user']['links']['html'] ) : 'https://unsplash.com';

		return sprintf(
			/* translators: 1: photographer profile URL, 2: photographer name, 3: Unsplash home URL. */
			__( 'Photo by <a href="%1$s">%2$s</a> on <a href="%3$s">Unsplash</a>', 'saddle' ),
			esc_url( add_query_arg( self::utm_args(), $profile_url ) ),
			esc_html( $name ),
			esc_url( add_query_arg( self::utm_args(), 'https://unsplash.com/' ) )
		);
	}

	/**
	 * The photographer profile URL with attribution UTM parameters.
	 *
	 * @param array $photo Photo object from the API.
	 * @return string
	 */
	public static function photographer_url( $photo ) {
		$profile_url = isset( $photo['user']['links']['html'] ) ? esc_url_raw( $photo['user']['links']['html'] ) : 'https://unsplash.com';
		return add_query_arg( self::utm_args(), $profile_url );
	}

	/**
	 * UTM parameters Unsplash requires on attribution links.
	 *
	 * @return array
	 */
	private static function utm_args() {
		return array(
			'utm_source' => 'saddle',
			'utm_medium' => 'referral',
		);
	}

	/*
	=========================================================================
	 * Media tags taxonomy
	 * =========================================================================
	 */

	/**
	 * Register the Media Tags taxonomy on attachments. Hooked to `init` from
	 * Saddle::init() (always-on — organization shouldn't depend on the MCP
	 * transport being available).
	 *
	 * Flag choices that look odd but are load-bearing:
	 * - `public` true + `publicly_queryable` false: the media modal's compat
	 *   section only renders taxonomies with public && show_ui, while core
	 *   strips front-end ?taxonomy=&term= requests for non-queryable
	 *   taxonomies — so the field shows in wp-admin with zero front-end
	 *   archive surface (rewrite/query_var off too).
	 * - `_update_generic_term_count`: the default counter only counts
	 *   attachments whose PARENT is published, so tags on unattached imports
	 *   would report 0.
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			array(
				'labels'                => array(
					'name'          => __( 'Media Tags', 'saddle' ),
					'singular_name' => __( 'Media Tag', 'saddle' ),
					'search_items'  => __( 'Search media tags', 'saddle' ),
					'all_items'     => __( 'All media tags', 'saddle' ),
					'edit_item'     => __( 'Edit media tag', 'saddle' ),
					'update_item'   => __( 'Update media tag', 'saddle' ),
					'add_new_item'  => __( 'Add new media tag', 'saddle' ),
					'new_item_name' => __( 'New media tag', 'saddle' ),
					'not_found'     => __( 'No media tags found.', 'saddle' ),
				),
				'hierarchical'          => false,
				'public'                => true,
				'publicly_queryable'    => false,
				'query_var'             => false,
				'rewrite'               => false,
				'show_ui'               => true,
				'show_in_nav_menus'     => false,
				'show_admin_column'     => true,
				'show_in_rest'          => false,
				'update_count_callback' => '_update_generic_term_count',
			)
		);
	}

	/*
	=========================================================================
	 * Media-library visibility (wp-admin)
	 * =========================================================================
	 */

	/**
	 * Wire the Media list screen filter, the Source column, and the
	 * attachment-details provenance section. Called from Saddle::init().
	 */
	public static function register_admin_hooks() {
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_media_filter' ) );
		add_action( 'parse_query', array( __CLASS__, 'apply_media_filter' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_provenance_field' ), 10, 2 );
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_source_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_source_column' ), 10, 2 );
	}

	/**
	 * "All sources / Unsplash imports (N)" dropdown on the Media list screen.
	 *
	 * @param string $post_type Current list-table post type.
	 */
	public static function render_media_filter( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$selected = self::requested_media_source();
		?>
		<select name="saddle_media_source" id="saddle-media-source">
			<option value=""><?php esc_html_e( 'All sources', 'saddle' ); ?></option>
			<option value="unsplash" <?php selected( $selected, 'unsplash' ); ?>>
				<?php
				printf(
					/* translators: %d: number of Unsplash-imported attachments. */
					esc_html__( 'Unsplash imports (%d)', 'saddle' ),
					(int) self::import_count()
				);
				?>
			</option>
		</select>
		<?php
	}

	/**
	 * Constrain the Media list query to Unsplash imports when filtered.
	 *
	 * @param WP_Query $query The list-table query.
	 */
	public static function apply_media_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'unsplash' !== self::requested_media_source() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'     => self::META_ID,
			'compare' => 'EXISTS',
		);
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * The sanitized saddle_media_source request value ('' when absent).
	 *
	 * @return string
	 */
	private static function requested_media_source() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter, matches core's own media filters.
		return isset( $_GET['saddle_media_source'] ) ? sanitize_key( wp_unslash( $_GET['saddle_media_source'] ) ) : '';
	}

	/**
	 * The attachment's page on unsplash.com, with attribution UTM params.
	 * Falls back to the id-based URL for imports that predate META_URL.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string '' when the attachment is not an Unsplash import.
	 */
	public static function photo_page_url( $attachment_id ) {
		$photo_id = (string) get_post_meta( $attachment_id, self::META_ID, true );
		if ( '' === $photo_id ) {
			return '';
		}

		$url = (string) get_post_meta( $attachment_id, self::META_URL, true );
		if ( '' === $url ) {
			$url = 'https://unsplash.com/photos/' . rawurlencode( $photo_id );
		}

		return add_query_arg( self::utm_args(), $url );
	}

	/**
	 * The read-only "Unsplash" section in the attachment details — rendered on
	 * both the edit-attachment screen and the media modal sidebar, so anyone
	 * browsing the library can see where the image came from.
	 *
	 * Core echoes 'html' fields verbatim, so every value is escaped here.
	 *
	 * @param array   $form_fields Attachment form fields.
	 * @param WP_Post $post        The attachment.
	 * @return array
	 */
	public static function add_provenance_field( $form_fields, $post ) {
		$photo_id = (string) get_post_meta( $post->ID, self::META_ID, true );
		if ( '' === $photo_id ) {
			return $form_fields;
		}

		$photographer     = (string) get_post_meta( $post->ID, self::META_PHOTOGRAPHER, true );
		$photographer_url = (string) get_post_meta( $post->ID, self::META_PHOTOGRAPHER_URL, true );

		$by = '';
		if ( '' !== $photographer ) {
			$by = '' !== $photographer_url
				? sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( add_query_arg( self::utm_args(), $photographer_url ) ),
					esc_html( $photographer )
				)
				: esc_html( $photographer );
			/* translators: %s: photographer name (already escaped/linked). */
			$by = sprintf( __( 'Photo by %s', 'saddle' ), $by ) . '<br />';
		}

		$html = $by . sprintf(
			'<code>%s</code> · <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_html( $photo_id ),
			esc_url( self::photo_page_url( $post->ID ) ),
			esc_html__( 'View on Unsplash', 'saddle' )
		);

		$form_fields['saddle_unsplash'] = array(
			'label'         => __( 'Unsplash', 'saddle' ),
			'input'         => 'html',
			'html'          => $html,
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $form_fields;
	}

	/**
	 * Add the Source column to the Media list view, just before Date.
	 *
	 * @param array $columns Column key => label.
	 * @return array
	 */
	public static function add_source_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['saddle_source'] = __( 'Source', 'saddle' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['saddle_source'] ) ) {
			$out['saddle_source'] = __( 'Source', 'saddle' );
		}
		return $out;
	}

	/**
	 * Render the Source column cell: "Unsplash · photographer" linked to the
	 * photo's page, or core's em-dash for media from anywhere else.
	 *
	 * @param string $column_name Current column.
	 * @param int    $post_id     Attachment ID.
	 */
	public static function render_source_column( $column_name, $post_id ) {
		if ( 'saddle_source' !== $column_name ) {
			return;
		}

		$url = self::photo_page_url( $post_id );
		if ( '' === $url ) {
			echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'Uploaded directly', 'saddle' ) . '</span>';
			return;
		}

		$photographer = (string) get_post_meta( $post_id, self::META_PHOTOGRAPHER, true );
		$text         = '' !== $photographer
			/* translators: %s: photographer name. */
			? sprintf( __( 'Unsplash · %s', 'saddle' ), $photographer )
			: __( 'Unsplash', 'saddle' );

		printf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $text )
		);
	}
}
