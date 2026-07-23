# WordPress.org submission — checklist & reviewer notes

Internal doc (never shipped in the zip — excluded in Gruntfile.js). Last audit:
2026-07-23, full submission-readiness pass — **no guideline blockers found**.

## Submitting

1. Upload `dist/saddle-1.0.0.zip` at https://wordpress.org/plugins/developers/add/
   (rebuild first if HEAD moved: `npm run build && npx grunt build` — never
   `grunt release`, which bumps the version).
2. Account: **badhonrocks** (readme `Contributors:` — confirm this is the
   submitting wp.org account before uploading).
3. After approval: SVN `assets/` gets the 4 screenshots listed in readme.txt
   plus banner/icon. Screenshots do NOT go in the zip.
4. First public GitHub tag/release only AFTER wp.org approval (CLAUDE.md
   distribution rule).

## Pre-written answers for likely reviewer questions

### 1. `permission_callback => '__return_true'` on `/saddle/v1/auth-probe`

`includes/class-saddle-connection.php:333`. Intentional and safe:

- The callback (`rest_auth_probe`) returns **only a boolean** — whether an
  `Authorization` header survived the server stack and reached PHP. It never
  reads, validates, stores, or echoes credentials.
- It exists so the connection self-check can detect header-stripping proxies
  (common on Apache CGI/LiteSpeed), which would otherwise surface as opaque
  401s for every connected agent.
- No information disclosure: the response is `{"authorization_header_received":
  true|false}` derived from the current request only.

Every other route is gated: admin REST routes require `manage_options`; all
MCP abilities run through `Saddle_Capabilities::permission()` (tiered
read/write/admin + per-tool toggles + pause switch).

### 2. External services (guideline 6)

Exactly three outbound `wp_remote_get` targets, all disclosed in readme.txt
"External services":

- `api.unsplash.com` / `images.unsplash.com` — only when the site owner
  supplies their own Unsplash Access Key; terms + privacy links in readme.
- A **loopback** request to the site's own REST URL (the auth-probe above) —
  connection self-check only, never leaves the server.
- `upload-media` fetches a URL only when the connected agent explicitly
  provides one (disclosed in readme).

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

- Auth: WordPress core Application Passwords only; no custom auth layer. A
  Saddle-issued credential is confined to Saddle's endpoint (cannot be used
  against the wider REST API or XML-RPC).
- No `eval`/`exec`/`system`/`shell_exec`/`proc_open`. One `base64_decode`
  (RFC 7617 Basic-auth parsing, annotated).
- Destructive actions require a two-step confirm (single-use token, 15-min
  TTL) via `Saddle_Approval::gate()`.
- New installs default to the read-only tier.
