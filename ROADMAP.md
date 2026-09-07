# Saddle — direction

Reset on 2026-09-07 (issue #166). The previous version, written 2026-08-12 around the
block-theme gap, is in git history; items 1 and 2 of it shipped, item 3 is carried
below, items 4–6 are folded into the pillars. Each item here becomes a GitHub issue
when it is picked up; this file names direction, not tickets.

## What changed the picture (2026-09-06 research)

- **Elegant Themes shipped Divi AI Agents on 2026-09-04**, built into Divi 5, with an
  official MCP "on the way". "An AI that builds Divi pages" is no longer something
  only Pro offers. What survives as Pro's reason to exist: it runs from the
  developer's own Claude Code or Codex, on a live site, with no relay, no code
  execution, and a lint → render → verify loop the agent can be held to.
- **What the community actually asks for**, in order: (1) not being afraid of write
  access on production, (2) bulk content and meta operations — the one concrete
  success story anyone told was SEO meta on 300 posts, (3) verifying from outside
  the tool that wrote, because "the MCP read its own write back and reported
  success while the public page served old content". Nobody sells (1) as the
  headline.
- **Respira** sells duplicate-first / approval / 90-day undo across 17 builders for
  €9/month — through their own server. **WPVibe** (SeedProd) is a hosted relay with
  12,000 sites. Saddle's edge over both is one sentence, and it has to be the first
  sentence everywhere.

## The sentence

> Self-hosted WordPress MCP for Claude Code. Nothing leaves your site, nothing
> executes agent code, every destructive step previews and asks. Safe on a live site.

## Pillars, in priority order

1. **Ship what exists.** WordPress.org approval of 1.0.0; a green CI that means
   green; Pro releases for what is already on `main`.
2. **The production-safe write path is the product.** A drafts-only policy switch
   (the advice every thread gives by hand, as a checkbox); bulk post operations with
   preview → confirm token → batch undo, the same shape Pro's `wc-bulk-*` already
   has; `upload-media` accepting inline content so a write-capable client without a
   public URL is not stranded; `verify-page` proving the *public* page serves the
   new content, not just the read-back. All free.
3. **The agency workflow.** Divi design-system export/import as JSON (Pro) —
   global colors, fonts, variables, presets, Theme Builder templates, library
   items — the version-control story Divi AI Agents does not have. Template, part
   and pattern writes on block themes (free, DB-only, built on `Saddle_Tree`,
   approval-gated on overwrite) — the gap the previous roadmap called the one that
   matters.
4. **Say it out loud.** The sentence above leads both readmes and plugpress.co. A
   comparison page against Divi AI Agents, Respira and WPVibe on five rows: where
   traffic goes, code execution, works on a live site, verify loop, price. A
   token-per-task benchmark, because nobody measures it and address-based edits
   with compact reads are Saddle's answer to the "burns a five-hour window in an
   hour" complaint.

## Explicit NO list

Do not open issues for these. If a paying customer asks by name, the ask goes on
the issue and the rule is revisited there.

- **New builders.** Elementor has six competing MCP servers; Bricks 2.4 ships its
  own abilities natively; only Divi has paying customers.
- **Partner-plugin wrappers without a named customer** (Slim SEO was closed on this
  rule).
- **Divi 4 → 5 conversion.** Divi ships its own converter.
- **Filesystem writes in free, ever.** A theme-export addon is the only place that
  belongs, and CSS or code writing anywhere waits until that addon exists.
- **Admin UI redesigns.** The re-brand landed 2026-08-25.
- **Runtime license checks in Pro** (decided 2026-07-12, saddle-pro#23).
- Per-connection access profiles, comment abilities, activity-log export and
  retention — closed as not planned on 2026-09-07.

## Kept, not scheduled (label `later`)

- **Custom post type support across the content abilities.** Real agency pull, but
  it touches the whole read-authorization funnel; size it after the pillar-2 work.
- **Menu abilities.** Divi AI Agents covers menus on Divi; block themes have the
  navigation block.
- **Rate limiting on the MCP surface.** Do it after the positioning copy so it can
  be named there.

## Constraints that do not move

- Nothing that adds a filesystem write ships in free while a WordPress.org
  submission is in flight, and nothing self-hosted is published without a version
  bump Fahim approved.
- Tool changes stay backward compatible — new optional parameters only. ChatGPT
  freezes an approved connector's tool list until an admin refreshes it, and a
  renamed tool or new required parameter errors there with no prompt to update.
- The three non-negotiables in `CLAUDE.md` apply to every item above without
  exception.
