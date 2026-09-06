# WordPress.org submission — checklist & reviewer notes

Internal doc (never shipped in the zip — excluded in Gruntfile.js). Last audit:
2026-08-27, responding to review round 2.

## Review history

### Round 1 — pended 2026-07-25

Review ID `AUTOPREREVIEW ❗TRM saddle/badhonrocks/25Jul26/T1 25Jul26/4.2A1
(P0TDX346076HGN)`. Largely automated ("humans, algorithms, and AI in varying
proportions"), so several items were pattern-match flags rather than findings.
Five flags; two were real. All addressed 2026-07-28 — see §8–§11 below for the
answers, which are the ones to reuse if round 2 asks again.

### Round 2 — pended 2026-08-24

Two flags. **One was real and is fixed** (per-object read authorization, §13);
**one is a false positive from the reviewer's URL checker** and needs an
explanation rather than a change (§14). Both answers below are written to be
reusable verbatim if a round 3 raises them again — the second one especially,
because nothing in this plugin can make it stop being flagged.

## Submitting

1. Upload `dist/saddle-1.0.0.zip` at https://wordpress.org/plugins/developers/add/
   (rebuild first if HEAD moved: `npm run build && npx grunt build` — never
   `grunt release`, which bumps the version).
2. Account: **badhonrocks** (readme `Contributors:` — confirm this is the
   submitting wp.org account before uploading).
3. After approval: SVN `assets/` gets the 4 screenshots listed in readme.txt
   plus banner/icon. Screenshots do NOT go in the zip.
   **Banner + icon are ready** in `.wordpress.org/` (icon.svg,
   icon-128/256 PNGs, banner-772x250 + banner-1544x500 — generated 2026-07-25
   from the disc brand mark). Only the screenshots remain to be captured —
   drop them in `.wordpress.org/` as `screenshot-N.png` and they ship with
   the next release automatically.
4. First public GitHub tag/release only AFTER wp.org approval (CLAUDE.md
   distribution rule).

### After approval: releases are automated (2026-09-07, #176)

Once the SVN repo exists, **every update is one tag.** `.github/workflows/release.yml`:

1. `grunt version:patch` (or `minor` / `major` / `--to=x.y.z`), commit, `git tag vX.Y.Z`,
   `git push --follow-tags`. Version bumps still need Fahim's OK (CLAUDE.md).
2. CI verifies tag = plugin header = `SADDLE_VERSION` = readme `Stable tag`, builds
   the .org-channel zip with the Gruntfile's exclusions, **proves the exclusions
   inside the artifact** (no updater, no `includes/lib`, no tests/docs/dev files,
   no debug calls, every PHP file parses), and publishes the GitHub Release.
3. On a real release (no `-rc`/`-beta` suffix) it then deploys `dist/saddle/` to
   SVN `trunk/` + `tags/X.Y.Z` and `.wordpress.org/` icons, banners and
   screenshots to `assets/`. A pre-release tag publishes the GitHub Release only
   and never touches SVN.

**One-time setup, after approval:** add repository secrets `SVN_USERNAME` and
`SVN_PASSWORD` for the wp.org account that owns the plugin (Settings → Secrets and
variables → Actions). Until they exist the SVN job fails with a named reason and
the GitHub Release still stands. Optional: Settings → Environments →
`wordpress-org` → add a required reviewer for a manual gate in front of SVN.

There is deliberately **no `.distignore`**: the Gruntfile is the only exclusion
list, so the two channels cannot drift apart.

## Pre-written answers for likely reviewer questions

### 1. `permission_callback => '__return_true'` on `/saddle/v1/auth-probe`

`includes/class-saddle-connection.php:366`. Intentional and safe:

- The callback (`rest_auth_probe`) returns **only booleans** describing which
  credentials the caller's own request managed to get as far as PHP. It never
  reads, validates, stores, or echoes a credential value.
- It exists so the connection self-check can detect header-stripping proxies
  (common on Apache CGI/LiteSpeed), which would otherwise surface as opaque
  401s for every connected agent.
- No information disclosure: the response is six booleans — `received`,
  `nonce_header`, `nonce_query`, `cookie`, `identified`, `custom_header` —
  every one derived from the current request only, so a caller learns nothing
  it did not already know about what it just sent. It deliberately does NOT
  report whether a nonce *verified*; that would make a public route a nonce
  oracle. The exact key set is pinned by a test
  (`tests/connection-test.php::test_auth_probe_returns_only_the_documented_booleans`)
  so a future key cannot be added carelessly.
- It also detects hosts that strip the `X-WP-Nonce` header — the failure that
  makes plugin settings screens 401 on some managed hosts (20i StackProtect
  and similar).

Every other route in the DEFAULT configuration is gated: admin REST routes
require `manage_options`; all MCP abilities run through
`Saddle_Capabilities::permission()` (tiered read/write/admin + per-tool
toggles + pause switch).

The optional OAuth 2.1 server adds further public routes — but only once an
administrator turns it on, and `Saddle_OAuth::register()` returns before any
`rest_api_init` hook when it is off, so on a default install they do not
exist. See §12 for each route and what protects it.

### 2. External services (guideline 6)

Four outbound `wp_remote_get` target classes, all disclosed in readme.txt
"External services" (two of the four are loopbacks to the site itself):

- `api.unsplash.com` / `images.unsplash.com` — only when the site owner
  supplies their own Unsplash Access Key; terms + privacy links in readme.
- Two **loopback** requests to the site's own host, neither leaving the
  server: the auth-probe above (connection self-check), and — only when the
  optional OAuth server is enabled — a probe of the site's own
  `/.well-known/oauth-authorization-server` to confirm discovery is reachable
  (`includes/oauth/class-saddle-oauth-discovery.php`, `probe_root()`).
- `upload-media` fetches a URL only when the connected agent explicitly
  provides one (disclosed in readme), behind the shared SSRF guard in
  `includes/class-saddle-http.php`.
- A client-supplied Client ID Metadata Document URL, fetched only when the
  optional OAuth server is enabled and an app identifies itself that way —
  HTTPS-only, zero redirects, 64 KB cap, 5s timeout, same SSRF guard.

No telemetry, no phoning home, no update checker, no external CDN assets.

### 3. Bundled libraries — none

**The zip contains no third-party library.** Every function, class, constant,
option, hook, CPT and asset handle it declares is prefixed `saddle` / `Saddle_`
/ `SADDLE_`.

This changed in response to the first review. Saddle previously bundled the
official WordPress **MCP Adapter** (`WP\MCP`) under `includes/lib/wp-mcp/` — 347
of 464 files, roughly half the zip, and the source of every `wp_mcp` and
`mcp_adapter` name in the prefix report. It is no longer shipped
(`Gruntfile.js`), together with the two files that existed only to serve it:
`includes/class-saddle-bundled-adapter.php` (which declared that library's own
`WP_MCP_*` constants) and `includes/class-saddle-mcp-compat.php`.

Nothing was lost. `Saddle_MCP` has always carried its own JSON-RPC transport on
the same `/wp-json/saddle/v1/mcp` route, exposing the same abilities behind the
same access tiers and approval gate; it is now the only transport in this build.
If a site separately installs the MCP Adapter plugin, Saddle detects the class
and uses it — that path is optional and guarded with `class_exists()`.

Two `mcp_adapter_*` strings remain in the source, at `saddle.php` and
`includes/class-saddle-mcp.php`. Both are `add_action`/`add_filter` calls
against **that plugin's own hooks** — the documented way to integrate with it.
They are names it owns, they cannot carry a Saddle prefix, and they only fire
when that plugin is present.

### 4. Third-party brand names/logos

- readme short description names Claude/Cursor descriptively (nominative use;
  they are the apps users connect).
- The connect wizard shows AI-app logos from `@lobehub/icons-static-svg`
  (MIT, GPL-compatible, credited in `admin/src/components/icons.jsx`).

### 5. Compiled JS source availability

`admin/build/` is compiled with `@wordpress/scripts`; the human-readable React
source ships in the zip at `admin/src/` (deliberate — see Gruntfile note).

### 6. Pro add-on

readme's `= Pro =` section describes Saddle Pro (separate Freemius add-on).
The free plugin is fully functional standalone (Gutenberg block editing
included); Pro only adds builder-native editing (Divi). There is zero
in-admin upsell UI and no locked/teaser features. The `admin_notices` hooks
in `class-saddle-settings.php` *suppress other plugins'* nags on Saddle's own
screens only — not a notice of ours.

### 7. Security posture (if asked broadly)

- Auth: WordPress core Application Passwords by default. A Saddle-issued
  credential is confined to Saddle's endpoint (cannot be used against the
  wider REST API or XML-RPC).
