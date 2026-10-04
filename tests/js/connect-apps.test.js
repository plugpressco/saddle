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

	it( 'puts only "Basic …" in a JSON key config’s Authorization header', () => {
		// The symptom: Cursor, VS Code, Windsurf and "Any MCP app" got
		// "Authorization": "Authorization: Basic …", so the app sent the
		// header name twice and every call was refused.
		const { buildConfig, buildGuideConfig } = load( site );
		const basic = `Basic ${ btoa( 'admin:abcdEFGH' ) }`;
		const server = ( app, root = 'mcpServers' ) =>
			JSON.parse( buildConfig( app, 'abcd EFGH', 'key' ) )[ root ][
				'saddle-example'
			];

		expect( server( 'cursor' ).headers.Authorization ).toBe( basic );
		expect( server( 'other' ).headers.Authorization ).toBe( basic );
		expect( server( 'windsurf' ).headers.Authorization ).toBe( basic );
		expect( server( 'vscode', 'servers' ).headers.Authorization ).toBe(
			basic
		);
		expect(
			JSON.parse( buildGuideConfig( 'cursor', 'key' ) ).mcpServers[
				'saddle-example'
			].headers.Authorization
		).toBe( 'Basic PASTE-YOUR-KEY-HERE' );
		// The command-line apps name the header themselves.
		expect( buildConfig( 'claude-code', 'abcd EFGH', 'key' ) ).toContain(
			`--header "Authorization: ${ basic }"`
		);
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
	} );

	it( 'keeps Claude on its desktop bridge on a local site with sign-in on (P7)', () => {
		// The symptom: with sign-in on, a local site led Claude with the
		// connector address, which Claude's servers can never reach. Only
		// the desktop bridge (a key) works there, whatever the owner prefers.
		const { APPS, connectPath } = load( site );
		const claude = meta( APPS, 'claude' );

		expect( connectPath( claude, { signIn: on, local: true } ) ).toBe(
			'key'
		);
		expect(
			connectPath( claude, { signIn: on, local: true, prefer: true } )
		).toBe( 'key' );
		expect(
			connectPath( meta( APPS, 'claude-code' ), {
				signIn: on,
				local: true,
			} )
		).toBe( 'address' );
		// Online, Claude's connector leads as before.
		expect( connectPath( claude, { signIn: on } ) ).toBe( 'address' );
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

describe( 'a site on this computer (P7)', () => {
	it( 'knows a local address', () => {
		const { isLocalAddress } = load( site );

		[
			'http://localhost:8080/wp-json/saddle/v1/mcp',
			'http://127.0.0.1:9420/wp-json/saddle/v1/mcp',
			'https://shop.test/wp-json/saddle/v1/mcp',
			'http://my-site.local/wp-json/saddle/v1/mcp',
		].forEach( ( url ) => expect( isLocalAddress( url ) ).toBe( true ) );
		[
			MCP_URL,
			'https://localhosting.example/wp-json/saddle/v1/mcp',
			'',
			null,
		].forEach( ( url ) => expect( isLocalAddress( url ) ).toBe( false ) );
	} );

	it( 'reads the page’s own address once', () => {
		expect( load( site ).IS_LOCAL ).toBe( false );
		expect(
			load( {
				...site,
				mcpUrl: 'http://localhost:8080/wp-json/saddle/v1/mcp',
			} ).IS_LOCAL
		).toBe( true );
	} );

	it( 'says only web apps can’t reach it', () => {
		const { APPS, WEB_APPS, LOCAL_APPS, unreachableHere } = load( site );

		APPS.forEach( ( app ) =>
			expect( unreachableHere( app, true ) ).toBe(
				WEB_APPS.includes( app.key )
			)
		);
		APPS.forEach( ( app ) =>
			expect( unreachableHere( app, false ) ).toBe( false )
		);
		expect( unreachableHere( null, true ) ).toBe( false );
		// What the drawer offers instead runs on this computer.
		LOCAL_APPS.forEach( ( key ) =>
			expect( WEB_APPS ).not.toContain( key )
		);
	} );

	it( 'tells Claude where the bridge goes on a local site', () => {
		const { APPS, howFor } = load( site );
		const claude = APPS.find( ( a ) => a.key === 'claude' );
		const cursor = APPS.find( ( a ) => a.key === 'cursor' );

		expect( howFor( claude, 'key', true ) ).toBe( claude.howLocal );
		expect( howFor( claude, 'key', true ) ).not.toMatch( /Older/ );
		expect( howFor( claude, 'key' ) ).toBe( claude.how );
		expect( howFor( claude, 'address', true ) ).toBe( claude.howAddress );
		expect( howFor( cursor, 'key', true ) ).toBe( cursor.how );
	} );
} );

describe( 'existingKey: an older key for the same app', () => {
	const appOf = ( name ) =>
		( { 'Claude Code': 'claude-code', Cursor: 'cursor' } )[ name ] || '';

	it( 'finds the newest key for the app, by app or by name', () => {
		const { existingKey } = load( site );
		const rows = [
			{
				id: 'key:old',
				kind: 'key',
				app: 'claude-code',
				created_at: 100,
			},
			{
				id: 'key:new',
				kind: 'key',
				name: 'Claude Code',
				created_at: 300,
			},
			{
				id: 'oauth:7',
				kind: 'oauth',
				app: 'claude-code',
				created_at: 500,
			},
			{ id: 'key:cur', kind: 'key', app: 'cursor', created_at: 900 },
		];

		expect( existingKey( rows, 'claude-code', appOf ).id ).toBe(
			'key:new'
		);
		expect( existingKey( rows, 'cursor', appOf ).id ).toBe( 'key:cur' );
	} );

	it( 'finds none for a sign-in, another app, or "Any MCP app"', () => {
		const { existingKey } = load( site );
		const rows = [
			{ id: 'oauth:1', kind: 'oauth', app: 'claude' },
			{ id: 'key:x', kind: 'key', app: 'other', name: 'AI app' },
		];

		expect( existingKey( rows, 'claude', appOf ) ).toBeNull();
		expect( existingKey( rows, 'other', appOf ) ).toBeNull();
		expect( existingKey( null, 'cursor' ) ).toBeNull();
	} );
} );

describe( 'a key made on this screen and not yet copied (R1)', () => {
	beforeEach( () => window.sessionStorage.clear() );

	it( 'is remembered across a reload, and forgotten once kept', () => {
		const { rememberPendingKey, pendingKey, forgetPendingKey } =
			load( site );

		expect( pendingKey() ).toBe( '' );
		rememberPendingKey( 'abc-1' );
		expect( pendingKey() ).toBe( 'abc-1' );
		// Another key's clean-up leaves this one alone.
		forgetPendingKey( 'zzz-9' );
		expect( pendingKey() ).toBe( 'abc-1' );
		forgetPendingKey( 'abc-1' );
		expect( pendingKey() ).toBe( '' );
	} );
} );
