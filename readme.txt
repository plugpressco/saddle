=== Saddle – Control Your Site with AI (MCP Server) ===
Contributors: badhonrocks
Tags: mcp, ai, application passwords, agents, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, Cursor, and other AI agents to WordPress. Structured tools, safe-by-default permissions, approval gates for destructive actions.

== Description ==

Saddle turns your WordPress site into a **Model Context Protocol (MCP) server**. AI apps you already use — Claude, Cursor, VS Code, and others — connect to your site and help with real work: reading and writing posts and pages, managing media, and designing pages with your theme's own styles.

Everything runs on your own site. There is no account to create, no cloud service in the middle, and no data sent to us — ever. Agents sign in with WordPress core's **Application Passwords**, and you decide how much they are allowed to do.

= How it stays safe =

Saddle is built around three rules:

1. **Your credentials and content stay on your site.** Saddle has no relay, proxy, or backend of its own. Authentication is WordPress core's Application Passwords — Saddle never sees or stores a separate password, and your tool-call traffic never touches a server we operate.
2. **New installs start read-only.** Out of the box, agents can look but not change anything. Writing and site management are levels *you* turn on. They are never on by default.
3. **Deleting or overwriting always asks first.** A destructive action takes two calls: the first returns a preview and a single-use confirmation token and changes nothing; only a second call with that token executes. Tokens expire after 15 minutes. An agent — even a misbehaving one — cannot delete anything in a single step.

= What your AI can do =

All tools are served from one authenticated endpoint on your site (`/wp-json/saddle/v1/mcp`). Each tool declares the access level it needs, so a read-only connection can only ever read.

* **Content** — list, read, create, update, and delete posts and pages (deletes trash by default and always confirm first); manage media, including upload from a URL; categories and tags; search; site info.
* **Page design** — build and edit real Gutenberg blocks that stay editable in the editor, read block schemas and your theme's design tokens, and insert patterns. Agents design *with* your theme instead of pasting raw HTML, and page-builder layouts are protected from accidental overwrites.
* **Site management** (separate opt-in level) — read and change common Settings screen options (site title, permalinks, reading and discussion settings), activate/deactivate plugins, switch themes, flush the cache.

  **Note:** everything here runs through WordPress's own functions. Saddle contains no shell commands, no `eval()`, and no arbitrary code execution — anywhere. Values are validated before saving, and sensitive settings (site URL, security keys, user roles, admin email) can never be touched.
* **Guidance & memory** — install Skills (plain `.md` playbook files that teach your AI how you like things done), and let each new session start knowing what changed on the site recently, from Saddle's own activity log.

= What you see and control =

* **Access levels** — pick Read, Read & write, or Managing the site, and see exactly which tools each level allows. Any individual tool can be switched off.
* **Activity** — a day-by-day record of everything agents changed and every attempt that was blocked. Reads are not logged.
* **Guidance** — the exact context every agent receives, plus your own instructions and Skills.
* **Pause** — one switch that instantly blocks every tool call, without losing your settings.

= How agents connect =

Go to **Saddle → Connections**, name a connection, and approve it. WordPress core issues an Application Password for it, and you paste the shown settings into your AI app. Revoking a connection invalidates its credential immediately.

**Note:** a Saddle-issued credential only works on Saddle's own endpoint. It cannot be used against the rest of the REST API or XML-RPC.

= Sign-in for apps that can't paste a key (optional, off by default) =

A few apps — ChatGPT's custom connectors among them — give you nowhere to paste a sign-in key. For those, Saddle can run a standard **OAuth 2.1 sign-in** on your own site: the app sends you to an approval screen in your WordPress admin, you see who is asking and what they want, and you decide.

This is **off until you turn it on**, on the Settings screen. It runs entirely inside your WordPress — there is no PlugPress server involved at any point, exactly as with Application Passwords. Only administrators can approve a connection, and an approval can never grant more than your chosen access level: if the site is set to read-only, an approved app gets read-only. You can see and revoke approved apps from the Connections screen at any time.

Turning it on publishes the small set of addresses the OAuth standard requires so apps can find and complete the sign-in. With it off — the default — none of them exist.

= Bundled library =

Saddle bundles the WordPress **MCP Adapter** library (`WP\MCP`, GPLv2-or-later, license included in `includes/lib/wp-mcp/`) so it speaks MCP with no extra plugin to install. If the standalone MCP Adapter plugin is already active, Saddle defers to that copy automatically.

= Source code =

The admin screen is a React app. Its full human-readable source ships inside this plugin in `admin/src/`; the compiled bundle in `admin/build/` is produced from it with the official `@wordpress/scripts` toolchain.

= Saddle Pro =

Saddle Pro is a separate, optional add-on that adds page-builder-native editing (Divi first). This free plugin is complete on its own — nothing in it is locked, limited, or nagging you to upgrade.

== External services ==

Saddle sends **no** analytics, telemetry, or usage data anywhere. Its MCP endpoint is *inbound* — agents call your site; your site does not call out to us or anyone else on its own.

Only four things ever make an outbound request, and each one is started by you:

