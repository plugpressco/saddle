/**
 * Where each Saddle page lives, and where the old in-page addresses went.
 *
 * Saddle's pages are real wp-admin pages under the Saddle menu
 * (`admin.php?page=saddle`, `saddle-connections`, `saddle-settings`, and one
 * per module), with `&tab=` for a tab. The server hands over the list as
 * `saddleData.areas`, so the menu and the page can never disagree. These
 * helpers are pure: they take that list and return URLs.
 */

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
 * The URL of a page, or of one of its tabs.
 *
 * @param {Array}  areas Pages from saddleData.areas.
 * @param {string} key   Area key.
 * @param {string} tab   Tab key (optional).
 * @return {string} URL, or '' for an unknown page.
 */
export function areaUrl( areas, key, tab ) {
	const area = findArea( areas, key );
	if ( ! area ) {
		return '';
	}
	const match = tab && area.tabs.find( ( t ) => t.key === tab );
	return match ? match.url : area.url;
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
 * section name (`'connect'`, `'activity'`) or an explicit `{ area, tab }`.
 *
 * @param {string|Object} target Section name or `{ area, tab }`.
 * @return {{area:string, tab:string}|null} Place, or null when unknown.
 */
export function placeFor( target ) {
	if ( target && typeof target === 'object' && target.area ) {
		return { area: target.area, tab: target.tab || '' };
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
