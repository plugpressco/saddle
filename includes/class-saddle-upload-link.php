<?php
/**
 * Upload links: a single-use address an agent sends one local file to.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Why this exists (#412): `saddle/upload-media` takes a public URL or the file
 * as base64 text inside the tool call. An agent has to write base64 out in
 * full, so a file of normal size (a screenshot, a photo) cannot be sent, and a
 * file on the owner's computer has no public URL.
 *
 * `saddle/create-upload-link` answers with an address on this site that takes
 * one file, once, within ten minutes. The agent sends the raw file there with
 * a single command, and the bytes go to the same place `upload-media` sends
 * them: core's own attachment controller, as the WordPress user the agent is
 * connected as. Saddle writes nothing to disk.
 *
 * The token in the address is the permission, in the way a preview link's is
 * (Saddle_Preview): nobody signs in on this route. What keeps that narrow:
 *
 * - A link is made only by a call that passed the tool's own gate (write
 *   tier, `upload_files`, Allow / Ask first / Off), and it is bound to that
 *   user, that connection, one filename, one type and one exact size.
 * - Only a hash of the token is stored. The token works once: it is claimed
 *   before anything else happens, so a second request, or a race, is refused.
 * - When the file arrives the gate is checked again for the stored
 *   connection: a link made before the owner paused Saddle, switched the tool
 *   off, lowered the agent's access or disconnected it uploads nothing.
 * - The link gives no other access. It reads nothing and reaches no other
 *   route, and an unknown, used or expired token gets one and the same answer.
 */
class Saddle_Upload_Link {

	/**
	 * The tool's id without the `saddle/` prefix.
	 */
	const ABILITY = 'create-upload-link';

	/**
	 * How long a link works, in seconds. Long enough to run one command,
	 * short enough that a leaked address is stale before it travels.
	 */
	const TTL = 600;

	/**
	 * Links one connection may have open at once.
	 */
	const MAX_OPEN = 20;

	/**
	 * Characters in a token.
	 */
	const TOKEN_LENGTH = 64;

	/**
	 * Transient prefix for one link, followed by the token's SHA-256.
	 */
	const TRANSIENT = 'saddle_upload_';

	/**
	 * Transient prefix for a connection's open links.
	 */
	const OPEN_TRANSIENT = 'saddle_uploads_open_';

	/**
	 * Hook the route. Called from the bootstrap.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * Register the address a link points at.
	 */
	public static function register_route() {
		register_rest_route(
			Saddle_MCP::REST_NAMESPACE,
			'/upload/(?P<token>[A-Za-z0-9]{' . self::TOKEN_LENGTH . '})',
			array(
				'methods'             => array( 'PUT', 'POST' ),
				'callback'            => array( __CLASS__, 'receive' ),
				// No WordPress sign-in here: the single-use token in the
				// address is the permission, and this callback checks it. It
				// discloses nothing, since every token that is not open gets
				// the same refusal.
				'permission_callback' => array( __CLASS__, 'token_is_open' ),
			)
		);
	}

