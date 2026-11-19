<?php
/**
 * The divi-build-page playbook body.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Divi 5 page playbook an agent reads with get-skill. Markdown kept as
 * data: placeholders like <id> reach the agent as written.
 *
 * Every statement matches the tools as they are: the node format
 * (Saddle_Divi_Author, Saddle_Divi_Node_Attrs), the check tools, the
 * loop and dynamic content tools. The shared design numbers come from
 * Saddle_Context::design_numbers(), the single source, embedded verbatim.
 */
class Saddle_Divi_Playbook {

	/**
	 * The whole body.
	 *
	 * @return string
	 */
	public static function body() {
		return implode( "\n", array_merge( self::head(), self::numbers(), self::tail() ) );
	}

	/**
	 * The shared design numbers, verbatim, without their heading.
	 *
	 * @return string[]
	 */
	private static function numbers() {
		$numbers = array();
		if ( class_exists( 'Saddle_Context' ) && method_exists( 'Saddle_Context', 'design_numbers' ) ) {
			foreach ( Saddle_Context::design_numbers() as $line ) {
				if ( '' !== $line && 0 !== strpos( $line, '#' ) ) {
					$numbers[] = $line;
				}
			}
		}
		return $numbers;
	}

	/**
	 * From the top to the design numbers.
	 *
	 * @return string[]
	 */
	private static function head() {
		return array(
			'# Building a page in Divi 5: agent playbook',
			'',
			'A Divi 5 page is a module tree: divi/section > divi/row > divi/column > modules, stored as blocks in post_content. You never write that markup; you work through the saddle/divi-* tools. Every write is validated before it saves (an invalid tree is refused, never repaired) and answers with what it changed. Work CLOSED-LOOP: look at the site, plan, build, check the SAVED page, fix by address, check again. A write that returns success is not evidence the page is right; verify-page, the rendered HTML and the pixels are.',
			'',
			'## The workflow, in order',
			'',
			'1. SETUP: divi-check-setup post_id=<id>. Is Divi 5 active, and is the post built with divi5, divi4 or something else? Divi 4 pages are refused: leave them alone. It also lists the breakpoints this site has on (the only ones "@<breakpoint>" may name) and the post types the divi-* tools edit.',
			'2. ORIENT: divi-context-bundle, once: the module catalog and packs, global colors, variables, presets and fonts, the conventions, and "site_style". Skip the separate list calls.',
			'3. LOOK AT THE SITE before you design. Start from the bundle\'s "site_style" (divi-get-site-style gives longer lists): what the site\'s own pages use, each value with a count. Section backgrounds and padding, row width, heading and body sizes on desktop and phone, fonts, button and card styling, and the presets used most. Reuse the most used values, and write a color as the global color token "tokens" names for it. Those hand-set colors are the site\'s real ones even when they are not global colors. Read a page with divi-get-page only when you need more: the page the user names, or one of "pages", with an "address" to read one section with its attrs. divi-list-global-presets kind=all lists the module presets and the option group presets (a button, a font, spacing); without kind it lists module presets only. PRESETS FIRST, tokens second, hand styling last: a page built from the site\'s presets looks like the brand; a page styled by hand on Divi\'s defaults looks like default Divi, even when it scores well.',
			'4. PLAN: see "Before you build". Hold every write to the plan.',
			'5. BUILD: divi-set-page for the FIRST build only (it returns the full address map). For a new page: create-page, then divi-set-page; a landing page usually also needs divi-set-page-settings (full width, no title). After that, EDIT by address with divi-edit-module, divi-add-module, divi-move-module, divi-remove-module. A second full rebuild means you skipped diagnosis.',
			'6. STYLE: a preset where the site has one. A module preset: divi-apply-global-preset (mode "append" stacks a second; the last wins). An option group preset (kind "group"): divi-get-global-preset gives the "groupPreset" entry to write with divi-edit-module attrs. For the rest, divi-get-style-schema type=<module> and copy the exact path it returns.',
			'7. RESPONSIVE PASS: its own step, below.',
			'8. CHECK: every write\'s response, then verify-page, then look. See "Check before you call it done".',
			'9. PUBLISH: divi-set-page keeps the post\'s status; a draft stays a draft (visitors get a 404). If the user expects a live page, publish with update-page status=publish.',
			'',
			'## Before you build',
			'',
			'- A specific request ("add a pricing section with these three plans"): build it.',
			'- An open request ("make me a landing page"): ask at most three short questions: the goal and the main action, the audience and the tone, and what content exists (copy, images, logos). Then show a five-line plan and wait for the user\'s OK: (1) the sections in order, (2) the presets and tokens you will use, (3) the fonts and type sizes, (4) the layout concept in one sentence ("dark split hero, three feature cards, pricing, FAQ, closing call to action"), (5) what you still need from them.',
			'- Copy the user gave is used word for word. Never lorem, never placeholder cards left in.',
			'- Precedence: the user\'s words, then the site\'s design (its site_style, presets, tokens, fonts), then your own choices. Never an ad-hoc value where a token exists.',
			'- Calibrate: a landing or product page deserves a point of view and one memorable move; a docs or policy page needs clear hierarchy and calm spacing, no flashy hero.',
			'',
			'## Hard rules (the server enforces these)',
			'',
			'- Never write a Divi page\'s `content` field with update-post or update-page: SaddleWP\'s builder guard refuses it, because a raw write destroys the layout.',
			'- Build real modules, never a code module holding an HTML dump. Pages must stay editable in the Visual Builder.',
			'- Respect the structure: sections at the root, rows in sections, columns in rows, modules in columns. A column or a divi/group may also hold a nested divi/row or a group; a parent module holds only its own child type (divi/accordion > divi/accordion-item). The validator refuses anything else.',
			'- Writes need the write access level; removing a container previews first and asks for confirmation.',
			'',
			'## Finding a module',
			'',
			'1. Name the element ("pricing table", "testimonial slider", "logo carousel").',
			'2. divi-list-modules search=<term>, with 2 or 3 terms ("card", "info box", "pricing"). It covers Divi\'s modules and every active pack\'s, each with a purpose line and a group.',
			'3. A purpose-built module beats one hand-stacked from heading, text and button. Compose from primitives only when nothing fits.',
			'4. divi-get-module-schema type=<module> before you use it. "attributes" are the content fields with their exact "write_as". "settings" are EVERY other option (post count, which menu, autoplay, arrows, lightbox, the featured plan), each with its control, choices and default. "children" is what a container holds. "notes" are its known traps. A pack module\'s options come from the same call. detail="full" adds labels, descriptions and whether an option can differ by screen size or on hover.',
			'5. divi-find-module-examples type=<module>: how this site\'s own published pages already set it, as nodes you can reuse. Above all for a pack module, start from the closest example and change its text, not from the bare schema; pass exclude_post_id=<the page you build>.',
			'6. "-item" modules (accordion-item, pricing-table, slide) live only inside their parent.',
			'',
			'Common picks: a card is divi/blurb or a pack card module; pricing is divi/pricing-tables holding divi/pricing-table; divi/testimonial; numbers divi/number-counter; FAQ divi/accordion; a call-to-action band divi/cta; divi/contact-form; a menu divi/menu with its "menu.advanced.menuId" setting.',
			'',
			'## The node format',
			'',
			'Writes take nodes {type, fields, settings, attrs, columns, children}:',
			'- `type`: the full name with namespace, "divi/text", never bare "text" (refused).',
			'- `fields`: content by field name; SaddleWP wraps each in Divi\'s envelope. Text-like fields are strings (fields.title, fields.content). Composite ones are objects with the keys write_as prints: fields.button = {text, linkUrl} (not {text, url}), fields.image = {src, alt} (alt is required to pass verify).',
			'- `settings`: every non-text option, by the id the schema lists, with a plain value: {"post.advanced.number": 6, "image.advanced.lightbox": true}. A toggle takes true or false, a select one of its choices, a number 6 or "24px". A wrong id or value is refused with the valid ones, and nothing is saved.',
			'- `attrs`: styling, at the paths divi-get-style-schema returns. A path with no breakpoint is the desktop value: {"module.decoration.spacing": {"padding": {"top": "96px"}}}. Full ".desktop.value" paths still work.',
			'- Suffixes, on keys in fields, settings and attrs: "@tablet" or "@phone" for a screen size, ":hover" or ":sticky" for a state, or both: "title.decoration.font.font@phone", "button.decoration.background:hover", "module.decoration.background@tablet:hover". Name only the breakpoints divi-check-setup lists ("phone", not "phoneWide"). A suffix the option does not support is refused.',
			'- `columns` on a divi/row sets the column widths: a count (3), widths ("2/3,1/3", "1/4,1/4,1/2") or a layout name ("offset-columns_5"). The row must hold that many columns. Two or three columns stack on phones; four or five go two per line on tablets and stack on phones; six or seven go three per line on tablets and two per line on phones; eight go four per line on tablets and two per line on phones. Without widths Divi stacks every column at full width, even on desktop, so a row of several columns with none gets equal columns and the response "notes" says so.',
			'- `children`: nested nodes. Sections are wrapped in the page\'s root placeholder for you.',
			'- A divi/group holds items side by side when its layout is flex: "module.decoration.layout": {"display": "flex", "flexDirection": "row", "flexWrap": "wrap", "columnGap": "16px"}. Two buttons in a plain column stack; in such a group they sit in a row.',
			'- A Component (a Library item kept in sync) is {"type": "divi/global-layout", "attrs": {"globalModule": "<Library post id>"}}, placed where its block belongs (divi-list-library-items). On Divi 5.15 and newer its children may be divi/global-slot nodes with "attrs": {"slotId": "<id>"}.',
			'- divi-edit-module takes the same fields, settings, attrs and columns, plus "unset" (dotted paths to remove). An object merges key by key; a list (gradient stops, interactions) replaces the stored list.',
			'',
			'ADDRESSES SHIFT: addresses are positional dot-paths and change after every structural edit. Most Divi 5 pages have a root placeholder, so "0" is that root, "0.0" the first section and "0.1.0.0.0" the first module of the second section; copy addresses from divi-get-page and from every write instead of counting. Every write returns its `changed` node(s) with full attrs, so the node you touched needs no re-read. For OTHER nodes re-read with divi-get-page: compact mode is a cheap skeleton, an `address` pulls one subtree with attrs, and passing back the previous `version` makes an unchanged page cost one line.',
			'',
			'## Styling: where silent failures live',
			'',
			'1. NEVER hand-write a style path. Call divi-get-style-schema type=<module>, then again with group="font" (or "title.font" for one element) for that group\'s fields and a correct example, and copy the path. Typography nests deeper than you would guess: a heading\'s color, size and alignment are at title.decoration.font.font (note font.font); a text module\'s body text is at content.decoration.bodyFont.body.font. A wrong path saves, validates and RENDERS NOTHING.',
			'2. READ THE WRITE RESPONSE. "warnings" lists what Divi will ignore: your path or value was wrong. Fix it now; never build on a silent no-op.',
			'3. FILLED BUTTONS: set the switch as well, "button.decoration.button": {"enable": "on"}, plus a background and a font color, also inside divi/cta and pricing tables. Before Divi 5.10 the fill does not show without the switch.',
			'4. NEVER set theme CSS classes unless the user names the exact class. Divi reads a CSS id and class only at module.advanced.htmlAttributes (desktop only). You cannot see theme stylesheets, so a class is styling blind, and the theme-css-class lint flags it. Style through presets, tokens and attrs.',
			'',
			'Shapes agents most often get wrong:',
			'- "hover" and "sticky" sit beside "value", never inside it. With the ":hover" suffix you write only the fields that change.',
			'- Box shadow needs "style" ("preset1" to "preset7"), or it renders nothing. Gaps are "columnGap" and "rowGap" (no "gap"). z-index is one plain string ("10"). Uppercase goes in "capitalization", not "style".',
			'- Tokens: a global color is var(--<gcid id>) from the bundle. A font family that uses a font variable takes that variable\'s "reference" from divi-list-variables, the $variable({"type":"content",…})$ token, never var(): var(--<gvid id>) does not load the webfont. Strings, links and images take that token too.',
			'- No double backgrounds: color the section or the module inside it, not both.',
			'- Headings: set the heading level on every heading ("title.decoration.font.font": {"headingLevel": "h2"}), one h1 per page. With no level, a Heading module is an h1 and a blurb title an h4.',
			'- Icons are objects {unicode, type, weight}: copy one from a page that already uses it. Images: upload-media, then fields.image = {src, alt} with a real description.',
			'',
			'## Responsive pass (its own step, after the desktop build)',
			'',
			'Tablet and phone inherit desktop, so any value you did not revisit is your phone design. Go through every:',
			'- Multi-column row: is its "columns" split right on tablet and phone? A grid ("display": "grid") does not stack on its own: set gridColumnCount "@phone" (the grid-phone-columns lint checks).',
			'- Large heading (40px and up): a phone size, e.g. "title.decoration.font.font@phone": {"size": "32px"}.',
			'- Big padding (64px and up): a phone value of about two thirds.',
			'- Side-by-side group: "flexWrap": "wrap", or "flexDirection": "column" at "@phone".',
			'- Anything that should not show on phones: attrs "module.decoration.disabledOn.phone.value": "on".',
			'Then look: render-node lists what differs on "tablet" and "phone", and the preview at phone width shows it.',
			'',
			'## Design quality bar: what "designed" means in numbers',
			'',
			'The shared bar (the same numbers every session carries):',
		);
	}

