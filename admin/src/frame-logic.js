/**
 * The frame's header and layout as plain logic, so they can be tested
 * without React (planning/MODULE-LAYOUT.md, M4; top tabs from 2026-10-05).
 *
 * Every Saddle page has a breadcrumb header: `[mark] Saddle / Page`, and Home
 * reads `[mark] Saddle`. A page with two or more tabs (a module's sections,
 * `&tab=`) draws them as the header's tab row. In a module section with two
 * or more pages (`&sub=`), a row of page links sits at the top of the content.
 *
 * A module screen opens one item with `&view=`. While it is open, the screen
 * may call `props.header.drillIn( { title } )`: the breadcrumb then reads
 * `Saddle / Module / Section / title`, and the tab row and the page links step
 * aside. The drill-in belongs to the tab, page and view it was set on, so it
 * ends on its own when the view closes or the page or tab changes; Core also
 * clears it when the screen unmounts.
 */

/**
 * What the frame draws.
 *
 * @param {Object}  props
 * @param {Object}  props.area  The page: `{ key, title, url, module, tabs: [ { key, label, url, subtabs } ] }`.
 * @param {string}  props.tab   The active tab.
 * @param {string}  props.sub   The active page inside the tab, or ''.
 * @param {string}  props.view  The view inside the page, or ''.
 * @param {?Object} props.drill What the screen asked for: `{ title, tab, sub, view }`
 *                              (as `drillHeader()` records it), or null.
 * @param {Object}  props.home  The first crumb: `{ label, url }` (Saddle, to Home).
 * @param {string=} props.crumb The page's name when it is not the area's title
 *                              (first run's "Welcome").
 * @return {{crumbs: Array, title: string, drilled: boolean, showTabs: boolean, showSubtabs: boolean}}
 *         The header. Each crumb is `{ key, label, url }`; `key` is `home`,
 *         `module`, `section` or `current`, and the last one has no url.
 */
export function frameHeader( {
	area,
	tab,
	sub = '',
	view = '',
	drill = null,
	home = { label: '', url: '' },
	crumb = '',
} ) {
	const tabs = area && Array.isArray( area.tabs ) ? area.tabs : [];
	const title = ( area && area.title ) || '';
	const module = !! ( area && area.module ) && ! crumb;
	const current = tabs.find( ( t ) => t.key === tab );
	const pages =
		current && Array.isArray( current.subtabs ) ? current.subtabs : [];

	const drilled =
		! crumb &&
		!! view &&
		!! drill &&
		typeof drill.title === 'string' &&
		'' !== drill.title &&
		( undefined === drill.tab || drill.tab === tab ) &&
		( undefined === drill.sub || drill.sub === sub ) &&
		( undefined === drill.view || drill.view === view );

	const homeCrumb = {
		key: 'home',
		label: home.label,
		url: home.url || '',
	};
	let crumbs;
	if ( crumb ) {
		crumbs = [ homeCrumb, { key: 'current', label: crumb, url: '' } ];
	} else if ( area && 'home' === area.key ) {
		crumbs = [ { ...homeCrumb, url: '' } ];
	} else if ( drilled ) {
		const page = sub && pages.find( ( p ) => p.key === sub );
		crumbs = [
			homeCrumb,
			{ key: 'module', label: title, url: ( area && area.url ) || '' },
			{
				key: 'section',
				label: current && current.label ? current.label : title,
				url:
					( page && page.url ) ||
					( current && current.url ) ||
					( area && area.url ) ||
					'',
			},
			{ key: 'current', label: drill.title, url: '' },
		];
	} else {
		crumbs = [ homeCrumb, { key: 'current', label: title, url: '' } ];
	}

	return {
		crumbs,
		title: crumbs[ crumbs.length - 1 ].label,
		drilled,
		showTabs: ! drilled && tabs.length > 1,
		showSubtabs: module && ! drilled && pages.length > 1,
	};
}

/**
 * The tab row's tabs: the page's tabs in their order, Settings moved last.
 *
 * @param {Array} tabs The page's tabs: `[ { key, label, url, icon } ]`.
 * @return {Array} The tabs, Settings last.
 */
export function sectionTabs( tabs ) {
	const list = Array.isArray( tabs ) ? tabs.filter( Boolean ) : [];
	return [
		...list.filter( ( t ) => 'settings' !== t.key ),
		...list.filter( ( t ) => 'settings' === t.key ),
	];
}

/**
 * The screen a module registered for a tab and page: the most specific
 * match first (module, tab and page), then the module's screen for the
 * whole tab (registered without a page).
 *
 * @param {Array}  screens Entries from `saddle.admin.screens`.
 * @param {string} module  Module key.
 * @param {string} tab     Tab key.
 * @param {string} sub     Page key, or ''.
 * @return {?Object} The entry, or null.
 */
