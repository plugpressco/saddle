<?php
/**
 * First-party integrations — PlugPress plugins' abilities, wrapped in
 * Saddle's safety model.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Re-exposes a first-party PlugPress plugin's Abilities-API tools through
 * Saddle's MCP server, each wrapped in the full Saddle safety model.
 *
 * Saddle's server deliberately serves only `saddle/` abilities, and its
 * credentials are scoped to Saddle's endpoint — so the ONLY way an agent
 * reaches a partner plugin's tools is through these wrappers, which add
 * what the source plugins don't have:
 *
 *   - tier enforcement (readonly source → read tier, writes → write tier)
 *   - the pause switch and Saddle's per-tool toggles
 *   - the approval gate on destructive operations (preview → confirm token)
 *   - the activity log on every mutation
 *
 * The source ability keeps its own permission_callback — core re-checks it
 * inside execute(), so a wrapper can never grant MORE than the plugin
 * itself allows; Saddle's layers only ever narrow.
 *
 * First-party PlugPress namespaces integrate FREE (decided 2026-07): the
 * catalog below is the free tier's list; Saddle Pro adds third-party
 * integrations through its own engine. A wrapped ability appears everywhere
 * a native one does (MCP tools, Permissions UI lanes, per-tool toggles)
 * with zero extra wiring, because it IS a `saddle/` ability:
 * `waggle/get-aeo-score` → `saddle/waggle-get-aeo-score`.
 */
class Saddle_Integrations {

	/**
	 * Wrapper ids this engine has registered, so an idempotent re-run (a
	 * second abilities-init pass, tests) skips its own wrappers silently and
	 * the collision notice fires only for genuinely foreign abilities.
	 *
	 * @var array<string,bool>
	 */
	private static $registered = array();

	/**
	 * The free, first-party integration catalog: slug => definition.
	 *
	 * @return array<string,array{prefix:string,title:string}>
	 */
	public static function integrations() {
		/**
		 * Filter the free Saddle integration catalog (first-party PlugPress
		 * namespaces).
		 *
		 * @param array $integrations slug => { prefix, title }.
		 */
		return (array) apply_filters(
			'saddle_integrations',
			array(
				'waggle' => array(
					'prefix' => 'waggle/',
					'title'  => 'Waggle',
				),
			)
		);
	}

	/**
	 * Register wrapper abilities for every discovered source ability of every
	 * enabled integration. Hooked to `wp_abilities_api_init` at priority 30 —
	 * after source plugins (10) register their own abilities.
	 */
	public static function register_wrappers() {
		if ( ! function_exists( 'wp_get_abilities' ) || ! class_exists( 'Saddle_Capabilities' ) ) {
			return;
		}

		$all = wp_get_abilities();

		foreach ( self::integrations() as $slug => $def ) {
			/**
			 * Filter whether one integration is enabled (default: on when the
			 * partner plugin registers abilities; the tier system, pause
			 * switch, and per-tool toggles all still apply on top).
			 *
			 * @param bool   $enabled Default true.
			 * @param string $slug    Integration slug.
			 */
			if ( ! apply_filters( 'saddle_integration_enabled', true, $slug ) ) {
				continue;
			}

			$prefix = isset( $def['prefix'] ) ? (string) $def['prefix'] : $slug . '/';
			foreach ( $all as $name => $ability ) {
				$name = is_string( $name ) ? $name : $ability->get_name();
				if ( 0 !== strpos( $name, $prefix ) ) {
					continue;
				}
				self::wrap( $slug, (string) $def['title'], $name, $ability );
			}
		}
	}

	/**
	 * Register one `saddle/<slug>-<short>` wrapper for a source ability.
	 *
	 * @param string $slug    Integration slug.
	 * @param string $title   Integration title (for descriptions).
	 * @param string $name    Source ability name, e.g. 'waggle/get-aeo-score'.
	 * @param object $ability Source WP_Ability.
	 */
	private static function wrap( $slug, $title, $name, $ability ) {
		$source_short = substr( $name, strpos( $name, '/' ) + 1 );
		$short        = $slug . '-' . $source_short; // waggle-get-aeo-score.
		$wrapper      = 'saddle/' . $short;

		// Collision — never overwrite an existing saddle ability. (Checked via
		// the full list: wp_get_ability() fires a notice on a miss.) Surfaced
		// as a dev notice, not silence: the shadowed tool simply not existing
		// is otherwise undiagnosable for an integration author.
		if ( isset( wp_get_abilities()[ $wrapper ] ) ) {
			if ( ! isset( self::$registered[ $wrapper ] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: wrapper ability id, 2: source ability id. */
						esc_html__( 'Integration wrapper "%1$s" (for "%2$s") collides with an existing saddle ability; the source tool is not exposed.', 'saddle' ),
						esc_html( $wrapper ),
						esc_html( $name )
					),
					'1.1.0'
				);
			}
			return;
		}

