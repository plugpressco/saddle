# The AI app matrix

Phase 1's exit bar (#223): each of six AI apps can connect, read, and make a
gated write that previews before it changes anything. Real apps can't run in
CI or in an agent's sandbox, so `scripts/client-matrix.mjs` replays what each
one sends. A pass with the real app is still the proof; the script tells you
what to expect, and catches a server change that would break an app before
anyone opens it.

Neither file ships: the Gruntfile leaves `scripts/` and `tests/` out of both zips.

## The six apps

How each one connects, and the setup the connect wizard gives for it
(`admin/src/connect-apps.js` is the source; this table follows it).

| App | Paths | Setup | `clientInfo.name` |
|---|---|---|---|
| Claude Code | key, sign-in | `claude mcp add <slug> --scope user --transport http <address> --header "Authorization: Basic …"`. For sign-in, leave out the header, then run `claude`, type `/mcp` and choose Authenticate. | `claude-code` (verified) |
| Claude (web and desktop) | sign-in | Settings → Connectors → Add custom connector, with the address. Older desktop builds use a key through the `mcp-remote` bridge, which sends what Claude Code sends. | `claude-ai` (verified) |
| ChatGPT | sign-in only | Developer mode, then a connector with OAuth. Its form has no field for a header, so it cannot use a key. | `openai-mcp` (assumed) |
| Codex | key, sign-in | `~/.codex/config.toml`: `[mcp_servers.<slug>]` with `url` and `http_headers = { Authorization = "Basic …" }`. For sign-in, leave out the header and run `codex mcp login <slug>`. | `codex-mcp-client` (verified) |
| Gemini CLI | key, sign-in | `gemini mcp add --scope user --transport http <slug> <address> --header "Authorization: Basic …"`. Without the header it finds the sign-in server itself. | `gemini-cli-mcp-client` (assumed) |
| Cursor | key, sign-in | Settings → MCP → Add new server: `{ "mcpServers": { "<slug>": { "url": "<address>", "headers": { … } } } }`. For sign-in, leave out `headers` and click "Needs login". | `cursor-vscode` (verified) |

"Verified" names were read from each app's own code on 2026-09-30
(`includes/class-saddle-connection-apps.php`). Saddle uses the name only to
label the connection under AI apps; it never decides access on it.

A key is an Application Password sent as `Authorization: Basic`. Sign-in is
Saddle's own OAuth server, off until the owner turns it on, and it sends
`Authorization: Bearer`. Both end at the same per-tool gate.

## What each one sends

The script's client profiles, at the top of `scripts/client-matrix.mjs`.

