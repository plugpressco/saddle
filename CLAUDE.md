# Saddle — agent guide

A **self-hosted WordPress MCP server**. An AI agent connects to the owner's own
WordPress and gets a tiered, approval-gated toolset: CRUD over posts, pages and
media; builder-agnostic block authoring; and a closed design-quality loop
(lint → render → preview → verify).

It is deliberately **not** a chat UI, not a screenshot engine, and not a hosted
relay. The buyer is a developer or agency already driving WordPress from Claude
Code or Codex; Saddle's job is to make the page *judgeable*, not to do the
judging.

Free Saddle is **1.0.0**, WordPress.org submission pending. **Saddle Pro** is a
separate plugin — the Divi 5 layer, sold commercially. Free never contains
license, upsell or builder-specific code.

Requires WordPress **6.9**, PHP **7.4**. Text domain `saddle`.

**Provenance:** rewritten clean on 2026-08-11, re-derived 2026-08-12 against the
tree. Everything here describes the code as it stands, not a plan. If the code
and this file disagree, **the code wins** — re-derive and say so to Fahim rather
than silently deviating.

---

## The three non-negotiables

Derived by reverse-engineering three competitors (Vibe AI, Novamira, AI Engine)
and finding each fails at least one. Check every change against all three.

1. **No third-party custody.** No site data, content, credentials or tool-call
   traffic ever leaves the owner's WordPress for a server we control. No relay,
   no proxy. If you're about to add `wp_remote_post`/`wp_remote_get` to an
   external host for anything other than a resource the user explicitly asked
   for, stop and ask.

   *One carve-out, added 2026-08-12 and deliberately narrow:* the **self-hosted
   build** checks for its own updates — plugin slug and installed version only,
   to one fixed endpoint, at most once every six hours, admin/cron only. No site
   URL, no user data, no telemetry. The **WordPress.org build makes no outbound
   request at all**, because the updater file isn't in that zip. Any wording
   anywhere — readme, docs, marketing — must state it this precisely rather than
   the old absolute, or it is simply false. *The public copy has not caught up
   yet; that is an open task, not a settled position.*

2. **Default-safe, not opt-out-unsafe.** New installs default to the `read` tier.
   Never change this default. Power is something the owner turns on, never
   something they have to turn off.

3. **No destructive action without a two-step confirm.** Any ability that mutates
   more than one row, or deletes/overwrites without recovery, goes through
   `Saddle_Approval::gate()` — dry-run preview, then a single-use, target-bound,
   15-minute token. No exceptions.

---

## Hard line: Saddle does not execute code, and does not write files

The sharpest edge of the product, reaffirmed 2026-08-11 after auditing Novamira
— whose free plugin ships `execute-php`, `run-wp-cli`, `write-file`, `edit-file`
and `delete-file` behind a single `current_user_can` check, with no tiers, no
dry-run, no diff and no revert.

**In this codebase, indefinitely:**

- No `eval()`, `exec()`, `shell_exec()`, `proc_open()`, `passthru()`, `system()`.
  Grep for all six before every release; the tree is currently clean.
- No WP-CLI passthrough, no shelling out, no arbitrary SQL.
- **No filesystem writes.** The only `fwrite` in the tree is inside the vendored
  MCP adapter's stdio bridge. Saddle writes to the database, never to disk.