1. **Upload from URL.** If you ask an agent to add a file to the media library by URL, WordPress's own HTTP API downloads that one URL to your server — the same mechanism core's "insert from URL" uses. Only the host in the URL you supplied is contacted.
2. **Endpoint self-checks.** The connection checker sends requests to *your own site* — its REST URL, and, when OAuth sign-in is on, its `/.well-known/` discovery address — to confirm those endpoints are reachable. Nothing leaves your server.
3. **Unsplash (optional, off until you add a key).** If you enter your own Unsplash API key on the Integrations screen, the `unsplash-search` and `unsplash-import` tools call the Unsplash API (`api.unsplash.com`, `images.unsplash.com`) directly from your site, sending only your search keywords or a photo id. With no key saved, no request is ever made. Unsplash terms: https://unsplash.com/api-terms — privacy policy: https://unsplash.com/privacy

  **Attribution:** a photo imported this way is saved with a caption crediting the photographer, containing links to their Unsplash profile and to unsplash.com. The Unsplash API Terms require this credit, so it is written for you. Because it is the image's caption, it is visible wherever your theme displays captions — including on the public side of your site. It is an ordinary caption: edit or clear it in the Media library whenever you like, or pass your own caption when importing. No other external link is ever added to your site, and nothing links back to the plugin author.

4. **Checking an app's identity (optional, off unless you turn on OAuth sign-in).** ChatGPT can't be given a sign-in key by hand, so Saddle can let apps sign in through an approval screen instead. Some apps identify themselves with a web address that serves a small description of the app. If one does, Saddle fetches that address — and only that address, chosen by the app, never by us — to confirm it vouches for the app, so the approval screen can tell you whether the app was verified or merely self-described. Nothing about your site is sent; it is a plain read. The request is HTTPS-only, follows no redirects, times out in five seconds, is capped at 64 KB, and the answer is cached. With OAuth sign-in off — the default — this never happens.

== Privacy ==

* Saddle stores only its own settings (access level, tool toggles, your instructions, Skills, memory entries), its activity log, and short-lived confirmation tokens that expire after 15 minutes.
* If you turn on OAuth sign-in, Saddle also stores the apps you approved and their sign-in tokens. Tokens are never kept in readable form — only a one-way fingerprint, so a database backup contains nothing anyone could sign in with. Disconnecting an app deletes its tokens immediately, and turning OAuth sign-in back off deletes all of them.
* No personal data is sent off-site.
* Uninstalling deletes all of the above. Application Passwords are left for you to revoke yourself (Users → Profile), since WordPress core owns them.

== Installation ==

1. Install and activate the plugin. Saddle needs WordPress 6.9+ (for the core Abilities API) and PHP 7.4+.
2. Open **Saddle** in the admin menu. New installs start at the **Read** level.
3. Go to **Connections**, name a connection (for example "Claude"), and approve it. Copy the settings it shows into your AI app.
4. When you want agents to do more than read, raise the level on the **Permissions** screen. Deletes and overwrites will still ask for confirmation every time.

== Frequently Asked Questions ==

= Does my content or my password go through your servers? =

No. Saddle has no servers. Everything runs inside your WordPress install, sign-in is WordPress core's Application Passwords, and no telemetry is sent.

= Can an agent delete something without asking? =

No. Every delete or destructive overwrite takes two calls: a preview first, then a confirmation with a single-use token that expires in 15 minutes. One call can never destroy anything.

= What stops an agent from doing more than I allowed? =

Every tool is bound to an access level, and new installs start at Read. Calls above the current level are refused and logged. You can also switch off individual tools, or hit Pause to block everything at once.

= Does a connected app get full access to my site? =

No. Its credential only works on Saddle's endpoint — not the rest of the REST API, not XML-RPC. Revoke the connection and the credential dies with it.

= Do I need an account or subscription? =

No. Saddle is free and entirely self-hosted. There is nothing to sign up for.

= Does it run shell commands or arbitrary code? =

Never. Every operation goes through WordPress's own PHP functions. There is no shell access, no `eval()`, and no way to add either through a tool call.

== Screenshots ==

1. Access levels — choose how much your AI can do, and see exactly which tools each level allows.
2. Connecting an app — name a connection and issue its credential without leaving the dashboard.
3. Activity — a day-grouped record of everything agents changed, and everything that was blocked.
4. Guidance — the exact context every agent receives, your own instructions, and your installed Skills.

== Changelog ==

= 1.0.0 =
* Initial public release.
* MCP server on your own site: content tools (posts, pages, media, taxonomies, search), Gutenberg block design tools with schema validation and theme design tokens, opt-in site management (settings, plugins, themes, cache), Skills, memory, and an activity log.
* Safety model: three access levels defaulting to read-only, per-tool switches, two-step confirmation on every destructive action, a master pause switch, and credentials confined to Saddle's endpoint.
* Optional Unsplash integration (bring your own API key): search and import stock photos with automatic photographer attribution.
* Design quality tools: page verification with a scored report, design lint, section recipes, and a design-system reader/seeder.
* Works on hosts whose security layer strips custom request headers: the dashboard sends its sign-in token in the address as well as the header, and reports plainly when a host is blocking something it cannot work around.
* Optional OAuth 2.1 sign-in (off by default) for apps that can't be given a sign-in key by hand, such as ChatGPT connectors — self-hosted, administrator-approved, and never able to grant more than the access level you chose.
* Fixed: some connected apps — ChatGPT connectors in particular — signed in successfully but then reported that the site had no actions they could use. Saddle now serves apps that don't hold on to a session between requests, and no longer turns away an app for naming a protocol revision it hadn't seen. Apps that do hold a session are unaffected, and no access level or approval step changes.
