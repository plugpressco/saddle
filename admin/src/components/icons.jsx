/**
 * Small inline SVG icons — monochrome, currentColor, stroke-based — plus the
 * AI app brand logos from @lobehub/icons-static-svg (MIT).
 *
 * The UI icons stay local rather than pulling @wordpress/icons: that package
 * isn't reliably enqueued in every wp-admin context, and inline SVGs give a
 * consistent, restrained, premium look we fully control.
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

const base = {
	width: 20,
	height: 20,
	viewBox: '0 0 24 24',
	fill: 'none',
	stroke: 'currentColor',
	strokeWidth: 1.6,
	strokeLinecap: 'round',
	strokeLinejoin: 'round',
	'aria-hidden': true,
	focusable: false,
};

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

// Bell — other plugins' notices, parked in the top bar.
export function IconBell( props ) {
	return (
		<svg { ...base } { ...props }>
			<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
			<path d="M13.7 21a2 2 0 0 1-3.4 0" />
		</svg>
	);
}

// Plug — connecting an app.
export function IconConnect( props ) {
	return (
		<svg { ...base } { ...props }>
			<path d="M9 2v6M15 2v6" />
			<path d="M7 8h10v3a5 5 0 0 1-10 0V8Z" />
			<path d="M12 16v6" />
		</svg>
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
