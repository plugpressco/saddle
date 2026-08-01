# WordPress.org submission — checklist & reviewer notes

Internal doc (never shipped in the zip — excluded in Gruntfile.js). Last audit:
2026-07-28, responding to review round 1.

## Review history

### Round 1 — pended 2026-07-25

Review ID `AUTOPREREVIEW ❗TRM saddle/badhonrocks/25Jul26/T1 25Jul26/4.2A1
(P0TDX346076HGN)`. Largely automated ("humans, algorithms, and AI in varying
proportions"), so several items were pattern-match flags rather than findings.
Five flags; two were real. All addressed 2026-07-28 — see §8–§11 below for the
answers, which are the ones to reuse if round 2 asks again.

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
   from the disc brand mark; see `.wordpress.org/README.md` for the SVN copy
   commands). Only the screenshots remain to be captured.
4. First public GitHub tag/release only AFTER wp.org approval (CLAUDE.md
   distribution rule).

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

### 3. Bundled library

`includes/lib/wp-mcp` — the official WordPress **MCP Adapter** (`WP\MCP`),
GPLv2, license included. If the standalone MCP Adapter plugin is active,
Saddle defers to that copy (`saddle_load_bundled_mcp_adapter` filter to opt
out of the bundled one).

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
2. **Two notices in the vendored `WP\MCP` library** (`includes/lib/wp-mcp/
   includes/Autoloader.php:67`, `Plugin.php:73`) are non-dismissible and not
   capability-checked. Both are unreachable in the shipped zip: the composer
   autoloader **is** bundled (262 `wp-mcp/vendor/` entries incl.
   `vendor/autoload.php`), and `Saddle::setup_mcp_transport()` only loads the
   library when `wp_register_ability` already exists (`saddle.php:132-153`).
   Deliberately **not** patched — don't fork third-party code for a dead branch.

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