- Plus an OPTIONAL, default-OFF self-hosted OAuth 2.1 authorization server
  (`includes/oauth/`), added because ChatGPT's connector screen has no field
  for a custom HTTP header and therefore cannot use an Application Password
  at all. It runs entirely inside the owner's WordPress — no relay, no vendor
  host — administrators only, and a granted scope can only ever *lower* the
  site's access tier, never raise it. Full detail in §12.
- No `eval`/`exec`/`system`/`shell_exec`/`proc_open`. One `base64_decode`
  (RFC 7617 Basic-auth parsing, annotated).
- Destructive actions require a two-step confirm (single-use token, 15-min
  TTL) via `Saddle_Approval::gate()`.
- New installs default to the read-only tier.

### 8. Plugin name (round 1 blocker — fixed)

Round 1 required removing "WordPress" from the display name. The name is now
**"Saddle – Control Your Site with AI (MCP Server)"** — the reviewer's own
suggested alternative, adopted verbatim — in *both* `readme.txt:1` and the
`Plugin Name` header (they previously disagreed; the reviewer asked for both).

Descriptive uses of "WordPress" in body copy were deliberately left alone
(`saddle.php` Description, the version-requirement notice, readme prose, ~25
PHP/JSX strings). Only the *name* is restricted, not the prose.

