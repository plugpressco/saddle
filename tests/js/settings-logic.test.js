/**
 * Settings → Advanced → "Turn off single tools" lists only the tools that
 * can run on this site (1.5.0 release QA P17, D3).
 */
import { runnableTools, safetyPrefs } from '../../admin/src/settings-logic';

describe( 'runnableTools', () => {
	it( 'leaves out tools for plugins that are not active', () => {
		const caps = [
			{ short: 'create-post', available: true },
			{ short: 'yoast-get-post-seo', available: false },
			{ short: 'wc-list-products', available: false },
			{ short: 'update-option', available: true },
		];
		expect( runnableTools( caps ).map( ( t ) => t.short ) ).toEqual( [
			'create-post',
			'update-option',
		] );
	} );

	it( 'lists a tool without the flag, as an older server sends it', () => {
		expect(
			runnableTools( [ { short: 'create-post' } ] ).map(
				( t ) => t.short
			)
		).toEqual( [ 'create-post' ] );
	} );

	it( 'keeps a switched-off tool that can run', () => {
		expect(
			runnableTools( [
				{ short: 'delete-post', available: true, enabled: false },
			] )
		).toHaveLength( 1 );
	} );

	it( 'copes with nothing', () => {
		expect( runnableTools( undefined ) ).toEqual( [] );
		expect( runnableTools( [ null ] ) ).toEqual( [] );
	} );
} );

describe( 'safetyPrefs', () => {
	it( 'reads the three switches from /preferences', () => {
		expect(
			safetyPrefs( {
				drafts_only: true,
				rehearsal: false,
				domain: { enforced: true },
			} )
		).toEqual( {
			drafts_only: true,
			rehearsal: false,
			domain_enforced: true,
		} );
	} );

	it( 'reads off for anything missing', () => {
		expect( safetyPrefs( {} ) ).toEqual( {
			drafts_only: false,
			rehearsal: false,
			domain_enforced: false,
		} );
		expect( safetyPrefs( null ).domain_enforced ).toBe( false );
	} );
} );
