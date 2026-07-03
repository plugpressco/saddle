# Saddle Pro — Plan (v0.1 MVP: Divi)

**One-liner:** Paid addon that teaches Saddle's safe MCP server to build and edit pages in page builders natively — Divi first — so agent edits stay fully editable in the builder.

**Why Divi first:** distribution. PlugPress already reaches Divi buyers (divitorque.co, divipeople.com, the Divi plugin portfolio), and the strongest competitor in this space — Novamira Pro, shipped by Dynamic.ooo, itself a Divi-ecosystem vendor — proves the demand is in this exact channel. We compete where we already have an audience.

---

## What the teardown established

Full analysis 2026-07-02, from source — both **free Novamira** (`~/Desktop/unsplash/novamira`, v1.7.1) and **Novamira Pro** (`~/Desktop/novamira-pro`, v1.5.0, requires the free plugin). The short version:

- **Product shape that works:** free base plugin = MCP engine + auth + general tools; Pro = a licensed catalog of plugin/theme-specific abilities (~738 across 33 integrations) registered into the same server. No relay; the only vendor traffic anywhere is a cosmetic update-info fetch to `license.dynamic.ooo` (site hostname + plugin version, doesn't gate functionality) plus Pro's separate license-key check. We should mirror this shape — it's also exactly how Saddle free/pro splits naturally.
- **Free Novamira's transport/auth are architecturally identical to Saddle's** — the official `WP\MCP` adapter package, and WordPress core Application Passwords via `WP_Application_Passwords` (no custom token store, no OAuth). This validates our architecture choices; it is not a point of differentiation.
- **Their moat is builder-native depth.** Divi 5 is Pro's deepest integration (27 tools): direct CRUD on the D5 block-module tree in `post_content`, structural validation (section → row → column → leaf), presets, global colors/fonts/tokens, loops, dynamic content, Theme Builder, Divi Library. Their skill docs enforce "build real modules, never fake it with a code-block HTML dump" — that's the correct quality bar and we adopt it.
- **Their weakness is safety — and it's total, at every tier, by design, not oversight.** This is worse than the Pro-only teardown suggested:
  - **Permissions are a single flat gate** (`novamira_permission_callback` → `manage_options`/multisite-super-admin) shared by literally every ability, free and Pro. No read/write/admin tiers, no least-privilege role, no per-object capability mapping — the *entire* concept of "safety level" that Saddle's `Saddle_Capabilities` implements does not exist in Novamira at all.
  - **Free Novamira's `execute-php` ability is a literal, unsandboxed `eval()`** of agent-supplied PHP with the full WordPress runtime — `$wpdb`, every plugin's classes, filesystem, `exec`/`proc_open` if the host allows it. The only "restriction" is a 30-second timeout. A sibling ability, `run-wp-cli`, shells out via `proc_open`/`exec` to the `wp` binary (including `wp eval`/`wp shell`) as a second, independent code-exec path.
  - **The sandbox loader is a product-sanctioned persistence mechanism**: `wp-content/novamira-sandbox/` auto-`require_once`s every `.php` file it contains **on every single WordPress page load**, for as long as the plugin is active. Combined with `write-file` + `execute-php`, this is a straightforward, intentional backdoor pattern, not an incidental bug.
  - **Free Novamira has no curated content-CRUD abilities at all** — no `create-post`/`update-post`/`delete-post`/media. Structured content operations are expected to happen via `execute-php`/`run-wp-cli` (i.e., the agent writes PHP or shells out) or the Gutenberg-block-specific abilities. Saddle's free plugin — curated, scoped, per-object abilities with explicit `accessLevel`/`destructive` flags — is already a strictly safer product than Novamira's *combined* free+pro stack, before Saddle Pro even exists.
  - **No confirmation gate, dry-run, or audit log anywhere in either plugin.** The only "safety" language is advisory UI copy telling the *human* to configure their *MCP client* to ask before acting — enforcement is 100% delegated away from the plugin. The system instructions injected into every agent session literally open with "Novamira gives you unrestricted control over this WordPress installation."
  - Their `.mcpb` one-click Claude Desktop bundle embeds the application password in plaintext inside a downloadable file (with a UI warning to delete it after install) — a real credential-hygiene risk baked into the recommended setup flow.
  - Pro layers 148 abilities flagged `destructive: true` on top of this — but the flag is a client-side MCP annotation hint only; the plugin itself never blocks or gates on it.

**The Saddle Pro thesis:** match Divi depth where it matters, keep Saddle's three non-negotiables (no third-party custody, default-safe tiers, approval-gated destructive actions) unchanged from the free plugin straight through Pro, and be the version you can run on a production site without gambling on the agent behaving. "Novamira gives an agent unrestricted `eval()` and calls itself dev/staging only. Saddle never runs arbitrary code, and every destructive action asks first — on your live site." That's the entire pitch, and free Saddle already earns half of it.

---

## Scope lock for the MVP

Simple MVP means this list and nothing else:

- **Divi 5 only.** No Divi 4 shortcode support (Novamira skips it too; D4 is legacy). No Elementor/Bricks/etc. — those are later Pro modules, decided by sales evidence, not speculation.
- **Page-scoped editing only.** No Theme Builder, no global presets/colors/fonts/tokens, no loops, no dynamic content, no Divi Library in v0.1. Every one of those is site-scoped or complex state — each needs its own safety design and none is required for "agent, build me a landing page."
- **No code execution, ever** — same rule as free Saddle, restated because Novamira's `execute-php` fallback is the pattern we're refusing. If a Divi feature seems to need PHP execution, the feature waits.
- **Carries Saddle's safety model unchanged.** Pro abilities register with explicit `accessLevel` and `destructive` meta like every free ability, run behind `Saddle_Capabilities`, and destructive ops go through `Saddle_Approval::gate()`.

## MVP ability set (~9, all `write` tier unless noted)

Divi 5 pages live in `post_content` as blocks (`<!-- wp:divi/section {…} -->`), so **WordPress revisions cover every page-scoped edit** — same recoverability story as free Saddle's `update-post`, which sets the gating line: single-page tree edits are write-tier and ungated; anything site-scoped (none in MVP) gets the gate.

| Ability | What it does |
|---|---|
| `saddle/divi-check-setup` | Divi 5 present + version + whether the post is Divi-built (read) |
| `saddle/divi-list-modules` | Catalog of available modules with one-line purposes (read) |
| `saddle/divi-get-module-schema` | Full attribute schema for one module type (read) |
| `saddle/divi-get-page` | Flat, addressable node list of a page's module tree (read) |
| `saddle/divi-add-module` | Insert a module/section/row at an addressed position, structurally validated |
| `saddle/divi-edit-module` | Patch an addressed module's attrs/content |
| `saddle/divi-move-module` | Reorder/reparent a node, validated |
| `saddle/divi-remove-module` | Remove a node (revision-recoverable; destructive-flagged, gated if it removes a subtree with children) |
| `saddle/divi-set-page` | Replace a page's whole tree (bulk build; revision-recoverable) |

Plus one non-ability deliverable: **Divi guidance injected into `get-instructions`** via `Saddle_Context` when Divi 5 is active — the "build real modules, keep pages Visual-Builder-editable, never dump HTML into a code module" rules. Novamira ships 36 skill playbooks; we ship one, excellent, for Divi.

**The hard engineering core** (where the real effort goes): a `Saddle_Divi_Tree` layer that parses/serializes the D5 block tree via the core block parser and **rejects invalid structure on every write** (leaf at root, unknown module, section-in-column, etc.), with stable node addressing. Everything else is thin registration around it.

## Product/plumbing decisions

- **Separate plugin, own repo (`saddle-pro`)**, requires free Saddle ≥ the version that ships v0.2 — hard dependency with an admin notice, same as Novamira requires its base. Abilities register into the existing `saddle` MCP server; zero new transport/auth surface.
- **Licensing:** follow the PlugPress portfolio standard (same store/membership mechanism as the other pro plugins — decide the exact mechanism when scaffolding; it's plumbing, not product). License traffic is the *only* outbound call Pro may make, disclosed plainly.
- **Testing:** the tree layer is pure functions over block markup → unit-testable with fixture pages in the existing SQLite PHPUnit harness, no Divi install needed. Ability round-trips get live-verified on the existing `divi-dev` site (`~/Workspace/wp/divi-dev`).
- **Free/pro line (decided 2026-07-03):** free = core content + safety + **native WordPress design** (structured Gutenberg block editing: the generic tree engine with a Gutenberg validation profile, block schemas from `WP_Block_Type_Registry`, theme.json design tokens, block patterns). Pro = **depth**: page-builder-native design (Divi first) **+ the plugin-integration catalog** (decided 2026-07-03, same day, after a live agent session on divitorque.com surfaced the demand): Pro exposes other plugins' Abilities-API tools through Saddle's MCP server, wrapped in Saddle's full safety model (tier mapping from their readonly/destructive annotations, pause, per-tool toggles, audit log, approval gate on destructive ops) — **PlugPress in-house plugins first (Knovia docs is integration #1)**, each integration an upsell for both products ("Knovia is AI-ready — with Saddle Pro"). This mirrors Novamira Pro's proven catalog model, with two advantages: we own both sides of every integration, and ours are safety-wrapped. **Safety is never paywalled** — which specifically includes **credential scoping in FREE** (Saddle-issued application passwords restricted to the `saddle/v1` namespace): it closes the wp-abilities/wp/v2 side-channel that would otherwise bypass tiers AND makes Saddle the only door to integrations, protecting the Pro upsell. The upsell: "Saddle designs with WordPress's native editor; Saddle Pro does it with your page builder — and connects your AI to the plugins you run." Architectural consequence: the builder-agnostic tree core (parse/address/mutate/serialize + pluggable validation profiles) lives in FREE — Pro's Divi layer ships only the Divi profile, detection, schemas, and playbook, and consumes the free engine (Pro already hard-requires free).

## Sequencing

1. **Free: credential scoping** (small, pre-release hardening) — requests authenticated via a `Saddle: `-prefixed application password may only reach `saddle/v1` routes. Ships before free goes to strangers.
2. **Saddle Pro Divi MVP** (this doc) — jumped ahead of everything per 2026-07-02 priority call: Divi first, because distribution. Tree core already extracted INTO FREE (done 2026-07-03); set-page shipped; remaining: get-module-schema + surgical writes + design recipes.
3. **Pro: integration catalog, Knovia first** (decided 2026-07-03) — expose `knovia/*` abilities through Saddle's server wrapped in the safety model; per-integration toggle in the UI; integration guidance appended to the system context. Pattern then repeats for other in-house plugins as they gain abilities.
4. **Free: Gutenberg design abilities** ✅ DONE (2026-07-03) — the tree engine's Gutenberg profile (`Saddle_Blocks_Tree`) + authoring layer (`Saddle_Blocks_Author`: content/attrs → editor-valid markup with preset classes) + 11 abilities: get/set-blocks, add/edit/move/remove-block (remove gated on subtrees), insert-block-pattern, list-block-types, get-block-schema, get-design-tokens, list-block-patterns. Builder pages refused (routed to divi-* tools when Pro is present). PHPUnit-covered AND live-verified 2026-07-03 on the plug.press Studio site (WP 7.0): all 11 abilities exercised end-to-end, audit log confirmed, and the built page opens in the block editor with zero validation warnings (headless-Chrome check). Live finding fixed same day: some core blocks (core/heading, core/image) register only client-side on real builds — curated types are now authorable without a server registration.
5. Free v0.2 site-management abilities (plugins/themes/options/cache + third "Full management" UI level) — fully mapped, resumes after the above or interleaved.
6. Pro v0.2 candidates, evidence-driven: Divi Theme Builder + presets/global styles (gated, site-scoped), Divi Library, loops/dynamic content; or a second builder if buyers ask.

## Not in any near version

- Elementor/Bricks/Breakdance/WooCommerce/SEO/forms catalogs — Novamira's 33-integration breadth is years of work; we win on Divi depth + production safety, not integration count.
- Form migration, agent memory, skill libraries beyond the Divi guidance.
- Anything requiring `execute-php`-style capability.
