=== Saddle – AI Site Control (MCP) ===
Contributors: badhonrocks
Tags: mcp, ai, claude, chatgpt, divi
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let Claude, ChatGPT and Cursor work on your WordPress site. Your AI starts read-only, and every delete asks first.

== Description ==

Saddle lets your AI app work on your WordPress site without handing it the keys.

Ask Claude, ChatGPT or Cursor to write a post, fix a page or update SEO titles. The app does the work through Saddle, using real WordPress tools. You decide which tools it may use.

Saddle is built for live sites. A new install can only read. Every delete shows a preview and waits for your approval. Your AI app connects straight to your site, so your content never passes through our servers.

Guides, examples and docs: [saddle.to](https://saddle.to)

= Try it now =

Click **Live Preview** at the top of this page. A test WordPress opens in your browser with Saddle installed, so you can explore every screen. The preview runs only in your browser, so AI apps cannot connect to it. To connect an app, install Saddle on your own site.

= How it works =

1. Install Saddle and choose what your AI may do.
2. Add your AI app under **Saddle → Apps**. Paste the address Saddle shows into the app and approve the connection when your browser opens.
3. Ask your AI to write, edit or check your content.

Saddle works with Claude and Claude Code, ChatGPT, Cursor, VS Code, Codex, Gemini CLI and other apps that support MCP (Model Context Protocol). Step-by-step setup for each app is at [saddle.to/docs](https://saddle.to/docs).

= Content =

* Posts and pages: Create, edit and delete posts and pages.
* Custom content types: Work with the post types your plugins and theme add, such as products, events or docs.
* Media: Upload images from a URL and edit their details.
* Categories and tags: Create and list categories and tags.
* Search: Find any post, page or media item.
* Menus: Add, rename, move and remove menu items, and choose which menu shows in each theme location.
* Users: List users and read their profiles.

= Block editor pages =

* Block pages: Build pages with real blocks that stay editable in the block editor.
* Block editing: Add, edit, move and remove single blocks.
* Theme styles: Use your theme's colors, fonts, spacing and patterns.
* Templates: Read your theme's templates, template parts and global styles.
* Design system: Add a color palette, type scale and spacing to a block theme.
* Templates and patterns: On a block theme, change a template or the header and footer, add a template part, and save a section as a pattern. Changes are saved in the database and the theme's files are never edited.

= Divi 5 pages =

These tools work on sites running Divi 5.

* Divi pages: Build and edit pages with real Divi modules. Add, edit, move and remove modules, and change page settings.
* Loops and dynamic content: Repeat a module for each post in a query, and show live post data in module fields.
* Display conditions and presets: Show or hide a module by rule, and apply a saved preset.
* Divi design data: Read global colors, fonts, variables and presets, and list Library items and Theme Builder templates.

Saddle edits Divi 5 pages only. It leaves Divi 4 shortcode pages untouched.

= Check the result =

* Page check: Find layout, contrast and accessibility problems on a block or Divi 5 page, with a score and a fix for each problem.
* Node view: See the real styles and HTML of any part of a page.
* Preview: Get a short-lived preview link to see the page before it goes live.

= Integrations =

The SEO and WooCommerce tools work when that plugin is active.

* Yoast SEO: Read and edit the SEO title, meta description, robots and schema type of posts, pages and terms.
* Rank Math: Read and edit the SEO title, meta description and robots of posts, pages and terms.
* All in One SEO: Read and edit the SEO title, meta description, robots and social fields of posts and pages.
* WooCommerce: List and read products, variations and orders.
* Unsplash: Find and import free stock photos. This needs your own Unsplash API key.
* Other plugins: Any plugin can add its own tools to Saddle. Tools from other developers stay off until you switch them on under Saddle > Integrations.

= Skills and memory =

* Skills: Add short Markdown guides that tell your AI how you work.
* Built-in Skills: Use the included guides to build a page and fix a page.
* Memory: Let your AI save notes and use them in later sessions.
* Recent changes: Let each new session see what changed on the site.

= Site management =

These tools stay off until you choose the Managing the site level.

* Settings: Change the site title, permalinks and reading options.
* Plugins and themes: Activate and deactivate plugins, and switch the active theme.
* Updates: See which plugin, theme and WordPress updates are waiting, apply plugin and theme updates through WordPress's own updater, and turn automatic updates on or off per plugin. WordPress keeps a backup and restores a plugin that breaks the site.
* Site Health: Read the results of WordPress's own Site Health checks.
* Cache: Clear the site cache.

= Safety =

* Access levels: Choose Just reading, Reading & writing, or Managing the site. New installs start at Just reading.
* Delete confirmation: Review a preview before anything is deleted or overwritten.
* Drafts only: Save new posts as drafts until you publish them.
* Rehearsal: Let an app try anything its level allows while nothing is saved. Each change it would have made shows in the activity log as rehearsed.
* Tool switches: Turn off any single tool.
* Pause: Block all AI requests with one switch.
* Activity log: See every change and every blocked request.
* Undo: Ask your AI to undo a logged change. It restores a page's earlier version, untrashes what was trashed, and puts settings back. A change that someone edited again since is left alone. Permanent deletes and plugin updates cannot be undone.
* Protected settings: The site URL, security keys, user roles and admin email cannot be changed.

Saddle never runs code from the AI. It has no shell access and does not write files.

= Connecting an app =

Turn on sign-in for apps once, when the wizard offers it. After that every app needs only your site's address: it opens your browser, and an administrator approves it on your own site. Nothing is installed and no key is copied. Sign-in needs HTTPS and pretty permalinks.

Apps can also connect with a pasted key. Saddle uses WordPress Application Passwords for that. Each key works only with Saddle, not with the rest of the REST API. ChatGPT cannot use a pasted key.

= Saddle Pro =

[Saddle Pro](https://saddle.to) is a paid add-on for Divi 5 sites. It needs this free plugin. Pro adds work that changes more than one page:

* Divi design system: Create, edit and delete global colors, fonts, variables and presets.
* Divi Library and Theme Builder: Save, update, apply and delete Library items, and assign Theme Builder templates.
* Whole pages: Build a new Divi page in one step, or clone one.
* Site-wide changes: Swap an image or apply a preset across many pages, with undo.
* Design brief: Save a page's design plan and check the page against it.
* WooCommerce: Update prices, stock, status and categories across many products, with undo.

Every feature in this free plugin stays free.

== Installation ==

1. Install and activate Saddle from **Plugins → Add New**.
2. Go to **Saddle → Apps**, turn on sign-in for apps, and pick your app.
3. Paste the address into your AI app and approve the connection when your browser opens.
4. To allow changes, go to **Saddle → Permissions** and choose Reading & writing or Managing the site.

== Frequently Asked Questions ==

= Do I need an account? =

No. Saddle is free and runs on your own site.

= Does my content go through your servers? =

No. Your AI app connects to your site directly. Saddle sends no tracking data.

= Can I try Saddle before installing it? =

Yes. Click Live Preview on this page. You can explore every screen. To connect an AI app, install Saddle on a real site, because the preview runs only in your browser.

= Can the AI delete my content? =

Only at the Reading & writing level or higher. Each delete shows a preview first. It runs only after a second confirmation. By default, deleted posts go to the trash.

= Can the AI run code on my server? =

No. Every action uses standard WordPress functions.

= Does it work with ChatGPT? =

Yes. Go to **Saddle → Apps**, pick ChatGPT, and turn on sign-in when the wizard offers it. Then add your site as a connector in ChatGPT and approve it when ChatGPT sends you to your site.

= Does it work with page builders? =

Saddle edits Divi 5 pages with real Divi modules. It protects layouts from other page builders against accidental overwrites.

= Do I need the MCP Adapter plugin? =

No. Saddle works on its own. If MCP Adapter is active, Saddle uses it.

== External services ==

Saddle sends no tracking or usage data. It connects to another site only in these cases:

1. Upload from URL: WordPress downloads the one file you asked for.
2. Connection check: Saddle sends a test request to your own site.
3. Live page check (optional): This runs only when your AI app asks for it after editing a published page. Saddle loads that page from your own site, as a visitor would, to confirm the change is live.
4. Unsplash (optional): This runs only after you add your own API key under **Saddle → Integrations**. Saddle sends your search words or a photo ID to `api.unsplash.com`. It downloads photos from `images.unsplash.com`. Each imported photo gets a caption that credits the photographer, as Unsplash requires. You can edit or remove it. See the [API Guidelines](https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines), [API Terms](https://unsplash.com/api-terms) and [Privacy Policy](https://unsplash.com/privacy).
5. OAuth app check (optional): This runs only when OAuth sign-in is on. Saddle reads a public web address the app provides to confirm who the app is. It sends nothing about your site.
6. Updates (optional): When your AI app lists or applies updates, WordPress runs its own update check and downloads the update packages it already offers, from WordPress.org or a plugin's own update server, exactly as the Updates screen does. After updating an active plugin, WordPress loads your own home page once to check for a fatal error.

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

= Unreleased =
* Improved: Connecting an app now takes one address. Turn on sign-in for apps once, paste the address into Claude, Claude Code, ChatGPT, Codex, Cursor, VS Code, Gemini CLI or Windsurf, and approve the connection when your browser opens. No key to copy and nothing to install. Pasted keys still work and stay the fallback for sites without HTTPS or pretty permalinks.
* New: Claude on the web and in the desktop app connects as a custom connector, with no bridge to install. Windsurf joins the app list.
* New: Cursor and VS Code connect in one click. The connect screen shows an Add to Cursor or Add to VS Code button that opens the app with the server filled in. The copy-and-paste setup stays as the fallback.
* New: Undo. Your AI app can reverse changes from the activity log: a page's earlier content and settings, trashed posts, created posts and tags, settings, the theme and plugin activation. It previews what comes back and waits for your approval. A change that was edited again since is skipped with the reason, and the undo can itself be undone.
* New: Rehearsal mode. Turn it on in Saddle > Permissions and every tool that would change your site answers with what it would have done, and saves nothing. Reading works as usual, and each attempt shows in Activity as rehearsed. It is off by default.
* New: Custom content types. Your AI app can list, read, create, edit and trash items of the custom post types your plugins and theme add, such as products, events or docs, and assign their own categories. Each type keeps its own permissions. Saddle manages the types that appear in wp-admin.
* Fixed: On a site with the trash turned off, the delete preview no longer says a post or page can be restored.
* New: Menus. Your AI app can list menus and read their items, add links to pages, posts, categories or any URL, rename and reorder items, remove an item after a preview, and assign a menu to a theme location. This covers classic themes, which have no navigation block.
* New: Templates, parts and patterns on block themes. Your AI app can replace a template or the header or footer after a preview of what changes, create a new template part, and save a section of a page as a pattern. Everything is saved in the database; the theme's files are never edited, and the Site Editor can reset a template to the theme's version.
* New: The page check flags links and buttons a screen reader can't name, link text like "click here", titles long enough to be cut off in search results, and published posts without an excerpt.
* New: Your Skills appear as prompts. Apps that show MCP prompts, such as Claude Desktop, Cursor and VS Code, list each enabled Skill as a slash command. They follow the same rules as reading a Skill: hidden while Saddle is paused, and gone when the Skill is turned off.
* Changed: The Saddle screens use saddle.to's olive brand colour instead of magenta. Buttons and links are easier to read: 6.7:1 contrast on white, up from 5.2.
* New: Your AI app can apply plugin and theme updates that WordPress is already offering. It previews every item first and waits for your approval, then WordPress's own updater runs the update in the background, keeps a backup, and restores an active plugin that causes a fatal error. It can also turn WordPress's automatic updates on or off per plugin or theme, and read the Site Health checks. Saddle never installs or deletes a plugin and never updates WordPress itself.
* New: Saddle Analytics, PlugPress's analytics plugin, counts as a PlugPress integration. Its read-only traffic tools work as soon as it is active, with no switch to turn on.
* New: Saddle Rank, the new name for PlugPress's Waggle, is trusted like Waggle was: its tools reach your AI app as the saddle-rank tools with nothing to approve, and any Waggle tool you had switched off stays off after the rename.
* Fixed: With Saddle Rank installed, Saddle could count its own Rank Math tools as Saddle Rank's when telling your AI app what is available, and list them under Integrations on sites without Rank Math.
* Fixed: The live page check now compares each part of the page on its own, so it names exactly what a stale cache is still missing.
* Fixed: Editing a list's items with a single block edit left an empty list followed by the old items. The new items now replace the old ones inside the list, and the page check flags any block whose inner blocks render outside it.
* Fixed: With Saddle active, Gravity Forms' "Site MCP" mode and other plugins that share the MCP Adapter's default server lost their MCP connection. Saddle now leaves that server running when another plugin uses it, and keeps its own tools off it.
* Fixed: The Connection check (Saddle > Settings, and the "Check the connection" tool) could not tell whether your server passes sign-in headers through, and always said "unknown". It now gives a real answer.

= 1.4.0 =
* New: Your AI app can check that a change is live. It loads the published page as a visitor would and says when a page cache is still serving the old version.
* New: Your AI app can upload a file it has no public link for, such as an image it made. It sends the file itself, and WordPress checks it like any other upload.
* New: A "Check the connection" tool. Your AI app can find out why tools are missing or why another app cannot connect, and tell you how to fix it.
* Fixed: OAuth sign-in from Claude Code and other apps that run on your own computer no longer fails when the app uses a different port than last time. Only the port may change; every other part of the return address must still match.
* Fixed: On Divi 5 sites, the design summary your AI app receives now includes the site's heading and body fonts. It was always empty.
* Fixed: Page lists now include each page's slug, parent and menu order, as the tool description already said. Post lists include the slug.

= 1.3.0 =
* New: Other plugins can add their own tools to Saddle. Tools from other developers stay off until you switch them on under Saddle > Integrations.
* Improved: The Integrations screen shows each plugin's author, how many tools it adds, and whether it is built in, from PlugPress or from another developer.

= 1.2.2 =
* Fixed: The MCP endpoint returned 404 when another plugin bundled the WordPress MCP Adapter without starting it, such as Gravity Forms 3.1. Saddle now starts the adapter itself, and falls back to its own transport if the adapter does not serve the endpoint.

= 1.2.1 =
* New: Live Preview. Try Saddle in a test WordPress in your browser, straight from this page.
* Improved: A clearer, rewritten description. Saddle is a plugin your AI app connects to, not a server.

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
