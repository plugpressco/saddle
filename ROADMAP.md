# Saddle — direction

**Near-term authorizer.** What ships next, in order. The long-horizon product
thesis — Cloud, multi-site, tasks, automations, agents — is
[`ROADMAP-PRODUCT.md`](ROADMAP-PRODUCT.md); read it for destination, read this
for what to pick up now. Where the two disagree on timing, this file wins.

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
   (the advice every thread gives by hand, as a checkbox; shipped in #168);
   `upload-media` accepting inline content so a write-capable client without a
   public URL is not stranded; `verify-page` proving the *public* page serves the
   new content, not just the read-back. All free. **Bulk post operations** with
   preview → confirm token → batch undo, the same shape as Pro's `wc-bulk-*`, are
   **Pro** (decided 2026-09-27; this line said free until then). That matches the
   2026-09-26 split, where free edits and Pro operates the site. They can move to
   free later, but never back (R6).

   **Added 2026-09-27 (evening), in this order, all free:** updates and health
   (`list-updates`, `update-plugin`, `update-theme`, `set-auto-update`,
   `get-site-health`; applied through core's own updater with its temporary
   backup and fatal-check rollback, admin tier, gated, never an install or a
   core update); `undo-changes` (#181) and rehearsal mode (#180); custom post
   types (#137); menus (#244); template, part and pattern writes (#172). Ordered
   by what people use and what saves time, then by what keeps "safe on a live
   site" true as rivals ship undo and snapshots. The research is in the
   2026-09-27 plan; the updates decision revises the morning's "reported, never
   run" and is written into `CLAUDE.md`'s hard line with the code.
3. **The agency workflow.** Divi 5 page editing moves to free (2026-09-26 split:
   free edits, Pro operates the site). Divi design-system export/import as JSON (Pro) —
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

   *Noted 2026-09-27:* the pages actually built on saddle.to compare WPVibe and
   Novamira, not the three named above; the benchmark has not been run; and no
   document states the family in one line. Proposed, for Fahim's copy review:
   "One connection, your whole site: your AI reads your analytics, fixes your
   SEO, writes and designs your pages, keeps your plugins updated, and asks
   before anything it can't undo. Nothing leaves your site."

**Decided 2026-09-27, later the same day:**

- **Connect by address first.** Once the owner turns sign-in on (one labelled
  button; the option still defaults to off), every app card leads with the
  site's MCP address and the browser consent screen; the Application Password
  is the fallback (#243). Claude, Claude Code, ChatGPT, Codex, Cursor, VS Code,
  Gemini CLI and Windsurf all take a bare address. Deep links follow (#179).
- **Saddle applies updates.** See pillar 2. No `run-wp-cli` tool, ever: WPVibe's
  "WP-CLI" is allowlisted in-process PHP with a command string in front, and
  Saddle's typed abilities are the same power with a per-tool tier and gate.
- **Multi-site waits for Phase 3.** The per-site tools above are what any
  router will route. The Phase 3 write-up records that routing's market price
  is now $0 (WPVibe: unlimited sites free; MainWP: a local MCP server), so the
  paywall is automations, approvals and agents.

**Decided 2026-09-27, while starting Phase 1:**

- **Pause keeps tools listed.** A paused site refuses every call and names the
  pause as the reason, but `tools/list` still lists every tool. Hiding them would
  leave ChatGPT's frozen tool list stale after resume.
- **OAuth accepts any port on a loopback redirect URI** (`127.0.0.1`, `[::1]`,
  `localhost`), as RFC 8252 §7.3 requires for native clients such as Claude Code.
  Every other redirect URI stays an exact string match.

## Explicit NO list

Do not open issues for these. If a paying customer asks by name, the ask goes on
the issue and the rule is revisited there.

- **New builders.** Elementor has six competing MCP servers; Bricks 2.4 ships its
  own abilities natively; only Divi has paying customers.
- **Partner-plugin wrappers without a named customer** (Slim SEO was closed on this
  rule).
- **Divi 4 → 5 conversion.** Divi ships its own converter.
- **Filesystem writes in free, ever.** A theme-export addon is the only place that
  belongs, and CSS or code writing anywhere waits until that addon exists. The
  one exception is owner-only and predates this rule: the connection check's
  "Fix it for me" button adds Saddle's marked `.htaccess` block through core's
  `insert_with_markers()`. No app can reach it (#271).
- **Admin UI redesigns.** The re-brand landed 2026-08-25. One exception, approved
  2026-09-07: a *consolidation* pass (#184) — same components, same styling, fewer
  places for the same fact. Still no re-styling. A second, on Fahim's request
  2026-09-29: first run (#269), so a new owner sees Saddle read their site before
  it asks for anything. Same kit and tokens; the rest of the admin is unchanged.
  A third, decided by Fahim 2026-09-30: the unified admin (#272, #274 and the
  phases after them). It changes structure, not style. Saddle's WordPress
  submenu becomes the nav, with one page per area and no sidebar inside the
  page, and each sibling plugin adds its page through `saddle_modules`. Same
  kit and tokens. A fourth, on Fahim's request 2026-10-01 after reviewing
  Jetpack's admin: a visual redesign (#280–#282). The admin becomes
  WordPress-native (its greys, links and focus) with one brand color, Petrol
  teal, a full-width header band with the tabs inside it, one small type scale,
  and less text on every page. Still one kit. A fifth, the same day, after
  Fahim saw it: four pages (Dashboard, AI apps, Context, Settings) and no
  Permissions page (#285).
- **Runtime license checks in Pro** (decided 2026-07-12, saddle-pro#23).
- Comment abilities, activity-log export and retention — closed as not
  planned on 2026-09-07. *Per-connection access*, closed with them, was
  reopened by Fahim on 2026-10-01 (#286): each connected app has its own
  role (Read only, Edit content, Manage the site, which are the read, write
  and admin tiers), new apps start at Read only, and an update never widens
  what an existing app could do. Big changes can also be approved on the
  Dashboard (#287). Per-tool custom profiles stay out.

## Kept, not scheduled (label `later`)

- **Custom post type support across the content abilities.** Real agency pull, but
  it touches the whole read-authorization funnel; size it after the pillar-2 work.
- ~~**Menu abilities.**~~ Scheduled 2026-09-27 as #244: classic themes have no
  navigation block, and WPVibe and Divi AI Agents both ship menus.
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

## Backlog from the 2026-09-07 brainstorm

Direction, not tickets. Seven picks became issues the same day (#178–#184), were
closed in the 2026-09-23 backlog reset, and on 2026-09-27 the ones the research
supported were reopened: one-click install links (#179), rehearsal mode (#180),
undo-changes (#181), MCP prompts from Skills (#178) and accessibility lint rules
(#183), plus custom post types (#137) and template writes (#172) from earlier.
Bulk-find-replace (#182) is Pro scope under saddle-pro#87; admin consolidation
(#184) stays closed. The rest is kept here so it is not lost and not re-litigated.

**Safety (pillar 2).** A proposal inbox in admin — agent drafts and pending revisions
listed as diffs with Approve / Reject, after #168. A unified text diff in every gate
preview. A per-connection write budget (rows per bulk op, writes per hour), which is
#141 reframed as a number the owner sets.

**Content.** Custom post types (#137) plus generic post-meta get/set behind an
owner-managed allowlist of keys. Optional `publish_at` on create/update. Compact reads
for token efficiency: a page-as-markdown read in free and a `site-map` tool (URLs,
titles, types, modified), both feeding the benchmark (#174). `edit-media` for crop,
rotate, scale and thumbnail regeneration through `WP_Image_Editor`. `export-content`
returning WXR or a JSON block tree, as the block-theme mirror of the Divi export and
as a backup step before a bulk op.

**Block-theme design (pillar 3).** `set-global-styles` writing the user global-styles
post, gated on overwrite, DB-only. Create and update patterns as `wp_block` posts,
alongside #172.

**Protocol polish.** MCP resources for site info, design system and the served
context. A `self-check` tool so an agent can diagnose a stripped auth header or
missing permalinks and tell the user what to fix.

**Trust and visibility.** A daily change digest by `wp_mail`, off by default. A
Saddle section in Tools → Site Health. WP-CLI (`wp saddle connection create
--app=claude-code`, `wp saddle tier set read`) and `SADDLE_TIER` / `SADDLE_PAUSED`
constants for provisioning many sites from a script.

**Ease of use.** Activity grouped by app and session, not only by day. Three sample
prompts per app at the end of the wizard so the first session succeeds in read mode.
