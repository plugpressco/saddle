<?php
/**
 * Built-in playbooks for the native SEO integrations (Yoast SEO, Rank Math,
 * AIOSEO) — each bundled only while its plugin is active, so an agent is
 * never pointed at a playbook for a plugin the site doesn't run.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO integration skills, served through `saddle_builtin_skills`.
 */
class Saddle_Seo_Skills {

	/**
	 * Hook the three skill providers.
	 */
	public static function register() {
		add_filter( 'saddle_builtin_skills', array( __CLASS__, 'yoast_builtin_skills' ) );
		add_filter( 'saddle_builtin_skills', array( __CLASS__, 'rankmath_builtin_skills' ) );
		add_filter( 'saddle_builtin_skills', array( __CLASS__, 'aioseo_builtin_skills' ) );
	}


	/**
	 * Bundle the yoast-seo skill when Yoast SEO is active (never point an
	 * agent at a playbook for a plugin the site doesn't run).
	 *
	 * @param array[] $skills Built-in skills registered so far.
	 * @return array[]
	 */
	public static function yoast_builtin_skills( $skills ) {
		if ( ! Saddle_Yoast::is_active() ) {
			return $skills;
		}

		$skills[] = array(
			'name'        => 'yoast-seo',
			'description' => __( 'Read and edit a post or term\'s Yoast SEO fields with the saddle/yoast-* tools: the robots tri-state trap, length targets, and what the schema-type tools do and don\'t cover.', 'saddle' ),
			'when_to_use' => __( 'reading or editing SEO title, meta description, robots, or schema type on a site running Yoast SEO', 'saddle' ),
			'source'      => 'saddle',
			'body'        => self::yoast_skill_body(),
		);
		return $skills;
	}

	/**
	 * Bundle the rank-math-seo skill when Rank Math is active.
	 *
	 * @param array[] $skills Built-in skills registered so far.
	 * @return array[]
	 */
	public static function rankmath_builtin_skills( $skills ) {
		if ( ! class_exists( 'Saddle_Rank_Math' ) || ! Saddle_Rank_Math::is_active() ) {
			return $skills;
		}

		$skills[] = array(
			'name'        => 'rank-math-seo',
			'description' => __( 'Read and edit a post or term\'s Rank Math SEO fields with the saddle/rank-math-* tools: the robots flag model, length targets, and what is deliberately out of scope.', 'saddle' ),
			'when_to_use' => __( 'reading or editing SEO title, meta description, or robots on a site running Rank Math', 'saddle' ),
			'source'      => 'saddle',
			'body'        => self::rankmath_skill_body(),
		);
		return $skills;
	}

	/**
	 * Bundle the aioseo-seo skill when AIOSEO is active.
	 *
	 * @param array[] $skills Built-in skills registered so far.
	 * @return array[]
	 */
	public static function aioseo_builtin_skills( $skills ) {
		if ( ! class_exists( 'Saddle_Aioseo' ) || ! Saddle_Aioseo::is_active() ) {
			return $skills;
		}

		$skills[] = array(
			'name'        => 'aioseo-seo',
			'description' => __( 'Read and edit a post\'s AIOSEO fields with the saddle/aioseo-* tools: the all-or-nothing robots default switch, length targets, and what is deliberately out of scope.', 'saddle' ),
			'when_to_use' => __( 'reading or editing SEO title, meta description, or robots on a site running AIOSEO (All in One SEO)', 'saddle' ),
			'source'      => 'saddle',
			'body'        => self::aioseo_skill_body(),
		);
		return $skills;
	}

	/**
	 * The aioseo-seo playbook body.
	 *
	 * @return string
	 */
	private static function aioseo_skill_body() {
		return implode(
			"\n",
			array(
				'# Editing AIOSEO fields — agent playbook',
				'',
				'1. Call aioseo-check-setup first — the aioseo-* tools only work when AIOSEO is active.',
				'2. Read before you write: aioseo-get-post-seo shows current values.',
				'3. Edit with aioseo-edit-post-seo — partial merge, only the fields you pass change. An empty string resets a field to AIOSEO\'s own default; the response\'s `changed` shows what actually persisted.',
				'',
				'## The robots default switch',
				'',
				'AIOSEO gates ALL robots flags behind one per-post "default" switch. robots_index "default" therefore only takes effect when no other robots flag remains set on the post — setting robots_follow "nofollow" in the same call keeps the post on custom robots. robots_advanced is a comma list of noarchive/nosnippet/noimageindex; empty string clears them.',
				'',
				'## Length targets (non-blocking)',
				'',
				'SEO title: aim for ~60 characters or fewer. Meta description: ~155-160 or fewer. edit-post-seo\'s response carries `warnings` when either runs long — it still saved.',
				'',
				'## Out of scope, on purpose',
				'',
				'Per-term SEO is an AIOSEO Pro feature (free AIOSEO has no term storage) — there is no aioseo term tool. Primary category and schema also have no tools yet. Do not reach for update-post meta writes to fake any of them: AIOSEO stores SEO in its own table, not post meta.',
			)
		);
	}

