/**
 * Pure helpers for Home (#309): the week's numbers and its days, each app's
 * next step and the plugins the apps can work inside. No React, no network.
 */
import { __ } from '@wordpress/i18n';

/**
 * The start of this week, for GET /audit-log's `since`: midnight six days
 * ago, so the week is today and the six days before it, the days the chart
 * draws (#369). Local time, as the Activity feed groups its days.
 *
 * @param {number} now Milliseconds, for tests.
 * @return {number} Unix seconds.
 */
export function weekStart( now = Date.now() ) {
	const d = new Date( now );
	d.setHours( 0, 0, 0, 0 );
	d.setDate( d.getDate() - 6 );
	return Math.floor( d.getTime() / 1000 );
}

/**
 * The week's seven days, oldest first, each with how many of `times` fell on
 * it, for Home's chart (#369). A time before the week is left out; one after
 * now (a server clock ahead of the browser's) counts for today.
 *
 * @param {number[]} times When each change happened, Unix seconds.
 * @param {number}   now   Milliseconds, for tests.
 * @return {Array<{date:Date,count:number,today:boolean}>} The days.
 */
export function weekDays( times, now = Date.now() ) {
	const first = new Date( weekStart( now ) * 1000 );
	const days = Array.from( { length: 7 }, ( _, i ) => {
		const date = new Date( first );
		date.setDate( first.getDate() + i );
		return { date, count: 0, today: 6 === i };
	} );
	( Array.isArray( times ) ? times : [] ).forEach( ( time ) => {
		const at = time * 1000;
		for ( let i = 6; i >= 0; i-- ) {
			if ( at >= days[ i ].date.getTime() ) {
				days[ i ].count++;
				return;
			}
		}
	} );
	return days;
}

/**
 * The numbers in "This week", in order. A number that failed to load is left
 * out rather than drawn as a dash.
 *
 * @param {?Object} week `{ changes, blocked, waiting }`, each a count or null.
 * @return {Array<{key:string,label:string,value:number}>} Tiles.
 */
export function weekTiles( week ) {
	if ( ! week ) {
		return [];
	}
	return [
		{
			key: 'changes',
			label: __( 'Changes', 'saddle' ),
			value: week.changes,
		},
		{
			key: 'blocked',
			label: __( 'Blocked', 'saddle' ),
			value: week.blocked,
		},
		{
			key: 'waiting',
			label: __( 'Waiting for you', 'saddle' ),
			value: week.waiting,
		},
	].filter( ( t ) => Number.isInteger( t.value ) && t.value >= 0 );
}

/**
 * The one next step for a connected app, shown on its row in Home's AI apps
 * (#367; a line of its own before): try it while it has not used Saddle
 * yet, then give it more than Read only.
 *
 * @param {Object} c A row from GET /connections.
 * @return {string} `try`, `access` or '' for nothing to do.
 */
export function appNext( c ) {
	if ( ! c ) {
		return '';
	}
	// The welcome's try step needs to know which app it is.
	if ( ! c.first_tool_at && c.app ) {
		return 'try';
	}
	return 'read' === ( c.role || 'read' ) ? 'access' : '';
}

/**
 * The plugins on this site the apps can work inside, for "Works with": the
 * Services page's plugin and add-on records that are on, with tools. An
 * add-on Home already lists under Add-ons is left out (#367), so no name
 * shows twice.
 *
 * @param {Array}    records From GET /services.
 * @param {string[]} listed  Keys Home already shows elsewhere.
 * @return {Array<Object>} The records, plugins first.
 */
export function worksWith( records, listed = [] ) {
	const list = Array.isArray( records ) ? records : [];
	const on = ( r ) =>
		r &&
		( 'plugin' === r.kind || 'addon' === r.kind ) &&
		! listed.includes( r.key ) &&
		( 'active' === r.status ||
			'detected' === r.status ||
			'ready' === r.status ) &&
		( r.tool_count || 0 ) > 0;
	return [
		...list.filter( ( r ) => on( r ) && 'plugin' === r.kind ),
		...list.filter( ( r ) => on( r ) && 'addon' === r.kind ),
	];
}

/**
 * Whether Home runs the connection self-check now (P12). The check calls the
 * site back, so on a server that answers one request at a time it would hold
 * everything sent after it: it waits until the week's numbers are in, which
 * Home asks for together with Needs your OK and the feed. Its answer matters
 * only once an app is connected.
 *
 * @param {boolean} connected Whether an app is connected.
 * @param {?Object} week      The week's numbers, or null while they load.
 * @return {boolean} True to run it.
 */
export function selfCheckDue( connected, week ) {
	return !! connected && null !== week && undefined !== week;
}

// The pastel tones Home's tiles and chips come in (#369), Cloud's set.
const TONES = [ 'pink', 'lilac', 'sky', 'mint', 'butter', 'peach' ];

// An app's tile, picked to sit well under its logo's own colours.
const APP_TONES = {
	claude: 'peach',
	'claude-code': 'peach',
	'claude-desktop': 'peach',
	chatgpt: 'mint',
	codex: 'mint',
	cursor: 'sky',
	'gemini-cli': 'sky',
	antigravity: 'sky',
	vscode: 'lilac',
	windsurf: 'mint',
	grok: 'lilac',
	openclaw: 'peach',
};

// A first-party add-on's tile; any other add-on gets one by its key.
const ADDON_TONES = {
	analytics: 'pink',
	rank: 'sky',
	mailpuff: 'butter',
};

/**
 * A tone for anything without its own, the same every time for one key.
 *
 * @param {string} key Any key.
 * @return {string} One of TONES.
 */
function toneFor( key ) {
	let sum = 0;
	for ( const ch of String( key || '' ) ) {
		sum = ( sum * 31 + ch.charCodeAt( 0 ) ) % 9973;
	}
	return TONES[ sum % TONES.length ];
}

/**
 * The tile behind an app's logo on Home (#369).
 *
 * @param {string} app The app key (connect-apps.js), or ''.
 * @return {string} A tone.
 */
export function appTone( app ) {
	return APP_TONES[ app ] || ( app ? toneFor( app ) : 'pink' );
}

/**
 * The tile behind an add-on's icon on Home (#369).
 *
 * @param {string} key The module key.
 * @return {string} A tone.
 */
export function addonTone( key ) {
	return ADDON_TONES[ key ] || toneFor( key );
}
