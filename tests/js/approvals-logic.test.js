import {
	askedAgo,
	sortApprovals,
	requestTitle,
	beforeAfter,
	previewRows,
} from '../../admin/src/approvals-logic';

describe( 'askedAgo', () => {
	it( 'says just now under a minute', () => {
		expect( askedAgo( 1000, 1030 ) ).toBe( 'just now' );
		expect( askedAgo( 1000, 900 ) ).toBe( 'just now' );
	} );
	it( 'counts minutes and hours', () => {
		expect( askedAgo( 1000, 1000 + 180 ) ).toBe( '3 min ago' );
		expect( askedAgo( 1000, 1000 + 3600 ) ).toBe( '1 hour ago' );
		expect( askedAgo( 1000, 1000 + 7300 ) ).toBe( '2 hours ago' );
	} );
} );

describe( 'sortApprovals', () => {
	it( 'puts the newest first without mutating', () => {
		const rows = [
			{ id: 1, created_at: 10 },
			{ id: 3, created_at: 20 },
			{ id: 2, created_at: 20 },
		];
		expect( sortApprovals( rows ).map( ( r ) => r.id ) ).toEqual( [
			3, 2, 1,
		] );
		expect( rows[ 0 ].id ).toBe( 1 );
		expect( sortApprovals( null ) ).toEqual( [] );
	} );
} );

describe( 'requestTitle', () => {
	it( 'reads as a sentence', () => {
		expect(
			requestTitle( { app: 'Claude', summary: 'Publish “Spring sale”' } )
		).toBe( 'Claude wants to publish “Spring sale”' );
	} );
	it( 'leaves acronyms alone and copes with no summary', () => {
		expect( requestTitle( { app: 'Cursor', summary: 'SEO reset' } ) ).toBe(
			'Cursor wants to SEO reset'
		);
		expect( requestTitle( { app: 'Cursor', summary: '' } ) ).toBe(
			'Cursor wants to make a change'
		);
	} );
} );

describe( 'beforeAfter', () => {
	it( 'finds a before/after pair', () => {
		expect( beforeAfter( { before: 'a', after: 'b' } ) ).toEqual( {
			before: 'a',
			after: 'b',
		} );
		expect( beforeAfter( { id: 4 } ) ).toBeNull();
		expect( beforeAfter( null ) ).toBeNull();
	} );
} );

describe( 'previewRows', () => {
	it( 'turns a flat preview into labelled rows, Yes/No for booleans', () => {
		expect(
			previewRows( {
				title: 'Spring sale',
				current_status: 'draft',
				recoverable: true,
				note: '',
				tags: [ 'a', 'b' ],
			} )
		).toEqual( [
			{ label: 'Title', value: 'Spring sale' },
			{ label: 'Current status', value: 'draft' },
			{ label: 'Recoverable', value: 'Yes' },
			{ label: 'Tags', value: 'a, b' },
		] );
	} );
	it( 'keeps only the fields a before/after preview changes', () => {
		expect(
			previewRows( {
				before: { status: 'draft', title: 'Sale' },
				after: { status: 'publish', title: 'Sale' },
			} )
		).toEqual( [ { label: 'Status', before: 'draft', value: 'publish' } ] );
	} );
	it( 'gives nothing for a preview that is not an object', () => {
		expect( previewRows( null ) ).toEqual( [] );
		expect( previewRows( 'text' ) ).toEqual( [] );
	} );
} );
