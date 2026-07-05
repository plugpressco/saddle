# Saddle — Finalized Plan (MVP + Phase 2 + Phase 3)

**One-liner:** Self-hosted MCP server for WordPress. Tiered, default-safe, approval-gated access for AI agents.

**The bet:** Vibe AI, Novamira, and AI Engine all already do "AI talks to WordPress." None of them combine (a) zero third-party credential custody, (b) default-safe tiering enforced consistently, and (c) a real two-step confirm on every destructive action. That combination is the entire differentiation. Nothing gets added to any phase below unless it serves one of those three or is required infrastructure to ship them.

---

## Scope lock — read this before changing anything

- **Content scope: post, page, media only.** No ecosystem integration (inbees/outbees/mailyard/formyard/flypops) in v0.1 — `Saddle_Ecosystem` exists in the codebase but is not instantiated, intentionally dead code until reopened.
- **Auth: WordPress core Application Passwords + the native Authorize Application flow** (`wp-admin/authorize-application.php`), not a custom OAuth server. This is a deliberate correction from earlier scaffolding notes that said "build OAuth like AI Engine" — core already provides the same one-click-connect UX with zero custom security-sensitive auth code to write or maintain. Don't reintroduce a custom OAuth server without a concrete reason core's flow can't satisfy.
- **No raw code execution, no shell WP-CLI passthrough**, ever, in this codebase. If a future feature seems to need either, that's a signal to redesign the feature, not add it. (Applies indefinitely, not just MVP — repeated from CLAUDE.md deliberately, this is the one rule most likely to get "simplified away" under time pressure.)

---

## v0.1 — MVP status (VERIFIED LIVE on WP 7.0, 2026-07-02)

