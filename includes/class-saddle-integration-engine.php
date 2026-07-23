<?php
/**
 * The shared integration engine — partner plugins' abilities, wrapped in
 * Saddle's safety model.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Re-exposes a partner plugin's Abilities-API tools through Saddle's MCP
 * server, each wrapped in the full Saddle safety model.
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
 * A wrapped ability appears everywhere a native one does (MCP tools,
 * Permissions UI lanes, per-tool toggles) with zero extra wiring, because
 * it IS a `saddle/` ability: `waggle/get-aeo-score` → `saddle/waggle-get-aeo-score`.
 *
 * One engine, many catalogs: free Saddle instantiates it for the free
 * first-party catalog (Waggle), Saddle Pro for its own (Knovia). Each
 * caller supplies only its catalog and filter names — the wrap/executor
 * safety logic exists exactly once, here.
 *
 * A catalog entry may declare, besides `prefix` and `title`:
 *   - `force_destructive` (string[]) — source short names to gate even when
 *     the partner forgot the `destructive` annotation. Saddle's tier/gate
 *     promises are only as honest as partner annotation hygiene; this is
 *     the owner-side override for a partner that mislabels.
 */
class Saddle_Integration_Engine {

	/**
	 * Filter name for the catalog, e.g. 'saddle_integrations'.
	 *
	 * @var string
	 */
	private $catalog_filter;

	/**
	 * Filter name for the per-integration kill switch,
	 * e.g. 'saddle_integration_enabled'.
	 *
	 * @var string
	 */
	private $enabled_filter;

	/**
	 * The default catalog: slug => { prefix, title, force_destructive? }.
	 *
	 * @var array<string,array{prefix:string,title:string,force_destructive?:string[]}>
	 */
	private $default_catalog;

	/**
	 * Wrapper ids this engine has registered, so an idempotent re-run (a
	 * second abilities-init pass, tests) skips its own wrappers silently and
	 * the collision notice fires only for genuinely foreign abilities.
	 *
	 * @var array<string,bool>
	 */
	private $registered = array();

	/**
	 * @param array  $default_catalog Catalog served when the filter adds nothing.
	 * @param string $catalog_filter  Filter name exposing the catalog.
	 * @param string $enabled_filter  Filter name for the per-integration switch.
	 */
	public function __construct( array $default_catalog, $catalog_filter, $enabled_filter ) {
		$this->default_catalog = $default_catalog;
		$this->catalog_filter  = (string) $catalog_filter;
		$this->enabled_filter  = (string) $enabled_filter;
	}

	/**
	 * The integration catalog: slug => definition, after the caller's filter.
	 *
	 * @return array<string,array{prefix:string,title:string,force_destructive?:string[]}>
	 */
	public function integrations() {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Filter name supplied by the wiring class ('saddle_integrations' / 'saddle_pro_integrations').
		return (array) apply_filters( $this->catalog_filter, $this->default_catalog );
	}

	/**
	 * Register wrapper abilities for every discovered source ability of every
	 * enabled integration. Call from `wp_abilities_api_init` at priority 30 —
	 * after source plugins (10) register their own abilities.
	 */
	public function register_wrappers() {
		if ( ! function_exists( 'wp_get_abilities' ) || ! class_exists( 'Saddle_Capabilities' ) ) {
			return;
		}

		$all = wp_get_abilities();

		foreach ( $this->integrations() as $slug => $def ) {
			/**
			 * Filter whether one integration is enabled (default: on when the
			 * partner plugin registers abilities; the tier system, pause
			 * switch, and per-tool toggles all still apply on top).
			 *
			 * @param bool   $enabled Default true.
			 * @param string $slug    Integration slug.
			 */
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Filter name supplied by the wiring class.
			if ( ! apply_filters( $this->enabled_filter, true, $slug ) ) {
				continue;
			}

			$prefix = isset( $def['prefix'] ) ? (string) $def['prefix'] : $slug . '/';
			foreach ( $all as $name => $ability ) {
				$name = is_string( $name ) ? $name : $ability->get_name();
				if ( 0 !== strpos( $name, $prefix ) ) {
					continue;
				}
				$this->wrap( $slug, (array) $def, $name, $ability, $all );
			}
		}
	}

