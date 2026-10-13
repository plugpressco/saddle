<?php
/**
 * What each tool may do: Allow, Ask first or Off.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every saddle/* tool has one of three modes, set by the owner for the whole
 * site (#364, product plan Phase 2):
 *
 * - **Allow**, the default: the tool runs as its app's role allows.
 * - **Ask first**: the call returns a preview and waits under Needs your OK.
 *   It runs only after the owner approves it there; a "yes" in the chat is not
 *   enough. Offered only for tools that change something.
 * - **Off**: the tool is refused for every app. This is the existing
 *   single-tool switch (`Saddle_Capabilities::DISABLED_OPTION`).
 *
 * The app's role stays the ceiling; a mode only narrows it.
 *
 * Ask first is enforced the way rehearsal is (`Saddle_Rehearsal`): at
 * registration, a wrapper around each changing tool's execute callback, so it
 * covers MCP, the REST abilities endpoint and direct calls, and Saddle Pro's
 * and the integrations' tools too. Until the owner approves, the tool's own
 * code never runs. A tool that previews by itself (delete-post and the other
 * destructive ones) would otherwise run without asking on the calls where it
 * skips its own gate, such as removing a single block.
 *
 * New tools arrive as Ask first (R3): a changing tool that did not exist when
 * this shipped, or when the site first ran it, asks until the owner chooses a
 * mode for it. `KNOWN_OPTION` is that first list.
 */
class Saddle_Tool_Modes {

	const ALLOW = 'allow';
	const ASK   = 'ask';
	const OFF   = 'off';

	/**
	 * The tools the owner set to Ask first. Small, read on every changing
	 * call, so autoloaded.
	 */
	const ASK_OPTION = 'saddle_ask_abilities';

	/**
	 * The changing tools the owner has had a say on: every one that existed
	 * the first time this ran, plus each one they set a mode for since. A
	 * changing tool missing from it is new, and asks first. Not autoloaded.
	 */
	const KNOWN_OPTION = 'saddle_known_abilities';

	/**
	 * The gate action an Ask-first request is stored under: `ask:<tool>`.
	 */
	const ACTION_PREFIX = 'ask:';

	/**
	 * Destructive tools the recommended set leaves at Allow: they change one
	 * part of a page, and the page's own undo puts it back.
	 */
	const IN_PAGE = array( 'remove-block', 'divi-remove-module', 'divi-swap-image' );

	/**
	 * Hook the registration filter. Priority 5, before rehearsal's 10, so
	 * rehearsal wraps this one: with rehearsal on, nothing asks the owner to
	 * approve a change that would not be saved anyway.
	 */
	public static function init() {
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap' ), 5, 2 );
		add_action( 'admin_init', array( __CLASS__, 'remember_known' ) );
	}

	/**
	 * Wrap a changing saddle/* tool's execute callback, and give a tool that
	 * asks first a `confirm_token` input if it has none.
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

		$short = substr( (string) $name, strlen( 'saddle/' ) );

		// The schema is built once per request, so it can follow the mode. The
		// mode itself is read again on every call.
		if ( self::asks_first_raw( $short ) && isset( $args['input_schema']['properties'] ) && is_array( $args['input_schema']['properties'] )
			&& ! isset( $args['input_schema']['properties']['confirm_token'] ) ) {
			$args['input_schema']['properties']['confirm_token'] = array(
				'type'        => 'string',
				'description' => __( 'Only after the site owner approved this request: the token its preview returned.', 'saddle' ),
			);
		}

		$inner                    = $args['execute_callback'];
		$args['execute_callback'] = static function () use ( $inner, $short ) {
			return self::run( $short, $inner, func_get_args() );
		};
		return $args;
	}

	/**
	 * Run a call, or hold it for the owner when its tool asks first.
	 *
	 * @param string   $short Tool name without the namespace.
	 * @param callable $inner The tool's own execute callback.
	 * @param array    $call  The arguments it was called with.
	 * @return mixed
	 */
	public static function run( $short, $inner, array $call ) {
		// The owner's own browser session is never asked.
		if ( ! self::asks_first( $short ) || '' === Saddle_Approval::connection() ) {
			return call_user_func_array( $inner, $call );
		}

		$input = ( $call && is_array( $call[0] ) ) ? $call[0] : array();
		$token = ( isset( $input['confirm_token'] ) && is_string( $input['confirm_token'] ) ) ? trim( $input['confirm_token'] ) : '';

		// A live token from this tool's own preview goes to the tool: its gate
		// checks that the owner approved it before it runs anything. Any other
		// token (spent, made up, or another tool's) goes to the gate below,
		// which refuses it, so it can never reach the tool's code.
		if ( '' !== $token ) {
			$action = Saddle_Approval::token_action( $token );
			if ( '' !== $action && 0 !== strpos( $action, self::ACTION_PREFIX ) && Saddle_Approval::token_tool( $token ) === $short ) {
				return call_user_func_array( $inner, $call );
			}
		}

		$clean = $input;
		unset( $clean['confirm_token'] );
		$payload = $clean;
		ksort( $payload );

		return Saddle_Approval::gate(
			array(
				'action'  => self::ACTION_PREFIX . $short,
				'tool'    => $short,
				'target'  => (string) Saddle_Rehearsal::target_id( $clean ),
				'bind'    => hash( 'sha256', (string) wp_json_encode( $payload ) ),
				'summary' => self::summary( $short, $clean ),
				'preview' => $clean,
				'input'   => $input,
				// The tool logs its own change, and the owner's approval is
				// logged when it is given.
				'log'     => false,
				'execute' => static function () use ( $inner, $short, $clean ) {
					return Saddle_Approval::run_approved(
						$short,
						static function () use ( $inner, $clean ) {
							return call_user_func( $inner, $clean );
						}
					);
				},
			)
		);
	}

