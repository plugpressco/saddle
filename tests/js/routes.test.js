/**
 * Where each Saddle page lives, and where the old in-page addresses went
 * (#274). Every old hash must land on its new page, once.
 */
import {
	LEGACY,
	areaUrl,
	legacyUrl,
	placeFor,
	readRoute,
	routeUrl,
	resolveTab,
	servicesSectionUrl,
	withArg,
} from '../../admin/src/routes';

const BASE = 'https://example.com/wp-admin/admin.php';

// The shape saddleData.areas has: what Saddle_Settings::areas_for_app() sends.
const AREAS = [
	{
		key: 'home',
		url: `${ BASE }?page=saddle`,
		tabs: [ { key: 'overview', url: `${ BASE }?page=saddle` } ],
	},
	{
		key: 'connections',
		url: `${ BASE }?page=saddle-connections`,
		tabs: [ { key: 'apps', url: `${ BASE }?page=saddle-connections` } ],
	},
	{
		key: 'services',
		url: `${ BASE }?page=saddle-services`,
		tabs: [ { key: 'overview', url: `${ BASE }?page=saddle-services` } ],
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
		// Activity is Home's feed since #309.
		[ '#activity', `${ BASE }?page=saddle#activity` ],
		[ '#connect', `${ BASE }?page=saddle-connections` ],
		// Permissions was a tab of Connections until #285: both land on AI apps.
		[ '#permissions', `${ BASE }?page=saddle-connections` ],
		// Services is a page of its own (#291).
		[ '#integrations', `${ BASE }?page=saddle-services` ],
		[ '#services', `${ BASE }?page=saddle-services` ],
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

	it( 'does not crash when the Services page is absent', () => {
		const without = AREAS.filter( ( a ) => a.key !== 'services' );
		expect( legacyUrl( without, '#services' ) ).toBeNull();
	} );

	it( 'leaves anything else alone', () => {
		expect( legacyUrl( AREAS, '' ) ).toBeNull();
		expect( legacyUrl( AREAS, '#section-3' ) ).toBeNull();
		expect( legacyUrl( AREAS, '#constructor' ) ).toBeNull();
		expect( legacyUrl( AREAS, '#toString' ) ).toBeNull();
	} );
} );

describe( 'settings section=services', () => {
	it( 'lands on Services from Settings only', () => {
		expect(
			servicesSectionUrl(
				AREAS[ 4 ],
				AREAS,
				'?page=saddle-settings&section=services'
			)
		).toBe( `${ BASE }?page=saddle-services` );
		expect(
			servicesSectionUrl( AREAS[ 4 ], AREAS, '?page=saddle-settings' )
		).toBeNull();
		expect(
			servicesSectionUrl( AREAS[ 0 ], AREAS, '?section=services' )
		).toBeNull();
		expect(
			servicesSectionUrl( AREAS[ 4 ], [], '?section=services' )
		).toBeNull();
	} );
} );

describe( 'tabs and places', () => {
	it( 'falls back to the first tab for an unknown one', () => {
		expect( resolveTab( AREAS[ 1 ], 'permissions' ) ).toBe( 'apps' );
		expect( resolveTab( AREAS[ 1 ], 'nope' ) ).toBe( 'apps' );
		expect( resolveTab( AREAS[ 1 ], '' ) ).toBe( 'apps' );
		expect( resolveTab( undefined, 'x' ) ).toBe( '' );
	} );

	it( 'builds a tab URL, and the page URL for an unknown tab', () => {
		expect( areaUrl( AREAS, 'home', 'activity' ) ).toBe(
			`${ BASE }?page=saddle`
		);
		expect( areaUrl( AREAS, 'connections', 'permissions' ) ).toBe(
			`${ BASE }?page=saddle-connections`
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
			tab: 'overview',
		} );
		expect( placeFor( 'permissions' ) ).toEqual( {
			area: 'connections',
			tab: 'apps',
		} );
		expect( placeFor( 'integrations' ) ).toEqual( {
			area: 'services',
			tab: 'overview',
		} );
		expect( placeFor( 'services' ) ).toEqual( {
			area: 'services',
			tab: 'overview',
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

describe( 'views', () => {
	it( 'reads tab, view and the module’s own arguments from the address', () => {
		expect(
			readRoute(
				'?page=saddle-crm&tab=campaigns&view=review&campaign=12'
			)
		).toEqual( {
			tab: 'campaigns',
			view: 'review',
			args: { campaign: '12' },
		} );
		expect( readRoute( '?page=saddle' ) ).toEqual( {
			tab: '',
			view: '',
			args: {},
		} );
		expect( readRoute( '?view=%22%3E%3Cscript%3E' ).view ).toBe( 'script' );
	} );

	it( 'builds the address of a view with its arguments', () => {
		expect(
			routeUrl( AREAS, 'home', 'overview', 'report', { campaign: 12 } )
		).toBe( `${ BASE }?page=saddle&view=report&campaign=12` );
		expect( routeUrl( AREAS, 'home', 'overview' ) ).toBe(
			`${ BASE }?page=saddle`
		);
		expect( routeUrl( AREAS, 'nowhere', 'x' ) ).toBe( '' );
	} );

	it( 'carries a view and args through placeFor only when named', () => {
		expect( placeFor( { tab: 'contacts' } ) ).toEqual( {
			area: '',
			tab: 'contacts',
		} );
		expect(
			placeFor( { view: 'setup', args: { campaign: '3' } } )
		).toEqual( {
			area: '',
			tab: '',
			view: 'setup',
			args: { campaign: '3' },
		} );
		expect( placeFor( {} ) ).toBeNull();
	} );
} );
