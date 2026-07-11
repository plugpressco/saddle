# STATUS

**Tier:** build
**Board:** [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3)

## Last session
2026-07-11 — **Admin UI refresh + two DS crash fixes + #8 hygiene → PR [#37](https://github.com/plugpressco/saddle/pull/37)** (branch `feat/admin-header`, base main):
- **[#35](https://github.com/plugpressco/saddle/issues/35) shipped** — full-bleed header was already in place; added Home `StatCard`/`StatGrid` tiles (Connected apps · Access level · Actions logged), `SkipLink` → `#pp-main`, dropped the redundant "%d apps" badge. Resynced the stale `@plugpress/ui` (node_modules was 0.6.2 vs the v0.8.0 pin).
- **Two `@plugpress/ui` bugs found via live testing, fixed at root + released:** **v0.8.1** — `Button asChild` threw `React.Children.only` on every use (passed a 2-entry `[spinner, children]` array to `Slot`); **v0.8.2** — `Dialog` "no accessible name" warning fired on *closed* dialogs (ConfirmProvider's idle dialog), now gated on `open`. Both tagged/pushed on `plugpressco/plugpress-ui` (manual release, not `fleet release`, to avoid repinning the whole fleet). Saddle also switched TopBar Docs/Rate to `Button href=`; pin now **v0.8.2**.
- **[#8](https://github.com/plugpressco/saddle/issues/8) closed** — deduped the four `log()` wrappers into `Saddle_Log::record_action()`; confirmed `--scope user` fix ships + connect/revoke tests already automated (22 green). Optional 401-legibility tail split to **[#36](https://github.com/plugpressco/saddle/issues/36)**.
- Note: an unrelated in-progress change to `includes/class-saddle-mcp.php` (MCP inputSchema `properties: []` → `{}` normalization) sits uncommitted in the working tree — NOT part of PR #37.

2026-07-10 (later session) — **Admin UI fully migrated to @plugpress/ui v0.6.0** (on top of v0.9.0):
- Pin bumped v0.2.0 → v0.6.0 (v0.6.0 verified non-breaking for Saddle: all 34 imports resolve, flat Tabs/FilterTabs/Steps + all `pp-*` classes survive; adds a WCAG 2.2 AA pass); `TooltipProvider`/`ConfirmProvider`/`Toaster` mounted once in App.jsx.
- Every screen rewritten on DS components: TopBar (Tabs/StatusDot), Onboarding + Permissions (CardRadioGroup/SelectableCard, ApplyBar, toast), Home (Hero/CalloutCard/CardGrid/RowList), Connect tab (PageHeader/EmptyState/RowList/useConfirm/Snippet/Badge), ConnectionHealth (CodeBlock/CalloutCard), ConnectWizard (Steps/CodeBlock/Snippet/LiveIndicator/useCopy — everCopied gate + back-out revoke preserved), Guidance/Memory (Card/RowList/Switch/Field/useConfirm/toast), Activity (FilterTabs/EmptyState/Badge).
- `admin/src/ui.jsx` compat shim **deleted**; zero `@wordpress/components` usage (PHP fallback deps + stylesheet deps updated to match); dead theme icons removed.
- `style.scss` 2,203 → **1,109 lines** (kept: token aliases, setup shell, lanes/chips, activity timeline, wizard flourishes, `.saddle-doc`).
- **Brand mark single-sourced**: `assets/brand/mark.svg` is the only copy — React `<BrandMark/>` (SVGR) and the PHP menu icon (file read + recolor, with fallback) both consume it.
- Docs reconciled: CLAUDE.md convention now names @plugpress/ui; DESIGN-ALIGNMENT.md re-decided (2026-07-10) — monochrome stands via the saddle accent, light-only.
- Earlier same day: the Closed-Loop Quality Engine free half (epic #22, PRs #28–#32, 300 green) — see git history.

## Next up
- **Review + merge PR [#37](https://github.com/plugpressco/saddle/pull/37)** (admin UI migration + refresh + crash fixes + hygiene). Manual click-through recommended: onboarding → wizard end-to-end (copy gate, back-out revoke, live listen), Permissions ApplyBar save/cancel/partial-fail, Guidance/Memory confirms + toasts, Activity filters/paging, ForeignNotices, keyboard-only + reduced-motion. Console should be clean post-reload (hash `14af28ac`).
- **Decide on `includes/class-saddle-mcp.php`** — the uncommitted inputSchema normalization; commit it on its own (small PR) or discard.
- **Live divi-dev round-trip** (epic #22's last gate): seeded-bad page → `verify-page` flags at correct addresses → fix → score rises; then close #22.
- **Next backlog issue** (post-#8): [#5](https://github.com/plugpressco/saddle/issues/5) design-system unify (triage vs shipped brief/bundle first) or saddle-pro [#2](https://github.com/plugpressco/saddle-pro/issues/2) CF7.
- Consider a free minor release (0.10.0) bundling the UI migration; Pro's min-free constant bump to match is still pending in Pro.
- CI PHPUnit still red on GitHub Actions (no WP core in the runner) — fix as its own PR so future PRs get a real green.

## Blockers / open questions
- Old work tickets #4–#6 remain from the previous scope — #5 (design-system unify) partially overlaps the shipped brief/bundle; triage against the new scope when convenient. (#8 closed 2026-07-11.)
