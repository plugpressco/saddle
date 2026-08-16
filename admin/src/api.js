/**
 * Small shared helpers for the Saddle admin app.
 */
import apiFetch from '@wordpress/api-fetch';

export const saddleData = window.saddleData || {};

// Build a namespaced REST path, e.g. ns( 'settings' ).
export const ns = ( path ) => `${ saddleData.ns || 'saddle/v1' }/${ path }`;

// Some host WAFs block whole pretty REST paths before WordPress ever runs —
// 20i's StackProtect answers */settings itself with a non-JSON 401 (customer
// report, 2026-08-03). WordPress errors are ALWAYS JSON, so apiFetch's
// `invalid_json` rejection means something answered before WordPress did —
// and core's own `?rest_route=` URL form sails past those path rules. A
// WAF-shaped rejection retries once through it, and a SUCCESSFUL retry makes
// the whole session prefer that form (no failing probe on every later call).
//
// Stale nonces need none of this: core's wp-api-fetch setup already refreshes
// `apiFetch.nonceMiddleware.nonce` via admin-ajax `rest-nonce` and replays the
// request on 403 rest_cookie_invalid_nonce. That is also why the fallback URL
// is built WITHOUT a nonce — the middlewares in nonce-fallback.js decorate it
// live on every (re)try, so a stale copy can never outrank a refreshed one.
let viaRestRoute = false;

// Where ?rest_route= requests go; '' when there is no usable fallback or the
// REST root already IS that form (plain permalinks).
const restRouteBase = () => {
	const root = String( saddleData.root || '' );
	if ( root.includes( 'rest_route=' ) ) {
		return '';
	}
	if ( saddleData.homeUrl ) {
		return saddleData.homeUrl;
	}
	// Cached page rendered before homeUrl existed: derive the site root.
	const m = root.match( /^(.*?)\/wp-json\// );
	return m ? m[ 1 ] + '/' : '';
};

// The ?rest_route= form of a namespaced route. A query string on the pretty
// path becomes real query parameters beside rest_route.
const restRouteUrl = ( path ) => {
	const base = restRouteBase();
	if ( ! base ) {
		return '';
	}
	const [ route, query ] = ns( path ).split( '?' );
	const url = new URL( base, window.location.href );
	url.searchParams.set( 'rest_route', '/' + route.replace( /^\/+/, '' ) );
	new URLSearchParams( query || '' ).forEach( ( value, key ) =>
		url.searchParams.set( key, value )
	);
	return url.toString();
};

// apiFetch against a namespaced Saddle route, WAF fallback included. The
// fallback request carries `url` only, never `path` — core's root-URL
// middleware rebuilds `url` from any lingering `path` and would undo it.
export const api = ( path, options = {} ) => {
	const url = restRouteUrl( path );
	if ( viaRestRoute && url ) {
		return apiFetch( { ...options, url } );
	}
	return apiFetch( { path: ns( path ), ...options } ).catch( ( error ) => {
		if ( 'invalid_json' !== error?.code || ! url ) {
			throw error;
		}
		return apiFetch( { ...options, url } ).then( ( result ) => {
			viaRestRoute = true; // The fallback worked: prefer it from now on.
			return result;
		} );
	} );
};

// Tier ordering. Higher rank = more power.
export const TIER_RANK = { read: 0, write: 1, admin: 2 };

// Whether a site at `siteTier` unlocks an ability requiring `abilityTier`.
export const tierUnlocks = ( siteTier, abilityTier ) =>
	( TIER_RANK[ siteTier ] ?? 0 ) >= ( TIER_RANK[ abilityTier ] ?? 0 );

// The three safety levels we present to people. "admin" adds site-management
// power (plugins, themes, options) and is a deliberate, separate opt-in — it is
// never bundled into "writing".
export const LEVELS = [
	{
		key: 'read',
		icon: 'read',
		title: 'Just reading',
		one: 'Your AI can read your content, but can’t change or delete anything.',
		short: 'Reads posts, pages, and media. Makes no changes.',
		recommended: true,
	},
	{
		key: 'write',
		icon: 'write',
		title: 'Reading & writing',
		one: 'Your AI can create and edit content. Deleting always asks you first.',
		short: 'Creates and edits content. Every deletion previews and asks first.',
		recommended: false,
	},
	{
		key: 'admin',
		icon: 'admin',
		title: 'Managing the site',
		one: 'Your AI can also manage plugins, themes, and settings. Overwrites and deletions always ask you first.',
		short: 'Also manages plugins, themes, and settings. Changes ask first.',
		recommended: false,
	},
];

// Map any backend tier to the human-facing level key. Unknown tiers fall back
// to the safest level.
export const levelKey = ( tier ) =>
	LEVELS.some( ( l ) => l.key === tier ) ? tier : 'read';

// Find the level descriptor for a backend tier.
export const levelFor = ( tier ) =>
	LEVELS.find( ( l ) => l.key === levelKey( tier ) ) || LEVELS[ 0 ];
