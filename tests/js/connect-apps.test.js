/**
 * The one-click install links (#179): the exact formats Cursor and VS Code
 * accept, built from the same values as the copy-paste setup, and never
 * from a placeholder key.
 */

const MCP_URL = 'https://example.com/wp-json/saddle/v1/mcp';

/**
 * Load connect-apps with a given saddleData: the module reads it at import.
 *
 * @param {Object} data window.saddleData for this load.
 * @return {Object} The module.
 */
function load( data ) {
	window.saddleData = data;
	let mod;
	jest.isolateModules( () => {
		mod = require( '../../admin/src/connect-apps' );
	} );
	return mod;
}

const site = {
	mcpUrl: MCP_URL,
	user: 'admin',
	serverSlug: 'saddle-example',
};

function queryParam( href, name ) {
	return new URL( href ).searchParams.get( name );
}

describe( 'installLinks', () => {
	it( 'builds the Cursor link with the base64 server object on the address path', () => {
		const { installLinks } = load( site );
		const links = installLinks( 'cursor', null, 'address' );

		expect( links ).toHaveLength( 1 );
		expect( links[ 0 ].href ).toMatch(
			/^cursor:\/\/anysphere\.cursor-deeplink\/mcp\/install\?name=saddle-example&config=/
		);
		const config = JSON.parse(
			atob( queryParam( links[ 0 ].href, 'config' ) )
		);
		expect( config ).toEqual( { url: MCP_URL } );
	} );

	it( 'builds VS Code and Insiders links with name, type and url', () => {
		const { installLinks } = load( site );
		const links = installLinks( 'vscode', null, 'address' );

		expect( links.map( ( l ) => l.key ) ).toEqual( [
			'vscode',
			'vscode-insiders',
		] );
		expect( links[ 0 ].href.startsWith( 'vscode:mcp/install?' ) ).toBe(
			true
		);
		expect(
			links[ 1 ].href.startsWith( 'vscode-insiders:mcp/install?' )
		).toBe( true );

		const server = JSON.parse(
			decodeURIComponent( links[ 0 ].href.split( '?' )[ 1 ] )
		);
		expect( server ).toEqual( {
			name: 'saddle-example',
			type: 'http',
			url: MCP_URL,
		} );
	} );

	it( 'carries the Basic credential on the key path, whitespace stripped', () => {
		const { installLinks } = load( site );
		const expected = `Basic ${ btoa( 'admin:abcdEFGHijklMNOP' ) }`;

		const cursor = installLinks( 'cursor', 'abcd EFGH ijkl MNOP', 'key' );
		const config = JSON.parse(
			atob( queryParam( cursor[ 0 ].href, 'config' ) )
		);
		expect( config.headers.Authorization ).toBe( expected );

		const vscode = installLinks( 'vscode', 'abcd EFGH ijkl MNOP', 'key' );
		const server = JSON.parse(
			decodeURIComponent( vscode[ 0 ].href.split( '?' )[ 1 ] )
		);
		expect( server.headers.Authorization ).toBe( expected );
	} );

	it( 'offers no link on the key path without a real key', () => {
		const { installLinks } = load( site );

		expect( installLinks( 'cursor', '', 'key' ) ).toEqual( [] );
		expect( installLinks( 'vscode', null, 'key' ) ).toEqual( [] );
	} );

	it( 'offers no link for apps without an install URL', () => {
		const { installLinks } = load( site );

		for ( const app of [
			'claude-code',
			'chatgpt',
			'codex',
			'gemini-cli',
			'windsurf',
			'openclaw',
			'grok',
			'other',
		] ) {
			expect( installLinks( app, null, 'address' ) ).toEqual( [] );
		}
	} );

	it( 'builds the Add to Claude link on the address path only', () => {
		const { installLinks } = load( site );
		const links = installLinks( 'claude', null, 'address' );

		expect( links ).toHaveLength( 1 );
		const url = new URL( links[ 0 ].href );
		expect( url.origin + url.pathname ).toBe(
			'https://claude.ai/customize/connectors'
		);
		expect( url.searchParams.get( 'modal' ) ).toBe(
			'add-custom-connector'
		);
		expect( url.searchParams.get( 'connectorName' ) ).toBe(
			'saddle-example'
		);
		expect( url.searchParams.get( 'connectorUrl' ) ).toBe( MCP_URL );

		// A key can't ride in claude.ai's link, so the key path has none.
		expect( installLinks( 'claude', 'abcd EFGH', 'key' ) ).toEqual( [] );
	} );

	it( 'offers no link when the site has no MCP address', () => {
		const { installLinks } = load( { ...site, mcpUrl: '' } );

		expect( installLinks( 'cursor', null, 'address' ) ).toEqual( [] );
	} );
} );

