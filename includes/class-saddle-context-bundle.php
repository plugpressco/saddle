<?php
/**
 * The site's working memory, in one call.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orienting used to cost five calls: get-instructions, get-design-system,
 * list-block-types, list-block-patterns, list-section-recipes — paid again at
 * the start of every session, on every connected site. This composes them into
 * one cached payload, and a short summary of it rides the system context so a
 * session starts oriented before it calls anything at all.
 *
 * COMPOSES the existing reads, never re-implements them. Saddle Pro proved this
 * shape for Divi (Saddle_Pro_Divi_Bundle); this is the Gutenberg equivalent.
 *
 * The cache is belt and braces: a 12-hour TTL AND a content signature checked on
 * every read. A signature miss self-corrects even when an invalidation hook
 * never fired, which matters because global styles and patterns can be edited
 * from the Site Editor, WP-CLI, or another agent entirely.
 *
 * ONE DELIBERATE OMISSION: the full block catalog is not in here. Pro can embed
 * Divi's module list because it is a bounded set; a WordPress block registry is
 * not — a site with WooCommerce and a block plugin runs to hundreds of types and
 * tens of kilobytes. An agency runs a dozen Saddle connections in one context
 * window, so this ships the curated set Saddle can author with worked examples,
 * a total count, and a pointer to list-block-types for anything else.
 */
class Saddle_Context_Bundle {

	/**
	 * Transient holding the assembled bundle.
	 */
	const TRANSIENT = 'saddle_context_bundle';

	/**
	 * Cache lifetime.
	 */
	const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Hard cap on the auto-injected summary, in characters. Context budget
	 * discipline applies to our own injection too.
	 */
	const SUMMARY_BUDGET = 1000;

	/**
	 * How many theme patterns to name in the bundle. A themeful of patterns is
	 * unbounded — Twenty Twenty-Five ships 109 — and the bundle exists to cost
	 * LESS context than the calls it replaces, not more.
	 */
	const PATTERN_SAMPLE = 12;

	/**
	 * The bundle, from cache when its signature still holds.
	 *
	 * @return array
	 */
	public static function get() {
		$signature = self::signature();
		$cached    = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'] ) && $cached['version'] === $signature ) {
			return $cached;
		}

