<?php
/**
 * Where the owner is in first run, and what they have already seen.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The onboarding state: one site option for first run and each module's
 * intro and setup, one user meta for the tour.
 *
 * It replaces the old `saddle_onboarded` flag, which could only say "done".
 * A site that already shows signs of use (a key Saddle issued, an OAuth grant,
 * a logged change, or the old flag) is migrated to `done`, so an upgraded site
 * never meets first run. Everything else starts at `new`.
 *
 * None of these events touches the access tier. The tier changes only through
 * `/preferences`, when the owner clicks "Let it draft and edit content".
 *
 * Milestones (an app connected, a first prompt tried) are not stored here.
 * They are derived from the connection registry, so there is one source.
 */
class Saddle_Onboarding {

	/**
	 * Site option holding the state. Autoload off: Home and the Plugins screen
	 * read it, nothing on the front end does.
	 */
	const OPTION = 'saddle_onboarding';

	/**
	 * The option this state replaces. Deleted by the migration.
	 */
	const LEGACY_OPTION = 'saddle_onboarded';

	/**
	 * Per-user meta: `{ tour_done: bool }`.
	 */
	const USER_META = 'saddle_ui';

	/**
	 * First-run states. `done` and `skipped` both count as finished.
	 */
	const STATES = array( 'new', 'active', 'done', 'skipped' );

	/**
	 * First-run steps after the site read, in order.
	 */
	const STEPS = array( 'app', 'connect', 'try', 'choose' );

	/**
	 * How many modules' records are kept.
	 */
	const MAX_MODULES = 50;

	/**
	 * Whether first run is over: finished or skipped.
	 *
	 * @return bool
	 */
	public static function is_finished() {
		$state = self::state();

		return in_array( $state['first_run']['state'], array( 'done', 'skipped' ), true );
	}

	/**
	 * The site state, migrating first when nothing is stored yet.
	 *
	 * @return array `first_run` and `modules`, always complete.
	 */
	public static function state() {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			$stored = self::migrate();
		}

		$first = isset( $stored['first_run'] ) && is_array( $stored['first_run'] ) ? $stored['first_run'] : array();
		$mods  = isset( $stored['modules'] ) && is_array( $stored['modules'] ) ? $stored['modules'] : array();

		$clean_modules = array();
		foreach ( $mods as $key => $record ) {
			if ( is_array( $record ) ) {
				$clean_modules[ (string) $key ] = array(
					'intro_seen'      => isset( $record['intro_seen'] ) ? (string) $record['intro_seen'] : '',
					'setup_hidden_at' => isset( $record['setup_hidden_at'] ) ? (int) $record['setup_hidden_at'] : 0,
				);
			}
		}

