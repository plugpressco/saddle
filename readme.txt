=== Saddle – AI Site Control (MCP Server) ===
Contributors: badhonrocks
Tags: mcp, ai, chatgpt, agents, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT, Cursor and other AI apps to your WordPress site. Your AI starts read-only and asks before it deletes.

== Description ==

Saddle turns your WordPress site into an MCP server. AI apps like Claude, ChatGPT and Cursor can then read and edit your site.

It helps site owners, developers and agencies who already work with an AI app. Saddle runs on your own site. It needs no account and no cloud service.

= Works with =

* Claude and Claude Code
* ChatGPT
* Cursor and VS Code
* Codex and Gemini CLI
* Other apps that support MCP

= Content =

* Posts and pages: Create, edit and delete posts and pages.
* Media: Upload images from a URL and edit their details.
* Categories and tags: Create and list categories and tags.
* Search: Find any post, page or media item.
* Users: List users and read their profiles.

= Page building =

* Block pages: Build pages with real blocks that stay editable in the block editor.
* Block editing: Add, edit, move and remove single blocks.
* Theme styles: Use your theme's colors, fonts, spacing and patterns.
* Templates: Read your theme's templates, template parts and global styles.
* Design system: Add a color palette, type scale and spacing to a block theme.
* Page check: Find layout and design problems on a page.
* Preview: Get a preview link to see the result.

= Site management =

This group is off until you choose the Managing the site level.

* Settings: Change the site title, permalinks and reading options.
* Plugins: Activate and deactivate plugins.
* Themes: Switch the active theme.
* Cache: Clear the site cache.

= Skills and memory =

* Skills: Add short Markdown guides that tell your AI how you work.
* Built-in Skills: Use the included guides to build a page and fix a page.
* Memory: Let your AI save notes and use them in later sessions.
* Recent changes: Let each new session see what changed on the site.

= Safety =

* Access levels: Choose Read, Read & write, or Managing the site. New installs start at Read.
* Delete confirmation: Review a preview before anything is deleted or overwritten.
* Drafts only: Save new posts as drafts until you publish them.
* Tool switches: Turn off any single tool.
* Pause: Block all AI requests with one switch.
* Activity log: See every change and every blocked request.
* Protected settings: The site URL, security keys, user roles and admin email cannot be changed.

Saddle never runs code from the AI. It has no shell access and does not write files.

= Connecting an app =

Saddle uses WordPress Application Passwords. Each key only works with Saddle, not the rest of the REST API.

ChatGPT cannot use a pasted key. For ChatGPT, turn on OAuth sign-in under **Saddle → Settings**. An administrator must approve each app.

= Unsplash =

Unsplash search: Find and import free stock photos. This needs your own Unsplash API key.

= Divi 5 =

These tools work on sites running Divi 5.

* Divi pages: Build and edit Divi 5 pages with real Divi modules. Add, edit, move and remove modules, and change page settings.
* Loops and dynamic content: Repeat a module for each post in a query, and show live post data in module fields.
* Display conditions: Show or hide a module by rule.
* Presets: Apply a saved Divi preset to a module.
* Design system: Read Divi global colors, fonts, variables and presets.
* Library and Theme Builder: List saved Library items and Theme Builder templates.
* Page check: Check a Divi 5 page for structure and design problems, and see what a module renders.

Saddle only edits Divi 5 pages. It leaves Divi 4 shortcode pages untouched.

= SEO plugins =

These tools work when the SEO plugin is active.

* Yoast SEO: Read and edit the SEO title, meta description, robots and schema type of posts, pages and terms.
* Rank Math: Read and edit the SEO title, meta description and robots of posts, pages and terms.
* All in One SEO: Read and edit the SEO title, meta description, robots and social fields of posts and pages.

= WooCommerce =

* Products and orders: List and read products, variations and orders. This works when WooCommerce is active.

= Saddle Pro =

