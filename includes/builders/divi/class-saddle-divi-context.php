<?php
/**
 * Divi 5 context: the guidance that leads the agent's context on a Divi
 * site, the divi-build-page skill, the Divi slice of get-design-system,
 * Divi section recipes, and the native builder flag, each only while
 * Divi 5 is active.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Divi 5 guidance and design data served through Saddle's context filters.
 */
class Saddle_Divi_Context {

	/**
	 * The heading of the Divi section that leads the context, untranslated
	 * (lead_heading() is the one printed).
	 */
	const LEAD_HEADING = '# Building pages with Divi 5';

	/**
	 * The heading of the Divi section, in the site's language like the rest
	 * of the context.
	 *
	 * @return string
	 */
	private static function lead_heading() {
		return __( '# Building pages with Divi 5', 'saddle' );
	}

	/**
	 * Hook into the context filters. Only the always-relevant rules ride
	 * every session; the how-to lives in the divi-build-page skill, loaded
	 * on demand.
	 */
	public static function register() {
		add_filter( 'saddle_context_design_section', array( __CLASS__, 'lead_design_section' ), 10, 2 );
		add_filter( 'saddle_system_context', array( __CLASS__, 'append_divi_guidance' ), 20, 2 );
		// After every other bundled skill, so the Divi playbook can go first
		// in the index and the block playbooks can say what they are for.
		add_filter( 'saddle_builtin_skills', array( __CLASS__, 'builtin_skills' ), 20 );
		add_filter( 'saddle_design_system', array( __CLASS__, 'fill_divi_design_system' ) );
		add_filter( 'saddle_section_recipe', array( __CLASS__, 'divi_section_recipe' ), 10, 4 );
		add_filter( 'saddle_section_recipe_catalog', array( __CLASS__, 'divi_recipe_catalog' ) );
		add_filter( 'saddle_native_builders', array( __CLASS__, 'native_builders' ) );
	}

	/**
	 * Declare Divi a native builder: its pages are edited through the
	 * saddle/divi-* surface, so the "prefer leaving builder-built pages alone"
	 * warning does not apply to them.
	 *
	 * @param string[] $native Native builder labels so far.
	 * @return string[]
	 */
	public static function native_builders( $native ) {
		if ( Saddle_Divi::is_active() ) {
			$native[] = 'Divi';
		}
		return $native;
	}

	/**
	 * Return the Divi body for a section recipe when Divi 5 is active, so
	 * get-section-recipe yields a Divi node tree on a Divi site.
	 *
	 * @param array|null $override Override so far (null if none).
	 * @param string     $name     Recipe name.
	 * @param array|null $nodes    The Gutenberg body (unused here).
	 * @param array      $content  Cleaned content (Saddle_Recipe_Content::clean()).
	 * @return array|null array( 'builder' => 'divi', 'nodes' => [...] ) or the input.
	 */
	public static function divi_section_recipe( $override, $name, $nodes = null, $content = array() ) {
		unset( $nodes );
		if ( null !== $override || ! Saddle_Divi::is_active() || ! class_exists( 'Saddle_Divi_Recipes' ) ) {
			return $override;
		}
		$content = is_array( $content ) ? $content : array();
		$tree    = Saddle_Divi_Recipes::tree( (string) $name, $content );
		if ( empty( $tree ) ) {
			return $override;
		}
		$out     = array(
			'builder'         => 'divi',
			'nodes'           => $tree,
			'content_applied' => true,
		);
		$ignored = Saddle_Divi_Recipes::ignored( (string) $name, $content );
		if ( $ignored ) {
			$out['content_applied'] = false;
			$out['content_ignored'] = $ignored;
		}
		return $out;
	}

	/**
	 * Add the Divi recipes other plugins register to the shared recipe list,
	 * on a Divi 5 site only.
	 *
	 * @param array $extra name => {title, description} so far.
	 * @return array
	 */
	public static function divi_recipe_catalog( $extra ) {
		if ( ! Saddle_Divi::is_active() || ! class_exists( 'Saddle_Divi_Recipes' ) ) {
			return $extra;
		}
		return array_merge( Saddle_Divi_Recipes::extra_catalog(), (array) $extra );
	}