		$meta        = (array) $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$readonly    = ! empty( $annotations['readonly'] );
		$destructive = ! empty( $annotations['destructive'] );
		$idempotent  = ! empty( $annotations['idempotent'] );
		$tier        = $readonly ? 'read' : 'write';

		$schema = $ability->get_input_schema();
		if ( $destructive && is_array( $schema ) ) {
			// A source ability may declare `properties` as an (object) cast of
			// an empty array (the house style for no-input schemas); normalize
			// to an array before adding our field, or the assignment below
			// fatals on PHP 8 ("Cannot use object of type stdClass as array").
			if ( isset( $schema['properties'] ) && is_object( $schema['properties'] ) ) {
				$schema['properties'] = (array) $schema['properties'];
			}
			// The gate's handshake field, added the same way Saddle's own
			// destructive abilities declare it.
			$schema['properties']['confirm_token'] = array(
				'type'        => 'string',
				'description' => __( 'Token from the preview step; required to execute this destructive operation.', 'saddle' ),
			);
		}

		wp_register_ability(
			$wrapper,
			array(
				'label'               => $ability->get_label(),
				'description'         => trim( (string) $ability->get_description() ) . ' ' . sprintf(
					/* translators: %s: partner plugin name. */
					__( '(Provided by the %s plugin through Saddle.)', 'saddle' ),
					$title
				) . ( $destructive ? ' ' . __( 'Destructive: the first call returns a preview and confirm_token; repeat the call with the token to execute.', 'saddle' ) : '' ),
				'category'            => 'saddle',
				'input_schema'        => $schema,
				'execute_callback'    => self::executor( $short, $name, $title, $destructive, $readonly ),
				'permission_callback' => Saddle_Capabilities::permission( $tier, $readonly ? 'read' : 'edit_posts', $short ),
				'meta'                => saddle_ability_meta( $readonly, $destructive, $idempotent, $tier ),
			)
		);
		self::$registered[ $wrapper ] = true;
	}

	/**
	 * Input keys that name the item a partner tool acts on, best first.
	 *
	 * Only used to make the log line and the token's target readable — the
	 * `bind` hash is what actually holds a confirm to its preview, so a key
	 * missing from this list costs legibility, never safety.
	 *
	 * @return string[]
	 */
	private static function target_keys() {
		/**
		 * Filter the input keys treated as a partner tool's target item.
		 *
		 * @param string[] $keys Candidate keys, best first.
		 */
		return (array) apply_filters(
			'saddle_integration_target_keys',
			array( 'id', 'post_id', 'page_id', 'doc_id', 'attachment_id', 'media_id', 'campaign_id', 'term_id', 'user_id', 'redirect_id' )
		);
	}

	/**
	 * Sort an argument array by key, recursively, so two calls carrying the
	 * same arguments hash the same however the client ordered its JSON.
	 *
	 * @param array $args Arguments.
	 * @return array Key-sorted copy.
	 */
	private static function canonical( array $args ) {
		ksort( $args );
		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				$args[ $key ] = self::canonical( $value );
			}
		}
		return $args;
	}

	/**
	 * Build the wrapper's execute callback: delegate to the source ability
	 * (whose own permission_callback core re-checks inside execute()), gate
	 * destructive calls, and log every mutation.
	 *
	 * @param string $short       Wrapper short name (waggle-update-seo-meta).
	 * @param string $name        Source ability name (waggle/update-seo-meta).
	 * @param string $title       Integration title.
	 * @param bool   $destructive Whether the source flags itself destructive.
	 * @param bool   $is_readonly Whether the source is read-only.
	 * @return callable
	 */
	private static function executor( $short, $name, $title, $destructive, $is_readonly ) {
		return static function ( $input = null ) use ( $short, $name, $title, $destructive, $is_readonly ) {
			$input  = is_array( $input ) ? $input : array();
			$all    = wp_get_abilities();
			$source = isset( $all[ $name ] ) ? $all[ $name ] : null;
			if ( ! $source ) {
				return new WP_Error(
					'saddle_integration_gone',
					sprintf(
						/* translators: 1: ability name, 2: plugin name. */
						__( 'The %1$s tool is unavailable — is the %2$s plugin still active?', 'saddle' ),
						$name,
						$title
					)
				);
			}

			$delegate = static function () use ( $source, $input ) {
				// Core re-runs the source's own permission_callback here, so
				// the partner plugin's capability rules always apply.
				return $source->execute( array_diff_key( $input, array( 'confirm_token' => true ) ) );
			};

			// Never the handshake field: the preview call carries no
			// confirm_token and the confirm call does, so folding it in would
			// make the two calls differ and nothing could ever be confirmed.
			$bindable = array_diff_key( $input, array( 'confirm_token' => true ) );

			// A stable-ish target so a preview token can't be replayed
			// against a different item.
			$target = '';
			foreach ( self::target_keys() as $key ) {
				if ( isset( $bindable[ $key ] ) && is_scalar( $bindable[ $key ] ) ) {
					$target = (string) $bindable[ $key ];
					break;
				}
			}
			if ( '' === $target && $bindable ) {
				$target = substr( md5( wp_json_encode( $bindable ) ), 0, 12 );
			}

			// The target names the item; `bind` covers EVERYTHING ELSE the call
			// carries. Without it only the id was held to the preview, so a
			// confirm on the same item could arrive with a different payload
			// and the gate would run it — the user approved one change and got
			// another (issue #89). Kept out of $target so the log line stays a
			// readable "#12" rather than a hash.
			$bind = $bindable ? hash( 'sha256', (string) wp_json_encode( self::canonical( $bindable ) ) ) : '';

			if ( $destructive ) {
				// The gate logs the confirmed execution itself.
				return Saddle_Approval::gate(
					array(
						'action'  => $short,
						'target'  => $target,
						'bind'    => $bind,
						'summary' => sprintf(
							/* translators: 1: tool label, 2: target, 3: plugin name. */
							__( 'Run "%1$s" on %2$s via the %3$s integration. This is flagged destructive by %3$s.', 'saddle' ),
							$source->get_label(),
							'' !== $target ? "#{$target}" : __( 'the given input', 'saddle' ),
							$title
						),
						'preview' => array(
							'tool'  => $name,
							'input' => $input,
						),
						'input'   => $input,
						'execute' => $delegate,
					)
				);
			}

			$result = $delegate();

			if ( ! $is_readonly && ! is_wp_error( $result ) && class_exists( 'Saddle_Log' ) ) {
				Saddle_Log::record(
					array(
						'action'  => $short,
						'target'  => $target,
						'summary' => sprintf(
							/* translators: 1: tool label, 2: plugin name. */
							__( '%1$s (via the %2$s integration).', 'saddle' ),
							$source->get_label(),
							$title
						),
					)
				);
			}

			return $result;
		};
	}

	/**
	 * Tell agents the integration exists: one line per active integration on
	 * the system context (the `saddle_system_context` filter), e.g.
	 * "Waggle is installed: use the saddle/waggle-* tools…".
	 *
	 * @param string $context System context so far.
	 * @return string
	 */
	public static function append_context( $context ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $context;
		}

		$lines = array();
		foreach ( self::integrations() as $slug => $def ) {
			$count = 0;
			foreach ( array_keys( wp_get_abilities() ) as $name ) {
				if ( 0 === strpos( $name, 'saddle/' . $slug . '-' ) ) {
					++$count;
				}
			}
			if ( $count ) {
				$lines[] = sprintf(
					'- %1$s is installed: use the saddle/%2$s-* tools (%3$d available) for its features instead of improvising with generic tools.',
					(string) $def['title'],
					$slug,
					$count
				);
			}
		}

		if ( ! $lines ) {
			return $context;
		}

		return rtrim( (string) $context ) . "\n\nFirst-party integrations:\n" . implode( "\n", $lines ) . "\n";
	}
}
