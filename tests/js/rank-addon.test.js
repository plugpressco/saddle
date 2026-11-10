/**
 * Adding Saddle Rank from the welcome and Home (#409): the same owner-side
 * install as Saddle Analytics, for the one slug `saddle-rank`.
 */
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

function load( state ) {
	window.saddleData = { rank: { state, canAdd: true } };
	let mod;
	jest.isolateModules( () => {
		mod = require( '../../admin/src/rank-addon' );
	} );
	return mod;
}

describe( 'adding Saddle Rank', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { status: 'active' } );
	} );

	it( 'installs it from WordPress.org and turns it on when missing', async () => {
		const { addRank, rankOffer } = load( 'missing' );
		await addRank();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/plugins',
			method: 'POST',
			data: { slug: 'saddle-rank', status: 'active' },
		} );
		expect( rankOffer() ).toMatchObject( {
			state: 'active',
			canAdd: false,
		} );
	} );

	it( 'only turns on a copy that is installed', async () => {
		const { addRank } = load( 'inactive' );
		await addRank();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/plugins/saddle-rank/saddle-rank',
			method: 'POST',
			data: { status: 'active' },
		} );
	} );

	it( 'does not offer it when the page said it is on', () => {
		window.saddleData = {};
		let mod;
		jest.isolateModules( () => {
			mod = require( '../../admin/src/rank-addon' );
		} );
		expect( mod.rankOffer().canAdd ).toBe( false );
	} );
} );
