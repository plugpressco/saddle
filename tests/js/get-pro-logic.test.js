/**
 * Get Premium's plans (#373; Free to $299, 2026-11-02): the prices saddle.to
 * shows, where each button goes, and when the Saddle Cloud offers stop.
 */
import {
	cloudConnectUrl,
	connectedToCloud,
	planCards,
	price,
} from '../../admin/src/get-pro-logic';

describe( 'plan cards', () => {
	it( 'shows saddle.to’s yearly prices, Free first', () => {
		expect( planCards().map( ( c ) => [ c.key, c.yearly ] ) ).toEqual( [
			[ 'free', 0 ],
			[ 'go', 49 ],
			[ 'pro', 99 ],
			[ 'reach', 299 ],
		] );
	} );

	it( 'sends Free to Saddle Cloud with this site, the rest to their checkout', () => {
		expect(
			planCards( 'https://example.com/' ).map( ( c ) => [
				c.href,
				c.buy,
			] )
		).toEqual( [
			[
				'https://cloud.saddle.to/connect?site=https%3A%2F%2Fexample.com',
				'Start free',
			],
			[ 'https://saddle.to/go/go-5/', 'Choose Go' ],
			[ 'https://saddle.to/go/pro-20/', 'Choose Pro' ],
			[ 'https://saddle.to/go/reach-50/', 'Choose Reach' ],
		] );
	} );

	it( 'gives every plan the same two numbers, credits on Pro and Reach', () => {
		const [ free, go, pro, reach ] = planCards();
		expect( free.facts.map( ( f ) => f.text ) ).toEqual( [
			'500 AI agent calls a month',
			'No SEO credits',
		] );
		expect( go.facts[ 0 ].text ).toBe( '10,000 AI agent calls a month' );
		expect( pro.facts.map( ( f ) => f.text ) ).toEqual( [
			'50,000 AI agent calls a month',
			'200 SEO credits a month',
		] );
		expect( reach.facts.map( ( f ) => f.text ) ).toEqual( [
			'250,000 AI agent calls a month',
			'2,000 SEO credits a month',
		] );
		expect( go.facts[ 1 ].none ).toBe( true );
		expect( reach.facts[ 1 ].none ).toBe( false );
		// Four things each, so the cards line up.
		expect(
			[ free, go, pro, reach ].map( ( plan ) => plan.items.length )
		).toEqual( [ 4, 4, 4, 4 ] );
		expect( planCards().map( ( plan ) => plan.pro ) ).toEqual( [
			false,
			false,
			true,
			false,
		] );
		expect( pro.icon ).toBe( 'crown' );
	} );
} );

describe( 'Saddle Cloud', () => {
	it( 'links to Connect a site with the address, or without one', () => {
		expect( cloudConnectUrl( 'https://shop.example.com/blog/' ) ).toBe(
			'https://cloud.saddle.to/connect?site=https%3A%2F%2Fshop.example.com%2Fblog'
		);
		expect( cloudConnectUrl( '' ) ).toBe(
			'https://cloud.saddle.to/connect'
		);
	} );

	it( 'stops offering itself once a connection is Saddle Cloud', () => {
		expect( connectedToCloud( [ { app: 'claude' } ] ) ).toBe( false );
		expect(
			connectedToCloud( [ { app: 'claude' }, { app: 'saddle-cloud' } ] )
		).toBe( true );
		expect( connectedToCloud( undefined ) ).toBe( false );
	} );
} );

describe( 'price', () => {
	it( 'says a whole price without cents, and cents when there are some', () => {
		expect( price( 49 ) ).toBe( '$49' );
		expect( price( 8.5 ) ).toBe( '$8.50' );
	} );
} );
