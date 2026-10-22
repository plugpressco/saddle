# Saddle — Design Alignment

> **BRAND COLOR CHANGED (2026-10-12, Fahim; #345):** one brand color for the
> plugin, saddle.to and Cloud: **Saddle magenta `#D000C4`**, replacing Petrol.
> Where the brand may appear does not change. Petrol below is history; read
> every "Petrol" in this file as "the brand".

> **DECIDED (2026-10-01, Fahim; #280):** the admin is **WordPress-native with one
> brand color, Petrol teal `#0B6470`**. Fahim reviewed Jetpack's admin and asked
> for "a simple UI compliant with the default WordPress UI yet representing the
> plugin's branding", with less clutter, less text and no oversized type. He
> chose a full-width white header band with the tabs inside it, and Petrol over
> a deeper olive and a graphite option. This supersedes the olive decision
> (2026-09-26) and the 85/15 rule below it. Don't re-litigate the color; do
> hold the line on where it may appear.

> **Surfaces updated again (2026-10-02, Fahim, #307):** "why no bg color …
> border around card", looking at Jetpack's Modules screen next to Saddle's
> Dashboard on the same site. The all-white page with `#ddd` outlines read as
> heavy boxes with nothing behind them. The surfaces are now **Jetpack's**,
> measured: a `#FCFCFC` page under a white header band, white blocks with a
> `#F0F0F0` hairline, 8px corners and one faint shadow
> (`0 1px 2px rgba(0,0,0,.05)`). Greys, links and focus stay WordPress's.

> *Superseded (2026-10-01):* one white canvas with white blocks on a `#DDD`
> hairline and no shadow, measured against WordPress 7.1's Connectors screen.
> That replaced wp-admin's legacy grey page (`#F0F0F1`) and a hard-coded
> `#2271B1`.

## The palette

Everything except the brand is WordPress's own value, so a Saddle page sits in
wp-admin like a core screen.

| Role | Hex | Contrast |
|---|---|---|
| **Brand** (Saddle magenta, was Petrol `#0B6470`) | `#D000C4` | white on it 4.69; on white 4.69 |
| Brand hover / press | `#B000A6` / `#900088` | white 6.18 / 8.26 |
| Brand text (small text on the tint) | `#A3009A` | on tint 5.7; on white 6.9 |
| Brand tint / soft | `#FDE0FB` / `#F9C2F4` | brand text on tint 5.7 (the brand itself is 3.86, marks only) |
| Canvas (page) | `#F0F0F1`, wp-admin's own body grey (palette v3) | white blocks on it 1.14:1 |
| Header band | `#FFFFFF`, `#DCDCDE` hairline under it | |
| Surface (blocks) | `#FFFFFF`, `#DCDCDE` edge, 8px corners, `0 1px 2px rgba(0,0,0,.04)` | |
| Surface 2 (row hover, code chips, sub-panels) | `#F6F7F7` | |
| Border / border 2 | `#DCDCDE` / `#C3C4C7` | block edges and row dividers, decorative |
| Field edge, switch off | `#8C8F94` (`--s-field-edge`) | 3.24 on white; fields sit on blocks |
| Text / secondary | `#1D2327` / `#646970`, one grey for every description, meta line, hint and footer | 15.89 / 5.53 on white, 13.95 / 4.86 on the page |
| Tab underline | `#50575E` (`--s-tab-indicator`) | 7.33 |
| Module sidebar | a white panel with a `#DCDCDE` hairline on its right, joined to the header and the tab band; hover `#F6F7F7` (`--s-nav-hover`); the current section is the page grey `#F0F0F1`, weight 600 | |
| Dark overlays, code panels | `#1D2327` (`--s-overlay`, `--s-code-bg`) | |
| Links, focus | `var(--wp-admin-theme-color)` (Default `#2271B1` 5.17 on white, 4.54 on the page; Modern `#3858E9` 5.36) | follows the owner's admin color scheme |
| Success | `#007017` on `#EDFAEF` | 5.86 |
| Warning | `#8A4F00` on `#FEF8EE` | 6.21 |
| Danger | `#B32D2E` on `#FCF0F1` | 5.67 |
| Info | `#2271B1` on `#F0F6FC` | 4.75 |

It lives in `admin/src/_tokens.scss` as consumer-side `--pp-*` overrides (the
brand as `--saddle-brand*`), and `style.scss` writes no colour of its own: every
value is a token, so one file recolours Core, every module and the siblings'
full-screen pages. **Never in `@plugpress/ui`**: the library is
off-limits from a plugin task, and the DS defines its tokens inside `:where()`
(zero specificity), so a plain class selector wins with no `!important`. The
selector names `.pp-app`, the body class **and `.pp-scope`**: portaled layers
(drawers, `Select` lists, menus, tooltips) carry `.pp-scope`, where the DS
re-declares its own tokens, so without it they fall back to the DS's lilac
greys.

WordPress's warning yellow `#DBA617` (2.22 on white) is a mark only, never
text; its red `#D63638` (4.73) is too thin for text, so text uses `#B32D2E`.

## Where the brand may appear

**Only:** the mark in the header band · primary
buttons (`--pp-action`) · switches, radios and checkboxes when on · the
selected role (its tick and tint) in an app's access list on Agent setup · chart
ink, through `--saddle-chart-ink` (the one data series; see "The family").

**Never:** links (WordPress blue), focus rings (WordPress's theme color), text,
headings, borders, icons, backgrounds of blocks or bands. If a screen seems to
need more teal, it needs less of something else.

The dark overlays (tooltip, toast, coachmark, apply bar, bulk bar) read their
ground from `--pp-action` in the kit, so `style.scss` pins them to `--s-overlay` (`#1D2327`);
code panels are pinned the same way. Both are kit seams.

## The frame

Every Saddle page, module pages and first run included, is drawn by
`admin/src/components/Frame.jsx`:

- **Header band:** white over the `#FCFCFC` canvas, full width of wp-admin's
  content area, `16px 24px`, one `#F0F0F0` hairline under it. Row 1 is the
  breadcrumb in the `h1` (15px): the 22px mark in Petrol and "Saddle", then
  `/` and the page's name. Home reads `[mark] Saddle`; every other page
  `[mark] Saddle / Page` ("Saddle / Agent setup", "Saddle / Rank", "Saddle /
  Welcome" on first run). The parts before the last are grey links (Saddle
  goes to Home); the last is `#1E1E1E` at 600. See "Navigation" for a
  drill-in. On the right, the notices bell and the **AI switch**: a pill
  reading "AI on" (green dot) or "Paused" (amber dot) that opens a small
  panel with what the state means and one button, "Pause all apps" or
  "Resume". The switch lives here, on every page, and nowhere else. While
  paused, a strip under the header says "AI is paused. No app can read or
  change this site until you resume." with Resume, on every Saddle page.
  Row 2, on a Core page with two or more tabs (none today): the minimal
  tabs below. **No description sentence** under the title.
- **Minimal tabs** (Jetpack AI's, WordPress 7's): the kit `Tabs`, 48px tall,
  13px regular, every tab `#1E1E1E` with its 16px icon, a 1.5px `#6E6E6E`
  line under the active one. No brand color on tabs (2026-10-02, Fahim: the
  teal underline and grey idle tabs looked dated).
- **Page:** the `#F0F0F1` canvas. A Core page is one centered 960px column
  (Home: 1040px, for its two columns). A module page has two columns: the
  sidebar panel and the content, 920px from the left (see "Navigation").
- **Footer strip:** a hairline, then mark · "Saddle 1.5.0" (and the module's
  product and version on a module page) · Docs · Rate Saddle, in 13px grey.

Never draw the frame while the app is loading: WordPress's `common.js` moves
every `.notice` after the first `.wrap h1`, and the header's `h1` would pull the
quarantined notices back into view.

## The family (2026-10-02)

Analytics, SEO (Rank), CRM, Blocks and Pro look like Core because Core paints
them. A module brings content, not paint. The rules, F1 to F11, are checked by
computed style or by grep:

- **F1 One palette, one owner.** Tokens live in `admin/src/_tokens.scss` and
  nowhere else. A module defines no `--pp-*` and no `--saddle-brand*`, and
  imports no `tokens/accents/*` file.
- **F2 One frame.** `Frame.jsx` draws the header band and its breadcrumb, the
  paused strip, a module's sidebar and icon tab row, the canvas and the
  footer. A module draws no header, top bar, rail, sidebar, wordmark,
  breadcrumb, description sentence, footer, toaster or tooltip provider.
- **F3 One kit, through Core.** Primitives and icons come from the `ui` prop
  (`ui.icons` for icons). A module bundles no copy of `@plugpress/ui`, no
  Tailwind, no icon, toast or font library, and no `@wordpress/components` on
  an admin screen (a block-editor sidebar or a full-screen editor may).
- **F4 One nav, four levels.** WordPress's Saddle submenu picks the module
  (Saddle → Name). Core draws the rest from the descriptor: the sections
  (`tabs`, `&tab=`) as the sidebar, a section's pages (`subtabs`, `&sub=`)
  as the icon tab row, and one item (`&view=` with the module's own
  `&args`) as a drill-in. No hash router, no inner nav pills, no nav of the
  module's own.
- **F5 Three type sizes.** 15 for titles, 13 for everything, 12 for badges,
  counts and chips, and 20/600 for a stat value. Use the `--pp-text-*` tokens.
- **F6 Structure and register.** Page → tab → section → block → row. One idea
  per block, no sentence under a heading unless it carries a fact, no captions
  under numbers, never a "—" tile, say a thing once.
- **F7 The brand (Petrol until 2026-10-12) in the places listed above, and in charts only as ink.** The one
  data series is `--saddle-chart-ink`, the comparison series
  `--saddle-chart-compare` dashed, grid `--saddle-chart-grid`, labels
  `--saddle-chart-text`. A vendor's bar keeps the vendor's colour; an unknown
  vendor is `#707070`, never the brand.
- **F8 Marks and names.** Inside the frame the mark is Core's and the name is
  the job word (Analytics, Rank, CRM).
- **F9 Status and notices through Core.** A module's state is its `status`
  callable, its setup is `setup` tasks (Core draws the unfinished ones above
  the first tab), its warnings are `saddle_notices`. No `admin_notices` on a
  Saddle page, no welcome dialog, no first-run flags of its own.
- **F10 Settings through the schema.** A module's settings are a `settings`
  callable; Core draws the form and exposes the module tools.
- **F11 Pages outside the frame** (a full-screen editor, a Dashboard widget)
  call `Saddle_Modules::enqueue_palette()`: tokens only, under
  `body.saddle-palette` and inherited from the body. The page gets no
  `pp-scope` or `pp-app` class, so nothing else on it changes, including
  other plugins' kit widgets.

## Navigation (2026-10-04, MODULE-LAYOUT.md)

Fahim: "saddle should clear header each, also a sidebar not sub menu", "for
big modular like seo and rank there many menu and sub tab, so need clear
view and easy user", "icon saddle / rank or icon saddle / crm". This
replaces the 2026-10-03 rule that a page had one tab row and no sidebar.
The plan and the section maps for Rank, CRM and Analytics are in
`planning/MODULE-LAYOUT.md`.

- **Four levels, one control each.**

  | Level | Control | Address |
  |---|---|---|
  | Module | WordPress's Saddle submenu | `admin.php?page=saddle-{key}` |
  | Section | The module's left sidebar | `&tab=` |
  | Page | The icon tab row | `&sub=` |
  | Single item | The drill-in | `&view=` and the module's `&args` |

  The first section and a section's first page are the defaults and are
  left out of the address. An unknown `&tab=` or `&sub=` lands on the
  first. A filter over a list stays `FilterTabs`, with counts; a mode
  switch (range, size, grouping) stays `SegmentedControl`. Neither carries
  icons.
- **The menu.** Saddle → Home, then the products (Analytics, Rank, CRM),
  then a hairline, then Agent setup, Context and Settings, then Get Pro
  while Saddle Pro is not active (#373). **Since 2026-10-23 (Fahim: "re
  order menu better way", "remove border menu in sub menu make clean list
  menus", then "instead home can we call overview - analytics, then context
  then mcp or settings and get pro with crown icon"): Overview (was Home),
  the add-ons (Analytics first and always, Saddle's placeholder until
  Saddle Analytics is on), Context, Agent setup (briefly named MCP the
  same day; Fahim: "call it context and Agent setup"), Logs (Overview's
  activity feed, moved: "Activity move to submenu as new page call
  Logs"), Settings, Get
  Pro; no dividing line. Get Pro is the one item with an icon: a 16px gold
  crown (`#f0c33c`, inline style, no menu stylesheet), and it is gone once
  Saddle Pro is installed, active or not.** Labels
  only, no icons (#369, 2026-10-22, Fahim: "remove icons of submenu of
  saddle"; they had one each since 2026-10-03). The hairline is drawn only when at
  least one product is there: `Saddle_Settings::order_submenu()` gives the
  first of Core's pages after the products the `saddle-menu-group` class,
  and one `admin_head` stylesheet (printed only for users who can see the
  menu) draws `box-shadow: inset 0 1px 0 rgba(128,128,128,.3)` with 6px
  above and inside. Page titles (`<title>`) stay plain text.
- **The header is a breadcrumb on every page** (see "The frame"). A drill-in
  reads `Saddle / CRM / Campaigns / Spring sale`: the module part goes to
  the module's first section, the section part to the section and page the
  item was opened from, with no view. Both are real links (a middle click
  opens a new tab); a plain click stays in the app, so the screen unmounts
  and can save what the owner typed.
- **The sidebar is for modules only.** Every module page has one
  (`components/SectionNav.jsx`); Core's pages keep the header and their tab
  row, if they have one.
  - 220px wide, a white panel with a `#DCDCDE` hairline on its right edge,
    running the full height, so it joins the white header above and the
    icon tab row's white band beside it. Its list sticks under the admin bar
    (32px; 46px below 782px).
  - Each item: a 16px Iconoir icon, 8px, a 13px label; 32px tall, 6px
    corners, `#1D2327`. Active: the page grey `#F0F0F1`, weight 600,
    `aria-current`; no ring, no shadow. Hover: `#F6F7F7`.
  - The module's sections in its order, Settings last after a hairline the
    items' full width.
  - No Petrol, no badges, no module name (the header names it).
  - Items are real links; a plain click navigates in place.
- **The icon tab row** is a section's pages: a white band with a `#DCDCDE`
  hairline at the top of the content column, joining the sidebar's panel,
  the minimal tabs (48px, 13px, 16px icon, 1.5px `#50575E` indicator), on
  the content's left edge, 40px from the panel. Drawn only when a section has two or more pages; aim for five
  at most. Icons show only when every page has one.
- **A drill-in (K4).** A module opens one item with `&view=` inside a page
  and names it with `props.header?.drillIn?.( { title } )`. The breadcrumb
  then ends with the title and the icon tab row steps aside; the sidebar
  stays. Core clears the title when the view closes, the page or section
  changes, or the screen unmounts. Drill-ins are opt-in: a screen that never
  calls it keeps the icon tab row. The logic is `admin/src/frame-logic.js`.
- **Phone, below 782px.** The sidebar becomes one horizontally scrolling row
  above the content, sticky under the admin bar; the icon tab row scrolls
  too, and both bring the active item into view. WordPress folds its own
  menu into the hamburger.
- **Content width.** 920px inside a module, starting 40px after the
  sidebar, on the left: centring it in the space left over opened a gap
  that grew with the screen. Core pages 960px, Home 1040px.
- **The right column is Home's only.** No search, no command palette, no
  assistant box, no upsell, no right panel or bottom bar.
- **Licence is a section on Saddle → Settings.** There is no Licence tab, and
  Pro never gets a menu item.

## Icons (2026-10-03)

Fahim: "for sidebar and tab icons ... use https://iconoir.com/ svg icons".
Iconoir 7.12.1 (MIT) is the family's one icon set: regular style, stroke
1.5, `currentColor`.

- **Sizes:** 16px in the tabs, 20px in the header and on Home's tiles, a
  6px gap to the label. The WordPress menu has none (#369).
- **Colour:** never Petrol. An icon is the colour of the text beside it.
- **Labels:** no icon without a label. The one exception is an icon button
  with a tooltip and an `aria-label`.
- **One meaning, one icon:** Overview `dashboard-dots`, Settings `settings`,
  Back `nav-arrow-left`.
- **No emoji and no check-mark characters in any UI string.**
- **Logos are not icons.** App and vendor logos (LobeHub, Simple Icons,
  flags, Rank's company marks) keep their own colours and stay.

| Item | Menu icon | Sections and pages |
|---|---|---|
| Home | `home-simple-door` | none |
| Analytics | `graph-up` | The map in `planning/MODULE-LAYOUT.md` |
| Rank | `search-engine` | The map in `planning/MODULE-LAYOUT.md` |
| CRM | `send-mail` | The map in `planning/MODULE-LAYOUT.md` |
| Agent setup | `sparks` (no longer drawn in the menu) | none |
| Context | `brain` | none |
| Settings | `settings` | General (one tab, no row) |
| Header | notices `bell` | |

Every section and page icon in those maps is in the allowlist.

**The pipeline and the allowlist (K2).** `npm run icons`
(`scripts/icons.mjs`) holds the manifest. It copies each icon from
`node_modules/iconoir/icons/regular/` to `assets/icons/<name>.svg` with
`width` and `height` removed (svgr's svgo preset drops the viewBox when both
are present) and with any child `stroke-width` that repeats the root's
removed (so a `strokeWidth` prop reaches every stroke). It also writes
`admin/src/icons/iconoir.js`: one svgr import per file, `byName`, `uiNames`
and `NavIcon( { name, size = 16 } )`, which draws nothing for an unknown
name. Both outputs are committed, and a second run changes nothing. The
files in `assets/icons/` are the allowlist; PHP reads them through
`Saddle_Nav_Icons` (`names()`, `svg( $name, $size )`). To add an icon, check
the name exists in `iconoir@7.12.1/icons/regular/`, add it to the manifest,
run the script and commit both outputs. A sibling asks Core for a new one.

**Descriptor icons (K1).** In `saddle_modules`, `'icon' => 'graph-up'` is
the menu item's icon, `'tab_icons' => array( 'reports' => 'reports' )` maps
section keys to icons, and `'subtab_icons' => array( 'reports' => array(
'sources' => 'globe' ) )` maps each section's pages to icons. Core fills the
defaults by key: `overview` is `dashboard-dots`, `settings` is `settings`.
A name outside the allowlist draws no icon and is never an error. `tabs`
and `subtabs` stay `key => label`; an icon never goes inside them. Older
Core ignores all three, so a sibling can ship them first.

**Pages (M1 to M3).** `'subtabs' => array( 'reports' => array( 'sources' =>
'Sources', 'pages' => 'Pages' ) )` names the pages inside a section.
`Saddle_Module_Nav` cleans them: keys through `sanitize_key`, non-empty
string labels, known sections only, and a section keeps its pages only
when two or more survive. A screen registers per page with `{ module, tab,
sub, Component }`; Core picks module, tab and sub first, then the screen
for the whole section, and passes `sub` (the resolved page, or '' for a
section with no pages). A module registers per-page screens only when
`window.saddleShell?.has?.( 'subtabs' )`, and today's section screens
otherwise.

**`ui.icons` (K3).** The 59 keys the kit's icon set had, plus `Grid` and
`ArrowLeftRight`, each drawn with Iconoir (`admin/src/icons/kit.js`; the
mapping is `UI` in `scripts/icons.mjs`). Each takes the kit's props: `size`
(default 18), `strokeWidth` (default 1.5), `color`, `className` and
`aria-label` (without one the icon is hidden from assistive technology).
Refs are not forwarded: wrap the icon in an element when, for example, a
Tooltip needs one. A key never disappears (Analytics has no fallback for a
missing one); keys are added on request. Core's own screens use the same
set: `NavIcon` beside a label, `icons.X` and friends everywhere else.

## Copy (2026-10-03, K5)

Fahim: "no ai slop in copy and no emdash ... clear to the point copy each
plugins admin ui". These rules hold for every admin-facing string in Core,
Analytics, CRM, Rank and Pro.

1. No em-dash or en-dash as a pause. Split the sentence. Use a colon only
   for "label: value".
2. No dash as an empty value. Show nothing, "None" or "0".
3. One idea per sentence, 12 words or fewer where possible. A notice has at
   most two sentences.
4. No sentence under a heading unless it states a fact the reader needs
   there. No captions under numbers. Never restate the heading.
5. Banned:
   - easily, simply, just (as filler), seamless, powerful, instantly,
     effortless, unlock, supercharge, magic;
   - "Great news", "Nice", "Nice work", "Please wait", "successfully", "Here
     you can", "This page lets you", "Don't worry";
   - exclamation marks, emoji and check-mark characters;
   - rhetorical questions, jargon asides ("The industry calls this"),
     metaphors ("the real lever", "an island") and sales lines ("Install
     Saddle (free) to see ...", "light up").
6. Buttons are a verb and an object ("Add key", "Review and send"). A toast
   states the result ("Saved", "Key added").
7. An error says what failed, then what to do. No apology.
8. Use the names on screen today: Saddle → Agent setup, Saddle → Settings,
   Writing assistant, Anthropic key. One word per thing across the family:
   "Visitors" not "Users", "and" not "&".
9. Exact numbers. Never "100+".
10. Keep sprintf placeholders and their order, and `_n()` plurals. Update the
    `/* translators: */` comment with the string.
11. Out of scope: agent-facing text (ability and tool descriptions, skills,
    playbooks, context sections, AI prompts), CLI output, front-end output
    (such as Rank's SEO title format for archives), readme.txt and
    changelogs, log rows and stored data, option keys, slugs and REST field
    names, code comments, and `.pot` files. If unsure whether text reaches
    the owner's screen, trace it. If it reaches only an agent, leave it.

## Home (#309; one column after Saddle Cloud, #369)

Home replaced the Dashboard on 2026-10-02 (v2 plan:
`planning/HOME-APPS-SERVICES-V2.md`), was made clearer in #367, and on
2026-10-22 became one column of soft cards after Saddle Cloud's overview.
Fahim: "make home better, bring stats, playful home with clean ui, no AI
slop of ui like dot, force border everywhere.. no 2 column.. inspire by
saddle cloud".

- **The look, Home only.** The page grey with Cloud's faint pink and lilac
  glow at the top (on `main[data-saddle-screen="home/overview"]`). White
  cards with 12px corners (`--s-radius-card`) and an edge you barely see,
  drawn as a shadow (`--s-card-edge`), never a border. No rules between
  rows: a row pointed at gets a soft `#F6F7F7` ground. No status dots
  anywhere on Home. 960px, like every Core page.
- **Pastel tiles and chips** (`--saddle-pop-*`, Cloud's six tones, each with
  its own ink at 3:1 or better): a 40px tile with 10px corners behind an
  app's logo or an add-on's icon; a pill chip for an app's role (Read only
  sky, Edit content lilac, Manage the site pink), an add-on that asks for the
  owner (butter: Set up, Check, Off) and each plugin under Works with. An
  app or add-on keeps its tone (`appTone`, `addonTone` in `home-logic.js`).
- **Nothing connected:** one card, centred: the mark on a pink tile, "No AI
  app is connected yet." at 15/600 and a primary "Connect an app". Then the
  feed, in its card, if there is history.
- **Connected, top to bottom:** the connection warning (a butter card with a
  warning tile, two lines and Check) when the self-check fails; Needs your
  OK while something waits; **This week**: Changes, Blocked and Waiting for
  you at 32/600 with their labels under them, then the changes of each of
  the last seven days (today and the six before, local midnight; the week
  the numbers count) as bars. Bars are `--saddle-chart-bar` pale pink, the
  brand on today's and on the one pointed at; a day with changes shows its
  count over its bar, today's in brand text; the weekdays under them, today
  in 600. The times come from `GET /audit-log?times=1`, one query of dates.
  Then **Agent setup** (the card was "AI apps" until 2026-10-23; Fahim:
  "overview AI apps should agent setup right", "make those as card, 2 or
  3 in a row"): "Manage" (to Agent setup) in the heading row, then one
  card per app on the soft grey (`--pp-n-50`), as many to a row as fit
  220px: the logo's tile (white here) and the role's chip, the name, its
  last use, its next step at the foot; a last card, "Connect an app" under
  a plus in a white circle, opens Agent setup's steps. (What follows
  describes the row layout before the cards.)
  each app as its logo tile, name, "Last used …", its role chip and its one
  next step ("Try it", or "Change access" as a link). On a phone the chip
  and the step sit under the name. Then **Add-ons** as tiles, as many to a
  row as fit 260px, each a link to its add-on (the name, stretched over the
  tile), with its icon, its line ("12 visitors today") and a chevron;
  Saddle Analytics with Install or Turn on while it is not on and the owner
  may add it. Then **Works with** as chips. Then **Activity**: heading and
  filters on one row, days as 13/600 grey labels, one-line rows (the app's
  logo, or a lilac person tile for the owner's own steps; the summary,
  which says "Blocked · …" for a refusal; the time).
- Nothing generated: no suggested prompts, no tips, no captions under
  numbers.

## Agent setup (#369; AI apps until then)

Fahim, 2026-10-22: "instead AI Apps call Agent Setup.. make setup nice way
present", then "no drawer, make like saddle cloud". The page follows Cloud's
Agent setup (`saddle-cloud/src/routes/_app/agent-setup.tsx`), on Home's soft
cards, tiles and chips.

- **The address card:** "Connect your AI app" (15/600), one line ("Add this
  site to your AI app with the address below. You choose what each app may
  do."), four app logos on soft discs, overlapping, and "Your address": the
  URL in a soft field with Copy at its end.
- **Connected,** when any app is: one card, each row the app's logo on its
  tile, its name, at most one meta fact ("Last used …" or "Connected …", the
  more recent; never the role, which the dropdown beside it shows), the role
  dropdown and the ⋯ menu. No rules between rows. On a phone the dropdown
  sits under the name.
- **The apps and their steps:** every app down the side, in the old groups'
  order then "Any MCP app", each a button with its logo, name and where it
  runs, a mint tick when it has connected; the chosen one sits on a white
  ground with the card edge. Beside them, one card: the app's tile and
  "Connect X", the one line of fact where one applies, the numbered steps
  (Cloud's soft grey discs) and the waiting line. It stays in view beside
  the long list on a wide screen. On a phone the apps are one row that
  scrolls, the chosen one kept in view, and the steps follow. One app's
  steps always show (Claude Code on a site on this computer, Claude
  otherwise).
- **No drawer.** Home's "Connect app", `&add=<app>` and `&key=<app>` (a key
  at once), the list's Setup guide and Rotate key all show that app's steps
  on this page and bring them into view.

## Accounts and plugins that add tools (Settings, #369; Services until then)

Fahim, 2026-10-23: "the services i think useless". The Services page (#291)
is gone; its two jobs are on Settings, and Home's Add-ons and Works with
show the rest.

- **Settings → Accounts,** after Safety: one row per outside account, its
  name and line ("Stock photos · Not set up"). "Add key" (secondary) or
  "Change key" (link) opens the key form right under the row, inside the
  same block: the key field, "Get a key from <service>", and **"Sends to
  <hosts>"** (the one privacy fact an owner needs; never remove it). Saving
  a key closes it. No drawer. `&section=services`, where every old Services
  address lands, brings Accounts into view.
- **Settings → Advanced → Plugins that add tools (n),** only when another
  developer's plugin adds tools: each one its switch and name, "Sends to",
  and its tools, folded. `&section=services` opens it too.
- Saddle's own add-ons and the plugins Saddle edits need nothing from the
  owner, so Settings does not list them.

## Get Pro (#373, clearer the same day)

Fahim, 2026-10-23: "add pricing get pro page with pricing table", then
"make cleaner ui, nicer text, easy understandable", "feature list height
with normal and how many credit show that also", "center show plans big,
make proper table with go pro premium icon (crown)". He amended
non-negotiable #3 for exactly this page; nothing else in free Saddle
names a paid plan.

- The last item in the Saddle menu, "Get Pro" with a gold crown, only while
  Saddle Pro is not installed. The page is 1040px wide.
- Centred: "One AI connection for all your sites" (24/600), one line, the
  sites picker (5 / 20 / 50, opening on 20).
- The plans as one table of three cards whose rows line up (subgrid): the
  plan's icon on its tile (Go `flash` sky, Pro `crown` pink, Reach
  `graph-up` mint), its name (20/600) and job; the price a month at 40/600
  with "/ month" and "$X billed yearly"; the button ("Choose Pro" primary,
  the others secondary, each opening saddle.to/go/<plan>-<sites>/ in a new
  tab); the same three numbers on a soft ground (sites, AI-app calls a day,
  SEO credits a month, "No SEO credits" grey on Go and Pro); then four
  things it adds with mint ticks. Pro: a pink wash from the top and a
  lifted pink shadow.
- Then "7-day money-back guarantee on every plan" (mint, a shield), and
  Questions: four short answers (do I need a plan, an AI-app call, an SEO
  credit, changing or cancelling) and "Compare every detail on saddle.to".
- The plans, numbers and answers live in `admin/src/get-pro-logic.js`; a
  change on saddle.to needs a Saddle release.

## Logs (2026-10-23)

Fahim: "Activity move to submenu as new page call Logs". Saddle → Logs
(`logs`, `saddle-logs`) is the activity feed that was Overview's: one soft
card with the filters (All, Changes, Blocked, and Rehearsed when one
exists), days as grey labels, one-line rows with Undo. Overview's This
week links to it ("See logs"); an old `#activity` link lands on it; the
tour's third stop points at its menu item.

## Add-on icons (2026-10-23)

Fahim: "use real icon of Add-ons what in wp repo u used". An add-on's own
WordPress.org icon (`assets/addons/<slug>.svg`, copied from the add-on's
`.wordpress.org/icon.svg`) replaces its Iconoir tile on Overview's
Add-ons and on the Analytics placeholder; bundled as a data URI, so no
request reaches WordPress.org. Saddle Analytics has one; an add-on without
one keeps its pastel tile.

## Analytics until Saddle Analytics is on (#373)

Fahim, 2026-10-23: "analytics menu show and if analytics plugin not
installed then show placeholder analytics with install plugin recommend".
Saddle registers its own `analytics` page at `saddle-analytics` while the
module is absent (`Saddle_Modules::offers_analytics()`, filter
`saddle_show_analytics_offer`); the module takes the slug once it is on, so
the address never changes. One centred card: the pink Analytics tile, "See
who visits your site" (24/600), one line, four points on a soft ground,
and "Install Saddle Analytics" (or "Turn on" when installed), through
`analytics-addon.js`. Done: "Saddle Analytics is on." and "Open Analytics".
An owner who may not add plugins is pointed at a site administrator and
WordPress.org. No agent reads about the placeholder.

## The welcome (#309)

First run is a conversation, titled "Welcome": Saddle's lines beside its
28px mark (a following line hides the mark, as a chat groups them), typing
dots between lines, the owner's answers as right-aligned grey bubbles, quick
replies as pills, and the access choice as three option buttons with their
hints. It reads at **15px**, one step above the admin's 13, because the
conversation is the page; it is the only place body text is 15. One sentence
about the site replaces stat tiles and check rows (Fahim: the Pages / Posts /
Media strip "not feel chat"; "onboarding should not like it setup").

After the access choice, one more question (#367) while Saddle Analytics is
not on and the owner may add it: "Do you want visitor stats?" with two
pills, "Yes, add it" (or "Yes, turn it on") and "Not now". Yes shows a grey
"Installing Saddle Analytics from WordPress.org…" line, then a ticked
"Saddle Analytics is on." The Finish button follows whichever answer.

## Type: three sizes (Jetpack AI's, 2026-10-02)

| Token | Size | Use |
|---|---|---|
| `--s-text-title` | 15 / 600 | the header breadcrumb (its links at 400), section heading |
| `--s-text` | 13 | everything you read: body, row titles (600), descriptions, meta, hints, buttons, tabs, footer |
| `--s-text-sm` | 12 | badges, counts, code chips, the uppercase day label |
| (numbers) | 20 / 600 | stat card values only |
| `--s-text-hero` | 32 / 600 | Home's three week numbers (#369) and Get Pro's prices (#373) |

**Why numbers are 20 (1.5.0 QA P21).** A count is read at a glance, before
its label, and 20 is the smallest size at which three numbers in a row
(Home's This week: Changes, Blocked, Waiting for you; a module's stat row)
read as numbers and not as one more line of text. At 15 a number is the same
size as the block's title above it and the two compete; at 13 it is just a
word. It stays narrow: only a kit `StatCard` value (`--pp-text-3xl`), never
a heading, a sentence or a label.

Every `font-size` in `style.scss` is one of the three tokens; no raw px value
(the one exception is a 24px icon box). The kit's steps are mapped onto the
same three through `--pp-text-*`, so kit components land on the scale too.
Checked by computed style on every page: only 12, 13 and 15px render, plus
20 for stat values and 32 for Home's week numbers. Nothing else is bigger:
no hero sentences, no 24px ledes, no 27px titles.

## Structure: page → tab → section → block → row

- A **section** is a 14px heading with at most one short muted line under it.
  It has no box.
- A **block** is a white box with a 1px `#F0F0F0` border, 8px corners and the
  faint block shadow (a kit `Card` or `RowList`), holding rows. A block nested
  inside another block stays flat. A row's icon or status dot sits on
  the block itself, not in a grey square.
- A **row** is a label on the left and a control or value on the right, with
  one line of meta under the label only when it is needed.
- **Don't show everything.** Explanations go behind a `HelpTip` or a
  `Collapsible`; rarely used things start collapsed. A label beats a
  sentence, and a sentence beats a paragraph.

Geometry: 8px corners on blocks (`--pp-r-lg`: cards, row lists, stat tiles,
Saddle's own panels), 4px on controls, buttons, tiles and notices (`--pp-r`);
the pill stays for badges, dots and switches. Blocks carry one faint shadow
(`--pp-shadow-xs`); buttons stay flat; overlays keep the DS's own.

## The UX register

1. **One idea per block.** Lead with the thing the reader came for; everything
   else is quieter or folded away.
2. **Content over chrome.** Structure comes from the frame, sections and
   hairlines, not from more panels.
3. **Say nothing rather than say nothing.** A tile reading `—` is worse than no
   tile.
4. **Plain, task-first names.** WordPress's Saddle submenu picks the page:
   Home, each installed module, then Agent setup, Context and
   Settings. Inside a module, its sidebar picks the section and the icon tab
   row the page (see "Navigation").
5. **A choice explains itself where it is made.** An option list carries one
   line per option (the access list on Agent setup) instead of a separate "what
   each option means" section.

## Constraints that still apply

- UI primitives come from `@plugpress/ui` only — no `@wordpress/components`, no
  Tailwind, no styled-components, no second UI kit.
- One Core React root per Saddle page: `#saddle-root`, carrying `data-area`,
  `data-tab`, `data-sub` and `data-view`. No second root on a page and no
  hash routing; sections are `&tab=` and pages `&sub=` URLs.
  `data-saddle-screen="area/tab[/sub][/view]"` stays on `<main>` (browser
  agents rely on it).
- Don't regress accessibility: labels, focus rings, `role`/`aria-*` semantics and
  `prefers-reduced-motion` must survive any restyling. Every text pair above
  clears AA.
- Light-only: never add a theme toggle.
- New CSS uses logical properties (`margin-inline`, `padding-block`, …).
- Portaled DS overlays read tokens from `pp-scope` on `<body>` (via
  `admin_body_class`) — keep it.
- Stylesheet order: the DS bundle (`index.css` → `saddle-admin-ds`) loads before
  Saddle's `style-index.css` so product rules win.
- **The brand mark is recolored through CSS `color`, never by editing
  `assets/brand/mark.svg`.** `class-saddle-settings.php` does
  `str_replace( 'currentColor', 'black', … )`, and that `black` is a sentinel
  core's `svg-painter.js` needs to repaint the wp-admin menu icon per the user's
  admin color scheme. The menu icon is deliberately left alone.

## Kit seams (each belongs upstream in plugpress-ui)

Kept together in one block of `style.scss`:

- the token selector names `.pp-scope`, so portaled layers get Saddle's
  palette;
- the dark overlays and code panels pinned off `--pp-action`;
- link-buttons (`<a class="pp-btn">`) get their text color back: the kit's
  `#wpbody-content .pp-app a` rule otherwise paints them link-blue;
- block titles at 13/600 and row titles at 600;
- field edges and the off switch at `#949494`; row icons without the grey
  square; no focus ring on a `Select` option (the highlight is the position);
- the header's tab row and the icon tab row drop their own border (the band
  draws it), and the active line sits inside the row, which scrolls
  sideways and would clip it.
- row lists get the card's shadow (the kit draws them flat), and a block
  nested in a card or in Advanced stays flat.

---

## History

> **DECIDED (2026-10-05, Fahim): the module sidebar is a white panel.** On
> the live CRM and Analytics pages he called the "raised" sidebar
> "disgusting": the tab band started at the sidebar's edge, the content
> centred itself and left a gap that grew with the screen, and the white
> active item read as a text field. Of the page-grey fixes he said a small
> sidebar with no background reads as a big empty space. He chose "D,
> sidebar with background" over top tabs (saddle#328); mockup
> https://claude.ai/artifact/MqHubsAeTUzwFjcdKWC2dN. Supersedes the
> 2026-10-04 "raised" sidebar below.

> **DECIDED (2026-10-04, Fahim): palette v3.** "the --pp-canvas is very close
> white.. redefine all colors make sure it look nice." The page moves from
> Jetpack AI's `#FCFCFC` to wp-admin's own `#F0F0F1`, blocks get a visible
> `#DCDCDE` edge, and every grey is WordPress's (`#1D2327` text, `#646970` the
> one secondary grey, `#8C8F94` field edges). He chose "WordPress native" over
> cool slate and warm stone, and for the module sidebar "raised" (B) over a
> white rail and a Petrol active item; mockup
> https://claude.ai/artifact/JFDVNG5GZhWsRMm13CNhh8. The brand, links, status
> colours and the one-grey rule are unchanged. Supersedes the 2026-10-02
> `#FCFCFC` canvas and `#F0F0F0` hairline.

> **SUPERSEDED (2026-10-01):** olive `#4D6410` (2026-09-26, matching saddle.to)
> and the 85/15 split with the accent on links, focus and nav. Before that,
> magenta `#DA5CC7` (2026-08-25), and before that monochrome (2026-07-04).

> **DECIDED (2026-07-10, Fahim):** the admin UI is fully migrated to
> `@plugpress/ui` — the shared PlugPress design system. Import primitives directly
> from `@plugpress/ui`; the old `admin/src/ui.jsx` compat shim is deleted. The
> system is light-only (dark mode was removed from the DS in v0.2.0).
