<?php
/**
 * Applies the updates WordPress already offers (plugins, themes, core,
 * translations) through core's own automatic updater, for one run of
 * {@see Saddle_Update_Runner}.
 *
 * Why a separate class: the update runner schedules, locks, guards and
 * stores runs for every kind of item; this is the one path that hands an
 * offer to {@see WP_Automatic_Updater::update()}, beside
 * {@see Saddle_Install_Runner}, the one that installs from the directory.
 * The split keeps each under the size a class should have (#351).
 *
 * How the hard line keeps its meaning (decided 2026-09-27, extended to core
 * 2026-10-12): each item is an offer core already holds in a transient, and
 * WP_Automatic_Updater::update() downloads and applies it exactly as an
 * auto-update would. The only thing Saddle adds is a one-shot
 * `auto_update_{type}` filter that says yes for the items the owner confirmed,
 * so `should_update()` treats them like an auto-update switched on for this
 * run only. An item core declines is reported as skipped, never forced.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The offer path of a run.
 */
class Saddle_Offer_Runner {

	/**
	 * Apply every offered update of one run through core's automatic updater.
	 * Called by the update runner with its lock held and the completion hooks
	 * lifted.
	 *
	 * @param array                $run     Run, by reference; items get their status.
	 * @param WP_Automatic_Updater $updater Core's updater.
	 */
	public static function apply( array &$run, WP_Automatic_Updater $updater ) {
		$type   = $run['type'];
		$offers = self::offers_for( $run );

		// Say yes, for this run only, to exactly the items the owner confirmed.
		$wanted = array();
		foreach ( $run['items'] as $item ) {
			$wanted[ $item['id'] ] = $item['to'];
		}
		$force = static function ( $update, $offer ) use ( $wanted, $type ) {
			$offer = (object) $offer;
			if ( 'core' === $type ) {
				return ( isset( $offer->current, $wanted['core'] ) && $offer->current === $wanted['core'] ) ? true : $update;
			}
			if ( 'translation' === $type ) {
				return isset( $wanted[ Saddle_Offer_Runner::translation_id( $offer ) ] ) ? true : $update;
			}
			$key = 'theme' === $type ? 'theme' : 'plugin';
			return ( isset( $offer->$key ) && isset( $wanted[ $offer->$key ] ) ) ? true : $update;
		};
		add_filter( "auto_update_{$type}", $force, PHP_INT_MAX, 2 );

		foreach ( $run['items'] as &$item ) {
			$offer = isset( $offers[ $item['id'] ] ) ? $offers[ $item['id'] ] : null;
			if ( ! $offer ) {
				$item['status']  = 'up_to_date';
				$item['message'] = __( 'No update was offered any more when the run started.', 'saddle' );
				continue;
			}
			$offer = is_array( $offer ) ? (object) $offer : $offer;
			// On a failure core's skin opens an output buffer and never closes
			// it (WP_Upgrader_Skin::error() calls header()). Its messages are
			// kept by the skin, so close what the update left open.
			$buffers = ob_get_level();
			try {
				$result = $updater->update( $type, $offer );
			} catch ( Throwable $e ) {
				// Something hooked into the upgrade threw. Core would have
				// left the site in maintenance mode; lift it and report.
				( new WP_Upgrader( new Automatic_Upgrader_Skin() ) )->maintenance_mode( false );
				$result = new WP_Error( 'saddle_update_exception', $e->getMessage() );
			}
			while ( ob_get_level() > $buffers ) {
				ob_end_clean();
			}
			self::record_item( $type, $item, $result );
		}
		unset( $item );

		remove_filter( "auto_update_{$type}", $force, PHP_INT_MAX );
	}

	/**
	 * The offers a run's items are applied from, keyed by item id, asked
	 * fresh where core can refresh them.
	 *
	 * @param array $run Run.
	 * @return object[]
	 */
	private static function offers_for( array $run ) {
		if ( 'core' === $run['type'] ) {
			foreach ( self::core_offers() as $offer ) {
				if ( $offer->current === $run['items'][0]['to'] ) {
					return array( 'core' => $offer );
				}
			}
			return array();
		}
		if ( 'translation' === $run['type'] ) {
			return self::translation_offers();
		}

		if ( 'theme' === $run['type'] ) {
			wp_update_themes();
			$transient = get_site_transient( 'update_themes' );
		} else {
			wp_update_plugins();
			$transient = get_site_transient( 'update_plugins' );
		}
		$offers = array();
		foreach ( ( is_object( $transient ) && ! empty( $transient->response ) ) ? (array) $transient->response : array() as $id => $offer ) {
			$offers[ $id ] = (object) $offer;
		}
		return $offers;
	}