The admin menu label stays the short `__( 'Saddle', 'saddle' )`
(`includes/admin/class-saddle-settings.php:26-35`) — a 46-character menu item
would be unusable.

**Slug: `saddle`, retained.** The round-1 mail only required the display-name
change; it did not require a new slug. Its AI flagged "Saddle" as a possible
trademark, but that is a false positive — `api.wordpress.org/plugins/info/1.2/
?action=query_plugins&request[search]=saddle` returns `"results":0`, and the
same AI called the name "otherwise distinctive". The reply states the intent to
keep the slug explicitly, since the mail warns that silence is not a request.
Keeping it also avoids renaming the text domain across ~930 translatable
strings (472 PHP + 369 JS call sites), `PAGE_SLUG`, and `ADAPTER_SERVER_ID`.

### 9. Plugin URLs (round 1 blocker — fixed)

Round 1 flagged `Plugin URI: https://plugpress.co/saddle` as a 404. Two more
URLs 404'd that the reviewer had not yet reached — both rendered as links in the
admin UI, so they would have caused a round 2:

| Was | Where | Now |
|---|---|---|
| `plugpress.co/saddle` | `saddle.php:4` Plugin URI | `https://plugpress.co` (200) |
| `plugpress.co/docs/saddle` | `class-saddle-settings.php` `docsUrl` | `https://wordpress.org/plugins/saddle/` |
| `plugpress.co/saddle/#reviews` | `class-saddle-settings.php` `rateUrl` | `https://wordpress.org/support/plugin/saddle/reviews/` |

Rationale:

- **Plugin URI → the site root, not the .org listing.** WP.org guidance is that
  Plugin URI should be the plugin's own home page, not its directory page.
  `https://plugpress.co` is live; the dedicated `/saddle` page is not built yet.
- **Docs + rate → WP.org.** For a free .org-hosted plugin, that is where docs
  and reviews genuinely live, and it removes a mild guideline-10 smell (a review
  CTA pointing at a vendor site rather than at .org).
- Both remain behind the existing `saddle_docs_url` / `saddle_rate_url` filters,
  so real first-party docs can be repointed later with no rebuild. Reuse those
  filters — do not add a settings field.

⚠️ Note for whoever checks these: until approval, `wordpress.org/plugins/saddle/`
is a **soft 200** — it silently serves a *search results* page rather than
404ing (`<title>Search Results for "saddle"</title>`). An HTTP-status checker
passes it, and both URLs become genuinely correct the moment the plugin is
approved. Don't "fix" them back on the basis of a browser check.

### 10. Guideline 10 — external links on the public site

Nothing the plugin author controls reaches the front end. There is **no**
`plugpress.co` link, no "powered by", no credit, and no front-end hook at all
(no `wp_head` / `wp_footer` / `the_content` / shortcode / widget anywhere). The
only front-end-reachable hooks are in `includes/preview/class-saddle-preview.php`
(`posts_results`, `template_redirect`), and both emit zero markup — they flip a
token-verified post's status in memory and set `X-Robots-Tag: noindex`.

