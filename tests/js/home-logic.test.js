/**
 * Home's pure helpers (#309): the week's numbers, each app's next step and
 * the plugins the apps can work inside.
 */
import {
	appNext,
	selfCheckDue,
	weekStart,
	weekTiles,
	worksWith,
} from '../../admin/src/home-logic';

const NOW = Date.parse( '2026-10-02T12:00:00Z' );

describe( 'this week', () => {
	it( 'starts seven days ago, in Unix seconds', () => {
		expect( weekStart( NOW ) ).toBe(
			Date.parse( '2026-09-25T12:00:00Z' ) / 1000
		);
		expect( Number.isInteger( weekStart( NOW + 999 ) ) ).toBe( true );
	} );

	it( 'shows the three numbers in order, zeros included', () => {
		const tiles = weekTiles( { changes: 12, blocked: 0, waiting: 3 } );
		expect( tiles.map( ( t ) => [ t.key, t.value ] ) ).toEqual( [
			[ 'changes', 12 ],
			[ 'blocked', 0 ],
			[ 'waiting', 3 ],
		] );
		expect( tiles.map( ( t ) => t.label ) ).toEqual( [
			'Changes',
			'Blocked',
			'Waiting for you',
		] );
	} );

	it( 'leaves out a number that failed to load', () => {
		expect(
			weekTiles( { changes: null, blocked: 4, waiting: 1 } ).map(
				( t ) => t.key
			)
		).toEqual( [ 'blocked', 'waiting' ] );
		expect( weekTiles( null ) ).toEqual( [] );
	} );
} );

describe( 'works with', () => {
	it( 'lists plugins, then add-ons, that are on and have tools', () => {
		const records = [
			{
				key: 'unsplash',
				kind: 'account',
				status: 'ready',
				tool_count: 2,
			},
			{
				key: 'analytics',
				kind: 'addon',
				status: 'active',
				tool_count: 9,
			},
			{ key: 'wc', kind: 'plugin', status: 'detected', tool_count: 3 },
			{ key: 'yoast', kind: 'plugin', status: 'off', tool_count: 4 },
			{ key: 'gf', kind: 'plugin', status: 'detected', tool_count: 0 },
		];
		expect( worksWith( records ).map( ( r ) => r.key ) ).toEqual( [
			'wc',
			'analytics',
		] );
		expect( worksWith( null ) ).toEqual( [] );
	} );

	it( 'leaves out an add-on Home already lists', () => {
		const records = [
			{
				key: 'analytics',
				kind: 'addon',
				status: 'active',
				tool_count: 9,
			},
			{ key: 'wc', kind: 'plugin', status: 'detected', tool_count: 3 },
		];
		expect(
			worksWith( records, [ 'analytics' ] ).map( ( r ) => r.key )
		).toEqual( [ 'wc' ] );
	} );
} );

describe( 'selfCheckDue (P12)', () => {
	it( 'waits for the week’s numbers', () => {
		expect( selfCheckDue( true, null ) ).toBe( false );
		expect( selfCheckDue( true, undefined ) ).toBe( false );
		expect(
			selfCheckDue( true, { changes: 1, blocked: 0, waiting: 0 } )
		).toBe( true );
	} );

	it( 'runs even when the numbers failed to load', () => {
		expect(
			selfCheckDue( true, {
				changes: null,
				blocked: null,
				waiting: null,
			} )
		).toBe( true );
	} );

	it( 'never runs with no app connected', () => {
		expect(
			selfCheckDue( false, { changes: 0, blocked: 0, waiting: 0 } )
		).toBe( false );
	} );
} );

describe( 'an app’s next step (#367)', () => {
	it( 'asks to try an app that has not used Saddle yet', () => {
		expect(
			appNext( { app: 'claude', role: 'read', first_tool_at: 0 } )
		).toBe( 'try' );
	} );

	it( 'offers more access once it has, while it can only read', () => {
		expect(
			appNext( { app: 'claude', role: 'read', first_tool_at: 9 } )
		).toBe( 'access' );
	} );

	it( 'skips the try step for an app it cannot name', () => {
		expect( appNext( { app: '', role: 'read', first_tool_at: 0 } ) ).toBe(
			'access'
		);
	} );

	it( 'has nothing to say once the app can edit', () => {
		expect(
			appNext( { app: 'claude', role: 'write', first_tool_at: 9 } )
		).toBe( '' );
		expect( appNext( null ) ).toBe( '' );
	} );
} );
