=== Saddle – Control WordPress with AI (MCP Server) ===
Contributors: badhonrocks
Tags: mcp, ai, application passwords, agents, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, Cursor, and other AI agents to WordPress. Structured tools, safe-by-default permissions, approval gates for destructive actions.

== Description ==

Saddle turns your own WordPress site into a **Model Context Protocol (MCP) server**, so AI agents such as Claude can work with your content — read posts, draft pages, manage media, design layouts, and more — under controls **you** set. Nothing is routed through anyone else's servers: the endpoint lives on your site, and agents authenticate with WordPress's own Application Passwords.

Most "AI for WordPress" tools ask you to hand your content or credentials to a third-party cloud. Saddle is built on the opposite premise — that an AI should be able to help with your site **without you giving up custody or control**.

= Three commitments =

1. **No third-party credential custody.** Saddle runs entirely inside your WordPress install. Your tokens, your content, and your tool-call traffic never pass through a relay or proxy operated by us or anyone else. Authentication uses WordPress core's Application Passwords; Saddle never sees or stores a separate password.
2. **Default-safe access levels.** A fresh install starts at the **Read** level — agents can look but not touch. Writing, deleting, and site management are powers you explicitly turn on, never things you have to turn off.
3. **Two-step confirmation on destructive actions.** Deleting or overwriting never happens in a single call. The first call returns a preview and a single-use confirmation token and changes nothing; only a second call carrying that token executes the change. Tokens are single-use and expire after 15 minutes.

= What your AI can do =

A focused, permissioned set of tools over a single authenticated MCP endpoint:

* **Content** — list, read, create, update, and delete (trash or permanent, always confirmed) posts and pages; manage media, including uploading from a URL; list and create categories and tags; search; read site info.
* **Page design** — build and edit real, validated Gutenberg blocks (add, edit, move, remove, insert patterns), read block schemas and your theme's design tokens, so agents design *with* your theme instead of pasting raw HTML. Page-builder layouts are guarded against accidental destruction.
* **Site management** *(opt-in "Managing the site" level)* — read and change your WordPress **Settings pages** (site title and tagline, permalink structure, front-page and reading options, date/time and discussion settings), activate/deactivate plugins, switch themes, and flush the cache. Everything runs through WordPress's own functions — Saddle never runs shell commands or arbitrary code, values are validated, changing permalinks rebuilds your links automatically, and sensitive settings (site URL, security keys, user roles, admin email) can never be touched.
* **Guidance & memory** — install **Skills**: playbook files (`.md`) that teach your AI how to do specific jobs your way. Every new session also starts knowing what changed on the site recently, from Saddle's own activity log.

Every tool declares its required access level and whether it is destructive. A Read-level connection can only ever read.

= Full transparency, in the dashboard =

* **Access levels** — choose Read, Read & write, or Managing the site, and see exactly which tools each level unlocks. Turn off any individual tool.
* **Activity** — a day-grouped record of everything connected apps changed, and every attempt that was blocked. Reads are never logged.
* **Guidance** — read the exact context every agent is given, write your own instructions, and manage your installed Skills.
* **Pause** — one switch instantly denies every tool call, without losing your settings.

= How agents connect =

From **Saddle → Apps**, you name a connection and approve it. WordPress core issues an Application Password scoped to that connection, and Saddle confines that credential to its own endpoint — a Saddle key cannot be used against the rest of the REST API or XML-RPC. Revoke any connection at any time to instantly invalidate its credentials.

= Bundled library =

Saddle bundles the **WordPress MCP Adapter** library (`WP\MCP`, GPLv2-or-later) so it speaks the Model Context Protocol out of the box, with no extra plugin to install. If the standalone "MCP Adapter" plugin is already active on your site, Saddle uses that copy instead. To keep the bundled copy dormant, return `false` from the `saddle_load_bundled_mcp_adapter` filter.

= Pro =

**Saddle Pro** adds page-builder-native editing (Divi first), so agents build real builder pages that stay fully editable in the Visual Builder — under the same access levels and confirmations. Pro is a separate add-on and is not required.

== External services ==

Saddle does not phone home. It sends **no** analytics, telemetry, or usage data anywhere, and it never relays your data through a service we operate. The MCP endpoint Saddle adds (`/wp-json/saddle/v1/mcp`) is an **inbound** authenticated endpoint on your own site.

