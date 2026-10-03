#!/usr/bin/env node
/**
 * The Saddle family's icons, drawn from Iconoir (MIT, iconoir.com).
 *
 * `npm run icons` copies every icon named in the manifest below from
 * `node_modules/iconoir/icons/regular/` to `assets/icons/<name>.svg`, and
 * writes `admin/src/icons/iconoir.js`. Both outputs are committed. The files
 * in `assets/icons/` are the allowlist (K2 in planning/NAV-STRUCTURE.md): PHP
 * draws the menu icons from them, the admin app imports them through svgr,
 * and a module descriptor may only name an icon that has a file there.
 *
 * Each copy differs from Iconoir's file in two ways:
 *
 * - `width` and `height` are removed from the root `<svg>`. svgr's svgo
 *   preset drops the viewBox when both are present, and without the viewBox
 *   the icon cannot scale. The size is set where the icon is drawn.
 * - A child's `stroke-width` that repeats the root's value is removed, so the
 *   root's value (the kit's `strokeWidth` prop) reaches every stroke.
 *
 * Running it twice changes nothing: files are written only when their
 * content differs, and an icon that left the manifest is deleted.
 *
 * To add an icon: check the name exists in iconoir@7.12.1/icons/regular/,
 * add it to NAV or UI below, run `npm run icons`, commit both outputs.
 */
import {
	existsSync,
	mkdirSync,
	readFileSync,
	readdirSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const PACKAGE = join( ROOT, 'node_modules/iconoir' );
const SOURCE = join( PACKAGE, 'icons/regular' );
const OUT_SVG = join( ROOT, 'assets/icons' );
const OUT_JS = join( ROOT, 'admin/src/icons/iconoir.js' );
const VERSION = '7.12.1';

/**
 * The menu, the tabs and the header. A module descriptor's `icon` and
 * `tab_icons` name one of these (or any other file in assets/icons).
 */
const NAV = [
	// The Saddle submenu.
	'home-simple-door',
	'graph-up',
	'search-engine',
	'send-mail',
	'sparks',
	'puzzle',
	'brain',
	'settings',
	// Tabs.
	'dashboard-dots',
	'reports',
	'eye',
	'search-window',
	'multiple-pages',
	'link',
	'mail-out',
	'group',
	// The header.
	'nav-arrow-left',
	'bell',
];

/**
 * `ui.icons`: the key a module reads, and the Iconoir icon it is drawn with
 * (K3). The keys never change and never disappear; new ones are added on
 * request.
 */
const UI = {
	Activity: 'activity',
	AlertCircle: 'warning-circle',
	AlertTriangle: 'warning-triangle',
	ArrowLeft: 'arrow-left',
	ArrowLeftRight: 'arrow-separate',
	ArrowRight: 'arrow-right',
	ArrowUpRight: 'arrow-up-right',
	BarChart: 'stats-report',
	Bell: 'bell',
	BookOpen: 'open-book',
	Check: 'check',
	CheckCircle: 'check-circle',
	ChevronDown: 'nav-arrow-down',
	ChevronLeft: 'nav-arrow-left',
	ChevronRight: 'nav-arrow-right',
	ChevronUp: 'nav-arrow-up',
	Clock: 'clock',
	Copy: 'copy',
	Dashboard: 'dashboard-dots',
	Download: 'download',
	ExternalLink: 'open-new-window',
	Eye: 'eye',
	EyeOff: 'eye-closed',
	FileText: 'page',
	Filter: 'filter',
	Globe: 'globe',
	Grid: 'view-grid',
	Grip: 'drag',
	Help: 'help-circle',
	Home: 'home-simple-door',
	Inbox: 'mail-in',
	Info: 'info-circle',
	Key: 'key',
	Link: 'link',
	List: 'list',
	Loader: 'refresh-double',
	Lock: 'lock',
	Mail: 'mail',
	Minus: 'minus',
	MoreHorizontal: 'more-horiz',
	MoreVertical: 'more-vert',
	Pencil: 'edit-pencil',
	Plug: 'plug-type-a',
	Plus: 'plus',
	Refresh: 'refresh',
	Search: 'search',
	Send: 'send',
	Settings: 'settings',
	Shield: 'shield',
	ShieldCheck: 'shield-check',
	Sparkles: 'sparks',
	Star: 'star',
	Trash: 'trash',
	TrendingUp: 'graph-up',
	Upload: 'upload',
	User: 'user',
	Users: 'group',
	Wand: 'magic-wand',
	X: 'xmark',
	XCircle: 'xmark-circle',
	Zap: 'flash',
};

/**
 * Iconoir's file, made ready for svgr and for PHP.
 *
 * @param {string} svg The file as Iconoir ships it.
 * @return {string} The copy.
 */
function clean( svg ) {
	const text = svg.replace( /\r\n?/g, '\n' ).trim();
	const open = text.match( /^<svg\b[^>]*>/ );
	if ( ! open ) {
		throw new Error( 'Not an SVG file.' );
	}
	const root = open[ 0 ]
		.replace( /\s(?:width|height)="[^"]*"/g, '' )
		.replace( /\s+>$/, '>' );
	if ( ! /\sviewBox="/.test( root ) ) {
		throw new Error( 'The root <svg> has no viewBox.' );
	}
	const stroke = root.match( /\sstroke-width="([^"]*)"/ );
	let body = text.slice( open[ 0 ].length );
	if ( stroke ) {
		body = body.split( ` stroke-width="${ stroke[ 1 ] }"` ).join( '' );
	}
	return `${ root }${ body }\n`;
}

