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
| Canvas (page) | `#FCFCFC` | |
| Header band | `#FFFFFF`, `#F0F0F0` hairline under it | |
| Surface (blocks) | `#FFFFFF`, `#F0F0F0` hairline, 8px corners, `0 1px 2px rgba(0,0,0,.05)` | |
| Surface 2 (row hover, code chips, sub-panels) | `#F5F5F5` | |
| Border / border 2 | `#F0F0F0` / `#CCCCCC` | block edges and row dividers, decorative |
| Field edge, switch off | `#949494` | 3.03 on white |
| Text / secondary | `#1E1E1E` / `#707070` (one grey for every description, meta line, hint and footer, as on Jetpack AI) | 16.67 / 4.95 (4.83 on the canvas) |
| Links, focus | `var(--wp-admin-theme-color)` (Default `#2271B1` 5.17, Modern `#3858E9` 5.36) | follows the owner's admin color scheme |
| Success | `#007017` on `#EDFAEF` | 5.86 |
| Warning | `#8A4F00` on `#FEF8EE` | 6.21 |
| Danger | `#B32D2E` on `#FCF0F1` | 5.67 |
| Info | `#2271B1` on `#F0F6FC` | 4.75 |

It lives in `admin/src/style.scss` as consumer-side `--pp-*` overrides (the
brand as `--saddle-brand*`). **Never in `@plugpress/ui`**: the library is
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
ground from `--pp-action` in the kit, so `style.scss` pins them to `#1D2327`;
code panels are pinned the same way. Both are kit seams.

## The frame

Every Saddle page, module pages and first run included, is drawn by
`admin/src/components/Frame.jsx`:

- **Header band:** white over the `#FCFCFC` canvas, full width of wp-admin's
  content area, `16px 24px`, one `#F0F0F0` hairline under it. Row 1: the
  22px mark in Petrol (a link to Home), then the page's name as the `h1`
  (15/600): "Home", "AI apps", "Analytics" on a module page, "Welcome" on
  first run. No "Saddle /" breadcrumb (#309): the mark says whose page it is.
  On the right, the notices bell and the **AI switch**: a pill reading
  "AI on" (green dot) or "Paused" (amber dot) that opens a small panel with
  what the state means and one button, "Pause all apps" or "Resume". The
  switch lives here, on every page, and nowhere else. While paused, a strip
  under the header says "AI is paused. No app can read or change this site
  until you resume." with Resume, on every Saddle page. Row 2, when a page
  has two or more tabs (no core page does since #309; modules may): the kit
  `Tabs` in Jetpack AI's (WordPress 7's minimal) style: 48px tall, 13px
  regular, every tab `#1E1E1E`, a 1.5px `#6E6E6E` line under the active one.
  No brand color on tabs (2026-10-02, Fahim: the teal underline and grey idle
  tabs looked dated).
  **No description sentence** under the title.
- **Page:** the `#FCFCFC` canvas, one centered 960px column (Home: 1040px,
  for its two columns).
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
- **F2 One frame.** `Frame.jsx` draws the header band, the paused strip, the
  tabs, the canvas and the footer. A module draws no header, top bar, rail,
  wordmark, breadcrumb, description sentence, footer, toaster or tooltip
  provider.
- **F3 One kit, through Core.** Primitives and icons come from the `ui` prop
  (`ui.icons` for icons). A module bundles no copy of `@plugpress/ui`, no
  Tailwind, no icon, toast or font library, and no `@wordpress/components` on
  an admin screen (a block-editor sidebar or a full-screen editor may).
- **F4 One nav.** WordPress's Saddle submenu is the nav; a module is
  Saddle → Name; its tabs are `&tab=` and its deep screens `&view=` with its
  own `&args`. No hash router, no inner nav pills, no settings sidebar.
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
  the job word (Analytics, SEO, CRM).
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

## Navigation (2026-10-03)

Fahim: "wordpress has sidebar we consider that ... each plugin have their sub
heading". WordPress's admin menu is the rail and the sidebar. There is no
second sidebar inside a page.

- **The menu.** Saddle → Home, then the products (Analytics, SEO, CRM), then
  a hairline, then AI apps, Services, Context and Settings. Every item has
  its Iconoir icon before its label. The hairline is drawn only when at least
  one product is there: `Saddle_Settings::order_submenu()` gives the first of
  Core's pages after the products the `saddle-menu-group` class, and one
  `admin_head` stylesheet (printed only for users who can see the menu) draws
  `box-shadow: inset 0 1px 0 rgba(128,128,128,.3)` with 6px above and inside.
  Page titles (`<title>`) stay plain text.
- **One tab row per page.** Each tab shows its icon and its label. The first
  tab is the landing page: Overview for Analytics and SEO, Campaigns for CRM.
  Settings is always last. At most 6 tabs. A page with one screen draws no
  tab row.
- **A third level is a drill-in (K4).** A module opens it with `&view=`
  inside a tab and names it with `props.header?.drillIn?.( { title } )`. The
  header then shows the back icon, the tab's label as a link, a `/` and the
  item's title. There is no tab row and no third nav. The back link is a real
  link, so a middle click opens a new tab; a plain click stays in the app, so
  the screen unmounts and can save what the owner typed. Core clears the
  title when the view closes, the tab changes or the screen unmounts.
  Drill-ins are opt-in: a screen that never calls it keeps the tab row, and
  on older Core a module keeps its own back button. The logic is
  `admin/src/frame-logic.js`.
- **Filters inside a tab are not tabs.** Filtering a list uses `FilterTabs`,
  with counts. Switching a mode (range, size, grouping) uses
  `SegmentedControl`. Neither carries icons.
- **The right column is Home's only.** No search, no command palette, no
  assistant box, no upsell.
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

| Item | Menu icon | Tabs and their icons |
|---|---|---|
| Home | `home-simple-door` | none |
| Analytics | `graph-up` | Overview `dashboard-dots` · Reports `reports` · Settings `settings` |
| SEO | `search-engine` | Overview `dashboard-dots` · AI visibility `eye` · Search appearance `search-window` · Content `multiple-pages` · Links `link` · Settings `settings` |
| CRM | `send-mail` | Campaigns `mail-out` · Contacts `group` · Settings `settings` |
| AI apps | `sparks` | none |
| Services | `puzzle` | none |
| Context | `brain` | none |
| Settings | `settings` | General (one tab, no row) |
| Header | back `nav-arrow-left`, notices `bell` | |

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
the menu item's icon and `'tab_icons' => array( 'reports' => 'reports' )`
maps tab keys to icons. Core fills the defaults by key: `overview` is
`dashboard-dots`, `settings` is `settings`. A name outside the allowlist
draws no icon and is never an error. `tabs` stays `key => label`; an icon
never goes inside it. Older Core ignores both keys, so a sibling can ship
them first.

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

- No section notes. No section heading when only one kind has records; with
  two or more, "Accounts" / "Plugins" / "Add-ons" and nothing under them.
- Rows: the name and one status word ("Not set up" · "Ready" · "Active" ·
  "Off"). No letter avatar, no description, no tool count. "Add key"
  (secondary) only for `needs_key`; every other row is the button that opens
  the drawer, with a chevron.
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
| `--s-text-title` | 15 / 600 | page (header breadcrumb), section heading |
| `--s-text` | 13 | everything you read: body, row titles (600), descriptions, meta, hints, buttons, tabs, footer |
| `--s-text-sm` | 12 | badges, counts, code chips, the uppercase day label |
| (numbers) | 20 / 600 | stat card values only |

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
4. **Plain, task-first names.** WordPress's Saddle submenu is the nav:
   Home, each installed module, then AI apps, Services, Context and Settings
   (see "Navigation"). There is no sidebar inside the page.
5. **A choice explains itself where it is made.** An option list carries one
   line per option (the access list on AI apps) instead of a separate "what
   each option means" section.

## Constraints that still apply

- UI primitives come from `@plugpress/ui` only — no `@wordpress/components`, no
  Tailwind, no styled-components, no second UI kit.
- One Core React root per Saddle page: `#saddle-root`, carrying `data-area` and
  `data-tab`. No second root on a page and no hash routing; tabs are `&tab=`
  URLs. `data-saddle-screen` stays on `<main>` (browser agents rely on it).
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
- the header's tab row drops its own border (the band draws it).
- row lists get the card's shadow (the kit draws them flat), and a block
  nested in a card or in Advanced stays flat.

---

## History

> **SUPERSEDED (2026-10-01):** olive `#4D6410` (2026-09-26, matching saddle.to)
> and the 85/15 split with the accent on links, focus and nav. Before that,
> magenta `#DA5CC7` (2026-08-25), and before that monochrome (2026-07-04).

> **DECIDED (2026-07-10, Fahim):** the admin UI is fully migrated to
> `@plugpress/ui` — the shared PlugPress design system. Import primitives directly
> from `@plugpress/ui`; the old `admin/src/ui.jsx` compat shim is deleted. The
> system is light-only (dark mode was removed from the DS in v0.2.0).
