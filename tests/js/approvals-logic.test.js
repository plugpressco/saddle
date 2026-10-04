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
			{ label: 'Status now', value: 'Draft' },
			{ label: 'Can be restored', value: 'Yes' },
			{ label: 'Tags', value: 'a, b' },
		] );
	} );
	it( 'keeps only the fields a before/after preview changes', () => {
		expect(
			previewRows( {
				before: { status: 'draft', title: 'Sale' },
				after: { status: 'publish', title: 'Sale' },
			} )
		).toEqual( [
			{ label: 'Status', before: 'Draft', value: 'Published' },
		] );
	} );
	// QA 1.5.0 P4: the Review drawer read "Id 6", "Type post", "Current
	// status publish", "Will delete permanently No".
	it( 'reads a delete preview in plain words, without its id', () => {
		expect(
			previewRows( {
				id: 6,
				type: 'post',
				title: 'Our new rye starter',
				current_status: 'publish',
				will_delete_permanently: false,
				recoverable: true,
			} )
		).toEqual( [
			{ label: 'Type', value: 'Post' },
			{ label: 'Title', value: 'Our new rye starter' },
			{ label: 'Status now', value: 'Published' },
			{ label: 'Can be restored', value: 'Yes' },
		] );
	} );
	it( 'keeps "permanently" when nothing else says it', () => {
		expect( previewRows( { will_delete_permanently: true } ) ).toEqual( [
			{ label: 'Will delete permanently', value: 'Yes' },
		] );
	} );
	it( 'names the fields a publish changes, not their contents', () => {
		expect(
			previewRows( {
				id: 9,
				type: 'page',
				current_status: 'draft',
				new_status: 'future',
				changes: { status: 'future', post_content: '<p>Long</p>' },
			} )
		).toEqual( [
			{ label: 'Type', value: 'Page' },
			{ label: 'Status now', value: 'Draft' },
			{ label: 'New status', value: 'Scheduled' },
			{ label: 'What changes', value: 'status, post content' },
		] );
	} );
	it( 'keeps a status or type it does not know as stored', () => {
		expect(
			previewRows( {
				type: 'core/paragraph',
				current_status: 'wc-on-hold',
			} )
		).toEqual( [
			{ label: 'Type', value: 'core/paragraph' },
			{ label: 'Status now', value: 'wc-on-hold' },
		] );
	} );
	it( 'gives nothing for a preview that is not an object', () => {
		expect( previewRows( null ) ).toEqual( [] );
		expect( previewRows( 'text' ) ).toEqual( [] );
	} );
} );

describe( 'previewRows for plugins and themes (R4)', () => {
	it( 'names the plugin, not its file', () => {
		expect(
			previewRows( {
				plugin: 'hello.php',
				plugin_name: 'Hello Dolly',
				version: '1.7.2',
			} )
		).toEqual( [
			{ label: 'Plugin', value: 'Hello Dolly' },
			{ label: 'Version', value: '1.7.2' },
		] );
	} );

	it( 'keeps the file when there is no name to show instead', () => {
		expect( previewRows( { plugin: 'hello.php', version: '' } ) ).toEqual( [
			{ label: 'Plugin', value: 'hello.php' },
		] );
	} );

	it( 'names the theme, not its folder', () => {
		expect(
			previewRows( {
				stylesheet: 'twentytwentyfive',
				theme_name: 'Twenty Twenty-Five',
				version: '1.2',
			} )
		).toEqual( [
			{ label: 'Theme', value: 'Twenty Twenty-Five' },
			{ label: 'Version', value: '1.2' },
		] );
	} );
} );
