=== Saddle ===
Contributors: badhonrocks
Tags: mcp, ai, model context protocol, application passwords, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted MCP server for WordPress. Tiered, default-safe, approval-gated access to your posts, pages, and media for AI agents — with no third-party credential custody.

== Description ==

Saddle turns your own WordPress site into a Model Context Protocol (MCP) server so AI agents (such as Claude) can work with your content — read posts, draft pages, manage media — under controls **you** set.

Saddle is built around three commitments:

1. **No third-party credential custody.** Saddle runs entirely inside your WordPress install. Your tokens, your content, and your tool-call traffic never pass through a relay or proxy controlled by us or anyone else. Authentication uses WordPress core's own Application Passwords; Saddle never sees or stores a separate password.
2. **Default-safe access tiers.** A fresh install starts at the **Read** tier — agents can look but not touch. Granting write or delete power is something you explicitly turn on in the settings, never something you have to turn off.
3. **Two-step confirmation on every destructive action.** Deleting a post, page, or media item never happens on a single call. The first call returns a preview and a single-use confirmation token and changes nothing; only a second call carrying that token executes the change. Tokens are single-use and expire after 15 minutes.

= What it exposes =

A focused set of content abilities over a single authenticated MCP endpoint:

* Posts — list, get, create, update, delete (trash or permanent), list revisions
* Pages — list, get, create, update, delete (trash or permanent)
* Media — list, get, upload from a URL, update metadata, delete
* Taxonomies — list and create categories and tags
* Site — get site info, search content, get owner instructions

Each ability declares its required access tier and whether it is destructive. Read-tier connections can only ever read.

= How agents connect =

From **Saddle → Apps**, you name a connection and approve it through WordPress core's built-in "Authorize Application" screen. Core issues an Application Password scoped to that connection. You can revoke any connection at any time, which immediately invalidates its credentials.

= Bundled library =

Saddle bundles the **WordPress MCP Adapter** library (`WP\MCP`, GPLv2-or-later) to speak the Model Context Protocol, so it works out of the box with no extra plugin to install. If the standalone "MCP Adapter" plugin is already active on your site, Saddle uses that copy instead. To keep Saddle's bundled copy dormant (e.g. to run the standalone plugin), return false from the `saddle_load_bundled_mcp_adapter` filter.

== External services ==

Saddle does not phone home and does not relay your data through any service we operate. There is, however, one outbound network behavior you should understand:

* **`upload_media` — fetching a URL you (or your agent) provide.** When you ask an agent to add a file to your media library by URL, Saddle uses WordPress's own HTTP API to download that specific URL to your server. This contacts only the host in the URL supplied at call time. No other data is transmitted, and no other endpoint is contacted. This is the same mechanism WordPress core uses for "insert from URL."

Saddle itself sends **no** analytics, telemetry, or usage data to any external endpoint. The MCP endpoint Saddle adds (`/wp-json/saddle/v1/mcp`) is an **inbound** authenticated endpoint on your own site; agents connect to it using an Application Password you issued.

== Privacy ==

* Authentication is handled by WordPress core Application Passwords. Saddle stores only a site-wide access-tier setting and short-lived, single-use confirmation tokens (auto-expiring after 15 minutes).
* No personal data is sent off-site by Saddle.

== Installation ==

1. Upload the plugin to `wp-content/plugins/saddle` and activate it (requires WordPress 6.9+ for the core Abilities API).
2. Open **Saddle** in the admin menu. The access tier defaults to **Read**.
3. Under **Connected Apps**, name a connection and click **Connect** to approve it through WordPress's Authorize Application screen.
4. Configure your MCP client with the connection URL, your username, and the issued Application Password.
5. Raise the access tier to **Write** only when you want agents to be able to create, update, or (with confirmation) delete content.

== Frequently Asked Questions ==

= Does my content or credentials go through your servers? =

No. Saddle has no relay or proxy. Everything runs inside your WordPress install, and authentication uses WordPress core Application Passwords.

= Can an agent delete content without my say-so? =

Deletion is only possible at the Write tier, and even then every delete requires a two-step confirmation: a preview first, then a second call carrying a single-use token. A misbehaving agent cannot delete in one shot.

= Why does a WordPress plugin let an external AI create or delete content? =

That is the entire purpose of an MCP server: to let an AI assistant you control act on your site. Saddle's design exists to make that power explicit, tiered, default-safe, and confirmable — rather than implicit.

== Changelog ==

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
* New: connect wizard issues credentials directly (no authorize-screen bounce) with per-site server names.
* Security: Saddle-issued application passwords only open Saddle's own endpoint.
* Improved: full site context served in the MCP initialize handshake; taxonomy abilities; activity log surfaced in Home.

= 0.1.0 =
* Initial release: tiered, default-safe, approval-gated MCP access to posts, pages, and media.
