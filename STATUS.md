# STATUS

**Tier:** build
**Board:** [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3)

## Last session
2026-07-10 (later session) — **Admin UI fully migrated to @plugpress/ui v0.6.0** (on top of v0.9.0):
- Pin bumped v0.2.0 → v0.6.0 (v0.6.0 verified non-breaking for Saddle: all 34 imports resolve, flat Tabs/FilterTabs/Steps + all `pp-*` classes survive; adds a WCAG 2.2 AA pass); `TooltipProvider`/`ConfirmProvider`/`Toaster` mounted once in App.jsx.
- Every screen rewritten on DS components: TopBar (Tabs/StatusDot), Onboarding + Permissions (CardRadioGroup/SelectableCard, ApplyBar, toast), Home (Hero/CalloutCard/CardGrid/RowList), Connect tab (PageHeader/EmptyState/RowList/useConfirm/Snippet/Badge), ConnectionHealth (CodeBlock/CalloutCard), ConnectWizard (Steps/CodeBlock/Snippet/LiveIndicator/useCopy — everCopied gate + back-out revoke preserved), Guidance/Memory (Card/RowList/Switch/Field/useConfirm/toast), Activity (FilterTabs/EmptyState/Badge).
- `admin/src/ui.jsx` compat shim **deleted**; zero `@wordpress/components` usage (PHP fallback deps + stylesheet deps updated to match); dead theme icons removed.
- `style.scss` 2,203 → **1,109 lines** (kept: token aliases, setup shell, lanes/chips, activity timeline, wizard flourishes, `.saddle-doc`).
- **Brand mark single-sourced**: `assets/brand/mark.svg` is the only copy — React `<BrandMark/>` (SVGR) and the PHP menu icon (file read + recolor, with fallback) both consume it.
- Docs reconciled: CLAUDE.md convention now names @plugpress/ui; DESIGN-ALIGNMENT.md re-decided (2026-07-10) — monochrome stands via the saddle accent, light-only.
- Earlier same day: the Closed-Loop Quality Engine free half (epic #22, PRs #28–#32, 300 green) — see git history.

## Next up
- **Open PR for the UI migration** (branch `feat/ui-plugpress-ui-v0.6.0`) and do a **manual click-through** (wp-playground or divi-dev): onboarding → wizard end-to-end (copy gate, back-out revoke, live listen), Permissions ApplyBar save/cancel/partial-fail, Guidance/Memory confirms + toasts, Activity filters/paging, ForeignNotices, keyboard-only + reduced-motion.
- **[#35](https://github.com/plugpressco/saddle/issues/35) — Admin UI design refresh**: full-width header (full-bleed the two-row TopBar) + Home StatCard/StatGrid tiles + SkipLink/#pp-main a11y, on v0.6.0. Filed, Todo.
- **Live divi-dev round-trip** (epic #22's last gate): seeded-bad page → `verify-page` flags at correct addresses → fix → score rises; then close #22.
- Consider a free minor release (0.10.0) bundling the UI migration; Pro's min-free constant bump to match is still pending in Pro.
- CI PHPUnit still red on GitHub Actions (no WP core in the runner) — fix as its own PR so future PRs get a real green.

## Blockers / open questions
- Old work tickets #4–#6, #8 remain from the previous scope — #5 (design-system unify) partially overlaps the shipped brief/bundle; triage them against the new scope when convenient.