	/**
	 * A tool's mode right now.
	 *
	 * @param string $short Tool name without the namespace.
	 * @return string One of the ALLOW, ASK, OFF constants.
	 */
	public static function mode( $short ) {
		if ( ! Saddle_Capabilities::is_ability_enabled( $short ) ) {
			return self::OFF;
		}
		return self::asks_first( $short ) ? self::ASK : self::ALLOW;
	}

	/**
	 * Whether a tool asks the owner first: the owner set it so, or it is new.
	 *
	 * @param string $short Tool name without the namespace.
	 * @return bool
	 */
	public static function asks_first( $short ) {
		if ( ! self::is_askable( $short ) ) {
			return false;
		}
		return in_array( $short, self::ask_list(), true ) || self::is_new( $short );
	}

	/**
	 * Whether a changing tool arrived after the owner last had a say on the
	 * list, so it asks first until they choose (R3).
	 *
	 * @param string $short Tool name without the namespace.
	 * @return bool
	 */
	public static function is_new( $short ) {
		$known = get_option( self::KNOWN_OPTION, false );
		if ( ! is_array( $known ) ) {
			// The first time: everything that exists now keeps what it does.
			self::remember_known();
			return false;
		}
		return self::is_askable( $short ) && ! in_array( $short, $known, true );
	}

	/**
	 * Whether a tool can ask first: it is registered and changes something.
	 *
	 * @param string $short Tool name without the namespace.
	 * @return bool
	 */
	public static function is_askable( $short ) {
		$name = 'saddle/' . $short;
		if ( ! function_exists( 'wp_get_abilities' ) || ! isset( wp_get_abilities()[ $name ] ) ) {
			return false;
		}
		$meta = wp_get_ability( $name )->get_meta();
		return empty( $meta['annotations']['readonly'] );
	}

