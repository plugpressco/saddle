# CLAUDE.md — Saddle

Read this before touching code. It encodes decisions already made — don't re-derive
or re-litigate them mid-session, follow them. If something here seems wrong, flag it
to Fahim explicitly rather than silently deviating. Then read `BUILD-GUIDE.md` for
execution order and `MVP-PLAN.md` for scope.

## What this is

Self-hosted WordPress MCP server: tiered, approval-gated CRUD for posts, pages, and
media, plus builder-agnostic block tooling. Check the scope lock in `MVP-PLAN.md`
before adding anything not already there.

**Distribution:** no WP.org submission planned (decided 2026-07-03) — self-hosted
only for now. Don't spend effort on WP.org-specific compliance beyond normal good
practice. Revisit only when Fahim explicitly reopens it.

## Feature workflow — GitHub Issues, one project

Every feature or backlog idea that comes up in conversation gets tracked as a GitHub
issue — not left in chat, and not a local `.md` file (the old `TASKS.md` backlog was
retired 2026-07-06; don't recreate it).

- File the issue in whichever repo it belongs to: `plugpressco/saddle` (this repo) or
  `plugpressco/saddle-pro`.
- Add it to the **PlugPress HQ** org project: `https://github.com/orgs/plugpressco/projects/3`.
  One board for everything — don't spin up a second project.

## The three non-negotiables

Out of reverse-engineering three competitors (Vibe AI, Novamira, AI Engine) and
finding each fails at least one. Check every PR against all three before merge:

1. **No third-party custody.** Nothing — tokens, site data, tool-call traffic — ever
   leaves the user's own WordPress install to a server you control. No relay, no
   proxy. If you're about to add `wp_remote_post`/`wp_remote_get` to an external host
   for anything other than fetching a resource the user explicitly requested, stop
   and ask first.
2. **Default-safe, not opt-out-unsafe.** New installs default to the `read` tier.
   Never change this default. Power is something the user turns on, never something
   they have to turn off.
3. **No destructive action without a two-step confirm.** Any ability that mutates
   more than one row, or deletes/overwrites without recovery, goes through
   `Saddle_Approval::gate()`. No exceptions — that exact shortcut is how Novamira's
   `run-wp-cli` ability shipped with zero gate.

## Auth model

Core Application Passwords, not a custom OAuth server. `Saddle_MCP`'s REST route
requires only `is_user_logged_in()`; core resolves Basic-Auth application passwords
into the current user, and each ability's `permission_callback` (via
`Saddle_Capabilities`) enforces per-tool tier from there. The connect wizard issues
the credential directly via
`WP_Application_Passwords::create_new_application_password()`
(`POST /saddle/v1/clients`, `manage_options`-gated) rather than round-tripping
through `wp-admin/authorize-application.php`. Don't add a custom auth layer without a
specific, written reason core's flow can't satisfy.

## Architecture map (current, not aspirational — MVP-PLAN.md flags stub vs. real)

```
saddle.php                    — bootstrap; wires hooks, defers MCP transport to plugins_loaded
includes/
  class-saddle-tree.php       — builder-agnostic block-tree engine (parse/address/mutate/serialize)
  class-saddle-blocks-*.php   — Gutenberg validation profile, authoring layer, schema/tokens, applied-vs-ignored echo
  lint/                       — design lint engine (DESIGN-PLAN §2.1): Saddle_Lint runner + Saddle_Lint_Accessor
                                 interface (only builder-specific surface) + 8 rules; Saddle Pro plugs Divi in
  class-saddle-capabilities.php — tier system (read/write/admin), single source of truth for permission_callback
  class-saddle-approval.php   — dry-run + confirm-token gate, single-use, 15-min TTL, target-bound
  class-saddle-log.php        — activity log (saddle_log CPT), executed mutations only
  class-saddle-context.php    — auto system context + owner instructions (get-instructions)
  class-saddle-integrations.php — free first-party integration engine (wraps waggle/* as saddle/waggle-*,
                                 full safety model applied on top; saddle_integrations filter)
  class-saddle-mcp.php        — MCP transport on the official WP\MCP Adapter, JSON-RPC fallback
  abilities/                  — core-content (23), blocks (11), site (9, admin-tier settings), context (3),
                                 lint (1), memory (3) — 50 free abilities total
  class-saddle-skills.php     — skills store (saddle_skill CPT), owner-installed .md playbooks
  class-saddle-memory.php     — agent memory store (saddle_memory CPT); trust split — agent entries are
                                 recall-only until owner-pinned, autoinject defaults OFF
  admin/                       — REST API + settings page for the React UI
  lib/wp-mcp/                 — vendored WP\MCP Adapter
admin/src/                    — React UI: Onboarding, Home, Permissions, Guidance (+Memory), ConnectWizard,
                                 ConnectedClients — see DESIGN-ALIGNMENT.md before writing CSS
tests/                        — PHPUnit integration suite (SQLite-backed, real WP) — tests/README.md
```

## Coding conventions

- WordPress Coding Standards, tabs not spaces in PHP.
- Every ability declares explicit `accessLevel` (`read`/`write`/`admin`) and
  `destructive` (`true`/`false`) — don't infer tier from the function body.
- Ability `description` fields are read by the agent to decide when to call the
  tool — write them like documentation, not code comments.
- No `eval()`, `proc_open`, `shell_exec`, `exec()` anywhere. Grep for these four
  before every release.
- React: `@wordpress/components` only — no separate UI kit, no Tailwind, until
  `DESIGN-ALIGNMENT.md` says otherwise.

## Testing checklist before any release

> Tier + approval-gate + log behavior is covered by `composer test` (PHPUnit,
> SQLite-backed). Connect-flow round-trip and revoke-invalidation are still manual.

- [ ] Fresh install defaults to `read` tier — verify in DB, not just UI
- [ ] A destructive tool call without `confirm_token` returns a preview, mutates nothing
- [ ] A destructive tool call with a valid, unused token executes exactly once
- [ ] `delete_post`/`delete_page` without `force` trashes, not permanently deletes
- [ ] No outbound HTTP to any dotyard/PlugPress-controlled domain during normal operation
- [ ] Connect flow round-trips through core's Authorize Application screen and back
- [ ] Revoke actually invalidates the credential

## What NOT to do, even if it seems like a small improvement

- Don't add a "quick mode" that skips the approval gate for trusted users — a real
  safety mechanism defaulted-out under friction pressure is what broke trust in all
  three competitors checked for this project.
- Don't add raw PHP execution or shell WP-CLI as a power-user convenience here. If
  ever built, it's a separate, clearly-labeled addon — never silently available in
  core (see Phase 3 in MVP-PLAN.md).
- Don't change the default tier away from `read`, even temporarily for testing.
- Don't build a custom OAuth server "for nicer UX" without confirming core's
  Authorize Application flow is actually insufficient first — it almost certainly
  isn't.
- Don't re-enable `Saddle_Ecosystem` without an explicit decision to reopen Phase 3
  scope — it's dead code on purpose.
## Session workflow

This repo is tracked on the [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3) org board (plugpressco, project #3).

- **Start of session:** read `STATUS.md` — "Last session" says what happened, "Next up" says what's queued.
- **During the session:** keep the board honest — move cards across Status (Todo → In Progress → Done), and set Tier (`build` / `slow-burn` / `maintain`) and Work Type (`bug` / `feature` / `support` / `marketing` / `interrupt`) on anything new.
- **End of session:** update `STATUS.md` — replace "Last session" with what actually happened this session, and refresh "Next up" for whoever (or whatever) picks this up next.
