/**
 * The connectable-apps catalog and per-app setup builders — shared by the
 * connect wizard (live credential) and the per-connection setup guide
 * (placeholder mode, since a key is only ever shown once).
 *
 * Two ways to connect, and the catalog says which each app supports:
 *
 * - **address** — the app gets the site's MCP address and nothing else. It
 *   registers itself with Saddle's sign-in server, opens the owner's browser,
 *   and the owner approves it on Saddle's consent screen. No key to paste, no
 *   header, no bridge. This is the recommended path once the owner has turned
 *   sign-in on (it is off by default, and the wizard offers the switch).
 * - **key** — the app gets a Basic-auth header carrying an Application
 *   Password. The fallback for CI, plain-HTTP or plain-permalink sites where
 *   sign-in can't be turned on, and older Claude Desktop builds.
 */
import { __ } from '@wordpress/i18n';
import { saddleData } from './api';

export const MCP_URL = saddleData.mcpUrl || '';
export const USER = saddleData.user || '';
// Per-site server name ("saddle-plugpress") so five connected sites show as
// five distinct servers in the client, not five entries all named "saddle".
export const SLUG = saddleData.serverSlug || 'saddle';
const WHITESPACE = /\s/g;

// What stands in for the base64 credential in placeholder configs. Reads as
// an instruction, never as a working value.
const PLACEHOLDER_AUTH = 'PASTE-YOUR-KEY-HERE';

/**
 * The example first message. Kept short so it fits one line in most apps.
 */
export const HELLO_PROMPT = __(
	'What can you see on my WordPress site?',
	'saddle'
);

/**
 * Each app: `viaAddress` / `viaKey` say which connection paths it supports;
 * `howAddress` is the instruction for the address path, `how` for the key
 * path (the historical name — the setup guide and older callers read it).
 */
