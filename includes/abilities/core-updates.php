<?php
/**
 * WordPress core and translation updates, through core's own automatic
 * updater.
 *
 * Decided 2026-10-12 (#347, #351): Saddle applies a core update only for a
 * release WordPress already offers for background updates, through
 * {@see WP_Automatic_Updater::update()} in {@see Saddle_Update_Runner}, out of
 * band, after a confirmed preview. Minor and security releases, always. A
 * major release only when the owner has turned on Saddle's own setting,
 * "Major WordPress releases" in Saddle → Settings, which is off by default
 * and which no tool can change (Fahim, 2026-10-13, #359). It replaced
 * WordPress's own "automatic updates for all new versions" switch (#351):
 * WordPress turns that on by default for sites installed since 5.6, so it
 * was rarely the owner's choice, and it decides what WordPress does
 * unattended, not what an app may do after a preview. A major has no
 * automatic rollback and upgrades the database.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the core update abilities. Hooked to `wp_abilities_api_init`.
 *
 * The permission capability is `activate_plugins`, as for update-plugin:
 * `update_core` maps to `do_not_allow` on a site that forbids file changes.
 * The handler names that reason first, then checks `update_core` itself.
 */
function saddle_register_core_update_abilities() {
	wp_register_ability(
		'saddle/update-core',
		array(
			'label'               => __( 'Update WordPress', 'saddle' ),
			'description'         => __( 'Updates WordPress itself to a release WordPress already offers for background updates (see list-updates). Minor and security releases (7.1.2 to 7.1.3) are always allowed. A major release (7.1 to 7.2) is allowed only when the owner has turned on "Major WordPress releases" in Saddle → Settings, which is off by default and which no tool can change; otherwise it is refused, and the owner updates it in Dashboard → Updates or turns that setting on. When both are offered, the minor release comes first. It takes two calls. The first changes nothing: it returns a preview with the installed and new version, whether it is minor or major, the PHP and MySQL versions it needs against this site\'s, and for a major release the active plugins not tested with it and what cannot be undone, plus a confirm_token. Call again with that token to queue the update. WordPress\'s own updater then runs it in the background, with a short maintenance message for visitors; check list-updates a minute later for the result under "runs". A core update cannot be undone: WordPress has no rollback for one, and a major release also upgrades the database. Refuses development versions, a site where the last automatic core update failed critically, and sites where WordPress cannot change files.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'Token from the preview step, required to queue the update.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Core_Update_Abilities', 'update_core' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'update-core' ),
			'meta'                => saddle_ability_meta( false, true, false, 'admin', true ),
		)
	);

	wp_register_ability(
		'saddle/update-translations',
		array(
			'label'               => __( 'Update translations', 'saddle' ),
			'description'         => __( 'Applies every translation update WordPress offers for WordPress, plugins and themes (list-updates counts them). It takes two calls. The first changes nothing: it returns a preview listing each translation (what it translates and the language) and a confirm_token. Call again with that token to queue the run. WordPress\'s own language pack updater then applies them in the background, as it does on its own during background updates; check list-updates a minute later for each result under "runs". There is no list to pass and no limit: all offered translations are applied together. Translation updates cannot be undone. Refuses when nothing is offered and on sites where WordPress cannot change files.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'Token from the preview step, required to queue the run.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Core_Update_Abilities', 'update_translations' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'activate_plugins', 'update-translations' ),
			'meta'                => saddle_ability_meta( false, true, false, 'admin', true ),
		)
	);
}

/**
 * Execute callbacks for the core update abilities.
 */
class Saddle_Core_Update_Abilities {

	/**
	 * saddle/update-core.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_core( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		Saddle_Update_Runner::load_update_api();

		// Refuse before a preview exists: a token must never be issued for an
		// update this site cannot perform.
		$blocked = Saddle_Update_Runner::blocked_by( 'core' );
		if ( ! empty( $blocked ) ) {
			return new WP_Error( 'saddle_updates_unavailable', implode( ' ', $blocked ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( 'update_core' ) ) {
			return new WP_Error( 'saddle_cannot_update_core', __( 'The WordPress account this app is connected as cannot update WordPress here (on a multisite network, only a network administrator can). No Saddle setting changes that.', 'saddle' ), array( 'status' => 403 ) );
		}

		$offer = self::choose();
		if ( is_wp_error( $offer ) ) {
			return $offer;
		}

		$from  = get_bloginfo( 'version' );
		$to    = (string) $offer->current;
		$major = 'major' === self::kind( $to );
		$risks = $major ? array(
			__( 'WordPress has no automatic rollback for a core update. If the new version breaks a plugin or the theme, the site stays on it until someone fixes it.', 'saddle' ),
			__( 'A major release upgrades the database, so going back means restoring a database backup.', 'saddle' ),
			__( 'Saddle runs on WordPress\'s own APIs. If the release changes them, this app may lose its connection before it can report the result.', 'saddle' ),
		) : array();

		return Saddle_Approval::gate(
			array(
				'action'  => 'update-core',
				'target'  => 'core',
				// Bind the exact release: a preview shown for one version must
				// not be confirmable into applying another offered in between.
				'bind'    => $to . '|' . ( isset( $offer->locale ) ? $offer->locale : '' ),
				'summary' => $major
					/* translators: 1: installed WordPress version, 2: new version. */
					? sprintf( __( 'Update WordPress from %1$s to %2$s, a major release, in the background. There is no automatic rollback.', 'saddle' ), $from, $to )
					/* translators: 1: installed WordPress version, 2: new version. */
					: sprintf( __( 'Update WordPress from %1$s to %2$s, a minor release, in the background.', 'saddle' ), $from, $to ),
				'preview' => array(
					'from'               => $from,
					'to'                 => $to,
					'kind'               => $major ? 'major' : 'minor',
					'locale'             => isset( $offer->locale ) ? (string) $offer->locale : null,
					'requires_php'       => isset( $offer->php_version ) ? (string) $offer->php_version : null,
					'php'                => PHP_VERSION,
					'requires_mysql'     => isset( $offer->mysql_version ) ? (string) $offer->mysql_version : null,
					'untested_plugins'   => $major ? self::untested_plugins( $to ) : array(),
					'cannot_be_undone'   => $risks,
					'runs_in_background' => true,
					'maintenance'        => __( 'Visitors see a short maintenance message while WordPress updates.', 'saddle' ),
				),
				// The gate logs the confirmed call with this line, once.
				/* translators: 1: installed WordPress version, 2: new version. */
				'done'    => sprintf( __( 'Queued update of WordPress from %1$s to %2$s.', 'saddle' ), $from, $to ),
				'input'   => $input,
				'execute' => static function () use ( $from, $to ) {
					$run_id = Saddle_Update_Runner::queue(
						'core',
						array(
							array(
								'id'   => 'core',
								'name' => 'WordPress',
								'from' => $from,
								'to'   => $to,
							),
						)
					);
					return array(
						'queued'     => true,
						'run_id'     => $run_id,
						'type'       => 'core',
						'from'       => $from,
						'to'         => $to,
						'check_with' => 'list-updates',
						'note'       => __( 'The update starts within about a minute and reports applied, failed or skipped under "runs" in list-updates.', 'saddle' ),
					);
				},
			)
		);
	}

	/**
	 * saddle/update-translations.
	 *
	 * No item list and no 10-item cap, unlike update-plugin: WordPress
	 * applies every offered language pack on its own whenever background
	 * updates run, and a site often has dozens.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_translations( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		Saddle_Update_Runner::load_update_api();

		$blocked = Saddle_Update_Runner::blocked_by( 'translation' );
		if ( ! empty( $blocked ) ) {
			return new WP_Error( 'saddle_updates_unavailable', implode( ' ', $blocked ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( 'update_languages' ) ) {
			return new WP_Error( 'saddle_cannot_update_translations', __( 'The WordPress account this app is connected as cannot update translations here (on a multisite network, only a network administrator can). No Saddle setting changes that.', 'saddle' ), array( 'status' => 403 ) );
		}

		$namer = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
		$items = array();
		foreach ( Saddle_Offer_Runner::translation_offers() as $id => $offer ) {
			$items[] = array(
				'id'   => $id,
				'name' => sprintf( '%s (%s)', $namer->get_name_for_update( $offer ), $offer->language ),
				'from' => '',
				'to'   => isset( $offer->updated ) ? (string) $offer->updated : (string) $offer->version,
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'saddle_no_update_offered', __( 'WordPress is not offering any translation updates right now.', 'saddle' ), array( 'status' => 409 ) );
		}
		$count = count( $items );

		return Saddle_Approval::gate(
			array(
				'action'  => 'update-translations',
				'target'  => 'translations',
				// Bind the exact list: a preview of these packs must not be
				// confirmable into applying a different set.
				'bind'    => substr( hash( 'sha256', wp_json_encode( array_combine( wp_list_pluck( $items, 'id' ), wp_list_pluck( $items, 'to' ) ) ) ), 0, 16 ),
				/* translators: %d: number of translations. */
				'summary' => sprintf( _n( 'Update %d translation in the background.', 'Update %d translations in the background.', $count, 'saddle' ), $count ),
				'preview' => array(
					'items'              => $items,
					'runs_in_background' => true,
				),
				// The gate logs the confirmed call with this line, once.
				/* translators: %d: number of translations. */
				'done'    => sprintf( _n( 'Queued %d translation update.', 'Queued %d translation updates.', $count, 'saddle' ), $count ),
				'input'   => $input,
				'execute' => static function () use ( $items ) {
					return array(
						'queued'     => true,
						'run_id'     => Saddle_Update_Runner::queue( 'translation', $items ),
						'type'       => 'translation',
						'items'      => $items,
						'check_with' => 'list-updates',
					);
				},
			)
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * The release to offer: the newest minor one, else a major one when the
	 * owner allows majors in Saddle → Settings, or a named refusal.
	 *
	 * @return object|WP_Error An offer from the update_core transient.
	 */
	private static function choose() {
		$current = get_bloginfo( 'version' );
		if ( false !== strpos( $current, '-' ) ) {
			return new WP_Error(
				'saddle_core_development',
				/* translators: %s: installed WordPress version. */
				sprintf( __( 'This site runs a development version of WordPress (%s). Saddle does not update development versions; update it in Dashboard → Updates.', 'saddle' ), $current ),
				array( 'status' => 409 )
			);
		}
		$failed = get_site_option( 'auto_core_update_failed' );
		if ( is_array( $failed ) && ! empty( $failed['critical'] ) ) {
			return new WP_Error( 'saddle_core_update_failed_before', __( 'The last automatic WordPress update on this site failed critically. WordPress stops updating itself until someone updates it in Dashboard → Updates, and Saddle does the same.', 'saddle' ), array( 'status' => 409 ) );
		}

		$best = array(
			'minor' => null,
			'major' => null,
		);
		foreach ( Saddle_Offer_Runner::core_offers() as $offer ) {
			if ( version_compare( $offer->current, $current, '<=' ) ) {
				continue;
			}
			$kind = self::kind( $offer->current );
			if ( ! $best[ $kind ] || version_compare( $offer->current, $best[ $kind ]->current, '>' ) ) {
				$best[ $kind ] = $offer;
			}
		}

		$offer = $best['minor'];
		if ( ! $offer && $best['major'] ) {
			if ( ! Saddle_Offer_Runner::core_majors_allowed() ) {
				return new WP_Error(
					'saddle_major_core_update',
					/* translators: %s: new WordPress version. */
					sprintf( __( 'WordPress %s is a major release. Saddle applies one only when the owner turns on "Major WordPress releases" in Saddle → Settings, and it is off on this site. Tell the user they can update it in Dashboard → Updates, or turn that setting on and ask again. Do not retry.', 'saddle' ), $best['major']->current ),
					array( 'status' => 409 )
				);
			}
			$offer = $best['major'];
		}
		if ( ! $offer ) {
			return new WP_Error( 'saddle_no_update_offered', __( 'WordPress is not offering a core update this site can apply in the background. list-updates shows what is offered; a release offered only in Dashboard → Updates is applied there.', 'saddle' ), array( 'status' => 409 ) );
		}

		global $wpdb;
		if ( ! empty( $offer->php_version ) && version_compare( PHP_VERSION, $offer->php_version, '<' ) ) {
			return new WP_Error(
				'saddle_php_too_old',
				/* translators: 1: new WordPress version, 2: required PHP version, 3: this site's PHP version. */
				sprintf( __( 'WordPress %1$s needs PHP %2$s or newer; this site runs PHP %3$s. The host has to update PHP first.', 'saddle' ), $offer->current, $offer->php_version, PHP_VERSION ),
				array( 'status' => 409 )
			);
		}
		$sqlite = file_exists( WP_CONTENT_DIR . '/db.php' ) && empty( $wpdb->is_mysql );
		if ( ! $sqlite && ! empty( $offer->mysql_version ) && version_compare( $wpdb->db_version(), $offer->mysql_version, '<' ) ) {
			return new WP_Error(
				'saddle_mysql_too_old',
				/* translators: 1: new WordPress version, 2: required MySQL version, 3: this site's MySQL version. */
				sprintf( __( 'WordPress %1$s needs MySQL %2$s or newer; this site runs %3$s. The host has to update the database first.', 'saddle' ), $offer->current, $offer->mysql_version, $wpdb->db_version() ),
				array( 'status' => 409 )
			);
		}
		return $offer;
	}

	/**
	 * Minor or major, by core's own rule: the same X.Y branch is minor.
	 *
	 * @param string $version Offered version.
	 * @return string 'minor' or 'major'.
	 */
	private static function kind( $version ) {
		return Saddle_Offer_Runner::is_major( $version ) ? 'major' : 'minor';
	}

	/**
	 * Active plugins whose author has not tested them with a release's X.Y,
	 * from what WordPress.org last told this site. Plugins from elsewhere carry
	 * no "tested" and are not listed.
	 *
	 * @param string $version New WordPress version.
	 * @return array[] {plugin, name, tested}
	 */
	private static function untested_plugins( $version ) {
		$known  = get_site_transient( 'update_plugins' );
		$tested = array();
		foreach ( array( 'no_update', 'response' ) as $list ) {
			foreach ( isset( $known->$list ) ? (array) $known->$list : array() as $file => $item ) {
				$item = (object) $item;
				if ( ! empty( $item->tested ) ) {
					$tested[ $file ] = (string) $item->tested;
				}
			}
		}

		$new   = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
		$all   = Saddle_Context::get_plugins_quietly();
		$found = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $tested[ $file ] ) && version_compare( $tested[ $file ], $new, '<' ) ) {
				$found[] = array(
					'plugin' => $file,
					'name'   => isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $file,
					'tested' => $tested[ $file ],
				);
			}
		}
		return $found;
	}
}
