# Connecting apps to Saddle

Saddle turns your WordPress site into something an AI app can talk to. This page
covers every way to connect one, what a connection can and cannot do, and what to
try when it doesn't work.

Your site's address for AI apps is always the same:

```
https://your-site.com/wp-json/saddle/v1/mcp
```

You'll find it on **Saddle → Apps**, under "Connection details & health".

---

## Two ways to connect

**By address.** You paste the address above into the app and nothing else. The
app introduces itself to your site, opens your browser, and you approve it on
your own site — the way "Sign in with Google" works, except the sign-in screen
is yours. No key to copy, nothing to install. This is the way to connect once
you have turned **sign-in for apps** on (it's off by default; the wizard offers
the switch). Claude, Claude Code, ChatGPT, Codex, Cursor, VS Code, Gemini CLI
and Windsurf all connect this way.

**With a key.** Saddle creates a sign-in key, you paste a short block of
configuration that contains it. This is the fallback: for a site that can't
offer sign-in (no HTTPS, or plain permalinks), for scripts and CI, and for
older Claude desktop builds. ChatGPT can't use a key at all.

Go to **Saddle → Apps → Connect an app**, pick the app, and Saddle shows you
exactly what to paste for whichever way applies. If sign-in is on, every card
leads with the address and offers "Use a key instead"; if it's off, the wizard
explains what's needed and hands out keys.

---

## Connecting by address

### First, turn sign-in on

The wizard offers a **Turn on sign-in** button above the app cards. The same
switch lives under **Saddle → Settings → Sign-in for apps**. Two requirements,
both checked for you:

- **HTTPS.** A sign-in token sent over plain HTTP can be read in transit, so
  Saddle won't enable this on an insecure site.
- **Pretty permalinks.** Settings → Permalinks, anything other than "Plain".
  Without them your site's addresses carry a `?` in the middle, and the sign-in
  standard can't work with that.

While this is off, nothing is published for any app to find. That's deliberate —
off means genuinely invisible, not merely refused. Turning it off later
disconnects every app that signed in this way.

### Then, in the app

Each card in the wizard says where the address goes. In short:

**Claude (claude.ai and the desktop app)** — click **Add to Claude**: claude.ai
opens its Add custom connector dialog with the name and address filled in;
click Add. Or do it by hand: Settings → Connectors → Add custom connector. Name
it, paste the address, leave the OAuth client ID and secret blank, click Add.
Either way Claude sends you to your site to approve it.

**Claude Code** — one command in your terminal:
`claude mcp add <name> --scope user --transport http <address>`. Then run
`claude`, type `/mcp`, pick the server and choose **Authenticate**. Saddle uses
`--scope user` deliberately: the default scope ties the server to the exact
folder you ran the command in, so it silently fails to load anywhere else.

**ChatGPT** — see the ChatGPT section below; it has a couple of quirks of its
own.

**Codex** — paste the block into `~/.codex/config.toml`, then run
`codex mcp login <name>` in a terminal. Codex opens your browser.

**Cursor** — click **Add to Cursor**: Cursor opens with the server filled in
and asks you to confirm. Or paste the block in Settings → MCP → Add new server
(or save it as `.cursor/mcp.json`). Cursor shows a **Needs login** button next
to the server; click it.

**VS Code (Copilot agent mode)** — click **Add to VS Code** (or **Add to VS Code
Insiders**): VS Code opens with the server filled in and asks you to confirm.
Or save the block as `.vscode/mcp.json` and start the server from the
**MCP: List Servers** command. VS Code opens your browser.

The install buttons are links the page builds itself from the same setup the
copy block shows. Nothing is fetched and nothing goes through a server: the
link only tells the app on your computer what to add. On the key path the
button carries the fresh key, exactly as the copy block does.

**Gemini CLI** — one command, same user-scope reasoning as Claude Code. The
first time Gemini uses the server it opens your browser.

**Windsurf** — Settings → MCP → Add custom server, paste the block, save.

**OpenClaw** — run the two lines the wizard shows in a terminal on the computer
running OpenClaw: `openclaw mcp add … --transport streamable-http --auth oauth`,
then `openclaw mcp login …`, which opens your browser. The transport has to be
named; left out, OpenClaw uses SSE.

