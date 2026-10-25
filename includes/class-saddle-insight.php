<?php
/**
 * Insight: opt-in usage data and deactivation feedback, sent to PlugPress's
 * insight.plugpress.io only after the owner clicks.
 *
 * Two clicks send something, and nothing else does:
 *
 * - "Allow and continue" on Overview's card, or Usage data turned on in
 *   Settings: the site block below and the Saddle plugins on the site, then
 *   a weekly update while it stays on (only when something changed). With
 *   "Saddle news" ticked, the address the person typed goes to PlugPress's
 *   newsletter, which emails them to confirm.
 * - "Send and deactivate" in the Plugins screen's dialog: the reason, the
 *   comment and the reply email typed there, the plugin's version, and the
 *   WordPress and PHP versions and the language.
 *
 * Skip, "No thanks" and "Skip and deactivate" send nothing. Saddle speaks for
 * the whole family (Saddle_Insight_Payload::FAMILY): its add-ons need Saddle, so they carry no code
 * of their own. `define( 'PLUGPRESS_INSIGHT', false );` turns everything
 * off, and so does the `saddle_insight_enabled` filter. No agent tool reads
 * or changes any of it.
 *
 * The contract is the README of plugpressco/plugpress-insight.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The opt-in state and every send.
 */
class Saddle_Insight {

	/**
	 * Where the sends go. Fixed, so it needs no SSRF guard.
	 */
	const ENDPOINT = 'https://insight.plugpress.io/api/v1/';

	/**
	 * The owner's answer: `state` ('' not asked, 'in', 'out'), the parts
	 * left on, when they answered, and a hash of the last weekly send.
	 */
	const OPTION = 'saddle_insight';

	/**
	 * The weekly update.
	 */
	const HOOK = 'saddle_insight_weekly';

	/**
	 * The newsletter list on plugpress.io.
	 */
	const NEWS_LIST = 'saddle';

	/**
	 * The deactivation dialog's choices, in its order.
	 */
	const REASONS = array( 'not_what_i_needed', 'hard_to_set_up', 'broke_something', 'found_another', 'short_time', 'temporary', 'other' );

	/**
	 * The parts an owner can switch off, and where each starts. The name and
	 * email part starts off: the statistics don't need it.
	 */
	const DEFAULT_PARTS = array(
		'site'    => true,
		'plugins' => true,
		'profile' => false,
	);