	/**
	 * The core releases WordPress offers for background updates: the
	 * `autoupdate` entries of the update_core transient, which
	 * get_core_updates() leaves out. find_core_auto_update() reads the same.
	 *
	 * @return object[]
	 */
	public static function core_offers() {
		$from_api = get_site_transient( 'update_core' );
		$offers   = array();
		foreach ( ( is_object( $from_api ) && ! empty( $from_api->updates ) ) ? (array) $from_api->updates : array() as $offer ) {
			$offer = (object) $offer;
			if ( isset( $offer->response, $offer->current ) && 'autoupdate' === $offer->response ) {
				$offers[] = $offer;
			}
		}
		return $offers;
	}

	/**
	 * The translation updates WordPress offers, keyed by
	 * "type:slug:language".
	 *
	 * @return object[]
	 */
	public static function translation_offers() {
		$offers = array();
		foreach ( (array) wp_get_translation_updates() as $offer ) {
			$offer = (object) $offer;
			if ( isset( $offer->type, $offer->slug, $offer->language ) ) {
				$offers[ self::translation_id( $offer ) ] = $offer;
			}
		}
		return $offers;
	}

	/**
	 * The id of one translation update: "type:slug:language".
	 *
	 * @param object $offer A language pack offer.
	 * @return string
	 */
	public static function translation_id( $offer ) {
		return isset( $offer->type, $offer->slug, $offer->language ) ? "{$offer->type}:{$offer->slug}:{$offer->language}" : '';
	}

	/**
	 * Translate core's result for one item into a status, and log it.
	 *
	 * @param string $type   'plugin' or 'theme'.
	 * @param array  $item   Run item, by reference.
	 * @param mixed  $result What WP_Automatic_Updater::update() returned.
	 */
	private static function record_item( $type, array &$item, $result ) {
		if ( false === $result ) {
			$item['status']  = 'skipped';
			$item['message'] = __( 'WordPress declined to update this item on its own (a version-control checkout, missing filesystem access, or a plugin telling it not to).', 'saddle' );
		} elseif ( is_wp_error( $result ) ) {
			$codes = $result->get_error_codes();
			if ( in_array( 'plugin_update_fatal_error_rollback_successful', $codes, true ) ) {
				$item['status'] = 'rolled_back';
			} else {
				$item['status'] = 'failed';
			}
			$item['message'] = implode( ' ', $result->get_error_messages() );
		} else {
			$item['status']  = 'applied';
			$item['message'] = '';
		}

		$actions = array(
			'plugin'      => 'update-plugin',
			'theme'       => 'update-theme',
			'core'        => 'update-core',
			'translation' => 'update-translations',
		);
		$action  = $actions[ $type ];
		if ( 'translation' === $type ) {
			$summary = 'applied' === $item['status']
				/* translators: %s: translation, as "Akismet (de_DE)". */
				? sprintf( __( 'Updated the %s translation.', 'saddle' ), $item['name'] )
				/* translators: 1: translation, as "Akismet (de_DE)", 2: why it was not updated. */
				: sprintf( __( 'The %1$s translation was not updated: %2$s', 'saddle' ), $item['name'], $item['message'] ? $item['message'] : $item['status'] );
			Saddle_Log::record_action( $action, $item['id'], $summary );
			return;
		}
		switch ( $item['status'] ) {
			case 'applied':
				/* translators: 1: item name, 2: old version, 3: new version. */
				$summary = sprintf( __( 'Updated %1$s from %2$s to %3$s.', 'saddle' ), $item['name'], $item['from'], $item['to'] );
				break;
			case 'rolled_back':
				/* translators: 1: item name, 2: new version, 3: old version. */
				$summary = sprintf( __( 'Update of %1$s to %2$s caused a fatal error; WordPress restored %3$s.', 'saddle' ), $item['name'], $item['to'], $item['from'] );
				break;
			case 'skipped':
				/* translators: 1: item name, 2: new version. */
				$summary = sprintf( __( 'Update of %1$s to %2$s was skipped by WordPress.', 'saddle' ), $item['name'], $item['to'] );
				break;
			default:
				/* translators: 1: item name, 2: new version, 3: error message. */
				$summary = sprintf( __( 'Update of %1$s to %2$s failed: %3$s', 'saddle' ), $item['name'], $item['to'], $item['message'] );
		}
		Saddle_Log::record_action( $action, $item['id'], $summary );
	}
}
