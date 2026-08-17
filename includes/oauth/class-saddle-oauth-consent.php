<?php
/**
 * The OAuth consent screen.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one place a person decides to let an app in.
 *
 * Everything else in the OAuth subsystem is protocol plumbing. This is the only
 * step with a human in it, and it is the only thing standing between "an app
 * registered itself" and "an app can act on this site" — so it does not get
 * skipped, remembered, or auto-approved. There is no "don't ask again".
 *
 * It lives in wp-admin rather than on the front end, and that is a security
 * decision rather than a convenience one. `admin.php` runs `auth_redirect()`,
 * which sends a logged-out visitor to `wp-login.php` and back — so the login
 * form is WordPress's own, with whatever two-factor or SSO plugin the site
 * runs already hooked into it. Re-implementing a login prompt on an
 * authorization endpoint is how you accidentally build a phishing surface.
 * wp-admin also sends `nocache_headers()`, and page caches and CDNs universally
 * bypass `/wp-admin/`, which matters because an authorization response landing
 * in a shared cache is a credential leak.
 */
class Saddle_OAuth_Consent {

	/**
	 * `admin-post.php` action name for the decision form.
	 */
	const ACTION = 'saddle_oauth_consent';

	/**
	 * Register the consent screen as a hidden admin page.
	 *
	 * Parented to `options.php` deliberately, and this is worth explaining
	 * because the obvious approaches both break.
	 *
	 * `options.php` is not a menu file, so nothing is ever drawn for it — the
	 * page exists and is reachable by URL, but appears in no sidebar. That is
	 * what we want for a screen only ever arrived at mid-OAuth-flow.
	 *
	 * The approach that looks right and is not: registering under Saddle's own
	 * menu and then calling `remove_submenu_page()`. WordPress derives the
	 * registration key from the parent — `add_submenu_page( 'saddle', … )`
	 * records `$_registered_pages['saddle_page_saddle-authorize']` — but
	 * `remove_submenu_page()` deletes the `$submenu` entry that
	 * `get_admin_page_parent()` needs to rediscover that parent. On the next
	 * request the parent resolves to `''`, `get_plugin_page_hookname()` derives
	 * `admin_page_saddle-authorize` instead, that key is absent, and
	 * `user_can_access_admin_page()` refuses with "Sorry, you are not allowed to
	 * access this page" — to an administrator. Registered under one name,
	 * looked up under another.
	 *
	 * Passing `null` as the parent is the historical idiom for a hidden page and
	 * is also out: PHP 8.1 deprecates null for a non-nullable string parameter,
	 * and Saddle supports PHP 8.x.
	 */
	public static function register_page() {
		add_submenu_page(
			'options.php',
			__( 'Authorize app', 'saddle' ),
			__( 'Authorize app', 'saddle' ),
			Saddle_OAuth::authorize_capability(),
			Saddle_OAuth::AUTHORIZE_PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the consent screen.
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the opaque request id to display the request; the decision itself is nonce-checked in handle().
		$request_id = isset( $_GET['saddle_req'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['saddle_req'] ) ) : '';
		$pending    = '' === $request_id ? null : Saddle_OAuth_Store::get_request( $request_id );

		echo '<div class="wrap saddle-oauth-consent">';

		if ( ! $pending ) {
			echo '<h1>' . esc_html__( 'This authorization request has expired', 'saddle' ) . '</h1>';
			echo '<p>' . esc_html__( 'Authorization requests are only good for a few minutes. Start the connection again from the app, and approve it when this screen appears.', 'saddle' ) . '</p>';
			echo '</div>';
			return;
		}

		$user     = wp_get_current_user();
		$verified = 'cimd' === ( isset( $pending['client_source'] ) ? $pending['client_source'] : '' );
		$name     = '' !== (string) $pending['client_name']
			? (string) $pending['client_name']
			: (string) wp_parse_url( (string) $pending['redirect_uri'], PHP_URL_HOST );

		printf(
			'<h1>%s</h1>',
			esc_html(
				sprintf(
					/* translators: 1: connecting app name, 2: site name. */
					__( 'Connect %1$s to %2$s?', 'saddle' ),
					$name,
					get_bloginfo( 'name' )
				)
			)
		);

		// Provenance, stated plainly in both directions. A self-registered app is
		// not necessarily hostile, but the person approving it deserves to know
		// that nothing about its identity was checked.
		echo '<p>';
		if ( $verified ) {
			printf(
				/* translators: %s: URL the app's identity document was fetched from. */
				esc_html__( 'This app identified itself at %s, and Saddle confirmed that address vouches for it.', 'saddle' ),
				'<code>' . esc_html( (string) $pending['client_id'] ) . '</code>'
			);
		} else {
			esc_html_e( 'This app registered itself with your site. Saddle could not verify who it is — only connect it if you started this yourself, just now.', 'saddle' );
		}
		echo '</p>';

		// The clamp, shown before the decision rather than discovered later as a
		// string of refusals. The site tier always wins.
		$site_tier  = Saddle_Capabilities::get_site_tier();
		$asked_tier = Saddle_OAuth::scope_to_tier( (string) $pending['scope'] );
		if ( Saddle_Capabilities::rank( $asked_tier ) > Saddle_Capabilities::rank( $site_tier ) ) {
			$asked_tier = $site_tier;
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: %s: the site's current access level. */
					__( 'This site is set to “%s”, so the app will only get that much no matter what it asked for.', 'saddle' ),
					$site_tier
				)
			) . '</strong></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION . '_' . $request_id );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="saddle_req" value="' . esc_attr( $request_id ) . '">';

		// The choice is the owner's, not the app's. Most apps ask for a level;
		// some — ChatGPT among them — ask for nothing at all and used to be pinned
		// to read forever as a result. Either way this screen decides, and it can
		// never offer more than the site's own level.
		echo '<h2>' . esc_html__( 'What it will be able to do', 'saddle' ) . '</h2>';
		echo '<p>' . esc_html__( 'You choose. This is the most this app will ever be able to do, and you can change it later from Saddle → Connections.', 'saddle' ) . '</p>';

		echo '<ul style="list-style:none;margin:0 0 1.5em">';
		foreach ( self::level_choices( $site_tier ) as $tier => $choice ) {
			printf(
				'<li style="margin-bottom:.75em"><label><input type="radio" name="saddle_level" value="%1$s"%2$s> <strong>%3$s</strong><br><span style="margin-left:1.9em">%4$s</span></label></li>',
				esc_attr( $tier ),
				checked( $tier, $asked_tier, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns its own fixed markup.
				esc_html( $choice['title'] ),
				esc_html( $choice['summary'] )
			);
		}
		echo '</ul>';

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: WordPress user display name. */
				__( 'It will act as %s, and can never do more than that account is allowed to.', 'saddle' ),
				$user->display_name
			)
		) . '</p>';

