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
 *   - `description`, `author`, `url` — shown to the owner on the
 *     Integrations screen. Plain text and a link; nothing is fetched.
 *   - `force_destructive` (string[]) — source short names to gate even when
 *     the partner forgot the `destructive` annotation. Saddle's tier/gate
 *     promises are only as honest as partner annotation hygiene; this is
 *     the owner-side override for a partner that mislabels.
 *
 * Every entry is validated on read ({@see self::normalize()}): the prefix has
 * to be one plain namespace the partner owns. An empty prefix would wrap
 * every ability on the site, and `saddle/` or `core/` would re-expose
 * abilities that aren't the partner's to offer.
 */
class Saddle_Integration_Engine {

	/**
	 * Namespaces no integration may claim as its prefix.
	 *
	 * @var string[]
	 */
	const RESERVED_PREFIXES = array( 'saddle/', 'core/', 'mcp-adapter/' );

	/**
	 * Slugs no integration may take. Each is a prefix Saddle's own tools
	 * already use, so a wrapper under it would collide with them or be filed
	 * under the wrong group on the Permissions screen.
	 *
	 * @var string[]
	 */
	const RESERVED_SLUGS = array( 'divi', 'yoast', 'rank-math', 'aioseo', 'wc', 'unsplash' );

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
	 * Slugs already reported as invalid, so the notice fires once per request
	 * rather than on every catalog read.
	 *
	 * @var array<string,bool>
	 */
	private $rejected = array();

	/**
	 * The owner's approval check, or null when every enabled entry is approved.
	 *
	 * @var callable|null
	 */
	private $gate;

	/**
	 * Configure the engine for one plugin's catalog.
	 *
	 * @param array         $default_catalog Catalog served when the filter adds nothing.
	 * @param string        $catalog_filter  Filter name exposing the catalog.
	 * @param string        $enabled_filter  Filter name for the per-integration switch.
	 * @param callable|null $gate            Optional owner-approval check, `fn( $slug ): bool`.
	 *                                       Applied after the enabled filter, so a
	 *                                       plugin cannot switch itself on.
	 */
	public function __construct( array $default_catalog, $catalog_filter, $enabled_filter, $gate = null ) {
		$this->default_catalog = $default_catalog;
		$this->catalog_filter  = (string) $catalog_filter;
		$this->enabled_filter  = (string) $enabled_filter;
		$this->gate            = is_callable( $gate ) ? $gate : null;
	}

	/**
	 * The integration catalog: slug => definition, after the caller's filter.
	 * Invalid entries are dropped here, so nothing downstream ever sees them.
	 *
	 * @return array<string,array{prefix:string,title:string,description:string,author:string,url:string,force_destructive?:string[]}>
	 */
	public function integrations() {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Filter name supplied by the wiring class ('saddle_integrations' / 'saddle_pro_integrations').
		$raw = (array) apply_filters( $this->catalog_filter, $this->default_catalog );

		$catalog = array();
		foreach ( $raw as $slug => $def ) {
			$entry = $this->normalize( $slug, $def );
			if ( null !== $entry ) {
				$catalog[ (string) $slug ] = $entry;
			}
		}
		return $catalog;
	}

	/**
	 * Validate and clean one catalog entry.
	 *
	 * @param mixed $slug Catalog key.
	 * @param mixed $def  Catalog entry.
	 * @return array|null The cleaned entry, or null when it must be skipped.
	 */
	private function normalize( $slug, $def ) {
		$slug   = is_string( $slug ) ? $slug : '';
		$def    = is_array( $def ) ? $def : array();
		$prefix = isset( $def['prefix'] ) ? (string) $def['prefix'] : $slug . '/';

		$valid = 1 === preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug )
			&& 1 === preg_match( '#^[a-z0-9][a-z0-9-]*/$#', $prefix )
			&& ! in_array( $prefix, self::RESERVED_PREFIXES, true )
			&& ! in_array( $slug, self::RESERVED_SLUGS, true );

		if ( ! $valid ) {
			if ( ! isset( $this->rejected[ $slug ] ) ) {
				$this->rejected[ $slug ] = true;
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: integration slug, 2: its tool-name prefix. */
						esc_html__( 'Integration "%1$s" (prefix "%2$s") was skipped. The slug must be lowercase letters, digits and hyphens, the prefix one namespace ending in a slash, and neither may be one Saddle reserves.', 'saddle' ),
						esc_html( $slug ),
						esc_html( $prefix )
					),
					'1.3.0'
				);
			}
			return null;
		}

		$entry = array(
			'prefix'      => $prefix,
			'title'       => isset( $def['title'] ) ? sanitize_text_field( (string) $def['title'] ) : ucfirst( $slug ),
			'description' => isset( $def['description'] ) ? sanitize_text_field( (string) $def['description'] ) : '',
			'author'      => isset( $def['author'] ) ? sanitize_text_field( (string) $def['author'] ) : '',
			'url'         => isset( $def['url'] ) ? esc_url_raw( (string) $def['url'], array( 'http', 'https' ) ) : '',
		);
		if ( '' === $entry['title'] ) {
			$entry['title'] = ucfirst( $slug );
		}
		if ( isset( $def['force_destructive'] ) ) {
			$entry['force_destructive'] = array_map( 'strval', (array) $def['force_destructive'] );
		}
		return $entry;
	}

	/**
	 * Whether one integration's wrappers may register: the enabled filter
	 * first, then the owner's approval, which no filter can override.
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	private function is_enabled( $slug ) {
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
			return false;
		}
		return null === $this->gate || (bool) call_user_func( $this->gate, $slug );
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
			if ( ! $this->is_enabled( $slug ) ) {
				continue;
			}

			$prefix = $def['prefix'];
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
		$title        = $def['title'];
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
	 * Input keys that name the item a partner tool acts on, best first.
	 *
	 * @return string[]
	 */
	private static function target_keys() {
		/**
		 * Filter the input keys treated as a partner tool's target item.
		 *
		 * Only used to make the log line and the token's target readable — the
		 * `bind` hash is what actually holds a confirm to its preview, so a key
		 * missing from this list costs legibility, never safety.
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
			foreach ( self::target_keys() as $key ) {
				if ( isset( $bindable[ $key ] ) && is_scalar( $bindable[ $key ] ) ) {
					$target = (string) $bindable[ $key ];
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
						// sha256 over a recursively key-sorted copy, not a
						// truncated md5 of the raw array: a client that
						// serializes the same arguments in a different key
						// order must not have a legitimate confirm refused.
						'bind'    => $bindable ? hash( 'sha256', (string) wp_json_encode( self::canonical( $bindable ) ) ) : '',
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
					'title' => $def['title'],
					'count' => $count,
				);
			}
		}
		return $active;
	}

	/**
	 * Count of the partner's own source abilities per catalog entry, whether
	 * or not they are wrapped: slug => count. A zero means the partner plugin
	 * isn't active. Lets the owner see "6 tools, off" before approving.
	 *
	 * @return array<string,int>
	 */
	public function available_counts() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$names  = array_keys( wp_get_abilities() );
		$counts = array();
		foreach ( $this->integrations() as $slug => $def ) {
			$count = 0;
			foreach ( $names as $name ) {
				if ( 0 === strpos( (string) $name, $def['prefix'] ) ) {
					++$count;
				}
			}
			$counts[ $slug ] = $count;
		}
		return $counts;
	}
}
