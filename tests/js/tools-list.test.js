/**
 * Settings → Advanced → "Turn off single tools": search, grouping and flipping
 * a tool.
 */
import {
	disabledSet,
	flipTool,
	groupTools,
	toolMatches,
} from '../../admin/src/tools-list';

const TOOLS = [
	{
		short: 'delete-page',
		label: 'Delete a page',
		category: 'Pages',
		description: 'Moves a page to the trash.',
		enabled: true,
	},
	{
		short: 'create-page',
		label: 'Create a page',
		category: 'Pages',
		description: 'Makes a new page.',
		enabled: true,
	},
	{
		short: 'list-media',
		label: 'List media',
		category: 'Media',
		description: 'Reads the library.',
		enabled: false,
	},
	{
		short: 'mystery',
		label: 'Mystery',
		description: 'No area.',
		enabled: true,
	},
	{
		short: 'a-tool',
		label: 'Another',
		category: 'Other',
		description: '',
		enabled: true,
	},
];

describe( 'tools list', () => {
	it( 'matches on label, id and description, ignoring case', () => {
		expect( toolMatches( TOOLS[ 0 ], '' ) ).toBe( true );
		expect( toolMatches( TOOLS[ 0 ], 'DELETE' ) ).toBe( true );
		expect( toolMatches( TOOLS[ 0 ], 'trash' ) ).toBe( true );
		expect( toolMatches( TOOLS[ 0 ], 'media' ) ).toBe( false );
	} );

	it( 'groups by area, tools by label, and puts the fallback last', () => {
		const groups = groupTools( TOOLS, '' );
		expect( groups.map( ( g ) => g.category ) ).toEqual( [
			'Media',
			'Pages',
			'Other',
		] );
		expect( groups[ 1 ].tools.map( ( t ) => t.short ) ).toEqual( [
			'create-page',
			'delete-page',
		] );
		// "Mystery" has no area and lands with the fallback.
		expect( groups[ 2 ].tools ).toHaveLength( 2 );
	} );

	it( 'drops groups with no match', () => {
		const groups = groupTools( TOOLS, 'library' );
		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].category ).toBe( 'Media' );
		expect( groupTools( TOOLS, 'zzz' ) ).toEqual( [] );
		expect( groupTools( null, '' ) ).toEqual( [] );
	} );

	it( 'reads the switched-off tools and flips one without mutating', () => {
		const off = disabledSet( TOOLS );
		expect( [ ...off ] ).toEqual( [ 'list-media' ] );

		const next = flipTool( off, 'delete-page' );
		expect( next.has( 'delete-page' ) ).toBe( true );
		expect( off.has( 'delete-page' ) ).toBe( false );
		expect( flipTool( next, 'delete-page' ).has( 'delete-page' ) ).toBe(
			false
		);
	} );
} );