There are two outbound behaviors, both initiated by you and both to hosts you choose:

* **`upload-media` — fetching a URL you or your agent provide.** When you ask an agent to add a file to your media library by URL, Saddle uses WordPress's own HTTP API to download that specific URL to your server. It contacts only the host in the supplied URL. This is the same mechanism WordPress core uses for "insert from URL."
* **Endpoint self-check — a request to your own site.** To confirm the MCP endpoint is reachable, Saddle may make a single request to your site's own REST URL. This contacts only your site; no data is sent off-site.

== Privacy ==

* Authentication is handled entirely by WordPress core Application Passwords. Saddle stores only its settings (access level, per-tool toggles, your instructions, installed Skills) and short-lived, single-use confirmation tokens that auto-expire after 15 minutes.
* No personal data is sent off-site by Saddle.
* Uninstalling removes Saddle's settings, its activity log, confirmation tokens, and installed Skills. Your Application Passwords are left intact so you can revoke them yourself.

== Installation ==

1. Upload the plugin to `wp-content/plugins/saddle` and activate it. Saddle requires **WordPress 6.9 or newer** (for the core Abilities API) and **PHP 8.0+**.
2. Open **Saddle** in the admin menu. The access level defaults to **Read**.
3. Under **Apps**, name a connection and click **Connect** to issue its credential.
4. Configure your MCP client with the connection URL, your username, and the issued Application Password.
5. Raise the access level to **Read & write** — or **Managing the site** — only when you want agents to be able to do more. Deletions and overwrites always ask first.

== Frequently Asked Questions ==

= Does my content or credentials go through your servers? =

No. Saddle has no relay or proxy. Everything runs inside your WordPress install, and authentication uses WordPress core Application Passwords. Saddle sends no telemetry.

= Can an agent delete or overwrite something without my say-so? =

Not in one step. Deleting requires the Write level, and every delete or destructive overwrite uses a two-step confirmation: a preview first, then a second call carrying a single-use token that expires in 15 minutes. A misbehaving or prompt-injected agent cannot delete in a single shot.

= What stops an agent from doing more than I allowed? =

Every tool is bound to an access level, and new installs start at Read. A tool above the current level is refused and the attempt is logged. You can also switch off individual tools, and the Pause switch denies everything at once.

= Does a connected agent get the keys to my whole site? =

No. A Saddle-issued Application Password is scoped to Saddle's own endpoint — it can't be used against the rest of the REST API or XML-RPC. Revoking the connection invalidates it immediately.

= Why does a WordPress plugin let an external AI act on my site? =

That is the purpose of an MCP server: to let an AI assistant *you* control work on *your* site. Saddle's whole design exists to make that power explicit, tiered, default-safe, and confirmable — rather than implicit and unlimited.

= Do I need an account or a subscription? =

No. Saddle is free and self-hosted. There is no account, no cloud service, and nothing to sign up for.

== Screenshots ==

1. Access levels — choose how much your AI can do, and see exactly which tools each level unlocks.
2. Connecting an app — name a connection and issue its credential without leaving the dashboard.
3. Activity — a day-grouped record of everything agents changed, and everything that was blocked.
4. Guidance — the exact context every agent receives, your own instructions, and your installed Skills.

== Upgrade Notice ==

= 0.8.0 =
Adds Memory (your AI remembers site facts between sessions, with you in control of what gets shared automatically), a design lint that reviews pages against your site's own styles, and free Waggle integration. Fully backward compatible.

= 0.7.0 =
Your AI can now read and change your WordPress Settings pages — site title, tagline, permalinks, reading and discussion options — all validated, previewed, and confined to safe keys. Fully backward compatible.

= 0.6.0 =
Adds Skills (installable .md playbooks that teach your AI your conventions) and automatic recent-changes recall so a new session knows what changed last time. Fully backward compatible.

== Changelog ==

= 0.9.0 =
* New: verify-page — one scored report (0-100) over a page's SAVED state: structural problems, style settings the editor silently ignores, and design/accessibility violations, each at a fixable node address. Your AI builds, verifies, fixes what was flagged, and re-verifies — it ends on evidence, not on a write call saying "ok".
* New: render-node — your AI can now SEE what it built: a node's effective styles (presets and tokens resolved to real values) and its rendered HTML, or a whole-page section outline to drill into. No more styling blind.
* New: get-preview-url — a signed, short-lived link that renders the page's current saved layout (drafts included) on your own site's front end, so an AI with a browser can screenshot its own work. The link is noindex, opens only that one post, expires in minutes, and nothing ever leaves your site.
* New: accessibility checks in page lint — text contrast against the real background it sits on (WCAG AA), images missing alt text, and broken heading order (skipped levels, duplicate h1s).

