---
name: wp-security-rules
description: Saddle-specific WordPress security rules. Load when reviewing or writing PHP that handles a request (REST route, ability permission/execute callback, admin_post handler, cron callback), renders output, or touches the database. Encodes the tier system, approval gate, and escaping-context judgments that phpcs cannot make.
---

# Saddle security rules

Covers `plugpress/saddle` and `plugpress/saddle-pro`. Every rule is a **semantic**
judgment; phpcs (`WordPress` ruleset, `composer lint` in CI) already catches the
mechanical failures listed at the end — do not re-report those.

Saddle is an MCP server: 108 agent-callable abilities, an OAuth 2.1 server, a
React admin. The caller is normally an AI agent holding an Application Password
or OAuth token, not a browser session. That inverts one usual WordPress
assumption — see rule 5.

The codebase is currently clean against every rule here, so BAD examples are
marked **constructed**: they are the failure the rule prevents, not code that
exists. GOOD examples are always real, with file:line.

## Bindings

Repo-specific names in one place. Porting these rules elsewhere means rewriting
this table and re-deriving every example.

| Role | This codebase |
|---|---|
| Class / option prefix | `Saddle_` `saddle_` · `Saddle_Pro_` `saddle_pro_` |
| Text domain | `saddle` · `saddle-pro` |
| Ability ids | `saddle/dash-case` (Pro too, as `saddle/divi-*`) |
| Permission gate | `Saddle_Capabilities::permission( $tier, $cap, $short )` |
| Effective tier (decide) | `Saddle_Capabilities::get_tier()` |
| Configured tier (report) | `Saddle_Capabilities::get_site_tier()` |
| Destructive gate | `Saddle_Approval::gate()` |
| Object-level authz | `Saddle_Abilities::authorize_write()` · `Saddle_Pro_Divi::editable_divi5_post()` |
| SSRF guard | `Saddle_HTTP::url_is_safe()` |
| Admin REST gate | `Saddle_REST_Admin::can_manage` |
| Option allowlist | `Saddle_Abilities::guard_option()` |
| Audit log | `Saddle_Log::record()` / `record_action()` |
| Constant-time compare | `Saddle_OAuth::secure_equals()` |
| PHP floor | 7.4 (saddle) · 8.0 (saddle-pro) |

## Severity

**CRITICAL** — exploitable now by a caller holding a credential the site itself
hands out. *e.g.* an ability that writes post content but never checks
`edit_post` against the target id, letting a Contributor-level Application
Password edit an Administrator's page.

**HIGH** — a required control is missing or incomplete, no proven path to reach
it. *e.g.* a destructive ability that calls `Saddle_Approval::gate()` but passes
no `bind`, so preview-swap is possible in principle.

**NOTE** — hardening; the control is present and correct, the concern is blast
radius. *e.g.* `saddle/activate-theme` (`includes/abilities/site.php:129`) is
ungated because switching a theme is reversible, which fits the stated gate
criterion — but it changes every page on the site in one agent call.

---

## 1. The capability must match what the ability touches — CRITICAL

`Saddle_Capabilities::permission()` takes a capability and nothing verifies it is
the *right* one. Pick it from the object mutated, not from the tier.

```php
// BAD (constructed) — seeds site-wide design tokens, gated as a post edit
'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'bootstrap-design-system' ),
// GOOD — includes/abilities/blocks.php:122
'permission_callback' => Saddle_Capabilities::permission( 'admin', 'edit_theme_options', 'bootstrap-design-system' ),
```

Established pairings: theme/design → `edit_theme_options` (`blocks.php:122`,
`saddle-pro/includes/abilities/divi-design.php:61`); options → `manage_options`
(`site.php:226`); plugins → `activate_plugins` (`site.php:78`); media →
`upload_files` (`core-content.php:398`); taxonomy → `manage_categories`
(`core-content.php:470`); users → `list_users` (`users.php:60`).

## 2. `get_tier()` decides, `get_site_tier()` reports — CRITICAL

Not interchangeable. `get_tier()` applies the `saddle_tier_ceiling` filter, which
is how an OAuth token's granted scope *lowers* the effective tier.
`get_site_tier()` is the owner's configured value and ignores the caller. Using
it in an access decision discards the OAuth clamp — a `saddle:read` token would
get write on a write-tier site.

```php
// BAD (constructed) — ignores the scope ceiling on this credential
if ( 'admin' === Saddle_Capabilities::get_site_tier() ) { /* allow */ }
// GOOD — includes/class-saddle-capabilities.php:182
return self::$levels[ self::get_tier() ] >= self::$levels[ $required ];
```

`get_site_tier()` is correct only for config display and the clone-domain warning
(`includes/admin/class-saddle-rest.php:505`, `class-saddle-capabilities.php:445`).

## 3. `destructive => true` implies `gate()`, and `gate()` needs `bind` — HIGH

All 7 destructive abilities gate today; keep the correspondence 1:1 both ways.

