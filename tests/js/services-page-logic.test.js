/**
 * Services (1.5.0 release QA P23): every section is named, and an outside
 * account's row says what it is for.
 */
import { namedGroups, rowLine } from '../../admin/src/services-page-logic';

const unsplash = {
	key: 'unsplash',
	name: 'Unsplash',
	description: 'Stock photos',
	kind: 'account',
	status: 'needs_key',
};

describe( 'namedGroups', () => {
	it( 'names the section when Accounts is the only one', () => {
		expect( namedGroups( [ unsplash ] ) ).toEqual( [
			{ kind: 'account', title: 'Accounts', rows: [ unsplash ] },
		] );
	} );

	it( 'names every section when there are several', () => {
		const woo = {
			key: 'wc',
			name: 'WooCommerce',
			kind: 'plugin',
			status: 'detected',
		};
		expect(
			namedGroups( [ unsplash, woo ] ).map( ( g ) => g.title )
		).toEqual( [ 'Accounts', 'Plugins' ] );
	} );

	it( 'is empty with nothing to show', () => {
		expect( namedGroups( [] ) ).toEqual( [] );
		expect( namedGroups( null ) ).toEqual( [] );
	} );
} );

describe( 'rowLine', () => {
	it( 'says what an account is for, then its status', () => {
		expect( rowLine( unsplash ) ).toBe( 'Stock photos · Not set up' );
		expect( rowLine( { ...unsplash, status: 'ready' } ) ).toBe(
			'Stock photos · Ready'
		);
	} );

	it( 'keeps a plugin or add-on to its status', () => {
		expect(
			rowLine( {
				kind: 'plugin',
				description: 'Products and orders.',
				status: 'detected',
			} )
		).toBe( 'Active' );
		expect(
			rowLine( { kind: 'addon', description: 'x', status: 'off' } )
		).toBe( 'Off' );
	} );

	it( 'falls back to whichever part there is', () => {
		expect( rowLine( { ...unsplash, description: '' } ) ).toBe(
			'Not set up'
		);
		expect( rowLine( { ...unsplash, status: 'unknown' } ) ).toBe(
			'Stock photos'
		);
	} );
} );