	/**
	 * Whether insight may run on this site at all.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$on = ! ( defined( 'PLUGPRESS_INSIGHT' ) && false === PLUGPRESS_INSIGHT );
		/**
		 * Whether Saddle may offer the opt-in and the deactivation dialog.
		 *
		 * @param bool $on False when PLUGPRESS_INSIGHT is defined as false.
		 */
		return (bool) apply_filters( 'saddle_insight_enabled', $on );
	}

	/**
	 * The stored answer, with defaults.
	 *
	 * @return array{state:string,parts:array,answered:int,hash:string}
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = isset( $stored['state'] ) && in_array( $stored['state'], array( 'in', 'out' ), true ) ? $stored['state'] : '';
		return array(
			'state'    => $state,
			'parts'    => self::parts( isset( $stored['parts'] ) ? $stored['parts'] : array() ),
			'answered' => isset( $stored['answered'] ) ? (int) $stored['answered'] : 0,
			'hash'     => isset( $stored['hash'] ) ? (string) $stored['hash'] : '',
		);
	}

	/**
	 * What the admin app needs for the Overview card and Settings.
	 *
	 * @return array
	 */
	public static function for_app() {
		$state = self::state();
		$user  = wp_get_current_user();
		return array(
			'enabled' => self::enabled(),
			'state'   => $state['state'],
			'parts'   => $state['parts'],
			'email'   => $user && $user->exists() ? $user->user_email : '',
		);
	}

	/**
	 * The owner answered the card, or flipped the switch in Settings.
	 *
	 * @param string $choice 'allow', 'skip' or 'off'.
	 * @param array  $parts  The parts left on (allow only).
	 * @param string $news   An address for Saddle news, or ''.
	 * @return array The app state after the answer.
	 */
	public static function answer( $choice, array $parts = array(), $news = '' ) {
		if ( ! self::enabled() ) {
			return self::for_app();
		}
		$before = self::state();
		if ( 'allow' === $choice ) {
			$parts = self::parts( $parts );
			self::save( 'in', $parts, '' );
			$body = Saddle_Insight_Payload::register_body( $parts );
			$news = sanitize_email( $news );
			if ( $news && is_email( $news ) ) {
				$body['news'] = array(
					'list'  => self::NEWS_LIST,
					'email' => $news,
				);
			}
			self::send( 'optin', $body );
			self::schedule();
		} else {
			self::save( 'out', $before['parts'], '' );
			wp_clear_scheduled_hook( self::HOOK );
			if ( 'off' === $choice && 'in' === $before['state'] ) {
				self::send(
					'optout',
					array(
						'site'     => array( 'id' => Saddle_Insight_Payload::site_id() ),
						'products' => wp_list_pluck( Saddle_Insight_Payload::products(), 'slug' ),
					)
				);
			}
		}
		return self::for_app();
	}

	/**
	 * Saddle news on its own, from Settings: the address goes to the
	 * newsletter, and no usage data goes with it.
	 *
	 * @param string $email The address the person typed.
	 * @return bool Whether it was valid and handed over.
	 */
	public static function subscribe( $email ) {
		$email = sanitize_email( $email );
		if ( ! self::enabled() || ! $email || ! is_email( $email ) ) {
			return false;
		}
		return self::send(
			'news',
			array(
				'list'  => self::NEWS_LIST,
				'email' => $email,
			)
		);
	}

	/**
	 * The weekly update, while opted in and only when something changed.
	 */
	public static function weekly() {
		$state = self::state();
		if ( ! self::enabled() || 'in' !== $state['state'] ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		$body = Saddle_Insight_Payload::register_body( $state['parts'] );
		$hash = md5( (string) wp_json_encode( $body ) );
		if ( $hash === $state['hash'] ) {
			return;
		}
		if ( self::send( 'update', $body ) ) {
			self::save( 'in', $state['parts'], $hash );
		}
	}

	/**
	 * "Send and deactivate": what the dialog lists, for one family plugin.
	 *
	 * @param string $plugin  Plugin file, e.g. saddle-rank/saddle-rank.php.
	 * @param string $reason  One of REASONS.
	 * @param string $comment What they typed, up to 500 characters.
	 * @param string $email   A reply address, or ''.
	 * @return bool Whether it was sent.
	 */
	public static function deactivation( $plugin, $reason, $comment = '', $email = '' ) {
		$product = Saddle_Insight_Payload::product( $plugin );
		if ( ! self::enabled() || ! $product || ! in_array( $reason, self::REASONS, true ) ) {
			return false;
		}
		$email = sanitize_email( $email );
		$site  = Saddle_Insight_Payload::site_block( array() );
		return self::send(
			'deactivate',
			array(
				'site'    => array(
					'id'          => $site['id'],
					'wp'          => $site['wp'],
					'php'         => $site['php'],
					'language'    => $site['language'],
					'multisite'   => $site['multisite'],
					'environment' => $site['environment'],
				),
				'product' => array(
					'slug'    => $product['slug'],
					'version' => $product['version'],
				),
				'reason'  => $reason,
				'comment' => mb_substr( sanitize_textarea_field( $comment ), 0, 500 ),
				'email'   => is_email( $email ) ? $email : '',
			)
		);
	}

	/**
	 * Keep the weekly update scheduled while opted in (after a reactivation,
	 * for one).
	 */
	public static function schedule() {
		if ( 'in' === self::state()['state'] && self::enabled() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::HOOK );
		}
	}

	/**
	 * Only the known parts, as booleans.
	 *
	 * @param mixed $parts Parts from the request or the option.
	 * @return array
	 */
	private static function parts( $parts ) {
		$parts = is_array( $parts ) ? $parts : array();
		$clean = array();
		foreach ( self::DEFAULT_PARTS as $key => $default ) {
			$clean[ $key ] = array_key_exists( $key, $parts ) ? (bool) rest_sanitize_boolean( $parts[ $key ] ) : $default;
		}
		return $clean;
	}

	/**
	 * Store the answer.
	 *
	 * @param string $state 'in' or 'out'.
	 * @param array  $parts The parts left on.
	 * @param string $hash  The last weekly send's hash.
	 */
	private static function save( $state, array $parts, $hash ) {
		update_option(
			self::OPTION,
			array(
				'state'    => $state,
				'parts'    => $parts,
				'answered' => time(),
				'hash'     => $hash,
			),
			false
		);
	}

	/**
	 * One call to insight.plugpress.io: 5 seconds at most, never retried.
	 *
	 * @param string $route optin, update, deactivate, optout or news.
	 * @param array  $body  The JSON body.
	 * @return bool Whether it answered 2xx.
	 */
	private static function send( $route, array $body ) {
		// A fixed https host, not an address from a request, so Saddle_HTTP's
		// SSRF guard has nothing to check. WordPress waits for the answer
		// over cURL even with `blocking` off, so this waits and says so.
		$response = wp_remote_post(
			self::ENDPOINT . $route,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
				'user-agent'  => 'Saddle/' . SADDLE_VERSION,
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