| App | Protocol | Echoes `Mcp-Session-Id` | Sends `MCP-Protocol-Version` | Opens the GET stream | Lists prompts | DELETE on close | Other |
|---|---|---|---|---|---|---|---|
| Claude Code | 2025-06-18 | yes | yes | yes | yes | yes | |
| Claude | 2025-06-18 | yes | yes | no | no | no | |
| ChatGPT | 2025-06-18 | yes | yes | no | no | no | "Refresh actions" sends `tools/list` with no session header |
| Codex | 2025-06-18 | yes | yes | no | no | no | Fails on a 200 with an empty body for a notification (#155) |
| Gemini CLI | 2025-06-18 | yes | yes | yes | yes | no | |
| Cursor | 2025-06-18 | yes | yes | yes | yes | no | |

Where these come from, so you know what to check against the real app:

- **Protocol version:** the version these apps' SDKs sent through 2025. A newer
  build may send 2025-11-25. The script's version check runs `initialize` with
  every version Saddle knows, so an app on any of them is covered.
- **Session, version header, GET stream, DELETE:** what the MCP TypeScript SDK
  client does, which Claude Code and Gemini CLI build on; Cursor's profile
  assumes the same. The others follow the streamable HTTP spec. Saddle's
  built-in transport issues no session; the adapter path does.
- **ChatGPT's refresh:** the 2026-08 diagnosis of its "Refresh actions" button,
  which `Saddle_MCP_Compat` exists to serve.
- **Codex's strict notification:** #155, seen with a real Codex.
- **User-Agent:** the script sends `saddle-client-matrix/1.0 (<app>)`, not the
  app's own. Saddle reads User-Agent only to label a connection that sent no
  `clientInfo`.

If a real app turns out to send something else, change its profile and note
the date here.

## Run the script

Node 22 or newer, no dependencies. Run it against a local or staging site,
never a live one: each run is recorded under AI apps as a connection of the key
you use, and with `--write` the gated write aims a preview at the newest post
(it waits under Needs your OK for 15 minutes).

```bash
SADDLE_USER=admin SADDLE_KEY='abcd efgh ijkl mnop qrst uvwx' \
  node scripts/client-matrix.mjs --site https://example.test
```

- **A key:** Saddle → AI apps → connect any app → use a key. Give it Edit
  content to see the gated write preview. At Read only the write is refused
  with a reason instead, which also passes.
- **Sign-in:** turn on sign-in for apps. Without `--token` (or `SADDLE_TOKEN`)
  the script checks discovery only: the 401 challenge, the protected-resource
  document, the authorization-server metadata, dynamic registration and S256.
  The calls need a token, which a real app holds after the consent screen. On a
  throwaway site you can mint one with `wp eval`, using
  `Saddle_OAuth_Store::save_grant()` and `save_token( 'access', … )` the way
  `Saddle_OAuth_Endpoints::issue_tokens()` does.
- **Plain permalinks:** pass `--endpoint '<site>/?rest_route=/saddle/v1/mcp'`.
- **A self-signed certificate:** pass `--insecure`.
- **One app or one path:** `--clients codex,cursor`, `--path key`.
- **Results to paste:** `--markdown` prints rows for the table below; `--json`
  prints everything.
- **Exit code:** 0 when nothing failed, 1 when a check failed, 2 for bad options.

A throwaway site that works: WordPress Playground CLI with
`WP_ENVIRONMENT_TYPE=local` (keys and sign-in over plain HTTP), pretty
permalinks and `--workers=1`. Mount a copy of the plugin without `includes/lib`
to get the built-in transport every zip runs, or with it for the adapter path.
Playground can answer the very first request with a 302 while it starts; run
again.

## What each step checks

| Step | Passes when |
|---|---|
| discover | Sign-in path, no credential: `initialize` gets 401 with a Bearer challenge naming `resource_metadata`; that document names an authorization server; its metadata offers `registration_endpoint` and S256. Skipped when sign-in is off. |
| initialize | 200 with a result: the version asked for, a `tools` capability and non-empty instructions. |
| initialized | The notification gets 202. A 200 with an empty body fails for Codex and is a note for the others. |
| stream | GET gets an event stream or 405. |
| tools/list | At least one tool; every name matches `^[a-zA-Z0-9_-]{1,64}$`; every input schema is a plain object with no top-level `anyOf`, `oneOf` or `allOf`, which Claude's and OpenAI's APIs refuse. |
| refresh | ChatGPT: `tools/list` with no session header lists the same tools. |
| prompts/list | When the server offers prompts: a list comes back. |
| read | `saddle-get-site-info` returns the site as JSON. |
| gated write (`--write` only) | `saddle-delete-post` on the newest post, with no confirm token: a preview that asks for the token, or a refusal that gives its reason. Never a bare "Permission denied". Nothing changes either way. |
| close | DELETE gets 200, 202, 204 or 405. |
| versions | `initialize` with 2024-11-05, 2025-03-26, 2025-06-18 and 2025-11-25. Saddle negotiates three of them; 2025-03-26 is answered with 2025-11-25, as the spec allows, and is a note. |

## The real-app pass

For each app, on a staging site with sign-in on and a draft post to spare:

1. Connect with the wizard's setup for that app, by key where it has one and by
   sign-in.
2. Ask "What can you see on my WordPress site?". It must name the site.
3. Ask it to move the oldest draft to the trash. It must show the preview and
   ask before it acts. Confirm. The post is in the trash, and Activity shows
   the step.
4. Lower the app to Read only and ask again. It must say the change needs a
   higher access level, not that the site can't do it.

Record the app's version and the date below. File a failure as its own issue.

## Results

### Script, 2026-10-04

WordPress Playground CLI 3.1.56, WordPress latest, PHP 8.3, Saddle at
`chore/150-b2-b`.

| Transport | Credential | Apps | Result |
|---|---|---|---|
| Built-in (every zip) | Key, Read only | Claude Code, Codex, Gemini CLI, Cursor | Pass. 41 tools, 34,891 bytes. The write is refused with the access level. |
| Built-in | Key, Edit content | Claude Code, Codex, Gemini CLI, Cursor | Pass. 69 tools, 70,022 bytes. delete-post previews; the post is unchanged. |
| Built-in | Sign-in token, Edit content | All six | Pass, with discovery. ChatGPT's refresh without a session passes. |
| Adapter (`includes/lib`) | Key and sign-in, Edit content | All six, both paths | Pass. A session is issued, echoed and closed with DELETE (200). |
| Adapter | Key, Read only | Codex | **Fail:** the write to a tool hidden at this level comes back as a bare "Permission denied". This is #292, fixed in the 1.5.0 batch. |

Versions on both transports: 2024-11-05, 2025-06-18 and 2025-11-25 negotiate;
2025-03-26 is answered with 2025-11-25.

### Real apps

| Date | App | Version | Path | Connect | Read | Gated write | Refusal at Read only | Notes |
|---|---|---|---|---|---|---|---|---|
| | Claude Code | | key | | | | | |
| | Claude Code | | sign-in | | | | | |
| | Claude | | sign-in | | | | | |
| | ChatGPT | | sign-in | | | | | |
| | Codex | | key | | | | | |
| | Codex | | sign-in | | | | | |
| | Gemini CLI | | key | | | | | |
| | Cursor | | key | | | | | |
| | Cursor | | sign-in | | | | | |
