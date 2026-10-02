/**
 * Home's pure helpers (#309): the week at a glance, the prompts worth trying
 * and the plugins the apps can work inside.
 */
import {
	weekCount,
	countText,
	askIdeas,
	worksWith,
} from '../../admin/src/home-logic';

const NOW = Date.parse( '2026-10-02T12:00:00Z' );

describe( 'the week at a glance', () => {
	it( 'counts only the last seven days', () => {
		const entries = [
			{ date: '2026-10-02 09:00:00' },
			{ date: '2026-09-28 09:00:00' },
			{ date: '2026-09-20 09:00:00' },
			{ date: '' },
		];
		expect( weekCount( entries, 100, NOW ) ).toEqual( {
			count: 2,
			more: false,
		} );
	} );

	it( 'says there are more when a full page is all inside the week', () => {
		const entries = [
			{ date: '2026-10-02 09:00:00' },
			{ date: '2026-10-01 09:00:00' },
		];
		const c = weekCount( entries, 2, NOW );
		expect( c ).toEqual( { count: 2, more: true } );
		expect( countText( c ) ).toBe( '2+' );
		expect( countText( { count: 0, more: false } ) ).toBe( '0' );
	} );

	it( 'survives no entries', () => {
		expect( weekCount( null, 100, NOW ) ).toEqual( {
			count: 0,
			more: false,
		} );
	} );
} );

describe( 'prompts worth trying', () => {
	it( 'leads with the work first-look found', () => {
		const ideas = askIdeas( {
			findings: { missing_alt: 12, missing_description: 1 },
			updates: { plugins: 2, themes: 1 },
		} );
		expect( ideas.map( ( i ) => i.key ) ).toEqual( [
			'alt',
			'descriptions',
			'updates',
		] );
		expect( ideas[ 0 ].title ).toBe( 'Add alt text to 12 images' );
		expect( ideas[ 1 ].title ).toBe( 'Write 1 search description' );
		expect( ideas[ 2 ].title ).toBe( 'Review 3 updates' );
	} );

	it( 'fills with everyday prompts on a tidy site', () => {
		expect(
			askIdeas( { findings: {}, updates: {} } ).map( ( i ) => i.key )
		).toEqual( [ 'links', 'tour', 'draft' ] );
		expect( askIdeas( null, 2 ) ).toHaveLength( 2 );
	} );

	it( 'never asks an app to change things unseen', () => {
		askIdeas(
			{
				findings: { missing_alt: 1, missing_description: 1 },
				updates: { plugins: 1 },
			},
			10
		).forEach( ( idea ) => {
			expect( idea.prompt ).toMatch(
				/Don’t change anything|before you change|before you save|Don’t update|as a draft/
			);
		} );
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