[Saddle Pro](https://saddle.to) is a paid add-on. It needs this free plugin. Pro adds:

* Divi design system: Create, edit and delete Divi global colors, fonts, variables and presets.
* Divi Library and Theme Builder: Save, update, apply and delete Library items, and assign Theme Builder templates.
* Whole pages: Build a new Divi page in one step, or clone one.
* Site-wide changes: Swap an image or apply a preset across many pages, with undo.
* Design brief: Save a page's design plan and check the page against it.
* WooCommerce: Update prices, stock, status and categories across many products, with undo.

All features in this free plugin stay free.

== Installation ==

1. Install and activate Saddle from **Plugins → Add New**.
2. Go to **Saddle → Apps** and add an app.
3. Copy the connection settings into your AI app.
4. To allow changes, go to **Saddle → Permissions** and choose a higher level.

== Frequently Asked Questions ==

= Do I need an account? =

No. Saddle is free and runs on your own site.

= Does my content go through your servers? =

No. Your AI app connects to your site directly. Saddle sends no tracking data.

= Can the AI delete my content? =

Only at the Read & write level or higher. Each delete shows a preview first. It runs only after a second confirmation. By default, deleted posts go to the trash.

= Can the AI run code on my server? =

No. Every action uses standard WordPress functions.

= Does it work with ChatGPT? =

Yes. Turn on OAuth sign-in under **Saddle → Settings**. Then add your site as a connector in ChatGPT.

= Does it work with page builders? =

Saddle edits Divi 5 pages with real Divi modules. It protects layouts from other page builders against accidental overwrites.

= Do I need the MCP Adapter plugin? =

No. Saddle works on its own. If MCP Adapter is active, Saddle uses it.

== External services ==

Saddle sends no tracking or usage data. It connects to another site only in these cases:

1. Upload from URL: WordPress downloads the one file you asked for.
2. Connection check: Saddle sends a test request to your own site.
3. Unsplash (optional): This runs only after you add your own API key under **Saddle → Integrations**. Saddle sends your search words or a photo ID to `api.unsplash.com`. It downloads photos from `images.unsplash.com`. Each imported photo gets a caption that credits the photographer, as Unsplash requires. You can edit or remove it. See the [API Guidelines](https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines), [API Terms](https://unsplash.com/api-terms) and [Privacy Policy](https://unsplash.com/privacy).
4. OAuth app check (optional): This runs only when OAuth sign-in is on. Saddle reads a public web address the app provides to confirm who the app is. It sends nothing about your site.

The WordPress.org version never checks for its own updates. The version from plugpress.co checks at most once every six hours. It sends only the plugin name and version number.

== Privacy ==

* Saddle stores its settings, Skills, memory, activity log and short-lived confirmation codes on your site.
* With OAuth on, it also stores approved apps and a one-way hash of their tokens.
* Saddle sends no personal data off your site.
* Uninstalling removes all Saddle data. Remove Application Passwords yourself under **Users → Profile**.

== Screenshots ==

1. Permissions: Choose what your AI can do.
2. Apps: Connect an AI app.
3. Activity: See every change and every blocked request.
4. Instructions: Read what your AI is told, and add your own instructions and Skills.

== Changelog ==

= 1.2.0 =
* New: Divi 5 pages. Build and edit Divi 5 pages with real Divi modules: add, edit, move and remove modules, change page settings, loops, dynamic content, display conditions and presets.
* New: Page check for Divi 5. Lint, verify and render-node work on Divi 5 pages.
* New: Read Divi global colors, fonts, variables and presets, and list Library items and Theme Builder templates.
* New: Yoast SEO, Rank Math and All in One SEO. Read and edit SEO titles, descriptions, robots and social fields.
* New: WooCommerce. List and read products, variations and orders.
* Improved: Skills that ship with more than one plugin are listed once.

= 1.0.1 =
* New: Drafts-only mode. New posts save as drafts, and publishing needs a confirmation.

= 1.0.0 =
* Initial release.
