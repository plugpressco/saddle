<?php
/**
 * Divi 5 context: the system-context section, the divi-build-page skill, the
 * Divi slice of get-design-system, Divi section recipes, and the native
 * builder flag — each only while Divi 5 is active.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Divi 5 guidance and design data served through Saddle's context filters.
 */
class Saddle_Divi_Context {

	/**
	 * Hook into the context filters. The system-context section stays short —
	 * only the always-relevant safety rules ride every session; the how-to
	 * lives in the divi-build-page skill, loaded on demand.
	 */
	public static function register() {
		add_filter( 'saddle_system_context', array( __CLASS__, 'append_divi_guidance' ), 20, 2 );
		add_filter( 'saddle_builtin_skills', array( __CLASS__, 'builtin_skills' ) );
		add_filter( 'saddle_design_system', array( __CLASS__, 'fill_divi_design_system' ) );
		add_filter( 'saddle_section_recipe', array( __CLASS__, 'divi_section_recipe' ), 10, 2 );
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
	 * @return array|null array( 'builder' => 'divi', 'nodes' => [...] ) or the input.
	 */
	public static function divi_section_recipe( $override, $name ) {
		if ( null !== $override || ! Saddle_Divi::is_active() || ! class_exists( 'Saddle_Divi_Recipes' ) ) {
			return $override;
		}
		$nodes = Saddle_Divi_Recipes::tree( (string) $name );
		if ( empty( $nodes ) ) {
			return $override;
		}
		return array(
			'builder' => 'divi',
			'nodes'   => $nodes,
		);
	}

	/**
	 * Plug Divi's Global Data into the unified get-design-system shape,
	 * so an agent on a Divi site gets the real brand (colors, variables, module
	 * presets, fonts) in the same response shape a block-theme site returns.
	 * theme.json-derived slots (spacing/layout/gradients) are left as free filled
	 * them — thin on most Divi themes, but harmless.
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

		$presets = Saddle_Divi_Design::list_global_presets( array() );
		if ( ! is_wp_error( $presets ) && isset( $presets['presets'] ) ) {
			$shape['presets'] = $presets['presets'];
		}

		$fonts = Saddle_Divi_Design::get_global_fonts();
		if ( ! is_wp_error( $fonts ) ) {
			$shape['fonts'] = $fonts;
		}

		$shape['usage'] = __( 'This is a Divi 5 site — the real brand lives in Divi Global Data above. Use the color/variable IDs (e.g. "gcid-…", "gvid-…") and the var(--gcid-…) tokens in module attrs, and apply module "presets" by name, instead of hardcoding values.', 'saddle' );

		return $shape;
	}

	/**
	 * Bundle the divi-build-page skill when Divi 5 is active (never point an
	 * agent at a playbook for a builder the site doesn't run).
	 *
	 * @param array[] $skills Built-in skills registered so far.
	 * @return array[]
	 */
	public static function builtin_skills( $skills ) {
		if ( ! Saddle_Divi::is_active() ) {
			return $skills;
		}

		$skills[] = array(
			'name'        => 'divi-build-page',
			'description' => __( 'Build or edit a Divi 5 page end-to-end with the saddle/divi-* tools: the module-tree model, the authoring format, and the closed loop — plan, build, verify-page, fix by address, re-verify — with a worked example.', 'saddle' ),
			'when_to_use' => __( 'creating, rebuilding, or editing any page built with Divi', 'saddle' ),
			'source'      => 'saddle',
			'body'        => self::divi_skill_body(),
		);
		return $skills;
	}

	/**
	 * The divi-build-page playbook body.
	 *
	 * @return string
	 */
	private static function divi_skill_body() {
		// Saddle_Context::design_numbers() is the single source of the shared
		// design bar (it already rides every session's context) — the skill
		// embeds those lines verbatim so the two can never drift, then adds
		// only the Divi-specific bullets.
		$numbers = array();
		if ( class_exists( 'Saddle_Context' ) && method_exists( 'Saddle_Context', 'design_numbers' ) ) {
			foreach ( Saddle_Context::design_numbers() as $line ) {
				if ( '' === $line || 0 === strpos( $line, '#' ) ) {
					continue;
				}
				$numbers[] = $line;
			}
		}
		if ( ! $numbers ) {
			$numbers = array(
				'- Type scale: hero 44-64px / weight 700; section headings 28-40px; body 16-18px / line-height 1.5-1.7.',
				'- Line length: ~50-75 characters (600-720px max width for a text column) — and CENTER a width-capped column.',
				'- Spacing on an 8px system; generous section padding (~64-96px top/bottom); more space BETWEEN groups than inside them.',
				'- One neutral ground, one text color, a SINGLE accent; body text hits WCAG AA (≥ 4.5:1).',
			);
		}

		return implode(
			"\n",
			array_merge(
				self::divi_skill_head(),
				$numbers,
				self::divi_skill_tail()
			)
		);
	}

	/**
	 * Skill body lines before the embedded design numbers.
	 *
	 * @return string[]
	 */
	private static function divi_skill_head() {
		return array(
			'# Building a page in Divi 5 — agent playbook',
			'',
			'Mental model: a Divi 5 page is a module tree — divi/section > divi/row > divi/column > leaf modules — stored as WordPress blocks in post_content. You never write that markup by hand; you work through the saddle/divi-* tools. Every write is validated before it saves (invalid trees are rejected, never repaired), and every write response echoes back what was applied vs ignored. Above all: work CLOSED-LOOP — plan, build, verify-page against the saved state, fix by address, re-verify. A write call returning success is not evidence the page is right; the verify score is.',
			'',
			'## The workflow — follow these steps in order',
			'',
			'1. SETUP    — divi-check-setup: Divi 5 active? What builder does the target post use (divi5 / divi4 / other)? Divi 4 shortcode pages are refused — leave them alone.',
			'2. ORIENT   — divi-context-bundle: the whole site memory in ONE call — module catalog + packs, global colors/variables/presets/fonts, brand, conventions. Skip the separate list-modules / list-global-colors / list-variables / get-global-fonts calls; drill into divi-get-module-schema only for modules you will actually compose.',
			'3. PLAN     — write the design plan before the first write: palette (4-6 values, prefer the site\'s var(--gcid-…) tokens), ONE accent, radius/spacing/type scales, a one-sentence layout concept (see "Design first" below). No ad-hoc values mid-build; if the design needs to grow, revise the plan first.',
			'4. BUILD    — divi-set-page for the FIRST build only (it returns the full address map). After that, EDIT — divi-edit-module / divi-add-module by address. A second full rebuild means you skipped diagnosis; compact divi-get-page re-reads are cheap and carry each node\'s text. Content first, via `fields`; schemas via divi-get-module-schema as needed.',
			'5. STYLE    — divi-get-style-schema type=<module> for EACH module you style, then write `attrs` using the exact paths it returns. Never guess a path.',
			'6. CHECK THE ECHO — every write response carries `changed` (the persisted node — no re-read needed) and may carry `warnings`: attributes the module will silently ignore. A warning means your path or key was wrong — fix it now, not later.',
			'7. VERIFY   — saddle/verify-page post_id=<id>: ONE scored report (0-100) over the SAVED state — structural breaks, silently-ignored attrs, accessibility, design lint — every finding at a node address with a fix hint. Fix in order (structural → ignored → errors → warnings), then re-run. Loop until the score is honest to ship.',
			'8. LOOK     — saddle/render-node for a node\'s effective styles + HTML when a finding needs eyes on the persisted truth; saddle/get-preview-url for REAL pixels — open the short-lived URL in your own browser and screenshot it. Judge what you see against your plan; fix; re-verify.',
			'9. PUBLISH — set-page keeps the post\'s current status; a draft stays a draft (visitors get a 404). If the user expects a live page, publish with update-page status=publish.',
			'',
			'## Hard rules (the server enforces these)',
			'',
			'- Never write a Divi page\'s `content` field with update-post/update-page — Saddle\'s builder guard refuses it, because raw content writes destroy builder layouts.',
			'- Build real modules, never a code module holding an HTML dump. Pages must stay fully editable in the Visual Builder.',
			'- Respect the skeleton: sections at the root, rows in sections, columns in rows, modules in columns. The validator rejects anything else.',
			'- Writes need the write access level; destructive container removals always preview first and ask for confirmation. Nothing here can bypass that.',
			'',
			'## Choosing modules — purpose-built beats hand-stacked, every time',
			'',
			'Many Divi sites have module packs installed beyond core Divi. A dedicated module looks designed out of the box; the same element hand-stacked from heading + text + button renders as generic default Divi. Procedure:',
			'',
			'1. Name the element you need ("product card", "pricing table", "testimonial slider").',
			'2. Search divi-list-modules for it (try 2-3 terms: "card", "info", "pricing"…). The catalog lists every active pack\'s modules, not just core.',
			'3. If a purpose-built module fits → use it. Only compose from primitives (divi/heading, divi/text, divi/button) when nothing fits.',
			'',
			'Element → module map (pack module first, core fallback second). Where a DiviTorque-style pack is installed, the search terms in parentheses find its ready-made module:',
			'- product / feature / pricing CARD → a pack card module (search "card", "info-card", "info box"), else divi/blurb. Cards are the most common element agents wrongly hand-stack — search for a card module BEFORE composing one.',
			'- pricing table → a pack pricing module (search "pricing" — e.g. a pricing-cards module with per-plan children), else divi/pricing-tables',
			'- testimonial / review → a pack module (search "testimonial", "review", "star-rating", "google-reviews" — sliders exist too), else divi/testimonial',
			'- stats / numbers band → a pack module (search "stats", "counter", "number"), else divi/number-counter or divi/circle-counter',
			'- feature checklist → a pack list module (search "checkmark", "icon-list", "list group"), else hand-stacked divi/blurb rows',
			'- team → a pack module (search "team"), else divi/person',
			'- countdown / launch timer → a pack module (search "countdown"), else divi/countdown-timer',
			'- slider / carousel / gallery / logo wall → a pack module (search "carousel", "marquee", "logo"), else divi/gallery or divi/slider',
			'- call-to-action band → divi/cta (heading + text + button in one module)',
			'- FAQ / accordion → a pack module (search "faq", "accordion", "toggle"), else divi/accordion; tabs → divi/tabs; contact → divi/contact-form',
			'- Note: "-item"/"child" modules (accordion-item, pricing-cards-item, …) only live inside their parent module — never place one at column level.',
			'',
			'Ready-made pack modules ship DESIGNED defaults — spacing, hover states, icon treatment you would otherwise have to hand-tune. Using one is both faster and better-looking; reserve primitive composition for genuinely custom layouts.',
			'',
			'## Authoring format',
			'',
			'Writes take nodes of the form {type, fields, attrs, children}:',
			'- `type` — the FULL module name with namespace: "divi/text", never bare "text" (unprefixed types are rejected).',
			'- `fields` — friendly content values by field name (fields.content = "Hello"); Saddle wraps each into Divi\'s responsive envelope (<attr>.innerContent.desktop.value) for you. Composite content fields are OBJECTS with exact sub-keys: fields.button = {text, linkUrl} (NOT {text, url}), fields.image = {src, alt} — alt is required to pass verify. divi-get-module-schema prints the exact `write_as` per attribute; copy it.',
			'- `attrs` — raw canonical Divi attributes as dot-paths, deep-merged on top. This is where ALL styling goes, using paths from divi-get-style-schema — its `example` values show the correct value SHAPE (some fields take objects, not scalars: border radius is {sync, topLeft, …}, padding is {top, bottom, …}).',
			'- `children` — nested nodes; sections are auto-wrapped in the root placeholder.',
			'',
			'Structural tools: for a new page, create-page then divi-set-page. divi-set-page replaces an existing page\'s full tree (revision-backed; refuses Divi 4 pages and non-empty non-Divi posts). divi-add-module inserts at a parent address + position; divi-edit-module patches fields/attrs at an address; divi-move-module reparents/reorders; divi-remove-module (leaf removals immediate + revision-recoverable; container removals preview + confirm).',
			'',
			'ADDRESSES SHIFT: addresses are positional dot-paths ("0" = first section, "0.1.0" = first module in its second row) and change after every structural edit. Every write returns its `changed` node(s) with full attrs — for the node you just touched, no re-read is needed. For OTHER nodes, re-read with divi-get-page; the default compact mode is a cheap skeleton, pass an `address` to pull one subtree with attrs, and pass back the previous `version` so an unchanged page costs one line.',
			'',
			'## Styling — where silent failures live, read carefully',
			'',
			'The three rules that separate a designed page from a broken-looking one:',
			'',
			'1. NEVER hand-write a style path. Call divi-get-style-schema type=<module> and copy the exact "path" it returns. Paths differ per module, and typography nests deeper than you would guess: a heading title\'s color/size/align live at title.decoration.font.font.desktop.value (note font.font), not title.decoration.font.desktop.value. A wrong path saves and validates but RENDERS NOTHING — the page just silently looks unstyled.',
			'2. READ THE WRITE RESPONSE. If `warnings` lists an attribute as ignored, your key was wrong. Fix and re-write; do not continue building on top of a silent no-op.',
			'3. FILLED BUTTONS need the toggle: a button background alone renders a ghost/outline button. Set attrs { "button.decoration.button.desktop.value.enable": "on" } PLUS background PLUS font color (same inside divi/cta and pricing tables). If lint-page reports a ghost button, this toggle is almost always what is missing.',
			'',
			'4. NEVER set theme CSS classes (module.advanced.html…class) unless the user names the exact class. You cannot see theme stylesheets, so you cannot know what a class renders — applying classes found elsewhere is styling blind, and the theme-css-class lint will flag it. Style through attrs, tokens, and presets.',
			'',
			'Also:',
			'- One module type\'s paths never transfer to another — schema first, every time.',
			'- No double backgrounds: if a module sits inside a colored section/row, do not give it its own background too. Color the section OR the module, not both.',
			'- Prefer tokens: reference a global color as var(--<id>) from divi-list-global-colors so a palette change later updates every page.',
			'- Presets: divi-list-global-presets, then divi-apply-global-preset gives a module a saved style bundle in one call.',
			'',
			'## Design first — plan before you build',
			'',
			'Approach every page as a designer, not a form-filler. Before the first write, produce a short design plan and then derive every decision from it:',
			'',
			'1. READ THE REQUEST — calibrate treatment. A landing/marketing/product page is editorial: it deserves a point of view and one memorable move. A docs/policy/internal page is utilitarian: real hierarchy and considered spacing, no flashy hero. When unsure, a well-composed restrained page is never wrong; an over-designed one sometimes is.',
			'2. HONOR WHAT EXISTS — precedence is: the user\'s explicit words, then the site\'s design system (global colors, variables, fonts, presets — step 4), then your own choices. Never introduce ad-hoc values where a token exists.',
			'3. WRITE THE PLAN — three lines, and hold every write to them:',
			'   - Palette: 4-6 named values (ground, surface, text, muted text, ONE accent). Pick neutrals deliberately — a grey with a slight hue bias toward the accent reads as chosen; pure mid-grey reads as default.',
			'   - Type: the site fonts (in the context bundle) mapped to a type scale you will not deviate from (see numbers below).',
			'   - Layout: the layout concept in one sentence ("dark centered hero, then alternating two-column feature rows, closing CTA band").',
			'4. GROUND IT IN THE SUBJECT — the page\'s content world should drive imagery, vocabulary, and emphasis. Build with the user\'s real content; never lorem, never placeholder cards left in.',
			'',
			'## Composition — the document-look tells',
			'',
			'The most common way a technically-valid page fails is COMPOSITION, not styling. Four tells, each with a lint rule watching for it:',
			'- Cap width ⇒ CENTER. Whenever you set maxWidth on a module, also set sizing alignment center (or auto side margins) — or place content beside it so the asymmetry is deliberate. maxWidth alone renders pinned left with a dead right half (pinned-max-width).',
			'- No long runs of single-column rows. Heading row, text row, heading row, text row reads as a Word document, not a page (single-column-flow). Merge running text into ONE text module; group related items into 2-3 column rows; pair text with media in asymmetric splits.',
			'- Structure encodes meaning: features belong in a grid, steps in a sequence, comparison in columns. If scrolling the page reads like a printed doc, recompose before styling.',
			'- A row is a design decision, not a paragraph break. New row = new visual idea.',
			'',
			'## Design quality bar — what "designed" means in numbers',
			'',
			'Agents that skip these produce technically-valid but amateur pages. The shared bar (same numbers every session carries):',
		);
	}

	/**
	 * Skill body lines after the embedded design numbers.
	 *
	 * @return string[]
	 */
	private static function divi_skill_tail() {
		return array(
			'',
			'Divi-specific additions:',
			'- Eyebrows/labels 12-14px, uppercase, letter-spacing 1-3px; card titles 20-24px.',
			'- Buttons: solid fill (enable=on), one visual size per context, same baseline when side by side. Label says exactly what happens, in Title Case: "Start Free Trial", "Get the Guide" — never "Click Here", "Continue", or "Learn More" when something specific exists.',
			'- On imagery, add an overlay or scrim before dropping text on a photo; lint-page flags WCAG AA failures.',
			'- Images: always set alt text via fields.image (a real description; empty alt only when purely decorative).',
			'- Pricing rows: feature ONE plan (color/scale emphasis on the recommended plan), never three identical cards.',
			'- Motion: default to none. Divi entrance animations only when the user asks or the treatment clearly calls for one orchestrated moment — scattered per-module animations read as AI-generated, and users with reduced-motion preferences see janky pages.',
			'',
			'## Avoid the AI-generated look',
			'',
			'Generated pages cluster around a few tells. If the user asked for one of these, follow their words exactly — otherwise spend your freedom elsewhere:',
			'- The purple-to-blue gradient hero on white; the lone acid-green accent on near-black; warm cream + serif + terracotta as a reflex.',
			'- Everything centered. Center the hero if it earns it, but body sections read better with varied, mostly left-aligned composition (width-capped columns still get centered or deliberately balanced — see Composition).',
			'- The same safe typeface treatment on every page regardless of subject. Use the site\'s fonts with intent — weight and scale contrast is free personality.',
			'',
			'## Copy is design material',
			'',
			'- The headline is a thesis, not a label: lead with the most characteristic true claim ("Sound that moves with you", not "Welcome to our website").',
			'- Active voice, second person, specific beats clever. Numerals for counts ("8 integrations", not "eight").',
			'- Every button label names its outcome; every section heading earns its place. If a heading could sit on any website, sharpen it.',
			'- Errors and empty states (forms, contact modules) say what to do next, not just what went wrong.',
			'',
			'## Worked example — a styled hero (content + styling together)',
			'',
			'divi-set-page post=<id> nodes:',
			'[ { "type": "divi/section",',
			'    "attrs": { "module.decoration.background.desktop.value.color": "#0f172a",',
			'               "module.decoration.spacing.desktop.value.padding": { "top": "96px", "bottom": "96px" } },',
			'    "children": [ { "type": "divi/row", "children": [ { "type": "divi/column", "children": [',
			'      { "type": "divi/heading", "fields": { "title": "Sound That Moves With You" },',
			'        "attrs": { "title.decoration.font.font.desktop.value": { "color": "#ffffff", "size": "56px", "weight": "700", "textAlign": "center" } } },',
			'      { "type": "divi/text", "fields": { "content": "Premium wireless audio for all-day comfort." },',
			'        "attrs": { "content.decoration.bodyFont.body.font.desktop.value": { "color": "#cbd5e1", "size": "18px", "textAlign": "center" } } },',
			'      { "type": "divi/button", "fields": { "button": { "text": "Shop Now", "linkUrl": "/shop" } },',
			'        "attrs": { "button.decoration.button.desktop.value.enable": "on",',
			'                   "button.decoration.background.desktop.value.color": "#2563eb",',
			'                   "button.decoration.font.font.desktop.value": { "color": "#ffffff", "weight": "600" } } }',
			'] } ] } ] } ]',
			'',
			'(Paths shown are illustrative of the shape — confirm each against divi-get-style-schema for the module and Divi version you are on. Then check the response `warnings`, then run lint-page.)',
			'',
			'## Loops — repeated, query-driven content',
			'',
			'To repeat a module once per query result (a card per latest post):',
			'- divi-list-loop-query-types — valid query types (post_types, terms, users, user_roles, menus, post_taxonomies) and their sub_types.',
			'- divi-enable-loop post=<id> address=<addr> query_type=post_types sub_types=["post"] per_page=6 — replaces any existing loop config.',
			'- divi-edit-loop changes parameters in place; divi-disable-loop switches off but keeps the config.',
			'- Never hardcode content that should be query-driven; loop it instead.',
			'',
			'## Dynamic content — bind fields to live data',
			'',
			'- divi-list-dynamic-sources — post values (post_title, post_excerpt, post_featured_image, …), site values, per-item loop sources.',
			'- divi-apply-dynamic-content address=<addr> field=<attr path from the module schema, e.g. "title.innerContent"> source=post_title — inside a loop-enabled module this resolves per item.',
			'- divi-clear-dynamic-content removes a binding.',
			'- A typical loop card: enable a loop on the column, then bind heading → post_title, text → post_excerpt, image → post_featured_image.',
			'',
			'## Reuse and structure',
			'',
			'- Display conditions: divi-set-display-conditions shows/hides a module by rule (logged-in status, post type, …); divi-list-condition-types for options.',
			'- Library: divi-list-library-items lists the saved layouts, sections, rows and modules.',
			'- Theme Builder: divi-list-theme-builder-templates lists the templates; divi-list-theme-builder-conditions lists what a template can be assigned to.',
			'',
			'## Verify before you call it done — the loop IS the job',
			'',
			'You built open-loop until now; close it. Never end on a write call\'s success — end on evidence:',
			'',
			'1. saddle/verify-page post_id=<id> — the scored report over the SAVED state. It composes everything: structural validation, silently-ignored attrs, accessibility, and the design lint. Fix by address in the order it ranks (structural → ignored → errors → warnings), re-run, repeat until the score is one you would defend to the user.',
			'2. When a finding needs eyes: saddle/render-node address=<addr> shows the node\'s effective persisted styles (tokens resolved to real values) and HTML. When only pixels will settle it: saddle/get-preview-url, open the URL in your own browser, screenshot, and judge the render against your plan.',
			'3. Confirm publish status matches the user\'s expectation (draft vs live).',
			'4. Every page edit is revision-backed — if the user dislikes the result, the previous version is one rollback away.',
			'',
			'Known limitation: server-side checks cannot see the final rendered pixels — that is exactly what get-preview-url is for. An aggressive theme stylesheet can override module styling (e.g. element-level p/a rules beating Divi\'s container-based colors). If the preview screenshot shows colors that verify-page and the echo both accept being ignored, say plainly that the THEME is likely overriding the builder CSS and suggest they check with a stock Divi theme — do not silently rewrite the same attrs again expecting a different result.',
		);
	}

	/**
	 * Add the Divi section when a Divi 5 theme is active.
	 *
	 * @param string $context Assembled system context.
	 * @param string $tier    Current access tier.
	 * @return string
	 */
	public static function append_divi_guidance( $context, $tier ) {
		// An older add-on may already have appended this section; never twice.
		if ( ! Saddle_Divi::is_active() || false !== strpos( $context, "\n## Editing Divi pages" ) ) {
			return $context;
		}

		// Only the always-relevant safety rules ride every session. The full
		// how-to (authoring format, workflow, worked example) lives in the
		// divi-build-page skill and is loaded on demand — see builtin_skills().
		$lines = array(
			'',
			'## Editing Divi pages',
			'',
			'This site runs Divi ' . Saddle_Divi::version() . '. Divi 5 pages are a structured module tree (section > row > column > module) stored as blocks.',
			'',
			'- ALWAYS use the saddle/divi-* tools on Divi-built pages. Never write to a Divi page\'s `content` field with update-post/update-page — that destroys the builder layout.',
			'- Structure is validated on every write: invalid trees are rejected, not repaired. Every page edit is revision-backed.',
		);

		if ( class_exists( 'Saddle_Skills' ) ) {
			$lines[] = '- Before building or editing a Divi page, read the divi-build-page skill (saddle/get-skill name=divi-build-page) — it has the authoring format, workflow, and a worked example.';
		} else {
			$lines[] = '- Call saddle/divi-check-setup before editing, saddle/divi-get-page for the addressable module tree, divi-get-module-schema before composing an unfamiliar module, and re-read the page after every structural change (addresses shift).';
			$lines[] = '- Build real modules (divi/text, divi/image, divi/button, …), never a code module holding an HTML dump.';
		}

		// Tell the agent which purpose-built module packs are installed, so it
		// prefers a ready-made module over composing an element from primitives.
		if ( class_exists( 'Saddle_Divi_Schema' ) ) {
			$packs = Saddle_Divi_Schema::module_packs();
			if ( $packs ) {
				$summary = array();
				foreach ( array_slice( $packs, 0, 8 ) as $p ) {
					$summary[] = sprintf( '%s (%d)', $p['name'], $p['count'] );
				}
				$lines[] = '- This site has extra Divi module packs installed: ' . implode( ', ', $summary ) . '. For a real web element (info box, pricing table, carousel/slider, gallery, testimonial, counter, …) call divi-list-modules and PREFER a purpose-built module from these packs over composing it from core primitives — it looks far better and is less work.';
			}
		}

		// The builder memory: a session starts already knowing the site's
		// palette, tokens, and catalog size — zero discovery calls.
		if ( class_exists( 'Saddle_Divi_Bundle' ) ) {
			$lines = array_merge( $lines, Saddle_Divi_Bundle::summary_lines() );
		}

		if ( 'read' === $tier ) {
			$lines[] = '- The current access level is read-only: you can inspect Divi pages but not change them.';
		}

		return $context . implode( "\n", $lines ) . "\n";
	}
}
