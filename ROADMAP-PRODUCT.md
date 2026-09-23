# Saddle Product Plan

> **Status: Fahim's product direction, adopted 2026-09-23.** This is the
> long-horizon thesis — where Saddle is going over quarters. It is not a task
> list and it does not authorize work on its own.
>
> **How this relates to `ROADMAP.md`.** `ROADMAP.md` is the near-term authorizer:
> what ships next, in order, as GitHub issues. This file is why. When they
> disagree on *timing*, `ROADMAP.md` wins — it is closer to the code. When they
> disagree on *destination*, this file wins.
>
> They currently agree on the next block of work: Phase 1 here ("stable MCP,
> excellent tools, permissions, authentication, Abilities support, good docs") is
> `ROADMAP.md` pillars 1 and 2. The drafts-only policy shipped 2026-09-22 (#186).
>
> **One open decision, recorded here rather than resolved.** Saddle Cloud (§3–§8)
> routes tool calls through infrastructure we operate. `CLAUDE.md` non-negotiable
> #1 currently reads "No site data, content, credentials or tool-call traffic ever
> leaves the owner's WordPress for a server we control. No relay, no proxy," and
> `ROADMAP.md`'s positioning sentence leads with "Nothing leaves your site."
> Before any Cloud code is written — in this repo or a new one — that
> non-negotiable needs an explicit amendment saying how the two coexist (most
> likely: Cloud is a separate, opt-in product and the self-hosted plugin's
> guarantee is unchanged). Until it is written down, an agent reading this repo
> has two contradictory instructions, and the plugin's core promise is the one at
> risk. See §36, which already says the plugin stays small and the runtime lives
> outside WordPress.

## Product Direction

**Saddle should become the agent infrastructure for WordPress.**

The long-term path is:

```text
AI connects to WordPress
        ↓
AI connects to all your WordPress sites
        ↓
Jobs run automatically
        ↓
WordPress AI agents work continuously
```

Saddle should not start as another generic AI employee product.

Its advantage is:

```text
WordPress knowledge
+ WordPress tools
+ WordPress permissions
+ multi-site infrastructure
+ automation
+ agents
```

---

# 0. Do Not Break Current Users

Every section below this one is written as if Saddle were a new product. It is
not. Saddle Pro is sold today, it has a WordPress.org install base, and
plugpress.co makes two promises about it in writing.

**Where a later section conflicts with this one, this one wins.**

## What is already sold

Saddle Pro, annual only, Freemius plugin `33502`. **Verified against the live
pricing page on 2026-09-23** (`plugpress.co` now redirects to `plugpress.io`;
this file still says `.co` in places below):

| Sites | Price / year |
|---|---:|
| 3 | $49 |
| 10 | $99 |
| Unlimited | $199 |

Plus a 3-day trial with no card, and PlugPress One, which bundles "Saddle Pro
with every Pro feature" at $99 / $149 / $249 a year for 3 / 10 / unlimited sites.

> **This table was wrong until 2026-09-23.** It read $29 / $79 / $149 for
> 1 / 5 / 50 sites, and One at $99 / $199 / $399 for 10 / 20 / 100 — numbers
> matching no live plan. Every later section reasoning about "a current 50-site
> Pro customer at $149" (§24, §25, the conflicts table) inherits that error and
> has **not** been re-derived.

> **Still unverified: the authoritative plan list in the Freemius dashboard.**
> The table above is what the pricing *page* renders. R2 needs the real plan
> list and a count of who sits on each, including older plans still renewing
> that the page no longer shows. Do not run a repricing off this table alone.

The pricing page promises:

> Your renewal never goes up.

> 30-day refund, no questions asked. Cancel anytime and your plugins keep working.

The product page promises:

> It can only look at your site until you say otherwise.

Those three sentences are the constraints. The rules below follow from them.

## Decided 2026-09-23: the next price list

Fahim's call. **Not live yet** — this is the target, and R1/R2 govern how it
ships.

Saddle Pro:

| Sites | Price / year | Per month |
|---|---:|---:|
| 5 | $60 | $5 |
| 10 | $84 | $7 |
| 20 | $108 | $9 |

Four decisions behind it:

- **No unlimited tier.** The $199 unlimited plan meant the best-fit customer — a
  large agency — paid the same as an 11-site freelancer. Above 20 sites is now a
  conversation, or PlugPress One.
- **No trial.** Free Saddle on WordPress.org is the evaluation path: unthrottled,
  no expiry, no account. The 30-day refund becomes the risk-reversal and moves
  onto the pricing cards — necessary because free carries no Divi code, so it
  demos the safety model rather than the Pro feature set.
- **PlugPress One moves onto the same brackets**, and also drops unlimited:

  | Sites | Price / year | Per month | Premium over Pro |
  |---|---:|---:|---:|
  | 5 | $108 | $9 | 1.8x |
  | 10 | $180 | $15 | 2.14x |
  | 20 | $204 | $17 | 1.89x |

  Matching brackets means one comparison for a buyer instead of two ladders that
  do not line up.

- **Waggle and Loggle take the same ladder as Saddle Pro** ($60 / $84 / $108 for
  5 / 10 / 20 sites), which makes them standalone SKUs for the first time. Today
  neither has a price: both product pages say "comes with PlugPress One". Noted
  in Saddle's plan because One's bundle math depends on it —

  | Sites | Each | All three | One | Saving |
  |---|---:|---:|---:|---:|
  | 5 | $60 | $180 | $108 | $72 (40%) |
  | 10 | $84 | $252 | $180 | $72 (29%) |
  | 20 | $108 | $324 | $204 | $120 (37%) |

  This also retires the notional figures in One's value-stack copy — it can cite
  real, purchasable prices instead of a $149 Waggle and a $129 Loggle that
  nobody could buy. The cost of unbundling: a buyer who wants only Waggle pays
  $60 rather than being pushed to One at $108. The 29-40% bundle saving is what
  holds One together for anyone wanting two or more.

### Retired 2026-09-23: the Agency tier

There is no Agency tier and no unlimited tier. §25 ("Saddle Agency", $39-79/month,
50-100+ sites) and the Agency block in §50 are **dead** — they are kept below as
written only because the rest of those sections still carries useful thinking, not
because the tier is planned. Above 20 sites is a conversation, or PlugPress One.

In its place, a **lifetime deal (LTD) page**, terms not yet set. Three things to
settle before it ships:

- **Price against retention, not against the annual figure.** At $108/year and a
  four-year life, a 20-site LTD is revenue-neutral around $432. Priced at 3x
  ($324) every annual subscriber who does the arithmetic switches, converting
  recurring revenue to one-time while the support obligation continues forever.
- **Cap it** — limited quantity or a closed window. A permanent LTD on the
  pricing page is an annual plan nobody buys.
- **Separate updates from support.** Freemius supports lifetime updates with
  time-boxed, renewable support. It bounds an otherwise unbounded obligation.

An LTD does not conflict with "your renewal never goes up" — there is no renewal.
It does need its own Freemius plan (R1: never edit a live one).

**Open: does PlugPress One get an LTD?** At any plausible price it would eat the
annual revenue of all three plugins at once.

> **Open: One's 10-site tier is mispriced.** $24 more buys double the sites, so
> anyone who can afford One at 10 sites takes 20 instead; the tier converts to
> near nothing and the customers who would have paid $180 are lost. Pro steps
> evenly (+$24, +$24); One steps +$72 then +$24. The bundle saving squeezes it
> from the other side too — $72 off at 10 sites against $120 off at 20. So the
> top tier is cheaper per site *and* the deeper discount. $9 / $13 / $17 —
> $108 / $156 / $204 — holds both anchors and steps evenly. Raised 2026-09-23,
> not adopted.

**This price is permanent for everyone who buys at it.** "Start low, raise later"
does not apply here: "your renewal never goes up" plus R1 means a customer who
subscribes at $60 renews at $60 for as long as they stay subscribed. A later
raise reaches new plans and new customers only. Priced deliberately at or below
Respira's €9/month at every tier; for scale, WPVibe's comparable tier is $299/yr
and its agency tier $599/yr (see "Why Saddle cannot bill on usage" below).

### What shipping it requires

- Freemius `33502`: create three **new** Saddle Pro plans, trial disabled.
  Publish-but-hide every current plan; they keep renewing untouched. Test-renew
  one real old subscription and confirm it charges the old price (R2). The same
  treatment applies to PlugPress One's own Freemius product — its id is not
  recorded here; get it from the dashboard before starting. Waggle and Loggle
  need products and plans created from scratch, having never been sold alone.
- `plugpress.io/saddle/`: new cards. Delete "Start 3-day trial", "No card
  needed", and "Introductory price" — the last promised a discount the following
  line withdrew. Surface the 30-day refund where the trial CTA was.
- `plugpress.io/pricing/`: new One cards (5 / 10 / 20, no unlimited). The value
  stack reads "Saddle Pro ($199) + Waggle ($149) + Loggle ($129)" → Pro becomes
  $108, which leaves Waggle cited above the flagship's top tier; the two notional
  figures want a second look at the same time.
- `saddle-pro/readme.txt:39` and `assets/freemius/listing.md:59,62-65`: trial
  copy out.
- `plugpress.io/waggle/` and `/loggle/`: both currently say "comes with
  PlugPress One" in place of pricing. Each needs its own cards.
- §24, §25 and "Conflicts to resolve before building" below: re-derive against
  the corrected numbers.

### Why Saddle cannot bill on usage

WPVibe meters **daily tool calls** (Free 100/day, $99 Pro 500, $299 Power 2,000,
$599 Agency 5,000, $899 Scale 10,000; rolling 24-hour window, per account, sites
unlimited and free). That metric tracks delivered value far better than site
count does — and it is closed to Saddle. Counting tool calls requires the calls
to cross a server you control, which is precisely non-negotiable #1 and the
reason to choose Saddle over WPVibe. Their billing model is downstream of the
architecture Saddle exists to reject; adopting it means becoming them.

The inversion is worth stating on the comparison page (#173): WPVibe charges for
usage and gives away sites, Saddle charges for sites and gives away usage.
**Unmetered** belongs next to the no-relay sentence.

---

## R1. Never edit the price of a live Freemius plan

Freemius renews a subscription at the price stored on the plan, not the price
paid. Editing a live plan's price changes what every existing subscriber is
billed at renewal, silently, and breaks "your renewal never goes up" for every
customer at once.

New pricing ships as **new plans**. Old plans stay published and keep renewing.

```text
WRONG
edit plan "Pro 5 sites" 79 → 129

RIGHT
create plan "Pro 5 sites (2027)" at 129
leave "Pro 5 sites" at 79, renewals only, hidden from checkout
```

This is the single most dangerous step in the whole plan. Do it wrong once and
it cannot be undone quietly.

## R2. Grandfather by plan, not by promise

A grandfathered customer is one whose subscription sits on an old plan that
still exists. A note in a spreadsheet is not grandfathering.

Every repricing needs, before it ships:

```text
the old plan still published (renewals only)
the old plan hidden from new checkout
a test renewal on a real old subscription
a count of who is on it
```

## R3. A plugin update never widens permissions

§15 proposes defaults where `Update posts`, `Update SEO metadata` and
`Draft content` are ALLOW. Today's install starts read-only, and the site sells
it on that sentence.

Rule: **an update may only ever narrow what an existing install can do.**

```text
new install        → §15 defaults apply
existing install   → keeps its current grants
a NEW capability   → arrives as ASK or BLOCK, never ALLOW
```

The migration writes the current effective permissions into the new
ALLOW/ASK/BLOCK schema and changes nothing else. If the mapping is ambiguous,
it resolves to the stricter mode.

## R4. Tool names are a public API

§45 lists "tool naming cleanup" in the first 30 days. Users have written those
names into Claude Projects, custom GPTs, Cursor rules and scripts. A rename
breaks all of it, silently, with no error the user can read.

```text
keep the old name as an alias
mark it deprecated in the tool description
never remove one inside 12 months
never change an argument's meaning under the same name
```

Adding an argument is safe if it is optional. Making an argument required is a
rename.

## R5. The direct MCP endpoint keeps working, unchanged

§4 keeps direct MCP, which is right. Make it explicit that this means the
endpoint path, the auth scheme and existing tokens all keep working after Cloud
ships. A user who never opens Saddle Cloud should not notice it was built.

No forced migration. No "connect to Cloud to continue" screen. No token
rotation the user did not ask for.

## R6. Free may only grow

§23 and §28 define a generous Free tier. Good. The risk is the reverse move:
something free on WordPress.org today quietly becoming paid later. That breaks
installed users, and it is also a WordPress.org guideline problem.

Anything shipped free in a .org release stays free in every later .org release.
New paid capability is new, never reclaimed.

## R7. One site counter, and the license is the ceiling

Today "sites" means Freemius license activations. Under Cloud it will also mean
rows in the site registry. Two counters for one number is a support nightmare
and will lock people out of sites they paid for.

```text
the Freemius license activation count is the ceiling
the cloud registry counts the same sites, never a smaller number
a 50-site customer is a 50-site customer in Cloud
```

§24 proposes Pro at "up to 5 sites". A current 50-site Pro customer at $149 must
not land on a 5-site plan. Either their old plan carries its own limit (R1/R2),
or the limit is read from the license, never from the plan name.

## R8. Decide what Cloud means for existing Pro before Pro ships

§27 puts Saddle Cloud in Pro. Existing Pro customers bought "Pro" before Cloud
existed. Two honest answers, one dishonest one:

```text
HONEST  existing Pro gets Cloud, and the new price funds it
HONEST  Cloud is a separate product, and Pro keeps meaning what they bought
BROKEN  "Pro" now includes Cloud, except for the people who already paid for Pro
```

PlugPress One makes this sharper. Its card says "Saddle Pro with every Pro
feature" and "Plugins we release while your license is active". A bundle
customer at $99 will read that as including Cloud. Decide before launch, then
make the bundle copy say it.

## R9. Monthly billing is additive

The FAQ and the pricing cards state that nothing is billed monthly, and the
per-month figure on every card is labelled "billed annually". Adding monthly
plans (§24, §25) is fine. Removing or repricing the annual ones is R1.

## R10. Destructive defaults start where they are or stricter

§33 is right that delete and refund default to ASK. Apply it to the migration
too: no existing install gains the ability to delete anything because of an
update. If a capability did not exist before, it arrives BLOCKED and the user
turns it on.

---

## Conflicts to resolve before building

| Section | Conflict with today | Resolution |
|---|---|---|
| §15 Approval defaults | Install is read-only today; several defaults are ALLOW | R3: defaults apply to new installs only |
| §22–24 Free vs Pro | Free is "1 site + write tools"; that is nearly the $29 Pro tier | Decide what the $29 tier is for, or retire it to renewals only (R1) |
| §24 Pro $99–149 / 5 sites | 5 sites is $79 today | New plan, old plan renews at $79 forever (R1, R2) |
| §25 Agency 50–100 sites | 50 sites is $149 today, Agency is $468–948 | Same. The 50-site customer at $149 keeps $149 |
| §24/§25 site limits | Limits by plan name, not by license | R7: read the limit from the license |
| §27 Cloud in Pro | Existing Pro predates Cloud | R8: pick one answer and write it on the bundle card |
| §45 tool naming cleanup | Names are in users' saved prompts | R4: alias, deprecate, never remove inside a year |
| §36 plugin responsibilities | Existing endpoint and tokens | R5: unchanged, no forced migration |

---

## Pre-launch checklist

Before any pricing or permission change goes live:

- [ ] Old Freemius plans still published, renewals only, hidden from checkout
- [ ] One real old subscription renewed in a test and charged the old price
- [ ] Permission migration mapped, and it only ever narrows
- [ ] Every renamed tool still answers to its old name
- [ ] Direct MCP tested from a client config written before the change
- [ ] A .org install updated in place, with nothing lost and nothing widened
- [ ] PlugPress One's card says what the bundle now includes
- [ ] The renewal promise on plugpress.co still true, word for word

---

# 1. Saddle Today

Current model:

```text
ChatGPT / Claude / Codex / Gemini
                ↓
           Saddle MCP
                ↓
            WordPress
```

Each WordPress site currently has its own Saddle MCP connection.

Saddle should be excellent at secure WordPress tools for content, media, plugins, themes, users, SEO, WooCommerce, Gutenberg, Divi, and WordPress Abilities.

Example tools:

```text
get_posts
get_post
create_post
update_post

search_media
upload_media
set_featured_image

get_plugins
activate_plugin
deactivate_plugin
update_plugin

get_site_info
get_site_health

get_seo_meta
update_seo_title
update_meta_description
```

Later:

```text
get_orders
get_products
get_customers
create_coupon
```

---

# 2. Permissions First

> **Compatibility: R3.** Saddle installs read-only today and the product page
> sells it on that. The new schema applies to new installs; an existing install
> keeps its current grants and an update never widens them.

Every capability should have one of three simple permission modes:

```text
ALLOW
ASK
BLOCK
```

Example:

```text
Read posts              ALLOW
Update posts            ALLOW
Publish posts           ASK
Delete posts            ASK

Read plugins            ALLOW
Update plugins          ASK
Delete plugins          BLOCK

Read Woo orders         ALLOW
Refund Woo orders       ASK
```

The same permission model should work across direct MCP, Saddle Cloud, automations, and agents.

---

# 3. Saddle Cloud

The next major product should be **Saddle Cloud**.

Today:

```text
Claude
 ├── Saddle Site A
 ├── Saddle Site B
 └── Saddle Site C
```

Future:

```text
             AI Client
                ↓
         Saddle Cloud MCP
                ↓
           User Account
                ↓
      ┌─────────┼─────────┐
      ↓         ↓         ↓
   Site A     Site B     Site C
   Saddle     Saddle     Saddle
```

The user connects ChatGPT, Claude, Codex, or another MCP client only once.

Then they can ask:

> Show me every site with outdated plugins.

> Update Divi Torque on all my sites.

> Find sites still running an old PHP version.

> Update the SEO description on these five sites.

Saddle Cloud routes each action to the correct Saddle installation.

---

# 4. Keep Direct MCP

> **Compatibility: R5.** This means the endpoint path, the auth scheme and
> existing tokens all keep working after Cloud ships. No forced migration, no
> "connect to Cloud to continue", no token rotation nobody asked for.

Saddle Cloud should not replace direct MCP.

Offer both:

```text
Direct Saddle MCP
→ privacy-first
→ simple
→ one site
→ great for developers

Saddle Cloud
→ multi-site
→ automation
→ history
→ agents
→ teams
```

Direct MCP should stay useful in the Free plan.

---

# 5. Saddle Cloud Architecture

Suggested first architecture:

```text
AI Client
    ↓
Saddle Cloud MCP
    ↓
Authentication
    ↓
Site Router
    ↓
Task / Permission Layer
    ↓
Saddle on WordPress
```

Suggested Cloudflare stack:

```text
Cloudflare Workers
→ API
→ MCP gateway
→ authentication
→ routing

Cloudflare D1
→ users
→ workspaces
→ sites
→ permissions
→ tasks
→ automations

Cloudflare Queues
→ background jobs
→ bulk operations
→ retries

Cloudflare R2
→ optional logs
→ exports
→ reports
```

---

# 6. Site Connection Flow

Inside WordPress:

```text
Saddle

[ Connect to Saddle Cloud ]
```

After connection:

```text
Connected ✓

Workspace:
Fahim's Workspace

Site:
example.com
```

Behind the scenes:

1. Saddle creates or receives a site identity.
2. A secure credential is established.
3. Saddle Cloud registers the site.
4. The site reports available capabilities.
5. User permissions are applied.
6. The site becomes available through Saddle Cloud MCP.

Users should not manually configure every site's MCP endpoint.

---

# 7. Site Registry

Saddle Cloud needs a central site registry.

Suggested fields:

```text
workspace_id
site_id
site_url
site_name
wordpress_version
php_version
saddle_version
connection_status
last_seen_at
capabilities
```

Example dashboard:

```text
Fahim Workspace

plugpress.co
Online

divitorque.com
Online

example.com
Offline
```

---

# 8. Unified MCP Tool Design

Avoid exposing separate tools for every site.

Bad:

```text
site_a_get_posts
site_b_get_posts
site_c_get_posts
```

Better:

```text
get_posts(site_id, ...)
```

Example:

```json
{
  "site": "plugpress.co",
  "status": "publish",
  "limit": 20
}
```

For bulk operations:

```text
run_on_sites
```

Example idea:

```json
{
  "sites": ["site-a", "site-b", "site-c"],
  "tool": "get_plugin_status",
  "arguments": {
    "plugin": "divi-torque"
  }
}
```

Cloud controls routing and permission checks.

---

# 9. Task Engine

Before building full AI agents, build a reliable task engine.

Every Saddle Cloud action becomes a task.

Example:

```text
Task #1842

Workspace: Fahim
Site: plugpress.co
Action: Update plugin
Plugin: Divi Torque
Source: Claude
Status: completed
```

Task states:

```text
queued
running
waiting_for_approval
completed
failed
cancelled
```

Later:

```text
scheduled
retrying
paused
```

The task engine should power MCP actions, bulk operations, automations, agents, retries, approvals, history, and usage metering.

---

# 10. Activity Log

Users should see everything AI has done.

Example:

```text
Activity

✓ Updated Divi Torque
  plugpress.co

✓ Updated meta description
  site-b.com

● Updating 12 plugins
  site-c.com

⚠ Delete unused plugin?
  Needs approval
```

Each activity should record:

```text
who requested it
which site
which tool
arguments
result
time
status
approval state
```

---

# 11. Bulk WordPress Operations

Multi-site control can become one of Saddle's strongest features.

Examples:

> Update this plugin across 25 sites.

> Find every site running old PHP.

> List every site using Elementor.

> Show every site where Saddle is outdated.

Bulk operations should create child tasks:

```text
Bulk Task
    ↓
25 child tasks
    ↓
Site A
Site B
Site C
...
    ↓
Combined result
```

---

# 12. Saddle Automations

Once Cloud and Tasks are reliable, add Automations.

Examples:

```text
Every morning
→ check site health

Every night
→ check plugin updates

Every Monday
→ run SEO audit

When post published
→ check SEO

When WooCommerce order fails
→ investigate

When new support email arrives
→ classify and prepare response
```

Architecture:

```text
Trigger
   ↓
Create Task
   ↓
Rules / AI
   ↓
Saddle Tools
   ↓
WordPress
   ↓
Result
```

Triggers can come from schedules, WordPress hooks, webhooks, email, WooCommerce events, manual actions, or external APIs.

---

# 13. Start with Rule-Based Automations

Not every automation needs an AI model.

Example:

```text
Every day
→ call get_plugin_updates
→ if updates exist
→ create notification
```

No LLM needed.

Use AI only when reasoning adds value.

This keeps cost low and makes automation more predictable.

---

# 14. Human Approval

Before giving agents more autonomy, build an approval system.

Example:

```text
Needs You

Saddle wants to delete:
unused-plugin/example.php

Reason:
Plugin has been inactive for 180 days.

[Approve]
[Reject]
[View Site]
```

Another:

```text
SEO Agent wants to change:

Old:
Best Divi Carousel

New:
Best Divi Carousel Plugin for Divi 5

[Approve]
[Edit]
[Reject]
```

Permissions decide whether an action runs automatically, asks for approval, or is blocked.

---

# 15. Approval Defaults

> **Compatibility: R3 and R10.** This table is for NEW installs. An existing
> install keeps what it has, a capability that did not exist before arrives as
> ASK or BLOCK, and an ambiguous mapping resolves to the stricter mode.

Suggested defaults:

| Action | Default |
|---|---|
| Read site data | Allow |
| Read posts | Allow |
| Draft content | Allow |
| Update draft | Allow |
| Publish content | Ask |
| Update SEO metadata | Allow or Ask |
| Update plugins | Ask |
| Install plugin | Ask |
| Delete plugin | Ask |
| Delete user | Block/Ask |
| Refund Woo order | Ask |
| Delete site content | Ask |

---

# 16. Saddle Agents

Only after Tasks + Automations + Approval are solid should Saddle introduce agents.

Start with three.

## Site Manager

Responsibilities:

```text
site health
plugin status
WordPress updates
basic security checks
errors
performance signals
maintenance
```

## SEO Agent

Responsibilities:

```text
SEO metadata
broken links
missing titles
missing descriptions
internal links
schema
new post review
```

## Content Agent

Responsibilities:

```text
create drafts
format Gutenberg
update content
upload media
set featured image
internal linking
content refresh
```

---

# 17. Agent Runtime

Concept:

```text
Agent
  ↓
Task
  ↓
LLM
  ↓
tool decision
  ↓
permission check
  ↓
Saddle tool
  ↓
WordPress
  ↓
result
  ↓
LLM continues
```

If permission requires approval:

```text
Agent
  ↓
tool request
  ↓
ASK
  ↓
pause task
  ↓
Needs You
  ↓
human approves
  ↓
resume task
```

Task resumability is important.

---

# 18. AI Provider Strategy

Saddle should be model-independent.

Support:

```text
OpenAI
Anthropic
Google Gemini
OpenRouter
other providers later
```

Start with BYOK.

Benefits:

```text
lower Saddle operating cost
better margins
customer controls provider
no unlimited-token risk
easy experimentation
```

Later Saddle can offer managed AI credits.

---

# 19. Model Routing

Do not use an expensive model for every step.

Example:

```text
simple classification
→ cheap model

basic tool selection
→ cheap model

complex reasoning
→ stronger model

high-risk operation
→ stronger model + approval
```

Later users can select models per agent.

---

# 20. Missions

Missions come later.

A Mission is a higher-level goal made of many tasks.

Example:

```text
Mission:
Prepare site for Black Friday
```

Saddle creates work for several agents:

```text
Site Manager
→ site health check

SEO Agent
→ landing page audit

Content Agent
→ update promotional content

Woo Agent
→ prepare coupon

Human
→ approve coupon
```

Do not build Missions before the task engine is reliable.

---

# 21. Cross-Site Intelligence

This can become a major long-term advantage.

Example:

> Which of my 200 sites need attention?

Saddle can collect:

```text
WordPress versions
PHP versions
plugin versions
site health
SEO problems
errors
WooCommerce status
performance information
```

Then produce:

```text
Critical
3 sites

Needs attention
18 sites

Healthy
179 sites
```

Other commands:

> Show every site using Elementor.

> Find all sites with outdated PHP.

> Update WordPress everywhere except WooCommerce stores.

> Show sites where the same plugin is failing.

---

# 22. Free vs Pro Strategy

Saddle should have a **real free product**.

Free should be useful enough that users install it, understand it, trust it, and recommend it.

Paid plans should charge for **scale, automation, convenience, and operations**, not for the basic ability to use Saddle.

---

# 23. Saddle Free

**Goal:** Make Saddle the easiest way to connect an AI client directly to one WordPress site.

> **Compatibility: R6.** Anything shipped free in a .org release stays free in
> every later one. Note also that this Free tier is close to what the $29
> 1-site Pro tier sells today; §0 flags that as a conflict to resolve.

Suggested Free features:

```text
✓ Direct MCP connection
✓ 1 WordPress site
✓ Core content tools
✓ Basic media tools
✓ Basic site information
✓ Basic plugin information
✓ WordPress Abilities integration
✓ Read permissions
✓ Basic write permissions
✓ Manual AI usage
✓ ChatGPT / Claude / Codex / Gemini compatibility
✓ Local/direct authentication
✓ Basic local activity log
✓ Community support
```

Free should support meaningful actions such as:

```text
read posts
create drafts
update pages
upload media
read plugin status
read site information
basic SEO reads
```

Sensitive actions can still require confirmation.

### Why Free matters

Free creates:

```text
WordPress.org distribution
developer adoption
MCP ecosystem visibility
word of mouth
trust
large install base
```

Free is Saddle's acquisition engine.

**Do not make Free feel like a demo.**

---

# 24. Saddle Pro

**Goal:** People pay when they need cloud convenience, multi-site control, automation, history, and approval workflows.

> **Compatibility: R1, R2, R7, R8.** 5 sites is $79 a year today. This price
> ships as a NEW Freemius plan; the old one stays published and renews at $79
> forever. Site limits read from the license, not from the plan name, so the
> current 50-site customer is not demoted to 5. And decide what Cloud means for
> someone who bought "Pro" before Cloud existed.

Suggested starting price:

```text
$9–15/month
or
$99–149/year
```

Suggested initial limit:

```text
Up to 5 WordPress sites
```

Suggested Pro features:

```text
✓ Everything in Free
✓ Saddle Cloud account
✓ One cloud MCP connection
✓ Multi-site access
✓ Cloud site registry
✓ Cross-site search
✓ Bulk operations
✓ Full activity history
✓ Advanced permissions
✓ Approval inbox
✓ Scheduled tasks
✓ Automations
✓ Retry system
✓ Notifications
✓ Advanced SEO integrations
✓ Advanced plugin/theme management
✓ Basic agent features when launched
✓ Email support
```

This should be the main paid product.

---

# 25. Saddle Agency

> **RETIRED 2026-09-23.** There is no Agency tier. See "Retired 2026-09-23: the
> Agency tier" in §0. Above 20 sites is a conversation, or PlugPress One; an LTD
> page replaces this as the high-end offer. Kept below as written because the
> segment thinking is still useful, not because the tier is planned.

**Goal:** Agencies, freelancers, and operators managing many WordPress websites.

> **Compatibility: R1, R2.** 50 sites is $149 a year today; this tier is
> $468-948. Those customers keep $149 on their existing plan.

Suggested starting range:

```text
$39–79/month
```

Possible limits:

```text
50 sites
100 sites
or tiered limits
```

Agency features:

```text
✓ Everything in Pro
✓ 50–100+ sites
✓ Workspace members
✓ Team access
✓ Role-based permissions
✓ Permission templates
✓ Bulk management
✓ Client/site groups
✓ Advanced automations
✓ Agent fleet
✓ Cross-site reports
✓ Exportable reports
✓ Priority support
✓ Longer activity retention
✓ Usage analytics
✓ Webhooks/API
```

Do not launch too many pricing tiers initially.

---

# 26. Managed AI Add-On

BYOK should remain available.

For users who do not want to manage API keys:

```text
Managed AI Credits

$10
$25
$50
```

Saddle pays the model provider and deducts usage from the user's credits.

Do not offer unlimited AI initially.

Usage should be transparent.

---

# 27. Feature Matrix

> **Compatibility: R8.** This matrix describes the plans as sold from launch.
> It does not describe what someone who bought Pro before Cloud existed is
> entitled to. Write that answer down separately, then make the PlugPress One
> card say it: the bundle currently promises "Saddle Pro with every Pro
> feature".

| Feature | Free | Pro | Agency |
|---|---:|---:|---:|
| Direct MCP | ✓ | ✓ | ✓ |
| Core WordPress tools | ✓ | ✓ | ✓ |
| One-site manual AI control | ✓ | ✓ | ✓ |
| Saddle Cloud |  | ✓ | ✓ |
| Multi-site | 1 | Up to 5 | 50–100+ |
| One cloud MCP endpoint |  | ✓ | ✓ |
| Cloud activity history |  | ✓ | ✓ |
| Bulk operations |  | ✓ | ✓ |
| Advanced permissions | Basic | ✓ | ✓ |
| Human approval inbox | Limited/local | ✓ | ✓ |
| Scheduled jobs |  | ✓ | ✓ |
| Automations |  | ✓ | ✓ |
| AI agents |  | Basic | Advanced |
| Cross-site intelligence |  | Limited | ✓ |
| Team members |  |  | ✓ |
| Site groups |  |  | ✓ |
| Permission templates |  |  | ✓ |
| Reports | Basic | ✓ | Advanced |
| API/Webhooks |  | Limited | ✓ |
| Support | Community | Email | Priority |

---

# 28. What Should Stay Free Forever

Strategically useful features to keep free:

```text
Direct MCP
Basic WordPress content tools
Basic media operations
Basic site information
Basic plugin reads
Basic WordPress Abilities support
Single-site manual AI control
Basic permissions
```

The biggest risk is not free users.

The biggest risk is **no adoption**.

Free direct MCP helps Saddle become infrastructure.

---

# 29. What Should Be Paid

Charge for:

```text
multi-site
cloud MCP
bulk actions
schedules
automations
long history
approvals
agents
team access
cross-site reports
advanced integrations
managed AI
```

This is a healthier paywall than blocking basic WordPress tools.

---

# 30. Natural Upgrade Journey

A user starts:

```text
1 WordPress site
+
Claude
+
Saddle Free
```

Then they add five client sites.

Manually configuring five MCP connections becomes annoying.

Upgrade reason:

> Connect all your WordPress sites through one Saddle Cloud MCP.

Later:

> Check all sites every morning.

Upgrade value:

```text
Automations
```

Later:

> Let my SEO Agent handle safe fixes automatically.

Upgrade value:

```text
Agents
```

This creates a natural Free → Pro → Agency path.

---

# 31. Security Model

Saddle may eventually control powerful WordPress actions.

Core principles:

```text
least privilege
explicit permissions
site isolation
encrypted credentials
short-lived tokens where possible
audit logs
human confirmation for destructive actions
rate limits
replay protection
revocation
```

Every task should carry:

```text
workspace_id
site_id
```

The runtime must verify that the workspace owns the site before executing anything.

Never trust a site identifier supplied by the model without authorization checks.

---

# 32. Credentials

Credentials should not be exposed to the AI model.

Example:

```text
AI:
"call update_post"

Runtime:
checks permission
loads site credential
executes action

AI:
receives only the result
```

---

# 33. Destructive Actions

Examples:

```text
delete user
delete post
delete plugin
delete database data
refund order
change administrator
install arbitrary code
```

Default:

```text
ASK
```

Some dangerous capabilities may start as:

```text
BLOCK
```

until the security model is mature.

---

# 34. Audit Logs

Every operation should record:

```text
workspace
user
agent
site
tool
arguments
permission decision
approval
result
timestamp
```

Sensitive values should be redacted.

---

# 35. Failure and Retry Design

WordPress sites can fail because of:

```text
site offline
timeout
plugin conflict
Cloudflare block
expired credential
PHP fatal error
maintenance mode
API failure
```

Tasks need bounded retry:

```text
attempt 1
↓
failed

wait

attempt 2
↓
failed

wait

attempt 3
↓
failed

mark failed
notify user
```

Never retry forever.

---

# 36. WordPress Plugin Responsibilities

Keep the WordPress plugin relatively small.

Responsibilities:

```text
MCP endpoint
authentication
tool registry
permission enforcement
WordPress operations
Abilities integration
event/webhook sender
cloud registration
local activity information
```

Do not put the full long-running agent runtime inside WordPress.

---

# 37. Saddle Cloud Responsibilities

Saddle Cloud handles:

```text
accounts
workspaces
site registry
multi-site routing
tasks
queues
automations
schedules
agents
approvals
history
billing
usage
notifications
```

WordPress remains the execution environment.

Cloud becomes the orchestration layer.

---

# 38. First Data Model

## users

```text
id
email
name
created_at
```

## workspaces

```text
id
name
owner_user_id
plan
created_at
```

## workspace_members

```text
workspace_id
user_id
role
```

## sites

```text
id
workspace_id
domain
name
status
wordpress_version
php_version
saddle_version
last_seen_at
```

## site_credentials

```text
site_id
encrypted_credential
created_at
rotated_at
```

## permissions

```text
workspace_id
site_id
capability
mode
```

## tasks

```text
id
workspace_id
site_id
source
agent_id
tool
status
input
output
created_at
started_at
finished_at
```

## approvals

```text
id
task_id
status
requested_at
resolved_at
resolved_by
```

## automations

```text
id
workspace_id
name
trigger_type
trigger_config
action_config
enabled
```

## agents

```text
id
workspace_id
name
type
instructions
model_provider
model_name
enabled
```

---

# 39. Notifications

Start with:

```text
Email
WordPress admin
Saddle Cloud dashboard
```

Later:

```text
Slack
Discord
SMS
mobile push
```

Examples:

```text
3 sites need attention.

An automation failed.

Saddle needs approval.

A site disconnected.

SEO Agent completed a task.
```

---

# 40. Integrations

Prioritize WordPress first.

Good early integrations:

```text
WordPress Core
WooCommerce
Yoast
AIOSEO
Rank Math
Gutenberg
Divi
Elementor
```

Later:

```text
Gravity Forms
WPForms
Fluent Forms
MemberPress
LearnDash
Easy Digital Downloads
```

Use customer demand to decide priority.

---

# 41. Divi Advantage

Saddle can have unusually strong Divi support.

Potential capabilities:

```text
read Divi layouts
update Divi content
manage Divi-specific settings
detect Divi version
Divi 4 / Divi 5 awareness
manage supported modules
SEO checks for Divi pages
```

This can be an early differentiator.

---

# 42. InBees Integration

InBees can later become another event source.

Example:

```text
Customer email
     ↓
InBees
     ↓
Support Agent
     ↓
Saddle tools
     ↓
WordPress customer/site information
     ↓
draft response
```

This does not need to be Saddle v1, but the architecture should allow it.

---

# 43. What NOT to Build Yet

Avoid:

```text
❌ full cloud browser
❌ virtual desktop per user
❌ generic AI employees
❌ Slack replacement
❌ visual workflow builder
❌ complex agent-to-agent conversations
❌ custom LLM
❌ huge vector-memory system
❌ 20 different agents
❌ every WordPress integration
```

These increase complexity without strengthening the core WordPress advantage.

---

# 44. Product Phases

## Phase 1: Saddle MCP

Goal:

> Make Saddle the best way for AI to use WordPress.

Build:

```text
stable MCP
excellent tools
permissions
authentication
Abilities support
good docs
ChatGPT support
Claude support
Codex support
Gemini support
```

## Phase 2: Saddle Cloud

Goal:

> One AI connection for all your WordPress sites.

Build:

```text
accounts
workspaces
connect site
site registry
cloud MCP gateway
site routing
multi-site commands
activity history
```

## Phase 3: Tasks

Goal:

> Every operation is reliable and observable.

Build:

```text
task model
queue
task states
logs
retry
cancel
bulk tasks
```

## Phase 4: Automations

Goal:

> Saddle works even when ChatGPT is closed.

Build:

```text
scheduler
WordPress events
webhooks
automation definitions
task creation
notifications
```

## Phase 5: Approval

Goal:

> Give users confidence to allow more automation.

Build:

```text
ALLOW / ASK / BLOCK
Needs You inbox
approve
reject
resume task
audit trail
```

## Phase 6: Agents

Goal:

> WordPress-specific AI workers.

Start with:

```text
Site Manager
SEO Agent
Content Agent
```

## Phase 7: Missions

Goal:

> Users give Saddle outcomes instead of individual tasks.

Example:

```text
"Prepare this site for Black Friday."
```

---

# 45. 90-Day Build Plan

## Days 1–30

Build:

```text
Saddle MCP reliability
permission schema
tool naming cleanup        (R4: alias the old names, never remove)
cloud authentication prototype
site registration
workspace model
cloud MCP proof of concept
```

Success:

```text
1 user
5 sites
1 cloud MCP
AI can choose and operate the correct site
```

## Days 31–60

Build:

```text
site dashboard
task engine
activity log
bulk operations
queue
retry logic
connection health
basic billing
```

Success:

```text
AI can safely perform multi-site work.
```

## Days 61–90

Build:

```text
scheduler
first automations
approval inbox
notifications
BYOK model settings
Pro plan
```

First automations:

```text
daily site health check
plugin update check
weekly WordPress report
SEO check after post publication
```

Success:

```text
Saddle performs useful work without the user opening an AI client.
```

Do not build full autonomous agents in the first 90 days unless the earlier layers are already stable.

---

# 46. MVP Backlog

## WordPress Plugin

- [ ] Stable MCP endpoint
- [ ] Tool registry
- [ ] Permission metadata
- [ ] Direct authentication
- [ ] Connect to Saddle Cloud button
- [ ] Site registration
- [ ] Secure cloud credential
- [ ] Connection heartbeat
- [ ] Capability reporting
- [ ] Event sender
- [ ] Local activity panel

## Saddle Cloud

- [ ] User accounts
- [ ] Workspaces
- [ ] Site registry
- [ ] Cloud MCP endpoint
- [ ] Site resolver
- [ ] Tool proxy
- [ ] Permission checks
- [ ] Task table
- [ ] Queue
- [ ] Activity history
- [ ] Bulk tasks
- [ ] Retry logic

## Pro

- [ ] Billing
- [ ] Site limits
- [ ] Multi-site
- [ ] Automations
- [ ] Scheduled tasks
- [ ] Approval inbox
- [ ] BYOK
- [ ] Email notifications

## Later

- [ ] Site Manager
- [ ] SEO Agent
- [ ] Content Agent
- [ ] Missions
- [ ] Team accounts
- [ ] Agency reporting
- [ ] Managed AI credits

---

# 47. Launch Positioning

Do not initially sell:

> AI employees for WordPress.

Start with:

> **Connect your AI to WordPress.**

For Saddle Cloud:

> **Manage all your WordPress sites from one AI connection.**

For Automations:

> **Let WordPress jobs run even when you're offline.**

For Agents:

> **AI workers built specifically for WordPress.**

---

# 48. Long-Term Architecture

```text
                    SADDLE

       ┌──────────────────────────┐
       │        AI Clients        │
       │                          │
       │ ChatGPT Claude Codex     │
       │ Gemini   Other MCP Apps  │
       └────────────┬─────────────┘
                    │
                    ↓
             Saddle Cloud MCP
                    │
       ┌────────────┼────────────┐
       │            │            │
       ↓            ↓            ↓
     Tasks      Automations    Agents
       │            │            │
       └────────────┼────────────┘
                    ↓
              Permissions
                    ↓
               Approvals
                    ↓
               Site Router
                    ↓
       ┌────────────┼────────────┐
       ↓            ↓            ↓
    Saddle       Saddle       Saddle
    Site A       Site B       Site C
       ↓            ↓            ↓
  WordPress      WordPress     WordPress
```

---

# 49. Core Product Rule

When deciding whether a feature belongs in Saddle, ask:

> **Does this make AI better at safely operating WordPress?**

If yes, it probably belongs.

If it is generic AI infrastructure with no WordPress advantage, it can probably wait.

---

# 50. Recommended Initial Packaging

## Saddle Free

```text
Direct MCP
1 WordPress site
Core WordPress tools
Basic media
Basic permissions
Basic local history
Manual AI control
Community support
```

## Saddle Pro

```text
Everything in Free
Saddle Cloud
Up to 5 sites
One cloud MCP endpoint
Multi-site control
Activity history
Bulk operations
Advanced permissions
Approvals
Automations
Scheduled jobs
BYOK
Basic agents later
```

Suggested starting price:

```text
$9–15/month
or
$99–149/year
```

## Saddle Agency

```text
Everything in Pro
50–100+ sites
Team access
Site groups
Permission templates
Advanced automation
Advanced agents
Cross-site reports
API/Webhooks
Priority support
```

Suggested starting price:

```text
$39–79/month
```

---

# Final Direction

The product progression should be:

```text
Saddle
AI can use WordPress.
```

↓

```text
Saddle Cloud
AI can use all your WordPress sites.
```

↓

```text
Saddle Automations
WordPress work happens automatically.
```

↓

```text
Saddle Agents
AI workers continuously manage WordPress.
```

The key is the order:

**MCP → Cloud → Tasks → Automations → Approvals → Agents → Missions**

Do not start by cloning Squad.

Build the WordPress execution infrastructure first.

That infrastructure is the moat.
