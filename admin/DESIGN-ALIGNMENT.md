# Saddle — Design Alignment

> **DECIDED (2026-08-25, Fahim):** Saddle has a brand palette, built on
> **`#DA5CC7`**. This **supersedes the 2026-07-04 monochrome
> decision** kept below for history. The register is unchanged — calm, restrained,
> Vercel/Geist — but the one accent is now the brand colour instead of near-black.
> **Roughly 85% of the interface stays black, white and gray; the accent is the
> remaining 15%.** Don't re-litigate this; do hold the line on the 85/15.

## The palette

| Role | Name | Hex |
|---|---|---|
| Primary brand | — | `#DA5CC7` |
| Hover / active | — | `#A82595` |
| Soft background | — | `#FDF4FC` |
| Main text | Near Black | `#111111` |
| Page background | Warm White | `#FBFBFA` |
| Success / read-only | Lime | `#84CC16` |
| Activity / info | Cyan | `#06B6D4` |
| Warning / gated | Amber | — |
| Destructive / blocked | Coral | `#F43F5E` |

It lives in `admin/src/style.scss` as consumer-side `--pp-*` overrides. **Never in
`@plugpress/ui`** — the library is off-limits from a plugin task, and the DS
defines its tokens inside `:where()` (zero specificity), so a plain class selector
wins with no `!important`. Both `.pp-app` and the body class are targeted, because
portaled overlays render outside `.pp-app` and would otherwise lose every token.

## The rule that governs every color

**Every color has a bright value for FILLS and a darker same-hue value for TEXT.**
This is not a preference. Measured on white the brand colour is **3.31:1** — enough
to be seen, not enough to be read — and lime is 1.98, cyan 2.43, coral 3.67. None
of them can carry a label, and none can be the ground under a white one. The
readable step of the same hue does that: `#BD2BA7` for links and for the primary
button, `#A82595` on hover. The DS mandates the
same split for the accent (`--pp-accent` paints surfaces at 3:1;
`--pp-accent-text` paints links and labels at 4.5:1, measured against the *tint*,
not just white).

Three consequences worth knowing before you touch a status color:

- **A mark needs 3:1 too.** A status dot, a 2px lane rail or a thin ring is a
  graphical object, so lime and cyan are each used **one step down their ramp**
  (`#65A30D`, `#0891B2`) — at the `-500` step they are invisible on a white card.
  Coral already clears it and is used exactly as given.
- **The DS collapses `--pp-tone-text` into `--pp-tone`** for status tones, so the
  readable partners are put back at the tone layer — the same seam the accent tone
  already uses.
- **Warning and destructive are two different signals.** Amber means "powerful,
  asks first" (the Remove lane, the shield chip, the write-tier pill); coral means
  "blocked or failed" (a denied call, a connection error). They need opposite
  reactions from the reader — don't fold them together.

## Where the accent is allowed to land

**Yes:** primary buttons · links · focus rings · the active nav row · the brand
mark · the Cookbook-style accent bars · `tone="accent"` fills · selection states.

**No:** card backgrounds, page bands, table headers, every icon, borders at large,
section headings. If a screen has more than a few accented elements, remove some
rather than softening the accent.

**One deliberate deviation from the DS guide, recorded so it isn't "fixed" back:**
the guide says *"Primary buttons are near-black (`--pp-action`), never the accent
color."* Saddle's carry the accent. The cost was that `.pp-code--dark` reads
`--pp-code-bg` from `--pp-action` while hard-coding its border, muted and body
colors, so `--pp-code-bg` is pinned back to near-black in `style.scss`. Code panels
are deliberately **not** the button color.

## The UX register

Four rules the admin is held to. A change that doesn't trace to one of these
doesn't belong.

1. **One idea per screen.** Lead with the thing the user came to find out, in a
   sentence. Supporting facts go quiet underneath. The Dashboard is the worked
   example: it opens with `levelFor( tier ).one` and nothing competes with it.
2. **Content over chrome.** Structure comes from type, spacing and hairlines, not
   from more panels. Radii are 3/4/6px and elevation is flat — close to wp-admin's
   own square register, a hair softer.
3. **Say nothing rather than say nothing.** A tile reading `—` is worse than no
   tile. The Dashboard's old "Connection" tile is why this rule is written down.
4. **Plain, task-first names.** Labels say what you do there. The sidebar is one
   flat list with no group headings — seven items is not a wall.

## Constraints that still apply

- UI primitives come from `@plugpress/ui` only — no `@wordpress/components`, no
  Tailwind, no styled-components, no second UI kit.
- The Settings page must remain a single mounted React root (`#saddle-root`).
- Don't regress accessibility: labels, focus rings, `role`/`aria-*` semantics and
  `prefers-reduced-motion` must survive any restyling. Every text pair in the
  palette clears AA; muted text actually improved (4.40 → 4.50).
- Light-only: never add a theme toggle.
- Portaled DS overlays read tokens from `pp-scope` on `<body>` (via
  `admin_body_class`) — keep it.
- Stylesheet order: the DS bundle (`index.css` → `saddle-admin-ds`) loads before
  Saddle's `style-index.css` so product rules win.
- **The brand mark is recolored through CSS `color`, never by editing
  `assets/brand/mark.svg`.** `class-saddle-settings.php` does
  `str_replace( 'currentColor', 'black', … )`, and that `black` is a sentinel
  core's `svg-painter.js` needs to repaint the wp-admin menu icon per the user's
  admin color scheme. The menu icon is deliberately left alone.

---

## History

> **SUPERSEDED (2026-07-04, Fahim):** the monochrome identity is intentional, not
> a placeholder. Saddle keeps its own near-black/white, OpenAI/Apple-register
> look — one restrained accent reserved for status/safety.
>
> Still true in spirit: the restraint, the single accent, the register. What
> changed on 2026-08-25 is only which color the accent is.

> **DECIDED (2026-07-10, Fahim):** the admin UI is fully migrated to
> `@plugpress/ui` — the shared PlugPress design system. Import primitives directly
> from `@plugpress/ui`; the old `admin/src/ui.jsx` compat shim is deleted. The
> system is light-only (dark mode was removed from the DS in v0.2.0).

### The former rule (superseded 2026-07-04)

Do **not** invent a visual design from memory or taste. Saddle has to read as "the
same workspace" as the rest of the PlugPress portfolio (inbees/outbees). That means
pulling real values, not approximating them — and if a shared PlugPress
design-system package exists, consume it rather than re-implementing it. (That
package now exists: `@plugpress/ui`. It happened.)