	/**
	 * `saddle/create-upload-link`: make a link for one file.
	 *
	 * @param mixed $input { filename, size, title, alt, caption, description, post_id }.
	 * @return array|WP_Error
	 */
	public static function create( $input = null ) {
		$input = is_array( $input ) ? $input : (array) $input;

		$filename = isset( $input['filename'] ) && is_string( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : '';
		if ( '' === $filename ) {
			return new WP_Error( 'saddle_missing_filename', __( 'A "filename" with an extension is required, e.g. "hero.png".', 'saddle' ), array( 'status' => 400 ) );
		}

		$type = wp_check_filetype( $filename );
		if ( empty( $type['type'] ) ) {
			return new WP_Error( 'saddle_file_type_not_allowed', __( 'That file type is not allowed on this site. Use an extension WordPress accepts for uploads, such as .jpg, .png, .webp or .pdf.', 'saddle' ), array( 'status' => 400 ) );
		}

		$size = isset( $input['size'] ) ? (int) $input['size'] : 0;
		if ( $size < 1 ) {
			return new WP_Error( 'saddle_missing_size', __( 'Pass "size": the file\'s exact size in bytes.', 'saddle' ), array( 'status' => 400 ) );
		}

		$max_bytes = Saddle_Abilities::max_upload_bytes();
		if ( $max_bytes > 0 && $size > $max_bytes ) {
			return new WP_Error(
				'saddle_file_too_large',
				sprintf(
					/* translators: %s: maximum size, e.g. "64 MB". */
					__( 'The file exceeds the maximum allowed size (%s).', 'saddle' ),
					size_format( $max_bytes )
				),
				array( 'status' => 413 )
			);
		}

		// If attaching to a post, require permission to edit that post.
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You do not have permission to attach media to that item.', 'saddle' ), array( 'status' => 403 ) );
		}

		$user       = get_current_user_id();
		$connection = Saddle_Access::current_connection();
		$open       = self::open_links( $connection, $user );
		if ( count( $open ) >= self::MAX_OPEN ) {
			return new WP_Error(
				'saddle_too_many_upload_links',
				sprintf(
					/* translators: %d: number of links. */
					__( 'You already have %d upload links open. Use them, or wait: each one expires after 10 minutes.', 'saddle' ),
					self::MAX_OPEN
				),
				array( 'status' => 429 )
			);
		}

		$fields = array();
		foreach ( array( 'title', 'alt', 'caption', 'description' ) as $field ) {
			if ( isset( $input[ $field ] ) && is_string( $input[ $field ] ) ) {
				$fields[ $field ] = $input[ $field ];
			}
		}

		$token   = wp_generate_password( self::TOKEN_LENGTH, false, false );
		$hash    = hash( 'sha256', $token );
		$expires = time() + self::TTL;

		set_transient(
			self::TRANSIENT . $hash,
			array(
				'user'       => $user,
				'connection' => $connection,
				'filename'   => $filename,
				'size'       => $size,
				'post_id'    => $post_id,
				'fields'     => $fields,
				'expires'    => $expires,
			),
			self::TTL
		);

		$open[ $hash ] = $expires;
		set_transient( self::open_key( $connection, $user ), $open, self::TTL );

		$url = rest_url( Saddle_MCP::REST_NAMESPACE . '/upload/' . $token );

		return array(
			'upload_url' => $url,
			'method'     => 'PUT',
			'filename'   => $filename,
			'size'       => $size,
			'expires_in' => self::TTL,
			'command'    => sprintf( 'curl -sS -T %s %s', escapeshellarg( $filename ), escapeshellarg( $url ) ),
			'note'       => __( 'Run the command from the folder that holds the file, or give curl the file\'s full path. Send the raw file as the body of one PUT request, with no sign-in header. The answer is the new media item. The address works once.', 'saddle' ),
		);
	}

