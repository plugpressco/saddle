=== Saddle – AI Site Control (MCP) ===
Contributors: badhonrocks
Tags: mcp, ai, claude, chatgpt, divi
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.1
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
2. Add your AI app under **Saddle → AI apps**. Paste the address Saddle shows into the app and approve the connection when your browser opens.
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
* WooCommerce: List and read products and variations. Orders show customer names and emails, so listing them needs Edit content.
* Unsplash: Find and import free stock photos. This needs your own Unsplash API key.
* Other plugins: Any plugin can add its own tools to Saddle. Tools from other developers stay off until you switch them on under Saddle > Services.

= Skills and memory =

* Skills: Add short Markdown guides that tell your AI how you work.
* Built-in Skills: Use the included guides to build a page and fix a page.
* Memory: Let your AI save notes and use them in later sessions.
* Recent changes: Let each new session see what changed on the site.

= Site management =

These tools stay off until you give an app Manage the site.

* Settings: Change the site title, permalinks and reading options.
* Plugins and themes: Activate and deactivate plugins and switch the active theme, each after a preview.
* Updates: See which plugin, theme and WordPress updates are waiting, apply plugin and theme updates through WordPress's own updater, and turn automatic updates on or off per plugin. WordPress keeps a backup and restores a plugin that breaks the site.
* Site Health: Read the results of WordPress's own Site Health checks.
* Cache: Clear the site cache.

= Safety =

* Access for each app: Choose Read only, Edit content or Manage the site for each connected app. New apps start at Read only.
* Confirmation: Review a preview before anything is deleted or overwritten, a plugin is turned on or off, or the theme changes.
* Drafts only: Save new posts as drafts until you publish them.
* Rehearsal: Let an app try anything its level allows while nothing is saved. Each change it would have made shows in the activity log as rehearsed.
* Tool switches: Turn off any single tool.
* Pause: Block all AI requests with one switch.
* Activity log: See every change and every blocked request.
* Undo: Ask your AI to undo a logged change, or click Undo on it in Home's Activity feed. It restores a page's earlier version, untrashes what was trashed, and puts settings back. A change that someone edited again since is left alone. Permanent deletes and plugin updates cannot be undone.
* Protected settings: The site URL, security keys, user roles and admin email cannot be changed.

Saddle never runs code from the AI and has no shell access. Your AI app cannot write or edit files on your server. WordPress itself still saves uploads, applies updates and saves permalink rules the way it always does. The one file Saddle changes is .htaccess, and only when you click Fix it for me in the connection check. That adds one marked block so your server passes sign-in headers to WordPress. Uninstalling Saddle removes it.

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

= Credits =

