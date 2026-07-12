# Saddle — Build Guide for Claude Code

Read `CLAUDE.md` first for the non-negotiables. This document is the execution order — do these steps in sequence, don't skip ahead because a later step looks more interesting. Each step has a concrete "done when" check; don't mark it done on vibes.

---

## Step 0 — Environment

- Local WordPress install, version 6.9+ (Abilities API must be core, not a feature plugin — confirm `function_exists('wp_register_ability')` before anything else).
- PHP 8.0+.
- Site must be reachable over HTTPS, or with `WP_ENVIRONMENT_TYPE` set such that Application Passwords aren't disabled — core disables the feature on non-HTTPS sites by default via the `wp_is_application_passwords_available` filter chain. If working on `http://localhost`, you'll need to allow it explicitly: `add_filter( 'wp_is_application_passwords_available', '__return_true' )` in a must-use plugin for local dev only — never ship that filter override in Saddle itself.
- Node 18+ for the React build (`@wordpress/scripts`).

**Done when:** `wp eval 'var_dump(function_exists("wp_register_ability"));'` (or equivalent admin-ajax check) returns `true`, and you can create an Application Password from Users → Profile without it being greyed out.

---

## Step 1 — Verify the plugin boots, fix what doesn't

This is the most important step in this whole guide. Activate the plugin on a clean install and watch for fatal errors. Specifically verify:

1. `Saddle_MCP::call_tool()` assumes `$ability->execute( $arguments )` enforces `permission_callback` internally and returns `WP_Error` on denial — **this is flagged as unverified in the file itself.** Read the actual `WP_Ability` class source (core, `wp-includes/abilities-api/class-wp-ability.php` or wherever 6.9 ships it) and confirm. If permission isn't auto-enforced, add an explicit `has_permission()`/equivalent check before `execute()` in `call_tool()`.
2. Every ability in `includes/abilities/core-content.php` registers with `wp_register_ability()` — confirm the exact function signature against 6.9 core (argument names like `input_schema` vs `inputSchema`, `execute_callback` vs `callback`, etc. may have shifted between Abilities API drafts and the final 6.9 merge). This scaffold was written against the API shape observed in AI Engine's and Novamira's implementations, not the final core source directly — treat every `wp_register_ability()` call as needing a signature check, not just a logic check.
3. Confirm `register_post_type( 'saddle_approval', ... )` in `Saddle_Approval` doesn't collide with anything and that the title-based lookup in `consume_token()` works as expected (using `get_posts(['title' => $token])` — confirm WP_Query's `title` param does an exact match, not a partial one, since an exact match is required for token security).

**Done when:** plugin activates with zero PHP errors/warnings/notices in `debug.log`, and `Settings → Saddle` renders the (currently unstyled) React shell without console errors.

---

## Step 2 — MCP transport smoke test

Before building anything else, prove the core loop works end to end with a raw HTTP client (curl/Postman), not yet through an actual MCP client like Claude.

**Important:** the MCP Adapter's HTTP transport is session-based (MCP 2025-11-25). You must `initialize` first to get an `Mcp-Session-Id` response header, then send that header on `tools/list` / `tools/call`. Also note the transport requires `Accept: application/json, text/event-stream`, and the **tool names have the slash sanitized to a dash** — ability `saddle/list-posts` is exposed to clients as tool `saddle-list-posts`.

```bash
# 1. initialize — capture the Mcp-Session-Id response header
curl -i -u "username:application_password" \
  -X POST https://yoursite.test/wp-json/saddle/v1/mcp \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}'

# 2. tools/list — expect every registered saddle/ tool (send the session id from step 1)
curl -u "username:application_password" \
  -X POST https://yoursite.test/wp-json/saddle/v1/mcp \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -H "Mcp-Session-Id: <id-from-step-1>" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

# 3. tools/call — real post data (note the DASHED tool name)
curl -u "username:application_password" \
  -X POST https://yoursite.test/wp-json/saddle/v1/mcp \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -H "Mcp-Session-Id: <id-from-step-1>" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"saddle-list-posts","arguments":{}}}'
```