export const APPS = [
	{
		key: 'claude',
		label: __( 'Claude', 'saddle' ),
		kind: __( 'Web & desktop app', 'saddle' ),
		viaAddress: true,
		viaKey: true,
		howAddress: __(
			'In Claude (claude.ai or the desktop app): Settings → Connectors → Add custom connector. Give it a name, paste the address, leave the OAuth client ID and secret blank, and click Add. Claude sends you here to approve it.',
			'saddle'
		),
		// Older desktop builds only speak the local flavour of MCP, so the key
		// path runs through the small mcp-remote bridge. Everything current
		// takes a custom connector by address instead.
		how: __(
			'Older Claude desktop builds: Settings → Developer → Edit Config. Paste this inside, save, and restart the app. It connects through a small bridge (mcp-remote), which needs Node installed.',
			'saddle'
		),
		next: __(
			'Open a chat, make sure the connector is enabled, and ask Claude about your site.',
			'saddle'
		),
	},
	{
		key: 'claude-code',
		label: __( 'Claude Code', 'saddle' ),
		kind: __( 'Terminal', 'saddle' ),
		viaAddress: true,
		viaKey: true,
		howAddress: __(
			'Paste this into your terminal and press Enter. Then run claude, type /mcp, pick this server and choose Authenticate — it opens your browser and sends you here to approve it.',
			'saddle'
		),
		how: __(
			'Paste this into your terminal and press Enter. That’s the whole setup.',
			'saddle'
		),
		next: __(
			'Run claude in any folder and ask it about your site.',
			'saddle'
		),
	},
	{
		key: 'chatgpt',
		label: __( 'ChatGPT', 'saddle' ),
		// Chat and Work specifically: since the July 2026 merge the same desktop
		// app also contains Codex, which connects a completely different way and
		// has its own card below.
		kind: __( 'Chat and Work', 'saddle' ),
		// ChatGPT's connector form has no field for a header, so the address
		// path is the only one. The wizard mints no key for it.
		viaAddress: true,
		viaKey: false,
		howAddress: __(
			'In ChatGPT on the web: turn on Developer mode (Settings → Apps & Connectors → Advanced settings), then create a connector. Paste the address, choose OAuth, and leave the client ID and secret blank. ChatGPT sends you here to approve it — the connector then works in the desktop app too.',
			'saddle'
		),
		// Worth saying on the screen rather than in a support email: OpenAI has
		// gated write-capable custom connectors to Business, Enterprise and Edu
		// workspaces at various points, and on a personal plan ChatGPT can end up
		// offered every tool and still decline to use the ones that change
		// anything. That looks identical to a Saddle permission problem.
		next: __(
			'Enable the connector in a ChatGPT chat and ask it about your site. If it can read but refuses to change anything, check your ChatGPT plan — write-capable custom connectors have been limited to Business, Enterprise and Edu workspaces.',
			'saddle'
		),
	},
	{
		// The other half of the same desktop app. Codex reads a config file on
		// the user's own machine, so it takes either path: the address plus a
		// one-time `codex mcp login`, or a plain header.
		key: 'codex',
		label: __( 'Codex', 'saddle' ),
		kind: __( 'In the ChatGPT app', 'saddle' ),
		viaAddress: true,
		viaKey: true,
		howAddress: __(
			'Codex reads a settings file. Open ~/.codex/config.toml, paste this at the end and save. Then run the login command from the last line in a terminal — Codex opens your browser and sends you here to approve it.',
			'saddle'
		),
		how: __(
			'Codex reads a settings file. Open ~/.codex/config.toml, paste this at the end, save, then restart the app. The codex terminal command reads the same file.',
			'saddle'
		),
		next: __(
			'Open Codex in the ChatGPT app and ask it about your site.',
			'saddle'
		),
	},
	{
		key: 'cursor',
		label: __( 'Cursor', 'saddle' ),
		kind: __( 'Code editor', 'saddle' ),
		viaAddress: true,
		viaKey: true,
		howAddress: __(
			'In Cursor: Settings → MCP → Add new server. Paste this (or save it as .cursor/mcp.json). Cursor shows a “Needs login” button next to the server — click it and approve the connection here.',
			'saddle'
		),
		how: __(
			'In Cursor: Settings → MCP → Add new server. Paste this (or save it as .cursor/mcp.json).',
			'saddle'
		),
		next: __( 'Open Cursor’s chat and ask it about your site.', 'saddle' ),
	},
	{
		key: 'vscode',
		label: __( 'VS Code', 'saddle' ),
		kind: __( 'Copilot (agent mode)', 'saddle' ),
		viaAddress: true,
		viaKey: true,
		howAddress: __(
			'Save this as .vscode/mcp.json in your project, then start the server from the MCP: List Servers command. VS Code opens your browser and sends you here to approve it.',
			'saddle'
		),
		how: __(
			'Save this as .vscode/mcp.json in your project, then start the server from the MCP: List Servers command.',
			'saddle'
		),
		next: __(
			'Open Copilot Chat in agent mode and ask it about your site.',
			'saddle'
		),
	},
	{
		key: 'gemini-cli',
		label: __( 'Gemini CLI', 'saddle' ),
		kind: __( 'Terminal', 'saddle' ),
		viaAddress: true,
		viaKey: true,
		howAddress: __(
			'Paste this into your terminal and press Enter. The first time Gemini uses the server it opens your browser and sends you here to approve it.',
			'saddle'
		),
		how: __(
			'Paste this into your terminal and press Enter. That’s the whole setup.',
			'saddle'
		),
		next: __(
			'Run gemini in any folder and ask it about your site.',
			'saddle'
		),
	},
	{
		key: 'windsurf',
		label: __( 'Windsurf', 'saddle' ),
		kind: __( 'Code editor', 'saddle' ),
		viaAddress: true,
		viaKey: true,
		howAddress: __(
			'In Windsurf: Settings → MCP → Add custom server. Paste this and save. Windsurf opens your browser and sends you here to approve it.',
			'saddle'
		),
		how: __(
			'In Windsurf: Settings → MCP → Add custom server. Paste this and save.',
			'saddle'
		),
		next: __( 'Open Cascade and ask it about your site.', 'saddle' ),
	},
	{
		key: 'other',
		label: __( 'Any MCP app', 'saddle' ),
		kind: __( 'Everything else', 'saddle' ),
		viaAddress: true,
		viaKey: true,
		howAddress: __(
			'Most AI apps accept this standard setup — look for “Add MCP server” in their settings and paste it there. If the app supports signing in, it opens your browser and sends you here to approve it; if it only takes a key, use the key instead.',
			'saddle'
		),
		how: __(
			'Most AI apps accept this standard setup — look for “Add MCP server” in their settings and paste it there.',
			'saddle'
		),
		next: __( 'Open your app and ask it about your site.', 'saddle' ),
	},
];

/**
 * The Name / Address / Authentication lines for apps that connect from a
 * form rather than a config file.
 *
 * @return {string} Three labelled lines.
 */
function formLines() {
	return [
		`${ __( 'Name', 'saddle' ) }:           ${ SLUG }`,
		`${ __( 'Address', 'saddle' ) }:        ${ MCP_URL }`,
		`${ __( 'Authentication', 'saddle' ) }: ${ __(
			'OAuth (leave client ID and secret blank)',
			'saddle'
		) }`,
	].join( '\n' );
}

/**
 * Assemble the setup text for one app.
 *
 * @param {string}      app  App key from APPS.
 * @param {string|null} auth Base64 credential (real or placeholder) for the
 *                           key path, or null for the address path.
 * @return {string} Ready-to-paste setup.
 */