export function findScreen( screens, module, tab, sub ) {
	const mine = ( Array.isArray( screens ) ? screens : [] ).filter(
		( s ) => s && s.module === module && s.tab === tab
	);
	const exact = sub ? mine.find( ( s ) => s.sub === sub ) : null;
	return exact || mine.find( ( s ) => ! s.sub ) || null;
}

/**
 * Whether a tab row draws its icons. A row is all icons or none: a module
 * that names icons for some tabs only (or an older one that names none) gets
 * plain labels, so the row never mixes the two.
 *
 * @param {Array} tabs The page's tabs: `[ { key, label, icon } ]`.
 * @return {boolean} True when every tab has an icon.
 */
export function tabsHaveIcons( tabs ) {
	return (
		Array.isArray( tabs ) &&
		tabs.length > 0 &&
		tabs.every(
			( t ) => !! t && 'string' === typeof t.icon && '' !== t.icon
		)
	);
}

/**
 * The `header` a screen gets, bound to the tab, page and view it was drawn
 * for.
 *
 * `drillIn( { title } )` records the title for this tab, page and view;
 * `drillIn()` with no title, and `clear()`, remove it. Neither touches a
 * drill-in another tab, page or view recorded, so a screen that unmounts
 * after its successor mounted cannot erase the successor's title.
 *
 * @param {Function} set  A state setter that takes an updater, like React's.
 * @param {string}   tab  The tab the screen is drawn for.
 * @param {string}   view The view the screen is drawn for, or ''.
 * @param {string}   sub  The page inside the tab, or ''.
 * @return {{drillIn: Function, clear: Function}} The header.
 */
export function drillHeader( set, tab, view, sub = '' ) {
	const mine = ( drill ) =>
		!! drill &&
		drill.tab === tab &&
		drill.view === view &&
		( drill.sub || '' ) === sub;
	const clear = () => set( ( prev ) => ( mine( prev ) ? null : prev ) );

	const drillIn = ( options ) => {
		const raw = options && options.title;
		const title =
			typeof raw === 'string' || typeof raw === 'number'
				? String( raw ).trim()
				: '';
		if ( ! title ) {
			clear();
			return;
		}
		set( ( prev ) =>
			mine( prev ) && prev.title === title
				? prev
				: { title, tab, sub, view }
		);
	};

	return { drillIn, clear };
}

/**
 * Whether a click on a link should stay in the app: the main button, no
 * modifier key (those open a new tab or window), and nothing else has
 * handled it.
 *
 * @param {Object} event The click event.
 * @return {boolean} True to navigate in the app.
 */
export function isPlainClick( event ) {
	return (
		!! event &&
		! event.defaultPrevented &&
		( undefined === event.button || 0 === event.button ) &&
		! event.metaKey &&
		! event.ctrlKey &&
		! event.shiftKey &&
		! event.altKey
	);
}

/**
 * The query string to show when the address named a tab or a page this
 * screen doesn't have (P20). The app already fell back to the first one;
 * this drops the bad value too, so a reload, a copied link or Back shows
 * the address of what is on screen. The first tab and a tab's first page
 * are left out of an address, so a bad value is removed, never rewritten.
 * Every other argument stays as it was.
 *
 * @param {string} search   `window.location.search`.
 * @param {Object} resolved `{ tab, sub }` the app drew ('' sub for a tab with no pages).
 * @return {?string} The new query string with its `?`, or null when the address is fine.
 */
export function canonicalSearch( search, resolved ) {
	const params = new URLSearchParams( search || '' );
	const tab = ( resolved && resolved.tab ) || '';
	const sub = ( resolved && resolved.sub ) || '';
	let changed = false;
	if ( params.has( 'tab' ) && params.get( 'tab' ) !== tab ) {
		params.delete( 'tab' );
		changed = true;
	}
	if ( params.has( 'sub' ) && params.get( 'sub' ) !== sub ) {
		// The app reads a page the way sanitize_key does (routes.js), so
		// `&sub=Pages` is the page `pages`: write it as such.
		const asked = String( params.get( 'sub' ) )
			.replace( /[^a-z0-9_-]/gi, '' )
			.toLowerCase();
		if ( sub && asked === sub ) {
			params.set( 'sub', sub );
		} else {
			params.delete( 'sub' );
		}
		changed = true;
	}
	if ( ! changed ) {
		return null;
	}
	const rest = params.toString();
	return rest ? `?${ rest }` : '';
}
