<?php
/**
 * Notices: what Core, and modules through a filter, want the owner to see.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One list of notices with one shape, so the admin frame can show the most
 * severe one under the header and park the rest behind the bell.
 *
 * A notice is `{ id, module, severity: error|warning|info|success, message,
 * action: null|{label,url}, where: saddle|plugins, screens?: ["area/tab"],
 * dismiss: user|site|false, resolved?: callable }`. A malformed notice is
 * dropped rather than shown half-drawn. `resolved` runs on the server on every
 * read and a resolved notice is dropped, so an error that carries its own fix
 * goes away the moment the fix lands.
 *
 * Dismissal is per user (user meta) or per site (an option). Both lists keep
 * only the newest MAX_DISMISSED ids so they cannot grow without bound.
 */
class Saddle_Notices {

	/** User meta and site option holding dismissed ids. */
	const STORE = 'saddle_dismissed_notices';

	/** The most ids either store keeps. */
	const MAX_DISMISSED = 100;

	/** Severity order, most severe first. */
	const SEVERITIES = array( 'error', 'warning', 'info', 'success' );

	/** The Core notice shown on the Plugins screen. */
	const INSTALLED_ID = 'saddle-installed';

	/**
	 * Whether first run is finished or skipped.
	 *
	 * @return bool
	 */
	public static function first_run_finished() {
		return class_exists( 'Saddle_Onboarding' ) ? (bool) Saddle_Onboarding::is_finished() : (bool) get_option( 'saddle_onboarded' );
	}

	/**
	 * Core's own notices.
	 *
	 * @return array[]
	 */
	private static function core() {
		return array(
			array(
				'id'       => self::INSTALLED_ID,
				'module'   => 'saddle',
				'severity' => 'info',
				'message'  => __( 'Saddle is installed. Connect your AI (2 minutes).', 'saddle' ),
				'action'   => array(
					'label' => __( 'Get started', 'saddle' ),
					'url'   => Saddle_Modules::url( 'home' ),
				),
				'where'    => 'plugins',
				'dismiss'  => 'user',
				'resolved' => array( __CLASS__, 'first_run_finished' ),
			),
		);
	}

