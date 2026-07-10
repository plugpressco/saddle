# STATUS

**Tier:** build
**Board:** [PlugPress HQ](https://github.com/orgs/plugpressco/projects/3)

## Last session
2026-07-10 — **The Closed-Loop Quality Engine shipped, free half complete** (epic [#22](https://github.com/plugpressco/saddle/issues/22), PRs #28–#32, suite 244 → **300 green**):
- **F1** `Saddle_Lint_Style_Accessor` companion interface (additive — older accessors keep working) + Gutenberg impl (#23/PR #28).
- **F5** a11y rules: text-contrast (ancestor-walk effective bg, WCAG AA 4.5/3.0 thresholds), missing-alt-text, heading-order — all feature-detect the companion and skip silently without it (#27/PR #29).
- **F2** Agent Eyes: `saddle/render-node` — effective persisted styles (one resolver shared with lint) + capped/sanitized HTML; whole-page = bounded section outline. New `saddle_render_accessor` filter (#24/PR #30).
- **F3** preview transport: `saddle/get-preview-url` — HMAC (post-bound, 5-min TTL, rotating secret with grace), served via the public-preview posts_results flip, noindex; the agent's own client does the screenshotting, nothing leaves the install (#25/PR #31).
- **F4** `saddle/verify-page` — structural + echo (silently-ignored attrs) + lint over freshly re-read state, deduped/ranked/capped findings at real addresses, deterministic 0–100 score. Builders plug in via `saddle_verify_builder_findings` (#26/PR #32).
- The Pro half (Divi driver, quality judgments, brief, context discipline, builder memory, skill) shipped the same day — see `saddle-pro/STATUS.md`.
- Old-backlog #7 (render preview) closed as delivered by this scope.

## Next up
- **Live divi-dev round-trip** (the epic's last gate): build a seeded-bad page → `verify-page` flags at correct addresses → fix → score rises; screenshot a minted preview URL in Claude Code; confirm effective styles against real Divi 5.8. Then close #22.
- Consider a free minor release (the scope note says: free ships minor, Pro's min-free constant bumps — the constant bump is still pending in Pro).
- CI PHPUnit still red on GitHub Actions (no WP core in the runner) — fix as its own PR so future PRs get a real green.

## Blockers / open questions
- Old work tickets #4–#6, #8 remain from the previous scope — #5 (design-system unify) partially overlaps the shipped brief/bundle; triage them against the new scope when convenient.
