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
		kind: __( 'Web + desktop', 'saddle' ),
		how: __(
			'In ChatGPT: Settings → Connectors → Create. Paste the address, and use the sign-in details wherever ChatGPT asks for authentication.',
			'saddle'
		),
		next: __(
			'Enable the connector in a ChatGPT chat and ask it about your site.',
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
		key: 'windsurf',
		label: __( 'Windsurf', 'saddle' ),
		kind: __( 'Code editor', 'saddle' ),
		how: __(
			'In Windsurf: open the Cascade MCP settings and “Add custom server”, or save this into ~/.codeium/windsurf/mcp_config.json.',
			'saddle'
		),
		next: __( 'Open Cascade and ask it about your site.', 'saddle' ),
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

		// Windsurf (Cascade) — mcp_config.json uses `serverUrl` for remote HTTP.
		case 'windsurf':
			return JSON.stringify(
				{
					mcpServers: {
						[ SLUG ]: {
							serverUrl: MCP_URL,
							headers: { Authorization: `Basic ${ auth }` },
						},
					},
				},
				null,
				2
			);

		// ChatGPT connects by URL from its Connectors screen — hand over the
		// address and the sign-in details as plain fields to fill in.
		case 'chatgpt':
			return [
				`${ __( 'Name', 'saddle' ) }:    ${ SLUG }`,
				`${ __( 'Address', 'saddle' ) }: ${ MCP_URL }`,
				`${ __( 'Header', 'saddle' ) }:  ${ header }`,
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
