/**
 * The connectable-apps catalog and per-app setup builders — shared by the
 * connect wizard (live credential) and the per-connection setup guide
 * (placeholder mode, since a key is only ever shown once).
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

export const APPS = [
	{
		key: 'claude',
		label: __( 'Claude', 'saddle' ),
		kind: __( 'Desktop app', 'saddle' ),
		how: __(
			'In Claude: Settings → Developer → Edit Config. Paste this inside, save, and restart the app.',
			'saddle'
		),
		next: __( 'Restart Claude, then ask it about your site.', 'saddle' ),
	},
	{
		key: 'chatgpt',
		label: __( 'ChatGPT', 'saddle' ),
		// Chat and Work specifically: since the July 2026 merge the same desktop
		// app also contains Codex, which connects a completely different way and
		// has its own card below.
		kind: __( 'Chat and Work', 'saddle' ),
		// OAuth apps sign in through Saddle's consent screen — the wizard
		// mints no Application Password for them and watches the OAuth
		// connections list (not the key's last_used) for the live flip.
		auth: 'oauth',
		how: __(
			'In ChatGPT on the web: turn on Developer mode (Settings → Apps & Connectors → Advanced settings), then create a connector. Paste the address, choose OAuth, and leave the client ID and secret blank. ChatGPT sends you here to approve it — the connector then works in the desktop app too.',
			'saddle'
		),
		next: __(
			'Enable the connector in a ChatGPT chat and ask it about your site.',
			'saddle'
		),
	},
	{
		// The other half of the same desktop app. Codex reads a config file on
		// the user's own machine rather than being fetched by OpenAI's servers,
		// so it accepts a plain header — no OAuth, no discovery, and it can
		// reach a local site the connector path can't see at all.
		key: 'codex',
		label: __( 'Codex', 'saddle' ),
		kind: __( 'In the ChatGPT app', 'saddle' ),
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
		key: 'claude-code',
		label: __( 'Claude Code', 'saddle' ),
		kind: __( 'Terminal', 'saddle' ),
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
		key: 'cursor',
		label: __( 'Cursor', 'saddle' ),
		kind: __( 'Code editor', 'saddle' ),
		how: __(
			'In Cursor: Settings → MCP → Add new server. Paste this (or save it as .cursor/mcp.json).',
			'saddle'
		),
		next: __( 'Open Cursor’s chat and ask it about your site.', 'saddle' ),
	},
	{
		key: 'gemini-cli',
		label: __( 'Gemini CLI', 'saddle' ),
		kind: __( 'Terminal', 'saddle' ),
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
		key: 'vscode',
		label: __( 'VS Code', 'saddle' ),
		kind: __( 'Copilot (agent mode)', 'saddle' ),
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
		key: 'other',
		label: __( 'Any MCP app', 'saddle' ),
		kind: __( 'Everything else', 'saddle' ),
		how: __(
			'Most AI apps accept this standard setup — look for “Add MCP server” in their settings and paste it there.',
			'saddle'
		),
		next: __( 'Open your app and ask it about your site.', 'saddle' ),
	},
];

// Assemble the setup text for one app from an auth token (real or placeholder).
function assemble( app, auth ) {
	const header = `Authorization: Basic ${ auth }`;

	switch ( app ) {
		// One CLI command, native HTTP transport. User scope, not the default
		// local scope: local binds the server to the exact directory string the
		// command runs in, so it silently fails to load from any other folder
		// (or even the same folder reached via different path casing). A site
		// credential belongs to the user, not to whatever cwd they happened to
		// be in — user scope makes "run claude in any folder" actually true.
		case 'claude-code':
			return `claude mcp add ${ SLUG } --scope user --transport http ${ MCP_URL } \\\n  --header "${ header }"`;

		// Gemini CLI — one command, native HTTP transport, user scope (so it
		// loads from any folder, same reasoning as Claude Code above).
		case 'gemini-cli':
			return `gemini mcp add --scope user --transport http ${ SLUG } ${ MCP_URL } \\\n  --header "${ header }"`;

		// VS Code (Copilot agent mode) — .vscode/mcp.json uses `servers` (not
		// `mcpServers`) with an explicit `type: "http"`.
		case 'vscode':
			return JSON.stringify(
				{
					servers: {
						[ SLUG ]: {
							type: 'http',
							url: MCP_URL,
							headers: { Authorization: `Basic ${ auth }` },
						},
					},
				},
				null,
				2
			);

		// ChatGPT connects by URL from its Connectors screen, and its form has
		// no field for a custom HTTP header — only "no authentication", an API
		// key, or OAuth. So there is nowhere to put a Basic credential, and the
		// key this wizard just minted is irrelevant here: ChatGPT signs in
		// through Saddle's own consent screen instead.
		case 'chatgpt':
			return [
				`${ __( 'Name', 'saddle' ) }:           ${ SLUG }`,
				`${ __( 'Address', 'saddle' ) }:        ${ MCP_URL }`,
				`${ __( 'Authentication', 'saddle' ) }: ${ __(
					'OAuth (leave client ID and secret blank)',
					'saddle'
				) }`,
			].join( '\n' );

		// Codex — TOML, the only target that uses it. `http_headers` takes
		// arbitrary static values, so the same Basic credential every other
		// header app gets works here; the connector path next door cannot carry
		// one at all. The table name is a TOML bare key, and SLUG's hyphens are
		// legal in one, so it needs no quoting.
		//
		// startup_timeout_sec is raised off its short default deliberately:
		// shared WordPress hosting has been measured answering in 5-16s, and a
		// handshake that times out surfaces as a broken credential rather than
		// a slow site, which is the wrong thing to go debugging.
		case 'codex':
			return [
				`[mcp_servers.${ SLUG }]`,
				`url = "${ MCP_URL }"`,
				`http_headers = { Authorization = "Basic ${ auth }" }`,
				'startup_timeout_sec = 30',
			].join( '\n' );

		// Native HTTP with headers.
		case 'cursor':
		case 'other':
			return JSON.stringify(
				{
					mcpServers: {
						[ SLUG ]: {
							url: MCP_URL,
							headers: { Authorization: `Basic ${ auth }` },
						},
					},
				},
				null,
				2
			);

		// Claude — stdio via mcp-remote.
		default:
			return JSON.stringify(
				{
					mcpServers: {
						[ SLUG ]: {
							command: 'npx',
							args: [
								'-y',
								'mcp-remote',
								MCP_URL,
								'--header',
								header,
							],
						},
					},
				},
				null,
				2
			);
	}
}

/**
 * The copy-pasteable setup for the given app, credentials filled in.
 *
 * @param {string} app      App key from APPS.
 * @param {string} password The raw application password (shown once).
 * @return {string} Ready-to-paste setup.
 */
export function buildConfig( app, password ) {
	// OAuth apps carry no key at all — their setup is identical to the guide
	// form, and calling this without a password must never throw.
	if ( ! password ) {
		return buildGuideConfig( app );
	}
	return assemble(
		app,
		btoa( `${ USER }:${ password.replace( WHITESPACE, '' ) }` )
	);
}

/**
 * The same setup with a readable placeholder where the credential goes —
 * for the per-connection guide, since a key is only ever shown once.
 *
 * @param {string} app App key from APPS.
 * @return {string} Setup text with a PASTE-YOUR-KEY-HERE marker.
 */
export function buildGuideConfig( app ) {
	return assemble( app, PLACEHOLDER_AUTH );
}
