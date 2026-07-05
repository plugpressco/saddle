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
import McpLogo from '@lobehub/icons-static-svg/icons/mcp.svg';

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

// The Saddle brand mark (a stirrup arch). Same path as the PHP admin-menu
// icon in class-saddle-settings.php — keep the two in sync.
export function BrandMark( props ) {
	return (
		<svg
			viewBox="0 0 24 24"
			width="20"
			height="20"
			fill="currentColor"
			aria-hidden="true"
			focusable="false"
			{ ...props }
		>
			<path d="M12 4c-4.4 0-8 3.6-8 8v7a1 1 0 0 0 1 1h2.5a1 1 0 0 0 1-1v-6.5a3.5 3.5 0 1 1 7 0V19a1 1 0 0 0 1 1H19a1 1 0 0 0 1-1v-7c0-4.4-3.6-8-8-8Z" />
		</svg>
	);
}

// Eye — the "read" / just-looking level.
export function IconRead( props ) {
	return (
		<svg { ...base } { ...props }>
			<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
			<circle cx="12" cy="12" r="3" />
		</svg>
	);
}

// Pencil — the "read & write" / editing level.
export function IconWrite( props ) {
	return (
		<svg { ...base } { ...props }>
			<path d="M12 20h9" />
			<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" />
		</svg>
	);
}

// Sliders — the "manage the site" / admin level.
export function IconAdmin( props ) {
	return (
		<svg { ...base } { ...props }>
			<path d="M4 6h11M19 6h1M4 12h1M9 12h11M4 18h7M15 18h5" />
			<circle cx="17" cy="6" r="2" />
			<circle cx="7" cy="12" r="2" />
			<circle cx="13" cy="18" r="2" />
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

// Sun — light theme.
export function IconSun( props ) {
	return (
		<svg { ...base } { ...props }>
			<circle cx="12" cy="12" r="4" />
			<path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
		</svg>
	);
}

// Moon — dark theme.
export function IconMoon( props ) {
	return (
		<svg { ...base } { ...props }>
			<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" />
		</svg>
	);
}

// Half-filled circle — follow the system theme.
export function IconThemeAuto( props ) {
	return (
		<svg { ...base } { ...props }>
			<circle cx="12" cy="12" r="9" />
			<path d="M12 3a9 9 0 0 1 0 18Z" fill="currentColor" stroke="none" />
		</svg>
	);
}

// Map a theme mode to its icon component.
const THEME_ICONS = { system: IconThemeAuto, light: IconSun, dark: IconMoon };

export function ThemeIcon( { mode, ...props } ) {
	const Cmp = THEME_ICONS[ mode ] || IconThemeAuto;
	return <Cmp { ...props } />;
}

// Map a level key to its icon component.
const LEVEL_ICONS = { read: IconRead, write: IconWrite, admin: IconAdmin };

export function LevelIcon( { name, ...props } ) {
	const Cmp = LEVEL_ICONS[ name ] || IconRead;
	return <Cmp { ...props } />;
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
	other: McpLogo,
	// Legacy keys — connections made before the card lineup changed.
	'claude-desktop': ClaudeLogo,
	vscode: CopilotLogo,
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
	if ( l.includes( 'antigravity' ) ) {
		return 'antigravity';
	}
	return 'other';
}
