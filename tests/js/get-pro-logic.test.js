/**
 * Get Pro's plans (#373): the prices saddle.to shows, a whole price a month
 * at every size, each checkout link, and Reach's credits first.
 */
import {
	FIRST_SIZE,
	SIZES,
	planCards,
	price,
} from '../../admin/src/get-pro-logic';

describe( 'plan cards', () => {
	it( 'shows saddle.to’s prices, billed yearly, for each size', () => {
		const byKey = ( size ) =>
			Object.fromEntries(
				planCards( size ).map( ( c ) => [
					c.key,
					[ c.monthly, c.yearly ],
				] )
			);
		expect( byKey( 5 ) ).toEqual( {
			go: [ 5, 60 ],
			pro: [ 9, 108 ],
			reach: [ 19, 228 ],
		} );
		expect( byKey( 20 ) ).toEqual( {
			go: [ 12, 144 ],
			pro: [ 19, 228 ],
			reach: [ 39, 468 ],
		} );
		expect( byKey( 50 ) ).toEqual( {
			go: [ 25, 300 ],
			pro: [ 39, 468 ],
			reach: [ 79, 948 ],
		} );
	} );

	it( 'opens each plan’s checkout on saddle.to for the size picked', () => {
		expect( planCards( 50 ).map( ( c ) => c.href ) ).toEqual( [
			'https://saddle.to/go/go-50/',
			'https://saddle.to/go/pro-50/',
			'https://saddle.to/go/reach-50/',
		] );
	} );

	it( 'gives every plan the same three numbers, credits only on Reach', () => {
		const [ go, pro, reach ] = planCards( 20 );
		expect( reach.facts.map( ( f ) => f.text ) ).toEqual( [
			'20 sites',
			'5,000 AI-app calls a day',
			'4,500 SEO credits a month',
		] );
		expect( go.facts.map( ( f ) => f.text ) ).toEqual( [
			'20 sites',
			'1,000 AI-app calls a day',
			'No SEO credits',
		] );
		expect( go.facts[ 2 ].none ).toBe( true );
		expect( reach.facts[ 2 ].none ).toBe( false );
		// Four things each, so the cards line up.
		expect(
			[ go, pro, reach ].map( ( plan ) => plan.items.length )
		).toEqual( [ 4, 4, 4 ] );
		expect( [ go.pro, pro.pro, reach.pro ] ).toEqual( [
			false,
			true,
			false,
		] );
		expect( pro.icon ).toBe( 'crown' );
	} );

	it( 'falls back to the first size for one it does not sell', () => {
		expect( SIZES ).toContain( FIRST_SIZE );
		expect( planCards( 7 ) ).toEqual( planCards( FIRST_SIZE ) );
	} );
} );

describe( 'price', () => {
	it( 'says a whole price without cents, and cents when there are some', () => {
		expect( price( 19 ) ).toBe( '$19' );
		expect( price( 8.5 ) ).toBe( '$8.50' );
	} );
} );
