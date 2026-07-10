# Saddle — Design Alignment

> **DECIDED (2026-07-10, Fahim):** the admin UI is fully migrated to
> **`@plugpress/ui` v0.3.0** — the shared PlugPress design system — which is
> exactly the "shared component package" outcome the original research called
> for. Import primitives directly from `@plugpress/ui`; the old
> `admin/src/ui.jsx` compat shim is deleted. The system is **light-only**
> (dark mode was removed from the DS in v0.2.0 — the dark-theme and
> `s-palette-*` mentions below are historical). The 2026-07-04 monochrome
> identity **stands**, now expressed through `tokens/accents/saddle.css`
> (a fully monochrome accent) on top of the shared `--pp-*` tokens;
> `style.scss` keeps only product-specific pieces (setup shell, permissions
> lanes/chips, activity timeline, wizard flourishes) aliased to those tokens.
> The brand mark is **single-sourced** from `assets/brand/mark.svg` — React's
> `<BrandMark />` (SVGR import) and the PHP admin-menu icon
> (`class-saddle-settings.php`, `file_get_contents` + recolor) both read that
> one file; edit the SVG once to rebrand every surface.
> Don't re-litigate any of this in future sessions.

## Constraints that still apply

- UI primitives come from `@plugpress/ui` only — no `@wordpress/components`,
  no Tailwind, no styled-components, no second UI kit.
- The Settings page must remain a single mounted React root (`#saddle-root`).
- Don't regress accessibility: labels, focus rings, `role`/`aria-*` semantics,
  and `prefers-reduced-motion` support must survive any restyling.
- Light-only: never add a theme toggle.
- Portaled DS overlays (dialogs, dropdowns, toasts) read tokens from the
  `pp-scope` class on `<body>` (added via `admin_body_class`) — keep it.
- Stylesheet order: the DS bundle (`index.css` → handle `saddle-admin-ds`)
  loads before Saddle's own `style-index.css` so product rules win.

---

## History

> **DECIDED (2026-07-04, Fahim):** the monochrome identity is intentional,
> not a placeholder. Saddle keeps its own near-black/white, OpenAI/Apple-register
> look — one restrained accent reserved for status/safety. The earlier
> "align to inbees/outbees" directive below is SUPERSEDED; kept for history.

### The former rule (superseded)

Do **not** invent a visual design from memory or taste. Saddle has to read as
"the same workspace" as the rest of the PlugPress portfolio (inbees/outbees).
That means pulling real values, not approximating them — and if a shared
PlugPress design-system package exists, consume it rather than re-implementing
it. (That package now exists: `@plugpress/ui`. It happened.)
