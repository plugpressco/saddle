<?php
/**
 * PlugPress Insight: a news bar, quick feedback on deactivate, and a weekly
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
	 * The plugins that called plugpress_insight(), by slug.
	 *
	 * @var array
	 */
	private static $products = array();

	/**
	 * Hook in once for every registered plugin.
	 *
	 * @param array $products What each plugin passed to plugpress_insight().
	 */
	public static function start( array $products ) {
		foreach ( $products as $product ) {
			if ( empty( $product['file'] ) || empty( $product['slug'] ) ) {
				continue;
			}
			$product += array(
				'name'    => $product['slug'],
				'version' => '0',
				'page'    => '',
				'news'    => 'plugpress',
				'color'   => '',
				'slot'    => '',
			);

			$product['basename']                = plugin_basename( $product['file'] );
			self::$products[ $product['slug'] ] = $product;
		}

		$on = ! ( defined( 'PLUGPRESS_INSIGHT' ) && false === PLUGPRESS_INSIGHT );
		if ( ! self::$products || ! apply_filters( 'plugpress_insight_enabled', $on ) ) {
			return;
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
	 * The owner's answer: when (0 = not yet), whether site info is shared,
	 * and a hash of the last weekly send.
	 *
	 * @return array{answered:int,usage:bool,hash:string}
	 */
	public static function state() {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'answered' => 0,
				'usage'    => false,
				'hash'     => '',
			)
		);
	}

	/**
	 * Keep the weekly update scheduled while site info is shared.
	 */
	public static function schedule() {
		if ( self::state()['usage'] && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON );
		}
	}

	/**
	 * The news card, once per site, on a registered plugin's page (the Plugins
	 * screen for a plugin with no page). With a slot, the script moves it into
	 * that element of the plugin's own page.
	 */
	public static function bar() {
		$product = self::product_on_screen();
		if ( ! $product || self::state()['answered'] || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$color = sanitize_hex_color( $product['color'] );
		?>
		<div class="notice <?php echo $product['slot'] ? 'inline ' : ''; ?>plugpress-insight-bar" data-slug="<?php echo esc_attr( $product['slug'] ); ?>"
			<?php if ( $product['slot'] ) : ?>
				data-slot="<?php echo esc_attr( $product['slot'] ); ?>"
			<?php endif; ?>
			data-done="<?php esc_attr_e( 'You are in', 'saddle' ); ?>"
			data-thanks="<?php esc_attr_e( 'Check your inbox to confirm your email.', 'saddle' ); ?>"
			data-shared="<?php esc_attr_e( 'Thanks for helping us test.', 'saddle' ); ?>"
			style="<?php echo esc_attr( $color ? '--plugpress-insight-accent:' . $color : '' ); ?>">
			<div class="plugpress-insight-bar__main">
				<span class="plugpress-insight-bar__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7.5 8.5 6 8.5-6"/></svg>
				</span>
				<p class="plugpress-insight-bar__text">
					<strong><?php /* translators: %s: plugin name. */ echo esc_html( sprintf( __( 'News from %s', 'saddle' ), $product['name'] ) ); ?></strong>
					<span><?php esc_html_e( 'Features, tips and fixes, now and then.', 'saddle' ); ?></span>
				</p>
				<form>
					<input type="email" name="email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" aria-label="<?php esc_attr_e( 'Email', 'saddle' ); ?>">
					<button type="submit"><?php esc_html_e( 'Subscribe', 'saddle' ); ?></button>
				</form>
				<button type="button" class="plugpress-insight-bar__close" aria-label="<?php esc_attr_e( 'No thanks', 'saddle' ); ?>">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
				</button>
			</div>
			<div class="plugpress-insight-bar__more">
				<label><input type="checkbox" name="usage" checked> <?php esc_html_e( 'Also share basic site info to help us test', 'saddle' ); ?></label>
				<details>
					<summary><?php esc_html_e( 'What is shared', 'saddle' ); ?></summary>
					<p><?php esc_html_e( 'Your site address and title, WordPress and PHP versions, language, timezone, theme, and plugins with their versions, once a week while the box is ticked. Your email goes only to our newsletter, which asks you to confirm it.', 'saddle' ); ?></p>
				</details>
			</div>
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
	 * The script and styles, where the bar or the dialog can show.
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
		foreach ( $plugins ? self::$products : array() as $product ) {
			$deactivate[ $product['basename'] ] = array( $product['slug'], $product['name'] );
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
		if ( in_array( $basename, wp_list_pluck( self::$products, 'basename' ), true ) && self::state()['usage'] && current_user_can( 'manage_options' ) ) {
			$meta[] = '<a href="#" data-plugpress-insight-off="' . esc_attr__( 'Sharing stopped', 'saddle' ) . '">' . esc_html__( 'Stop sharing site info', 'saddle' ) . '</a>';
		}
		return $meta;
	}

	/**
	 * Every click from the script: allow, skip, off and deactivate.
	 */
	public static function ajax() {
		check_ajax_referer( 'plugpress_insight' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$do      = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$slug    = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$reason  = isset( $_POST['reason'] ) ? sanitize_key( $_POST['reason'] ) : '';
		$comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';
		$usage   = isset( $_POST['usage'] ) && '1' === $_POST['usage'];
		// phpcs:enable
		$product = isset( self::$products[ $slug ] ) ? self::$products[ $slug ] : null;
		$reasons = array( 'short_time', 'broke_something', 'hard_to_set_up', 'not_what_i_needed', 'found_another', 'temporary', 'other' );

		if ( 'deactivate' === $do ) {
			if ( ! $product || ! in_array( $reason, $reasons, true ) || ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error( null, 400 );
			}
			self::send(
				'deactivate',
				array(
					'site'    => self::site( false ),
					'product' => array(
						'slug'    => $product['slug'],
						'version' => $product['version'],
					),
					'reason'  => $reason,
					'comment' => mb_substr( $comment, 0, 500 ),
					'email'   => is_email( $email ) ? $email : '',
				)
			);
			wp_send_json_success();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( 'allow' === $do ) {
			$news = $product && is_email( $email ) ? array(
				'list'  => $product['news'],
				'email' => $email,
			) : null;
			if ( $usage ) {
				self::send( 'optin', array_filter( self::body() + array( 'news' => $news ) ) );
			} elseif ( $news ) {
				self::send( 'news', $news );
			}
			self::save( $usage );
			self::schedule();
		} elseif ( 'skip' === $do ) {
			self::save( false );
		} elseif ( 'off' === $do ) {
			if ( self::state()['usage'] ) {
				self::send(
					'optout',
					array(
						'site'     => array( 'id' => self::site_id() ),
						'products' => array_keys( self::$products ),
					)
				);
			}
			self::save( false );
			wp_clear_scheduled_hook( self::CRON );
		}
		wp_send_json_success();
	}

	/**
	 * The weekly update: only while site info is shared, only when it changed.
	 */
	public static function weekly() {
		$state = self::state();
		if ( ! $state['usage'] ) {
			wp_clear_scheduled_hook( self::CRON );
			return;
		}
		$body = self::body();
		$hash = md5( (string) wp_json_encode( $body ) );
		if ( $hash !== $state['hash'] && self::send( 'update', $body ) ) {
			self::save( true, $hash );
		}
	}

	/**
	 * The opt-in and weekly body: the site and every registered plugin.
	 *
	 * @return array
	 */
	private static function body() {
		$products = array();
		foreach ( self::$products as $product ) {
			$products[] = array(
				'slug'    => $product['slug'],
				'version' => $product['version'],
				'active'  => true,
			);
		}
		return array(
			'site'     => self::site( true ),
			'products' => $products,
		);
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
	 * The registered plugin whose bar belongs on this screen, or null.
	 *
	 * @return array|null
	 */
	private static function product_on_screen() {
		$screen = get_current_screen();
		$page   = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which page, not an action.
		foreach ( self::$products as $product ) {
			if ( $product['page'] ? $page === $product['page'] : ( $screen && 'plugins' === $screen->id ) ) {
				return $product;
			}
		}
		return null;
	}

	/**
	 * Store the answer.
	 *
	 * @param bool   $usage Whether site info is shared.
	 * @param string $hash  The last weekly send's hash.
	 */
	private static function save( $usage, $hash = '' ) {
		update_option(
			self::OPTION,
			array(
				'answered' => time(),
				'usage'    => (bool) $usage,
				'hash'     => $hash,
			)
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