		echo '<p>' . esc_html__( 'Deletions and overwrites will still show you a preview and ask for confirmation every time. You can disconnect this app at any point from Saddle → Connections.', 'saddle' ) . '</p>';

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: redirect address. */
				__( 'When you decide, you will be sent back to %s', 'saddle' ),
				(string) $pending['redirect_uri']
			)
		) . '</p>';

		echo '<p>';
		echo '<button type="submit" name="decision" value="allow" class="button button-primary">' . esc_html__( 'Allow', 'saddle' ) . '</button> ';
		echo '<button type="submit" name="decision" value="deny" class="button">' . esc_html__( 'Deny', 'saddle' ) . '</button>';
		echo '</p>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * The access levels this site can offer an app, most limited first.
	 *
	 * Capped at the site's own level, because the consent screen must not offer
	 * something the tier system would then refuse — a choice that silently does
	 * nothing is worse than no choice at all. The wording matches the Permissions
	 * screen (`admin/src/api.js`) deliberately: the same three levels described
	 * two different ways is how an owner ends up unsure which one they picked.
	 *
	 * @param string $site_tier The site's configured tier.
	 * @return array<string,array{title:string,summary:string}>
	 */
	private static function level_choices( $site_tier ) {
		$all = array(
			'read'  => array(
				'title'   => __( 'Just reading', 'saddle' ),
				'summary' => __( 'Reads posts, pages, media, and site information. Changes nothing.', 'saddle' ),
			),
			'write' => array(
				'title'   => __( 'Reading & writing', 'saddle' ),
				'summary' => __( 'Also creates and edits posts, pages, and media. Every deletion previews and asks first.', 'saddle' ),
			),
			'admin' => array(
				'title'   => __( 'Managing the site', 'saddle' ),
				'summary' => __( 'Also manages settings, plugins, and themes. Overwrites and deletions ask first.', 'saddle' ),
			),
		);

		$offered = array();
		foreach ( $all as $tier => $choice ) {
			if ( Saddle_Capabilities::rank( $tier ) <= Saddle_Capabilities::rank( $site_tier ) ) {
				$offered[ $tier ] = $choice;
			}
		}

		return $offered;
	}

	/**
	 * Handle the decision.
	 */
	public static function handle() {
		$request_id = isset( $_POST['saddle_req'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['saddle_req'] ) ) : '';

		// Nonce is scoped to this specific request id, so one harvested from a
		// pending authorization cannot be used to approve a different one.
		check_admin_referer( self::ACTION . '_' . $request_id );

		if ( ! current_user_can( Saddle_OAuth::authorize_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to connect apps to this site.', 'saddle' ), 403 );
		}

		$pending = Saddle_OAuth_Store::consume_request( $request_id );
		if ( ! $pending ) {
			wp_die( esc_html__( 'This authorization request has expired or was already answered. Start the connection again from the app.', 'saddle' ), 400 );
		}

		$decision = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['decision'] ) ) : 'deny';

		if ( 'allow' !== $decision ) {
			self::log( $pending, 'oauth-denied', __( 'Declined to connect an app', 'saddle' ) );
			self::bounce_back(
				$pending,
				array(
					'error'             => 'access_denied',
					'error_description' => __( 'The site owner declined the connection.', 'saddle' ),
				)
			);
		}

		// The level the owner picked, never trusted as it arrives. An unknown name
		// falls back to what the client asked for, and anything above the site's
		// own level is clamped down to it — the same ceiling that applies at call
		// time, applied here so the grant can never encode a promise the tier
		// system will refuse to keep.
		$site_tier = Saddle_Capabilities::get_site_tier();
		$level     = isset( $_POST['saddle_level'] ) ? sanitize_key( wp_unslash( (string) $_POST['saddle_level'] ) ) : '';

		if ( ! in_array( $level, Saddle_Capabilities::tiers(), true ) ) {
			$level = Saddle_OAuth::scope_to_tier( (string) $pending['scope'] );
		}

		if ( Saddle_Capabilities::rank( $level ) > Saddle_Capabilities::rank( $site_tier ) ) {
			$level = $site_tier;
		}

		$scope = Saddle_OAuth::tier_to_scope( $level );

		$grant_id = Saddle_OAuth::random_secret( 16 );
		$code     = Saddle_OAuth::random_secret( 32 );

		if ( is_wp_error( $grant_id ) || is_wp_error( $code ) ) {
			self::bounce_back(
				$pending,
				array(
					'error'             => 'server_error',
					'error_description' => __( 'Could not generate a secure token on this server.', 'saddle' ),
				)
			);
		}

		Saddle_OAuth_Store::save_grant(
			array(
				'grant_id'    => $grant_id,
				'client_id'   => (string) $pending['client_id'],
				'client_name' => (string) $pending['client_name'],
				'user_id'     => get_current_user_id(),
				'scope'       => $scope,
				'resource'    => (string) $pending['resource'],
			)
		);

		Saddle_OAuth_Store::save_code(
			$code,
			array(
				'grant_id'       => $grant_id,
				'client_id'      => (string) $pending['client_id'],
				'redirect_uri'   => (string) $pending['redirect_uri'],
				'scope'          => $scope,
				'resource'       => (string) $pending['resource'],
				'code_challenge' => (string) $pending['code_challenge'],
				'user_id'        => get_current_user_id(),
			)
		);

		self::log(
			$pending,
			'oauth-authorized',
			sprintf(
				/* translators: %s: the access level granted (read, write, or admin). */
				__( 'Connected an app with OAuth at the “%s” level', 'saddle' ),
				$level
			)
		);

		self::bounce_back( $pending, array( 'code' => $code ) );
	}

	/**
	 * Send the person back to the app with the outcome.
	 *
	 * Uses `wp_redirect()` rather than `wp_safe_redirect()`, because the
	 * destination is external by definition — that is the entire mechanic of an
	 * OAuth redirect. This is safe for exactly one reason, and it is worth
	 * stating explicitly because it is the highest-risk line in the feature:
	 * `$pending['redirect_uri']` is read from a server-side record, and that
	 * record is only ever written by
	 * {@see Saddle_OAuth_Endpoints::authorize()} after the value matched — by
	 * exact string comparison — one of the redirect URIs the client registered.
	 * No caller-supplied URL reaches this function. If that invariant is ever
	 * relaxed, this becomes an open redirector.
	 *
	 * @param array $pending Pending request record.
	 * @param array $args    Response parameters to append.
	 */
	private static function bounce_back( array $pending, array $args ) {
		if ( '' !== (string) $pending['state'] ) {
			$args['state'] = (string) $pending['state'];
		}

		// RFC 9207: naming ourselves lets the client detect a mix-up attack,
		// where a response from one authorization server is replayed at another.
		$args['iss'] = Saddle_OAuth::issuer();

		$url = add_query_arg( array_map( 'rawurlencode', $args ), (string) $pending['redirect_uri'] );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External by protocol design; the destination came from a registered, exactly-matched redirect URI. See docblock.
		wp_redirect( $url );
		exit;
	}

	/**
	 * Record the decision in Saddle's activity log.
	 *
	 * Connections are exactly the kind of thing an owner should be able to find
	 * later — including the ones they turned down, which is how you notice an
	 * app trying repeatedly.
	 *
	 * @param array  $pending Pending request record.
	 * @param string $action  Log action key.
	 * @param string $summary One-line description.
	 */
	private static function log( array $pending, $action, $summary ) {
		if ( ! class_exists( 'Saddle_Log' ) ) {
			return;
		}

		Saddle_Log::record(
			array(
				'action'  => $action,
				'target'  => (string) $pending['client_id'],
				'summary' => $summary . ' — ' . ( '' !== (string) $pending['client_name'] ? (string) $pending['client_name'] : (string) $pending['client_id'] ),
			)
		);
	}
}