= 0.8.0 =
* New: Memory — your AI can remember facts about your site (remember, recall, forget) so a new session starts already knowing them. You stay in control: entries an AI saved on its own are only found when searched for, until you pin them; pinned notes and your own notes are shown to every session automatically. A Memory panel in Guidance shows everything stored, who saved it, and a preview of exactly what gets shared.
* New: page lint — a lint-page tool reviews a page's design against your site's own styles (button contrast, spacing, mixed accent colors, heading order and more) and reports concrete fixes, so your AI can check its own work before you see it.
* New: every design write now reports back which style settings were actually applied and which were ignored, so your AI notices a mistyped setting immediately instead of assuming it worked.
* New: Waggle integration — if the Waggle plugin is installed, its tools appear through Saddle automatically, governed by the same access levels, previews, and Activity log. First-party PlugPress plugins integrate free.

= 0.7.0 =
* New: manage the WordPress Settings pages — read and change the site title and tagline (General), the front page and posts-per-page and search visibility (Reading), comment defaults (Discussion), and the permalink structure (Permalinks). Grouped by settings page, so your AI can find them.
* Changing the permalink structure now rebuilds your site's links automatically, exactly like saving the Permalinks screen.
* Settings are validated before saving so a bad value can't break your site: a static front page must point to a real published page (never a draft or a non-page), the default category must exist, timezones and number/format options are range-checked, and switching to a static homepage is refused until a valid page is assigned. Every change previews and asks first, records the old and new value in Activity, and sensitive keys (site URL, security keys, roles, admin email) remain off-limits.

= 0.6.0 =
* New: Skills — install playbook files (.md) that teach your AI how to do specific jobs on your site. Every connected AI sees the skill list and reads a playbook when a task matches; only you can add skills, and a skill can never grant more access than your chosen level.
* New: recent-changes recall — every new session automatically starts knowing what connected AI apps changed on the site recently, from Saddle's own activity log. Blocked attempts are never included; the block can be switched off.
* New: recall-changes tool — your AI can look further back in the change log before editing something.

= 0.5.0 =
* New: a "Managing the site" access level — a separate, explicit opt-in above reading & writing that lets your AI manage plugins, themes, and settings. Reading and writing never grant it.
* New: site-management tools — list/activate/deactivate plugins, list/activate themes, read and change an allowlist of safe site settings, and flush the object cache. All run through WordPress's own functions; Saddle never runs shell commands or arbitrary code.
* Safety: settings changes are limited to an allowlist (site URL, security keys, roles, and the active plugin/theme list are never touched), overwriting a setting previews and asks first, and Saddle refuses to deactivate itself.

= 0.4.0 =
* New: Activity page — the full, day-grouped record of everything connected apps changed through Saddle, and every attempt that was blocked. Filter by changes or blocked; reads are never logged.

= 0.3.0 =
* New: dark mode — follows your system by default, with a per-user toggle in the top bar.
* New: Saddle owns its screen — a calm full-height canvas; notices from other plugins are tucked behind a quiet disclosure instead of piling above the app.
* New: connected apps show their key's last four characters, and the UI now says where keys live and that they're shown only once.
* Improved: one Save bar on Permissions — level and individual tool changes apply together.
* New: a real Saddle brand mark and admin menu icon.

= 0.2.1 =
* Fix: the Connect tab could crash when rendering app logos.

= 0.2.0 =
* New: native block design abilities — agents build and edit pages with real, validated editor blocks (get/set-blocks, add/edit/move/remove-block, insert-block-pattern, block schemas, theme.json design tokens, pattern browsing).
* New: builder-content guard — raw content writes can never destroy a page-builder layout.
* New: connect wizard issues credentials directly with per-site server names.
* Security: Saddle-issued application passwords only open Saddle's own endpoint.
* Improved: full site context served in the MCP initialize handshake; taxonomy abilities; activity log surfaced in Home.

= 0.1.0 =
* Initial release: tiered, default-safe, approval-gated MCP access to posts, pages, and media.
