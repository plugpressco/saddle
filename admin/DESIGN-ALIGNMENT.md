# Saddle — Design Alignment

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
| **Brand** (Petrol) | `#0B6470` | white on it 6.84; on white 6.84 |
| Brand hover / press | `#08505A` / `#063F47` | white 9.11 / 11.60 |
| Brand tint / soft | `#E2F1F2` / `#C5E4E7` | brand on tint 5.89 |
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
selected role (its tick and tint) in an app's access list on AI apps · chart
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
  `[mark] Saddle / Page` ("Saddle / AI apps", "Saddle / Rank", "Saddle /
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
- **F7 Petrol in the places listed above, and in charts only as ink.** The one
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
  then a hairline, then AI apps, Services, Context and Settings. Every item
  has its Iconoir icon before its label. The hairline is drawn only when at
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

- **Sizes:** 16px in the menu and the tabs, 20px in the header, a 6px gap to
  the label.
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
| AI apps | `sparks` | none |
| Services | `puzzle` | none |
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
8. Use the names on screen today: Saddle → Services, Saddle → Settings,
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

## Home (#309, v2 the same day)

Home replaced the Dashboard on 2026-10-02. The first build had captioned stat
tiles, a row of suggested prompts and three ways to say "connect"; Fahim:
"more ugly now … no ai slop, random text box, ai text, no repeat unnecessary
things". The v2 plan is `planning/HOME-APPS-SERVICES-V2.md`. Home is now:

- **Nothing connected:** one block, centred, "No AI app is connected yet." and
  a primary "Connect an app". Then the feed if there is history. No stats,
  no side column.
- **Connected:** left, "Needs your OK" while something waits; one plain line
  from Saddle with the single next setup step (`try` or `choose` only;
  mark, sentence, button, close; no box); **This week**, one block with its
  title in the header row and three numbers with labels only, Changes ·
  Blocked · Waiting for you, exact (the log's `since` argument); then
  **Activity**: the heading and the filters (All · Changes · Blocked, and
  Rehearsed only when one exists) on one row, days as 13/600 grey labels,
  one-line rows (the app's logo or a status dot, the summary, the time; the
  actor in the row's tooltip), "Show older". Right, **Apps** (logo, name,
  role, "Manage", "Connect another app" as the last row), **Works with**
  (plugin names only, "Services" link) when non-empty, the connection
  warning, **Modules**.
- Nothing generated: no suggested prompts, no tips, no captions under
  numbers.

## AI apps (#309 v2)

- **Nothing connected:** "Connect an app" (15/600, no sentence), then Chat
  apps · Agents · Code editors as rows of light tiles: logo and name only,
  44px, hairline, no shadow, 6px corners, `#949494` on hover. Under them one
  link, "Another MCP app", and one line, "Address", the URL in a code chip,
  "Copy" as a link-button. No boxes besides the tiles.
- **Connected:** the list; each row has at most one meta fact ("Last used …"
  or "Connected …", the more recent) and never repeats the role shown in the
  dropdown beside it.

## Services (#309 v2)

- No section notes. Every section is named, "Accounts" / "Plugins" /
  "Add-ons", even when it is the only one (1.5.0 QA P23: a page of one
  unnamed list doesn't say what the list is), and nothing under the name.
- Rows: the name and one status word ("Not set up" · "Ready" · "Active" ·
  "Off"). An outside account says what it is for first ("Stock photos · Not
  set up"), because its name alone may not; a plugin's name says it. No
  letter avatar, no tool count. "Add key" (secondary) only for `needs_key`;
  every other row is the button that opens the drawer, with a chevron.
- The drawer: the service's one-line summary under its name, the key form
  or the on/off switch, "Get a key from <service>", **"Sends to <hosts>"**
  (the one privacy fact an owner needs; never remove it), and the tools as a
  collapsed list.
- Empty page: one grey line, "Nothing here yet."

## The welcome (#309)

First run is a conversation, titled "Welcome": Saddle's lines beside its
28px mark (a following line hides the mark, as a chat groups them), typing
dots between lines, the owner's answers as right-aligned grey bubbles, quick
replies as pills, and the access choice as three option buttons with their
hints. It reads at **15px**, one step above the admin's 13, because the
conversation is the page; it is the only place body text is 15. One sentence
about the site replaces stat tiles and check rows (Fahim: the Pages / Posts /
Media strip "not feel chat"; "onboarding should not like it setup").

## Type: three sizes (Jetpack AI's, 2026-10-02)

| Token | Size | Use |
|---|---|---|
| `--s-text-title` | 15 / 600 | the header breadcrumb (its links at 400), section heading |
| `--s-text` | 13 | everything you read: body, row titles (600), descriptions, meta, hints, buttons, tabs, footer |
| `--s-text-sm` | 12 | badges, counts, code chips, the uppercase day label |
| (numbers) | 20 / 600 | stat card values only |

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
Checked by computed style on every page: only 12, 13 and 15px render.
Nothing is bigger, anywhere: no hero sentences, no 24px ledes, no 27px titles.

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
   Home, each installed module, then AI apps, Services, Context and
   Settings. Inside a module, its sidebar picks the section and the icon tab
   row the page (see "Navigation").
5. **A choice explains itself where it is made.** An option list carries one
   line per option (the access list on AI apps) instead of a separate "what
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
