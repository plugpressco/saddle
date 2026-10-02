import {
	groupServices,
	keyLink,
	replaceRecord,
	roleLabel,
	sections,
	statusLabel,
	summaryOf,
	toolCount,
} from '../../admin/src/services-logic';

const unsplash = {
	key: 'unsplash',
	name: 'Unsplash',
	description: 'Stock photos',
	kind: 'account',
	source: 'built-in',
	status: 'needs_key',
	credentials_url: 'https://unsplash.com/developers',
	sends: [ { host: 'api.unsplash.com', what: 'x', when: 'y' } ],
	tools: [ { name: 'a' }, { name: 'b' } ],
};
const yoast = {
	key: 'yoast',
	name: 'Yoast SEO',
	description: 'SEO plugin',
	kind: 'plugin',
	source: 'built-in',
	status: 'detected',
	sends: [],
	tools: [ { name: 'a' } ],
};
const analytics = {
	key: 'analytics',
	name: 'Saddle Analytics',
	description: '',
	kind: 'addon',
	source: 'plugpress',
	status: 'active',
	tools: [],
};

describe( 'sections', () => {
	it( 'has a title and no note for each kind, in page order', () => {
		expect( sections() ).toEqual( [
			{ kind: 'account', title: 'Accounts' },
			{ kind: 'plugin', title: 'Plugins' },
			{ kind: 'addon', title: 'Add-ons' },
		] );
	} );
} );

describe( 'groupServices', () => {
	it( 'groups by kind in page order and drops empty kinds', () => {
		const groups = groupServices( [ analytics, yoast, unsplash ] );
		expect( groups.map( ( g ) => g.kind ) ).toEqual( [
			'account',
			'plugin',
			'addon',
		] );
		expect( groups.map( ( g ) => g.title ) ).toEqual( [
			'Accounts',
			'Plugins',
			'Add-ons',
		] );
		expect( groups[ 1 ].rows ).toEqual( [ yoast ] );
	} );
	it( 'keeps the headings when two kinds have records', () => {
		const groups = groupServices( [ yoast, unsplash ] );
		expect( groups.map( ( g ) => g.title ) ).toEqual( [
			'Accounts',
			'Plugins',
		] );
	} );
	it( 'drops the heading when only one kind has records', () => {
		const groups = groupServices( [
			unsplash,
			{ ...unsplash, key: 'pexels', name: 'Pexels' },
		] );
		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].kind ).toBe( 'account' );
		expect( groups[ 0 ].title ).toBe( '' );
		expect( groups[ 0 ].rows ).toHaveLength( 2 );
	} );
	it( 'carries no section note', () => {
		const groups = groupServices( [ yoast, unsplash ] );
		groups.forEach( ( g ) => expect( g ).not.toHaveProperty( 'note' ) );
	} );
	it( 'copes with nothing, and ignores unknown kinds', () => {
		expect( groupServices( null ) ).toEqual( [] );
		expect( groupServices( [] ) ).toEqual( [] );
		expect( groupServices( [ { key: 'x', kind: 'weird' } ] ) ).toEqual(
			[]
		);
		// An unknown kind does not count towards "two kinds".
		const groups = groupServices( [ yoast, { key: 'x', kind: 'weird' } ] );
		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].title ).toBe( '' );
	} );
} );

describe( 'statusLabel', () => {
	it( 'says one word or two for each status', () => {
		expect( statusLabel( unsplash ) ).toBe( 'Not set up' );
		expect( statusLabel( { status: 'ready' } ) ).toBe( 'Ready' );
		expect( statusLabel( { status: 'active' } ) ).toBe( 'Active' );
		expect( statusLabel( { status: 'off' } ) ).toBe( 'Off' );
	} );
	it( 'reads a plugin found on the site as Active', () => {
		expect( statusLabel( yoast ) ).toBe( 'Active' );
	} );
	it( 'says Active whoever made the add-on', () => {
		expect(
			statusLabel( { status: 'active', source: 'third-party' } )
		).toBe( 'Active' );
		expect( statusLabel( { status: 'active', source: 'plugpress' } ) ).toBe(
			'Active'
		);
	} );
	it( 'says nothing for an unknown status or no record', () => {
		expect( statusLabel( { status: 'weird' } ) ).toBe( '' );
		expect( statusLabel( {} ) ).toBe( '' );
		expect( statusLabel( null ) ).toBe( '' );
	} );
	it( 'never mentions tools or where data goes', () => {
		[ 'needs_key', 'ready', 'detected', 'active', 'off' ].forEach(
			( status ) => {
				const label = statusLabel( { ...unsplash, status } );
				expect( label ).not.toMatch( /tool|·|send|leaves/i );
			}
		);
	} );
} );

describe( 'summaryOf', () => {
	it( 'is the service’s own description, whole', () => {
		expect( summaryOf( unsplash ) ).toBe( 'Stock photos' );
		const long =
			'This site’s own traffic analytics — visitors, sources, pages, AI referrals and AI crawlers. Read-only.';
		expect( summaryOf( { ...analytics, description: long } ) ).toBe( long );
	} );
	it( 'names the kind when there is no description', () => {
		expect( summaryOf( { kind: 'account', description: '' } ) ).toBe(
			'Outside service'
		);
		expect( summaryOf( { kind: 'plugin', description: '  ' } ) ).toBe(
			'Plugin'
		);
		expect( summaryOf( analytics ) ).toBe( 'PlugPress add-on' );
		expect( summaryOf( { kind: 'addon', source: 'third-party' } ) ).toBe(
			'Third-party add-on'
		);
	} );
	it( 'copes with no record', () => {
		expect( summaryOf( null ) ).toBe( '' );
	} );
} );

describe( 'keyLink', () => {
	it( 'links an account to where its key comes from', () => {
		expect( keyLink( unsplash ) ).toEqual( {
			url: 'https://unsplash.com/developers',
			label: 'Get a key from Unsplash',
		} );
	} );
	it( 'is null without a URL, for anything but an account, or no record', () => {
		expect( keyLink( { ...unsplash, credentials_url: '' } ) ).toBeNull();
		expect(
			keyLink( { ...yoast, credentials_url: 'https://example.com' } )
		).toBeNull();
		expect( keyLink( null ) ).toBeNull();
	} );
} );

describe( 'toolCount', () => {
	it( 'counts the tools the drawer lists', () => {
		expect( toolCount( unsplash ) ).toBe( '2 tools' );
		expect( toolCount( yoast ) ).toBe( '1 tool' );
	} );
	it( 'ignores the server count, which an add-on that is off keeps', () => {
		expect( toolCount( { tools: [], tool_count: 3 } ) ).toBe( '0 tools' );
		expect( toolCount( {} ) ).toBe( '0 tools' );
	} );
} );

describe( 'roleLabel, replaceRecord', () => {
	it( 'names the roles as AI apps does', () => {
		expect( roleLabel( 'read' ) ).toBe( 'Read only' );
		expect( roleLabel( 'write' ) ).toBe( 'Edit content' );
		expect( roleLabel( 'admin' ) ).toBe( 'Manage the site' );
		expect( roleLabel( 'other' ) ).toBe( 'other' );
		expect( roleLabel( undefined ) ).toBe( '' );
	} );
	it( 'replaces one record by key', () => {
		const next = { ...unsplash, status: 'ready' };
		expect( replaceRecord( [ yoast, unsplash ], next ) ).toEqual( [
			yoast,
			next,
		] );
		expect( replaceRecord( null, next ) ).toEqual( [] );
	} );
} );
