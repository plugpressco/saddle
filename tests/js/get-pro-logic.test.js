/**
 * Get Premium's plans (#373; by sites since 2026-11-09): the prices and
 * sites saddle.to shows, where each button goes, and when the Saddle Cloud
 * offers stop. Keep equal to saddle-cloud's src/shared/plans.ts.
 */
import {
	cloudConnectUrl,
	connectedToCloud,
	planCards,
	price,
} from '../../admin/src/get-pro-logic';

describe( 'plan cards', () => {
	it( 'shows saddle.to’s yearly prices and sites, Free first', () => {
		expect(
			planCards().map( ( c ) => [ c.key, c.name, c.yearly, c.sites ] )
		).toEqual( [
			[ 'free', 'Free', 0, '1 site' ],
			[ 'go', 'Go', 49, '1 site' ],
			[ 'pro', 'Pro', 99, 'Up to 3 sites' ],
			[ 'reach', 'Ultimate', 199, 'Up to 10 sites' ],
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
			[ 'https://saddle.to/go/reach-50/', 'Choose Ultimate' ],
		] );
	} );

	it( 'gives every plan the same two numbers: calls, and credits on paid plans', () => {
		expect(
			planCards().map( ( plan ) => plan.facts.map( ( f ) => f.text ) )
		).toEqual( [
			[ '500 AI agent calls a month', 'No SEO credits' ],
			[ '100 AI agent calls an hour', '500 SEO credits a month' ],
			[ '200 AI agent calls an hour', '1,000 SEO credits a month' ],
			[ '500 AI agent calls an hour', '2,500 SEO credits a month' ],
		] );
		expect( planCards().map( ( plan ) => plan.facts[ 1 ].none ) ).toEqual( [
			true,
			false,
			false,
			false,
		] );
		expect( planCards().map( ( plan ) => plan.pro ) ).toEqual( [
			false,
			false,
			true,
			false,
		] );
		expect( planCards()[ 2 ].icon ).toBe( 'crown' );
	} );

	it( 'never offers unlimited sites or a support level saddle.to does not sell', () => {
		const words = JSON.stringify( planCards() );
		expect( words ).not.toMatch( /Unlimited sites|Priority support|Reach/ );
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