	/**
	 * The tools the owner set to Ask first.
	 *
	 * @return string[]
	 */
	public static function ask_list() {
		$stored = get_option( self::ASK_OPTION, array() );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * Set one tool's mode. Only the owner does this, in wp-admin; no tool can
	 * (`Saddle_Settings_Guard`).
	 *
	 * @param string $short Tool name without the namespace.
	 * @param string $mode  One of the ALLOW, ASK, OFF constants.
	 * @return string|WP_Error The tool's mode afterwards.
	 */
	public static function set_mode( $short, $mode ) {
		$short = sanitize_key( (string) $short );
		$name  = 'saddle/' . $short;
		if ( '' === $short || ! function_exists( 'wp_get_abilities' ) || ! isset( wp_get_abilities()[ $name ] ) ) {
			return new WP_Error( 'saddle_unknown_tool', __( 'There is no tool by that name on this site.', 'saddle' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $mode, array( self::ALLOW, self::ASK, self::OFF ), true ) ) {
			return new WP_Error( 'saddle_bad_mode', __( 'A tool can be set to Allow, Ask first or Off.', 'saddle' ), array( 'status' => 400 ) );
		}
		if ( self::ASK === $mode && ! self::is_askable( $short ) ) {
			return new WP_Error( 'saddle_bad_mode', __( 'This tool only reads, so there is nothing to ask about. It can be Allow or Off.', 'saddle' ), array( 'status' => 400 ) );
		}

		$off = Saddle_Capabilities::disabled_abilities();
		$off = self::OFF === $mode ? array_merge( $off, array( $short ) ) : array_diff( $off, array( $short ) );
		Saddle_Capabilities::set_disabled_abilities( array_values( array_unique( $off ) ) );

		$ask = self::ask_list();
		$ask = self::ASK === $mode ? array_merge( $ask, array( $short ) ) : array_diff( $ask, array( $short ) );
		update_option( self::ASK_OPTION, array_values( array_unique( $ask ) ), true );

		if ( self::is_askable( $short ) ) {
			self::remember_known( array( $short ) );
		}

		return self::mode( $short );
	}

	/**
	 * Record the changing tools that exist now as known, the first time, and
	 * add `$more` to the list after that.
	 *
	 * @param string[] $more Tools the owner just chose a mode for.
	 */
	public static function remember_known( $more = array() ) {
		$known = get_option( self::KNOWN_OPTION, false );
		if ( ! is_array( $known ) ) {
			$known = array_keys( array_filter( self::changing_tools() ) );
		}
		$next = array_values( array_unique( array_merge( $known, is_array( $more ) ? $more : array() ) ) );
		if ( false === get_option( self::KNOWN_OPTION, false ) || $next !== $known ) {
			update_option( self::KNOWN_OPTION, $next, false );
		}
	}

	/**
	 * The recommended set: Ask first for what changes the site rather than
	 * one page's content (every Manage-the-site tool that changes something),
	 * and for what deletes or overwrites, except the in-page edits.
	 *
	 * @return string[] Tool names without the namespace.
	 */
	public static function recommended() {
		$tools = array();
		foreach ( self::changing_tools() as $short => $meta ) {
			$admin       = isset( $meta['saddle']['tier'] ) && 'admin' === $meta['saddle']['tier'];
			$destructive = ! empty( $meta['annotations']['destructive'] );
			if ( $admin || ( $destructive && ! in_array( $short, self::IN_PAGE, true ) ) ) {
				$tools[] = $short;
			}
		}
		sort( $tools );
		return $tools;
	}

	/**
	 * Apply the recommended set. It only tightens: a tool at Allow moves to
	 * Ask first, a tool already Off stays Off, and "Publishing needs my OK"
	 * is turned on.
	 *
	 * @return string[] The tools it moved to Ask first.
	 */
	public static function apply_recommended() {
		$moved = array();
		foreach ( self::recommended() as $short ) {
			if ( self::ALLOW === self::mode( $short ) ) {
				self::set_mode( $short, self::ASK );
				$moved[] = $short;
			}
		}
		Saddle_Capabilities::set_drafts_only( true );
		return $moved;
	}

	/**
	 * The context line that tells an app which of its tools ask the owner
	 * first, or '' when none do. Names only tools it can call now, at most
	 * ten, to stay inside the budget every session pays.
	 *
	 * @return string
	 */
	public static function context_line() {
		$asking = array();
		foreach ( array_keys( self::changing_tools() ) as $short ) {
			if ( self::asks_first( $short ) && Saddle_Capabilities::is_callable_now( 'saddle/' . $short ) ) {
				$asking[] = 'saddle-' . $short;
			}
		}
		if ( ! $asking ) {
			return '';
		}
		sort( $asking );

		$names = implode( ', ', array_slice( $asking, 0, 10 ) );
		if ( count( $asking ) > 10 ) {
			/* translators: 1: comma-separated tool names, 2: how many more. */
			$names = sprintf( __( '%1$s and %2$d more', 'saddle' ), $names, count( $asking ) - 10 );
		}

		return sprintf(
			/* translators: %s: tool names, such as "saddle-delete-post, saddle-update-plugin". */
			__( 'These tools wait for the site owner’s OK in wp-admin before they run: %s. Call them as usual. When a result says "needs_owner_approval", tell the user it is waiting under Needs your OK, and call again with the token only after they approve.', 'saddle' ),
			$names
		);
	}

	/**
	 * Every changing saddle/* tool, with its meta.
	 *
	 * @return array<string, array> Tool name without the namespace => meta.
	 */
	private static function changing_tools() {
		$tools = array();
		foreach ( function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array() as $key => $ability ) {
			$name = is_string( $key ) ? $key : $ability->get_name();
			$meta = $ability->get_meta();
			if ( 0 === strpos( $name, 'saddle/' ) && empty( $meta['annotations']['readonly'] ) ) {
				$tools[ substr( $name, strlen( 'saddle/' ) ) ] = $meta;
			}
		}
		return $tools;
	}

	/**
	 * Whether a tool asks first, from the stored options alone. For the
	 * registration filter, which runs while abilities are being registered
	 * and so cannot look them up.
	 *
	 * @param string $short Tool name without the namespace.
	 * @return bool
	 */
	private static function asks_first_raw( $short ) {
		if ( in_array( $short, self::ask_list(), true ) ) {
			return true;
		}
		$known = get_option( self::KNOWN_OPTION, false );
		return is_array( $known ) && ! in_array( $short, $known, true );
	}

	/**
	 * What the owner reads under Needs your OK, and in the log once decided:
	 * "Create post", "Update page #12 “About”".
	 *
	 * @param string $short Tool name without the namespace.
	 * @param array  $input The call, without its token.
	 * @return string
	 */
	private static function summary( $short, array $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'saddle/' . $short ) : null;
		$label   = $ability ? $ability->get_label() : $short;
		$id      = Saddle_Rehearsal::target_id( $input );
		$post    = $id ? get_post( $id ) : null;

		if ( $post && current_user_can( 'read_post', $id ) && '' !== $post->post_title ) {
			/* translators: 1: tool name, such as Update page, 2: id, 3: title. */
			return sprintf( __( '%1$s #%2$d “%3$s”', 'saddle' ), $label, $id, $post->post_title );
		}
		if ( $id ) {
			/* translators: 1: tool name, such as Update page, 2: id. */
			return sprintf( __( '%1$s #%2$d', 'saddle' ), $label, $id );
		}
		return $label;
	}
}