function assemble( app, auth ) {
	const byAddress = null === auth;
	const header = byAddress ? '' : `Authorization: Basic ${ auth }`;
	const headers = byAddress ? {} : { headers: { Authorization: header } };

	switch ( app ) {
		// One CLI command, native HTTP transport. User scope, not the default
		// local scope: local binds the server to the exact directory string the
		// command runs in, so it silently fails to load from any other folder
		// (or even the same folder reached via different path casing). A site
		// credential belongs to the user, not to whatever cwd they happened to
		// be in — user scope makes "run claude in any folder" actually true.
		case 'claude-code':
			return byAddress
				? `claude mcp add ${ SLUG } --scope user --transport http ${ MCP_URL }`
				: `claude mcp add ${ SLUG } --scope user --transport http ${ MCP_URL } \\\n  --header "${ header }"`;

		// Gemini CLI — one command, native HTTP transport, user scope (so it
		// loads from any folder, same reasoning as Claude Code above). Without
		// a header it discovers Saddle's sign-in server on its own.
		case 'gemini-cli':
			return byAddress
				? `gemini mcp add --scope user --transport http ${ SLUG } ${ MCP_URL }`
				: `gemini mcp add --scope user --transport http ${ SLUG } ${ MCP_URL } \\\n  --header "${ header }"`;

		// VS Code (Copilot agent mode) — .vscode/mcp.json uses `servers` (not
		// `mcpServers`) with an explicit `type: "http"`.
		case 'vscode':
			return JSON.stringify(
				{
					servers: {
						[ SLUG ]: {
							type: 'http',
							url: MCP_URL,
							...headers,
						},
					},
				},
				null,
				2
			);

		// Form-based apps: ChatGPT's connector screen, and Claude's custom
		// connector. Neither has a field for a custom header, so the address
		// path is the only shape shown for them here; Claude's key fallback is
		// the mcp-remote bridge in the default branch below.
		case 'chatgpt':
			return formLines();

		case 'claude':
			if ( byAddress ) {
				return formLines();
			}
			break;

		// Codex — TOML, the only target that uses it. On the address path the
		// login is a separate command, kept on the last line as a TOML comment
		// so one paste still carries the whole setup.
		//
		// startup_timeout_sec is raised off its short default deliberately:
		// shared WordPress hosting has been measured answering in 5-16s, and a
		// handshake that times out surfaces as a broken credential rather than
		// a slow site, which is the wrong thing to go debugging.
		case 'codex':
			return byAddress
				? [
						`[mcp_servers.${ SLUG }]`,
						`url = "${ MCP_URL }"`,
						'startup_timeout_sec = 30',
						`# ${ __(
							'Then, in a terminal:',
							'saddle'
						) } codex mcp login ${ SLUG }`,
				  ].join( '\n' )
				: [
						`[mcp_servers.${ SLUG }]`,
						`url = "${ MCP_URL }"`,
						`http_headers = { Authorization = "Basic ${ auth }" }`,
						'startup_timeout_sec = 30',
				  ].join( '\n' );

		// Windsurf names the field serverUrl.
		case 'windsurf':
			return JSON.stringify(
				{
					mcpServers: {
						[ SLUG ]: {
							serverUrl: MCP_URL,
							...headers,
						},
					},
				},
				null,
				2
			);

		// Native HTTP, with or without headers.
		case 'cursor':
		case 'other':
			return JSON.stringify(
				{
					mcpServers: {
						[ SLUG ]: {
							url: MCP_URL,
							...headers,
						},
					},
				},
				null,
				2
			);

		default:
			break;
	}

	// Claude's key path — stdio via mcp-remote.
	return JSON.stringify(
		{
			mcpServers: {
				[ SLUG ]: {
					command: 'npx',
					args: [ '-y', 'mcp-remote', MCP_URL, '--header', header ],
				},
			},
		},
		null,
		2
	);
}

/**
 * The copy-pasteable setup for the given app.
 *
 * @param {string} app      App key from APPS.
 * @param {string} password The raw application password (shown once). Ignored
 *                          on the address path.
 * @param {string} mode     'address' or 'key'.
 * @return {string} Ready-to-paste setup.
 */
export function buildConfig( app, password, mode = 'key' ) {
	if ( 'address' === mode ) {
		return assemble( app, null );
	}
	// A key-path call without a key must never throw — fall back to the
	// placeholder form.
	if ( ! password ) {
		return buildGuideConfig( app, 'key' );
	}
	return assemble(
		app,
		btoa( `${ USER }:${ password.replace( WHITESPACE, '' ) }` )
	);
}

/**
 * The same setup with a readable placeholder where the credential goes —
 * for the per-connection guide, since a key is only ever shown once. The
 * address path has no credential, so it is the real thing.
 *
 * @param {string} app  App key from APPS.
 * @param {string} mode 'address' or 'key'.
 * @return {string} Setup text, with a PASTE-YOUR-KEY-HERE marker on the key path.
 */
export function buildGuideConfig( app, mode = 'key' ) {
	return assemble( app, 'address' === mode ? null : PLACEHOLDER_AUTH );
}

/**
 * The instruction for an app on a given path.
 *
 * @param {Object} app  An APPS entry.
 * @param {string} mode 'address' or 'key'.
 * @return {string} Where the setup goes.
 */
export function howFor( app, mode ) {
	if ( 'address' === mode ) {
		return app.howAddress || app.how || '';
	}
	return app.how || app.howAddress || '';
}