	/**
	 * Check one notice's shape and fill its defaults.
	 *
	 * @param mixed $notice As registered.
	 * @return array|null Null when it is malformed.
	 */
	private static function normalize( $notice ) {
		if ( ! is_array( $notice ) ) {
			return null;
		}

		$id      = isset( $notice['id'] ) && is_string( $notice['id'] ) ? $notice['id'] : '';
		$message = isset( $notice['message'] ) && is_string( $notice['message'] ) ? trim( $notice['message'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', $id ) || '' === $message ) {
			return null;
		}

		$severity = isset( $notice['severity'] ) ? $notice['severity'] : 'info';
		$where    = isset( $notice['where'] ) ? $notice['where'] : 'saddle';
		$dismiss  = isset( $notice['dismiss'] ) ? $notice['dismiss'] : false;
		if ( ! in_array( $severity, self::SEVERITIES, true ) || ! in_array( $where, array( 'saddle', 'plugins' ), true ) || ! in_array( $dismiss, array( 'user', 'site', false ), true ) ) {
			return null;
		}

		$action = null;
		if ( isset( $notice['action'] ) && is_array( $notice['action'] ) ) {
			$label = isset( $notice['action']['label'] ) && is_string( $notice['action']['label'] ) ? $notice['action']['label'] : '';
			$url   = isset( $notice['action']['url'] ) && is_string( $notice['action']['url'] ) ? $notice['action']['url'] : '';
			if ( '' !== $label && '' !== $url ) {
				$action = array(
					'label' => $label,
					'url'   => esc_url_raw( $url ),
				);
			}
		}

		$screens = array();
		if ( isset( $notice['screens'] ) && is_array( $notice['screens'] ) ) {
			$screens = array_values( array_filter( $notice['screens'], 'is_string' ) );
		}

		return array(
			'id'       => $id,
			'module'   => isset( $notice['module'] ) && is_string( $notice['module'] ) ? sanitize_key( $notice['module'] ) : 'saddle',
			'severity' => $severity,
			'message'  => $message,
			'action'   => $action,
			'where'    => $where,
			'screens'  => $screens,
			'dismiss'  => $dismiss,
			'resolved' => isset( $notice['resolved'] ) && is_callable( $notice['resolved'] ) ? $notice['resolved'] : null,
		);
	}

	/**
	 * Every valid, unresolved notice, dismissed or not. The first notice with
	 * an id wins.
	 *
	 * @return array[] Keyed by id.
	 */
	private static function live() {
		/**
		 * Notices for the owner. Add arrays shaped as described on this class.
		 *
		 * @param array[] $notices Core's notices so far.
		 */
		$raw = apply_filters( 'saddle_notices', self::core() );

		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $notice ) {
			$notice = self::normalize( $notice );
			if ( null === $notice || isset( $out[ $notice['id'] ] ) ) {
				continue;
			}

			if ( $notice['resolved'] ) {
				try {
					if ( call_user_func( $notice['resolved'] ) ) {
						continue;
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- A check that throws must never break a page; the notice stays.
					unset( $e );
				}
			}

			$out[ $notice['id'] ] = $notice;
		}

		return $out;
	}

	/**
	 * Ids the current user has dismissed, for themselves or for the site.
	 *
	 * @return string[]
	 */
	private static function dismissed() {
		$site = get_option( self::STORE, array() );
		$user = get_user_meta( get_current_user_id(), self::STORE, true );

		return array_merge( is_array( $site ) ? $site : array(), is_array( $user ) ? $user : array() );
	}

	/**
	 * The notices to show now on one surface, most severe first, callables
	 * stripped.
	 *
	 * @param string $where  `saddle` (the app) or `plugins`.
	 * @param string $screen The app screen as `area/tab`; ignored for `plugins`.
	 * @return array[] Each `{ id, module, severity, message, action, dismiss }`.
	 */
	public static function for_screen( $where = 'saddle', $screen = '' ) {
		$dismissed = self::dismissed();
		$out       = array();

		foreach ( self::live() as $notice ) {
			if ( $notice['where'] !== $where || in_array( $notice['id'], $dismissed, true ) ) {
				continue;
			}
			if ( 'saddle' === $where && $notice['screens'] && ! self::on_screen( $notice['screens'], $screen ) ) {
				continue;
			}

			$out[] = array(
				'id'       => $notice['id'],
				'module'   => $notice['module'],
				'severity' => $notice['severity'],
				'message'  => $notice['message'],
				'action'   => $notice['action'],
				'dismiss'  => $notice['dismiss'],
			);
		}

		// A stable sort: equal severities keep the order they were added in.
		$rank  = array_flip( self::SEVERITIES );
		$index = array_keys( $out );
		usort(
			$index,
			static function ( $a, $b ) use ( $out, $rank ) {
				$by = $rank[ $out[ $a ]['severity'] ] <=> $rank[ $out[ $b ]['severity'] ];
				return 0 !== $by ? $by : $a <=> $b;
			}
		);

		return array_map(
			static function ( $i ) use ( $out ) {
				return $out[ $i ];
			},
			$index
		);
	}

	/**
	 * Whether a screen is in a notice's list. An entry may be a whole area
	 * (`connections`) or one tab (`connections/apps`).
	 *
	 * @param string[] $screens The notice's screens.
	 * @param string   $screen  `area/tab`.
	 * @return bool
	 */
	private static function on_screen( $screens, $screen ) {
		$area = strtok( $screen, '/' );

		return in_array( $screen, $screens, true ) || in_array( $area, $screens, true );
	}

	/**
	 * Dismiss a notice for this user or for the site, as the notice asks.
	 *
	 * @param string $id Notice id.
	 * @return true|WP_Error
	 */
	public static function dismiss( $id ) {
		$live = self::live();
		if ( ! isset( $live[ $id ] ) ) {
			return new WP_Error( 'saddle_unknown_notice', __( 'That notice does not exist, or it has already cleared itself.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( false === $live[ $id ]['dismiss'] ) {
			return new WP_Error( 'saddle_notice_not_dismissible', __( 'That notice cannot be dismissed. It goes away once the problem is fixed.', 'saddle' ), array( 'status' => 400 ) );
		}

		if ( 'site' === $live[ $id ]['dismiss'] ) {
			$ids = get_option( self::STORE, array() );
			update_option( self::STORE, self::remember( $ids, $id ), false );
		} else {
			$ids = get_user_meta( get_current_user_id(), self::STORE, true );
			update_user_meta( get_current_user_id(), self::STORE, self::remember( $ids, $id ) );
		}

		return true;
	}

	/**
	 * Add an id to a stored list, keeping only the newest MAX_DISMISSED.
	 *
	 * @param mixed  $ids The stored value.
	 * @param string $id  The id to add.
	 * @return string[]
	 */
	private static function remember( $ids, $id ) {
		$ids   = is_array( $ids ) ? array_values( array_diff( $ids, array( $id ) ) ) : array();
		$ids[] = $id;

		return array_slice( $ids, -self::MAX_DISMISSED );
	}
}
