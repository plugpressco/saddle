<?php
/**
 * PlugPress Insight: a news card, quick feedback on deactivate, and a weekly
 * update while the owner shares site info. Nothing is sent before a click;
 * README.md says what is sent and when.
 *
 * @package PlugPress_Insight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the SDK does.
 */
class PlugPress_Insight {

	const URL     = 'https://insight.plugpress.io/api/v1/';
	const OPTION  = 'plugpress_insight';
	const SITE_ID = 'plugpress_insight_site_id';
	const CRON    = 'plugpress_insight_weekly';

	/**
	 * The plugins that called plugpress_insight(), by plugin slug.
	 *
	 * @var array
	 */
	private static $products = array();

	/**
	 * Hook in once for every registered plugin.
	 *
	 * @param array $plugins What each plugin passed to plugpress_insight().
	 */
	public static function start( array $plugins ) {
		foreach ( $plugins as $plugin ) {
			if ( empty( $plugin['file'] ) ) {
				continue;
			}
			$basename = plugin_basename( $plugin['file'] );
			$plugin  += array(
				'slug' => false === strpos( $basename, '/' ) ? basename( $basename, '.php' ) : dirname( $basename ),
			);
			$plugin  += array(
				'product' => $plugin['slug'],
				'name'    => $plugin['slug'],
				'version' => '0',
				'page'    => '',
				'color'   => '',
				'slot'    => '',
			);
			$plugin  += array( 'brand' => $plugin['name'] );

			$plugin['basename']                = $basename;
			self::$products[ $plugin['slug'] ] = $plugin;
		}

		$on = ! ( defined( 'PLUGPRESS_INSIGHT' ) && false === PLUGPRESS_INSIGHT );
		if ( ! self::$products || ! apply_filters( 'plugpress_insight_enabled', $on ) ) {
			return;
		}

		// A copy of the site (staging, a clone, a move) starts over: a new id,
		// and every question asked again.
		$state = self::state();
		if ( $state['home'] && self::home() !== $state['home'] ) {
			delete_option( self::OPTION );
			delete_option( self::SITE_ID );
			wp_clear_scheduled_hook( self::CRON );
		}

		add_action( self::CRON, array( __CLASS__, 'weekly' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
			// Priority 0: before hosts that fold notices away, such as Saddle.
			add_action( 'admin_notices', array( __CLASS__, 'bar' ), 0 );
			add_action( 'admin_footer', array( __CLASS__, 'dialog' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
			add_action( 'wp_ajax_plugpress_insight', array( __CLASS__, 'ajax' ) );
			add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		}
	}

	/**
	 * The owner's answers: site info shared (null until asked, once per site),
	 * who clicked Allow, the brands whose card was answered, a hash of the last
	 * weekly send, and the address all of it belongs to.
	 *
	 * @return array{usage:bool|null,by:int,asked:array,hash:string,home:string}
	 */
	public static function state() {
		$state          = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'usage' => null,
				'by'    => 0,
				'asked' => array(),
				'hash'  => '',
				'home'  => '',
			)
		);
		$state['asked'] = (array) $state['asked'];
		return $state;
	}

	/**
	 * Keep the weekly update scheduled while site info is shared.
	 */
	public static function schedule() {
		if ( true === self::state()['usage'] && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON );
		}
	}

