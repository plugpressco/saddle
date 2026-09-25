# Control Your WordPress Site with AI Using Saddle

Saddle connects AI apps to your WordPress site through MCP. Your AI can read and edit posts, pages and media. It starts read-only and asks before it deletes.

- **Requires:** WordPress 6.9 or later, PHP 7.4 or later
- **Website:** [saddle.to](https://saddle.to)
- **Download:** [WordPress.org](https://wordpress.org/plugins/saddle/)
- **License:** GPL-2.0-or-later
- **Author:** [PlugPress](https://plugpress.co)

## Who it is for

Saddle helps site owners, developers and agencies who already use an AI app. It runs on your own site. You need no account and no cloud service.

It works with:

- Claude and Claude Code
- ChatGPT
- Cursor and VS Code
- Codex and Gemini CLI
- Any other app that supports [MCP](https://modelcontextprotocol.io)

## Features

### Content

- **Posts and pages:** Create, edit and delete posts and pages.
- **Media:** Upload images from a URL and edit their details.
- **Categories and tags:** Create and list categories and tags.
- **Search:** Find posts, pages and media.
- **Users:** List users and read their profiles.

### Page building

- **Block pages:** Build pages with blocks you can still edit in the block editor.
- **Block editing:** Add, edit, move and remove single blocks.
- **Theme styles:** Use your theme's colors, fonts, spacing and patterns.
- **Templates:** Read your theme's templates, template parts and global styles.
- **Design system:** Add a color palette, type scale and spacing to a block theme.
- **Page check:** Find layout and design problems on a page.
- **Preview:** Get a preview link for a page.

### Site management

These tools stay off until you choose the **Managing the site** level.

- **Settings:** Change the site title, permalinks and reading options.
- **Plugins:** Activate and deactivate plugins.
- **Themes:** Switch the active theme.
- **Cache:** Clear the site cache.

### Skills and memory

- **Skills:** Add Markdown guides that tell your AI how you work.
- **Built-in Skills:** Use the included guides to build a page or fix a page.
- **Memory:** Let your AI save notes for later sessions.
- **Recent changes:** Show each new session what changed on the site.

### Unsplash

- **Unsplash search:** Find and import free stock photos. You need your own Unsplash API key.

## Safety

- **Access levels:** Choose Read, Read & write, or Managing the site. New installs start at Read.
- **Delete confirmation:** See a preview before anything is deleted or overwritten.
- **Drafts only:** Save new posts as drafts until you publish them.
- **Tool switches:** Turn off any single tool.
- **Pause:** Block all AI requests with one switch.
- **Activity log:** See every change and every blocked request.
- **Protected settings:** The AI cannot change the site URL, security keys, user roles or admin email.

Saddle never runs code from the AI. It has no shell access. It does not write files.

A delete or overwrite takes two calls. The first call shows a preview and returns a one-time token. The second call must send that token. The token expires after 15 minutes.

## Getting started

1. Install and activate Saddle from **Plugins → Add New**.
2. Go to **Saddle → Apps** and add an app.
3. Copy the connection settings into your AI app.
4. To allow changes, go to **Saddle → Permissions** and choose a higher level.

The MCP endpoint is `POST /wp-json/saddle/v1/mcp`.

### Connecting an app

Saddle uses WordPress Application Passwords. Each key works only with Saddle. It cannot reach the rest of the REST API.

ChatGPT cannot use a pasted key. Turn on OAuth sign-in under **Saddle → Settings** instead. An administrator must approve each app.

## Writing a Skill

A Skill is a Markdown file. Only an administrator can add one. A Skill cannot give the AI more access than the level you chose.

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

`readme.txt` lists every outside request Saddle can make.

## Saddle Pro

[Saddle Pro](https://saddle.to) is a paid add-on. It needs this free plugin. Pro adds:

- **Divi 5 pages:** Build and edit Divi 5 pages with Divi modules.
- **Divi design:** Use Divi global colors, fonts, variables and presets.
- **Divi features:** Manage loops, dynamic content, display conditions, the Library and the Theme Builder.
- **SEO plugins:** Read and edit SEO fields in Yoast SEO, Rank Math and All in One SEO.
- **WooCommerce:** List and read products.

Every feature in the free plugin stays free.

## How it works

Saddle registers each tool as a WordPress Ability. A bundled copy of the WordPress MCP Adapter serves these tools over MCP. You do not need to install anything else. If the MCP Adapter plugin is active, Saddle uses it instead.

Each tool checks the access level and the user's capabilities. Delete tools also need the two-step confirmation.

## Development

The admin app uses `@wordpress/scripts`. Composer installs the test tools.

```bash
# Admin app
npm install
npm run build          # production build
npm start              # watch mode

# PHP tests (WordPress on SQLite)
composer install
composer test

# Build the zip without changing the version
npm run build && npx grunt build
```

Do not run `npm run package` or `grunt release` unless you are making a release. Both change the version number.

The tests run inside WordPress. They cover access levels, delete confirmation, key limits, block and content tools, site management and Skills.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). The bundled MCP Adapter (`WP\MCP`) is GPLv2 or later.

Made by [PlugPress](https://plugpress.co).