**Grok** (grok.com and the apps) — Connectors → New Connector → Custom, paste
the address, continue. Grok signs in through your browser. On a Grok Business
team an admin adds the connector first. Grok takes no header, so it needs
sign-in for apps turned on.

**Any other MCP app** — paste the standard block. If the app supports signing
in, it opens your browser; if it only takes a key, use the key instead.

### What the approval screen tells you

- **Which app is asking**, and whether Saddle could verify it. Some apps identify
  themselves with a web address that vouches for them — those show as verified.
  Apps that simply registered themselves are marked as such, because nothing about
  their identity was checked.
- **What it will be able to do**, in plain language, never more than the site
  allows.
- **Which WordPress account it will act as** — yours. It can never do more than
  that account is allowed to.
- **Where you'll be sent afterwards.**

Only administrators can approve a connection. A request is good for 15 minutes.

---

## Connecting with a key

Pick the app in the wizard and choose **Use a key instead** (or, with sign-in
off, the wizard hands out keys by default). Copy the block into the app, and
you're connected.

A few things worth knowing:

- **The key is shown once.** Saddle keeps only its name and last four characters.
  If you lose it, rotate the connection to get a fresh one — there's no way to
  read the old one back.
- **Each app gets its own key.** Disconnecting one doesn't affect the others.
- **Leaving the wizard early cancels the key.** If you back out before copying,
  Saddle revokes the key it just made rather than leaving an orphan.
- **Older Claude desktop builds** connect through a small bridge
  (`mcp-remote`), which needs Node installed. Current builds take a custom
  connector by address instead.

---

## Connecting ChatGPT

ChatGPT's connector screen has no field for a sign-in key, so it always
connects by address. Turn sign-in on first (above).

### In ChatGPT

The menu has been renamed more than once, so go by this path rather than older
guides:

```
Settings → Apps & Connectors → Advanced settings → Developer mode (on)
```

then create a connector. Fill in:

| Field | Value |
|---|---|
| Name | anything, e.g. `saddle-mysite` |
| Connection | **Server URL** → your MCP address |
| Authentication | **OAuth** |

Leave client ID and secret **blank**. Saddle registers ChatGPT automatically.
ChatGPT will send you to your own site to approve the connection. Read the
screen, then choose Allow. The connector then works in the desktop app too.

### What to expect

Re-checked against OpenAI's own help pages on 2026-09-07. In their current
vocabulary Saddle is an **app** (the thing that connects to an external service);
a **plugin** is a bundle of apps and skills you install from the Plugins
Directory. Installing a plugin never bypasses an app's own limits, so everything
below applies whichever screen you reach Saddle through.

**On ChatGPT Plus and Pro, custom apps are read-only.** OpenAI's developer-mode
page says full MCP support, including write and modify actions, is available to
Business, Enterprise and Edu workspaces, and that Pro users can connect MCP apps
with read/fetch permissions only. So if ChatGPT reads your site happily but won't
create a post or upload media, nothing is broken — that's ChatGPT's limit, not
Saddle's. A Business workspace is the only fix.

**ChatGPT Go doesn't have apps at all.** You'll need Plus or above.

**On a workspace plan, the tool list is frozen when an admin approves it.** ChatGPT
keeps a snapshot of Saddle's tools from the moment the connection is published.
Saddle only ever adds optional parameters, so an existing connection keeps working
across updates — but a brand-new tool won't appear until an admin opens the app in
Workspace settings and refreshes its actions.

**Agent mode never uses custom apps, and deep research only reads.** Ask in a
normal chat, with the app selected, for anything that writes.

**Uploading media from the chat.** A file you attach in the chat lives in
ChatGPT's sandbox with no address Saddle can fetch. Since 1.4.0 the app can send
the file itself (inline upload); older clients need a file that is already
online, or the Unsplash tools.

---

## What a connection can actually do

Every connection, whichever way it was made, runs into the same limits.

**It only opens Saddle's door.** A Saddle key works at the MCP address and
nowhere else. It can't be used against the rest of the WordPress API, it can't be
used over XML-RPC, and it can't reach Saddle's own settings — so a connected app
can never raise its own access level or hand itself a new key.

**Your access level is the ceiling.** New sites start at **Read**. Raise it on
**Saddle → Permissions** when you want more. Individual tools can be switched off
there too.

**The pause switch beats everything.** Saddle → Settings. Pausing refuses every
request from every app instantly, without forgetting anything — resuming puts it
all back exactly as it was.