* Iconoir icons (MIT): [iconoir.com](https://iconoir.com)
* AI app logos from LobeHub Icons (MIT): [github.com/lobehub/lobe-icons](https://github.com/lobehub/lobe-icons). Each logo belongs to its owner.

== Installation ==

1. Install and activate Saddle from **Plugins → Add New**.
2. Go to **Saddle → AI apps**, click Connect an app, and pick your app.
3. Paste the address into your AI app and approve the connection when your browser opens.
4. To allow changes, choose Edit content or Manage the site next to the app on **Saddle → AI apps**.

== Frequently Asked Questions ==

= Do I need an account? =

No. Saddle is free and runs on your own site.

= Does my content go through your servers? =

No. Your AI app connects to your site directly. Saddle sends no tracking data.

= Can I try Saddle before installing it? =

Yes. Click Live Preview on this page. You can explore every screen. To connect an AI app, install Saddle on a real site, because the preview runs only in your browser.

= Can the AI delete my content? =

Only for an app with Edit content or higher. Each delete shows a preview first. It runs only after a second confirmation. By default, deleted posts go to the trash.

= Can the AI run code on my server? =

No. Every action uses standard WordPress functions.

= Does it work with ChatGPT? =

Yes. Go to **Saddle → AI apps**, click Connect an app, pick ChatGPT, and turn on sign-in when Saddle offers it. Then add your site as a connector in ChatGPT and approve it when ChatGPT sends you to your site.

= Does it work with page builders? =

Saddle edits Divi 5 pages with real Divi modules. It protects layouts from other page builders against accidental overwrites.

= Do I need the MCP Adapter plugin? =

No. Saddle works on its own. If MCP Adapter is active, Saddle uses it.

== External services ==

Saddle sends no tracking or usage data. It connects to another site only in these cases:

1. Upload from URL: WordPress downloads the one file you asked for.
2. Connection check: Saddle sends a test request to your own site.
3. Live page check (optional): This runs only when your AI app asks for it after editing a published page. Saddle loads that page from your own site, as a visitor would, to confirm the change is live.
4. Unsplash (optional): This runs only after you add your own API key under **Saddle → Services**. Saddle sends your search words or a photo ID to `api.unsplash.com`. It downloads photos from `images.unsplash.com`. Each imported photo gets a caption that credits the photographer, as Unsplash requires. You can edit or remove it. See the [API Guidelines](https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines), [API Terms](https://unsplash.com/api-terms) and [Privacy Policy](https://unsplash.com/privacy).
5. OAuth app check (optional): This runs only when OAuth sign-in is on. Saddle reads a public web address the app provides to confirm who the app is. It sends nothing about your site.
6. Updates and Site Health (optional): When your AI app lists or applies updates, or reads Site Health, WordPress runs its own checks with WordPress.org and downloads the update packages it already offers, from WordPress.org or a plugin's own update server, exactly as the Updates screen does. After updating an active plugin, WordPress loads your own home page once to check for a fatal error.

The WordPress.org version never checks for its own updates. The version from plugpress.co checks at most once every six hours. It sends only the plugin name and version number.

== Privacy ==

* Saddle stores its settings, Skills, memory, activity log and short-lived confirmation codes on your site.
* With OAuth on, it also stores approved apps and a one-way hash of their tokens.
* For each connected app, it stores the name the app reports, when it connected and last called, and the names of its last five tools. It stores no tool inputs or results.
* Saddle sends no personal data off your site.
* Deactivating or deleting Saddle deletes the app keys it made, because nothing limits them while Saddle is off. Apps that connected with a key need to connect again after you turn Saddle back on. Keys you made yourself stay.
* Uninstalling removes all Saddle data.

== Screenshots ==

1. Home: see what your AI changed and undo it, what waits for your OK, and what each app may do.
2. AI apps: connect Claude, ChatGPT, Claude Code, Cursor and other MCP apps.
3. Needs your OK: deletes and plugin changes wait for you. See what an app wants, then approve or reject it.
4. Context: tell every app about your site, its voice and its rules, and add Skills.
5. Settings: choose the safety switches, sign-in for apps and advanced options.
6. Welcome: Saddle looks around your site and helps you connect your first AI app.

== Changelog ==

= 1.5.1 =
* New: A plugin that adds a page under Saddle can draw it as one page, with no sidebar and no tab row. Its settings open from the page and lead back to it. Saddle Analytics uses this.
* Changed: On a plugin's page under Saddle, such as SEO or CRM, the sections sit in a white panel on the left, and the page starts right beside it instead of in the middle of the screen.

= 1.5.0 =
* New: Each connected app has its own access. On AI apps, choose Read only, Edit content (posts, pages, media, menus and SEO) or Manage the site (also plugins, themes, updates and settings) for each app. A new app starts at Read only. Updating Saddle keeps what each existing app could already do and never widens it.
* New: Needs your OK. When an app asks to make a big change, such as publishing or deleting, you can approve or reject it on Home as well as in the chat. The request shows what would change. Only the app that asked can confirm it, and a request leaves the list once its app no longer has the access it needs.
* New: Undo from Home. An Undo link on a change in the Activity feed shows what comes back, then asks you to confirm. A change edited since, a permanent delete or a plugin update says why it can't be undone.
* New: Undo. Your AI app can reverse changes from the activity log: a page's earlier content and settings, trashed posts, created posts and tags, settings, the theme and plugin activation. It previews what comes back and waits for your approval. A change that was edited again since is skipped with the reason, and the undo can itself be undone.
* New: Practice mode. Turn it on in Saddle > Settings and every tool that would change your site answers with what it would have done, and saves nothing. Reading works as usual, and each attempt shows in Activity as rehearsed. It is off by default.
* New: Custom content types. Your AI app can list, read, create, edit and trash items of the custom post types your plugins and theme add, such as products, events or docs, and assign their own categories. Each type keeps its own permissions. Saddle manages the types that appear in wp-admin.
* New: Menus. Your AI app can list menus and read their items, add links to pages, posts, categories or any URL, rename and reorder items, remove an item after a preview, and assign a menu to a theme location. This covers classic themes, which have no navigation block.
* New: Templates, parts and patterns on block themes. Your AI app can replace a template or the header or footer after a preview of what changes, create a new template part, and save a section of a page as a pattern. Everything is saved in the database. The theme's files are never edited, and the Site Editor can reset a template to the theme's version.
* New: Plugin and theme updates. Your AI app can apply updates that WordPress is already offering. It previews every item first and waits for your approval. Then WordPress's own updater runs the update in the background, keeps a backup, and restores an active plugin that causes a fatal error. Your AI app can also turn automatic updates on or off per plugin or theme, and read the Site Health checks. Saddle never installs or deletes a plugin and never updates WordPress itself.
* New: The page check flags links and buttons a screen reader can't name, link text like "click here", titles long enough to be cut off in search results, and published posts without an excerpt.
* New: Your AI app can check that a change is live. It loads the published page as a visitor would and says when a page cache is still serving the old version.
* New: Your AI app can upload a file it has no public link for, such as an image it made. It sends the file itself, and WordPress checks it like any other upload.
* New: Your AI app can build with blocks from other plugins that hold other blocks and render on the server, such as a section block with a heading and a paragraph inside. The block schema says which blocks each one accepts.
* New: A "Check the connection" tool. Your AI app can find out why tools are missing or why another app cannot connect, and tell you how to fix it.
* New: Your Skills appear as prompts. Apps that show MCP prompts, such as Claude Desktop, Cursor and VS Code, list each enabled Skill as a slash command. They are hidden while Saddle is paused, and gone when the Skill is turned off.
* New: The Context page. Tell every connected app about your site in five fields: about this site, current goal, voice and style, rules, and other instructions. Skills, memory and what Saddle tells every app automatically are on the same page.
* New: Your AI app can list Saddle's pages and read their settings, then give you a link to the right screen instead of describing clicks. It can ask to change a setting, shows you the change first, and the change can be undone. It can never change its own access, pause, practice mode, publishing approval or sign-in.
* Improved: Connecting an app takes one address. Turn on sign-in for apps once, paste the address into Claude, Claude Code, ChatGPT, Codex, Cursor, VS Code, Gemini CLI or Windsurf, and approve the connection when your browser opens. No key to copy and nothing to install. Pasted keys still work and stay the fallback for sites without HTTPS or pretty permalinks.
* Improved: Claude on the web and in the desktop app connects as a custom connector, with no bridge to install. Claude, Cursor and VS Code also get an Add button that opens the app with your site filled in. OpenClaw, Grok and Windsurf join the app list.
* Changed: Saddle is one menu in wp-admin with five pages: Home, AI apps, Services, Context and Settings. Each page and each tab has an icon. Every page has a header that reads "Saddle / Page" and a switch that pauses all apps at once.
* Changed: Home shows anything waiting for your OK, what changed this week, recent activity, your connected apps and any modules. Until an app is connected, it shows one step: connect an app.
* Changed: First run is a short conversation. It reads your site, helps you connect your AI app, and has you paste a read-only prompt to watch it work. It asks what the app may do only after the app connects, and skipping keeps it at Read only. If the app has not connected after two minutes, Saddle checks HTTPS, permalinks and the sign-in header, and offers a key instead. Settings > Run setup again opens it at any time.
* Changed: AI apps lists your connected apps by the name each app reports, with its access and a menu to rotate its key or disconnect it. Connect an app opens a panel with the apps grouped as chat apps, agents and code editors. Making a key happens in the same panel, with the same short steps as the address, and a key you never copy is removed when you leave.
* Changed: On a site that runs on your own computer, the Connect panel and the welcome point you to the apps that can reach it (Claude Code, Cursor and Codex), and Claude users to its desktop app.
* Changed: Activity names the app that made each change, such as "via Claude Code", and keeps the name after you disconnect the app. Each action is in plain words, such as "Blocked · Update option · needs Manage the site".
* Changed: The Services page replaces Integrations. It lists Unsplash, the SEO and shop plugins Saddle edits, and the plugins that add tools to Saddle. Each says what it sends off your site and which tools it gives your AI apps. Plugins from other developers still stay off until you switch them on.
* Changed: Unsplash tools stay out of your AI app's tool list until you add a key under Services. Searching Unsplash needs Edit content access, because it uses your Unsplash quota. On WordPress 7.0 and later, the same key also appears under Settings > Connectors.
* Changed: Settings is one page: Safety (publishing needs your OK, practice mode, stop writes if the site moves) and Advanced (turn off single tools, sign-in for apps, memory limits, recent changes and the connection check).
* Changed: The Saddle screens look like WordPress's own pages. The page uses the same grey as the rest of wp-admin, blocks have a visible edge, and text, form controls and links use WordPress's own colors and your admin color scheme. Saddle's own color, a deep teal, appears only on its logo, buttons and switches. Saddle shows at most one notice at the top of its pages and keeps the rest behind the bell.
* Changed: Activating or deactivating a plugin, or switching the theme, shows a preview first, and nothing changes until your AI app confirms it. The request also waits for you under Needs your OK.
* Changed: Your AI app is no longer offered the tools for Divi 5, Yoast SEO, Rank Math, All in One SEO or WooCommerce when that plugin is not active. At Read only its tool list is about a third smaller, and the app is told which plugins are missing.
* Changed: The activity log says what a confirmed change did, such as "Moved post #5 to the trash.", instead of repeating what the app asked. Your own approvals read "You approved Claude Code's request: …".
* Improved: When an app tries a tool it may not use, it is told why: its access level, a tool you turned off, or Saddle being paused. It no longer gets a bare "Permission denied".
* Improved: Skills. Uploading a skill with a name you already have asks before it replaces it, and you can edit your own skills in place on the Context page.
* Improved: Settings switches save as soon as you flip them and say "Saved." "Turn off single tools" lists only the tools that can run on your site.
* Improved: Saddle's pages show their layout while they load, instead of a blank page or a spinner.
* Improved: Previews and the activity log name custom content types by their label, such as "Event". Asking for an item by the ID of another content type says which type it is.
* Changed: Listing WooCommerce orders needs Edit content, because orders show customer names and emails. Products can still be read at Read only.
* Changed: Saddle Analytics, Saddle Rank and Saddle CRM, PlugPress's own plugins, are trusted like Waggle: their tools work as soon as they are active, with no switch to turn on. Saddle Rank is Waggle's new name, and any Waggle tool you switched off stays off after the rename.
* New: For plugin developers: the saddle_modules filter adds a page under the Saddle menu with its own icon, a sidebar of sections, icon tabs and an address for each page. Describe a module's settings once, and Saddle draws the form, serves it over the REST API and offers it to agents. A full-screen page outside the Saddle frame can load Saddle's colors.
* Removed: The unused dark and light theme setting. The Saddle screens have one light theme.
* Fixed: Deactivating or deleting Saddle now deletes the app keys it made. Before, a key still worked on the rest of the WordPress REST API while Saddle was off, without Saddle's limits. Apps that connected with a key need to connect again after you turn Saddle back on.
* Fixed: An app whose key was revoked or deleted got WordPress's "invalid application password" message. It now gets Saddle's: the key was rejected, so reconnect the app.
* Fixed: Trashing a post that is already in the trash says so instead of failing with an error.
* Fixed: Moving a block to where it already is says nothing moved, and a position past the end says where the block went.
* Fixed: The WooCommerce product tool also accepts the product's ID as "id", the name the post and page tools use.
* Fixed: The button block's guide told AI apps never to set the link the way its own example did. It now says to give the link as the button's url.
* Fixed: An AI app that passed an argument to a tool that takes none, such as listing Divi modules with a search word, caused a critical error on the site. It now gets a normal answer.
* Fixed: On Divi 5 sites, styling body text on a text module and similar modules now works. Saddle gave your AI app a path Divi does not read, so the color and size saved but never showed.
* Fixed: On a Divi shop, your AI app can use Divi's WooCommerce modules, such as the products grid. They were missing from the module list.
* Fixed: On Divi 5 sites, the design summary your AI app receives includes the site's heading and body fonts, and the Divi guide gives the right address for a page's first section.
* Fixed: The design check no longer counts a dark tinted background, such as slate or a near-black green, as a second accent color.
* Fixed: Removing a block or a Divi module with a confirmation that was already used could remove the block that had moved into its place. A used or out-of-date confirmation is now refused.
* Fixed: Editing a list's items with a single block edit left an empty list followed by the old items. The new items now replace the old ones inside the list, and the page check flags any block whose inner blocks render outside it.
* Fixed: With Saddle active, Gravity Forms' "Site MCP" mode and other plugins that share the MCP Adapter's default server lost their MCP connection. Saddle now leaves that server running when another plugin uses it, and keeps its own tools off it.
* Fixed: The connection check could not tell whether your server passes sign-in headers through, and always said "unknown". It now gives a real answer.
* Fixed: OAuth sign-in from Claude Code and other apps that run on your own computer no longer fails when the app uses a different port than last time. Only the port may change. Every other part of the return address must still match.
* Fixed: On a site with the trash turned off, the delete preview no longer says a post or page can be restored.
* Fixed: Closing the connect screen no longer removes a key the app has already used.
* Fixed: Page lists include each page's slug, parent and menu order, as the tool description already said. Post lists include the slug.

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
