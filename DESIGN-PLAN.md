# Saddle — Design Quality Plan (builder-agnostic)

Status: **in progress** — §2.1 lint engine + §2.2 applied-vs-ignored echo SHIPPED
2026-07-05 (free `includes/lint/` + `Saddle_Blocks_Echo`, Pro `builders/divi/`
accessor + echo; `saddle/lint-page` is the 47th free ability). Built ahead of the
P1 builders/ refactor per TASKS.md ordering — the Divi lint accessor already
lives at `builders/divi/`, seeding the driver directory the refactor fills.
Read `CLAUDE.md` + `saddle-pro/INTEGRATIONS-PLAN.md` first.
Goal: agents produce *designed* pages (9/10), not just *valid* ones (7/10) — on
Gutenberg (free) today, Divi (Pro) today, Elementor/Bricks (Pro) later, without
building the quality layer per builder.

## 1. The architecture rule (decide once, save three rebuilds)

**Everything conceptual lives in FREE as a builder-agnostic engine; everything
builder-specific lives behind the driver interface** (the `builders/` refactor,
INTEGRATIONS-PLAN §7 — now a hard prerequisite, not a nice-to-have).

| Concern | Free engine (generic) | Driver supplies (per builder) |
| --- | --- | --- |
| Tree model | `Saddle_Tree` (exists) | parse/serialize/storage (Gutenberg=blocks ✅, Divi=blocks ✅, Elementor/Bricks=JSON-in-meta) |
| Lint rules | rule definitions + runner over generic tree | how to read a node's colors/buttons/alignment (attr accessors) |
| Design tokens | token model + `get-design-system` shape | source: theme.json (Gutenberg ✅ partial), Divi GlobalData ✅, Elementor kit, Bricks settings |
| Section recipes | recipe format {slots, tokens} + insert flow | recipe bodies per builder (verified attrs) |
| Applied-vs-ignored echo | diff engine | the module/widget attr schema to validate paths against |
| Skill design sense | ONE shared "design numbers" section | per-builder authoring specifics |
| Render preview | endpoint + response shape | builder render pipeline |

## 2. The design-quality stack (build order)

1. **`lint-page` (free engine + per-builder accessors).** Rules: double
   background, button contrast < WCAG AA, mixed accent colors (>1 hue family),
   unaligned sibling buttons, no featured pricing plan, empty titles,
   ghost buttons (Divi accessor: missing enable=on), inconsistent section
   padding. Violations per node address → agent fix loop. Harness-testable.

   **Engine contract (write the code to THIS, no improvising):**
   - `Saddle_Lint::run( array $tree, Saddle_Lint_Accessor $accessor ): array`
     → violations: `{ address, rule, severity(error|warn), message, fix_hint }`.
   - `interface Saddle_Lint_Accessor` — the ONLY builder-specific surface:
     `background_color($node)`, `text_color($node)`, `is_button($node)`,
     `button_is_filled($node)`, `alignment($node)`, `padding($node)`,
     `title_text($node)`. Gutenberg + Divi implement it; Elementor/Bricks later.
   - Each rule = one small class with `id()`, `check(nodes, accessor)`; rules
     registered via one filter. No rule reaches into builder attrs directly.
   - One file per class: `includes/lint/class-saddle-lint.php`, `interface-…-accessor.php`,
     `rules/class-rule-….php`; Divi accessor lives in saddle-pro `builders/divi/`.
   - Every rule ships with fixture tests proving both the violation AND the
     clean case (no false positives — a lint that cries wolf gets ignored).
2. **Applied-vs-ignored echo** on every write: validate style paths against the
   builder's real attr schema; warn on unknown keys. Kills silent no-ops per
   builder, forever.
3. **`get-design-system` / `bootstrap-design-system`.** One call returning
   colors/fonts/spacing tokens; bootstrap creates them on fresh sites (gated,
   admin). Gutenberg source: theme.json (get-design-tokens exists — unify shape);
   Divi: GlobalData (exists — unify shape).
4. **Shared "design numbers" skill section** (hero 56–64px white centered,
   ~96px section rhythm, one accent + neutrals, 65ch text, solid same-baseline
   buttons, featured middle plan) — written ONCE, included by divi-build-page,
   gutenberg page-design guidance, and future builder skills.
5. **Section recipes** — generic format; per-builder verified bodies (hero,
   features, pricing, testimonials, CTA, FAQ). Insert = fill slots + tokens.
6. **Render preview** — rendered HTML + computed attrs per node (v1); screenshot
   via companion/headless later. Flagship, last.

## 3. Builder rollout

- **Gutenberg (free)**: already has blocks engine + design tokens; gets lint,
  echo, recipes, skill from the shared engine → free tier becomes a real
  designer, which sells Pro.
- **Divi (Pro, shipped)**: refactor under `builders/divi/` driver, then plug
  into the engine; its lint accessors + recipes verified on divi-dev.
- **Elementor (Pro, next)**: driver = detection (`_elementor_data` meta),
  JSON tree parse/serialize, widget schema (Elementor's widget registry),
  kit tokens. NO new abilities — the generic saddle/builder tools + engine work
  through the driver (INTEGRATIONS-PLAN §7 decision stands: one tool surface).
- **Bricks (Pro, after)**: same driver contract (`_bricks_page_content_2`).

## 4. Sequence

P1: builders/ refactor (Divi → driver, no behavior change; task #20)
P2: lint-page + applied-echo (engine in free, Divi+Gutenberg accessors)
P3: design-system unify + bootstrap; shared design-numbers skill section
P4: section recipes (Gutenberg + Divi)
P5: Elementor driver → inherits everything; then Bricks
P6: render preview

Non-negotiables unchanged: native APIs only, tiered, gated, no execute-anything,
engine never false-rejects. Each phase: tests + live round-trip before release.