The one external link the plugin can create is the Unsplash attribution caption
(`includes/class-saddle-unsplash.php:341-352`, written to `post_excerpt` at
`includes/abilities/unsplash.php:298-306`), which WordPress renders as the
image's visible caption:

- Required by the Unsplash API Terms, **including** the `utm_source=saddle`
  parameter (`utm_args()`) — their terms mandate a `utm_source` naming the
  consuming app. Removing it would breach the terms, not improve compliance.
- Links go to unsplash.com and the photographer, never to the author's site.
- Only exists after the owner enters their **own** Unsplash API key and
  explicitly imports a photo. Zero network traffic on a fresh install.
- It is an ordinary caption: editable/removable in the Media library, and the
  caller can override it via `input['caption']`.
- Now disclosed explicitly in readme.txt's `== External services ==` (item 3).

### 11. Guideline 11 — admin dashboard hijacking

Audited clean: **no upsell UI, no promo, no nag, no dismissible marketing, no
telemetry, no phone-home, no license check, no external CDN, and no
`plugin_action_links` / `plugin_row_meta` additions** anywhere in `admin/src/**`
or the built bundle. Zero pro/premium/upgrade strings in the shipped JS.

Saddle's own only global notice (`saddle.php:157` → `:256-268`) is a hard
requirement error ("requires WordPress 6.9+"), gated on `activate_plugins`, and
registered only when the Abilities API is absent.

Two things a scanner plausibly matched on:

1. **The notice quarantine** (`includes/admin/class-saddle-settings.php:89-134`),
   already noted in §6. On Saddle's own screen *only* (`get_current_screen()->id`
   gate), other plugins' notices are buffered into a hidden container the React
   app surfaces behind a disclosure. **Moved, not deleted** — dismiss buttons and
   inline handlers keep working, and it degrades safely if another callback
   closes the buffer. Saddle's own notices register at priority 0 so they print
   above it rather than exempting themselves from view. This prevents other
   plugins hijacking Saddle's screen; it is not Saddle hijacking anything.
2. ~~Two notices in the vendored `WP\MCP` library.~~ **No longer applicable** —
   the library is not shipped (see §3). The same removal takes with it the
   `error_log()` in its `Autoloader.php`, the `fwrite(STDOUT)` calls in its
   stdio CLI bridge, and the second REST endpoint it registered at
   `/wp-json/mcp/mcp-adapter-default-server`. **The shipped zip contains no
   `error_log()` call, no `fwrite()`, and no `var_dump()`** — verified by
   grepping the built artifact, not the dev tree.

### 12. Public OAuth endpoints (new in this submission)

Saddle now ships a small OAuth 2.1 authorization server, at
`includes/oauth/`. A reviewer scanning for `permission_callback => '__return_true'`
will find eight new routes; here is why each is public and what actually protects
it.

**Why it exists at all.** Saddle's MCP endpoint authenticates with core
Application Passwords over `Authorization: Basic`. Every AI client that lets a
person paste an HTTP header connects that way, and that remains the default.
**ChatGPT's custom-connector screen has no field for a custom header** — it offers
"no authentication", an API key, or OAuth. There is no way to hand ChatGPT a Basic
credential, so core's Authorize Application flow cannot connect it. The MCP
specification's answer is OAuth 2.1, so Saddle speaks it.

**It is off by default.** `saddle_oauth_enabled` defaults to false. While off,
none of these routes are registered at all (`Saddle_OAuth::register()` returns
early), the discovery documents 404, and no bearer token is honoured. A site that
never connects ChatGPT never exposes any of this. Turning it back off deletes
every stored token.

**The four public routes, and what guards them.**

