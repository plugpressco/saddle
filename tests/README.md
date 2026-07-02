# Saddle test suite

Real WordPress integration tests for the three non-negotiables — default-safe
tiers, the two-step approval gate, and the activity log — plus the end-to-end
ability path an MCP client actually hits.

## Why integration (not mocked) tests

The approval gate's security properties depend on real WordPress behaviour:
`WP_Query`'s exact-title token lookup, post-meta storage, and capability
mapping. Mocking those would test the mocks, not the guarantee. So the suite
boots a genuine WordPress.

To stay runnable without a MySQL server, it drives core through the **SQLite
drop-in** against an **isolated, throwaway content directory** (`tests/.wp/`,
git-ignored). The SQLite database is deleted and reinstalled every run, so tests
never touch a real site's data.

## Requirements

- PHP 8.0+ with the `pdo_sqlite` extension.
- A local WordPress core checkout (6.9+) and the
  [`sqlite-database-integration`](https://wordpress.org/plugins/sqlite-database-integration/)
  plugin + its `db.php` drop-in to borrow. By default the bootstrap uses the
  plug-press Studio site at `/Users/fahim/Workspace/wp/plug-press/`.
- Composer dev dependencies: `composer install`.

## Run

```bash
composer install
composer test            # or: vendor/bin/phpunit
```

## Point it at a different WordPress

Override via environment variables (see `tests/bootstrap.php`):

```bash
SADDLE_TEST_ABSPATH=/path/to/wordpress/ \
SADDLE_SQLITE_SRC=/path/to/wp-content \
vendor/bin/phpunit
```

- `SADDLE_TEST_ABSPATH` — a WordPress core checkout (has `wp-load.php`).
- `SADDLE_SQLITE_SRC` — a `wp-content` dir containing `db.php` and the
  `sqlite-database-integration` plugin (under `mu-plugins/` or `plugins/`).

## Coverage

| File | What it locks in |
|---|---|
| `capabilities-test.php` | Fresh install defaults to `read` (in the DB); activation never downgrades; tier ordering; the `permission()` closure denies logged-out / uncapable / under-tier callers. |
| `approval-test.php` | Preview mutates nothing; a valid token executes exactly once; reused / unknown / expired / wrong-action / wrong-target tokens all fail cleanly; tokens are single-use even on mismatch; GC removes only expired tokens. |
| `log-test.php` | Mutations are recorded and queryable; empty records no-op; GC bounds the log. |
| `abilities-test.php` | Through the real `wp_get_ability()->execute()` path: a write ability is denied at the `read` tier; delete previews don't mutate; confirm trashes (recoverable) vs. `force` deletes permanently; confirmed deletes are logged, previews and reads are not. |
| `connect-test.php` | The connect URL targets core's Authorize screen with the `Saddle:`-prefixed name and a round-trip success/reject URL; the clients list shows only Saddle-prefixed credentials; **revoke actually invalidates** — a credential authenticates, then after revoke no longer does; revoke refuses non-Saddle credentials and 404s unknown UUIDs. |

**Not covered here (genuinely un-unit-testable):** the visual browser hop through WordPress core's own *Authorize Application* screen (click Connect → approve → redirect back). That's core's code, not Saddle's; verify it once by hand in a browser. Everything Saddle owns on either side of that hop is tested above.
