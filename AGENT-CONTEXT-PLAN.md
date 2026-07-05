# Saddle — Agent Context System (Skills + Memory)

Status: **proposed** (new scope, supersedes the earlier memory-only draft). Read
`CLAUDE.md` first — the three non-negotiables constrain every decision. This is the
architecture + phased build order for giving connected agents durable, owner-governed
context: *how* to work on this site (skills) and *what* has happened / been decided
(memory). Nothing ships until the "Open decisions" (§12) are settled.

---

## 1. Problem & goal

MCP sessions are **stateless**. Every session, Saddle serves the same generated
context (`Saddle_Context::system_context()`) and nothing carries over — the agent
re-derives the site's conventions and forgets what it did last time.

Two distinct gaps, two different mechanisms:

- **Skills** — reusable *procedures* the owner authors once: a design skill, an SEO
  skill, a brand-voice skill, a malware-triage skill. Static, authored, trusted. Skills
  are the modular, uploadable evolution of today's single owner-instructions blob.

  **A skill guides; it never grants capability.** It orchestrates tools that already
  exist and cannot bypass a tier or the approval gate — it's the brain, the abilities
  are the hands. So a skill is only as powerful as the tools available: a *design* or
  *SEO* skill rides the existing content/block/site tools; a *malware* skill is a
  read-only **triage** playbook (core-file checksums, suspicious admin users, recently-
  modified files, injected-content scan → explain, then hand off to a real cleaner) —
  it detects and guides, it never writes files or runs code. This is how the malware
  idea fits Saddle at all: as guidance over safe tools, not as an execution surface.
- **Memory** — accumulated *state*: what changed, what was decided, which page is the
  pricing page. Dynamic, grows over time, mixed trust.

**Goal:** a single context system that serves the right skill and the right memory at
the right time — **without** breaking the three non-negotiables, and with the **owner
in control** of what persists and what gets injected.

Saddle already has primitive versions: owner instructions is a one-blob proto-skill,
and Saddle Pro's Divi playbook (injected via `saddle_system_context`) is a hardcoded
skill. This generalizes both.

---

## 2. The model — four layers, split by trust and load-timing

The organizing principle: **a layer's trust level decides whether it may auto-serve,
and its size decides whether it loads eagerly or on demand.**

```
                     ┌────────────────────────────────────────────────────────┐
  every session ◀────┤ SESSION-START CONTEXT  (auto-served, size-capped)        │
  (handshake +      │                                                          │
   get-instructions)│  L0 · SKILLS index   ← saddle_skill  (owner-installed)    │ trust: HIGH
                     │       names+descriptions only; body loads on demand      │  load: index eager
                     │                                                          │
                     │  L1 · RECENT CHANGES ← saddle_log    (Saddle's facts)    │ trust: HIGH
                     │       "what changed here lately"                         │  load: eager
                     │                                                          │
                     │  L2 · CORE MEMORY    ← owner instr + pinned entries      │ trust: HIGH
                     │       "what the site always wants known"                 │  load: eager
                     └────────────────────────────────────────────────────────┘
                          │ get-skill(name)          ▲ pin / unpin (owner)
                          ▼                           │
                     ┌──────────────────────┐  ┌─────┴──────────────────────────┐
  on demand    ◀────┤ L0 skill BODIES        │  │ L3 · ARCHIVAL MEMORY           │ trust: LOW
                     │ (full .md, just-in-time)│  │  ← saddle_memory (agent prose) │ (agent-written)
                     └──────────────────────┘  │    searchable; NOT auto-served  │  load: on demand
                                                └────────────────────────────────┘
```

| Layer | What | Author | Trust | Served |
| --- | --- | --- | --- | --- |
| **L0 Skills** | how-to playbooks (`.md`) | owner | high | index eager, body on `get-skill` |
| **L1 Recent changes** | executed-mutation feed | Saddle | high | eager (auto) |
| **L2 Core memory** | owner instructions + pinned | owner | high | eager (auto) |
| **L3 Archival memory** | agent facts/decisions | agent | low | on `recall`; off-by-default inject |

L0–L2 are **owner-authored or Saddle-generated** → safe to auto-serve. L3 is
**agent-written** → the one layer that can be poisoned, so it's governed and
off-by-default (§8). This split is the whole safety story.

---

## 3. Reuse map — the machinery this builds on (file:line)