| Route | Why public | What protects it |
|---|---|---|
| `GET /saddle/v1/oauth/authorize` | The caller has no credentials yet — that is why it is here | Validates the client and redirect URI, then **redirects to a wp-admin consent screen** that requires `manage_options`. It cannot issue anything by itself |
| `POST /saddle/v1/oauth/token` | RFC 6749 §3.2; a token endpoint is public by definition | Requires an authorization code that only the consent screen can mint, plus the PKCE **S256** verifier that produced its challenge. Codes are single-use, 60 seconds, and a replay revokes the whole grant |
| `POST /saddle/v1/oauth/register` | RFC 7591; a client with no credentials is exactly the client that needs to register | **Grants nothing.** A registration is inert metadata — no token, no user, no access. Rate limited to 5/hour per IP and 60/hour site-wide, body capped at 8 KB, record count capped, unused records garbage-collected |
| `GET .../oauth/protected-resource`, `.../authorization-server` | RFC 9728 / RFC 8414 discovery documents | Static, secret-free JSON. Every fact in them is already implied by the endpoint URL |

**No third-party service is involved.** The authorization server runs entirely
inside the user's own WordPress. There is no relay, no proxy, and no
PlugPress-controlled host anywhere in the flow. The one outbound request the
subsystem can make is fetching an app's own identity document from the HTTPS URL
that app presents as its `client_id` — disclosed in `readme.txt` under
`== External services ==` (item 4), guarded by the same SSRF check the media
sideloader uses (`Saddle_HTTP::url_is_safe()`), HTTPS-only, redirect-free, 5s
timeout, 64 KB cap, and cached.

**Nothing is stored in readable form.** Access tokens, refresh tokens, and
authorization codes are 256-bit random values persisted only as SHA-256 digests,
namespaced by record kind, in a private non-queryable CPT (`saddle_oauth`) that
uninstall removes. The raw value is returned to the client once and never written.

**It cannot escalate.** A token is only ever resolved into a WordPress user on the
MCP endpoint — never on `wp/v2`, `wp-abilities/v1`, XML-RPC, or Saddle's own
control plane. Its granted scope acts as a ceiling on the site's configured access
level (`Saddle_Capabilities::get_tier()` returns `min(site tier, scope)`), so a
connected app can never do more than the owner already allowed, and usually less.

Covered by 60 tests across `tests/oauth-flow-test.php`,
`tests/oauth-bearer-test.php`, and `tests/oauth-discovery-test.php`.

### 13. Per-object read authorization (round 2 blocker — fixed)

**Flagged:** `saddle/get-media`, quoted from
`includes/abilities/core-content.php`, with the note that the
`permission_callback` "only checks the generic read capability, while
`get_media` returns any attachment's metadata and URL without a per-attachment
`read_post` authorization check."

**The finding is correct, and it was five abilities wider than the line they
cited.** The reason it generalizes: read-tier abilities pass `$cap = 'read'`,
which **every logged-in user holds including a Subscriber**; any logged-in user
can mint a core Application Password; and the MCP route requires only
`is_user_logged_in()`. So the permission callback proved the caller was signed
in and nothing more, across the whole read surface. (The write side has guarded
against exactly this since day one, in `authorize_write()`.)

Six gaps, all closed: `get-media`, `get-post` / `get-page`,
`list-post-revisions`, `list-posts` / `list-pages`, `search-content`,
`list-media`.

**Authorization is two layers, and only the first is in the
`permission_callback`.** This is documented at the top of
`includes/abilities/core-content.php` and pointed at from every affected
registration, so the split is legible at the line a scan quotes:

| Layer | Where | Answers |
|---|---|---|
| Tool | `Saddle_Capabilities::permission( $tier, $cap, $tool )` | May this caller use this tool at all — authenticated, site access tier, generic capability, per-tool switch, global pause |
| Object | `Saddle_Abilities::require_readable_post()` / `::collection()` | May this caller read *this* row |

The object layer is on the execute path rather than in the gate on purpose.
Core does pass `$input` to `WP_Ability::check_permissions()` and accepts a
`WP_Error` back, but `Saddle_Capabilities::denial_reason()` and
`is_callable_now()` — which build `tools/list` and every refusal message an
agent reads — are input-free by construction, and a gate that answered "no"
without naming which layer said so is what puts an agent into a retry loop.
It is called *first* in every id-taking read callback, before the row is
touched.

**What each control does:**

- `require_readable_post()` resolves the id, rejects a wrong post type, then
  requires `read_post` on the object. For an attachment that is the whole
  mechanism: `read_post` resolves an `inherit` status through
  `get_post_status()`, which follows `post_parent`, so an upload on a draft or
  private post is refused and a parentless one stays readable — exactly how
  core's own media endpoint behaves.
