# CLAUDE.md — Saddle

Rewritten clean on **2026-08-11**. Everything here describes the tree as it stands
today, not a plan. It encodes decisions already made — follow them, don't re-derive
them mid-session. If something here looks wrong, say so to Fahim explicitly rather
than silently deviating.

---

## What Saddle is

A **self-hosted WordPress MCP server**. An AI agent connects to the owner's own
WordPress and gets a tiered, approval-gated toolset: CRUD over posts, pages and
media; builder-agnostic block authoring; and a closed design-quality loop
(lint → render → preview → verify).

Free Saddle is **1.0.0**, WP.org submission pending. **Saddle Pro** is a separate
plugin: the Divi 5 layer, sold commercially. Free never contains license, upsell or
builder-specific code.

- Requires WordPress **6.9**, PHP **7.4**. Text domain `saddle`.
- 66 abilities registered in free (see the map below). The Permissions screen in
  wp-admin is the authoritative live list — trust it over any count written down.

---

## The three non-negotiables

Derived by reverse-engineering three competitors (Vibe AI, Novamira, AI Engine) and
finding each fails at least one. Check every change against all three.

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
   the old absolute, or it is simply false.
2. **Default-safe, not opt-out-unsafe.** New installs default to the `read` tier.
   Never change this default. Power is something the owner turns on, never something
   they have to turn off.
3. **No destructive action without a two-step confirm.** Any ability that mutates
   more than one row, or deletes/overwrites without recovery, goes through
   `Saddle_Approval::gate()` — dry-run preview, then a single-use, target-bound,
   15-minute token. No exceptions.

---

## Hard line: Saddle does not execute code, and does not write files

This is the sharpest edge of the product, reaffirmed **2026-08-11** after auditing
Novamira (whose free plugin ships `execute-php`, `run-wp-cli`, `write-file`,
`edit-file`, `delete-file` behind a single `current_user_can` check — no tiers, no
dry-run, no diff, no revert).

**In this codebase, indefinitely:**

- No `eval()`, `exec()`, `shell_exec()`, `proc_open()`, `passthru()`, `system()`.
  Grep for all six before every release; the tree is currently clean.
- No WP-CLI passthrough, no shelling out, no arbitrary SQL.
- **No filesystem writes.** The only `fwrite` in the tree is inside the vendored MCP
  adapter's stdio bridge. Saddle writes to the database, never to disk.