**Deletions always ask twice.** Anything that deletes or overwrites returns a
preview first, along with a single-use code that expires in 15 minutes. Nothing
destructive happens in one step, ever.

**It can't outrank the account.** A connection acts as a specific WordPress user
and inherits that user's permissions. An editor's connection can't touch things
an editor couldn't touch by hand.

For connections made by address there's one extra limit: the app is granted a
level when you approve it, and that acts as its own ceiling. If your site is set
to Read & Write but you only granted an app read access, it gets read — the app
can be given less than the site allows, never more.

---

## Managing connections

Everything lives on **Saddle → Apps**.

**Rotate** replaces an app's key with a fresh one under the same name. The old
key stops working immediately, so the app is disconnected until you paste the new
setup in.

**Disconnect** takes a connection away for good. It stops working immediately —
no waiting for anything to expire. You can always connect the app again later.

Connections made by address are listed separately, since there's no key involved
and nothing to rotate — only to take away.

Sign-in keys also appear under **Users → Profile → Application Passwords**. Same
keys; revoking in either place works.

**Every action is logged.** Saddle → Activity shows what connected apps actually
did, including refused attempts — which is how you'd notice an app trying things
it shouldn't.

---

## When it doesn't work

### "Connection failed" or every request is refused

Run **Saddle → Apps → Connection details & health → Test connection**.

The most common cause by far is your web server stripping the `Authorization`
header before WordPress sees it, so your key never arrives. Saddle detects this
and, on Apache or LiteSpeed, offers to fix it in one click. On nginx it shows you
the one line to send your host.

### "Your sign-in key was rejected"

The key was revoked, deleted, or mistyped. Reconnect the app from Saddle →
Apps to issue a fresh one.

### "The request arrived without a sign-in key"

The key never reached your site — that's the stripped-header problem above. Run
the connection check.

### "MCP server does not implement OAuth" or "Needs login" never finishes

Sign-in for apps is switched off. Turn it on from the wizard or under Saddle →
Settings, check the readiness line underneath, then add the server again in the
app.

### The approval screen says the request expired

Approval requests are only good for 15 minutes. Start the connection again from
the app and approve it when the screen appears.

### The app connected but says it can't do something

Three things to check, in order:

1. **Saddle → Settings** — is Saddle paused?
2. **Saddle → Permissions** — is your access level high enough, and is that
   particular tool switched on?
3. **For connections made by address** — was the app granted enough when you
   approved it? If not, disconnect and reconnect, approving the higher level.

Saddle tells connected apps *why* they were refused, so a well-behaved app should
relay the reason rather than just failing.

### The app connected but shows no tools at all

Different problem, and worth separating from the one above: the app signed in
successfully, then reports it has no actions it can use.

Open **Saddle → Apps → Client traffic**, press **Record the next hour**,
then ask the app to refresh its actions. Each attempt appears as a row, and the
result column is what tells the two causes apart:

- **"refused"** with a status code — the request never reached Saddle's tools.
  Usually a hosting security layer sitting in front of WordPress.
- **"0 tools sent"** — Saddle answered but had nothing to offer, which points at
  the site rather than the connection.
- **No row at all** — the request never reached WordPress. Almost always a
  firewall, CDN or security plugin. This is the case nothing else can show you.

**Copy report** gives a plain-text summary you can paste into a support email —
it has no keys or content in it, only what was asked for and what came back.

---

## Do I need the MCP Adapter plugin?

No. Saddle speaks MCP on its own, and there is nothing else to install.

You may see the *MCP Adapter* plugin mentioned elsewhere — it's a separate
WordPress plugin doing a similar job. If it happens to be active on your site,
Saddle notices and uses it instead. That's the only difference, and it changes
nothing you can see: same address, same tools, same access levels, same
approvals. The Transport line under *Connection details & health* says which one
is in play.

---

## Privacy

Nothing about your site leaves it. Saddle has no servers — the MCP address points
at your own WordPress, and connections are inbound. There's no telemetry, no
phone-home, and no third party holding your credentials.

Sign-in tokens are never stored in readable form, only as a one-way fingerprint,
so a database backup contains nothing anyone could sign in with.

The full list of outbound requests Saddle can make — all of them started by you —
is in the plugin's readme under **External services**.
