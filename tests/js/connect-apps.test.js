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
			'claude',
			'claude-code',
			'chatgpt',
			'codex',
			'gemini-cli',
			'windsurf',
			'other',
		] ) {
			expect( installLinks( app, null, 'address' ) ).toEqual( [] );
		}
	} );

	it( 'offers no link when the site has no MCP address', () => {
		const { installLinks } = load( { ...site, mcpUrl: '' } );

		expect( installLinks( 'cursor', null, 'address' ) ).toEqual( [] );
	} );
} );
