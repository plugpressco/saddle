/**
 * Where each Saddle page lives, and where the old in-page addresses went.
 *
 * Saddle's pages are real wp-admin pages under the Saddle menu
 * (`admin.php?page=saddle`, `saddle-connections`, `saddle-settings`, and one
 * per module), with `&tab=` for a tab (a module's section), `&sub=` for a
 * page inside a section and `&view=` for one item. The server hands over the
 * list as `saddleData.areas`, so the menu and the page can never disagree.
 * These helpers are pure: they take that list and return URLs.
 */

// Core's query arguments. Every other one is a module's own.
const RESERVED = [ 'page', 'tab', 'sub', 'view' ];

// What sanitize_key keeps.
const cleanKey = ( value ) =>
	String( value || '' )
		.replace( /[^a-z0-9_-]/gi, '' )
		.toLowerCase();

/**
 * The hash routes the admin used before it moved to submenu pages (#274),
 * and where each one lives now. Old bookmarks, docs and other plugins' links
 * (Saddle Pro's licence link is `page=saddle#settings`) land here once.
 */
export const LEGACY = {
	dashboard: { area: 'home', tab: 'overview' },
	home: { area: 'home', tab: 'overview' },
	// Activity was a tab of the Dashboard until #309; it is Home's feed now.
	activity: { area: 'home', tab: 'overview', anchor: 'activity' },
	connect: { area: 'connections', tab: 'apps' },
	// Permissions was a tab of Connections until #285; access is now chosen
	// per app on AI apps.
	permissions: { area: 'connections', tab: 'apps' },
	// Services moved out of Settings onto a page of its own (#291).
	integrations: { area: 'services', tab: 'overview' },
	services: { area: 'services', tab: 'overview' },
	guidance: { area: 'context', tab: 'overview' },
	memory: { area: 'context', tab: 'overview', anchor: 'memory' },
	settings: { area: 'settings', tab: 'general' },
};

/**
 * @param {Array}  areas Pages from saddleData.areas.
 * @param {string} key   Area key.
 * @return {Object|undefined} The page.
 */
export function findArea( areas, key ) {
	return ( areas || [] ).find( ( a ) => a.key === key );
}

/**
 * A tab of a page, or the page's first tab when the tab is unknown.
 *
 * @param {Object} area Page from saddleData.areas.
 * @param {string} tab  Requested tab.
 * @return {string} Tab key.
 */
export function resolveTab( area, tab ) {
	if ( ! area || ! area.tabs || ! area.tabs.length ) {
		return '';
	}
	return area.tabs.some( ( t ) => t.key === tab ) ? tab : area.tabs[ 0 ].key;
}

/**
 * The pages inside a tab: `[ { key, label, url, icon } ]`, or none.
 *
 * @param {Object} area Page from saddleData.areas.
 * @param {string} tab  Tab key.
 * @return {Array} The tab's pages.
 */
export function subtabsOf( area, tab ) {
	const match =
		area && Array.isArray( area.tabs )
			? area.tabs.find( ( t ) => t.key === tab )
			: null;
	return match && Array.isArray( match.subtabs ) ? match.subtabs : [];
}

/**
 * A page inside a tab, or the tab's first page when the page is empty or
 * unknown. '' when the tab has no pages.
 *
 * @param {Object} area Page from saddleData.areas.
 * @param {string} tab  Tab key, already resolved.
 * @param {string} sub  Requested page.
 * @return {string} Page key.
 */
export function resolveSub( area, tab, sub ) {
	const pages = subtabsOf( area, tab );
	if ( ! pages.length ) {
		return '';
	}
	return pages.some( ( p ) => p.key === sub ) ? sub : pages[ 0 ].key;
}

/**
 * The URL of a page, of one of its tabs, or of a page inside that tab.
 *
 * @param {Array}  areas   Pages from saddleData.areas.
 * @param {string} areaKey Area key.
 * @param {string} tab     Tab key (optional).
 * @param {string} sub     Page key inside the tab (optional).
 * @return {string} URL, or '' for an unknown page.
 */
export function areaUrl( areas, areaKey, tab, sub ) {
	const area = findArea( areas, areaKey );
	if ( ! area ) {
		return '';
	}
	const match = tab && area.tabs.find( ( t ) => t.key === tab );
	if ( ! match ) {
		return area.url;
	}
	const page = sub && subtabsOf( area, tab ).find( ( p ) => p.key === sub );
	return page && page.url ? page.url : match.url;
}

/**
 * Where an old `#hash` address lives now, or null when it isn't one.
 *
 * @param {Array}  areas Pages from saddleData.areas.
 * @param {string} hash  `window.location.hash`, with or without the `#`.
 * @return {string|null} The new URL.
 */
