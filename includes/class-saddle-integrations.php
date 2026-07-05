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
		// the full list: wp_get_ability() fires a notice on a miss.)
		if ( isset( wp_get_abilities()[ $wrapper ] ) ) {
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
	 * @param bool   $readonly    Whether the source is read-only.
	 * @return callable
	 */
	private static function executor( $short, $name, $title, $destructive, $readonly ) {
		return static function ( $input = null ) use ( $short, $name, $title, $destructive, $readonly ) {
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

			// A stable-ish target so a preview token can't be replayed
			// against a different item.
			$target = '';
			foreach ( array( 'id', 'post_id', 'attachment_id' ) as $key ) {
				if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
					$target = (string) $input[ $key ];
					break;
				}
			}
			if ( '' === $target && $input ) {
				// Hash the arguments, but never the handshake field: the preview
				// call carries no confirm_token and the confirm call does, so
				// hashing it in would make the target differ between the two and
				// no destructive keyless tool could ever be confirmed.
				$bindable = array_diff_key( $input, array( 'confirm_token' => true ) );
				$target   = $bindable ? substr( md5( wp_json_encode( $bindable ) ), 0, 12 ) : '';
			}

			if ( $destructive ) {
				// The gate logs the confirmed execution itself.
				return Saddle_Approval::gate(
					array(
						'action'  => $short,
						'target'  => $target,
						'summary' => sprintf(
							/* translators: 1: tool label, 2: target, 3: plugin name. */
							__( 'Run "%1$s" on %2$s via the %3$s integration. This is flagged destructive by %3$s.', 'saddle' ),
							$source->get_label(),
							'' !== $target ? "#{$target}" : __( 'the given input', 'saddle' ),
							$title
						),
						'preview' => array( 'tool' => $name, 'input' => $input ),
						'input'   => $input,
						'execute' => $delegate,
					)
				);
			}

			$result = $delegate();

			if ( ! $readonly && ! is_wp_error( $result ) && class_exists( 'Saddle_Log' ) ) {
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

		return rtrim( (string) $context ) . "\n\n" . "First-party integrations:\n" . implode( "\n", $lines ) . "\n";
	}
}
