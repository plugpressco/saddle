<?php
/**
 * Freemius integration — the account + add-ons host for the Saddle ecosystem.
 *
 * Saddle (free) is the Freemius PARENT product. Saddle Pro attaches to it as a
 * Freemius add-on (its own product, `parent => 33502`), so both plugins stay
 * installed and active together — the add-on never replaces the free plugin.
 *
 * Privacy posture (see CLAUDE.md non-negotiable #1): this is the single
 * disclosed exception to "nothing leaves your install." It is the licensing /
 * account channel only. It NEVER carries site content, MCP tool-call traffic,
 * Application Passwords, or agent data — those never leave the install, full
 * stop. Usage diagnostics stay opt-in (Freemius default; we never force it).
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'sad_fs' ) ) {
	/**
	 * Singleton accessor for the Saddle Freemius instance.
	 *
	 * @return Freemius|null The instance, or null if the SDK isn't loaded.
	 */
	function sad_fs() {
		global $sad_fs;

		if ( ! isset( $sad_fs ) ) {
			// SDK is auto-loaded through Composer (freemius/wordpress-sdk).
			if ( ! function_exists( 'fs_dynamic_init' ) ) {
				return null;
			}

			$sad_fs = fs_dynamic_init(
				array(
					'id'               => '33502',
					'slug'             => 'saddle',
					'premium_slug'     => 'saddle-pro',
					'type'             => 'plugin',
					'public_key'       => 'pk_66f7e1428a6f73d463be1776bbb0a',
					// Free parent: the paid product is the Pro ADD-ON, not this.
					'is_premium'       => false,
					'has_premium_version' => false,
					'has_addons'       => true,
					'has_paid_plans'   => false,
					// Self-hosted distribution — not the WordPress.org directory.
					'is_org_compliant' => false,
					'menu'             => array(
						'slug'    => 'saddle',
						'contact' => false,
						'support' => false,
					),
				)
			);
		}

		return $sad_fs;
	}

	// Init Freemius.
	sad_fs();
	// Signal that the SDK was initiated.
	do_action( 'sad_fs_loaded' );
}