	/**
	 * Register one `saddle/<slug>-<short>` wrapper for a source ability.
	 *
	 * @param string $slug    Integration slug.
	 * @param array  $def     Catalog definition (title, force_destructive, …).
	 * @param string $name    Source ability name, e.g. 'knovia/create-doc'.
	 * @param object $ability Source WP_Ability.
	 * @param array  $all     The ability registry snapshot from this pass.
	 */
	private function wrap( $slug, array $def, $name, $ability, array $all ) {
		$title        = isset( $def['title'] ) ? (string) $def['title'] : ucfirst( $slug );
		$source_short = substr( $name, strpos( $name, '/' ) + 1 );
		$short        = $slug . '-' . $source_short; // knovia-create-doc.
		$wrapper      = 'saddle/' . $short;

		// Collision — never overwrite an existing saddle ability. (Checked
		// against this pass's registry snapshot plus everything this engine
		// itself registered.) Surfaced as a dev notice, not silence: the
		// shadowed tool simply not existing is otherwise undiagnosable for an
		// integration author.
		if ( isset( $all[ $wrapper ] ) || isset( wp_get_abilities()[ $wrapper ] ) ) {
			if ( ! isset( $this->registered[ $wrapper ] ) ) {
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

		// The catalog can force the gate onto a source the partner forgot to
		// annotate as destructive — a missing annotation must fail toward
		// MORE protection, and the partner's own callback still applies.
		if ( ! $destructive
			&& isset( $def['force_destructive'] )
			&& in_array( $source_short, (array) $def['force_destructive'], true ) ) {
			$destructive = true;
			$readonly    = false;
			$tier        = 'write';
		}

		$schema = $ability->get_input_schema();
		if ( $destructive && is_array( $schema ) ) {
			// A source ability may declare `properties` as an (object) cast of
			// an empty array (the house style for no-input schemas); normalize
			// to an array before adding our field, or the assignment below
			// fatals on PHP 8 ("Cannot use object of type stdClass as array").
			if ( isset( $schema['properties'] ) && is_object( $schema['properties'] ) ) {
				$schema['properties'] = (array) $schema['properties'];
			}
			// The gate's handshake field, added the same way free Saddle's
			// own destructive abilities declare it.
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
				'execute_callback'    => $this->executor( $short, $name, $title, $destructive, $readonly ),
				'permission_callback' => Saddle_Capabilities::permission( $tier, $readonly ? 'read' : 'edit_posts', $short ),
				'meta'                => saddle_ability_meta( $readonly, $destructive, $idempotent, $tier ),
			)
		);
		$this->registered[ $wrapper ] = true;
	}

	/**
	 * Build the wrapper's execute callback: delegate to the source ability
	 * (whose own permission_callback core re-checks inside execute()), gate
	 * destructive calls, and log every mutation.
	 *
	 * @param string $short       Wrapper short name (knovia-create-doc).
	 * @param string $name        Source ability name (knovia/create-doc).
	 * @param string $title       Integration title.
	 * @param bool   $destructive Whether the wrapper treats the source as destructive.
	 * @param bool   $is_readonly Whether the source is read-only.
	 * @return callable
	 */
	private function executor( $short, $name, $title, $destructive, $is_readonly ) {
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

			// The arguments the source will actually run with — the handshake
			// field excluded, since the preview call carries no confirm_token
			// and the confirm call does.
			$bindable = array_diff_key( $input, array( 'confirm_token' => true ) );

			// A stable target for logging and token identity, so a preview
			// token can't be replayed against a different item.
			$target = '';
			foreach ( array( 'id', 'post_id', 'doc_id', 'attachment_id' ) as $key ) {
				if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
					$target = (string) $input[ $key ];
					break;
				}
			}
			if ( '' === $target && $bindable ) {
				$target = substr( md5( wp_json_encode( $bindable ) ), 0, 12 );
			}

			if ( $destructive ) {
				// The gate logs the confirmed execution itself. The FULL
				// argument set is folded into the token identity via `bind`:
				// with only an id-shaped target bound, a confirm call could
				// carry different other arguments (a force/permanent flag)
				// than the preview the owner saw and still redeem the token.
				return Saddle_Approval::gate(
					array(
						'action'  => $short,
						'target'  => $target,
						'bind'    => $bindable ? substr( md5( wp_json_encode( $bindable ) ), 0, 12 ) : '',
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
	 * Count of registered wrappers per active integration, for context lines:
	 * slug => { title, count }. Only integrations with at least one wrapper.
	 *
	 * @return array<string,array{title:string,count:int}>
	 */
	public function active_counts() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$names  = array_keys( wp_get_abilities() );
		$active = array();
		foreach ( $this->integrations() as $slug => $def ) {
			$count = 0;
			foreach ( $names as $name ) {
				if ( 0 === strpos( $name, 'saddle/' . $slug . '-' ) ) {
					++$count;
				}
			}
			if ( $count ) {
				$active[ $slug ] = array(
					'title' => isset( $def['title'] ) ? (string) $def['title'] : ucfirst( $slug ),
					'count' => $count,
				);
			}
		}
		return $active;
	}
}
