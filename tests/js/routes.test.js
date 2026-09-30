/**
 * Where each Saddle page lives, and where the old in-page addresses went
 * (#274). Every old hash must land on its new page, once.
 */
import {
	LEGACY,
	areaUrl,
	legacyUrl,
	placeFor,
	resolveTab,
	withArg,
} from '../../admin/src/routes';

const BASE = 'https://example.com/wp-admin/admin.php';

// The shape saddleData.areas has: what Saddle_Settings::areas_for_app() sends.
const AREAS = [
	{
		key: 'home',
		url: `${ BASE }?page=saddle`,
		tabs: [
			{ key: 'overview', url: `${ BASE }?page=saddle` },
			{ key: 'activity', url: `${ BASE }?page=saddle&tab=activity` },
		],
	},
	{
		key: 'connections',
		url: `${ BASE }?page=saddle-connections`,
		tabs: [
			{ key: 'apps', url: `${ BASE }?page=saddle-connections` },
			{
				key: 'permissions',
				url: `${ BASE }?page=saddle-connections&tab=permissions`,
			},
		],
	},
	{
		key: 'context',
		url: `${ BASE }?page=saddle-context`,
		tabs: [ { key: 'overview', url: `${ BASE }?page=saddle-context` } ],
	},
	{
		key: 'settings',
		url: `${ BASE }?page=saddle-settings`,
		tabs: [ { key: 'general', url: `${ BASE }?page=saddle-settings` } ],
	},
];

describe( 'legacy hash addresses', () => {
	it.each( [
		[ '#dashboard', `${ BASE }?page=saddle` ],
		[ '#home', `${ BASE }?page=saddle` ],
		[ '#activity', `${ BASE }?page=saddle&tab=activity` ],
		[ '#connect', `${ BASE }?page=saddle-connections` ],
		[ '#permissions', `${ BASE }?page=saddle-connections&tab=permissions` ],
		[
			'#integrations',
			`${ BASE }?page=saddle-connections&tab=permissions`,
		],
		[ '#guidance', `${ BASE }?page=saddle-context` ],
		[ '#memory', `${ BASE }?page=saddle-context#memory` ],
		// Saddle Pro's licence link is page=saddle#settings.
		[ '#settings', `${ BASE }?page=saddle-settings` ],
	] )( '%s lands on its new page', ( hash, url ) => {
		expect( legacyUrl( AREAS, hash ) ).toBe( url );
	} );

	it( 'covers every old section name', () => {
		Object.keys( LEGACY ).forEach( ( name ) => {
			expect( legacyUrl( AREAS, `#${ name }` ) ).toBeTruthy();
		} );
	} );

	it( 'leaves anything else alone', () => {
		expect( legacyUrl( AREAS, '' ) ).toBeNull();
		expect( legacyUrl( AREAS, '#section-3' ) ).toBeNull();
		expect( legacyUrl( AREAS, '#constructor' ) ).toBeNull();
		expect( legacyUrl( AREAS, '#toString' ) ).toBeNull();
	} );
} );

describe( 'tabs and places', () => {
	it( 'falls back to the first tab for an unknown one', () => {
		expect( resolveTab( AREAS[ 1 ], 'permissions' ) ).toBe( 'permissions' );
		expect( resolveTab( AREAS[ 1 ], 'nope' ) ).toBe( 'apps' );
		expect( resolveTab( AREAS[ 1 ], '' ) ).toBe( 'apps' );
		expect( resolveTab( undefined, 'x' ) ).toBe( '' );
	} );

	it( 'builds a tab URL, and the page URL for an unknown tab', () => {
		expect( areaUrl( AREAS, 'connections', 'permissions' ) ).toBe(
			`${ BASE }?page=saddle-connections&tab=permissions`
		);
		expect( areaUrl( AREAS, 'connections', 'nope' ) ).toBe(
			`${ BASE }?page=saddle-connections`
		);
		expect( areaUrl( AREAS, 'nope' ) ).toBe( '' );
	} );

	it( 'maps the section names page components still use', () => {
		expect( placeFor( 'connect' ) ).toEqual( {
			area: 'connections',
			tab: 'apps',
		} );
		expect( placeFor( 'activity' ) ).toEqual( {
			area: 'home',
			tab: 'activity',
		} );
		expect( placeFor( 'memory' ) ).toEqual( {
			area: 'context',
			tab: 'overview',
		} );
		expect( placeFor( { area: 'settings', tab: 'general' } ) ).toEqual( {
			area: 'settings',
			tab: 'general',
		} );
		expect( placeFor( 'nowhere' ) ).toBeNull();
	} );

	it( 'sets and removes one query argument', () => {
		const url = `${ BASE }?page=saddle-connections`;
		const added = withArg( url, 'add', '1' );
		expect( added ).toBe( `${ BASE }?page=saddle-connections&add=1` );
		expect( withArg( added, 'add', null ) ).toBe( url );
	} );
} );
