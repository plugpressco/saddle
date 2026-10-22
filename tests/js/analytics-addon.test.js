/**
 * Adding Saddle Analytics from the welcome and Home (#367): which of
 * WordPress's plugin routes it calls, and that the page stops offering it.
 */
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// The module reads `saddleData.analytics` once, when it loads, so each case
// loads its own copy with the state it needs.
function load( state ) {
	window.saddleData = { analytics: { state, canAdd: true } };
	let mod;
	jest.isolateModules( () => {
		mod = require( '../../admin/src/analytics-addon' );
	} );
	return mod;
}

describe( 'adding Saddle Analytics', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { status: 'active' } );
	} );

	it( 'installs it from WordPress.org and turns it on when missing', async () => {
		const { addAnalytics, analyticsOffer } = load( 'missing' );
		await addAnalytics();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/plugins',
			method: 'POST',
			data: { slug: 'saddle-analytics', status: 'active' },
		} );
		expect( analyticsOffer() ).toMatchObject( {
			state: 'active',
			canAdd: false,
		} );
	} );

	it( 'only turns on a copy that is installed', async () => {
		const { addAnalytics } = load( 'inactive' );
		await addAnalytics();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/plugins/saddle-analytics/saddle-analytics',
			method: 'POST',
			data: { status: 'active' },
		} );
	} );

	it( 'turns on a copy installed since the page loaded', async () => {
		const { addAnalytics } = load( 'missing' );
		apiFetch.mockRejectedValueOnce( { code: 'folder_exists' } );
		await addAnalytics();
		expect( apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				path: '/wp/v2/plugins/saddle-analytics/saddle-analytics',
			} )
		);
	} );

	it( 'keeps offering it when WordPress refuses', async () => {
		const { addAnalytics, analyticsOffer } = load( 'missing' );
		apiFetch.mockRejectedValueOnce( {
			code: 'fs_unavailable',
			message: 'Unable to connect to the filesystem.',
		} );
		await expect( addAnalytics() ).rejects.toMatchObject( {
			code: 'fs_unavailable',
		} );
		expect( analyticsOffer().canAdd ).toBe( true );
	} );
} );
