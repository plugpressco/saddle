# Connecting apps to Saddle

Saddle turns your WordPress site into something an AI app can talk to. This page
covers every way to connect one, what a connection can and cannot do, and what to
try when it doesn't work.

Your site's address for AI apps is always the same:

```
https://your-site.com/wp-json/saddle/v1/mcp
```

You'll find it on **Saddle → Connections**, under "Connection details & health".

---

## Two ways to connect

There are two, and which one you use is decided by the app, not by you.

**A sign-in key.** Most AI apps let you paste a line of configuration that
includes a key. You create the key in Saddle, paste it into the app, done. This
is the default and it's what Claude, Claude Code, Cursor, VS Code and Gemini CLI
all use.

**A sign-in screen.** Some apps — ChatGPT most notably — have no place to paste a
key. Instead they send you to your own site to approve the connection, the way
"Sign in with Google" works. Saddle supports this too, but it's switched **off**
until you turn it on.

If you're not sure which an app needs: try the key first. If the app's setup
screen has no field for one, it needs the sign-in screen.

---

## Connecting with a sign-in key

Go to **Saddle → Connections → Connect an app**, pick the app, and Saddle shows
you exactly what to paste. Copy it into the app, and you're connected.

A few things worth knowing:

- **The key is shown once.** Saddle keeps only its name and last four characters.
  If you lose it, rotate the connection to get a fresh one — there's no way to
  read the old one back.
- **Each app gets its own key.** Disconnecting one doesn't affect the others.
- **Leaving the wizard early cancels the key.** If you back out before copying,
  Saddle revokes the key it just made rather than leaving an orphan.

### What each app expects

**Claude (desktop app)** — Settings → Developer → Edit Config. Paste the block
Saddle gives you, save, restart Claude. It connects through a small bridge
(`mcp-remote`), because the desktop app speaks the local flavour of MCP.

**Claude Code** — one command in your terminal. Saddle uses `--scope user`
deliberately: the default scope ties the server to the exact folder you ran the
command in, so it silently fails to load anywhere else. User scope means "run
`claude` in any folder and it's there."

**Cursor** — Settings → MCP → Add new server, or save Saddle's block as
`.cursor/mcp.json` in your project.

**Gemini CLI** — one command, same user-scope reasoning as Claude Code.

**VS Code (Copilot agent mode)** — save the block as `.vscode/mcp.json`, then
start the server from the **MCP: List Servers** command.

**Any other MCP app** — most accept the standard block Saddle shows under "Any
MCP app". Look for "Add MCP server" in the app's settings.

---

## Connecting ChatGPT

ChatGPT's connector screen has no field for a sign-in key, so it needs the
sign-in screen instead.

### First, turn it on

**Saddle → Settings → "Sign-in for ChatGPT"**

Two requirements, both checked for you right under the switch:

- **HTTPS.** A sign-in token sent over plain HTTP can be read in transit, so
  Saddle won't enable this on an insecure site.
- **Pretty permalinks.** Settings → Permalinks, anything other than "Plain". Without
  them your site's addresses carry a `?` in the middle, and the sign-in standard
  can't work with that.

If either is missing the switch stays disabled and tells you which one.

While this is off, nothing is published for any app to find. That's deliberate —
off means genuinely invisible, not merely refused.

### Then, in ChatGPT

The menu has been renamed at least once, so go by this path rather than older
guides:

```
Settings → Plugins → Browse plugins → the "+" button (top right)
```

Or go straight to **chatgpt.com/plugins** and click **+**. If the button isn't
there, turn on **Settings → Plugins → Developer mode** first — that's what
reveals it.

Fill in:

| Field | Value |
|---|---|
| Name | anything, e.g. `saddle-mysite` |
| Connection | **Server URL** → your MCP address |
| Authentication | **OAuth** |

Leave client ID and secret **blank**. Saddle registers ChatGPT automatically.

ChatGPT will send you to your own site to approve the connection. Read the
screen, then choose Allow.

### What the approval screen tells you

- **Which app is asking**, and whether Saddle could verify it. Some apps identify
  themselves with a web address that vouches for them — those show as verified.
  Apps that simply registered themselves are marked as such, because nothing about
  their identity was checked.
- **What it will be able to do**, in plain language.
- **Which WordPress account it will act as** — yours. It can never do more than
  that account is allowed to.
- **Where you'll be sent afterwards.**

Only administrators can approve a connection.

### Two things to expect

**On ChatGPT Plus and Pro, connectors are read-only.** Fully write-capable
connectors need a Business, Enterprise or Edu workspace. So if ChatGPT reads your
site happily but won't create a post, nothing is broken — that's ChatGPT's limit,
not Saddle's.

**ChatGPT Go doesn't have connectors at all.** You'll need Plus or above.

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

For sign-in-screen connections there's one extra limit: the app is granted a
level when you approve it, and that acts as its own ceiling. If your site is set
to Read & Write but you only granted an app read access, it gets read — the app
can be given less than the site allows, never more.

---

## Managing connections

Everything lives on **Saddle → Connections**.

**Rotate** replaces an app's key with a fresh one under the same name. The old
key stops working immediately, so the app is disconnected until you paste the new
setup in.

**Disconnect** takes a connection away for good. It stops working immediately —
no waiting for anything to expire. You can always connect the app again later.

Sign-in-screen connections are listed separately, since there's no key involved
and nothing to rotate — only to take away.

Sign-in keys also appear under **Users → Profile → Application Passwords**. Same
keys; revoking in either place works.

**Every action is logged.** Saddle → Activity shows what connected apps actually
did, including refused attempts — which is how you'd notice an app trying things
it shouldn't.

---

## When it doesn't work

### "Connection failed" or every request is refused

Run **Saddle → Connections → Connection details & health → Test connection**.

The most common cause by far is your web server stripping the `Authorization`
header before WordPress sees it, so your key never arrives. Saddle detects this
and, on Apache or LiteSpeed, offers to fix it in one click. On nginx it shows you
the one line to send your host.

### "Your sign-in key was rejected"

The key was revoked, deleted, or mistyped. Reconnect the app from Saddle →
Connections to issue a fresh one.

### "The request arrived without a sign-in key"

The key never reached your site — that's the stripped-header problem above. Run
the connection check.

### "MCP server does not implement OAuth"

Sign-in for ChatGPT is switched off. Saddle → Settings → turn it on, check the
readiness line underneath, then try again in ChatGPT.

### The approval screen says the request expired

Approval requests are only good for 15 minutes. Start the connection again from
the app and approve it when the screen appears.

### The app connected but says it can't do something

Three things to check, in order:

1. **Saddle → Settings** — is Saddle paused?
2. **Saddle → Permissions** — is your access level high enough, and is that
   particular tool switched on?
3. **For sign-in-screen connections** — was the app granted enough when you
   approved it? If not, disconnect and reconnect, approving the higher level.

Saddle tells connected apps *why* they were refused, so a well-behaved app should
relay the reason rather than just failing.

### The app connected but shows no tools at all

Different problem, and worth separating from the one above: the app signed in
successfully, then reports it has no actions it can use.

Open **Saddle → Connections → Client traffic**, press **Record the next hour**,
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
