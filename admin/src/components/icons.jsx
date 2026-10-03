/**
 * The Saddle brand mark and the AI app logos from @lobehub/icons-static-svg
 * (MIT). Logos are logos, not icons: they keep their own colours.
 *
 * Interface icons are Iconoir (MIT): `NavIcon` in icons/iconoir.js for the
 * menu, tabs and header, and `icons` in icons/kit.js (the same set modules
 * get as `ui.icons`) everywhere else.
 */
import ClaudeCodeLogo from '@lobehub/icons-static-svg/icons/claudecode-color.svg';
import ClaudeLogo from '@lobehub/icons-static-svg/icons/claude-color.svg';
import OpenAILogo from '@lobehub/icons-static-svg/icons/openai.svg';
import CursorLogo from '@lobehub/icons-static-svg/icons/cursor.svg';
import CopilotLogo from '@lobehub/icons-static-svg/icons/copilot-color.svg';
import CodexLogo from '@lobehub/icons-static-svg/icons/codex.svg';
import AntigravityLogo from '@lobehub/icons-static-svg/icons/antigravity-color.svg';
import GeminiLogo from '@lobehub/icons-static-svg/icons/geminicli-color.svg';
import McpLogo from '@lobehub/icons-static-svg/icons/mcp.svg';
import WindsurfLogo from '@lobehub/icons-static-svg/icons/windsurf.svg';
import OpenClawLogo from '@lobehub/icons-static-svg/icons/openclaw-color.svg';
import GrokLogo from '@lobehub/icons-static-svg/icons/grok.svg';
import { ReactComponent as Mark } from '../../../assets/brand/mark.svg';

// The Saddle brand mark (a saddle draped over the horse's back, knocked out
// of a filled disc — the PlugPress portfolio motif), single-sourced from
// assets/brand/mark.svg — the PHP admin-menu icon reads the same file, so
// editing that one SVG rebrands every surface at once.
export function BrandMark( props ) {
	return (
		<Mark
			width={ 20 }
			height={ 20 }
			aria-hidden="true"
			focusable="false"
			{ ...props }
		/>
	);
}

/* ---------- AI app brand logos ----------
 *
 * From @lobehub/icons-static-svg (MIT, imported at the top of this file),
 * bundled at build time via @svgr. VS Code has no lobe icon (the set is
 * AI-focused) — its MCP setup runs Copilot agent mode, so it wears the
 * Copilot mark. "Another app" gets the MCP logo itself.
 */
const APP_LOGOS = {
	claude: ClaudeLogo,
	chatgpt: OpenAILogo,
	'claude-code': ClaudeCodeLogo,
	cursor: CursorLogo,
	'gemini-cli': GeminiLogo,
	vscode: CopilotLogo,
	windsurf: WindsurfLogo,
	openclaw: OpenClawLogo,
	grok: GrokLogo,
	other: McpLogo,
	// Legacy keys — connections made before the card lineup changed.
	'claude-desktop': ClaudeLogo,
	codex: CodexLogo,
	antigravity: AntigravityLogo,
};

// Brand logo for a wizard app key; the MCP mark when unknown.
//
// The svg imports above resolve to data-URI strings in the wp-scripts build
// (the default export is the asset URL, not a component), so these render as
// <img> — rendering them as JSX tags crashes React with InvalidCharacterError.
export function AppLogo( { app, ...props } ) {
	const src = APP_LOGOS[ app ] || McpLogo;
	return (
		<img
			src={ src }
			alt=""
			aria-hidden="true"
			width="20"
			height="20"
			{ ...props }
		/>
	);
}

// Best-effort app key from a stored connection label ("Claude Code 2" →
// claude-code), for the connected-apps list where only the name survives.
export function appKeyFromLabel( label ) {
	const l = ( label || '' ).toLowerCase();
	if ( l.includes( 'claude code' ) ) {
		return 'claude-code';
	}
	if ( l.includes( 'claude' ) ) {
		return 'claude';
	}
	if ( l.includes( 'chatgpt' ) || l.includes( 'openai' ) ) {
		return 'chatgpt';
	}
	if ( l.includes( 'cursor' ) ) {
		return 'cursor';
	}
	if (
		l.includes( 'vs code' ) ||
		l.includes( 'vscode' ) ||
		l.includes( 'copilot' )
	) {
		return 'vscode';
	}
	if ( l.includes( 'codex' ) ) {
		return 'codex';
	}
	if ( l.includes( 'gemini' ) ) {
		return 'gemini-cli';
	}
	if ( l.includes( 'windsurf' ) ) {
		return 'windsurf';
	}
	if ( l.includes( 'openclaw' ) ) {
		return 'openclaw';
	}
	if ( l.includes( 'grok' ) ) {
		return 'grok';
	}
	if ( l.includes( 'antigravity' ) ) {
		return 'antigravity';
	}
	return 'other';
}
