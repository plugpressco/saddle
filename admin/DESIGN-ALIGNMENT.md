# Saddle — Design Alignment

> **DECIDED (2026-10-01, Fahim; #280):** the admin is **WordPress-native with one
> brand color, Petrol teal `#0B6470`**. Fahim reviewed Jetpack's admin and asked
> for "a simple UI compliant with the default WordPress UI yet representing the
> plugin's branding", with less clutter, less text and no oversized type. He
> chose a full-width white header band with the tabs inside it, and Petrol over
> a deeper olive and a graphite option. This supersedes the olive decision
> (2026-09-26) and the 85/15 rule below it. Don't re-litigate the color; do
> hold the line on where it may appear.

## The palette

Everything except the brand is WordPress's own value, so a Saddle page sits in
wp-admin like a core screen.

| Role | Hex | Contrast |
|---|---|---|
| **Brand** (Petrol) | `#0B6470` | white on it 6.84; on white 6.84 |
| Brand hover / press | `#08505A` / `#063F47` | white 9.11 / 11.60 |
| Brand tint / soft | `#E2F1F2` / `#C5E4E7` | brand on tint 5.89 |
| Page | `#F0F0F1` | |
| Surface (blocks, header band) | `#FFFFFF` | |
| Surface 2 (row hover, sub-panels) | `#F6F7F7` | |
| Border / border 2 | `#DCDCDE` / `#C3C4C7` | hairlines, decorative |
| Field edge, switch off | `#8C8F94` | 3.24 on white |
| Text / secondary / muted | `#1D2327` / `#50575E` / `#646970` | 13.95 / 6.43 / 4.86 on the page |
| Links | `#2271B1`, hover `#135E96` | 5.17 on white, 4.54 on the page |
| Focus | `var(--wp-admin-theme-color)` | WordPress's own |
| Success | `#007017` on `#EDFAEF` | 5.86 |
| Warning | `#8A4F00` on `#FEF8EE` | 6.21 |
| Danger | `#B32D2E` on `#FCF0F1` | 5.67 |
| Info | `#2271B1` on `#F0F6FC` | 4.75 |

It lives in `admin/src/style.scss` as consumer-side `--pp-*` overrides (the
brand as `--saddle-brand*`). **Never in `@plugpress/ui`**: the library is
off-limits from a plugin task, and the DS defines its tokens inside `:where()`
(zero specificity), so a plain class selector wins with no `!important`. Both
`.pp-app` and the body class are targeted, because portaled overlays render
outside `.pp-app` and would otherwise lose every token.

WordPress's warning yellow `#DBA617` (2.22 on white) is a mark only, never
text; its red `#D63638` (4.73) is too thin for text, so text uses `#B32D2E`.

## Where the brand may appear

**Only:** the mark in the header band · the active tab's indicator · primary
buttons (`--pp-action`) · switches, radios and checkboxes when on · the
selected access-level card on Connections → Permissions.

**Never:** links (WordPress blue), focus rings (WordPress's theme color), text,
headings, borders, icons, backgrounds of blocks or bands. If a screen seems to
need more teal, it needs less of something else.

The dark overlays (tooltip, toast, coachmark, apply bar, bulk bar) read their
ground from `--pp-action` in the kit, so `style.scss` pins them to `#1D2327`;
code panels are pinned the same way. Both are kit seams.

## The frame

Every Saddle page, module pages and first run included, is drawn by
`admin/src/components/Frame.jsx`:

- **Header band:** white, full width of wp-admin's content area, `16px 24px`,
  one `#DCDCDE` hairline under it. Row 1: the 20px mark in Petrol, then an `h1`
  breadcrumb "Saddle / Page" (15px; "Saddle" in grey links Home; the page in
  600). A module page reads "Saddle / Analytics". On the right, only the AI
  status pill and the notices bell. Row 2, when a page has two or more tabs:
  the kit `Tabs`, sitting on the band's hairline with a Petrol indicator.
  **No description sentence** under the title.
- **Page:** WordPress grey, one centered 960px column.
- **Footer strip:** a hairline, then mark · "Saddle 1.5.0" (and the module's
  product and version on a module page) · Docs · Rate Saddle, in 12px grey.

Never draw the frame while the app is loading: WordPress's `common.js` moves
every `.notice` after the first `.wrap h1`, and the header's `h1` would pull the
quarantined notices back into view.

## Type: one scale

| Use | Size / weight |
|---|---|
| Page (header breadcrumb) | 15 / 600 |
| Section heading | 14 / 600 |
| Block title, row label | 13 / 600 |
| Body, controls | 13 / 400 |
| Meta, hints, footer | 12 / 400, `#646970` |
| Numbers in stat cards only | up to 20 / 600 |

Nothing is bigger, anywhere: no hero sentences, no 24px ledes, no 27px titles.
The kit's larger steps are pulled in through `--pp-text-*`.

## Structure: page → tab → section → block → row

- A **section** is a 14px heading with at most one short muted line under it.
  It has no box.
- A **block** is a white box with a 1px border and 4px corners (a kit `Card` or
  `RowList`), holding rows.
- A **row** is a label on the left and a control or value on the right, with
  one line of meta under the label only when it is needed.
- **Don't show everything.** Explanations go behind a `HelpTip` or a
  `Collapsible`; rarely used things start collapsed. A label beats a
  sentence, and a sentence beats a paragraph.

Geometry: 4px corners everywhere (the pill stays for badges, dots and switches),
no shadows (hairlines draw structure; overlays keep the DS's own).

## The UX register

1. **One idea per block.** Lead with the thing the reader came for; everything
   else is quieter or folded away.
2. **Content over chrome.** Structure comes from the frame, sections and
   hairlines, not from more panels.
3. **Say nothing rather than say nothing.** A tile reading `—` is worse than no
   tile.
4. **Plain, task-first names.** WordPress's Saddle submenu is the nav: Home,
   each installed module, then Connections, Context and Settings. There is no
   sidebar inside the page.

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

- the dark overlays and code panels pinned off `--pp-action`;
- link-buttons (`<a class="pp-btn">`) get their text color back: the kit's
  `#wpbody-content .pp-app a` rule otherwise paints them link-blue;
- block titles at 13/600 and row titles at 600;
- field edges and the off switch at `#8C8F94`;
- the header's tab row drops its own border (the band draws it).

---

## History

> **SUPERSEDED (2026-10-01):** olive `#4D6410` (2026-09-26, matching saddle.to)
> and the 85/15 split with the accent on links, focus and nav. Before that,
> magenta `#DA5CC7` (2026-08-25), and before that monochrome (2026-07-04).

> **DECIDED (2026-07-10, Fahim):** the admin UI is fully migrated to
> `@plugpress/ui` — the shared PlugPress design system. Import primitives directly
> from `@plugpress/ui`; the old `admin/src/ui.jsx` compat shim is deleted. The
> system is light-only (dark mode was removed from the DS in v0.2.0).