	/**
	 * Plug Divi's Global Data into the unified get-design-system shape,
	 * so an agent on a Divi site gets the real brand (colors, variables,
	 * presets, fonts) in the same response shape a block-theme site returns.
	 * theme.json-derived slots (spacing/layout/gradients) are left as free filled
	 * them: thin on most Divi themes, but harmless.
	 *
	 * @param array $shape The theme.json-derived design system.
	 * @return array
	 */
	public static function fill_divi_design_system( $shape ) {
		if ( ! Saddle_Divi::is_active() || ! class_exists( 'Saddle_Divi_Design' ) ) {
			return $shape;
		}

		$shape['builder'] = 'divi';
		$shape['source']  = 'divi-global-data';

		$colors = Saddle_Divi_Design::list_global_colors();
		if ( ! is_wp_error( $colors ) && ! empty( $colors['colors'] ) ) {
			$shape['colors'] = $colors['colors'];
		}

		$variables = Saddle_Divi_Design::list_variables();
		if ( ! is_wp_error( $variables ) && isset( $variables['variables'] ) ) {
			$shape['variables'] = $variables['variables'];
		}

		$presets = Saddle_Divi_Design::list_global_presets( array( 'kind' => 'all' ) );
		if ( ! is_wp_error( $presets ) && isset( $presets['presets'] ) ) {
			$shape['presets'] = $presets['presets'];
		}

		$fonts = Saddle_Divi_Design::get_global_fonts();
		if ( ! is_wp_error( $fonts ) ) {
			$shape['fonts'] = $fonts;
		}

		$shape['usage'] = __( 'This is a Divi 5 site: the real brand lives in Divi Global Data above. Apply the site\'s presets first. Reference a global color as var(--<gcid id>) and a variable by its "reference" (a font variable must use its $variable token; var() never loads the font), instead of hardcoding values.', 'saddle' );

		return $shape;
	}

	/**
	 * Bundle the divi-build-page skill when Divi 5 is active, first in the
	 * index, and say on the block playbooks that they are for the pages
	 * Divi does not build. Never point an agent at a playbook for a builder
	 * the site does not run.
	 *
	 * @param array[] $skills Built-in skills registered so far.
	 * @return array[]
	 */
	public static function builtin_skills( $skills ) {
		if ( ! Saddle_Divi::is_active() ) {
			return $skills;
		}

		$skills = (array) $skills;
		foreach ( $skills as $i => $skill ) {
			if ( is_array( $skill ) && isset( $skill['name'] ) && in_array( $skill['name'], array( 'build-page', 'fix-page' ), true ) ) {
				$skills[ $i ]['when_to_use'] = 'build-page' === $skill['name']
					? __( 'a post or page that is not built with Divi (divi-check-setup tells); for Divi pages use divi-build-page', 'saddle' )
					: __( 'a block page (not built with Divi) that verify-page or lint-page reported findings on', 'saddle' );
			}
		}

		array_unshift(
			$skills,
			array(
				'name'        => 'divi-build-page',
				'description' => __( 'Build or edit a Divi 5 page with the saddle/divi-* tools: study the site\'s style (site_style) and its presets first, find modules and their settings, the node format (fields, settings, attrs, columns, @phone and :hover), a responsive pass, then verify-page, the rendered HTML and real pixels, with a worked example.', 'saddle' ),
				'when_to_use' => __( 'creating, rebuilding, restyling or editing any page on this Divi site, or a new page for it', 'saddle' ),
				'source'      => 'saddle',
				'body'        => self::divi_skill_body(),
			)
		);
		return $skills;
	}

	/**
	 * The divi-build-page playbook body.
	 *
	 * @return string
	 */
	private static function divi_skill_body() {
		return Saddle_Divi_Playbook::body();
	}

	/**
	 * On a Divi 5 site, put the Divi guidance where the context's "how to
	 * design pages" section sits (near the top: some agents read only the
	 * first 2,048 characters), and keep a shortened block section after it
	 * for the posts Divi does not build. Any other site keeps its lines.
	 *
	 * @param string[] $lines The block design section.
	 * @param string   $tier  Current access tier.
	 * @return string[]
	 */
	public static function lead_design_section( $lines, $tier ) {
		if ( ! Saddle_Divi::is_active() ) {
			return $lines;
		}
		return array_merge(
			array( self::lead_heading(), '' ),
			self::guidance_lines( (string) $tier ),
			array(
				'',
				__( '# Pages not built with Divi', 'saddle' ),
				'',
				'- ' . __( 'A post or page that is not a Divi page (divi-check-setup tells) uses the block tools (get-blocks, set-blocks, add/edit/move/remove-block) and the build-page skill; context-bundle returns their design system.', 'saddle' ),
				'',
			)
		);
	}

