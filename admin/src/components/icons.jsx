/**
 * Small inline SVG icons — monochrome, currentColor, stroke-based.
 *
 * Deliberately local rather than pulling @wordpress/icons: that package isn't
 * reliably enqueued in every wp-admin context, and inline SVGs give a
 * consistent, restrained, premium look we fully control.
 */

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

// Map a level key to its icon component.
const LEVEL_ICONS = { read: IconRead, write: IconWrite };

export function LevelIcon( { name, ...props } ) {
	const Cmp = LEVEL_ICONS[ name ] || IconRead;
	return <Cmp { ...props } />;
}