export function legacyUrl( areas, hash ) {
	const name = String( hash || '' ).replace( /^#/, '' );
	const target = Object.prototype.hasOwnProperty.call( LEGACY, name )
		? LEGACY[ name ]
		: null;
	if ( ! target ) {
		return null;
	}
	const url = areaUrl( areas, target.area, target.tab );
	if ( ! url ) {
		return null;
	}
	return target.anchor ? `${ url }#${ target.anchor }` : url;
}

/**
 * Where `page=saddle-settings&section=services` lives now: the Services page
 * (#291). Null on any other page, or when Services is not registered.
 *
 * @param {Object} area   The current page.
 * @param {Array}  areas  Pages from saddleData.areas.
 * @param {string} search `window.location.search`.
 * @return {string|null} The Services URL.
 */
export function servicesSectionUrl( area, areas, search ) {
	if ( ! area || 'settings' !== area.key ) {
		return null;
	}
	if ( 'services' !== new URLSearchParams( search || '' ).get( 'section' ) ) {
		return null;
	}
	return areaUrl( areas, 'services' ) || null;
}

/**
 * Resolve a navigation request from a page component to a place: an old
 * section name (`'connect'`, `'activity'`) or an explicit
 * `{ area, tab, sub, view, args }`.
 *
 * @param {string|Object} target Section name or `{ area, tab, sub, view, args }`.
 * @return {?Object} Place `{ area, tab }`, with `sub`, `view` and `args`
 *                   when the caller named any of them; null when unknown.
 */
export function placeFor( target ) {
	if ( target && typeof target === 'object' ) {
		const named = [ 'sub', 'view', 'args' ].some( ( k ) => k in target );
		// `area` may be left out: the request is for the page we are on.
		if ( ! target.area && ! target.tab && ! named ) {
			return null;
		}
		const place = { area: target.area || '', tab: target.tab || '' };
		// `sub` (a page inside the tab) rides along only when named, so a
		// plain tab switch lands on the tab's first page.
		if ( 'sub' in target ) {
			place.sub = typeof target.sub === 'string' ? target.sub : '';
		}
		// `view` and `args` (a module's deep screens) ride along only when the
		// caller named them, or a page, so a plain tab switch clears both.
		if ( named ) {
			place.view = typeof target.view === 'string' ? target.view : '';
			place.args =
				target.args && typeof target.args === 'object'
					? target.args
					: {};
		}
		return place;
	}
	const legacy = Object.prototype.hasOwnProperty.call( LEGACY, target )
		? LEGACY[ target ]
		: null;
	return legacy ? { area: legacy.area, tab: legacy.tab } : null;
}

/**
 * The same URL with one query argument set, or removed when `value` is null.
 *
 * @param {string}      url   URL.
 * @param {string}      name  Argument.
 * @param {string|null} value Value, or null to remove it.
 * @return {string} URL.
 */
export function withArg( url, name, value ) {
	const next = new URL( url, 'http://placeholder.invalid' );
	if ( value === null ) {
		next.searchParams.delete( name );
	} else {
		next.searchParams.set( name, value );
	}
	return next.origin === 'http://placeholder.invalid'
		? next.pathname + next.search + next.hash
		: next.toString();
}

/**
 * The tab, page, view and extra query arguments an address names.
 *
 * `page`, `tab`, `sub` and `view` are Core's; every other argument is the
 * module's own (`&campaign=12`) and comes back untouched as strings.
 *
 * @param {string} search `window.location.search`.
 * @return {{tab:string, sub:string, view:string, args:Object}} The route.
 */
export function readRoute( search ) {
	const params = new URLSearchParams( search || '' );
	const args = {};
	params.forEach( ( value, name ) => {
		if ( ! RESERVED.includes( name ) ) {
			args[ name ] = value;
		}
	} );
	return {
		tab: String( params.get( 'tab' ) || '' ),
		sub: cleanKey( params.get( 'sub' ) ),
		view: cleanKey( params.get( 'view' ) ),
		args,
	};
}

/**
 * The URL of a tab, a page inside it, a view and the module's own
 * arguments.
 *
 * @param {Array}  areas   Pages from saddleData.areas.
 * @param {string} areaKey Area key.
 * @param {Object} route   `{ tab, sub, view, args }`, each optional.
 * @return {string} URL, or '' for an unknown page.
 */
export function routeUrl( areas, areaKey, route = {} ) {
	const { tab = '', sub = '', view = '', args = {} } = route || {};
	let url = areaUrl( areas, areaKey, tab, sub );
	if ( ! url ) {
		return '';
	}
	if ( view ) {
		url = withArg( url, 'view', view );
	}
	Object.keys( args || {} ).forEach( ( name ) => {
		if (
			! RESERVED.includes( name ) &&
			null !== args[ name ] &&
			undefined !== args[ name ]
		) {
			url = withArg( url, name, String( args[ name ] ) );
		}
	} );
	return url;
}