	/**
	 * The rank-math-seo playbook body.
	 *
	 * @return string
	 */
	private static function rankmath_skill_body() {
		return implode(
			"\n",
			array(
				'# Editing Rank Math SEO fields — agent playbook',
				'',
				'1. Call rank-math-check-setup first — the rank-math-* tools only work when Rank Math is active.',
				'2. Read before you write: rank-math-get-post-seo (or rank-math-get-term-seo) to see current values.',
				'3. Edit with rank-math-edit-post-seo / rank-math-edit-term-seo — partial merge, only the fields you pass change. An empty string resets a field to Rank Math\'s own default; the response\'s `changed` shows what actually persisted.',
				'',
				'## The robots model',
				'',
				'robots_index takes "default"/"index"/"noindex" — "default" inherits the site-wide setting, NOT "index". robots_follow takes "follow"/"nofollow" only (Rank Math has no separate inherit state for follow). robots_advanced is a comma list of noarchive/noimageindex/nosnippet; empty string clears them. Flags you do not touch are preserved.',
				'',
				'## Length targets (non-blocking)',
				'',
				'SEO title: aim for ~60 characters or fewer. Meta description: ~155-160 or fewer. edit-post-seo\'s response carries `warnings` when either runs long — it still saved.',
				'',
				'## Out of scope, on purpose',
				'',
				'Post schema (Rank Math\'s is a full JSON graph, not a type field), site-wide settings, redirections, and module toggles have no rank-math-* tools yet. Do not reach for update-option or post meta to fake them.',
			)
		);
	}

	/**
	 * The yoast-seo playbook body.
	 *
	 * @return string
	 */
	private static function yoast_skill_body() {
		return implode(
			"\n",
			array(
				'# Editing Yoast SEO fields — agent playbook',
				'',
				'1. Call yoast-check-setup first — the yoast-* tools only work when Yoast SEO is active.',
				'2. Read before you write: yoast-get-post-seo (or yoast-get-term-seo for a category/tag) to see current values.',
				'3. Edit with yoast-edit-post-seo / yoast-edit-term-seo — partial merge, only the fields you pass change.',
				'',
				'## The robots trap',
				'',
				'robots_index and robots_follow take the words "default"/"noindex"/"index" and "default"/"nofollow"/"follow" — never a raw number. "default" means inherit the site-wide setting, NOT "index/follow" — do not assume default is permissive, read it back if you need to know the effective value.',
				'',
				'## Length targets (non-blocking)',
				'',
				'SEO title: aim for ~60 characters or fewer. Meta description: aim for ~155-160 characters or fewer. edit-post-seo\'s response carries `warnings` when either runs long — it still saved; tighten the copy and re-edit if the warning matters for this page.',
				'',
				'## Schema type — smaller than it looks',
				'',
				'yoast-get-post-schema / yoast-edit-post-schema cover only page_type and article_type (Yoast\'s per-post overrides). This is NOT a full custom schema graph editor — that\'s a Yoast Premium feature and out of scope here. Yoast validates both fields against its own fixed option list and silently keeps the PRIOR value for anything outside it — the call still succeeds, so always check the response\'s "changed" (or re-read) rather than assume a value took effect. A post already has an implicit default (Article for posts, WebPage for pages) — never pass "Article" as page_type; use article_type to refine it instead. page_type is for switching to something else entirely (WebPage, FAQPage, AboutPage, ContactPage, …).',
				'',
				'## Primary category',
				'',
				'yoast-edit-post-seo\'s primary_category field only applies to post types that support the "category" taxonomy — check yoast-get-post-seo\'s primary_category value (null means unsupported or unset) before assuming it will take effect.',
			)
		);
	}
}
