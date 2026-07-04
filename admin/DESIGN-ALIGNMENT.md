# Saddle — Design Alignment

> **DECIDED (2026-07-04, Fahim):** the monochrome identity is now intentional,
> not a placeholder. Saddle keeps its own near-black/white, OpenAI/Apple-register
> look — one restrained accent reserved for status/safety — with light AND dark
> themes driven by the `s-palette-*` mixins in `style.scss`. The earlier
> "align to inbees/outbees" directive below is SUPERSEDED; kept for history.
> The brand mark is the stirrup-arch SVG (`<BrandMark />` in `icons.jsx`,
> mirrored as the admin-menu icon in `class-saddle-settings.php`) — keep the
> two paths in sync. Don't re-litigate this in future sessions.

## The former rule (superseded)

Do **not** invent a visual design from memory or taste. Saddle has to read as
"the same workspace" as the rest of the PlugPress portfolio (inbees/outbees).
That means pulling real values, not approximating them.

## Before you touch styles, gather the real reference

1. **Get the real inbees/outbees source.** Find the actual plugin admin code in
   the PlugPress monorepo (or the installed plugins). Do not work from
   screenshots or memory.
2. **Extract real tokens, don't guess:**
   - Color palette (primary, surface, border, text, success/warning/error).
   - Font family and the type scale (sizes, weights, line-heights).
   - Spacing scale and border-radius conventions.
   - Any logo / icon assets and how they're sized.
3. **Check for a shared component package.** If inbees/outbees pull from a shared
   PlugPress design-system package (React components or a token file), Saddle
   should consume that same package rather than re-implementing it. Re-using it
   is the whole point of "same workspace."

## Constraints that still apply

- `@wordpress/components` only for UI primitives (see CLAUDE.md). No Tailwind,
  no styled-components, no separate UI kit — unless this research concludes a
  shared PlugPress package is the established pattern and overrides that.
- The Settings page must remain a single mounted React root (`#saddle-root`).
- Don't regress accessibility: keep `@wordpress/components` semantics (labels,
  focus states) intact when restyling.

## Done when

- `admin/src/style.scss` is built from real reference material (tokens traceable
  to inbees/outbees source, not invented).
- A real Saddle icon replaces the placeholder `dashicons-rest-api` menu icon.
- Side by side with inbees/outbees, the Saddle settings page is recognizably the
  same product family.