**Done when:** all three calls succeed, and a `tools/call` against a `write`-tier ability (e.g. `saddle-create-post`) correctly fails with a permission error while the site's tier is still at the default `read`.

> Shortcut for a local Studio/CLI site: you can bypass HTTP auth and exercise abilities directly with `wp eval` — `wp_set_current_user(1); wp_get_ability('saddle/get-site-info')->execute([]);` — which is how v0.1 was verified (see the notes on WP-CLI verification).

---

## Step 3 — Approval gate end-to-end test

This is the actual product — test it like it matters, because it does:

1. Set tier to `write` via the Settings UI (or directly via `update_option`).
2. Call `saddle/delete_post` with a real post ID, no `confirm_token`. Confirm the response is a preview, not a deletion — check the post still exists.
3. Call again with the `confirm_token` from the previous response. Confirm the post is now trashed.
4. Call a third time reusing the same token. Confirm it's rejected (`invalid_token`) — tokens are single-use.
5. Wait 16 minutes (or temporarily shorten `Saddle_Approval::TOKEN_TTL` for testing), get a fresh preview, wait it out, try to confirm. Confirm it's rejected as expired.

**Done when:** all five behaviors above are verified manually at least once, then captured as automated tests (PHPUnit + `WP_UnitTestCase`, standard WP plugin testing setup) before this is considered shippable.

---

## Step 4 — Connect flow end-to-end test

1. From Settings → Saddle → Connected Apps, type a client name, click Connect.
2. Confirm it redirects to `wp-admin/authorize-application.php` with the right `app_name`.
3. Approve. Confirm it redirects back to `admin.php?page=saddle&connected=1` with the new credentials issued.
4. Confirm the new entry shows up under Connected Apps (reading from `WP_Application_Passwords::get_user_application_passwords()`, filtered to the `Saddle:` name prefix).
5. Click Revoke. Confirm the application password is actually deleted (test that the old credentials no longer authenticate against `/saddle/v1/mcp`).

**Done when:** all five steps work without manual database intervention.

---

## Step 5 — React build pipeline

```bash
npm install
npm run build       # produces admin/build/index.js, index.css, index.asset.php
```

`class-saddle-settings.php` already reads `admin/build/index.asset.php` for the dependency array and falls back to a hardcoded list if the build hasn't run yet — don't remove that fallback, it's what stops a missing build from fataling the admin page.

**Done when:** `npm run build` completes without errors and the three tabs render with live data from Step 2-4's testing.

---

## Step 6 — Design alignment (do not skip, do not guess)

Read `admin/DESIGN-ALIGNMENT.md` in full. The short version: get the real inbees/outbees source, pull actual color tokens and font setup from it, check for a shared component package in the PlugPress monorepo before writing any custom CSS here. The current React build is intentionally bare `@wordpress/components` styling — that's a placeholder, not a design decision.

**Done when:** `admin/src/style.scss` exists and is built from real reference material, not memory or guesswork, and the Settings page is visually distinguishable as "the same workspace" as inbees/outbees when viewed side by side.

---

## Step 7 — Phase 2 kickoff (only after v0.1 exit criteria in the [Finalized Plan](https://github.com/plugpressco/saddle/issues/12) are all checked off)

Work the Phase 2 list in the [Finalized Plan](https://github.com/plugpressco/saddle/issues/12) in the order given. Audit log first — it's the cheapest high-value item and closes the last "stub" in the REST layer.

---

## Before WordPress.org submission

Re-read the "WordPress.org submission" section in the [Finalized Plan](https://github.com/plugpressco/saddle/issues/12). Draft the external-services disclosure language early — this is the section most likely to cause review delays based on the mailyard precedent, and it's better written calmly in advance than reactively after a reviewer flags it.

---

## Things this guide deliberately does not cover

Server-side input validation hardening, rate limiting on the MCP endpoint, internationalization (`.pot` file generation), and accessibility audit of the React UI. All real pre-release tasks, all secondary to the steps above — don't start on these until Steps 1-6 are done and exit criteria are met.
