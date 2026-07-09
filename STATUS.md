# STATUS

**Tier:** build
**Board:** [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3)

## Last session
2026-07-10 — Shipped Users read-only ([#13](https://github.com/plugpressco/saddle/issues/13)) + backlog reset for a scope replan:
- Built + **merged #13** (PR [#20](https://github.com/plugpressco/saddle/pull/20), squashed to `main` as `be79e28`): two read-tier user abilities, `saddle/list-users` + `saddle/get-user`, in new `includes/abilities/users.php` (wired in bootstrap require + `wp_abilities_api_init` hook). Both gated on the `list_users` cap (admins only by default) on top of the read tier; PII (email/real name/login) revealed only to callers who can `edit_users`. list-users supports role filter, search, ordering, pagination (`{items,total,total_pages,page}` envelope). No create/update/delete. Free ability count 50 → **52**. New `tests/users-test.php` (10 tests); full suite **244 green**; phpcs-clean; no eval/exec.
- **Backlog reset:** closed the session-created spinoffs (#14 comments, #15 revision-restore, #16–#19 deferred Phase 3) AND all roadmap docs (#12 Finalized Plan, #10 Design Quality, #9 Agent Context) as *not planned* — reopenable. Scope is being replanned; the new plan will be filed as fresh issues after discussion. Local plan `.md` files were already migrated + deleted 2026-07-07.

## Next up
- **Scope replan** — define the new Saddle direction, then file it as fresh GitHub issues. Nothing in the old backlog restarts until then.
- CI: the PHPUnit job has never passed on GitHub Actions (no WP core set up in the runner — bootstrap looks for a local ABSPATH). Worth fixing as its own PR so future PRs get a real green.
- #13 live round-trip on a real site is the one remaining manual check (harness proves logic; a live `list-users`/`get-user` call over MCP confirms the transport surface).

## Blockers / open questions
- Saddle scope under active replanning — hold new feature work until the new plan lands.
- (Prior) Theme-builder writes unverified live (no create-template ability → only real templates to test against).