	/**
	 * The route's permission check: is this token an open link?
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public static function token_is_open( $request ) {
		return null === self::find( (string) $request['token'] ) ? self::not_valid() : true;
	}

	/**
	 * Take the file a link was made for.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive( $request ) {
		$token = (string) $request['token'];
		$link  = self::find( $token );
		// The upload path lives with the abilities, which need the Abilities API.
		if ( null === $link || ! class_exists( 'Saddle_Abilities' ) ) {
			return self::not_valid();
		}

		// Claim it. delete_transient() is true for exactly one caller, so two
		// requests racing with the same token cannot both upload.
		$hash = hash( 'sha256', $token );
		if ( ! delete_transient( self::TRANSIENT . $hash ) ) {
			return self::not_valid();
		}
		self::forget_open( (string) $link['connection'], (int) $link['user'], $hash );

		$bytes = (string) $request->get_body();
		if ( strlen( $bytes ) !== (int) $link['size'] ) {
			return new WP_Error(
				'saddle_upload_size_mismatch',
				sprintf(
					/* translators: 1: bytes received, 2: bytes the link was made for. */
					__( 'Received %1$d bytes, but this link was made for a file of %2$d bytes. Nothing was stored and the link is used up. Check the file and its size, then ask for a new link.', 'saddle' ),
					strlen( $bytes ),
					(int) $link['size']
				),
				array( 'status' => 400 )
			);
		}

		// Act as the user the link was made for, for this upload only.
		$previous = get_current_user_id();
		wp_set_current_user( (int) $link['user'] );
		try {
			$allowed = self::still_allowed( $link );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$attachment_id = Saddle_Abilities::upload_bytes_to_library( $bytes, (string) $link['filename'], (int) $link['post_id'] );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$media = Saddle_Abilities::finish_upload( $attachment_id, (array) $link['fields'], (string) $link['connection'] );
		} finally {
			wp_set_current_user( $previous );
		}

		return is_wp_error( $media ) ? $media : rest_ensure_response( $media );
	}

	/**
	 * Whether the call that made this link would still be allowed now.
	 *
	 * The same questions the tool's own gate asks
	 * (Saddle_Capabilities::permission()), put to the stored connection rather
	 * than to this request, which carries no credential. Runs as the link's
	 * user.
	 *
	 * @param array $link The stored link.
	 * @return true|WP_Error
	 */
	private static function still_allowed( array $link ) {
		$user       = (int) $link['user'];
		$connection = (string) $link['connection'];

		$ok = $user > 0
			&& get_userdata( $user )
			&& current_user_can( 'upload_files' )
			&& ! Saddle_Capabilities::is_paused()
			&& Saddle_Capabilities::is_ability_enabled( self::ABILITY )
			&& ! ( Saddle_Capabilities::is_domain_enforced() && ! Saddle_Capabilities::domain_matches_recorded() )
			&& self::connection_exists( $connection, $user )
			&& Saddle_Capabilities::rank( Saddle_Access::role_for( $connection ) ) >= Saddle_Capabilities::rank( 'write' );

		if ( $ok && (int) $link['post_id'] > 0 ) {
			$ok = current_user_can( 'edit_post', (int) $link['post_id'] );
		}

		if ( $ok ) {
			return true;
		}

		Saddle_Log::record(
			array(
				'action'     => self::ABILITY,
				'summary'    => __( 'Refused an upload: the link was made before Saddle was paused, the tool was switched off, or the agent\'s access changed', 'saddle' ),
				'type'       => 'denied',
				'connection' => $connection,
			)
		);

		return new WP_Error(
			'saddle_upload_link_revoked',
			__( 'This upload link no longer works: since it was made, Saddle was paused, the tool was switched off, or the agent\'s access changed. Nothing was stored.', 'saddle' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether a connection is still there.
	 *
	 * @param string $connection `key:<uuid>`, `oauth:<grant>`, or '' for the owner's own session.
	 * @param int    $user       The link's user.
	 * @return bool
	 */
	private static function connection_exists( $connection, $user ) {
		if ( '' === $connection ) {
			return true;
		}

		list( $kind, $ref ) = array_pad( explode( ':', $connection, 2 ), 2, '' );

		if ( 'key' === $kind && '' !== $ref && class_exists( 'WP_Application_Passwords' ) ) {
			return null !== WP_Application_Passwords::get_user_application_password( $user, $ref );
		}

		if ( 'oauth' === $kind && '' !== $ref && class_exists( 'Saddle_OAuth_Store' ) ) {
			return (bool) Saddle_OAuth_Store::get_grant( $ref );
		}

		return false;
	}

	/**
	 * Look up an open link by its token.
	 *
	 * @param string $token Token from the address.
	 * @return array|null The stored link, or null when it is unknown, used or expired.
	 */
	private static function find( $token ) {
		if ( self::TOKEN_LENGTH !== strlen( $token ) ) {
			return null;
		}

		$link = get_transient( self::TRANSIENT . hash( 'sha256', $token ) );
		if ( ! is_array( $link ) || empty( $link['expires'] ) || (int) $link['expires'] < time() ) {
			return null;
		}

		return $link;
	}

	/**
	 * The one answer for every token that is not an open link.
	 *
	 * @return WP_Error
	 */
	private static function not_valid() {
		return new WP_Error(
			'saddle_upload_link_invalid',
			__( 'This upload link is not valid. It may have been used already, or it expired: a link works once, within 10 minutes. Ask for a new one with create-upload-link.', 'saddle' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * A connection's open links: token hash => expiry, expired ones dropped.
	 *
	 * @param string $connection Connection id, or ''.
	 * @param int    $user       User id.
	 * @return array<string,int>
	 */
	private static function open_links( $connection, $user ) {
		$open = get_transient( self::open_key( $connection, $user ) );
		$now  = time();

		return array_filter(
			is_array( $open ) ? $open : array(),
			static function ( $expires ) use ( $now ) {
				return (int) $expires >= $now;
			}
		);
	}

	/**
	 * Drop one link from its connection's open list.
	 *
	 * @param string $connection Connection id, or ''.
	 * @param int    $user       User id.
	 * @param string $hash       The token's SHA-256.
	 */
	private static function forget_open( $connection, $user, $hash ) {
		$open = self::open_links( $connection, $user );
		unset( $open[ $hash ] );

		if ( $open ) {
			set_transient( self::open_key( $connection, $user ), $open, self::TTL );
		} else {
			delete_transient( self::open_key( $connection, $user ) );
		}
	}

	/**
	 * Transient name for a connection's open list.
	 *
	 * @param string $connection Connection id, or ''.
	 * @param int    $user       User id.
	 * @return string
	 */
	private static function open_key( $connection, $user ) {
		return self::OPEN_TRANSIENT . md5( $connection . '|' . (int) $user );
	}
}
