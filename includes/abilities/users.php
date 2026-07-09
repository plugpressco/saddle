<?php
/**
 * User abilities — read-only directory access (Phase 2, item 5).
 *
 * Reads only. There is deliberately NO create / update / delete on users here:
 * that is a meaningfully higher-risk surface (privilege escalation) and is
 * intentionally out of scope for this phase — see
 * https://github.com/plugpressco/saddle/issues/13 and the Finalized Plan
 * (#12, Phase 2 §5). If a user-mutation surface is ever added it gets its own
 * scoped decision, not a default inclusion.
 *
 * Both abilities require the `list_users` capability — which, by default, only
 * administrators hold — so surfacing the user directory is gated by capability
 * on top of the read tier. Personally-identifying fields (email, real name,
 * login) are returned ONLY to a caller who can `edit_users`; everyone else sees
 * the public-facing profile only.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the read-only user abilities. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_user_abilities() {
	wp_register_ability(
		'saddle/list-users',
		array(
			'label'               => __( 'List users', 'saddle' ),
			'description'         => __( 'Lists site users as summaries (id, name, slug, roles, registered date, avatar, published-post count). Read-only. Supports filtering by role and a search term, plus ordering and pagination via per_page/page. Email and real name are included only for callers who can edit users. There is no create, update, or delete — this surface is read-only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword filter (matches name, login, email, URL).', 'saddle' ),
					),
					'role'     => array(
						'type'        => 'string',
						'description' => __( 'Filter by role slug, e.g. "administrator", "editor", "author", "subscriber".', 'saddle' ),
					),
					'orderby'  => array(
						'type'    => 'string',
						'enum'    => array( 'registered', 'display_name', 'ID', 'post_count' ),
						'default' => 'registered',
					),
					'order'    => array(
						'type'    => 'string',
						'enum'    => array( 'ASC', 'DESC' ),
						'default' => 'DESC',
					),
					'per_page' => saddle_per_page_schema(),
					'page'     => saddle_page_schema(),
				),
			),
			'execute_callback'    => array( 'Saddle_User_Abilities', 'list_users' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'list_users', 'list-users' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-user',
		array(
			'label'               => __( 'Get user', 'saddle' ),
			'description'         => __( 'Returns a single user by id: name, slug, roles, registered date, avatar, bio, website, and published-post count. Read-only. Email, real name, and login are included only for callers who can edit users.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => saddle_id_schema( __( 'The user ID.', 'saddle' ) ),
			'execute_callback'    => array( 'Saddle_User_Abilities', 'get_user' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'list_users', 'get-user' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Execute callbacks for the read-only user abilities.
 *
 * Every method assumes the permission_callback has already enforced an
 * authenticated caller, the `list_users` capability, the read tier, the pause
 * switch, and the per-ability toggle. These add only the shaping the generic
 * gate can't: pagination bounds, the PII split, and safe field selection.
 */
class Saddle_User_Abilities {

	/**
	 * Orderby values accepted by list-users, all natively supported by
	 * WP_User_Query. Anything else falls back to the default.
	 */
	const ORDERBY = array( 'registered', 'display_name', 'ID', 'post_count' );

	/**
	 * saddle/list-users.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public static function list_users( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$orderby = isset( $input['orderby'] ) && in_array( $input['orderby'], self::ORDERBY, true ) ? $input['orderby'] : 'registered';
		$order   = isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC';

		$args = array(
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => $orderby,
			'order'       => $order,
			'count_total' => true,
		);

		if ( ! empty( $input['role'] ) && is_string( $input['role'] ) ) {
			$args['role'] = $input['role'];
		}
		if ( ! empty( $input['search'] ) && is_string( $input['search'] ) ) {
			// Wildcards so it matches substrings, like the core users list table.
			$args['search']         = '*' . trim( $input['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email', 'user_url' );
		}

		$query  = new WP_User_Query( $args );
		$reveal = current_user_can( 'edit_users' );

		$items = array();
		foreach ( $query->get_results() as $user ) {
			$items[] = self::format_user( $user, $reveal, false );
		}

		$total = (int) $query->get_total();

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
		);
	}

	/**
	 * saddle/get-user.
	 *
	 * @param mixed $input Ability input { id }.
	 * @return array|WP_Error
	 */
	public static function get_user( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id < 1 ) {
			return new WP_Error( 'saddle_missing_id', __( 'A user "id" is required.', 'saddle' ), array( 'status' => 400 ) );
		}

		$user = get_userdata( $id );
		if ( ! $user ) {
			return new WP_Error( 'saddle_not_found', __( 'No user with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}

		return self::format_user( $user, current_user_can( 'edit_users' ), true );
	}

	/**
	 * Shape a WP_User into the safe array returned to the agent.
	 *
	 * The base fields are the public-facing profile — the same surface a theme
	 * already exposes on an author archive. PII (email, real name, login) is
	 * added only when $reveal_pii is true, i.e. the caller can edit users.
	 *
	 * @param WP_User $user       The user.
	 * @param bool    $reveal_pii Whether to include email / real name / login.
	 * @param bool    $detailed   Whether to include bio + website (single-user view).
	 * @return array
	 */
	private static function format_user( WP_User $user, $reveal_pii, $detailed ) {
		$data = array(
			'id'         => (int) $user->ID,
			'name'       => $user->display_name,
			'slug'       => $user->user_nicename,
			'roles'      => array_values( (array) $user->roles ),
			'registered' => $user->user_registered,
			'avatar_url' => get_avatar_url( $user->ID ),
			'post_count' => (int) count_user_posts( $user->ID, 'post', true ),
		);

		if ( $detailed ) {
			$data['url'] = $user->user_url;
			$data['bio'] = get_user_meta( $user->ID, 'description', true );
		}

		if ( $reveal_pii ) {
			$data['email']      = $user->user_email;
			$data['first_name'] = $user->first_name;
			$data['last_name']  = $user->last_name;
			$data['login']      = $user->user_login;
		}

		return $data;
	}
}