/**
 * `home-simple-door` → `HomeSimpleDoor`.
 *
 * @param {string} name Iconoir name.
 * @return {string} A component identifier.
 */
function component( name ) {
	return name
		.split( '-' )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( '' );
}

/**
 * A key for an object literal, quoted only when it has to be (as Prettier
 * writes it).
 *
 * @param {string} key Property name.
 * @return {string} Source text.
 */
function prop( key ) {
	return /^[A-Za-z_$][\w$]*$/.test( key ) ? key : `'${ key }'`;
}

/**
 * The generated module: one svgr import per icon, the `byName` map, the
 * `ui.icons` names and `NavIcon`.
 *
 * @param {string[]} names Every icon, sorted.
 * @return {string} Source text.
 */
function moduleSource( names ) {
	const imports = names.map(
		( name ) =>
			`import { ReactComponent as ${ component(
				name
			) } } from '../../../assets/icons/${ name }.svg';`
	);
	const byName = names.map(
		( name ) => `\t${ prop( name ) }: ${ component( name ) },`
	);
	const ui = Object.keys( UI )
		.sort()
		.map( ( key ) => `\t${ prop( key ) }: '${ UI[ key ] }',` );

	return [
		'/**',
		` * Iconoir ${ VERSION } (MIT, iconoir.com): the Saddle family's icons.`,
		' *',
		' * Generated by `npm run icons` (scripts/icons.mjs). Do not edit: change the',
		' * manifest in the script and run it again. Each file in assets/icons/ is',
		' * also the allowlist for a module descriptor’s `icon` and `tab_icons`.',
		' */',
		...imports,
		'',
		'// Every icon, by its Iconoir name.',
		'export const byName = {',
		...byName,
		'};',
		'',
		'// `ui.icons`: each key and the Iconoir icon it is drawn with.',
		'export const uiNames = {',
		...ui,
		'};',
		'',
		'/**',
		' * An icon beside a label: the menu, a tab, the header. Decorative, so it is',
		' * hidden from assistive technology; the label beside it carries the name.',
		' *',
		' * @param {Object} props',
		' * @param {string} props.name Iconoir name.',
		' * @param {number} props.size Pixel size (16 in tabs, 20 in the header).',
		' * @return {?Element} The icon, or null for an unknown name.',
		' */',
		'export function NavIcon( { name, size = 16 } ) {',
		'\tconst Icon = Object.prototype.hasOwnProperty.call( byName, name )',
		'\t\t? byName[ name ]',
		'\t\t: null;',
		'\tif ( ! Icon ) {',
		'\t\treturn null;',
		'\t}',
		'\treturn (',
		'\t\t<Icon',
		'\t\t\tclassName="saddle-nav-icon"',
		'\t\t\twidth={ size }',
		'\t\t\theight={ size }',
		'\t\t\taria-hidden="true"',
		'\t\t\tfocusable="false"',
		'\t\t/>',
		'\t);',
		'}',
		'',
	].join( '\n' );
}

/**
 * Write a file only when its content changed, so a second run leaves the
 * tree (and every file time) as it was.
 *
 * @param {string} file    Path.
 * @param {string} content Content.
 * @return {boolean} Whether the file was written.
 */
function write( file, content ) {
	if ( existsSync( file ) && readFileSync( file, 'utf8' ) === content ) {
		return false;
	}
	writeFileSync( file, content );
	return true;
}

function run() {
	if ( ! existsSync( SOURCE ) ) {
		throw new Error(
			`Iconoir is not installed. Run: npm install -D -E iconoir@${ VERSION }`
		);
	}
	const installed = JSON.parse(
		readFileSync( join( PACKAGE, 'package.json' ), 'utf8' )
	).version;
	if ( installed !== VERSION ) {
		throw new Error(
			`Iconoir ${ installed } is installed; the manifest is for ${ VERSION }.`
		);
	}

	const names = [ ...new Set( [ ...NAV, ...Object.values( UI ) ] ) ].sort();
	const missing = names.filter(
		( name ) => ! existsSync( join( SOURCE, `${ name }.svg` ) )
	);
	if ( missing.length ) {
		throw new Error( `Not in Iconoir ${ VERSION }: ${ missing.join( ', ' ) }` );
	}

	mkdirSync( OUT_SVG, { recursive: true } );
	mkdirSync( dirname( OUT_JS ), { recursive: true } );

	let changed = 0;
	for ( const name of names ) {
		const svg = readFileSync( join( SOURCE, `${ name }.svg` ), 'utf8' );
		changed += write( join( OUT_SVG, `${ name }.svg` ), clean( svg ) )
			? 1
			: 0;
	}

	for ( const file of readdirSync( OUT_SVG ) ) {
		if ( file.endsWith( '.svg' ) && ! names.includes( file.slice( 0, -4 ) ) ) {
			unlinkSync( join( OUT_SVG, file ) );
			changed++;
		}
	}

	changed += write( OUT_JS, moduleSource( names ) ) ? 1 : 0;

	// eslint-disable-next-line no-console
	console.log(
		`${ names.length } icons from Iconoir ${ VERSION }; ${ changed } file(s) changed.`
	);
}

run();