`bind` is the non-obvious half. The token binds *action* and *target* but not the
payload, so a token issued for one preview is otherwise replayable with different
arguments. Any gate whose outcome depends on a caller-varied value must bind it.

```php
// BAD (constructed) — a trash preview's token also confirms force-delete
return Saddle_Approval::gate( array(
    'action' => $action, 'target' => (string) $id, 'input' => $input, 'execute' => $run,
) );
// GOOD — includes/abilities/core-content.php:1967
'bind' => $force ? 'permanent' : 'trash',
// GOOD — includes/abilities/site.php:586 (binds the proposed value)
'bind' => substr( hash( 'sha256', wp_json_encode( $value ) ), 0, 16 ),
```

## 4. The permission callback is necessary, never sufficient — CRITICAL

Core's post insert/update/delete primitives do not apply `map_meta_cap`, so the
generic capability says nothing about *this* object. Every write path re-checks
the target inside the execute callback.

```php
// BAD (constructed) — permission_callback passed edit_posts; nothing checks THIS post
public static function update_post( $input ) {
    return wp_update_post( array( 'ID' => (int) $input['id'], 'post_title' => $input['title'] ) );
}
// GOOD — includes/abilities/core-content.php:1873
$denied = self::authorize_write( $type, $input, (int) $existing->post_author, $id );
```

`authorize_write()` (`core-content.php:868`) covers `edit_post` on the target,
`publish_posts`/`publish_pages` on status change to publish, and
`edit_others_posts`/`edit_others_pages` on author reassignment.

**Pro has three funnels, not one** — `authorize_write()` is private to
`Saddle_Abilities`, so Pro re-derives the check. A new Pro write ability must
route through `Saddle_Pro_Divi::editable_divi5_post()`
(`includes/builders/divi/class-divi.php:108`), `edit_guard()`
(`includes/abilities/divi.php:789`), or `locate_module()` (`divi.php:1851`).
`set_page` keeps a documented inline guard (`divi.php:1272`) — it deliberately
allows building an empty non-Divi post. Any other route to `post_content` is a
finding.

## 5. Agent-facing payloads must NOT be HTML-escaped — HIGH

Ability return values are JSON for an AI agent, not HTML for a browser.
`esc_html()`/`wp_kses()` corrupts instruction text — angle-bracket placeholders
like `<id>` in a skill body are exactly what the agent must receive verbatim.
This is the one place generic WordPress advice points the wrong way.

```php
// BAD (constructed) — mangles the playbook the agent is meant to follow
return array( 'body' => esc_html( $skill['body'] ) );
// GOOD — includes/class-saddle-skills.php:319 (UTF-8 + control-char strip only)
$body = wp_check_invalid_utf8( (string) $body, true );
$body = preg_replace( '/[^\P{C}\n\t]/u', '', $body );
```

Corollary: because output escaping is not doing the work, **input sanitization at
the store boundary is**. Route `args` carry no `sanitize_callback` (0 of 38
routes) — sanitizing lives in the store class. A new store method that persists
caller text unsanitized is a finding even though its route looks like its
neighbours. Real boundaries: `Saddle_Context::set_user()` →
`sanitize_textarea_field` (`class-saddle-context.php:45`);
`Saddle_Memory::remember()` → `wp_kses( …, array() )` (`class-saddle-memory.php:100`).

## 6. Escaping context on the two HTML surfaces — HIGH

Server-rendered HTML exists in exactly two files: the OAuth consent screen
(`includes/oauth/class-saddle-oauth-consent.php`) and the Pro license page
(`saddle-pro/includes/class-license-page.php`). Judge the escaper by the slot,
not the variable.

```php
// BAD (constructed) — esc_attr in an href slot permits javascript: URLs
echo '<form action="' . esc_attr( $url ) . '">';
// GOOD — class-saddle-oauth-consent.php:162,165 (URL slot vs attribute slot)
echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
echo '<input type="hidden" name="saddle_req" value="' . esc_attr( $request_id ) . '">';
```

`class-saddle-oauth-consent.php:154` prints `redirect_uri` with `esc_html()`, not
`esc_url()` — correct: it is displayed as text, not used as a target, and
`esc_url()` would silently rewrite what the user is shown before deciding.

## 7. External fetches use `Saddle_HTTP::url_is_safe()` — CRITICAL

`wp_http_validate_url()` does **not** block link-local `169.254.0.0/16` — the
cloud metadata range (`169.254.169.254`). Any fetch of a caller-supplied URL uses
the shared guard.

```php
// BAD (constructed) — reaches cloud instance metadata
if ( wp_http_validate_url( $url ) ) { $body = wp_remote_get( $url ); }
// GOOD — includes/class-saddle-http.php:84
if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
```

Callers: `source_url_is_safe()` (`core-content.php:916`) for media sideload, and
OAuth client-metadata fetching. The DNS-rebinding TOCTOU is documented at
`core-content.php:902` — do not re-report it.

## 8. Option reads and writes go through `guard_option()` — CRITICAL