		return array(
			'first_run' => array(
				'state'       => isset( $first['state'] ) && in_array( $first['state'], self::STATES, true ) ? $first['state'] : 'new',
				'step'        => isset( $first['step'] ) && in_array( $first['step'], self::STEPS, true ) ? $first['step'] : '',
				'app'         => isset( $first['app'] ) ? sanitize_key( $first['app'] ) : '',
				'tier_choice' => isset( $first['tier_choice'] ) && in_array( $first['tier_choice'], array( 'read', 'write' ), true ) ? $first['tier_choice'] : '',
				'finished_at' => isset( $first['finished_at'] ) ? (int) $first['finished_at'] : 0,
			),
			'modules'   => $clean_modules,
		);
	}

	/**
	 * Create the state for a site that has none, and retire the old flag.
	 *
	 * Idempotent: a site that already has the option keeps it untouched.
	 *
	 * @return array The stored option.
	 */
	public static function migrate() {
		$existing = get_option( self::OPTION, null );
		if ( is_array( $existing ) ) {
			delete_option( self::LEGACY_OPTION );

			return $existing;
		}

		$used  = self::shows_use();
		$state = array(
			'first_run' => array(
				'state'       => $used ? 'done' : 'new',
				'step'        => '',
				'app'         => '',
				'tier_choice' => '',
				'finished_at' => $used ? time() : 0,
			),
			'modules'   => array(),
		);

		update_option( self::OPTION, $state, false );
		delete_option( self::LEGACY_OPTION );

		return $state;
	}

	/**
	 * Whether this site has been used: the old flag, a key Saddle issued, an
	 * OAuth grant, or a logged change.
	 *
	 * @return bool
	 */
	private static function shows_use() {
		if ( get_option( self::LEGACY_OPTION ) ) {
			return true;
		}

		if ( self::has_issued_key() ) {
			return true;
		}

		foreach ( array(
			array( Saddle_OAuth_Store::CPT, 'grant' ),
			array( Saddle_Log::CPT, '' ),
		) as $source ) {
			$args = array(
				'post_type'              => $source[0],
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);
			if ( '' !== $source[1] ) {
				$args['title'] = $source[1];
			}
			if ( ( new WP_Query( $args ) )->posts ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a key Saddle issued still exists on any account.
	 *
	 * @return bool
	 */
	private static function has_issued_key() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return false;
		}

		$users = get_users(
			array(
				'meta_key'     => Saddle_Connection::ISSUED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One existence check, once per site.
				'meta_compare' => 'EXISTS',
				'number'       => 50,
				'fields'       => 'ID',
			)
		);

		foreach ( $users as $user_id ) {
			foreach ( (array) get_user_meta( (int) $user_id, Saddle_Connection::ISSUED_META, true ) as $uuid ) {
				if ( WP_Application_Passwords::get_user_application_password( (int) $user_id, (string) $uuid ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Old-flag compatibility: `/preferences` with `onboarded`.
	 *
	 * True finishes first run; false opens it again.
	 *
	 * @param bool $finished The value the caller sent.
	 */
	public static function set_finished_flag( $finished ) {
		$state = self::state();

		if ( $finished ) {
			if ( ! in_array( $state['first_run']['state'], array( 'done', 'skipped' ), true ) ) {
				$state['first_run']['state']       = 'done';
				$state['first_run']['finished_at'] = time();
			}
		} else {
			$state['first_run'] = array(
				'state'       => 'new',
				'step'        => '',
				'app'         => '',
				'tier_choice' => '',
				'finished_at' => 0,
			);
		}

		update_option( self::OPTION, $state, false );
	}

	/**
	 * The current user's own state.
	 *
	 * @return array `tour_done`.
	 */
	public static function user_state() {
		$meta = get_user_meta( get_current_user_id(), self::USER_META, true );

		return array( 'tour_done' => is_array( $meta ) && ! empty( $meta['tour_done'] ) );
	}

	/**
	 * What `GET /onboarding` returns.
	 *
	 * @return array
	 */
	public static function body() {
		return self::state() + array( 'user' => self::user_state() );
	}

	/**
	 * Apply one event.
	 *
	 * @param array $event `event` plus its own fields.
	 * @return true|WP_Error
	 */
	public static function apply( array $event ) {
		$name  = isset( $event['event'] ) ? (string) $event['event'] : '';
		$state = self::state();

		switch ( $name ) {
			case 'first_run.step':
				$step = isset( $event['step'] ) ? (string) $event['step'] : '';
				if ( ! in_array( $step, self::STEPS, true ) ) {
					return self::invalid( __( 'That is not a first-run step.', 'saddle' ) );
				}
				// A finished run being repeated keeps its state: running setup
				// again never makes the site look unfinished.
				if ( 'new' === $state['first_run']['state'] ) {
					$state['first_run']['state'] = 'active';
				}
				$state['first_run']['step'] = $step;
				if ( ! empty( $event['app'] ) ) {
					$state['first_run']['app'] = substr( sanitize_key( $event['app'] ), 0, 32 );
				}
				break;

			case 'first_run.skip':
				if ( ! in_array( $state['first_run']['state'], array( 'done', 'skipped' ), true ) ) {
					$state['first_run']['state']       = 'skipped';
					$state['first_run']['finished_at'] = time();
				}
				break;

			case 'first_run.done':
				$choice = isset( $event['tier_choice'] ) ? (string) $event['tier_choice'] : '';
				if ( ! in_array( $choice, array( '', 'read', 'write' ), true ) ) {
					return self::invalid( __( 'The choice must be read or write.', 'saddle' ) );
				}
				$state['first_run']['state']       = 'done';
				$state['first_run']['step']        = '';
				$state['first_run']['tier_choice'] = $choice;
				$state['first_run']['finished_at'] = time();
				break;

			case 'tour.done':
				update_user_meta( get_current_user_id(), self::USER_META, array( 'tour_done' => true ) );

				return true;

			case 'intro.seen':
			case 'setup.hide':
				$module = isset( $event['module'] ) ? sanitize_key( $event['module'] ) : '';
				if ( '' === $module || strlen( $module ) > 40 ) {
					return self::invalid( __( 'Name the module.', 'saddle' ) );
				}
				if ( ! isset( $state['modules'][ $module ] ) ) {
					if ( count( $state['modules'] ) >= self::MAX_MODULES ) {
						return self::invalid( __( 'Too many modules are recorded.', 'saddle' ) );
					}
					$state['modules'][ $module ] = array(
						'intro_seen'      => '',
						'setup_hidden_at' => 0,
					);
				}
				if ( 'intro.seen' === $name ) {
					$state['modules'][ $module ]['intro_seen'] = substr( preg_replace( '/[^0-9A-Za-z.\-]/', '', isset( $event['version'] ) ? (string) $event['version'] : '' ), 0, 20 );
				} else {
					$state['modules'][ $module ]['setup_hidden_at'] = time();
				}
				break;

			default:
				return self::invalid( __( 'That is not a known onboarding event.', 'saddle' ) );
		}

		update_option( self::OPTION, $state, false );

		return true;
	}

	/**
	 * A 400 for a bad event.
	 *
	 * @param string $message What was wrong.
	 * @return WP_Error
	 */
	private static function invalid( $message ) {
		return new WP_Error( 'saddle_onboarding_invalid', $message, array( 'status' => 400 ) );
	}
}
