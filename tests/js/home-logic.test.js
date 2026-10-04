/**
 * Home's pure helpers (#309): the week's numbers and the plugins the apps
 * can work inside.
 */
import {
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