	/**
	 * From after the design numbers to the end.
	 *
	 * @return string[]
	 */
	private static function tail() {
		return array(
			'',
			'Divi additions:',
			'- Eyebrows and labels 12-14px with "capitalization": "uppercase" and 1-3px letter spacing; card titles 20-24px.',
			'- Buttons: ONE filled main action per section (the one-main-action note watches); the rest outline or text. Labels say what happens, in Title Case: "Start Free Trial", never "Click Here" or "Learn More" when something specific exists.',
			'- Text on a photo needs an overlay or scrim first; lint-page flags WCAG AA failures.',
			'- Pricing: feature ONE plan (the "module.advanced.featured" setting), never three identical cards.',
			'',
			'## Composition: the document-look tells',
			'',
			'The most common way a valid page fails is COMPOSITION, not styling. Each tell has a lint rule:',
			'- Cap width, then CENTER: whenever you set maxWidth, also set the sizing "alignment": "center", or put content beside it so the asymmetry is deliberate (pinned-max-width).',
			'- No long runs of single-column rows: heading row, text row, heading row reads as a Word document (single-column-flow). Merge running text into ONE text module; put related items in 2-3 column rows; pair text with media in an asymmetric split.',
			'- Structure encodes meaning: features in a grid, steps in a sequence, a comparison in columns. A row is a design decision, not a paragraph break.',
			'',
			'## Avoid the AI-generated look',
			'',
			'If the user asked for one of these, follow their words; otherwise spend your freedom elsewhere:',
			'- The purple-to-blue gradient hero on white; the lone acid-green accent on near-black; cream, serif and terracotta as a reflex.',
			'- Everything centered. Center a hero if it earns it; body sections read better mostly left-aligned (capped columns still get centered or balanced).',
			'- The same safe type on every page. Use the site\'s fonts with intent: weight and size contrast are free personality.',
			'- Motion: default none. An entrance animation only when the user asks or one orchestrated moment calls for it; scattered animations read as generated.',
			'',
			'## Copy is design material',
			'',
			'- The headline is a thesis, not a label: "Sound that moves with you", not "Welcome to our website".',
			'- Active voice, second person, specific beats clever. Numerals for counts ("8 integrations").',
			'- Every button names its outcome; every section heading earns its place. If a heading could sit on any website, sharpen it.',
			'- Forms and empty states say what to do next, not only what went wrong.',
			'',
			'## Section recipes',
			'',
			'list-section-recipes lists the recipes this site offers. get-section-recipe name=<name> returns Divi nodes with column widths, phone values and the accent token, and takes the user\'s content (heading, text, buttons, items, plans, quotes, questions) in place of its placeholders; "content_ignored" names what had no place. Use recipes for native sections (pricing, FAQ, testimonials, a call-to-action band), then apply the site\'s presets. A page built only from recipes looks generic.',
			'',
			'## Worked example: a split hero on the site\'s tokens',
			'',
			'If the site has a heading or button preset, apply it (divi-apply-global-preset) and leave out the hand styling it covers. Else style by hand as below, with the site\'s own tokens or the four colors every Divi site has: gcid-primary-color, gcid-secondary-color, gcid-heading-color, gcid-body-color.',
			'',
			'divi-set-page post_id=<id> nodes:',
			'[ { "type": "divi/section",',
			'    "attrs": { "module.decoration.spacing": { "padding": { "top": "96px", "bottom": "96px" } },',
			'               "module.decoration.spacing@phone": { "padding": { "top": "64px", "bottom": "64px" } } },',
			'    "children": [ { "type": "divi/row", "columns": "1/2,1/2", "children": [',
			'      { "type": "divi/column", "children": [',
			'        { "type": "divi/heading", "fields": { "title": "Sound That Moves With You" },',
			'          "attrs": { "title.decoration.font.font": { "headingLevel": "h1", "color": "var(--gcid-heading-color)" },',
			'                     "title.decoration.font.font@phone": { "size": "36px" } } },',
			'        { "type": "divi/text", "fields": { "content": "<p>Premium wireless audio for all-day comfort.</p>" } },',
			'        { "type": "divi/button", "fields": { "button": { "text": "Shop Now", "linkUrl": "/shop" } },',
			'          "attrs": { "button.decoration.button": { "enable": "on" },',
			'                     "button.decoration.background": { "color": "var(--gcid-heading-color)" },',
			'                     "button.decoration.background:hover": { "color": "var(--gcid-body-color)" },',
			'                     "button.decoration.font.font": { "color": "#ffffff" } } } ] },',
			'      { "type": "divi/column", "children": [',
			'        { "type": "divi/image", "fields": { "image": { "src": "<media library URL>", "alt": "Headphones on a walnut desk" } },',
			'          "settings": { "image.advanced.lightbox": true } } ] } ] } ] } ]',
			'',
			'Swap the image src for a URL from the media library. The heading is the page\'s one h1 with a phone size; the button shows the token form and a hover state; the row\'s "columns" stacks the image under the text on phones. Then read the response "warnings" and "notes", and run verify-page.',
			'',
			'## Loops: repeated, query-driven content',
			'',
			'- divi-list-loop-query-types: the query types (post_types, post_taxonomies, user_roles, menus, current_page, acf_repeater), this site\'s sub_types, the order_by values and the filters.',
			'- divi-enable-loop post_id=<id> address=<the column or group holding one card> query_type=post_types sub_types=["post"] per_page=6 starts a fresh config and keeps the loop id. An empty sub_types on a post or taxonomy loop keeps the default; a user_roles loop needs the list_users capability. A module with its own query (blog, portfolio, gallery) cannot loop; loop its column instead.',
			'- divi-edit-loop changes only what you pass (include or exclude posts and terms, meta_query, exclude_current_post). divi-disable-loop switches the loop off and keeps its config.',
			'- Pagination: add a divi/post-nav and set "module.advanced.targetLoop.desktop.value" in its attrs to the loop_id the loop tools return. A divi/post-filter connects the same way.',
			'- Never hardcode content that should be query-driven; loop it.',
			'',
			'## Dynamic content: bind fields to live data',
			'',
			'- divi-list-dynamic-sources: the page sources (post, author, site, term, product, custom fields) and, per loop type, the loop_ sources with their settings.',
			'- divi-apply-dynamic-content address=<addr> field=<"title.innerContent", or one part: "image.innerContent.src", "button.innerContent.linkUrl"> source=<name>. Inside a loop, only loop_ sources change per item: post_title shows the page\'s own title on every card.',
			'- A typical card: loop the column, then bind the heading to loop_post_title, the text to loop_post_excerpt, image.innerContent.src to loop_post_featured_image, image.innerContent.alt to loop_post_featured_image_alt_text (else every card shares one alt) and button.innerContent.linkUrl to loop_post_link.',
			'- A custom field: source=post_meta_key settings={"meta_key": "price"}; in a loop, source=loop_post_meta_key_manual_custom_field settings={"loop_meta_key": "price"}.',
			'- divi-clear-dynamic-content removes a binding.',
			'',
			'## Interactions, conditions and reuse',
			'',
			'- divi-add-interaction trigger_address=<addr> target_address=<addr> trigger=click effect=toggleVisibility (also mouseEnter, viewportEnter, load; addAttribute, scrollToElement, …) writes the interaction and the ids Divi needs on both modules. Never write the interactions group or the two interaction ids (interactionTrigger, interactionTarget) by hand: use divi-add-interaction.',
			'- divi-set-display-conditions shows or hides a module by rule (logged in, post type, …); divi-list-condition-types lists them.',
			'- divi-list-library-items lists saved layouts, sections, rows, modules and Components; divi-list-theme-builder-templates and divi-list-theme-builder-conditions show the templates and where they apply.',
			'',
			'## Check before you call it done: the loop IS the job',
			'',
			'Never end on a write call\'s success. End on evidence:',
			'1. Every write\'s response: `changed` is the persisted node; "warnings" are attributes Divi will ignore; "notes" are what SaddleWP added on its own (equal columns). Fix warnings at once.',
			'2. saddle/verify-page post_id=<id>: one scored report over the SAVED page: structure, ignored attributes, accessibility and the design lint, each finding at an address with a fix hint. Fix in order (structural, ignored, errors, warnings) and re-run. Lint advice of severity "note" (a second filled button, a third font, a button with no hover) comes apart under "notes": weigh it; it costs no points.',
			'3. divi-get-rendered-page post_id=<id>: the page\'s real HTML and Divi\'s CSS for it, size-capped. Use it to confirm that a value you set reached the CSS, or to see what a module printed.',
			'4. saddle/render-node address=<addr>: one node\'s effective styles (tokens resolved), with what differs on "tablet", "phone" and "hover".',
			'5. saddle/get-preview-url: real pixels. Open the URL in your own browser, wait about two seconds (Divi loads its CSS after the page, so an early screenshot looks unstyled), then screenshot desktop and phone width and judge them against the plan.',
			'6. Confirm the publish status the user expects. Every edit is revision-backed: the previous version is one rollback away.',
			'',
			'Known limitation: an aggressive theme stylesheet can override module styling (element-level p and a rules beating Divi\'s colors). If the screenshot shows colors that verify-page and the echo both accept being ignored, say plainly that the THEME is likely overriding the builder CSS and suggest checking with a stock Divi theme. Do not rewrite the same attrs again expecting a different result.',
		);
	}
}
