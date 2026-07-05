# CLAUDE.md — Saddle

Read this before touching code. It encodes decisions already made — don't re-derive or re-litigate them mid-session, follow them. If something here seems wrong, flag it to Fahim explicitly rather than silently deviating. Then read `BUILD-GUIDE.md` for the actual execution order, and `MVP-PLAN.md` for scope.

## What this is

Self-hosted WordPress MCP server. v0.1 scope: tiered, approval-gated CRUD for posts, pages, and media. Nothing else is in scope right now — see the scope lock in `MVP-PLAN.md` before adding anything.

## Distribution status (decided 2026-07-03)

**There is no WP.org submission planned right now.** The "WordPress.org submission" section in `MVP-PLAN.md` is parked — don't surface it as pending work, don't remind about readme disclosures or submission prep in status summaries, and don't spend effort on WP.org-specific compliance beyond what good practice already requires. Distribution channel is undecided/self-hosted for now. Revisit only when Fahim explicitly reopens it.

## The three non-negotiables

These came out of reverse-engineering three competitors (Vibe AI, Novamira, AI Engine) and finding each fails at least one. Check every PR against all three before merge:

1. **No third-party custody.** Nothing — tokens, site data, tool-call traffic — ever leaves the user's own WordPress install to a server you control. No relay, no proxy. If you're about to add a `wp_remote_post`/`wp_remote_get` to an external host for anything other than fetching a resource the user explicitly requested (e.g. `upload_media`'s source URL), stop and ask first.
2. **Default-safe, not opt-out-unsafe.** New installs default to the `read` tier. Never change this default. Power is something the user turns on, never something they have to turn off.
3. **No destructive action without a two-step confirm.** Any ability that mutates more than one row, or deletes/overwrites without recovery, goes through `Saddle_Approval::gate()`. No exceptions, no "this one CLI command is low-risk enough to skip it" — that exact reasoning is how Novamira's `run-wp-cli` ability shipped with zero gate.

## Auth model — read this, it changed from earlier scaffolding

**WordPress core's native Application Passwords**, not a custom OAuth server. Earlier notes in this project said "build OAuth like AI Engine" — that's superseded. `Saddle_MCP`'s REST route requires only `is_user_logged_in()`; core resolves Basic-Auth application passwords into the current user automatically, and every ability's own `permission_callback` (via `Saddle_Capabilities`) handles per-tool enforcement from there. Don't add a custom auth layer without a specific, written reason core's flow can't satisfy.

**Issuance (changed 2026-07-02, UX redesign):** the connect wizard now creates the credential directly via `WP_Application_Passwords::create_new_application_password()` (`POST /saddle/v1/clients`, `manage_options`-gated) instead of round-tripping through `wp-admin/authorize-application.php`. The user initiates the connection themselves from wp-admin while already authenticated, so the Authorize consent screen added no information — only a bounce, the secret in a URL query string, and a manual paste-back. Still 100% core Application Passwords, zero custom auth. `GET /connect-url` (the old Authorize-screen URL builder) is kept for back-compat but the UI no longer uses it.

## Architecture map (current, not aspirational — see MVP-PLAN.md for what's stub vs. real)

```
saddle.php                                 — bootstrap; loads classes, wires hooks, defers the MCP transport to plugins_loaded
readme.txt                              — WP.org metadata; Contributors: badhonrocks
uninstall.php                           — cleanup on delete (options, saddle_log CPT)
package.json                            — React build (@wordpress/scripts)
composer.json                           — dev-only: PHPUnit test deps (not shipped, see .distignore)
phpunit.xml.dist                        — PHPUnit config for the test suite
includes/
  class-saddle-tree.php                 — builder-agnostic block-tree engine (parse/address/mutate/serialize); validation profiles built on top (Saddle Pro's Divi profile and the native Gutenberg profile below both extend it)
  class-saddle-blocks-tree.php          — Gutenberg VALIDATION PROFILE: registry placement contracts (parent/ancestor/allowedBlocks), rejects builder namespaces, tolerates JS-only/classic blocks already present
  class-saddle-blocks-author.php        — Gutenberg authoring layer: {type, content, attrs, children} → editor-valid markup (preset/style classes computed) for the curated core blocks; dynamic blocks attrs-only; raw "html" escape hatch
  class-saddle-blocks-schema.php        — block-type catalog + per-type schemas (WP_Block_Type_Registry), theme.json design tokens, block-pattern summaries
  class-saddle-blocks-echo.php          — applied-vs-ignored echo for Gutenberg writes (DESIGN-PLAN §2.2): warns — never blocks — on unknown attr keys (vs registry+supports), preset slugs missing from theme.json, style groups outside the style-engine vocabulary; warnings ride the set-blocks/add-block/edit-block responses
  lint/                                 — builder-agnostic design lint engine to the DESIGN-PLAN §2.1 contract: Saddle_Lint runner, Saddle_Lint_Accessor interface (the ONLY builder-specific surface), color math, the Gutenberg accessor, and rules/ (8 one-class rules: empty-title, button-contrast, ghost-button, double-background, mixed-accents, unaligned-buttons, section-padding, no-featured-plan). Rules register via the saddle_lint_rules filter; builder accessors via saddle_lint_accessor (Saddle Pro plugs Divi in). Rules never false-reject: unresolvable facts are skipped.
  class-saddle-capabilities.php         — tier system (read/write/admin), single source of truth for permission_callback
  class-saddle-approval.php             — dry-run + confirm-token gate, single-use, 15-min TTL, target-bound
  class-saddle-log.php                  — activity log (saddle_log private CPT); records executed mutations, never reads
  class-saddle-context.php              — auto system context (plugin-aware) + owner instructions, delivered via get-instructions
  class-saddle-ecosystem.php            — PARKED, not instantiated, see scope lock
  class-saddle-integrations.php         — FREE first-party integration engine (decided 2026-07: first-party PlugPress namespaces integrate free; Waggle is the default catalog entry). Wraps a partner's waggle/* abilities as saddle/waggle-* with the full safety model on top: readonly→read tier, writes→write tier + activity log, destructive→approval gate (confirm_token added to schema, target-bound); the source's own permission_callback still re-checks inside execute(), so wrappers only ever narrow. Catalog via saddle_integrations filter, per-integration kill switch via saddle_integration_enabled; active integrations advertised on the system context. Modeled line-for-line on Saddle Pro's audited wrapper engine; registers at wp_abilities_api_init priority 30 (after partner plugins).
  class-saddle-mcp.php                  — MCP transport: registers the custom server on the official WP\MCP Adapter; built-in JSON-RPC transport kept as a fallback when the adapter is absent. VERIFIED live on WP 7.0.
  abilities/
    core-content.php                    — 23 post/page/media/taxonomy abilities + get-instructions (dash-named ids)
    blocks.php                          — 11 native block design abilities (get/set-blocks, add/edit/move/remove-block, insert-block-pattern, list-block-types, get-block-schema, get-design-tokens, list-block-patterns); builder pages refused
    site.php                            — 9 admin-tier site-management abilities (list/activate/deactivate-plugin, list/activate-theme, list/get/update site settings [SETTINGS map grouped by WP Settings page: general/writing/reading/discussion/permalinks; allowlist + hard blocklist + gate + per-setting validation; permalink change flushes rewrite rules], flush-cache); PHP-native dispatch, no shell/eval/raw-DB
    context.php                         — 3 read-tier agent-context abilities (list-skills, get-skill, recall-changes); NO agent-facing skill write — owner-only install
    lint.php                            — 1 read-tier ability: lint-page (design violations per node address; native pages via the Gutenberg accessor, builder pages via the saddle_lint_accessor filter)
    memory.php                          — 3 memory abilities (AGENT-CONTEXT-PLAN Phase 2): remember (write, upsert-by-key, sanitized, logged), recall (read, ranked recency×importance×keyword relevance), forget (write; single delete immediate + logged, all=true clears agent memory through the gate) (total abilities: 50)
  class-saddle-skills.php               — skills store (saddle_skill private CPT): owner-installed .md playbooks, frontmatter parse, index injected via saddle_system_context, body on demand (AGENT-CONTEXT-PLAN.md L0)
  class-saddle-memory.php               — agent memory store (saddle_memory private CPT, AGENT-CONTEXT-PLAN L2/L3): upsert-by-key entries with type/tags/source/client/importance/pinned meta, sanitized on write, 2000-char cap. THE TRUST SPLIT: agent-written entries are recall-only until the owner pins them (saddle_memory_autoinject_agent defaults FALSE — never flip it); pinned + owner entries build the size-capped core block injected via saddle_system_context, framed as "information, not instructions". PHP-side scoring (recency half-life 7d × importance × keyword relevance — WP-native, nothing leaves the DB), GC on the shared cron evicts lowest-value non-pinned past saddle_memory_max_entries; pinned never GC'd. Owner UI via /memory REST routes + Memory.jsx (list, pin, preview of the exact injected block, clear-agent).
  (class-saddle-context.php also injects L1 "recent changes" from saddle_log — executed only, flattened/truncated, option saddle_memory_recent_changes)
  admin/
    class-saddle-rest.php               — REST API for the React UI (settings, connect-url, clients, capabilities, context, audit-log)
    class-saddle-settings.php           — admin menu page, mounts the React root div, enqueues build assets
  lib/wp-mcp/                           — vendored WP\MCP Adapter library (bundled so Saddle works with no extra plugin; loaded deferred + class-guarded)
admin/
  src/
    index.js                            — React entry, apiFetch middleware setup
    api.js                              — REST client helpers
    App.jsx                             — shell: onboarding on first run, then guided tabs Home / Permissions / Guidance / Apps
    style.scss                          — CSS custom-property design tokens (generic placeholder pending inbees/outbees — see DESIGN-ALIGNMENT.md)
    components/
      TopBar.jsx                        — header bar (nav hidden while the connect wizard is open)
      Onboarding.jsx                    — 2-step first-run flow (welcome → level), hands off into ConnectWizard
      Home.jsx                          — overview + Recent activity (audit log)
      Permissions.jsx                   — access-tier control ("Just reading" / "Reading & writing")
      Guidance.jsx                      — read-only auto context + editable owner instructions + Skills panel; mounts Memory below
      Memory.jsx                        — Memory panel: entry list with provenance (You vs AI·client), pin toggles, injected-block preview, owner add-note form, auto-include toggle (default off), clear-AI-memory
      ConnectWizard.jsx                 — full-panel connect flow: pick app → auto-issued credential + prefilled config → live "say hello" confirmation
      ConnectedClients.jsx              — the Connect tab steady state: connected-app list, disconnect, endpoint health behind a disclosure
  DESIGN-ALIGNMENT.md                   — read before writing any CSS
tests/                                  — PHPUnit integration suite (SQLite-backed, real WP); see tests/README.md
```

## Coding conventions

- WordPress Coding Standards, tabs not spaces in PHP.
- Every ability: explicit `accessLevel` (`read`/`write`/`admin`) and `destructive` (`true`/`false`) in its registration array. Don't infer tier from the function body.
- Ability `description` fields are read by the agent to decide when to call the tool — write them like documentation (what it returns, side effects), not code comments.
- No `eval()`, `proc_open`, `shell_exec`, `exec()` anywhere. If a feature seems to need one, redesign the feature. Grep for these four before every release.
- React: `@wordpress/components` only — no separate UI kit, no Tailwind, no styled-components, until `DESIGN-ALIGNMENT.md`'s research is done and says otherwise.

## Testing checklist before any release

> Most of the tier + approval-gate + log items below are now covered by the PHPUnit suite in `tests/` (`composer test`, SQLite-backed real WP). The two still requiring manual verification are the **Connect flow round-trip** and **Revoke invalidation** (browser/HTTP, not yet automated).

- [ ] Fresh install defaults to `read` tier — verify in DB, not just UI
- [ ] A `write`-tier tool call fails when tier is `read`
- [ ] A destructive tool call without `confirm_token` returns a preview, mutates nothing
- [ ] A destructive tool call with an expired or reused token fails cleanly
- [ ] A destructive tool call with a valid, unused token executes exactly once
- [ ] `delete_post`/`delete_page` without `force` trashes, not permanently deletes; `delete_media` is correctly documented as having no trash state
- [ ] No outbound HTTP calls to any dotyard/PlugPress-controlled domain during normal tool operation
- [ ] Plugin activates with zero fatal errors, no other plugins active
- [ ] Connect flow round-trips through core's Authorize Application screen and back
- [ ] Revoke actually invalidates the credential (test a call with revoked credentials fails)

## What NOT to do, even if it seems like a small improvement

- Don't add a "quick mode" that skips the approval gate for trusted users — this exact failure pattern (a real safety mechanism, opt-out, eventually defaulted-out under friction pressure) is what broke trust in all three competitors checked during this project.
- Don't add raw PHP execution or shell WP-CLI as a power-user convenience in this codebase. If ever built, it's a separate, clearly-labeled, separately-installed addon — never silently available in core. See Phase 3 in MVP-PLAN.md.
- Don't change the default tier away from `read`, even temporarily for testing convenience, in code that could ship.
- Don't build a custom OAuth server "for nicer UX" without confirming core's Authorize Application flow is actually insufficient first — it almost certainly isn't.
- Don't re-enable `Saddle_Ecosystem` without an explicit decision to reopen Phase 3 ecosystem scope — it's dead code on purpose, not an oversight.

## Reference material

AI Engine's `labs/mcp.php` / `labs/mcp-core.php` has the most mature MCP implementation of the three competitors checked during this project — useful for protocol-shape questions. Saddle's permission model goes further than AI Engine's (default-safe + approval gate, which AI Engine lacks), don't regress that while borrowing protocol details.