| Piece | Status |
|---|---|
| Plugin bootstrap, Abilities API guard | Built + verified (boots clean, no fatal) |
| `Saddle_Capabilities` (read/write/admin tiers, default read) | Built + verified (read blocks write) |
| `Saddle_Approval` (dry-run + confirm-token gate, 15-min TTL) | Built + verified (preview→confirm→single-use) |
| MCP transport — **official WordPress MCP Adapter** (`WP\MCP`), custom server at `/saddle/v1/mcp`; `Saddle_MCP` kept only as a fallback when the adapter is absent | Built + verified (19 tools over HTTP, session-based) |
| **19** post/page/media abilities + `get-instructions` (dash-named ids; exposed to agents as `saddle-<verb>-<noun>`) | Built + verified |
| `Saddle_REST_Admin` (settings, connect-url, clients, capabilities, context, audit-log stub) | Built |
| `Saddle_Context` — auto system context (plugin-aware) + owner instructions | Built |
| React admin UI — guided **Home / Permissions / Guidance / Apps** + 3-step onboarding; pre-connect security modal; paste-password auto-config | Built (Vercel/OpenAI aesthetic; tokens still generic pending inbees/outbees — see `admin/DESIGN-ALIGNMENT.md`) |
| Real audit log (`saddle_log` CPT; logs every executed write/delete, reads silent; bounded GC; surfaced in Home → Recent activity) | Built + verified (Phase 2 #1 done) |
| Visual design tokens matching inbees/outbees | **Not built** — blocked on seeing real source, see Build Guide Step 6 |
| `Saddle_Ecosystem` | Built, intentionally inert |

**Verified live via WP-CLI on a Studio WP 7.0 site (with the mcp-adapter plugin active):** activation clean; MCP Adapter serves the custom Saddle server with 19 tools over the session-based HTTP transport; tier gate and approval gate confirmed end-to-end. See `saddle-ai.php` / `class-saddle-mcp.php` for the adapter wiring.

---

## v0.1 exit criteria — status (✅ = verified live 2026-07-02, ⬜ = still to do)

1. ✅ Plugin activates with zero fatal errors on WP 7.0.
2. ✅ `tools/list` over `/saddle/v1/mcp` returns all **19** tools with schemas (via the MCP Adapter's session-based transport; tool names are dash-form, e.g. `saddle-list-posts`).
3. ✅ A `read`-tier connection can call the read tools (`saddle-get-site-info` etc. return data).
4. ✅ A `write`-tier connection can create/update content (verified `saddle-create-post`); media upload URL path built but not yet exercised end-to-end.
5. ✅ A destructive call without `confirm_token` returns a preview and mutates nothing; a second call with the token executes exactly once; the token is single-use.
6. ✅ Fresh install defaults to `read` (option verified).
7. ⬜ The Connect button round trip (click → core Authorize screen → approve → back → new entry) — screenshotted working manually; not yet automated.
8. ✅ No outbound HTTP to any dotyard/PlugPress domain during tool operation (the one exception: `upload-media` fetching an agent-supplied URL via WP core's HTTP API — SSRF hardening is under review in Phase B).

---

## Phase 2 — next after v0.1 ships (target: v0.2)

Ordered roughly by value, not all required before starting the next:

1. **Real audit log.** ✅ DONE (2026-07-02, verified live). Built as a `saddle_log` CPT; `Saddle_Approval::gate()` logs every executed destructive action, and `create/update/upload/update-media` log via `Saddle_Abilities::log()` — reads stay silent. Bounded GC (`saddle_log_max_entries`, default 1000). Surfaced in Home → Recent activity. See `includes/class-saddle-log.php`.
2. **Visual design tokens applied.** Pull real values from inbees/outbees per `admin/DESIGN-ALIGNMENT.md`, write `admin/src/style.scss`, swap the placeholder dashicon for a real Saddle icon.
3. **WP-CLI-equivalent native abilities.** ✅ DONE (2026-07-04). Nine `admin`-tier ops in `includes/abilities/site.php`, all PHP-native dispatch (no shell, no eval, no raw DB): `list-plugins`, `activate-plugin`, `deactivate-plugin` (self-deactivation refused), `list-themes`, `activate-theme`, `list-options`, `get-option`, `update-option`, `flush-cache`. Options are confined to a filterable allowlist with a hard blocklist that always wins (siteurl/home, auth keys/salts, active_plugins/template/stylesheet, default_role/user_roles/users_can_register/admin_email); `update-option` overwrites without recovery so it routes through `Saddle_Approval::gate()` with the new value bound into the token. These sit at the new `admin` tier — a separate, explicit opt-in surfaced as the third "Managing the site" level (never bundled into write). Total abilities now **43**. Left deliberately out (higher risk, own decision): plugin install/update/delete, user management (item 5), revision restore (item 7).
4. **Taxonomies.** ✅ DONE (2026-07-02, verified live). `saddle/list-categories`, `saddle/create-category`, `saddle/list-tags`, `saddle/create-tag` (read/write tier; creates log + require `manage_categories`). Total abilities now **23**.
5. **Users — read only.** `get_user`, `list_users`. No create/update/delete on users in Phase 2 — that's a meaningfully higher-risk surface (privilege escalation potential) and deserves its own scoped discussion before being added, not a default inclusion.
6. **Comments — moderate only.** `list_comments`, `approve_comment`, `spam_comment`, `trash_comment` (the last two destructive, gated same as post/page deletes). No `create_comment` — agents posting comments as the site isn't an obvious win and opens spam/abuse questions worth a separate decision.
7. **Revision restore**, approval-gated the same way as deletes — explicitly cut from v0.1 (`list_post_revisions` is read-only there), build properly through `Saddle_Approval::gate()` once there's real usage data on whether anyone's asking for it.

---

## Phase 3 — explicitly deferred, revisit only when reopened

- **Ecosystem integration** (inbees/outbees/mailyard/formyard/flypops cross-product orchestration via `Saddle_Ecosystem`). Parked per earlier scope decision, not cancelled.
- **Custom post type generalization.** Current abilities are hardcoded to `post`/`page`. Generalizing to arbitrary registered post types is a real but non-trivial refactor (schema becomes dynamic per post type) — worth doing once there's a concrete CPT use case, not speculatively.
- **Raw execute-php as a separate, explicitly-labeled, separately-installed addon.** Never folded into core. If built, it needs its own approval/sandboxing design from scratch — don't treat it as "Tier 2 of the existing system."
- **Theme builder / draft-preview-publish workflow, Elementor support, multisite.** Tier 2/3 cuts from the original 80/20 analysis, still correct to defer — high build cost, lower call frequency than content ops, not the differentiation.

---

## WordPress.org submission — PARKED (2026-07-03): no submission planned right now, see CLAUDE.md "Distribution status"

> Kept for reference only. Do not treat anything below as pending work until the decision is explicitly reopened.

Saddle is, structurally, **more** of an "external services" / "AI integration" surface than mailyard was — it's the entire product, not one feature. Expect WP.org review scrutiny on:
- **External services disclosure**: even though there's no relay server, the plugin connects to MCP clients and the `upload_media` ability fetches arbitrary user-specified URLs — both need clear plain-language disclosure in `readme.txt`, written before submission, not bolted on after a reviewer flags it (same lesson from mailyard's review cycle).
- **Justifying the MCP integration itself** — have a tight, specific answer ready for "why does a WordPress plugin need to let an external AI agent create/delete content," same category of question mailyard got for its MCP integration.
- **Ownership/ownership verification** — make sure the WP.org submitter account, the `Contributors: badhonrocks` readme field, and the `Author: PlugPress` plugin header are consistent and verifiable, since mismatches here were part of what slowed mailyard's review.
- **Webhook/endpoint security** — `saddle/v1/mcp` is a new authenticated endpoint; document the Application Passwords auth model explicitly in the readme so a reviewer doesn't have to infer it from code.

Don't wait until submission to write this — draft the external-services disclosure section in parallel with Phase 2, while the mailyard review experience is still fresh.
