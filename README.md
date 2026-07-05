# Saddle – Control WordPress with AI (MCP Server)

**A safe, self-hosted MCP server for WordPress.** Turn your own site into a [Model Context Protocol](https://modelcontextprotocol.io) server so AI agents like Claude can work with your content — under access levels you set, with no third-party credential custody.

- **Requires:** WordPress 6.9+ (core Abilities API) · PHP 8.0+
- **License:** GPL-2.0-or-later
- **Author:** [PlugPress](https://plugpress.co)

---

## Why Saddle

Most "AI for WordPress" tools ask you to hand your content and credentials to a third-party cloud. Saddle is built on the opposite premise: an AI should be able to help with your site **without you giving up custody or control**.

The endpoint lives on your own site. Agents authenticate with WordPress's own Application Passwords. Nothing is relayed through servers we operate, and Saddle sends no telemetry.

## The three commitments

1. **No third-party credential custody.** Everything runs inside your WordPress install. Tokens, content, and tool-call traffic never pass through a relay or proxy. Auth uses core Application Passwords — Saddle never sees or stores a separate password.
2. **Default-safe access levels.** A fresh install starts at **Read**. Writing, deleting, and site management are powers you explicitly turn on — never things you have to turn off.
3. **Two-step confirmation on destructive actions.** Deleting or overwriting never happens in one call: the first call previews and returns a single-use token (15-minute TTL) and changes nothing; only a second call carrying that token executes. A prompt-injected agent cannot delete in a single shot.

## What your AI can do

| Area | Tools | Level |
| --- | --- | --- |
| **Content** | Posts, pages, media (incl. upload-from-URL), categories & tags, search, site info | read / write |
| **Page design** | Build & edit real Gutenberg blocks (add/edit/move/remove, patterns), block schemas, theme design tokens — builder layouts guarded | read / write |
| **Site management** | Activate/deactivate plugins, switch themes, allowlisted settings, cache flush — native PHP, never shell/eval | admin (opt-in) |
| **Guidance & memory** | Install Skills (`.md` playbooks), recent-changes recall, per-session context | read |

Every tool declares its required access level and whether it's destructive. A Read-level connection can only ever read. Sensitive settings (site URL, security keys, user roles) are never touchable, and a Saddle-issued key is scoped to Saddle's own endpoint — it can't be used against the rest of the REST API or XML-RPC.

## Skills

**Skills** are Markdown playbook files you install that teach your AI how to do specific jobs *your* way — "how we publish a post", "our SEO checklist". The skill index is always in the agent's context; the full playbook loads on demand. Only you can install a skill (owner-only, via the dashboard), and a skill is guidance — it can never grant more access than the level you chose.

```markdown
---
name: publish-a-post
description: How we publish posts on this site.
when_to_use: publishing or scheduling a post
---

# Steps
- Draft first, never publish directly.
- File under a category from list-categories.
- Keep the excerpt under 155 characters.
```

## Installation

1. Copy the plugin to `wp-content/plugins/saddle` and activate it (WordPress 6.9+, PHP 8.0+).
2. Open **Saddle** in the admin menu — the access level defaults to **Read**.
3. Under **Apps**, name a connection and click **Connect** to issue its credential.
4. Point your MCP client at the connection URL with your username and the issued Application Password.
5. Raise the access level to **Read & write** (or **Managing the site**) only when you want agents to do more.

The MCP endpoint is `POST /wp-json/saddle/v1/mcp`.

## How it works

Saddle registers its tools as WordPress [Abilities](https://make.wordpress.org/core/) and exposes them over MCP via the bundled **WordPress MCP Adapter** (`WP\MCP`, GPLv2+) — so it works with no extra plugin. If the standalone MCP Adapter plugin is active, Saddle defers to it. Each ability enforces its own access level, capability check, and — for destructive actions — the two-step approval gate.

## Development

Built with `@wordpress/scripts` (admin React app) and Composer (dev-only test deps).

```bash
# JS admin app
npm install
npm run build          # production build
npm start              # watch

# PHP tests (SQLite-backed real-WP PHPUnit)
composer install
composer test          # full suite

# Package a release zip
npm run package
```

The test suite is a real WordPress integration suite (not stubs) covering the access tiers, the approval gate, credential scoping, the block and content abilities, site management, and skills.

## Saddle Pro

[**Saddle Pro**](https://plugpress.co/saddle) adds page-builder-native editing — Divi first — so agents build real builder pages that stay fully editable in the Visual Builder, under the same access levels and confirmations. It's a separate add-on and is not required.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). The bundled `WP\MCP` adapter is GPLv2-or-later.

---

Made by [PlugPress](https://plugpress.co).
