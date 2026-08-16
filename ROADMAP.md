# Saddle — direction

Moved out of `CLAUDE.md` on 2026-08-12. It was a dated section in a file that
loads on every task; roadmaps belong where they can go stale without misleading
anyone mid-session.

Each item becomes a GitHub issue when it's picked up. The positioning narrative
behind it is in `STATUS.md` (2026-08-12 entry).

**Re-derived 2026-08-15: items 1 and 2 have SHIPPED.** The text below was
written before they did; it is kept because items 3–6 still stand on it. Read
the gap description as history, not as the current state.

## The gap that matters — as it stood on 2026-08-12

**On a block theme, Saddle can build a page but not a site.** Verified against
the tree: there are zero abilities for templates, template parts, global styles,
user patterns or fonts, and no reference anywhere in `includes/` to
`wp_template`, `wp_global_styles`, `wp_font_family` or `wp_block`.

Worse, `saddle/bootstrap-design-system` **silently no-ops on block themes**
(`includes/abilities/blocks.php`): it returns `applied: false` and tells the
owner to go do it by hand in Appearance → Editor → Styles. Only Divi, via Pro's
filter, gets a design system actually written. An agent on a block-theme site
cannot see the header, cannot set the palette, and cannot save what it built as
a reusable pattern.

## Ordered, and deliberately split by risk

1. ~~**Site-editor reads (free, DB-only).**~~ **SHIPPED.** `list-templates`,
   `get-template`, `get-global-styles` and `list-saved-patterns` are registered
   in `includes/abilities/site-editor.php`, read tier. `list-fonts` was not
   built and nobody has asked for it.
2. ~~**Make `bootstrap-design-system` real on block themes.**~~ **SHIPPED.**
   `includes/abilities/blocks.php` resolves a `$store` — builder, global-styles,
   or none — *before* the gate, so a classic-theme site is refused up front with
   a reason instead of spending a single-use token to be told to go do it by
   hand. The block-theme path writes the spec into global styles.
3. **Template / part / pattern writes (free, DB-only).** `set-template`,
   `create-template-part`, and "save this subtree as a pattern" built on
   `Saddle_Tree`. Approval-gated on overwrite.
4. **Filesystem export — a separate addon, never free.** The block-theme
   equivalent of Create Block Theme, agent-driven: plan → export → clean, moving
   templates, parts, global styles, patterns and fonts out of the database and
   into theme files so an agency can version-control them. Native PHP via
   `WP_Filesystem` — **not** a bash or WP-CLI wrapper (see the hard line in
   `CLAUDE.md`). The `clean` step is exactly what `Saddle_Approval::gate()` was
   built for.
5. **If code-writing ever happens, it is that same addon, and CSS first.** A
   child theme stylesheet or Additional CSS is non-executable, covers most "make
   it look right" work, and pairs directly with `get-design-system`. Data files
   (`templates/*.html`, `theme.json`) next. PHP templates only if demand proves
   it out. Every one of them goes through diff-preview → confirm → `saddle_log` →
   revertable, which is the whole differentiator.
6. **The Divi analogue belongs in Pro.** Theme Builder templates, Global Presets
   and Global Colors all live in the database with no version-control story. Pro
   already reads all three; a JSON export/import into a repo is the direct
   parallel.

## Constraint on all of the above

Nothing that adds a filesystem write ships in free while the WordPress.org
submission is in flight.