Arbitrary option writes are site takeover (`siteurl`, `default_role`,
`users_can_register`). The blocklist is pattern-based, catching
`secret|salt|nonce|token|password|auth_key|_key$|user_roles|capabilities`
(`site.php:854`).

```php
// BAD (constructed) — trusts the allowlist filter without the blocklist
if ( in_array( $name, apply_filters( 'saddle_option_allowlist', array() ), true ) ) {
    update_option( $name, $value );
}
// GOOD — includes/abilities/site.php:867
if ( ! in_array( $name, self::allowlist(), true ) ) { return new WP_Error( 'saddle_option_not_allowed', … ); }
```

`allowlist()` (`site.php:798`) re-filters through `is_blocked_option()` *after*
applying `saddle_option_allowlist`, so a third-party filter cannot widen the set
into security material. Preserve that ordering in any new path.

## 9. Nonce scope must cover the target, not just the action — HIGH

A nonce naming only the action class is replayable across every target of that
action.

```php
// BAD (constructed) — a nonce from any pending request approves any other
check_admin_referer( self::ACTION );
// GOOD — includes/oauth/class-saddle-oauth-consent.php:183
check_admin_referer( self::ACTION . '_' . $request_id );
```

Paired with `wp_nonce_field( self::ACTION . '_' . $request_id )` at line 163.
Pro's license actions (`class-license-page.php:155`) use unscoped nonces —
correct there, the action has no target to bind.

## 10. Compare secrets in constant time — HIGH

```php
// BAD (constructed) — leaks the signature byte-by-byte under timing analysis
if ( $signature === self::signature( $post_id, $expires, $secret ) ) { return true; }
// GOOD — includes/preview/class-saddle-preview.php:105
if ( '' !== $secret && hash_equals( self::signature( (int) $post_id, $expires, $secret ), $signature ) ) {
```

Also `Saddle_OAuth::secure_equals()` (`class-saddle-oauth.php:350`) for
non-string-safe input, and PKCE at `oauth/class-saddle-oauth-endpoints.php:385`.

## 11. A new `__return_true` needs a written reason — NOTE

Eleven public routes exist, all OAuth-spec-required plus one documented probe. A
twelfth needs a comment stating why it must be unauthenticated and what it
discloses.

```php
// GOOD — includes/class-saddle-connection.php:358
// Unauthenticated: reports only whether an Authorization header arrived.
// Used exclusively by self_check()'s loopback request to itself.
'permission_callback' => '__return_true',
```

---

## Known-safe patterns — do not flag

- **11 OAuth `__return_true` routes** — `oauth/class-saddle-oauth-discovery.php:84`
  (shared by 7 docs), `oauth/class-saddle-oauth-endpoints.php:43,53,63`,
  `oauth/class-saddle-oauth-clients.php:102`. Required by RFC 8414/9728 + MCP spec.
- **`/auth-probe`** — `class-saddle-connection.php:366`. Returns booleans about
  the caller's own headers; reads no credential.
- **MCP transport gated only on `is_user_logged_in()`** — `class-saddle-mcp.php:168`.
  Per-tool authorization is each ability's `permission_callback`.
- **Unescaped echo of captured admin notices** — `includes/admin/class-saddle-settings.php:134`,
  standing `phpcs:ignore`. Other plugins' rendered HTML moved to a hidden
  container; escaping breaks their dismiss buttons.
- **`printf()` with `<code>` in the argument** — `class-saddle-oauth-consent.php:114-118`.
  Format string escaped, interpolated value `esc_html()`'d, tags intentional.
- **Both `$wpdb` calls** — `class-saddle-unsplash.php:326`,
  `oauth/class-saddle-oauth-store.php:190`. Prepared; `{$wpdb->posts}` /
  `{$wpdb->postmeta}` are table names, not user input.
- **Preview capability URLs** — `includes/preview/class-saddle-preview.php`. An
  unauthenticated token holder reads one draft by design: HMAC-signed,
  post-bound, 300s TTL, `noindex`.
- **Skill bodies kept byte-identical** — `class-saddle-skills.php:319`. See rule 5.
- **`@dns_get_record()` silenced** — `class-saddle-http.php:62`. Deliberate; an
  attacker-chosen name must not warn or throw.
- **Verbose ability `description` fields** — agent-facing API surface, not
  comments.

## Already covered by phpcs — do not re-report

The `WordPress` ruleset reliably catches: missing `esc_*` on echo/print, missing
`wp_unslash()`, unsanitized `$_GET`/`$_POST`/`$_SERVER`, direct DB queries without
`prepare()`, missing translator comments, missing text domain, missing
`defined( 'ABSPATH' ) || exit`, `eval`/`exec`/`shell_exec`/`proc_open`, and PHP
incompatibilities below 7.4. Four sniffs are excluded in `phpcs.xml.dist` (file
naming, mixed function/OO layout, two docblock-capitalization sniffs) — none is a
security exclusion.