		$bundle = self::assemble( $signature );
		set_transient( self::TRANSIENT, $bundle, self::TTL );
		return $bundle;
	}

	/**
	 * Drop the cached bundle. Hooked to plugin/theme changes and to Saddle's
	 * own flush-cache ability.
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Build the payload.
	 *
	 * @param string $signature The cache signature to stamp on it.
	 * @return array
	 */
	private static function assemble( $signature ) {
		$bundle = array(
			'design_system' => Saddle_Blocks_Schema::design_system(),
			'blocks'        => self::blocks(),
			'patterns'      => self::patterns(),
			'site'          => self::site(),
			'brand'         => array(
				'site_name' => get_bloginfo( 'name' ),
				'tagline'   => get_bloginfo( 'description' ),
			),
			'recipes'       => Saddle_Recipes::catalog(),
			'conventions'   => array(
				__( 'Build with real editor blocks. Never approximate a layout by dumping markup into one core/html block — it stops being editable in WordPress the moment you do.', 'saddle' ),
				__( 'Use the design system\'s slugs, not raw values: {"backgroundColor":"<slug>"} and {"fontSize":"<slug>"}, so a later change to the site\'s colors reaches every page you built.', 'saddle' ),
				__( 'Prefer a theme pattern or a section recipe over composing a section from parts. Patterns arrive already styled for this theme.', 'saddle' ),
				__( 'Read get-block-schema before composing a block type you have not used: it carries the placement rules (what must contain it, what it may contain) and a worked example.', 'saddle' ),
				__( 'Build, then verify-page, then fix by address and verify again. The score is server-side only — open get-preview-url and look at the real page before calling it done.', 'saddle' ),
			),
			'version'       => $signature,
		);

		/**
		 * Filter the whole context bundle.
		 *
		 * A builder addon can swap in its own payload the way it already does
		 * for `saddle_design_system`, so a Divi site gets Divi's working memory
		 * from the same call.
		 *
		 * @param array $bundle The assembled bundle.
		 */
		return apply_filters( 'saddle_context_bundle', $bundle );
	}

	/**
	 * The blocks slice: what Saddle can author well, plus how to find the rest.
	 *
	 * @return array
	 */
	private static function blocks() {
		$all     = Saddle_Blocks_Schema::catalog();
		$curated = Saddle_Blocks_Schema::curated_types();

		$common = array();
		foreach ( $all as $type ) {
			if ( in_array( $type['name'], $curated, true ) ) {
				$common[] = array(
					'name'      => $type['name'],
					'title'     => $type['title'],
					'authoring' => $type['authoring'],
				);
			}
		}

		return array(
			'total'  => count( $all ),
			'common' => $common,
			'note'   => __( 'These are the types Saddle composes with worked examples. Every other registered block is available too — search the full catalog with list-block-types, then read get-block-schema before using one.', 'saddle' ),
		);
	}

	/**
	 * Patterns from both sources, which are easy to confuse: the theme ships
	 * one set, the owner saves another.
	 *
	 * @return array
	 */
	private static function patterns() {
		// Same unbounded-list trap as the block catalog, and one a unit test
		// will not catch on a minimal fixture theme: Twenty Twenty-Five alone
		// ships 109 patterns, which took this payload to 28KB on its own.
		// Ship a sample plus the count, and point at the searchable tool.
		$all   = Saddle_Blocks_Schema::patterns();
		$theme = array_slice( $all, 0, self::PATTERN_SAMPLE );

		$saved = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) as $pattern ) {
			$saved[] = array(
				'id'     => $pattern->ID,
				'title'  => $pattern->post_title,
				'synced' => 'unsynced' !== get_post_meta( $pattern->ID, 'wp_pattern_sync_status', true ),
			);
		}

		return array(
			'theme_total' => count( $all ),
			'theme'       => $theme,
			'saved'       => $saved,
			'note'        => __( 'Theme patterns come styled for this site: insert one with insert-block-pattern. Only a sample is listed here, so search the rest with list-block-patterns. Saved patterns are the owner\'s own; read them with list-saved-patterns.', 'saddle' ),
		);
	}

	/**
	 * The site slice: what wraps every page. Empty on a classic theme, where
	 * the header and footer live in PHP that Saddle does not edit.
	 *
	 * @return array
	 */
	private static function site() {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return array(
				'block_theme' => false,
				'note'        => __( 'Classic theme: no block templates or parts. The header and footer are in the theme\'s PHP files, which Saddle does not edit.', 'saddle' ),
			);
		}

		$templates = array();
		$parts     = array();
		foreach ( get_block_templates( array(), 'wp_template' ) as $template ) {
			$templates[] = $template->slug;
		}
		foreach ( get_block_templates( array(), 'wp_template_part' ) as $part ) {
			$parts[] = $part->slug;
		}

		return array(
			'block_theme' => true,
			'theme'       => get_stylesheet(),
			'templates'   => $templates,
			'parts'       => $parts,
			'note'        => __( 'Read any of these with get-template to see what wraps your content before you design inside it.', 'saddle' ),
		);
	}

	/**
	 * A short orientation line for the auto-served system context, so a session
	 * starts knowing the shape of the site with zero tool calls.
	 *
	 * @return string[] Markdown lines, or empty when there is nothing to say.
	 */
	public static function summary_lines() {
		$bundle = self::get();
		$design = isset( $bundle['design_system'] ) ? $bundle['design_system'] : array();

		$facts = array();

		if ( ! empty( $design['colors'] ) ) {
			$swatches = array();
			foreach ( array_slice( $design['colors'], 0, 6 ) as $color ) {
				$slug = isset( $color['slug'] ) ? $color['slug'] : '';
				if ( '' !== $slug ) {
					$swatches[] = $slug;
				}
			}
			if ( $swatches ) {
				$facts[] = sprintf(
					/* translators: %s: comma-separated color slugs. */
					__( 'the palette is %s', 'saddle' ),
					implode( ', ', $swatches )
				);
			}
		}

		if ( ! empty( $bundle['site']['block_theme'] ) && ! empty( $bundle['site']['parts'] ) ) {
			$facts[] = sprintf(
				/* translators: %s: comma-separated template part slugs. */
				__( 'every page is wrapped by these template parts: %s', 'saddle' ),
				implode( ', ', array_slice( $bundle['site']['parts'], 0, 4 ) )
			);
		}

		if ( ! empty( $bundle['patterns']['theme'] ) ) {
			$facts[] = sprintf(
				/* translators: %d: number of theme patterns. */
				__( '%d ready-made theme patterns are available', 'saddle' ),
				count( $bundle['patterns']['theme'] )
			);
		}

		if ( ! $facts ) {
			return array();
		}

		$line = '- ' . __( 'Site design memory: ', 'saddle' ) . implode( '; ', $facts ) . '. '
			. __( 'Use these slugs instead of ad-hoc values. Call saddle/context-bundle once for the whole design system, block set, patterns and templates in a single call.', 'saddle' );

		if ( strlen( $line ) > self::SUMMARY_BUDGET ) {
			$line = substr( $line, 0, self::SUMMARY_BUDGET - 1 ) . '…';
		}

		return array( $line );
	}

	/**
	 * Everything that could stale the bundle, hashed: the active theme and its
	 * version, the active plugin set (which registers blocks and patterns), and
	 * the owner's global styles.
	 *
	 * @return string
	 */
	private static function signature() {
		$theme   = wp_get_theme();
		$plugins = (array) get_option( 'active_plugins', array() );

		// The owner's own design choices change out of band, from the Site
		// Editor or another agent, so hash the record itself rather than
		// trusting a hook to have fired.
		$styles = '';
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$post_id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			if ( $post_id ) {
				$styles = (string) get_post_field( 'post_modified_gmt', $post_id ) . '|' . (string) get_post_field( 'post_content', $post_id );
			}
		}

		return substr(
			md5(
				$theme->get_stylesheet() . '|' . $theme->get( 'Version' )
				. '|' . md5( implode( ',', $plugins ) )
				. '|' . md5( $styles )
			),
			0,
			12
		);
	}
}
