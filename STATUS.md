# STATUS

**Tier:** build
**Board:** [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3)

## Last session
2026-07-10 — Roadmap replan + shipped Users read-only ([#13](https://github.com/plugpressco/saddle/issues/13)):
- Scope is being replanned, so the buried Finalized-Plan (#12) items I'd broken out (#14 comments, #15 revision-restore, #16–#19 deferred Phase 3) were **closed as not-planned** — #12 still records the original intent if any needs reviving.
- Built **#13**: two read-tier user abilities, `saddle/list-users` + `saddle/get-user`, in new `includes/abilities/users.php` (wired in bootstrap require + `wp_abilities_api_init` hook). Both gated on the `list_users` cap (admins only by default) on top of the read tier; PII (email/real name/login) revealed only to callers who can `edit_users`. list-users supports role filter, search, ordering, pagination (`{items,total,total_pages,page}` envelope). No create/update/delete — that surface stays out per the plan. Free ability count 50 → **52**.
- New `tests/users-test.php` (10 tests, 49 assertions) drives the real `execute()` path: cap-gate denial for a subscriber, PII split, role filter, pagination, not-found. Full suite **244 green**; `includes/abilities/users.php` phpcs-clean; no eval/exec.

## Next up
- **Not committed/pushed** — the #13 work is in the working tree only. Commit + tag when ready.
- Live round-trip on a real site is the one remaining manual check for #13 (harness proves logic; a live `list-users`/`get-user` call over MCP confirms the transport surface).
- Scope replan: decide the new Saddle direction before reopening any of the closed backlog (#14–#19).

## Blockers / open questions
- Saddle scope under active replanning — hold new feature work until the new plan lands.
- (Prior) Theme-builder writes unverified live (no create-template ability → only real templates to test against).