If a feature seems to need any of these, that is a signal to redesign the
feature. Two reasons, both load-bearing: WordPress.org will not accept arbitrary
code execution or unrestricted file writes in a directory plugin (Novamira is on
GitHub under AGPL, not in the repository — that isn't a coincidence), and "the
agent can see the diff before it lands, and you can undo it" is the entire reason
to pick Saddle over the alternatives.

If code-writing is ever built, it is a **separate, clearly-labeled addon** with a
typed surface — never a raw `eval`, and never silently present in free. See
`ROADMAP.md`.

---

## Commands

```bash
composer test        # PHPUnit — SQLite-backed, against a real WordPress
composer lint        # phpcs (WPCS)
composer lint:fix    # phpcbf
npm run build        # admin/src -> admin/build; the committed bundle must match
npm run lint:js
npx grunt build      # zip. See "Release" — do NOT use `npm run package` casually
```

WP Playground (`wp-playground` with `--auto-mount`) is the fastest way to verify
a fix on a clean install, including host-specific failures reproduced with a
small mu-plugin.

---

## The four pillars

What a connected agent gets, and where each part of it lives. A new feature
belongs *in* a pillar; if it lands beside one, that is the smell.

**1. Context — what the agent knows before it acts.** One ordered document,
assembled by `Saddle_Context::system_context()` and served identically on the
`initialize` handshake and by `saddle/get-instructions`. Sections are ordered and
tier-aware: identity, what this credential may do, how to work here, the site's
design memory, the content landscape, editing notes, skills, memory,
integrations, recent changes, the refusal playbook, owner instructions last.
Anything appended must carry its own heading at the same level and stay
budgeted — the whole thing is paid on every session, on every connected site.
Addons extend it through `saddle_system_context`.

**2. Guardrails — what the agent cannot do.** `Saddle_Capabilities` (tiers, the
pause switch, per-tool toggles, and the single source of every
`permission_callback`) and `Saddle_Approval` (dry-run preview → single-use token
bound to action, target and `bind`). Two rules that are easy to get wrong:
a destructive call must pass `bind` covering every argument the preview showed,
not just the target; and a refusal must name the gate that caused it, because
`denial_reason()` is the only thing standing between an agent and a retry loop.

**3. Tools — what the agent is offered.** Every ability declares `accessLevel`,
`destructive` and a tier via `saddle_ability_meta()` — free, Pro and the wrapped
integrations alike, which is what lets one resolver reason about all ~160 of
them. `tools/list` is filtered at **dispatch** to `is_callable_now()`; never at
registration, which happens before authentication. What filtering hides, the
context must say in prose.

**4. The system — how the agent works.** `Saddle_Playbook` bundles the free
playbooks (`build-page`, `fix-page`), `Saddle_Skills` holds the owner's, and
`Saddle_Memory` is what carries across sessions. Progressive disclosure
throughout: only the index rides the context, bodies come from
`saddle/get-skill`. Addons bundle their own through `saddle_builtin_skills` and
shadow a free one by using its name.

The seams, in one place: `saddle_system_context`, `saddle_builtin_skills`,
`saddle_ability_meta()`, `saddle_native_builders`, `mcp_adapter_tools_list`.

---

## Architecture

Roles, not a file listing — the tree moves and a copied listing goes stale. Read
`includes/` directly for the current shape.

**The engine.** `Saddle_Tree` is the builder-agnostic block-tree engine
(parse / address / mutate / serialize); the `Saddle_Blocks_*` classes layer the
Gutenberg validation profile, the authoring surface, schema/tokens, and the
applied-vs-ignored echo on top of it.

**The quality loop.** `lint/` holds the design lint runner plus ~12 rules, and
`Saddle_Lint_Accessor` is the *only* builder-specific surface — Pro plugs Divi in
and adds its own rules through the `saddle_lint_rules` filter. `verify/` scores
the result, and the grades are **honest by design**: a structural finding caps
the letter at C, an echo finding at B, and every report carries a `coverage`
caveat, because it is server-side only and real pixels need `get-preview-url`.

**The safety model.** `Saddle_Capabilities` owns the read/write/admin tiers and
is the single source of truth for every `permission_callback`. `Saddle_Approval`
is the dry-run + confirm-token gate. `Saddle_Log` records executed mutations only
— reads stay silent.

**Context and extensibility.** `Saddle_Context` builds the auto system context
and owner instructions; its `design_numbers()` is the **single** source of the
shared design bar (Pro's skill embeds it verbatim — edit the numbers here only).
`Saddle_Integrations` wraps first-party abilities from sibling plugins (e.g.
`waggle/*` as `saddle/waggle-*`) with the full safety model applied on top.
`Saddle_Skills` and `Saddle_Memory` are owner-installed playbooks and agent
memory, both CPT-backed.

**Transport.** `Saddle_MCP` runs on the official `WP\MCP` adapter vendored under
`includes/lib/wp-mcp/`, with a JSON-RPC fallback. **That directory is vendored —
not a style reference, and never hand-edited.**

**The addon seam.** `admin/src/extensions.js` is a **public contract** (shell
v1): two `wp.hooks` filters — `saddle.admin.settingsCards` and
`saddle.admin.tabs` — each passed a `ui` object of already-bundled
`@plugpress/ui` primitives so addon bundles ship none of their own. Additive
changes only; anything else bumps `SHELL_VERSION` there **and**
`SADDLE_SHELL_VERSION` in `saddle.php`.

Two things worth knowing before you go looking:

- `includes/class-saddle-ecosystem.php` exists and is **intentionally never
  loaded**. It is dead code on purpose — don't wire it up.
- `class-saddle-updater.php` is **not in the .org zip**. The build drops it and
  the `require` in `saddle.php` degrades to a no-op, so its *absence* is what
  makes a build .org-safe.

For the live ability list, trust the **Permissions screen in wp-admin** over any
count written down.

---

## Auth model

Two paths, both ending in a resolved WordPress user, both funnelling into the
same per-tool gate (`Saddle_Capabilities`). **Adding a third needs a written
reason.**

**1. Core Application Passwords — the default.** The MCP REST route requires only
`is_user_logged_in()`; core resolves Basic-Auth application passwords into the
current user. The connect wizard mints the credential via
`WP_Application_Passwords::create_new_application_password()`
(`POST /saddle/v1/clients`, `manage_options`-gated). This is the path for every
client that lets a person paste an HTTP header: Claude Code, Claude, Cursor,
VS Code, Gemini CLI.

**2. A self-hosted OAuth 2.1 authorization server — opt-in, default OFF**
(`includes/oauth/`). The written reason core's flow can't satisfy: **ChatGPT's
custom-connector screen has no field for a custom HTTP header.** It offers no
auth, an API key, or OAuth — none of which carries `Authorization: Basic`.

The server lives entirely inside the owner's WordPress. A granted scope only ever
*lowers* the site tier, never raises it: `get_tier()` returns
`min(site tier, granted scope)`. Use `get_site_tier()` when reporting or writing
configuration, `get_tier()` when deciding whether a call is allowed.

Constraints that must not be relaxed: PKCE **S256 only** (never `plain`),
redirect URIs matched by **exact string comparison** (never prefix), refresh
tokens rotate with reuse detection, authorization-code replay revokes the whole
grant, and dynamic registration grants nothing until an administrator completes
consent.

---

## Conventions

Applies to `includes/**/*.php` and `admin/src/**`. The shared WordPress rules are
in the managed block below; these are the Saddle-specific additions.

- Every ability declares explicit `accessLevel` (`read`/`write`/`admin`) and
  `destructive` (`true`/`false`). **Don't infer the tier from the function body.**
- Ability `description` fields are read by the agent to decide when to call the
  tool. Write them like documentation, not code comments — that text *is* the
  interface.
- Outbound fetches go through `Saddle_HTTP`, which carries the SSRF guard. Don't
  hand-roll a second `wp_remote_get`.
- The internal CPTs — `saddle_log`, `saddle_skill`, `saddle_memory`,
  `saddle_oauth` — stay `public => false`, out of search and out of REST.
- **Skill bodies are Markdown-as-DATA.** `sanitize_body()` keeps them
  byte-identical (UTF-8 and control-char strip only). Never run a skill body
  through `wp_kses` or `esc_html`: angle-bracket placeholders like `<id>` are
  instruction text the agent must receive verbatim.
- Regenerate `languages/saddle.pot` whenever strings change. It has gone stale
  before and shipped missing an entire feature's worth of msgids.
- React UI: **`@plugpress/ui`** (git tag pinned in `package.json`) is the kit.
  No `@wordpress/components`, no Tailwind, no second kit. Product-specific pieces
  (BrandMark, LevelIcon, the Permissions lanes, the activity timeline) stay
  in-plugin, styled on `--pp-*` tokens. Light-only. The brand mark is
  single-sourced from `assets/brand/mark.svg`. Read `admin/DESIGN-ALIGNMENT.md`
  before writing admin CSS.
  - npm trap: changing the git ref in `package.json` and running `npm install`
    does **not** re-resolve it. `rm -rf node_modules/@plugpress/ui` and install
    the tag explicitly.

---

## Testing

PHPUnit, SQLite-backed, running against a real WordPress — 37 test files in
`tests/`. Setup is in `tests/README.md`.

**What a new ability needs, every time:**

- The happy path.
- A tier test: the ability is refused below its declared `accessLevel`.
- If `destructive`, a gate test: no token returns a preview and mutates nothing;
  a valid token executes exactly once; a reused token fails.
- A contract test if it has a stable output shape other code depends on.

**How to write them:**

- Drive the real code path. A test that re-implements the logic it is checking
  passes forever and proves nothing — this has bitten this repo before.
- When you find a bug, pin the *actual* behaviour with a regression test before
  fixing it, and leave the test naming the symptom.
- Third-party or core behaviour we depend on gets pinned too, so a core update
  surfaces as a red test rather than a customer email.

**Before any release, verified in a real install — not just in the UI:**

- [ ] Fresh install defaults to the `read` tier (check the DB row)
- [ ] A destructive call without `confirm_token` returns a preview and mutates nothing
- [ ] A destructive call with a valid, unused token executes exactly once
- [ ] `delete-post` / `delete-page` without `force` trashes, never permanently deletes
- [ ] The **wporg** zip contains no `class-saddle-updater.php` and makes no
      outbound request at all (verify in the zip, not in the source tree)
- [ ] Connect flow round-trips and revoke actually invalidates the credential
- [ ] Grep for the six execution functions — zero hits outside the vendored adapter
- [ ] Plugin Check runs clean **against the built zip**, not the dev tree

Two Plugin Check traps worth remembering: scanning the symlinked dev tree reports
files that never ship, and staging the zip under any folder name other than
`saddle` forces a text-domain mismatch on every string in the plugin.

---

## Release

- **No public version above 1.0.0 until Fahim confirms WordPress.org approval.**
  The short-lived 1.1.0 release was reversed on 2026-08-03.
- **Interim builds use a release-candidate suffix: `1.0.0-rc1`, `-rc2`, …** Never
  ship two different zips under one number — three distinct builds were all
  called 1.0.0 at once, and "I'm on 1.0.0" stopped carrying information.
  `version_compare` sorts `1.0.0-rc2` **below** `1.0.0`, so every rc install
  upgrades cleanly onto the real release. `Stable tag` never moves for an rc.
- Never run `grunt release` / `npm run package` casually — they bump the version.
  Build with `npm run build && npx grunt build`.

### Two build channels

| Command | Zip | Updater | Use |
|---|---|---|---|
| `grunt build` | `saddle-<v>.zip` | **excluded** | WordPress.org. Safe by default |
| `grunt build --channel=selfhosted` | `saddle-<v>-selfhosted.zip` | included | plugpress.co, customer test builds |

`clean:dist` wipes `dist/` on every run, so the two zips cannot coexist — build
and upload one at a time. Submitting a `-selfhosted` zip to WordPress.org is an
instant rejection; the filename suffix exists to make that mistake visible.

Self-hosted releases publish to R2 through the shared worker
(`plugin-update-workers`):
`./scripts/release.sh saddle <version> <zip> <manifest>`. The permanent download
link is `https://updates.plugpress.co/v1/latest?slug=saddle` — every
`/v1/update` package URL is a 7-day HMAC token, so nothing else is safe to link
to from a site or an email.

The version lives in five places and they must agree: the plugin header,
`SADDLE_VERSION`, `readme.txt` stable tag, `package.json`, `package-lock.json`.
Any change to user-facing behaviour updates `readme.txt`'s changelog in the same
change. `Tested up to:` moves only when Fahim has verified against a newer WP.

---

## What NOT to do, even if it looks like a small improvement

- Don't add a "quick mode" that skips the approval gate for trusted users. A real
  safety mechanism defaulted-out under friction pressure is what broke trust in
  all three competitors that were checked.
- Don't add PHP execution, filesystem writes or shell WP-CLI as a power-user
  convenience. See the hard line.
- Don't change the default tier away from `read`, even temporarily for testing.
- Don't remove the Application Password path now that OAuth exists. Every
  header-capable client uses it, it needs no consent round-trip, and it is the
  only path that works while the OAuth toggle is off — which is the default.
- Don't turn OAuth on by default, and don't let a scope grant more than the site
  tier. Both are load-bearing for non-negotiable #2.
- Don't wire up `Saddle_Ecosystem`.
- Don't put licensing, upsell or builder-specific code in free.
- Don't edit `includes/lib/wp-mcp/` — it is vendored. Fix upstream and re-vendor.

---

## Where state lives

- **`STATUS.md`** — read at the start of a session ("Last session" is what
  happened, "Next up" is what's queued) and update at the end. It is the session
  log; this file is the durable rules.
- **`ROADMAP.md`** — direction and the ordered next moves.
- **GitHub Issues + project #3** — the backlog. Not chat, not a local file.
- **`admin/DESIGN-ALIGNMENT.md`** — read before writing admin CSS.
- **`tests/README.md`** — how the SQLite-backed suite runs.
- **`WPORG-SUBMISSION.md`** — the submission checklist.
- **`.claude/skills/wp-security-rules/`** — Saddle's tier/gate/escaping judgments
  that phpcs cannot make. Load it when writing request-handling PHP.

<!-- BEGIN plugpress:architecture (managed by fleet:blocks) -->
## Architecture rules

- Directory boundaries: the admin layer may call the core/domain layer. The core
  layer must never call the admin or REST layer. No REST controller renders HTML.
- Before writing a new helper, grep this plugin's shared helper location and
  `plugpress-sdk`. If something within 80% of what you need exists, extend it —
  don't add a variant.
- Every hook callback is a thin wrapper. Business logic goes in a class method
  that is callable and testable without WordPress loaded.
- No new top-level class without a stated reason it isn't a method on an existing
  one.
- No feature flags, config options, or abstraction layers for hypothetical future
  needs. Build the concrete case.
- Max ~300 lines per class. Hitting it is a signal to split, not to reformat.
<!-- END plugpress:architecture -->

<!-- BEGIN plugpress:security (managed by fleet:blocks) -->
## WordPress plugin rules

The baseline every PHP file is held to. None of it is optional, and a reviewer at
WordPress.org will check most of it.

**Security**

- `defined( 'ABSPATH' ) || exit;` at the top of every PHP file. `uninstall.php`
  guards on `WP_UNINSTALL_PLUGIN` instead.
- **Sanitize on the way in, escape on the way out** — always both, never one as an
  excuse for the other. `sanitize_text_field`, `absint`, `sanitize_key`,
  `wp_kses_post` in; `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` out.
  Escape at the point of output, not in a variable three functions earlier.
- Every SQL query goes through `$wpdb->prepare()`. Never interpolate a variable into
  a query string, including table-name fragments built from input.
- **Capability checks, never role checks.** `current_user_can( 'edit_posts' )`, not
  `in_array( 'editor', $user->roles )`.
- Nonces on every admin-initiated write (`wp_verify_nonce` / `check_admin_referer`).
- Every REST route declares a real `permission_callback`. `__return_true` is only
  acceptable on a genuinely public endpoint, and then it needs a comment saying why.
- No credentials, keys or tokens in the repo, in logs, or in an error message.

**Naming and isolation**

- Prefix everything: classes, functions, hooks, options, transients, constants and
  asset handles. No bare global anything.
- Never assume another plugin or theme is present. Guard with `class_exists()` /
  `function_exists()` at every consumption site, not once at boot — a guard that is
  always true is worse than none, because it hides a mistake for weeks.
- Don't break backward compat on a public hook or filter without flagging it
  explicitly in the PR body under a `## Breaking` heading.

**Data**

- Options: no unbounded growth, and think about autoload. Anything that accumulates
  (logs, tokens, codes) needs a bounded GC sweep.
- CPTs used as internal storage stay `public => false`, out of search and out of REST.
- Transients for cached remote reads, with a sane TTL. Never cache a permission
  decision.
- Activation seeds the safe default. Deactivation cleans up scheduled events.
  `uninstall.php` removes what the plugin created — and nothing it didn't.

**i18n**

- Every user-facing string goes through `__()` / `esc_html__()` / `_n()` with the
  plugin's text domain. No exceptions, including error messages.
- Never build a sentence by concatenating translated fragments. Use placeholders and
  `sprintf`, so translators see the whole sentence.
- Regenerate the `.pot` whenever strings change. It has gone stale before and
  shipped missing an entire feature's worth of msgids.

**Performance and hygiene**

- Admin assets enqueue only on the plugin's own screen, with an explicit dependency
  array and version. Nothing global.
- No queries in a loop, no `posts_per_page => -1` on a user-facing path.
- No `error_log()`, `var_dump()`, or commented-out dead code in a commit.
- Dev files never ship: `tests/`, `dist/`, configs, `.github`, root `*.md` and every
  dot-file are excluded from the zip. Verify in the built zip, don't assume.

**Coding standards**

- **WordPress Coding Standards (WPCS)** for PHP — *not* the ET/Divi `et-phpcs`
  ruleset, which is only for Divi/Elegant Themes products. Tabs, not spaces.
<!-- END plugpress:security -->

<!-- BEGIN plugpress:plugpress-ui (managed by fleet:blocks) -->
## @plugpress/ui

This plugin's admin UI is built on the PlugPress design system. Before building or editing any
admin UI, read the usage guide shipped with the package:

    node_modules/@plugpress/ui/docs/consumer-agent-guide.md

It covers setup, the design rules you must follow, the component inventory, and why UI changes
don't appear until the pinned tag is bumped and the plugin is rebuilt.

**Never edit the library from a plugin task.** If a component needs a change, that is a separate
issue in `plugpressco/plugpress-ui` — editing `node_modules/` fixes nothing and is lost on the
next install. Read the guide from the installed package rather than copying it into this repo:
a forked copy goes stale against a pinned tag and starts telling agents the wrong export surface.
<!-- END plugpress:plugpress-ui -->

<!-- BEGIN plugpress:session (managed by fleet:blocks) -->
## Session workflow

This repo is tracked on the [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3) org board (plugpressco, project #3).

- **Start of session:** read `STATUS.md` — "Last session" says what happened, "Next up" says what's queued.
- **During the session:** keep the board honest — move cards across Status (Todo → In Progress → Done), and set Tier (`build` / `slow-burn` / `maintain`) and Work Type (`bug` / `feature` / `support` / `marketing` / `interrupt`) on anything new.
- **End of session:** update `STATUS.md` — replace "Last session" with what actually happened this session, and refresh "Next up" for whoever (or whatever) picks this up next.
<!-- END plugpress:session -->

<!-- BEGIN plugpress:workflow (managed by fleet:blocks) -->
## GitHub workflow

Tracked on the **PlugPress HQ** org board, project #3
(`https://github.com/orgs/plugpressco/projects/3`). One board for everything; don't
spin up a second.

Every feature, bug or backlog idea that comes up in conversation becomes a GitHub
issue — not a note in chat, and **not a local `TASKS.md`** (that backlog was retired
on 2026-07-06; don't recreate it).

### Definition of done

A task is not done when the code works. It is done when all of these are true:

1. Every change is committed — no dirty tree, no untracked source files
2. The branch is **pushed to `origin`**
3. A PR exists, linked to the issue with a closing keyword
4. Checks are green, or the failure is reported explicitly
5. The merge policy below has been followed

**Never end a turn with unpushed commits or uncommitted changes.** If you stop
mid-task for any reason, commit and push what exists first, then say where you
stopped. If a push or PR step fails — auth, protected branch, no remote, network —
say so in the final message with the exact error. Don't stop silently, and don't
imply the work shipped.

### The loop

**1. Pick up the issue.** If it's vague or has no acceptance criteria, ask before
writing code.

```bash
gh issue view <N>
gh issue edit <N> --add-label "in-progress"
```

**2. Branch from an up-to-date `main`,** via `gh issue develop` so GitHub links it:

```bash
git checkout main && git pull --ff-only origin main
gh issue develop <N> --base main --name feat/<N>-short-slug --checkout
```

| Type | Prefix | Example |
|---|---|---|
| Feature | `feat/` | `feat/142-site-editor-reads` |
| Bug fix | `fix/` | `fix/151-nonce-stripped` |
| Refactor | `refactor/` | `refactor/160-lint-accessor` |
| Docs | `docs/` | `docs/163-readme-changelog` |
| Chore / deps | `chore/` | `chore/165-bump-wp-tested` |

**3. Commit at each logical checkpoint** — not one giant commit at the end.
Conventional Commits, one concern per commit, subject ≤ 72 chars, imperative mood:

```
feat(blocks): add list-templates and get-template abilities

Refs #142
```

Never commit `vendor/`, `node_modules/`, build output, `.env`, local WP config or
`.DS_Store` — if they appear, fix `.gitignore` in the same PR. Never commit
commented-out dead code or debug `error_log()` / `var_dump()`.

**4. Push after the first commit,** then after each one — the branch should be
visible on GitHub at any moment.

```bash
git push -u origin HEAD
```

**5. Open the PR as soon as the branch is pushed.** Draft is fine while work
continues; `gh pr ready <N>` when it's done.

```bash
gh pr create --base main --draft --title "feat: site-editor reads (#142)" --body-file .git/PR_BODY.md
```

```markdown
Closes #142

## What
One paragraph, plain language.

## Why
The problem from the issue.

## How
- Key decisions
- Anything non-obvious a future reader would trip on

## Testing
- [ ] The repo's own build / lint / test commands run clean (name them here)
- [ ] Verified in a real install, not just in a story or a unit test
- [ ] No notices or warnings with `WP_DEBUG` on

## Screenshots
(admin UI changes only)
```

`Closes #N` is mandatory — it auto-closes the issue and keeps the board honest. Use
`Refs #N` only when the PR genuinely doesn't finish the issue.

**6. Verify before merge.** This repo has **no CI workflows**, so `gh pr checks` reports nothing. "Green" means you ran the repo's own build, lint and test commands locally and said so in the PR body. Do not claim CI passed when there is no CI.

**7. Merge — solo fast mode.** Once checks are green and the description is
complete, merge it yourself:

```bash
gh pr merge --squash --delete-branch
git checkout main && git pull --ff-only origin main
```

**Stop and ask Fahim first** if the PR touches licensing/activation, payments or
Freemius, database schema or migrations, uninstall routines, anything that ships to
WordPress.org, a version bump, or more than ~400 changed lines.

**8. Close the loop.** Remove the `in-progress` label, confirm the issue closed, move
the card to Done on project #3, and set its Tier (`build` / `slow-burn` / `maintain`)
and Work Type (`bug` / `feature` / `support` / `marketing` / `interrupt`).

### Issue hygiene

- **One issue = one PR = one branch.** Unrelated work found mid-task becomes a new
  issue (`gh issue create`), not scope creep.
- Post a short progress comment when a task spans sessions: what's done, what's left,
  branch name.
- If an issue turns out to be wrong — not reproducible, already fixed, bad premise —
  say so and ask before closing.

### Version bumps

**Do not bump the plugin header `Version:`, the version constant, or a published
package tag on your own.** Version bumps need Fahim's explicit OK, every time. If a
PR needs one, write the changelog entry against the intended version, say so in the
PR body, and ask. Unreleased plugins stay at `1.0.0`.

### Hard stops — ask before

- Force-pushing anything (`--force`, `--force-with-lease`)
- Rewriting history on a branch that already has a PR
- Pushing directly to `main`
- Deleting branches you didn't create
- Committing anything that looks like a credential, API key or license key
- Merging a PR that touches the ask-first surfaces above

### Final message format

```
Branch:  feat/142-site-editor-reads (pushed)
PR:      https://github.com/plugpressco/saddle/pull/57
Checks:  green
Issue:   #142 — will auto-close on merge
Status:  merged / awaiting your review / blocked on X
```

If any line would say "not pushed" or "no PR", go back and finish it.
<!-- END plugpress:workflow -->
