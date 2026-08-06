# CLAUDE.md — Saddle

Read this before touching code. It encodes decisions already made — don't re-derive
or re-litigate them mid-session, follow them. If something here seems wrong, flag it
to Fahim explicitly rather than silently deviating. Then read `BUILD-GUIDE.md` for
execution order and the [Finalized Plan](https://github.com/plugpressco/saddle/issues/12) for scope.

## What this is

Self-hosted WordPress MCP server: tiered, approval-gated CRUD for posts, pages, and
media, plus builder-agnostic block tooling. Check the scope lock in the
[Finalized Plan](https://github.com/plugpressco/saddle/issues/12) before adding anything not already there.

**Distribution:** WordPress.org submission planned (Fahim reopened this
2026-07-13, superseding the 2026-07-03 self-hosted-only call). WP.org
compliance is in scope. _2026-08-03: Fahim reversed the short-lived 2026-08-02
v1.1.0 release — the version stays **1.0.0** until WP.org approves the pending
submission. The v1.1.0 tag + GitHub release were deleted, its changelog entries
folded into 1.0.0, and everything shipped since simply rides in 1.0.0. Do not
bump the version again until Fahim confirms WP.org approval._

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

Two paths, both ending in a resolved WordPress user, both funnelling into the same
per-tool gate (`Saddle_Capabilities`). Adding a third needs a written reason.

**1. Core Application Passwords — the default, unchanged.** `Saddle_MCP`'s REST route
requires only `is_user_logged_in()`; core resolves Basic-Auth application passwords
into the current user. The connect wizard issues the credential directly via
`WP_Application_Passwords::create_new_application_password()`
(`POST /saddle/v1/clients`, `manage_options`-gated) rather than round-tripping
through `wp-admin/authorize-application.php`. This is the path for every client that
lets a person paste an HTTP header: Claude Code, Claude, Cursor, VS Code, Gemini CLI.

**2. A self-hosted OAuth 2.1 authorization server — opt-in, default OFF** (added
2026-07-28, `includes/oauth/`). The written reason core's flow can't satisfy:
**ChatGPT's custom-connector screen has no field for a custom HTTP header.** It
offers "no authentication", an API key, or OAuth — and none of those carries
`Authorization: Basic`. Core's flow produces exactly that credential, so it cannot
connect ChatGPT at all, and the MCP specification's answer is OAuth 2.1.

The server lives entirely inside the owner's WordPress — no relay, no PlugPress
host, non-negotiable #1 intact. It is off until the owner turns it on
(non-negotiable #2), and a granted scope only ever *lowers* the site tier, never
raises it: the clamp is `Saddle_Capabilities::get_tier()`, which is
`min(site tier, granted scope)`. Use `get_site_tier()` when reporting or writing
configuration, `get_tier()` when deciding whether a call is allowed.

Constraints that must not be relaxed: PKCE **S256 only** (never `plain`), redirect
URIs matched by **exact string comparison** (never prefix), refresh tokens rotate
with reuse detection, authorization-code replay revokes the whole grant, and
registration grants nothing until an administrator completes the consent screen.

## Architecture map (current, not aspirational — the [Finalized Plan](https://github.com/plugpressco/saddle/issues/12) flags stub vs. real)

```
saddle.php                    — bootstrap; wires hooks, defers MCP transport to plugins_loaded
includes/
  class-saddle-tree.php       — builder-agnostic block-tree engine (parse/address/mutate/serialize)
  class-saddle-blocks-*.php   — Gutenberg validation profile, authoring layer, schema/tokens, applied-vs-ignored echo
  lint/                       — design lint engine: Saddle_Lint runner + Saddle_Lint_Accessor
                                 interface (only builder-specific surface) + 12 rules incl. the
                                 builder-agnostic single-column-flow (doc-look) advisory; Saddle Pro
                                 plugs Divi in and adds its own rules via saddle_lint_rules
  verify/                     — scored verify engine; grades are HONEST: a structural finding caps
                                 the letter at C, an echo (ignored-styling) finding at B, and every
                                 verify-page report carries a `coverage` caveat (server-side only —
                                 real pixels need get-preview-url)
  class-saddle-capabilities.php — tier system (read/write/admin), single source of truth for permission_callback
  class-saddle-approval.php   — dry-run + confirm-token gate, single-use, 15-min TTL, target-bound
  class-saddle-log.php        — activity log (saddle_log CPT), executed mutations only
  class-saddle-context.php    — auto system context + owner instructions (get-instructions);
                                 design_numbers() is the SINGLE source of the shared design bar
                                 (Pro's skill embeds it verbatim — edit numbers here only), and
                                 the saddle_native_builders filter lets a builder addon replace
                                 the hands-off builder warning with in-scope guidance
  class-saddle-integrations.php — free first-party integration engine (wraps waggle/* as saddle/waggle-*,
                                 full safety model applied on top; saddle_integrations filter)
  class-saddle-mcp.php        — MCP transport on the official WP\MCP Adapter, JSON-RPC fallback
  class-saddle-http.php       — shared SSRF guard + capped JSON fetch (media sideload, OAuth client metadata)
  oauth/                      — OAuth 2.1 authorization server, OFF by default: discovery (RFC 9728/8414),
                                 client registration (DCR + Client ID Metadata Documents), authorize/token/
                                 revoke, the wp-admin consent screen, and the bearer resolver that clamps
                                 the effective tier to the granted scope
  abilities/                  — core-content (23), blocks (15), site (9, admin-tier settings), context (3),
                                 memory (3), unsplash (2), render (2), users (2), lint (1), verify (1) — 61 free
                                 abilities as of 1.0.0 (the Permissions UI is the authoritative live list)
  class-saddle-skills.php     — skills store (saddle_skill CPT), owner-installed .md playbooks.
                                 Bodies are Markdown-as-DATA: sanitize_body() keeps them
                                 byte-identical (UTF-8/control-char strip only) — never run a
                                 skill body through wp_kses/esc_html; angle-bracket placeholders
                                 like <id> are instruction text agents must receive verbatim
  class-saddle-memory.php     — agent memory store (saddle_memory CPT); trust split — agent entries are
                                 recall-only until owner-pinned, autoinject defaults OFF
  admin/                       — REST API + settings page for the React UI
  lib/wp-mcp/                 — vendored WP\MCP Adapter
admin/src/extensions.js       — the ADDON EXTENSION SEAM (shell v1): wp.hooks filters
                                 saddle.admin.settingsCards (a Card in Settings) and
                                 saddle.admin.tabs (a whole page + nav entry, routed at #<id>),
                                 both passing a `ui` object of already-bundled @plugpress/ui
                                 primitives so addon bundles ship none of their own. That object
                                 and the filter shapes are a PUBLIC CONTRACT — additive only;
                                 anything else bumps SHELL_VERSION here AND SADDLE_SHELL_VERSION
                                 in saddle.php. Addons enqueue on the `saddle_admin_enqueue`
                                 action with a dependency on the `saddle-admin` handle.
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
- React UI: **`@plugpress/ui`** (github tag pin in package.json) is the UI kit —
  import primitives directly from `@plugpress/ui`. No `@wordpress/components`,
  no Tailwind, no second kit. Product-specific pieces (BrandMark, LevelIcon,
  AppLogo, the Permissions lanes/chips, the activity timeline) stay in-plugin,
  styled on `--pp-*` tokens. Light-only — no theme toggles. The brand mark is
  single-sourced from `assets/brand/mark.svg` (React SVGR import + PHP menu
  icon read the same file). See `admin/DESIGN-ALIGNMENT.md`.

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
  core (see Phase 3 in the [Finalized Plan](https://github.com/plugpressco/saddle/issues/12)).
- Don't change the default tier away from `read`, even temporarily for testing.
- Don't remove the Application Password path now that OAuth exists. Every
  header-capable client uses it, it needs no consent round trip, and it is the only
  path that works while the OAuth toggle is off — which is the default.
- Don't turn OAuth on by default, and don't let a scope grant more than the site
  tier. Both are load-bearing for non-negotiable #2.
- Don't re-enable `Saddle_Ecosystem` without an explicit decision to reopen Phase 3
  scope — it's dead code on purpose.
## Session workflow

This repo is tracked on the [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3) org board (plugpressco, project #3).

- **Start of session:** read `STATUS.md` — "Last session" says what happened, "Next up" says what's queued.
- **During the session:** keep the board honest — move cards across Status (Todo → In Progress → Done), and set Tier (`build` / `slow-burn` / `maintain`) and Work Type (`bug` / `feature` / `support` / `marketing` / `interrupt`) on anything new.
- **End of session:** update `STATUS.md` — replace "Last session" with what actually happened this session, and refresh "Next up" for whoever (or whatever) picks this up next.

---

# GitHub Workflow — Agent Rules

## Definition of Done

A task is **not** done when the code works. It is done when **all** of these are true:

1. Every change is committed (no dirty working tree, no untracked source files)
2. The branch is **pushed to `origin`**
3. A PR exists, linked to the issue with a closing keyword
4. CI is green (or the failure is reported to me)
5. The merge policy below has been followed

**Never end a turn with unpushed commits or uncommitted changes.** If you stop
mid-task for any reason, commit and push what exists first, then tell me where
you stopped.

If a push or PR step fails (auth error, protected branch, no remote, network),
say so **explicitly in your final message** with the exact error. Do not stop
silently and do not pretend the work shipped.

---

## The Loop

Every unit of work starts from a GitHub issue. No issue, no branch.

### 1. Pick up the issue

```bash
gh issue view <N>                    # read the full issue + comments
gh issue edit <N> --add-label "in-progress"
```

If the issue is vague, ambiguous, or missing acceptance criteria — **ask me
before writing code**. Do not guess at scope.

### 2. Create the branch

Use `gh issue develop` so GitHub links the branch to the issue automatically:

```bash
gh issue develop <N> --base main --name feat/<N>-short-slug --checkout
```

Branch naming:

| Type | Prefix | Example |
|---|---|---|
| Feature | `feat/` | `feat/142-instant-indexing` |
| Bug fix | `fix/` | `fix/151-aioseo-pro-conflict` |
| Refactor | `refactor/` | `refactor/160-settings-api` |
| Docs / readme | `docs/` | `docs/163-readme-changelog` |
| Chore / deps | `chore/` | `chore/165-bump-wp-tested` |

Always branch from an up-to-date `main`:

```bash
git checkout main && git pull --ff-only origin main
```

### 3. Commit as you go

Commit at each logical checkpoint — not one giant commit at the end.
Conventional Commits format, one concern per commit:

```
feat(indexing): add IndexNow submission on post publish

Refs #142
```

```
fix(compat): guard against AIOSEO Pro sitemap filter

Closes #151
```

Rules:
- Subject line ≤ 72 chars, imperative mood, lowercase after the colon
- Reference the issue in **every** commit body (`Refs #N`)
- Never commit: `vendor/`, `node_modules/`, build output, `.env`, local WP
  config, `.DS_Store`. If they show up, fix `.gitignore` in the same PR.
- Never commit commented-out dead code or debug `error_log()` / `var_dump()`

### 4. Push — always

```bash
git push -u origin HEAD
```

Push after the **first** commit, not at the end. Then keep pushing after each
subsequent commit. I want to be able to see the branch on GitHub at any moment.

### 5. Open the PR

Open it as soon as the branch is pushed — draft is fine if work continues.

```bash
gh pr create --base main --draft --title "feat: instant indexing (#142)" --body-file .git/PR_BODY.md
```

PR body must follow this template:

```markdown
Closes #142

## What
One paragraph: what this changes, in plain language.

## Why
Link back to the problem in the issue.

## How
- Key implementation decisions
- Anything non-obvious a future reader would trip on

## Testing
- [ ] Tested on WP 7.0, PHP 8.2
- [ ] Tested with the plugin's known conflict list
- [ ] No PHP notices/warnings with WP_DEBUG on
- [ ] Checked free vs pro gating still correct

## Screenshots
(admin UI changes only)
```

`Closes #N` is mandatory — it auto-closes the issue on merge and keeps the
board accurate. Use `Refs #N` only when the PR genuinely does not finish the
issue.

When work is complete: `gh pr ready <N>`.

### 6. Verify before merge

```bash
gh pr checks --watch
```

CI runs on every push to a PR. Wait for it.

If CI fails, fix it on the same branch and push again. Never merge red.

### 7. Merge policy — **A, solo fast mode**

Once CI is green and the PR description is complete, merge it yourself:

```bash
gh pr merge --squash --delete-branch
git checkout main && git pull --ff-only origin main
```

Exception — **stop and ask me first** if the PR touches:
licensing/activation, payment or Freemius/Creem code, database schema or
migrations, uninstall routines, anything that ships to WordPress.org, a version
bump, or more than ~400 changed lines.

### 8. Close the loop

After merge:

```bash
gh issue edit <N> --remove-label "in-progress"
git branch -d feat/<N>-short-slug
```

Confirm the issue actually closed. If the board has a Status field, move the
card to Done. This repo is tracked on the [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3)
org board (plugpressco, project #3).

---

## Issue Hygiene

- **One issue = one PR = one branch.** If you discover unrelated work mid-task,
  open a new issue for it (`gh issue create`) and link it — do not scope-creep
  the current PR.
- Post a short progress comment on the issue when a task spans multiple
  sessions: what's done, what's left, branch name.
- If the issue turns out to be wrong (not reproducible, already fixed, bad
  premise), say so and ask before closing.

---

## WordPress Plugin Specifics

Any PR that changes user-facing behaviour must also update, in the same PR:

- `readme.txt` — `== Changelog ==` entry under the new version
- **Do not bump the plugin header `Version:` or the version constant on your own.**
  Version bumps need my explicit OK, every time. If a PR needs one, write the
  changelog entry against the intended version, say so in the PR body, and ask.
  Unreleased plugins stay at `1.0.0`.
- `Stable tag` only when we're actually releasing (ask me)
- `Tested up to:` if I've verified against a newer WP

Coding standards for this repo:

- **WordPress Coding Standards (WPCS)** for PHP — *not* the ET/Divi `et-phpcs`
  ruleset, which is only for Divi/Elegant Themes products
- Run `composer lint` before opening the PR
- All output escaped (`esc_html`, `esc_attr`, `wp_kses_post`), all input
  sanitized, nonces + capability checks on every write path
- Prefix every global function, class, hook, option, and transient
- Never break backward compat on public hooks/filters without flagging it in the
  PR body under a `## Breaking` heading

---

## Hard Stops

Stop and ask me before:

- Force-pushing anything (`--force`, `--force-with-lease`)
- Rewriting history on a branch that already has a PR
- Pushing directly to `main`
- Deleting branches you didn't create
- Committing anything that looks like a credential, API key, or license key
- Merging a PR that touches the hard-stop surfaces listed in Merge policy A

---

## Final Message Format

End every task with:

```
Branch:  feat/142-instant-indexing (pushed)
PR:      https://github.com/plugpressco/saddle/pull/57
CI:      green
Issue:   #142 — will auto-close on merge
Status:  merged / awaiting your review / blocked on X
```

If any line above would say "not pushed" or "no PR", go back and finish it.
