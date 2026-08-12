<?php
/**
 * Site-editor reads — the whole site, not just one page.
 *
 * Saddle could read a post's blocks and nothing else: not the header, not the
 * footer, not the palette the owner actually set, not the patterns they saved.
 * An agent asked to "match this site" had no way to look at the site.
 *
 * These four abilities close that. They are READ-ONLY by design — templates are
 * shared surfaces, and overwriting one is destructive in a way editing a single
 * post is not, so writes are a separate decision.
 *
 * Everything here goes through core's own readers (`get_block_templates()`,
 * `get_block_template()`, `WP_Theme_JSON_Resolver`) rather than querying the
 * `wp_template` / `wp_global_styles` post types directly. Core merges the
 * theme's template FILES with the database customisations; querying posts alone
 * would silently miss every template the theme ships and has never been edited.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the site-editor abilities. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_site_editor_abilities() {

	wp_register_ability(
		'saddle/list-templates',
		array(
			'label'               => __( 'List templates and parts', 'saddle' ),
			'description'         => __( 'Lists this site\'s block templates (page, single, archive, 404, …) and template parts (header, footer, sidebar). Read-only. Each entry gives the id to pass to get-template, its title, and whether it is still the theme\'s original file or has been customised by the owner. Use this to find the header or footer before reading it — a page is not the whole site, and matching a site means looking at what wraps every page. Block themes only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'type'   => array(
						'type'        => 'string',
						'enum'        => array( 'template', 'part', 'both' ),
						'default'     => 'both',
						'description' => __( 'Which to list: "template" for page templates, "part" for header/footer parts, "both" (default).', 'saddle' ),
					),
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword filter on slug and title.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Editor_Abilities', 'list_templates' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-templates' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-template',
		array(
			'label'               => __( 'Get a template', 'saddle' ),
			'description'         => __( 'Returns one template or template part as an addressable node list — the same shape get-blocks returns for a post, so addresses mean the same thing. Read-only. Read the header part before you design a page and you will know what sits above your content: the site title, the navigation, the spacing it already uses. Pass an id from list-templates (for example "twentytwentyfive//header").', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'   => array(
						'type'        => 'string',
						'description' => __( 'The template id from list-templates, e.g. "twentytwentyfive//header".', 'saddle' ),
					),
					'type' => array(
						'type'        => 'string',
						'enum'        => array( 'template', 'part' ),
						'default'     => 'template',
						'description' => __( 'Whether the id is a page template or a template part. Defaults to "template".', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Editor_Abilities', 'get_template' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-template' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-global-styles',
		array(
			'label'               => __( 'Get global styles', 'saddle' ),
			'description'         => __( 'Returns the design choices the OWNER has made in Appearance → Editor → Styles: their palette, font sizes and spacing, separate from what the theme ships. Read-only. get-design-system shows you the merged result and is what you should build against; this shows you which parts of it are the owner\'s own decisions, which is what you need before changing any of them. Block themes only.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Site_Editor_Abilities', 'get_global_styles' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'get-global-styles' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/list-saved-patterns',
		array(
			'label'               => __( 'List saved patterns', 'saddle' ),
			'description'         => __( 'Lists the patterns the owner has saved on this site, with the id to read them and whether each is synced (edit once, changes everywhere) or unsynced (a starting point that is copied on insert). Read-only. These are the site\'s own building blocks — reuse one instead of composing a section from scratch. For the theme\'s bundled patterns, use list-block-patterns instead.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => array(
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword filter on the pattern title.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Site_Editor_Abilities', 'list_patterns' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'list-saved-patterns' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Execute callbacks for the site-editor abilities.
 */
class Saddle_Site_Editor_Abilities {

