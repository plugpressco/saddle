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
- **How much of the server instructions an app reads:** Claude Code 2.1.289
  keeps the first 2,048 characters of `initialize`'s `instructions` and drops
  the rest, ending its copy with "… [truncated]" (measured 2026-10-05; a fresh
  site's guide is about 6,900 characters). So anything an agent must not miss
  goes in the first 2,048 characters or in a tool result, and the first line
  points to `saddle/get-instructions` for the rest (#333). Not yet measured
  for the other apps.

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

Claude Code can run the pass without touching your own setup:

- **Key:** put the server in a file of its own and run
  `claude -p --mcp-config <file> --strict-mcp-config --allowedTools "mcp__<slug>__*" --output-format json "<prompt>" < /dev/null`.
  Continue the same conversation with `--resume <session_id>` from the JSON.
  Without `< /dev/null` it waits for input that never comes.
- **Sign-in:** in a scratch folder, `claude mcp add --scope local --transport http <slug> <address>`,
  then `claude mcp login <slug>`. It needs a terminal; `--no-browser` prints the
  address to open instead of opening a browser. Afterwards the `-p` command
  above works with a config file that has the address and no header.
Codex, the same way:

- **Key:** `codex exec --skip-git-repo-check -s read-only -c approval_policy='"never"' -c 'mcp_servers.<slug>.url="<address>"' -c "mcp_servers.<slug>.http_headers={Authorization=\"Basic …\"}" --json -o <file> "<prompt>" < /dev/null`.
  Continue with `... resume <thread_id> "<prompt>"`. Turn off your other MCP
  servers for the run with `-c 'mcp_servers.<name>.enabled=false'`.
- Codex asks for its own approval before a destructive tool, and with
  approvals off it refuses the call. To test Saddle's gate instead, pass that
  one tool through: `-c 'mcp_servers.<slug>.tools.saddle-delete-post.approval_mode="approve"'`.
- **Sign-in:** `codex mcp login <slug> --no-browser -c 'mcp_servers.<slug>.url="<address>"'`
  (it needs a terminal), then the `exec` command above without the header.

Cursor's `cursor-agent`: put the server in a project's `.cursor/mcp.json`,
run `cursor-agent mcp enable <slug>`, and `cursor-agent mcp list-tools <slug>`
proves the connection. Asking it anything needs a Cursor sign-in.

- On WordPress Playground or Studio, sign-in from Claude Code stops before the
  consent screen, because PHP there has no DNS to check Claude Code's identity
  document (#335). Keys work there.

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

### Script, 2026-10-05

WordPress Playground CLI 3.1.57, WordPress 7.1.2, PHP 8.3, the Saddle 1.5.1
zip from WordPress.org (sha256 `001e0a44…0c53b4`), built-in transport.

| Credential | Apps | Result |
|---|---|---|
| Key, Edit content, `--write` | All six profiles, key and sign-in rows | Pass (sign-in rows: token reused, discover skipped) |

Versions: 2024-11-05, 2025-06-18 and 2025-11-25 negotiate; 2025-03-26 is a note.

### Real apps

The same site as the script run above. Sign-in was on for the sign-in rows;
the site is plain HTTP with `WP_ENVIRONMENT_TYPE=local`.
The Codex and Cursor rows ran later the same day, on the 1.5.1 zip with
#334's three files on top, which is `main` at a0f8676.

| Date | App | Version | Path | Connect | Read | Gated write | Refusal at Read only | Notes |
|---|---|---|---|---|---|---|---|---|
| 2026-10-05 | Claude Code | 2.1.289 | key | Pass | Pass: names the site, lists the four drafts, explains its access | **Fail on 1.5.1:** got the preview and confirmed in the same turn without asking (#333). **Pass on `main` since #334 (a0f8676):** quotes the preview, asks, trashes only after "yes" | Pass: says the level needed and points to AI apps; nothing changes | Activity shows "Moved post #4 … to the trash." with Undo. Called the levels "write" and "read" (since #334 it says "Edit content", "Read only") |
| 2026-10-05 | Claude Code | 2.1.289 | sign-in | Pass: client metadata document, consent screen, Edit content chosen | Pass | **Fail on 1.5.1**, as with the key (#333) | Pass | On Playground, needed a test-only DNS stand-in for `claude.ai` (#335) |
| | Claude | | sign-in | | | | | Not run: needs an HTTPS address the web app can reach |
| | ChatGPT | | sign-in | | | | | Not run: needs an HTTPS address the web app can reach |
| 2026-10-05 | Codex | 0.157.0 | key | Pass | Pass: calls get-instructions first, names the site and the drafts, says "Edit content" | Pass on `main` (a0f8676): quotes the preview, "Nothing has changed yet. Shall I confirm?", trashes only after "Yes, confirm" | Pass: says Read only and links to AI apps; nothing changes | Codex asks its own approval for a destructive tool. With approvals off it refuses `delete-post` before Saddle sees it; the run passed that one tool through (see below) to test Saddle's gate |
| 2026-10-05 | Codex | 0.157.0 | sign-in | Pass: client metadata document `chatgpt.com/oauth/codex/client.json`, consent screen, Edit content; client `codex-mcp-client 0.157.0` | Pass | Pass on `main`, as with the key | Pass | On Playground, needed the DNS stand-in for `chatgpt.com` (#335) |
| | Gemini CLI | | key | | | | | Not run: not installed here, and needs a Google sign-in |
| 2026-10-05 | Cursor | 2026.01.23 (`cursor-agent`) | key | Pass: "ready", 69 tools at Edit content; Saddle labels it "Cursor 1.0.0" | | | | Read and the gated write not run: `cursor-agent` needs the owner's Cursor sign-in |
| | Cursor | | sign-in | | | | | Not run: `cursor-agent mcp login` opens a browser, so it needs the owner at the keyboard |