describe( 'buildConfig', () => {
	it( 'gives OpenClaw an OAuth add and a login on the address path', () => {
		const { buildConfig } = load( site );
		const lines = buildConfig( 'openclaw', null, 'address' ).split( '\n' );

		expect( lines ).toEqual( [
			`openclaw mcp add saddle-example --url ${ MCP_URL } --transport streamable-http --auth oauth`,
			'openclaw mcp login saddle-example',
		] );
	} );

	it( 'gives OpenClaw a Basic header, and no OAuth, on the key path', () => {
		const { buildConfig } = load( site );
		const setup = buildConfig( 'openclaw', 'abcd EFGH', 'key' );

		expect( setup ).toContain( '--transport streamable-http' );
		expect( setup ).toContain(
			`--header "Authorization=Basic ${ btoa( 'admin:abcdEFGH' ) }"`
		);
		expect( setup ).not.toContain( '--auth oauth' );
	} );

	it( 'gives Grok the connector form lines', () => {
		const { buildConfig } = load( site );
		const setup = buildConfig( 'grok', null, 'address' );

		expect( setup ).toContain( MCP_URL );
		expect( setup ).toContain( 'OAuth' );
	} );
} );

describe( 'connectPath: which path the connect wizard takes', () => {
	const off = { enabled: false, ready: true, permalinks: true };
	const on = { enabled: true, ready: true, permalinks: true };
	const noHttps = { enabled: false, ready: false, permalinks: true };
	const plain = { enabled: false, ready: false, permalinks: false };
	const meta = ( APPS, key ) => APPS.find( ( a ) => a.key === key );
	const both = ( APPS ) => APPS.filter( ( a ) => a.viaKey && a.viaAddress );

	it( 'gives an address-only app the address whatever the switch says', () => {
		const { APPS, connectPath } = load( site );
		[ null, off, on, noHttps, plain ].forEach( ( signIn ) => {
			[ 'chatgpt', 'grok' ].forEach( ( key ) =>
				expect(
					connectPath( meta( APPS, key ), {
						signIn,
						offer: true,
						prefer: false,
					} )
				).toBe( 'address' )
			);
		} );
	} );

	it( 'leads with the address once sign-in is on, and honours "Use a key instead"', () => {
		const { APPS, connectPath } = load( site );
		both( APPS ).forEach( ( app ) => {
			expect( connectPath( app, { signIn: on } ) ).toBe( 'address' );
			expect( connectPath( app, { signIn: on, prefer: false } ) ).toBe(
				'key'
			);
		} );
	} );

	it( 'keeps AI apps’ key wizard on the key while sign-in is off', () => {
		const { APPS, connectPath } = load( site );
		both( APPS ).forEach( ( app ) =>
			expect( connectPath( app, { signIn: off } ) ).toBe( 'key' )
		);
	} );

	it( 'leads the welcome with the switch while sign-in can be turned on (S1)', () => {
		// The symptom: picking Claude in the welcome with sign-in off made a
		// key and showed the old desktop setup (mcp-remote, needs Node).
		const { APPS, connectPath, buildConfig } = load( site );
		both( APPS ).forEach( ( app ) => {
			expect( connectPath( app, { signIn: off, offer: true } ) ).toBe(
				'address'
			);
			expect(
				connectPath( app, { signIn: off, offer: true, prefer: false } )
			).toBe( 'key' );
		} );
		const claude = connectPath( meta( APPS, 'claude' ), {
			signIn: off,
			offer: true,
		} );
		expect( buildConfig( 'claude', null, claude ) ).not.toContain(
			'mcp-remote'
		);
	} );

	it( 'keeps the key as the only path where sign-in can’t work', () => {
		const { APPS, connectPath } = load( site );
		[ noHttps, plain, null ].forEach( ( signIn ) =>
			both( APPS ).forEach( ( app ) => {
				expect( connectPath( app, { signIn, offer: true } ) ).toBe(
					'key'
				);
				expect(
					connectPath( app, { signIn, offer: true, prefer: true } )
				).toBe( 'key' );
			} )
		);
	} );

	it( 'keeps Claude on a key for a local site, which claude.ai can’t reach', () => {
		const { APPS, connectPath } = load( site );
		const args = { signIn: off, offer: true, local: true };

		expect( connectPath( meta( APPS, 'claude' ), args ) ).toBe( 'key' );
		expect( connectPath( meta( APPS, 'claude-code' ), args ) ).toBe(
			'address'
		);
		expect( connectPath( meta( APPS, 'chatgpt' ), args ) ).toBe(
			'address'
		);
		// Turned on by the owner, the address stays the lead as before.
		expect(
			connectPath( meta( APPS, 'claude' ), { ...args, signIn: on } )
		).toBe( 'address' );
	} );

	it( 'follows the switch before an app is picked', () => {
		const { connectPath } = load( site );

		expect( connectPath( null, { signIn: on } ) ).toBe( 'address' );
		expect( connectPath( null, { signIn: off } ) ).toBe( 'key' );
		expect( connectPath( null, { signIn: off, offer: true } ) ).toBe(
			'address'
		);
		expect( connectPath( null ) ).toBe( 'key' );
	} );
} );

describe( 'APP_GROUPS', () => {
	it( 'puts every app in exactly one group, and leaves "other" for the link', () => {
		const { APPS, APP_GROUPS } = load( site );
		const grouped = APP_GROUPS.flatMap( ( g ) => g.apps );

		expect( new Set( grouped ).size ).toBe( grouped.length );
		expect( grouped.sort() ).toEqual(
			APPS.map( ( a ) => a.key )
				.filter( ( k ) => 'other' !== k )
				.sort()
		);
	} );
} );