If a feature seems to need any of these, that is a signal to redesign the feature.
Two reasons, both load-bearing: WordPress.org will not accept arbitrary code
execution or unrestricted file writes in a directory plugin (Novamira is on GitHub
under AGPL, not in the repository — that isn't a coincidence), and "the agent can
see the diff before it lands, and you can undo it" is the entire reason to pick
Saddle over the alternatives.

If code-writing is ever built, it is a **separate, clearly-labeled addon** with a
typed surface (write a stylesheet, write a template file) — never a raw `eval`, and
never silently present in free. See **Direction** below.

---

## Auth model

Two paths, both ending in a resolved WordPress user, both funnelling into the same
per-tool gate (`Saddle_Capabilities`). Adding a third needs a written reason.

**1. Core Application Passwords — the default.** `Saddle_MCP`'s REST route requires
only `is_user_logged_in()`; core resolves Basic-Auth application passwords into the
current user. The connect wizard mints the credential directly via
`WP_Application_Passwords::create_new_application_password()`
(`POST /saddle/v1/clients`, `manage_options`-gated). This is the path for every
client that lets a person paste an HTTP header: Claude Code, Claude, Cursor,
VS Code, Gemini CLI.

**2. A self-hosted OAuth 2.1 authorization server — opt-in, default OFF**
(`includes/oauth/`). The written reason core's flow can't satisfy: **ChatGPT's
custom-connector screen has no field for a custom HTTP header.** It offers no auth,
an API key, or OAuth — none of which carries `Authorization: Basic`.

The server lives entirely inside the owner's WordPress. A granted scope only ever
*lowers* the site tier, never raises it: `Saddle_Capabilities::get_tier()` returns
`min(site tier, granted scope)`. Use `get_site_tier()` when reporting or writing
configuration, `get_tier()` when deciding whether a call is allowed.

Constraints that must not be relaxed: PKCE **S256 only** (never `plain`), redirect
URIs matched by **exact string comparison** (never prefix), refresh tokens rotate
with reuse detection, authorization-code replay revokes the whole grant, and
dynamic registration grants nothing until an administrator completes consent.

---

## Architecture map (current tree)

```
saddle.php                     — bootstrap; wires hooks, defers MCP transport to plugins_loaded
                                 SADDLE_VERSION 1.0.0 · SADDLE_SHELL_VERSION 1
includes/                      — 60 PHP files (excluding the vendored adapter)
  class-saddle-tree.php        — builder-agnostic block-tree engine (parse/address/mutate/serialize)
  class-saddle-blocks-*.php    — Gutenberg validation profile, authoring layer, schema/tokens,
                                 applied-vs-ignored echo
  lint/                        — design lint engine: Saddle_Lint runner + Saddle_Lint_Accessor
                                 (the only builder-specific surface) + 12 rules; Pro plugs Divi in
                                 and adds its own via the saddle_lint_rules filter
  verify/                      — scored verify engine. Grades are HONEST: a structural finding caps
                                 the letter at C, an echo (ignored-styling) finding at B, and every
                                 report carries a `coverage` caveat — it is server-side only, real
                                 pixels need get-preview-url
  class-saddle-capabilities.php — read/write/admin tiers; single source of truth for permission_callback
  class-saddle-approval.php    — dry-run + confirm-token gate; single-use, 15-min TTL, target-bound
  class-saddle-log.php         — activity log (saddle_log CPT); executed mutations only, reads silent
  class-saddle-context.php     — auto system context + owner instructions (get-instructions).
                                 design_numbers() is the SINGLE source of the shared design bar
                                 (Pro's skill embeds it verbatim — edit the numbers here only);
                                 the saddle_native_builders filter lets a builder addon replace the
                                 hands-off warning with in-scope guidance
  class-saddle-integrations.php — first-party integration engine (wraps waggle/* as saddle/waggle-*,
                                 full safety model applied on top; saddle_integrations filter)
  class-saddle-recipes.php     — 6 token-free section recipes (hero, features, pricing,
                                 testimonials, cta, faq)
  class-saddle-skills.php      — skills store (saddle_skill CPT), owner-installed .md playbooks.
                                 Bodies are Markdown-as-DATA: sanitize_body() keeps them
                                 byte-identical (UTF-8/control-char strip only) — never run a skill
                                 body through wp_kses/esc_html; angle-bracket placeholders like
                                 <id> are instruction text agents must receive verbatim
  class-saddle-memory.php      — agent memory (saddle_memory CPT); trust split — agent entries are
                                 recall-only until owner-pinned, autoinject defaults OFF
  class-saddle-mcp.php         — MCP transport on the official WP\MCP Adapter, JSON-RPC fallback
  class-saddle-http.php        — shared SSRF guard + capped JSON fetch (media sideload, OAuth metadata)
  class-saddle-updater.php     — self-hosted update client. NOT IN THE .ORG ZIP: the build
                                 task drops it and the require in saddle.php degrades to a
                                 no-op, so its absence is what makes a build .org-safe.
                                 Sends slug + version only, 6-hourly, admin/cron only
  oauth/                       — 7 classes: discovery (RFC 9728/8414), registration (DCR + Client ID
                                 Metadata Documents), authorize/token/revoke, consent screen, bearer
                                 resolver that clamps the effective tier to the granted scope
  abilities/                   — 66 total: core-content 23 · blocks 15 · site 9 · site-editor 4
                                 context 4 · memory 3 · render 2 · unsplash 2 · users 2
                                 lint 1 · verify 1
  admin/                       — REST API + settings page for the React UI
  lib/wp-mcp/                  — vendored WP\MCP Adapter (do not edit)
admin/src/extensions.js        — the ADDON EXTENSION SEAM (shell v1). Two wp.hooks filters:
                                 saddle.admin.settingsCards (a Card in Settings) and
                                 saddle.admin.tabs (a whole page + nav entry, routed at #<id>),
                                 both passed a `ui` object of 16 already-bundled @plugpress/ui
                                 primitives so addon bundles ship none of their own. That object and
                                 both filter shapes are a PUBLIC CONTRACT — additive only; anything
                                 else bumps SHELL_VERSION here AND SADDLE_SHELL_VERSION in saddle.php.
                                 Addons enqueue on the `saddle_admin_enqueue` action with a dependency
                                 on the `saddle-admin` handle.
admin/src/                     — React UI: Onboarding, Home, Permissions, Guidance (+Memory),
                                 ConnectWizard, ConnectedClients — read admin/DESIGN-ALIGNMENT.md
                                 before writing CSS
tests/                         — 37 PHPUnit integration files (SQLite-backed, real WP); tests/README.md
```

`includes/class-saddle-ecosystem.php` exists and is **intentionally never loaded**.
It is dead code on purpose — don't wire it up.

---

## Architecture rules

- Directory boundaries: the admin layer may call the core/domain layer. The core
  layer must never call the admin or REST layer. No REST controller renders HTML.
- Before writing a new helper, grep this plugin and `plugpress-sdk`. If something
  within 80% of what you need exists, extend it — don't add a variant.
- Every hook callback is a thin wrapper. Business logic goes in a class method that
  is callable and testable without WordPress loaded.
- No new top-level class without a stated reason it isn't a method on an existing one.
- No feature flags, config options, or abstraction layers for hypothetical future
  needs. Build the concrete case.
- Max ~300 lines per class. Hitting it is a signal to split, not to reformat.

---

## WordPress plugin rules

The baseline every file in this plugin is held to. None of it is optional, and a
reviewer at WordPress.org will check most of it.

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
- Never trust a URL from input. Outbound fetches go through `Saddle_HTTP`, which
  carries the SSRF guard — don't hand-roll a second `wp_remote_get`.
- No credentials, keys or tokens in the repo, in logs, or in an error message.

**Naming and isolation**

- Prefix everything: `Saddle_` classes, `saddle_` functions/hooks/options/transients,
  `SADDLE_` constants, `saddle-` asset handles. No bare global anything.
- Never assume another plugin or theme is present. Guard with `class_exists()` /
  `function_exists()` / `interface_exists()` at every consumption site, not once at
  boot — a guard that is always true is worse than none, because it hides a mistake
  for weeks.
- Don't break backward compat on a public hook or filter without flagging it
  explicitly in the PR body under a `## Breaking` heading.

**Data**

- Options: no unbounded growth, and think about autoload. Anything that accumulates
  (logs, tokens, codes) needs a bounded GC sweep.
- CPTs used as internal storage (`saddle_log`, `saddle_skill`, `saddle_memory`,
  `saddle_oauth`) stay `public => false`, out of search and out of REST.
- Transients for cached remote reads, with a sane TTL. Never cache a permission
  decision.
- Activation seeds the safe default. Deactivation cleans up scheduled events.
  `uninstall.php` removes what the plugin created — and nothing it didn't.

**i18n**

- Every user-facing string goes through `__()` / `esc_html__()` / `_n()` with the
  `'saddle'` text domain. No exceptions, including error messages and ability
  descriptions.
- Never build a sentence by concatenating translated fragments. Use placeholders and
  `sprintf`, so translators see the whole sentence.
- Regenerate `languages/saddle.pot` whenever strings change. It has gone stale
  before and shipped missing an entire feature's worth of msgids.

**Performance and hygiene**

- Admin assets enqueue only on Saddle's own screen, with an explicit dependency
  array and version. Nothing global.
- No queries in a loop, no `posts_per_page => -1` on a user-facing path.
- No `error_log()`, `var_dump()`, or commented-out dead code in a commit.
- Dev files never ship: `tests/`, `dist/`, configs, `.github`, root `*.md` and every
  dot-file are Gruntfile-excluded from the zip. Verify, don't assume.

---

## Coding conventions

- **WordPress Coding Standards (WPCS)** for PHP — *not* the ET/Divi `et-phpcs`
  ruleset, which is only for Divi/Elegant Themes products. Tabs, not spaces.
- Every ability declares explicit `accessLevel` (`read`/`write`/`admin`) and
  `destructive` (`true`/`false`). Don't infer the tier from the function body.
- Ability `description` fields are read by the agent to decide when to call the tool.
  Write them like documentation, not code comments — that text *is* the interface.
- React UI: **`@plugpress/ui`** (git tag pinned in package.json) is the kit — import
  primitives directly from it. No `@wordpress/components`, no Tailwind, no second
  kit. Product-specific pieces (BrandMark, LevelIcon, AppLogo, the Permissions
  lanes/chips, the activity timeline) stay in-plugin, styled on `--pp-*` tokens.
  Light-only, no theme toggles. The brand mark is single-sourced from
  `assets/brand/mark.svg`.
  - npm trap: changing the git ref in `package.json` and running `npm install` does
    **not** re-resolve it. `rm -rf node_modules/@plugpress/ui` and install the tag
    explicitly.

---

## Testing

The suite is PHPUnit, SQLite-backed, running against a real WordPress — 37 test
files in `tests/`. Setup is documented in `tests/README.md`.

```
composer test      # PHPUnit
composer lint      # phpcs, WPCS
composer lint:fix  # phpcbf
npm run lint:js
npm run build      # the committed bundle must match a fresh build
```

**What a new ability needs, every time:**

- The happy path.
- A tier test: the ability is refused below its declared `accessLevel`.
- If `destructive`, a gate test: no token returns a preview and mutates nothing; a
  valid token executes exactly once; a reused token fails.
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
      outbound request at all (`grunt build` excludes it by default — verify in
      the zip, not in the source tree)
- [ ] Connect flow round-trips and revoke actually invalidates the credential
- [ ] Grep for the six execution functions — zero hits outside the vendored adapter
- [ ] Plugin Check runs clean **against the built zip**, not the dev tree

Two Plugin Check traps worth remembering: scanning the symlinked dev tree reports
files that never ship, and staging the zip under any folder name other than `saddle`
forces a text-domain mismatch on every string in the plugin.

WP Playground (`wp-playground` with `--auto-mount`) is the fastest way to verify a
fix on a clean install, including host-specific failures reproduced with a small
mu-plugin.

---

## Version and release rules

- **No public version above 1.0.0 until Fahim confirms WP.org approval.** The
  short-lived 1.1.0 release was reversed on 2026-08-03. Do not bump past 1.0.0
  without his explicit OK, every time.
- **Interim builds use a release-candidate suffix: `1.0.0-rc1`, `-rc2`, …** Never
  ship two different zips under one number — three distinct builds were all called
  1.0.0 at once, and "I'm on 1.0.0" stopped carrying information. `version_compare`
  sorts `1.0.0-rc2` **below** `1.0.0`, so every rc install upgrades cleanly onto the
  real release, including the WordPress.org one. `Stable tag` never moves for an rc
  (the `version` task refuses).
- Never run `grunt release` / `npm run package` casually — it bumps the version.
  Build with `npm run build && npx grunt build`.

### Two build channels

| Command | Zip | Updater | Use |
|---|---|---|---|
| `grunt build` | `saddle-<v>.zip` | **excluded** | WordPress.org. Safe by default |
| `grunt build --channel=selfhosted` | `saddle-<v>-selfhosted.zip` | included | plugpress.co, customer test builds |

`clean:dist` wipes `dist/` on every run, so the two zips cannot coexist — build and
upload one at a time. Submitting a `-selfhosted` zip to WordPress.org is an instant
rejection; the filename suffix exists to make that mistake visible.

Self-hosted releases publish to R2 through the shared worker
(`plugin-update-workers`): `./scripts/release.sh saddle <version> <zip> <manifest>`.
The permanent download link is `https://updates.plugpress.co/v1/latest?slug=saddle`
— every `/v1/update` package URL is a 7-day HMAC token, so nothing else is safe to
link to from a site or an email.
- Any change to user-facing behaviour updates `readme.txt`'s changelog in the same
  change, written against the intended version. `Stable tag` moves only on a real
  release. `Tested up to:` only when Fahim has verified against a newer WP.
- The version lives in five places and they must agree: the plugin header,
  `SADDLE_VERSION`, `readme.txt` stable tag, `package.json`, `package-lock.json`.

---

## GitHub workflow

Two repos — `plugpressco/saddle` (free) and `plugpressco/saddle-pro` — both tracked
on the **PlugPress HQ** org board, project #3
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
4. CI is green, or the failure is reported to Fahim explicitly
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
- [ ] `composer test` green
- [ ] `composer lint` + `npm run lint:js` clean
- [ ] Tested on WP 6.9+, PHP 8.2, WP_DEBUG on, no notices
- [ ] Free vs Pro gating still correct

## Screenshots
(admin UI changes only)
```

`Closes #N` is mandatory — it auto-closes the issue and keeps the board honest. Use
`Refs #N` only when the PR genuinely doesn't finish the issue.

**6. Verify before merge.** `gh pr checks --watch`. Never merge red — fix on the
same branch and push again.

**7. Merge — solo fast mode.** Once CI is green and the description is complete,
merge it yourself:

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
CI:      green
Issue:   #142 — will auto-close on merge
Status:  merged / awaiting your review / blocked on X
```

If any line would say "not pushed" or "no PR", go back and finish it.

---

## Direction — as of 2026-08-11

Replaces the old phase roadmap entirely. Nothing below is started; each item becomes
an issue when it's picked up.

**The gap that matters: on a block theme, Saddle can build a page but not a site.**
Verified against the tree — there are zero abilities for templates, template parts,
global styles, user patterns or fonts, and no reference anywhere in `includes/` to
`wp_template`, `wp_global_styles`, `wp_font_family` or `wp_block`. Worse,
`saddle/bootstrap-design-system` **silently no-ops on block themes**
(`includes/abilities/blocks.php`): it returns `applied: false` and tells the owner to
go do it by hand in Appearance → Editor → Styles. Only Divi, via Pro's filter, gets a
design system actually written. An agent on a block-theme site cannot see the header,
cannot set the palette, and cannot save what it built as a reusable pattern.

Ordered, and deliberately split by risk:

1. **Site-editor reads (free, DB-only).** `list-templates`, `get-template`,
   `list-template-parts`, `get-global-styles`, `list-user-patterns` (synced vs
   unsynced), `list-fonts`. Read tier. This is the missing half of
   `get-design-system` and unblocks everything else.
2. **Make `bootstrap-design-system` real on block themes (free, DB-only).** Write the
   spec into the `wp_global_styles` CPT via a `WP_Theme_JSON_Resolver::get_user_data()`
   merge. Already approval-gated. Highest value per line in this list — it closes a
   shipped no-op.
3. **Template / part / pattern writes (free, DB-only).** `set-template`,
   `create-template-part`, and "save this subtree as a pattern" built on
   `Saddle_Tree`. Approval-gated on overwrite.
4. **Filesystem export — a separate addon, never free.** The block-theme equivalent of
   Create Block Theme, agent-driven: plan → export → clean, moving templates, parts,
   global styles, patterns and fonts out of the database and into theme files so an
   agency can version-control them. Native PHP via `WP_Filesystem` — **not** a bash
   or WP-CLI wrapper (see the hard line above). The `clean` step is exactly what
   `Saddle_Approval::gate()` was built for.
5. **If code-writing ever happens, it is that same addon, and CSS first.** A child
   theme stylesheet or Additional CSS is non-executable, covers most "make it look
   right" work, and pairs directly with `get-design-system`. Data files
   (`templates/*.html`, `theme.json`) next. PHP templates only if demand proves it
   out. Every one of them goes through diff-preview → confirm → `saddle_log` →
   revertable, which is the whole differentiator.
6. **The Divi analogue belongs in Pro.** Theme Builder templates, Global Presets and
   Global Colors all live in the database with no version-control story. Pro already
   reads all three; a JSON export/import into a repo is the direct parallel.

**Constraint on all of the above:** nothing that adds a filesystem write ships in
free while the WP.org submission is in flight.

---

## What NOT to do, even if it looks like a small improvement

- Don't add a "quick mode" that skips the approval gate for trusted users. A real
  safety mechanism defaulted-out under friction pressure is what broke trust in all
  three competitors that were checked.
- Don't add PHP execution, filesystem writes or shell WP-CLI as a power-user
  convenience. See the hard line.
- Don't change the default tier away from `read`, even temporarily for testing.
- Don't remove the Application Password path now that OAuth exists. Every
  header-capable client uses it, it needs no consent round-trip, and it is the only
  path that works while the OAuth toggle is off — which is the default.
- Don't turn OAuth on by default, and don't let a scope grant more than the site
  tier. Both are load-bearing for non-negotiable #2.
- Don't wire up `Saddle_Ecosystem`.
- Don't put licensing, upsell or builder-specific code in free.

---

## Where state lives

- **`STATUS.md`** — read it at the start of a session ("Last session" is what
  happened, "Next up" is what's queued) and update it at the end. It is the session
  log; this file is the durable rules.
- **GitHub Issues + project #3** — the backlog. Not chat, not a local file.
- **`admin/DESIGN-ALIGNMENT.md`** — read before writing admin CSS.
- **`tests/README.md`** — how the SQLite-backed suite runs.