	/**
	 * Templates and parts only exist on a block theme. Say so plainly rather
	 * than returning an empty list, which reads as "this site has no header".
	 *
	 * @return true|WP_Error
	 */
	private static function require_block_theme() {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return new WP_Error(
				'saddle_not_block_theme',
				__( 'This site does not use a block theme, so it has no block templates or template parts. Its header and footer live in the theme\'s PHP files, which Saddle does not edit.', 'saddle' ),
				array( 'status' => 409 )
			);
		}
		return true;
	}

	/**
	 * One template's summary row. `source` is core's own word: "theme" means
	 * still the file the theme ships, "custom" means the owner has edited it
	 * and the edit lives in the database.
	 *
	 * @param WP_Block_Template $template Core template object.
	 * @return array
	 */
	private static function summarize( $template ) {
		$row = array(
			'id'          => $template->id,
			'slug'        => $template->slug,
			'title'       => $template->title,
			'description' => $template->description,
			'type'        => 'wp_template_part' === $template->type ? 'part' : 'template',
			'source'      => $template->source,
			'customized'  => 'custom' === $template->source,
		);

		// Parts declare where they belong (header/footer/uncategorized). It is
		// the fastest way for an agent to find the header without guessing at
		// slug conventions that differ between themes.
		if ( isset( $template->area ) && $template->area ) {
			$row['area'] = $template->area;
		}

		return $row;
	}

	/**
	 * saddle/list-templates.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_templates( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$guard = self::require_block_theme();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$type   = isset( $input['type'] ) ? (string) $input['type'] : 'both';
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

		$wanted = array();
		if ( 'both' === $type || 'template' === $type ) {
			$wanted[] = 'wp_template';
		}
		if ( 'both' === $type || 'part' === $type ) {
			$wanted[] = 'wp_template_part';
		}
		if ( ! $wanted ) {
			return new WP_Error(
				'saddle_invalid_type',
				__( 'type must be "template", "part", or "both".', 'saddle' ),
				array( 'status' => 400 )
			);
		}

		$rows = array();
		foreach ( $wanted as $post_type ) {
			foreach ( get_block_templates( array(), $post_type ) as $template ) {
				if ( '' !== $search
					&& false === stripos( $template->slug, $search )
					&& false === stripos( (string) $template->title, $search ) ) {
					continue;
				}
				$rows[] = self::summarize( $template );
			}
		}

		return array(
			'templates' => $rows,
			'usage'     => __( 'Read one with get-template, passing its id and type. "customized": true means the owner has already changed it from the theme default.', 'saddle' ),
		);
	}

	/**
	 * saddle/get-template.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_template( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$guard = self::require_block_theme();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id = isset( $input['id'] ) ? trim( (string) $input['id'] ) : '';
		if ( '' === $id ) {
			return new WP_Error( 'saddle_missing_id', __( 'Pass the template id from list-templates.', 'saddle' ), array( 'status' => 400 ) );
		}

		$post_type = isset( $input['type'] ) && 'part' === $input['type'] ? 'wp_template_part' : 'wp_template';
		$template  = get_block_template( $id, $post_type );

		if ( ! $template ) {
			return new WP_Error(
				'saddle_not_found',
				__( 'No template with that id. Call list-templates to see the ids this site actually has, and check whether it is a "template" or a "part".', 'saddle' ),
				array( 'status' => 404 )
			);
		}

		$tree  = Saddle_Blocks_Tree::parse( (string) $template->content );
		$valid = Saddle_Blocks_Tree::validate( $tree );

		$out          = self::summarize( $template );
		$out['nodes'] = Saddle_Blocks_Tree::flatten( $tree );

		// Structural validation is reported, not hidden — but templates legally
		// contain blocks whose parent/ancestor rules only hold in a template
		// context, so a bare `false` here would read as "this header is broken"
		// when it is fine. Ship the violations alongside so the caller can judge.
		$out['tree_valid'] = ! is_wp_error( $valid );
		if ( is_wp_error( $valid ) ) {
			$data                 = $valid->get_error_data();
			$out['violations']    = isset( $data['violations'] ) ? $data['violations'] : array();
			$out['validity_note'] = __( 'Some blocks report placement violations. In a template that is often legitimate — template-only blocks declare constraints that only hold inside a template — so read the violations before treating this as broken.', 'saddle' );
		}

		return $out;
	}

	/**
	 * saddle/get-global-styles.
	 *
	 * @return array|WP_Error
	 */
	public static function get_global_styles() {
		$guard = self::require_block_theme();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return new WP_Error(
				'saddle_no_theme_json',
				__( 'This WordPress does not expose theme.json data.', 'saddle' ),
				array( 'status' => 409 )
			);
		}

		$user = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
		$user = is_array( $user ) ? $user : array();

		$settings = isset( $user['settings'] ) && is_array( $user['settings'] ) ? $user['settings'] : array();
		$styles   = isset( $user['styles'] ) && is_array( $user['styles'] ) ? $user['styles'] : array();

		$colors  = isset( $settings['color']['palette']['custom'] ) ? $settings['color']['palette']['custom'] : array();
		$sizes   = isset( $settings['typography']['fontSizes']['custom'] ) ? $settings['typography']['fontSizes']['custom'] : array();
		$spacing = isset( $settings['spacing']['spacingSizes']['custom'] ) ? $settings['spacing']['spacingSizes']['custom'] : array();

		$has_any = $colors || $sizes || $spacing || $styles;

		return array(
			'customized' => (bool) $has_any,
			'colors'     => array_values( (array) $colors ),
			'font_sizes' => array_values( (array) $sizes ),
			'spacing'    => array_values( (array) $spacing ),
			'styles'     => $styles,
			'note'       => $has_any
				? __( 'These are the owner\'s own choices, layered over the theme\'s defaults.', 'saddle' )
				: __( 'The owner has not customised anything yet — this site is running the theme\'s own design as shipped.', 'saddle' ),
			'usage'      => __( 'Build against get-design-system, which is the merged result of theme plus these. Read this one when you need to know which values the owner chose deliberately, for example before proposing a change to them.', 'saddle' ),
		);
	}

	/**
	 * saddle/list-patterns.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_patterns( $input = null ) {
		$input  = is_array( $input ) ? $input : array();
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

		$query = array(
			'post_type'      => 'wp_block',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( '' !== $search ) {
			$query['s'] = $search;
		}

		$rows = array();
		foreach ( get_posts( $query ) as $pattern ) {
			// Core stores 'unsynced' for a copy-on-insert pattern and leaves the
			// meta empty for a synced one, so absence means synced.
			$sync = get_post_meta( $pattern->ID, 'wp_pattern_sync_status', true );

			$rows[] = array(
				'id'     => $pattern->ID,
				'title'  => $pattern->post_title,
				'synced' => 'unsynced' !== $sync,
				'slug'   => $pattern->post_name,
			);
		}

		return array(
			'patterns' => $rows,
			'usage'    => __( 'Synced patterns update everywhere when edited; unsynced ones are copied into the page and then live their own life. These are the owner\'s saved patterns — for the theme\'s bundled ones, use list-block-patterns.', 'saddle' ),
		);
	}
}
