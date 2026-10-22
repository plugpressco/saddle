import {
	keyLink,
	replaceRecord,
	roleLabel,
	statusLabel,
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
