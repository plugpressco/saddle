/**
 * Get Pro's plans (#373; flat pricing 2026-10-27): the prices saddle.to
 * shows, each checkout link, and Reach's credits.
 */
import { planCards, price } from '../../admin/src/get-pro-logic';

describe( 'plan cards', () => {
	it( 'shows saddle.to’s yearly prices and the sites each includes', () => {
		expect(
			planCards().map( ( c ) => [ c.key, c.yearly, c.sites ] )
		).toEqual( [
			[ 'go', 49, 5 ],
			[ 'pro', 99, 20 ],
			[ 'reach', 249, 50 ],
		] );
	} );

	it( 'opens each plan’s checkout on saddle.to', () => {
		expect( planCards().map( ( c ) => c.href ) ).toEqual( [
			'https://saddle.to/go/go-5/',
			'https://saddle.to/go/pro-20/',
			'https://saddle.to/go/reach-50/',
		] );
	} );

	it( 'gives every plan the same two numbers, credits on Pro and Reach', () => {
		const [ go, pro, reach ] = planCards();
		expect( pro.facts.map( ( f ) => f.text ) ).toEqual( [
			'1,000 AI-app calls a day',
			'200 SEO credits a month',
		] );
		expect( reach.facts.map( ( f ) => f.text ) ).toEqual( [
			'4,000 AI-app calls a day',
			'1,000 SEO credits a month',
		] );
		expect( go.facts.map( ( f ) => f.text ) ).toEqual( [
			'150 AI-app calls a day',
			'No SEO credits',
		] );
		expect( go.facts[ 1 ].none ).toBe( true );
		expect( reach.facts[ 1 ].none ).toBe( false );
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
} );

describe( 'price', () => {
	it( 'says a whole price without cents, and cents when there are some', () => {
		expect( price( 49 ) ).toBe( '$49' );
		expect( price( 8.5 ) ).toBe( '$8.50' );
	} );
} );