	/**
	 * The card, once per brand, on the Plugins screen and on a registered
	 * plugin's own page. It asks for site info until that has an answer, then
	 * only for the brand's news. On the plugin's page with a slot, the script
	 * moves it into that element.
	 */
	public static function bar() {
		$plugin = self::product_on_screen();
		$state  = self::state();
		if ( ! $plugin || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ask   = null === $state['usage'];
		$brand = $plugin['brand'];
		$user  = wp_get_current_user();
		$color = sanitize_hex_color( $plugin['color'] );
		$slot  = self::on_own_page( $plugin ) ? $plugin['slot'] : '';
		?>
		<div class="notice <?php echo $slot ? 'inline ' : ''; ?>plugpress-insight-bar<?php echo $ask ? '' : ' is-news'; ?>" data-product="<?php echo esc_attr( $plugin['product'] ); ?>"
			<?php if ( $slot ) : ?>
				data-slot="<?php echo esc_attr( $slot ); ?>"
			<?php endif; ?>
			data-done="<?php echo esc_attr( $ask ? __( 'Thanks for helping', 'saddle' ) : __( 'You are in', 'saddle' ) ); ?>"
			data-confirm="<?php esc_attr_e( 'Check your inbox to confirm your email.', 'saddle' ); ?>"
			data-stop="<?php esc_attr_e( 'Stop any time under the plugin on the Plugins screen.', 'saddle' ); ?>"
			style="<?php echo esc_attr( $color ? '--plugpress-insight-accent:' . $color : '' ); ?>">
			<form>
				<div class="plugpress-insight-bar__main">
					<span class="plugpress-insight-bar__icon" aria-hidden="true">
						<?php if ( $ask ) : ?>
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 20v-7M12 20V5M19 20v-10"/></svg>
						<?php else : ?>
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7.5 8.5 6 8.5-6"/></svg>
						<?php endif; ?>
					</span>
					<div class="plugpress-insight-bar__text">
						<?php if ( $ask ) : ?>
							<strong><?php /* translators: %s: brand name, such as Saddle. */ echo esc_html( sprintf( __( 'Get better support for %s', 'saddle' ), $brand ) ); ?></strong>
							<span>
								<?php esc_html_e( 'Allow to share your name, email and basic site info with PlugPress. No posts, no visitor data.', 'saddle' ); ?>
								<button type="button" class="plugpress-insight-bar__what" aria-expanded="false"><?php esc_html_e( 'What is shared', 'saddle' ); ?></button>
							</span>
							<span class="plugpress-insight-bar__list" hidden>
								<span><b><?php esc_html_e( 'Your profile', 'saddle' ); ?></b> <?php echo esc_html( $user->display_name . ', ' . $user->user_email ); ?></span>
								<span><b><?php esc_html_e( 'Your site', 'saddle' ); ?></b> <?php esc_html_e( 'Address, title, language and timezone', 'saddle' ); ?></span>
								<span><b><?php esc_html_e( 'Versions', 'saddle' ); ?></b> <?php esc_html_e( 'WordPress, PHP, your theme and plugins', 'saddle' ); ?></span>
								<span><?php esc_html_e( 'Sent now, then once a week only when something changed. Stop any time under the plugin on the Plugins screen.', 'saddle' ); ?></span>
							</span>
						<?php else : ?>
							<strong><?php /* translators: %s: brand name, such as Saddle. */ echo esc_html( sprintf( __( '%s news', 'saddle' ), $brand ) ); ?></strong>
							<span><?php esc_html_e( 'Features, tips and fixes, now and then.', 'saddle' ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( $ask ) : ?>
						<button type="button" class="plugpress-insight-bar__skip"><?php esc_html_e( 'No thanks', 'saddle' ); ?></button>
						<button type="submit"><?php esc_html_e( 'Allow', 'saddle' ); ?></button>
					<?php else : ?>
						<input type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" aria-label="<?php esc_attr_e( 'Email', 'saddle' ); ?>">
						<button type="submit"><?php esc_html_e( 'Subscribe', 'saddle' ); ?></button>
						<button type="button" class="plugpress-insight-bar__skip plugpress-insight-bar__close" aria-label="<?php esc_attr_e( 'No thanks', 'saddle' ); ?>">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
						</button>
					<?php endif; ?>
				</div>
				<?php if ( $ask ) : ?>
					<div class="plugpress-insight-bar__more">
						<label>
							<input type="checkbox" name="news">
							<?php /* translators: %s: brand name, such as Saddle. */ echo esc_html( sprintf( __( 'Also email me %s news at', 'saddle' ), $brand ) ); ?>
						</label>
						<input type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" aria-label="<?php esc_attr_e( 'Email', 'saddle' ); ?>">
					</div>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Quick feedback, printed once on the Plugins screen, before the footer
	 * scripts. The script opens it from a registered plugin's Deactivate link.
	 */
	public static function dialog() {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$email = wp_get_current_user()->user_email;
		// Reason => its label, and the question it opens ('' for none).
		$reasons = array(
			'short_time'        => array( __( 'I only needed it for a short time', 'saddle' ), '' ),
			'broke_something'   => array( __( 'It broke something on my site', 'saddle' ), __( 'What broke? An error message helps a lot', 'saddle' ) ),
			'hard_to_set_up'    => array( __( 'It was hard to set up', 'saddle' ), __( 'Where did you get stuck?', 'saddle' ) ),
			'not_what_i_needed' => array( __( 'It didn\'t do what I needed', 'saddle' ), __( 'What were you hoping it would do?', 'saddle' ) ),
			'found_another'     => array( __( 'I found a better plugin', 'saddle' ), __( 'Which one? We would love to learn from it', 'saddle' ) ),
			'temporary'         => array( __( 'It\'s temporary, I\'m troubleshooting', 'saddle' ), '' ),
			'other'             => array( __( 'Something else', 'saddle' ), __( 'Tell us a little more', 'saddle' ) ),
		);
		?>
		<dialog class="plugpress-insight-dialog" aria-labelledby="plugpress-insight-title">
			<form method="dialog">
				<button type="button" class="plugpress-insight-dialog__close" aria-label="<?php esc_attr_e( 'Close, and keep the plugin on', 'saddle' ); ?>">&times;</button>
				<p class="plugpress-insight-dialog__eyebrow"><?php esc_html_e( 'Quick feedback', 'saddle' ); ?></p>
				<h2 id="plugpress-insight-title" data-title="<?php /* translators: %s: plugin name. */ esc_attr_e( 'Why are you turning off %s?', 'saddle' ); ?>"></h2>
				<div class="plugpress-insight-dialog__reasons" role="radiogroup" aria-labelledby="plugpress-insight-title">
					<?php foreach ( $reasons as $id => $reason ) : ?>
						<label><input type="radio" name="reason" value="<?php echo esc_attr( $id ); ?>"> <?php echo esc_html( $reason[0] ); ?></label>
						<?php if ( $reason[1] ) : ?>
							<input type="text" data-for="<?php echo esc_attr( $id ); ?>" maxlength="500" placeholder="<?php echo esc_attr( $reason[1] ); ?>" aria-label="<?php echo esc_attr( $reason[1] ); ?>" hidden>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<label class="plugpress-insight-dialog__reply">
					<input type="checkbox" name="reply" value="<?php echo esc_attr( $email ); ?>">
					<?php /* translators: %s: the admin's email address. */ echo esc_html( sprintf( __( 'Reply to me at %s', 'saddle' ), $email ) ); ?>
				</label>
				<div class="plugpress-insight-dialog__foot">
					<button type="button" class="plugpress-insight-dialog__skip"><?php esc_html_e( 'Skip and deactivate', 'saddle' ); ?></button>
					<button type="submit" class="button button-primary" disabled><?php esc_html_e( 'Send and deactivate', 'saddle' ); ?></button>
				</div>
				<p class="plugpress-insight-dialog__note"><?php esc_html_e( 'Sends your answer, the plugin\'s version, and your WordPress and PHP versions.', 'saddle' ); ?></p>
			</form>
		</dialog>
		<?php
	}

	/**
	 * The script and styles, where the card or the dialog can show.
	 */
	public static function assets() {
		$screen  = get_current_screen();
		$plugins = $screen && 'plugins' === $screen->id && current_user_can( 'activate_plugins' );
		if ( ! $plugins && ! self::product_on_screen() ) {
			return;
		}
		$file    = $GLOBALS['plugpress_insight_sdk']['dir'] . '/class-plugpress-insight.php';
		$version = $GLOBALS['plugpress_insight_sdk']['version'];
		// The handle is not the text domain, so renaming the domain leaves it.
		wp_enqueue_style( 'plugpress-insight-sdk', plugins_url( 'insight.css', $file ), array(), $version );
		wp_enqueue_script( 'plugpress-insight-sdk', plugins_url( 'insight.js', $file ), array(), $version, true );

		// Plugin file => slug and name, for the Deactivate links.
		$deactivate = array();
		foreach ( $plugins ? self::$products : array() as $plugin ) {
			$deactivate[ $plugin['basename'] ] = array( $plugin['slug'], $plugin['name'] );
		}
		$config = array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'plugpress_insight' ),
			'plugins' => $deactivate,
		);
		wp_add_inline_script( 'plugpress-insight-sdk', 'var plugpressInsight = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * "Stop sharing site info" under each registered plugin while it is shared.
	 *
	 * @param array  $meta     The row's links.
	 * @param string $basename The row's plugin file.
	 * @return array
	 */
	public static function row_meta( $meta, $basename ) {
		if ( in_array( $basename, wp_list_pluck( self::$products, 'basename' ), true ) && true === self::state()['usage'] && current_user_can( 'manage_options' ) ) {
			$meta[] = '<a href="#" data-plugpress-insight-off="' . esc_attr__( 'Sharing stopped', 'saddle' ) . '">' . esc_html__( 'Stop sharing site info', 'saddle' ) . '</a>';
		}
		return $meta;
	}

	/**
	 * Every click from the script: allow, subscribe, skip, off and deactivate.
	 */
	public static function ajax() {
		check_ajax_referer( 'plugpress_insight' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$do      = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$slug    = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';
		$brand   = isset( $_POST['product'] ) ? sanitize_key( $_POST['product'] ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$reason  = isset( $_POST['reason'] ) ? sanitize_key( $_POST['reason'] ) : '';
		$comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';
		// phpcs:enable
		$reasons = array( 'short_time', 'broke_something', 'hard_to_set_up', 'not_what_i_needed', 'found_another', 'temporary', 'other' );
		$email   = is_email( $email ) ? $email : '';

		if ( 'deactivate' === $do ) {
			$plugin = isset( self::$products[ $slug ] ) ? self::$products[ $slug ] : null;
			if ( ! $plugin || ! in_array( $reason, $reasons, true ) || ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error( null, 400 );
			}
			self::send(
				'deactivate',
				array(
					'site'    => self::site( false ),
					'product' => array(
						'slug'    => $plugin['product'],
						'version' => $plugin['version'],
						'plugin'  => $plugin['slug'],
						// Another plugin of the brand stays on.
						'active'  => count( wp_list_filter( self::$products, array( 'product' => $plugin['product'] ) ) ) > 1,
					),
					'reason'  => $reason,
					'comment' => mb_substr( $comment, 0, 500 ),
					'email'   => $email,
				)
			);
			wp_send_json_success();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( 'off' === $do ) {
			if ( true === self::state()['usage'] ) {
				self::send(
					'optout',
					array(
						'site'     => array( 'id' => self::site_id() ),
						'products' => array_keys( self::brands() ),
					)
				);
			}
			self::remember( array( 'usage' => false ) );
			wp_clear_scheduled_hook( self::CRON );
			wp_send_json_success();
		}

		if ( ! isset( self::brands()[ $brand ] ) ) {
			wp_send_json_error( null, 400 );
		}
		$news   = array(
			'product' => $brand,
			'email'   => $email,
		);
		$answer = array( 'asked' => array( $brand => time() ) + self::state()['asked'] );
		if ( 'allow' === $do ) {
			self::remember(
				$answer + array(
					'usage' => true,
					'by'    => get_current_user_id(),
				)
			);
			self::send( 'optin', self::body() + ( $email ? array( 'news' => $news ) : array() ) );
			self::schedule();
		} elseif ( 'subscribe' === $do && $email ) {
			self::send( 'news', $news );
			self::remember( $answer );
		} elseif ( 'skip' === $do ) {
			// No thanks to site info holds for every brand; to news, for this one.
			self::remember( $answer + ( null === self::state()['usage'] ? array( 'usage' => false ) : array() ) );
		}
		wp_send_json_success();
	}

	/**
	 * The weekly update: only while site info is shared, only when it changed.
	 */
	public static function weekly() {
		$state = self::state();
		if ( true !== $state['usage'] ) {
			wp_clear_scheduled_hook( self::CRON );
			return;
		}
		$body = self::body();
		$hash = md5( (string) wp_json_encode( $body ) );
		if ( $hash !== $state['hash'] && self::send( 'update', $body ) ) {
			self::remember( array( 'hash' => $hash ) );
		}
	}

	/**
	 * The opt-in and weekly body: the site, and each brand once.
	 *
	 * @return array
	 */
	private static function body() {
		$products = array();
		foreach ( self::brands() as $brand => $version ) {
			$products[] = array(
				'slug'    => $brand,
				'version' => $version,
				'active'  => true,
			);
		}
		return array(
			'site'     => self::site( true ),
			'products' => $products,
		);
	}

	/**
	 * Each brand on the site with its version: the version of its plugin of
	 * the same slug (Saddle's for saddle), else of its first plugin.
	 *
	 * @return array Brand => version.
	 */
	private static function brands() {
		$brands = array();
		foreach ( self::$products as $plugin ) {
			if ( ! isset( $brands[ $plugin['product'] ] ) || $plugin['slug'] === $plugin['product'] ) {
				$brands[ $plugin['product'] ] = $plugin['version'];
			}
		}
		return $brands;
	}

	/**
	 * The site block: versions and language, plus with $full the address,
	 * title, timezone, theme and plugins.
	 *
	 * @param bool $full False for a deactivation.
	 * @return array
	 */
	private static function site( $full ) {
		$site = array(
			'id'          => self::site_id(),
			'wp'          => (string) get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'language'    => get_locale(),
			'multisite'   => is_multisite(),
			'environment' => wp_get_environment_type(),
		);
		if ( ! $full ) {
			return $site;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theme            = wp_get_theme();
		$site['timezone'] = wp_timezone_string();
		$site['url']      = home_url( '/' );
		$site['title']    = get_bloginfo( 'name' );
		$site['theme']    = array(
			'slug'    => $theme->get_stylesheet(),
			'version' => (string) $theme->get( 'Version' ),
		);
		$site['plugins']  = array();
		foreach ( array_slice( get_plugins(), 0, 200, true ) as $file => $data ) {
			$site['plugins'][] = array(
				'slug'    => false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ),
				'version' => (string) $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		// The person who clicked Allow, for support. Gone if they are.
		$by = get_userdata( (int) self::state()['by'] );
		if ( $by ) {
			$site['admin'] = array(
				'name'  => $by->display_name,
				'email' => $by->user_email,
			);
		}
		return $site;
	}

	/**
	 * The site's random id, made once and shared by every PlugPress plugin.
	 *
	 * @return string
	 */
	private static function site_id() {
		$id = get_option( self::SITE_ID );
		if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) {
			$id = wp_generate_uuid4();
			update_option( self::SITE_ID, $id, false );
		}
		return $id;
	}

	/**
	 * The site's address as stored, without the scheme or www. The stored
	 * option, not home_url(), which language plugins change per request.
	 *
	 * @return string
	 */
	private static function home() {
		return strtolower( untrailingslashit( (string) preg_replace( '#^https?://(www\.)?#i', '', (string) get_option( 'home' ) ) ) );
	}

	/**
	 * The registered plugin whose card belongs on this screen, or null: on the
	 * Plugins screen the first brand not yet asked, on a plugin's own page
	 * that plugin's brand if not yet asked.
	 *
	 * @return array|null
	 */
	private static function product_on_screen() {
		$screen  = get_current_screen();
		$plugins = $screen && 'plugins' === $screen->id;
		$asked   = self::state()['asked'];
		foreach ( self::$products as $plugin ) {
			if ( ! isset( $asked[ $plugin['product'] ] ) && ( $plugins || self::on_own_page( $plugin ) ) ) {
				return $plugin;
			}
		}
		return null;
	}

	/**
	 * Whether this is the plugin's own page (?page=).
	 *
	 * @param array $plugin A registered plugin.
	 * @return bool
	 */
	private static function on_own_page( $plugin ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which page, not an action.
		return $plugin['page'] && $page === $plugin['page'];
	}

	/**
	 * Store answers over the ones already given, with the site's address.
	 *
	 * @param array $changes Any of usage, by, asked and hash.
	 */
	private static function remember( array $changes ) {
		update_option( self::OPTION, array_merge( self::state(), $changes, array( 'home' => self::home() ) ) );
	}

	/**
	 * One call to insight.plugpress.io: 5 seconds at most, never retried.
	 *
	 * @param string $route optin, update, deactivate, optout or news.
	 * @param array  $body  The JSON body.
	 * @return bool Whether it answered 2xx.
	 */
	private static function send( $route, array $body ) {
		$url      = defined( 'PLUGPRESS_INSIGHT_URL' ) ? PLUGPRESS_INSIGHT_URL : self::URL;
		$response = wp_remote_post(
			$url . $route,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
				'user-agent'  => 'PlugPress-Insight/' . $GLOBALS['plugpress_insight_sdk']['version'],
			)
		);
		$code     = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
