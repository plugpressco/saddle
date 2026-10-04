<?php
/**
 * Rehearsal mode: write tools answer with what they would do, and save nothing.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rehearsal mode (#180). With the switch on, every saddle/* tool that is not
 * read-only answers with the call it received and the current state of its
 * target, and never runs its own code. Read tools run as usual.
 *
 * Why the tool never runs, rather than running with its writes blocked: a
 * sandbox has to intercept every write path a tool could take, and a missed one
 * (a direct query, a builder's own storage, a persistent object cache after a
 * rolled-back transaction) would save for real while the owner believes
 * nothing is. Not running the code is the only version that is safe on a live
 * site by construction.
 *
 * Implemented once, at registration: wp_register_ability_args (WordPress 6.9)
 * wraps each non-read-only saddle/* execute callback, which covers every path
 * to a tool — MCP, the REST abilities endpoint, direct calls — and Saddle Pro's
 * and the integrations' saddle/* tools too. The wrapper runs after core's
 * input validation and permission check, so rehearsal never lets a call
 * through that the tier or a tool switch would refuse.
 */
class Saddle_Rehearsal {

	/**
	 * Hook the registration filter. Must run before wp_abilities_api_init.
	 */
	public static function init() {
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap' ), 10, 2 );
	}

	/**
	 * Wrap a non-read-only saddle/* tool's execute callback.
	 *
	 * @param array  $args Ability arguments.
	 * @param string $name Ability name.
	 * @return array
	 */
	public static function wrap( $args, $name ) {
		if ( 0 !== strpos( (string) $name, 'saddle/' ) || ! is_array( $args ) || empty( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}
		if ( ! empty( $args['meta']['annotations']['readonly'] ) ) {
			return $args;
		}

		$inner                    = $args['execute_callback'];
		$args['execute_callback'] = static function () use ( $inner, $name ) {
			$call = func_get_args();
			if ( Saddle_Capabilities::is_rehearsal() ) {
				return self::rehearse( $name, $call ? $call[0] : null );
			}
			return call_user_func_array( $inner, $call );
		};
		return $args;
	}

	/**
	 * The rehearsal answer, recorded in the activity log as rehearsed.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Validated input.
	 * @return array
	 */
	public static function rehearse( $name, $input ) {
		$input = is_array( $input ) ? $input : array();
		unset( $input['confirm_token'] );
		$short = substr( $name, strlen( 'saddle/' ) );

		Saddle_Log::record(
			array(
				'action'  => $short,
				'target'  => (string) self::target_id( $input ),
				'summary' => self::summary( $short, $input ),
				'type'    => 'rehearsed',
			)
		);

		$answer  = array(
			'rehearsal' => true,
			'tool'      => $name,
			'would'     => $input,
			'note'      => __( 'Rehearsal mode is on, so nothing was saved and nothing changed. "would" is the call as received. Tell the user what this would have changed. Don’t retry: the site owner turns rehearsal off in Saddle → Settings when they want changes to land.', 'saddle' ),
		);
		$current = self::current( $input );
		if ( $current ) {
			$answer['current'] = $current;
		}
		return $answer;
	}

	/**
	 * The post or page a call names, if any.
	 *
	 * @param array $input Call input.
	 * @return int
	 */
	private static function target_id( array $input ) {
		foreach ( array( 'id', 'post_id', 'page_id' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				return (int) $input[ $key ];
			}
		}
		return 0;
	}

	/**
	 * What the call's target looks like now, so "would" can be read against
	 * it. Limited to what this account may already read.
	 *
	 * @param array $input Call input.
	 * @return array
	 */
	private static function current( array $input ) {
		$id = self::target_id( $input );
		if ( $id && get_post( $id ) && current_user_can( 'read_post', $id ) ) {
			$post = get_post( $id );
			return array(
				'id'     => $id,
				'type'   => $post->post_type,
				'title'  => $post->post_title,
				'status' => $post->post_status,
			);
		}
		if ( isset( $input['name'] ) && is_string( $input['name'] ) && class_exists( 'Saddle_Site_Abilities' )
			&& current_user_can( 'manage_options' ) && in_array( $input['name'], Saddle_Site_Abilities::allowlist(), true ) ) {
			return array(
				'name'  => $input['name'],
				'value' => get_option( $input['name'] ),
			);
		}
		return array();
	}

	/**
	 * The activity-log line: which tool, on what, touching which fields.
	 *
	 * @param string $short Ability name without the namespace.
	 * @param array  $input Call input.
	 * @return string
	 */
	private static function summary( $short, array $input ) {
		$id     = self::target_id( $input );
		$fields = implode( ', ', array_diff( array_keys( $input ), array( 'id', 'post_id', 'page_id' ) ) );
		if ( $id && '' !== $fields ) {
			/* translators: 1: tool name, 2: post id, 3: comma-separated field names. */
			return sprintf( __( 'Rehearsed %1$s on #%2$d (%3$s). Nothing was saved.', 'saddle' ), $short, $id, $fields );
		}
		if ( $id ) {
			/* translators: 1: tool name, 2: post id. */
			return sprintf( __( 'Rehearsed %1$s on #%2$d. Nothing was saved.', 'saddle' ), $short, $id );
		}
		if ( '' !== $fields ) {
			/* translators: 1: tool name, 2: comma-separated field names. */
			return sprintf( __( 'Rehearsed %1$s (%2$s). Nothing was saved.', 'saddle' ), $short, $fields );
		}
		/* translators: %s: tool name. */
		return sprintf( __( 'Rehearsed %s. Nothing was saved.', 'saddle' ), $short );
	}
}
