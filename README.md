# Control Your WordPress Site with AI Using Saddle

Connect Claude, ChatGPT, Cursor and other AI apps to your WordPress site. Your AI starts read-only and asks before it deletes.

- **Requires:** WordPress 6.9 or later, PHP 7.4 or later
- **License:** GPL-2.0-or-later
- **Website:** [saddle.to](https://saddle.to)
- **Download:** [WordPress.org](https://wordpress.org/plugins/saddle/)
- **Author:** [PlugPress](https://plugpress.co)

---

## What Saddle does

Saddle turns your WordPress site into an [MCP](https://modelcontextprotocol.io) server. AI apps can then read and edit your site.

It helps site owners, developers and agencies who already work with an AI app. Saddle runs on your own site. It needs no account and no cloud service.

### Works with

- Claude and Claude Code
- ChatGPT
- Cursor and VS Code
- Codex and Gemini CLI
- Other apps that support MCP

## Features

### Content

- **Posts and pages:** Create, edit and delete posts and pages.
- **Media:** Upload images from a URL and edit their details.
- **Categories and tags:** Create and list categories and tags.
- **Search:** Find any post, page or media item.
- **Users:** List users and read their profiles.

### Page building

- **Block pages:** Build pages with real blocks that stay editable in the block editor.
- **Block editing:** Add, edit, move and remove single blocks.
- **Theme styles:** Use your theme's colors, fonts, spacing and patterns.
- **Templates:** Read your theme's templates, template parts and global styles.
- **Design system:** Add a color palette, type scale and spacing to a block theme.
- **Page check:** Find layout and design problems on a page.
- **Preview:** Get a preview link to see the result.

### Site management

This group is off until you choose the **Managing the site** level.

- **Settings:** Change the site title, permalinks and reading options.
- **Plugins:** Activate and deactivate plugins.
- **Themes:** Switch the active theme.
- **Cache:** Clear the site cache.

### Skills and memory

- **Skills:** Add short Markdown guides that tell your AI how you work.
- **Built-in Skills:** Use the included guides to build a page and fix a page.
- **Memory:** Let your AI save notes and use them in later sessions.
- **Recent changes:** Let each new session see what changed on the site.

### Unsplash

- **Unsplash search:** Find and import free stock photos. This needs your own Unsplash API key.

## Safety

- **Access levels:** Choose Read, Read & write, or Managing the site. New installs start at Read.
- **Delete confirmation:** Review a preview before anything is deleted or overwritten.
- **Drafts only:** Save new posts as drafts until you publish them.
- **Tool switches:** Turn off any single tool.
- **Pause:** Block all AI requests with one switch.
- **Activity log:** See every change and every blocked request.
- **Protected settings:** The site URL, security keys, user roles and admin email cannot be changed.

Saddle never runs code from the AI. It has no shell access and does not write files.

A delete or overwrite always takes two calls. The first call returns a preview and a single-use token, and changes nothing. The token expires after 15 minutes. Only a second call with that token makes the change.

## Getting started

1. Install and activate Saddle from **Plugins → Add New**.
2. Go to **Saddle → Apps** and add an app.
3. Copy the connection settings into your AI app.
4. To allow changes, go to **Saddle → Permissions** and choose a higher level.

### Connecting an app

Saddle uses WordPress Application Passwords. Each key only works with Saddle, not the rest of the REST API.

ChatGPT cannot use a pasted key. For ChatGPT, turn on OAuth sign-in under **Saddle → Settings**. An administrator must approve each app.

The MCP endpoint is `POST /wp-json/saddle/v1/mcp`.

## Skills

A Skill is a Markdown file that tells your AI how to do a job your way. Only an administrator can add one. A Skill is guidance. It never gives the AI more access than the level you chose.

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

## Privacy

- Your AI app connects to your site directly. Nothing passes through our servers.
- Saddle sends no tracking or usage data.
- Saddle stores its settings, Skills, memory and activity log on your site.
- Uninstalling removes all Saddle data.

See `readme.txt` for the full list of external requests.

## Saddle Pro

[Saddle Pro](https://saddle.to) is a paid add-on. It needs this free plugin. Pro adds:

- **Divi 5 pages:** Build and edit Divi 5 pages with real Divi modules.
- **Divi design:** Use Divi global colors, fonts, variables and presets.
- **Divi features:** Manage loops, dynamic content, display conditions, the Library and the Theme Builder.
- **SEO plugins:** Read and edit SEO fields in Yoast SEO, Rank Math and All in One SEO.
- **WooCommerce:** List and read products.

All features in this free plugin stay free.

## How it works

Saddle registers each tool as a WordPress [Ability](https://make.wordpress.org/core/). The bundled WordPress MCP Adapter (`WP\MCP`, GPLv2 or later) serves them over MCP, so no extra plugin is needed. If the MCP Adapter plugin is active, Saddle uses it instead.

Each tool checks its own access level and user capability. Destructive tools also go through the two-step confirmation.

## Development

The admin app uses `@wordpress/scripts`. Test dependencies come from Composer.

```bash
# Admin app
npm install
npm run build          # production build
npm start              # watch

# PHP tests (real WordPress on SQLite)
composer install
composer test

# Build the release zip without changing the version
npm run build && npx grunt build
```

Do not run `npm run package` or `grunt release` unless you are cutting a release. Both bump the version.

The tests run against a real WordPress install. They cover access levels, delete confirmation, key scoping, block and content tools, site management and Skills.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). The bundled `WP\MCP` adapter is GPLv2 or later.

---

Made by [PlugPress](https://plugpress.co).