- **Password-protected posts refuse rather than blank.** `map_meta_cap` never
  consults `post_password`; core blanks the content at render time instead.
  Saddle returns *raw* `post_content`, which core only hands out in the edit
  context, behind `WP_REST_Posts_Controller::can_access_password_content()` —
  so the threshold here is core's own `edit_post`. It refuses rather than
  blanks because this reader feeds a writer on the same id, and an agent handed
  `content: ""` concludes the page is empty and rebuilds it.
- `list-post-revisions` additionally requires `edit_post`, matching
  `WP_REST_Revisions_Controller::get_items_permissions_check()`. Being able to
  read a post is not being able to read the drafts it went through.
- Listings gate the *requested status* on the post type's `edit_posts`
  (core's `sanitize_post_statuses()`), then drop rows failing `read_post` in
  `collection()` (core's `get_items()`). Both are needed: an Author holds
  `edit_posts` and may legitimately ask for drafts, and must still not receive
  another author's.
- **One deliberate divergence from core, narrated rather than silent.**
  Filtering after the query leaves `total` counting rows that were not
  returned. Core accepts that silently, because recounting means a second
  unbounded query. Saddle adds a `note` to the response instead, because an
  agent handed a short page with no explanation retries it. It never fires for
  an administrator, so that response shape is unchanged for the normal setup.
- The same class of gap was swept for and closed in the activity log too:
  `recall-changes` and the "recent changes" section of the system context now
  filter each row against the object it names
  (`Saddle_Log::entry_is_visible()`).

**`get-preview-url` is deliberately stricter and does not use the shared
funnel.** A preview link renders unpublished content on the front end, so an
unpublished post needs `edit_post` there, not `read_post`.

**Tests:** `tests/read-authorization-test.php` — 39 cases driving the real
`wp_get_ability()->execute()` path an MCP client hits. Roughly half of them are
the administrator half of the contract: the normal Saddle setup is an owner's
administrator Application Password, and nothing about it may change. The core
behaviours this leans on (`read_post` following `post_parent` for `inherit`)
are pinned separately, so a core release surfaces as a red test rather than a
support email.

**Verified on a live install, not only in the suite.** Two Application
Passwords (administrator + a throwaway subscriber) against a private post, a
draft, and an attachment on the private post. Subscriber: 403 with a named
reason on `get-post`, `get-media` and `list-post-revisions`, and a named
refusal on `list-posts status=draft`; listings returned `publish` only.
Administrator: everything, `status=draft` still works, and no `note` key.

### 14. The Unsplash URL 401 (round 2 — a false positive in the checker)

**Flagged:** "Terms/Privacy URL: `https://unsplash.com/api-terms` — readme.txt —
This URL replies us with a 401 HTTP code, meaning that it does not work or is
not public."

**The page is public. `unsplash.com` sits behind Anubis** (the `within.website`
anti-scraping proof-of-work challenge) and serves a challenge to any
user-agent containing `Mozilla`. A human browser solves it and reads the page;
a checker that does not run the challenge JavaScript sees the 401.

Reproduction — same URL, two user agents:

```console
$ curl -sI -A 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 \
    (KHTML, like Gecko) Chrome/120.0 Safari/537.36' https://unsplash.com/api-terms
HTTP/2 307
location: /.within.website?redir=%2Fapi-terms
    → 401 Authorization required

$ curl -s -o /dev/null -w '%{http_code}\n' -A 'curl/8.4.0' https://unsplash.com/api-terms
200
```

Deterministic across repeated runs on 2026-08-25 and 2026-08-27.

**It is not limited to the flagged path.** Every `unsplash.com` path behaves
identically, including one that was **not** flagged:

| URL | browser UA | `curl` UA |
|---|---|---|
| `https://unsplash.com/api-terms` | 401 | 200 |
| `https://unsplash.com/privacy` | 401 | 200 |
| `https://unsplash.com/terms` | 401 | 200 |
| `https://unsplash.com/license` | 401 | 200 |
| `https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines` | 200 | 200 |

So swapping only the flagged URL would leave the identical landmine on the
privacy link, and there is **no** browser-reachable canonical Unsplash terms or
privacy URL to swap to. Both canonical URLs therefore stay — guideline 6 asks
for the service's actual terms and privacy policy, and Unsplash's privacy
policy exists nowhere else — with Unsplash's own help-centre API guidelines
page, which answers 200 to everything, listed first alongside them.

**Context for how little this reaches a user.** The Unsplash integration is off
until the site owner pastes their own Unsplash Access Key on the Integrations
screen. With no key saved, no request is ever made and the two tools are not
offered. The disclosure exists because the integration can be turned on, not
because it is on.

## Draft reply — round 2

Not sent. Re-run the two `curl` commands in §14 on the day it goes out, so the
transcript in the mail is current rather than quoted from here.

---

Thanks for the review — both items below, with the second one needing an
explanation rather than a change.

**1. `permission_callback` on `saddle/get-media`**

You were right, and it was wider than the line you cited. Our read-tier
abilities pass `read` as the capability, which every logged-in user holds
including a Subscriber, so the permission callback proved the caller was signed
in and nothing more. Six abilities were affected: `get-media`, `get-post`,
`get-page`, `list-post-revisions`, `list-posts`/`list-pages`, `search-content`
and `list-media`.

All of them now authorize the object, not just the tool:

- Every id-taking read calls `Saddle_Abilities::require_readable_post()` first,
  before touching the row. It resolves the id, rejects a wrong post type, and
  requires `read_post` on the object. For an attachment that resolves the
  `inherit` status through `post_parent`, so an upload on a draft or private
  post is refused and a parentless one stays readable — the same behaviour as
  core's media endpoint.
- Raw `post_content` on a password-protected post now requires `edit_post`,
  matching `WP_REST_Posts_Controller::can_access_password_content()`, since we
  return the raw field rather than the rendered one.
- `list-post-revisions` additionally requires `edit_post` on the parent, the
  same threshold as `WP_REST_Revisions_Controller`.
- Listings gate the requested status on the post type's `edit_posts` and then
  drop rows failing `read_post`, mirroring `sanitize_post_statuses()` and
  `get_items()`.

To make the split easy to see at the line you quoted: the `permission_callback`
gates the *tool* (authentication, the site's access level, the generic
capability, the per-tool switch, the pause switch) and the object check is the
first thing the execute callback does. Both layers are now documented at the top
of `includes/abilities/core-content.php` and pointed at from each affected
registration. It lives on the execute path because our refusal messages have to
name which control refused — an AI agent given an unexplained "no" retries in a
loop — and the tool-list filter that reads the same gate has no input to check
an object against.

Covered by 39 tests that drive the real ability-execution path, about half of
them pinning that an administrator credential — the normal setup for this
plugin — is unaffected. We also verified it on a live install with two
Application Passwords, an administrator and a throwaway Subscriber, against a
private post, a draft and an attachment on the private post.

**2. `https://unsplash.com/api-terms` returning 401**

This one is a false positive, and unfortunately there is nothing we can change
in the plugin to make it stop. The page is public; `unsplash.com` sits behind
Anubis, an anti-scraping proof-of-work challenge, and serves that challenge to
any user agent containing `Mozilla`. A browser solves it and reads the page; a
checker that does not run the challenge script sees a 401.

Same URL, two user agents:

```
$ curl -sI -A 'Mozilla/5.0 … Chrome/120.0 Safari/537.36' https://unsplash.com/api-terms
HTTP/2 307
location: /.within.website?redir=%2Fapi-terms      → 401 Authorization required

$ curl -s -o /dev/null -w '%{http_code}\n' -A 'curl/8.4.0' https://unsplash.com/api-terms
200
```

It affects every path on that host, including `https://unsplash.com/privacy`,
which your check did not flag. So swapping the flagged URL for another
`unsplash.com` page would not fix anything, and there is no
browser-reachable canonical Unsplash terms or privacy URL to point at instead.
We have kept both canonical links, because guideline 6 asks for the service's
actual terms and privacy policy and Unsplash's privacy policy exists nowhere
else, and listed Unsplash's own help-centre API guidelines page — which answers
200 to everything — alongside them.

Worth adding for context: this integration is inert until the site owner pastes
their own Unsplash API key on our Integrations screen. With no key saved, no
request is ever made and the two tools are not offered at all. We disclose it
because it can be turned on, not because it is on.

Happy to make any change you would prefer here — including dropping the two
`unsplash.com` links entirely and keeping only the help-centre page, if you
would rather the readme contain no URL your checker flags.

The corrected zip is uploaded.