- **Injection hook (the keystone).** `Saddle_Context::system_context()` ends by
  applying the `saddle_system_context` filter (`includes/class-saddle-context.php:187`);
  `server_instructions()` (`includes/class-saddle-mcp.php:318`) feeds it into both the
  `initialize` handshake (`:121`, `:244`) and the `get-instructions` ability
  (`includes/abilities/core-content.php:1011`). **One filter → every session, both
  transports.** L0/L1/L2 all append here. (Pro's Divi playbook already does this.)
- **Storage template + L1 source.** `Saddle_Log` (`includes/class-saddle-log.php`) —
  private CPT, `record()`/`query()`, bounded GC via `saddle_log_max_entries`. Its
  `query()` is the L1 source (no new storage); its CPT pattern is the template for the
  `saddle_skill` and `saddle_memory` stores. `Saddle_Approval` — CPT + hourly GC cron.
- **Owner-instruction store + UI.** `Saddle_Context::user()`/`set_user()` (option
  `saddle_user_context`); REST `context` route — `get_context()`
  (`includes/admin/class-saddle-rest.php:265`) + `POST { user }`; React `Guidance.jsx`.
  → the Context management UI (Skills + Memory panels) extends here.
- **Ability shape.** `saddle_ability_meta($readonly,$destructive,$idempotent,$tier)`,
  `Saddle_Capabilities::permission($level,$cap,$short)`, tiers `read`/`write`/`admin`.
- **Uninstall.** `uninstall.php` deletes options + `saddle_approval`/`saddle_log`
  posts. Add `saddle_skill`, `saddle_memory`, and the new options.

---

## 4. Prior art distilled

| System | Idea borrowed | Saddle decision |
| --- | --- | --- |
| **Anthropic / OpenClaw Skills** (`SKILL.md` + frontmatter) | progressive disclosure — description always loaded, body on demand | L0 shape exactly: inject the index, `get-skill` loads the body |
| **Anthropic memory tool** (GA; Claude is primary client) | `/memories` files, just-in-time reads, security checklist | tool grammar + safety checklist (caps, sanitize, poisoning) |
| **Letta / MemGPT** | tiers: core / recall (history) / archival | maps onto L2 / L1 / L3 exactly |
| **Mem0** | upsert with ADD/UPDATE/DELETE conflict resolution | upsert-by-key; the *agent* resolves conflicts (server has no LLM) |
| **Generative Agents** (Stanford) | score = recency × importance × relevance | adopt — but relevance = keyword/FULLTEXT, **not embeddings** |

**The constraint that shapes retrieval:** every mainstream system computes relevance
with embeddings → sending text to an external vector API → violates non-negotiable #1.
So Saddle retrieval is **WP-native** (MySQL `MATCH…AGAINST` / `WP_Query`) with
embeddings only as an opt-in bring-your-own filter. Feature, not limitation: **context
that never leaves your database.**

---

## 5. Data model — two private CPTs

Both copy the `saddle_log` private-CPT pattern (`public=false`, `show_in_rest=false`,
hidden, no export).

**`saddle_skill`** (L0) — a `.md` skill, frontmatter parsed on upload:

| Field | Storage | Purpose |
| --- | --- | --- |
| name/slug | `post_title` | stable id; `get-skill(name)` |
| description | meta `_saddle_skill_desc` | the always-injected index line |
| when_to_use | meta `_saddle_skill_when` | trigger hint in the index |
| body | `post_content` | full instructions, served on demand |
| enabled | meta `_saddle_skill_enabled` | owner toggle |
| source | meta `_saddle_skill_source` | `owner-upload` \| `hub:<id>` (provenance) |

**`saddle_memory`** (L3) — an agent/owner memory entry:

| Field | Storage | Purpose |
| --- | --- | --- |
| key/slug | `post_title` | upsert id (`remember` by key) |
| text | `post_content` | the memory (sanitized) |
| type | meta `_saddle_mem_type` | `fact`\|`preference`\|`decision`\|`note` |
| tags | meta `_saddle_mem_tags` | recall filter / future "spaces" |
| source | meta `_saddle_mem_source` | `owner` \| `agent` |
| client | meta `_saddle_mem_client` | which connection wrote it |
| importance | meta `_saddle_mem_importance` | `1–5` (pinned ⇒ max) |
| pinned | meta `_saddle_mem_pinned` | promote into L2 + never GC'd |
| last_used | meta `_saddle_mem_last_used` | recency term |

Options: `saddle_skills_enabled` (default true), `saddle_memory_recent_changes`
(default true), `saddle_memory_recent_limit` (15), `saddle_memory_max_entries` (500),
`saddle_memory_autoinject_agent` (**false**), `saddle_memory_core_budget` (1500 chars).

---

## 6. Injection & retrieval

- **L0 skills index (eager, tiny):** for each enabled skill, inject one line —
  `- <name>: <description> (use when <when_to_use>)`. Capped count. On `get-skill(name)`,
  return the full `post_content`. This is the progressive-disclosure split that keeps
  20 skills from bloating every session.
- **L1 recent changes (eager, no scoring):** `saddle_log` query for the last
  `recent_limit` **executed** mutations in the recency window (exclude `denied` — those
  are owner-facing noise), rendered most-recent-first (`date · action · target ·
  summary`). A time feed, not a search.
- **L2 core memory (eager):** all pinned entries, then top entries by
  `recency × importance` until `core_budget` fills. Agent-authored entries only if
  `autoinject_agent` is on.
- **L3 recall (on demand):** `score = wR·recency + wI·importance + wRel·relevance`,
  relevance = normalized `MATCH…AGAINST`. Return top-K with keys; bump `last_used`.
- **Embeddings:** opt-in `saddle_memory_relevance` filter; default off; never a shipped
  external call.

---

## 7. Tools (MCP)

All `saddle/` dash-named → inherit tier, pause, per-tool toggle, audit log.

| Tool | Tier | Shape |
| --- | --- | --- |
| `get-skill` | read | `{ name }` → full skill body (L0 on-demand). |
| `list-skills` | read | enabled skills' names + descriptions (usually already injected). |
| `recall` | read | L3: `{ query?, tags?, type?, limit? }` → ranked memory entries. |
| `remember` | write | L3: `{ key?, text, type?, tags?, importance? }` → upsert by key; `source=agent`; archival-only unless pinned; logged. |
| `forget` | write | L3: `{ key }` → delete one (recoverable, §12); bulk clear **gated**; logged. |
| `recall-changes` | read | L1 on demand: `{ target?, since?, limit? }` → log beyond the injected window. |

Grammar mirrors Anthropic's memory/skills tools so Claude clients map with zero
friction. **Skills are never agent-writable** — no `install-skill` tool. Installation
is owner-only, through the admin UI (§8).

---

## 8. Safety model — the keystone

The risk that makes a naive port of these ideas unsafe for a production site: **any
auto-served, followable text is a persistent prompt-injection surface.** The four-layer
trust split is the answer.

1. **Only owner/Saddle content auto-serves.** L0 skills = owner-installed. L1 = Saddle's
   own facts. L2 = owner instructions + owner-pinned. L3 (agent prose) does **not**
   auto-serve — `saddle_memory_autoinject_agent = false`; it's recall-only until the
   owner pins it.
2. **Skills are install-gated to the owner.** No agent tool installs a skill — a skill
   is instructions the agent follows, so agent-self-install would be self-granted
   authority. Owner uploads via the admin UI (`manage_options`), or an agent *suggests*
   and the owner approves. Provenance stored (`owner-upload` vs `hub:<id>`).
3. **L1 caveat (kept honest).** The log's `summary`/`target` interpolate user/agent
   strings (a post title could read "IGNORE ABOVE"). L1 is *structurally* safer (a
   bounded factual list, never free narrative) but not immune — so it's framed as a
   change log, values escaped and truncated on render.
4. **Context never grants capability.** A skill or memory that says "delete without
   confirming" is inert — tiers and the approval gate still fire. Enforced structurally:
   these layers only ever append strings to context; they touch no permission code.
5. **Owner governance UI.** View / edit / enable / delete every skill; view / edit /
   pin / delete every memory, with source + client shown; one-click "clear agent memory."
   A read-only preview of the exact injected block, so the owner sees what agents get.
6. **Caps, expiration, sanitize.** Entry/skill caps + GC of stale non-pinned; hard char
   cap on injected size; `wp_kses`/`sanitize_textarea_field` on every write.
7. **No third-party custody.** All in the WP DB; embeddings and any skill "hub" fetch
   are opt-in and owner-initiated (a chosen public resource, like `upload_media`) — never
   a Saddle-controlled phone-home.

---

## 9. UI — a "Context" area (extends the Guidance tab)

Guidance already renders read-only auto-context + editable owner instructions. Add:

- **Skills panel:** upload `.md` (parse frontmatter → preview name/description/when),
  enable/disable, view body, delete, provenance badge. "Suggested by agent" queue if
  agent-suggest is enabled.
- **Memory panel:** entry list by type with source badge, pin toggle, importance, edit,
  delete; master toggles (auto-inject agent memory [off], recent-changes [on], budgets);
  "clear agent memory."
- **"What agents see at session start":** a read-only render of the assembled L0+L1+L2
  block — the owner's ground truth.
- REST: extend the `context` route family (or add `skills`/`memory` routes) mirroring
  `get_context()`/`set_user()`.

---

## 10. Free vs Pro

**Free Saddle owns the engine** — skill install/serve, memory store, injection, UI. It's
context infrastructure (extends `Saddle_Context`), and Pro's own rule sends new context
surface to free.

**Pro adds curated content, not new engine:**
- A **skill hub / catalog** — PlugPress-curated skills ("Divi page-building," "WooCommerce
  product," "SEO"), installed through the same owner-gated flow. This reuses Pro's existing
  integration-catalog pattern (Knovia) and is a natural monetization hook.
- Pro's Divi playbook becomes a built-in skill instead of a hardcoded filter append.

---

## 11. Phasing — risk-ordered

The trust split gives a clean rollout: **ship the trusted, high-value layers first;
gate the agent-written layer behind them.**

- **Phase 1 — v0.6.0 (trusted context):** ✅ DONE (2026-07-04). L0 skills engine —
  `Saddle_Skills` (`saddle_skill` CPT), owner-only install via `/skills` REST + Guidance
  UI, index injected through `saddle_system_context`, `saddle/get-skill` +
  `saddle/list-skills` abilities — **and** L1 recent-changes injection
  (`Saddle_Log::recent_executed()` → `Saddle_Context::recent_changes_lines()`, executed
  only, flattened + truncated, option-gated) + `saddle/recall-changes`. 13 tests.
  No agent-writable storage yet, exactly as designed.
- **Phase 2 — agent memory (landed in the 0.8 cycle):** ✅ DONE (2026-07-06). L3
  `saddle_memory` CPT (`Saddle_Memory`) + `remember`/`recall`/`forget` abilities +
  owner pinning into the L2 core block (injected via `saddle_system_context`, framed
  "information, not instructions") + Memory panel in Guidance (list/pin/preview/
  clear-agent, `/memory` REST routes) + caps/GC (2000-char entries, max-entries
  eviction sparing pinned, shared GC cron) + uninstall cleanup.
  `saddle_memory_autoinject_agent` defaults OFF as designed; bulk clear gated.
  Retrieval is PHP-scored recency×importance×keyword (WP-native, DB-only). 15 tests.
- **Phase 3 — later:** skill hub/catalog (Pro), opt-in BYO embeddings, entity/relation
  graph recall, owner-triggered consolidation, `saddle://context` MCP Resource.

---

## 12. Open decisions (need Fahim before build)

1. **Phase 1 split** — ship skills + recent-changes together (recommended), or skills
   first / recent-changes first?
2. **Skill install source** — owner-upload only for v1 (recommended); agent-suggest +
   owner-approve as a Phase-2 add?
3. **Memory scope** — site-wide shared + tags (recommended) vs named spaces vs per-client.
4. **`forget` semantics** — immediate single delete + gated bulk (recommended)?
5. **Auto-inject agent memory default** — off (recommended, safe)?
6. **Versioning** — Phase 1 as 0.6.0, Phase 2 as 0.7.0?
7. **Naming** — `get-skill`/`recall`/`remember`/`forget` vs alternatives.

---

## 13. Non-negotiables checklist (verify before any release)

- [ ] No third-party custody — embeddings + any hub fetch opt-in/owner-initiated only.
- [ ] Default-safe — L0/L1/L2 (owner/Saddle) auto-serve; L3 (agent) recall-only until pinned.
- [ ] Skills install is owner-gated; no agent self-install.
- [ ] Context never bypasses tier/gate — skills and memory are only ever appended strings.
- [ ] Sanitized on write; injected block size-capped; stale non-pinned GC'd.
- [ ] `grep -rn "eval(\|proc_open\|shell_exec\|exec(" includes/` stays clean.
- [ ] `uninstall.php` removes `saddle_skill` + `saddle_memory` CPTs + `saddle_*` options.