	/**
	 * Append the Divi section when a caller assembled a context without the
	 * lead section (an older caller, or the filter alone).
	 *
	 * @param string $context Assembled system context.
	 * @param string $tier    Current access tier.
	 * @return string
	 */
	public static function append_divi_guidance( $context, $tier ) {
		// Already leading the context, or appended by an older add-on.
		if ( ! Saddle_Divi::is_active() || false !== strpos( $context, self::lead_heading() . "\n" ) || false !== strpos( $context, self::LEAD_HEADING . "\n" ) || false !== strpos( $context, "\n## Editing Divi pages" ) ) {
			return $context;
		}
		$lines = array_merge( array( '', self::lead_heading(), '' ), self::guidance_lines( (string) $tier ) );
		return $context . implode( "\n", $lines ) . "\n";
	}

	/**
	 * The Divi guidance every session carries: what matters most first.
	 *
	 * @param string $tier Current access tier.
	 * @return string[]
	 */
	private static function guidance_lines( $tier ) {
		$lines = array(
			sprintf(
				/* translators: %s: Divi version. */
				__( 'This site runs Divi %s. Its pages are a module tree (section > row > column > module) stored as blocks.', 'saddle' ),
				(string) Saddle_Divi::version()
			),
			'',
		);

		if ( class_exists( 'Saddle_Skills' ) ) {
			$lines[] = '- ' . __( 'Before building or editing a Divi page, read the divi-build-page skill (saddle/get-skill name=divi-build-page): study the site\'s style (site_style) and its presets first, then build, check and look.', 'saddle' );
		} else {
			$lines[] = '- ' . __( 'Call saddle/divi-check-setup before editing, saddle/divi-get-page for the addressable module tree, divi-get-module-schema before composing an unfamiliar module, and re-read the page after every structural change (addresses shift).', 'saddle' );
		}
		$lines[] = '- ' . __( 'Build and edit Divi pages only with the saddle/divi-* tools. Never write a Divi page\'s "content" with update-post or update-page: that destroys the layout. Build real modules, never HTML in a code module.', 'saddle' );
		$lines[] = '- ' . __( 'Every write is validated (an invalid tree is refused, never repaired) and revision-backed. Read each response\'s "warnings" (what Divi will ignore) and "notes", then run verify-page.', 'saddle' );
		$lines[] = '- ' . __( 'Traps the tools refuse or flag: a CSS id or class goes at module.advanced.htmlAttributes (desktop only); a font variable is written as its $variable token from divi-list-variables, never var(); the small screens are "tablet" and "phone" (not "phoneWide").', 'saddle' );

		// Tell the agent which purpose-built module packs are installed, so it
		// prefers a ready-made module over composing an element from primitives.
		if ( class_exists( 'Saddle_Divi_Schema' ) ) {
			$packs = Saddle_Divi_Schema::module_packs();
			if ( $packs ) {
				$summary = array();
				foreach ( array_slice( $packs, 0, 8 ) as $p ) {
					$summary[] = sprintf( '%s (%d)', $p['name'], $p['count'] );
				}
				$lines[] = '- ' . sprintf(
					/* translators: %s: comma-separated module packs, each with its module count. */
					__( 'Module packs installed: %s. For a real web element (pricing table, carousel, testimonial, counter, …) search divi-list-modules and prefer a purpose-built module over one composed from core primitives.', 'saddle' ),
					implode( ', ', $summary )
				);
			}
		}

		// The builder memory: a session starts already knowing the site's
		// palette, tokens, and catalog size, with zero discovery calls.
		if ( class_exists( 'Saddle_Divi_Bundle' ) ) {
			$lines = array_merge( $lines, Saddle_Divi_Bundle::summary_lines() );
		}

		if ( 'read' === $tier ) {
			$lines[] = '- ' . __( 'The current access level is read-only: you can inspect Divi pages but not change them.', 'saddle' );
		}

		return $lines;
	}
}
