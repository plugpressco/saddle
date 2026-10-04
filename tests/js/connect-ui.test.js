/**
 * The connect screens, rendered (1.5.0 release QA): the Connect an app
 * drawer makes a key in place and reads like the address path (S7), leads
 * with what works on this computer (P7), never shows a wrong "Connected"
 * dot first (R10) and cleans up a key a reload left (R1); the welcome never
 * asks for the app again.
 */

const SITE = {
	mcpUrl: 'https://example.com/wp-json/saddle/v1/mcp',
	user: 'admin',
	serverSlug: 'saddle-example',
	areas: [],
	appPasswords: true,
	ssl: true,
};

const LOCAL = {
	...SITE,
	mcpUrl: 'http://localhost:8080/wp-json/saddle/v1/mcp',
};

const SIGN_IN_OFF = { enabled: false, ready: true, permalinks: true };
const SIGN_IN_ON = { enabled: true, ready: true, permalinks: true, ssl: true };
const NO_SIGN_IN = { enabled: false, ready: false, permalinks: true };

const NEW_KEY = {
	uuid: 'aaaa-1111',
	label: 'Claude Code',
	password: 'abcd efgh ijkl',
};

global.IS_REACT_ACT_ENVIRONMENT = true;

/* eslint-disable import/no-extraneous-dependencies -- React comes with
   @wordpress/scripts here; in wp-admin the screens get it from WordPress as
   wp.element, which Jest stands in for below. */

/**
 * A fake REST API: each route answers from `routes`, and every call is
 * recorded as "METHOD path".
 *
 * @param {Object} routes "METHOD path" → value, or function( options ).
 * @return {Function} The fake `api()`, with `.calls`.
 */
function fakeApi( routes ) {
	const calls = [];
	const api = ( path, options = {} ) => {
		const method = options.method || 'GET';
		const key = `${ method } ${ path.split( '?' )[ 0 ] }`;
		calls.push( key );
		if ( ! ( key in routes ) ) {
			// Never answers: a route the test doesn't care about.
			return new Promise( () => {} );
		}
		const value = routes[ key ];
		return Promise.resolve(
			'function' === typeof value ? value( options ) : value
		);
	};
	api.calls = calls;
	return api;
}

/**
 * Load the connect screens fresh, against a page's data and a fake API.
 *
 * @param {Object} data   window.saddleData for this load.
 * @param {Object} routes The fake API's answers.
 * @return {Object} React, the renderer and the components.
 */
function setup( data, routes ) {
	jest.resetModules();
	window.saddleData = data;
	const api = fakeApi( routes );
	jest.doMock( '@wordpress/element', () => require( 'react' ), {
		virtual: true,
	} );
	jest.doMock( '../../admin/src/api', () => ( {
		api,
		saddleData: data,
		connectionPath: ( id, rest = '' ) =>
			[ 'connections', id, rest ].filter( Boolean ).join( '/' ),
	} ) );
	// The app logos are SVG imports, which Jest can't read.
	jest.doMock( '../../admin/src/components/icons', () => ( {
		AppLogo: () => null,
		BrandMark: () => null,
		appKeyFromLabel: ( label ) =>
			String( label || '' )
				.toLowerCase()
				.replace( /\s+\d+$/, '' )
				.replace( /\s+/g, '-' ),
	} ) );
	const React = require( 'react' );
	const { createRoot } = require( 'react-dom/client' );
	return {
		React,
		api,
		createRoot,
		ConnectApps: require( '../../admin/src/components/ConnectApps' )
			.default,
		ConnectWizard: require( '../../admin/src/components/ConnectWizard' )
			.default,
	};
}

/**
 * Render an element into the document and let its effects settle.
 *
 * @param {Object} env     From setup().
 * @param {Object} element The element.
 * @return {Promise<Object>} `{ el, unmount }`.
 */
async function render( env, element ) {
	const el = document.createElement( 'div' );
	document.body.appendChild( el );
	const root = env.createRoot( el );
	await env.React.act( async () => {
		root.render( element );
	} );
	await settle( env );
	return {
		el,
		unmount: async () => {
			await env.React.act( async () => root.unmount() );
			await settle( env );
		},
	};
}

// Let pending promises and the renders they cause finish.
async function settle( env ) {
	for ( let i = 0; i < 5; i++ ) {
		await env.React.act( async () => {
			await Promise.resolve();
		} );
	}
}

async function click( env, node ) {
	await env.React.act( async () => {
		node.dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true } )
		);
	} );
	await settle( env );
}

const button = ( el, text ) =>
	[ ...el.querySelectorAll( 'button, a' ) ].find(
		( b ) => b.textContent.trim() === text
	);

beforeEach( () => {
	window.sessionStorage.clear();
	document.body.innerHTML = '';
} );

describe( 'the drawer’s key path (S7)', () => {
	const routes = ( extra = {} ) => ( {
		'GET connections': { connections: [] },
		'POST clients': NEW_KEY,
		'GET connections/pulse': { now: 1000, connections: [] },
		...extra,
	} );

	it( 'makes the key in the drawer and shows it as one line per step', async () => {
		const env = setup( SITE, routes() );
		const { el } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_OFF } initialApp="claude-code" />
		);

		expect( el.textContent ).toContain(
			'Sign-in for apps is off, so Claude Code connects with a key.'
		);
		await click( env, button( el, 'Make a key for Claude Code' ) );

		expect( env.api.calls ).toContain( 'POST clients' );
		const steps = el.querySelectorAll( '.saddle-connect__list > li' );
		expect( steps ).toHaveLength( 2 );
		expect( steps[ 0 ].textContent ).toContain(
			'Paste this into your terminal and press Enter.'
		);
		expect( steps[ 0 ].querySelector( 'code' ).textContent ).toContain(
			'--header "Authorization: Basic'
		);
		expect( el.textContent ).toContain(
			'Waiting for Claude Code to connect…'
		);
		// None of the old wizard's chrome, and the app isn't asked again.
		expect( el.textContent ).not.toMatch(
			/Choose app|Paste setup|Set up Claude Code|will be able to do|Which app are you connecting/
		);
		expect( el.querySelector( '.saddle-connect__app' ) ).toBeNull();
	} );

	it( 'deletes a key nobody copied when the drawer closes', async () => {
		const env = setup(
			SITE,
			routes( { [ `DELETE clients/${ NEW_KEY.uuid }` ]: {} } )
		);
		const { el, unmount } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_OFF } initialApp="claude-code" />
		);
		await click( env, button( el, 'Make a key for Claude Code' ) );
		await unmount();

		expect( env.api.calls ).toContain( `DELETE clients/${ NEW_KEY.uuid }` );
	} );

	it( 'deletes a key that arrives after the drawer closed', async () => {
		let answer;
		const env = setup(
			SITE,
			routes( {
				'POST clients': () =>
					new Promise( ( resolve ) => {
						answer = resolve;
					} ),
				[ `DELETE clients/${ NEW_KEY.uuid }` ]: {},
			} )
		);
		const { el, unmount } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_OFF } initialApp="claude-code" />
		);
		await click( env, button( el, 'Make a key for Claude Code' ) );
		await unmount();
		await env.React.act( async () => answer( NEW_KEY ) );
		await settle( env );

		expect( env.api.calls ).toContain( `DELETE clients/${ NEW_KEY.uuid }` );
	} );

	it( 'keeps a key once its setup was copied', async () => {
		const env = setup(
			SITE,
			routes( { [ `DELETE clients/${ NEW_KEY.uuid }` ]: {} } )
		);
		const { el, unmount } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_OFF } initialApp="claude-code" />
		);
		await click( env, button( el, 'Make a key for Claude Code' ) );
		await click( env, el.querySelector( '.pp-code__copy' ) );
		await unmount();

		expect( env.api.calls ).not.toContain(
			`DELETE clients/${ NEW_KEY.uuid }`
		);
	} );

	it( 'offers to replace an older key for the app instead of stacking one', async () => {
		const env = setup(
			SITE,
			routes( {
				'GET connections': {
					connections: [
						{
							id: 'key:old-1',
							kind: 'key',
							app: 'claude-code',
							name: 'Claude Code',
							created_at: 100,
							last_seen_at: 200,
						},
					],
				},
				'POST clients/old-1/rotate': NEW_KEY,
			} )
		);
		const { el } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_OFF } initialApp="claude-code" />
		);
		await click( env, button( el, 'Make a key for Claude Code' ) );

		expect( el.textContent ).toContain( 'Claude Code already has a key.' );
		expect( env.api.calls ).not.toContain( 'POST clients' );
		await click( env, button( el, 'Replace its key (recommended)' ) );

		expect( env.api.calls ).toContain( 'POST clients/old-1/rotate' );
		expect(
			el.querySelectorAll( '.saddle-connect__list > li' )
		).toHaveLength( 2 );
	} );

	it( 'switches to a key in place from the address steps', async () => {
		const env = setup( SITE, routes() );
		const { el } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_ON } initialApp="cursor" />
		);

		expect( el.textContent ).toContain( 'Approve it on the screen' );
		await click( env, button( el, 'Use a key instead' ) );

		expect( env.api.calls ).toContain( 'POST clients' );
		expect( el.textContent ).toContain( 'Use the address instead' );
		expect( el.querySelector( 'code' ).textContent ).toContain(
			'Authorization'
		);
	} );

	it( 'says ChatGPT needs sign-in instead of offering a key it can’t use', async () => {
		const env = setup( SITE, routes() );
		const { el } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_OFF } initialApp="chatgpt" />
		);

		expect( el.textContent ).toContain(
			'ChatGPT connects only through sign-in for apps, which is off.'
		);
		expect( el.textContent ).not.toContain( 'connects with a key' );
		expect( button( el, 'Make a key for ChatGPT' ) ).toBeUndefined();
	} );
} );

describe( 'the drawer on a site on this computer (P7)', () => {
	it( 'leads with the apps that run here, then Claude’s desktop bridge', async () => {
		const env = setup( LOCAL, {
			'GET connections': { connections: [] },
		} );
		const { el } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_ON } initialApp="claude" />
		);
		const text = el.textContent;

		expect( text ).toContain(
			'Claude runs on its own servers, so it can’t reach a site on this computer.'
		);
		const tiles = [ ...el.querySelectorAll( '.saddle-connect__app' ) ];
		expect( tiles.map( ( t ) => t.textContent ) ).toEqual( [
			'Claude Code',
			'Cursor',
			'Codex',
		] );
		const key = button( el, 'Make a key for Claude' );
		expect( key.className ).toContain( 'pp-btn--secondary' );
		// The tiles come first; the key follows the line about the bridge.
		const order = [ ...el.querySelectorAll( '*' ) ];
		expect( order.indexOf( tiles[ 0 ] ) ).toBeLessThan(
			order.indexOf( key )
		);
		// No address steps that can't work here.
		expect( text ).not.toContain( 'Approve it on the screen' );
	} );

	it( 'shows the desktop setup for the key it makes', async () => {
		const env = setup( LOCAL, {
			'GET connections': { connections: [] },
			'POST clients': { ...NEW_KEY, label: 'Claude' },
			'GET connections/pulse': { now: 1000, connections: [] },
		} );
		const { el } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_ON } initialApp="claude" />
		);
		await click( env, button( el, 'Make a key for Claude' ) );

		const first = el.querySelector( '.saddle-connect__list > li' );
		expect( first.textContent ).toContain( 'In the Claude desktop app' );
		expect( first.textContent ).toContain( 'mcp-remote' );
	} );

	it( 'opens on a tile it lists', async () => {
		const env = setup( LOCAL, {
			'GET connections': { connections: [] },
		} );
		const { el } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_OFF } initialApp="chatgpt" />
		);
		await click(
			env,
			[ ...el.querySelectorAll( '.saddle-connect__app' ) ].find(
				( t ) => 'Cursor' === t.textContent
			)
		);

		expect( el.querySelector( 'h3' ).textContent ).toBe( 'Cursor' );
		expect( button( el, 'Make a key for Cursor' ) ).toBeDefined();
	} );
} );

describe( 'the tiles’ Connected dots (R10)', () => {
	it( 'waits for the connections instead of drawing tiles without dots', async () => {
		let answer;
		const env = setup( SITE, {
			'GET connections': () =>
				new Promise( ( resolve ) => {
					answer = resolve;
				} ),
		} );
		const { el } = await render(
			env,
			<env.ConnectApps oauth={ SIGN_IN_OFF } />
		);

		expect( el.querySelector( '.saddle-connect__app' ) ).toBeNull();
		expect(
			el.querySelectorAll( '.saddle-connect__app-skeleton' ).length
		).toBeGreaterThan( 0 );

		await env.React.act( async () =>
			answer( {
				connections: [
					{
						id: 'key:1',
						kind: 'key',
						app: 'cursor',
						last_seen_at: 50,
					},
				],
			} )
		);
		await settle( env );

		const cursor = [
			...el.querySelectorAll( '.saddle-connect__app' ),
		].find( ( t ) => t.textContent.startsWith( 'Cursor' ) );
		expect(
			cursor.querySelector( '[aria-label="Connected"]' )
		).not.toBeNull();
	} );
} );

describe( 'a key a reload left behind (R1)', () => {
	it( 'is removed when the next screen opens, unless an app used it', async () => {
		window.sessionStorage.setItem( 'saddle-pending-key', 'left-1' );
		const env = setup( SITE, {
			'GET connections': { connections: [] },
			'GET clients': {
				clients: [
					{ uuid: 'left-1', label: 'Claude', last_used: null },
				],
			},
			'DELETE clients/left-1': {},
		} );
		await render( env, <env.ConnectApps oauth={ SIGN_IN_OFF } /> );

		expect( env.api.calls ).toContain( 'DELETE clients/left-1' );
		expect( window.sessionStorage.getItem( 'saddle-pending-key' ) ).toBe(
			null
		);
	} );

	it( 'stays when an app used it after all', async () => {
		window.sessionStorage.setItem( 'saddle-pending-key', 'left-1' );
		const env = setup( SITE, {
			'GET connections': { connections: [] },
			'GET clients': {
				clients: [ { uuid: 'left-1', label: 'Claude', last_used: 99 } ],
			},
		} );
		await render( env, <env.ConnectApps oauth={ SIGN_IN_OFF } /> );

		expect( env.api.calls ).not.toContain( 'DELETE clients/left-1' );
	} );

	it( 'is cleaned up in the welcome too', async () => {
		window.sessionStorage.setItem( 'saddle-pending-key', 'left-1' );
		const env = setup( SITE, {
			'GET oauth-settings': SIGN_IN_OFF,
			'GET clients': {
				clients: [
					{ uuid: 'left-1', label: 'Claude', last_used: null },
				],
			},
			'DELETE clients/left-1': {},
		} );
		await render(
			env,
			<env.ConnectWizard
				embedded
				presetApp="claude"
				clients={ [] }
				renderWaiting={ () => null }
				onExit={ () => {} }
			/>
		);

		expect( env.api.calls ).toContain( 'DELETE clients/left-1' );
	} );
} );

describe( 'the welcome’s connect step', () => {
	it( 'asks about an older key without showing the app picker again', async () => {
		const env = setup( SITE, {
			'GET oauth-settings': NO_SIGN_IN,
		} );
		const { el } = await render(
			env,
			<env.ConnectWizard
				embedded
				presetApp="claude-code"
				clients={ [
					{ uuid: 'old-1', label: 'Claude Code', last_used: 99 },
				] }
				renderWaiting={ () => null }
				onExit={ () => {} }
			/>
		);

		expect( el.textContent ).toContain( 'Claude Code already has a key.' );
		expect( button( el, 'Replace its key (recommended)' ) ).toBeDefined();
		expect( el.textContent ).not.toMatch(
			/Which AI do you use|Which app are you connecting|More apps/
		);
		expect( el.querySelector( '[role="radiogroup"]' ) ).toBeNull();
	} );

	it( 'puts the sign-in button inside its callout and "Add to Claude" beside its line (R8)', async () => {
		const env = setup( SITE, {
			'GET oauth-settings': SIGN_IN_OFF,
		} );
		const { el } = await render(
			env,
			<env.ConnectWizard
				embedded
				presetApp="claude"
				clients={ [] }
				renderWaiting={ () => null }
				onExit={ () => {} }
			/>
		);

		expect(
			button( el, 'Turn on sign-in' ).closest( '.pp-callout__action' )
		).not.toBeNull();
		const install = el.querySelector( '.saddle-wizard__install' );
		expect( install.textContent ).toContain( 'Or open Claude' );
		expect( button( install, 'Add to Claude' ).parentElement ).toBe(
			install
		);
	} );

	it( 'shows Claude’s desktop bridge on a site on this computer (P7)', async () => {
		const env = setup( LOCAL, {
			'GET oauth-settings': SIGN_IN_ON,
			'POST clients': { ...NEW_KEY, label: 'Claude' },
		} );
		const { el } = await render(
			env,
			<env.ConnectWizard
				embedded
				presetApp="claude"
				clients={ [] }
				renderWaiting={ () => null }
				onExit={ () => {} }
			/>
		);

		expect( el.textContent ).toContain(
			'The Claude desktop app can reach it through a small bridge.'
		);
		expect( el.textContent ).toContain( 'In the Claude desktop app' );
		expect( el.textContent ).not.toContain( 'Older Claude desktop builds' );
		expect( button( el, 'Use the address instead' ) ).toBeUndefined();
	} );

	it( 'tells a local ChatGPT owner to pick an app on this computer', async () => {
		const env = setup( LOCAL, {
			'GET oauth-settings': SIGN_IN_ON,
		} );
		const { el } = await render(
			env,
			<env.ConnectWizard
				embedded
				presetApp="chatgpt"
				clients={ [] }
				renderWaiting={ () => {
					throw new Error( 'nothing to wait for' );
				} }
				onExit={ () => {} }
			/>
		);

		expect( el.textContent ).toContain(
			'Try Claude Code, Cursor or Codex here'
		);
		expect( el.querySelector( '.saddle-wizard__config' ) ).toBeNull();
	} );
} );

describe( 'the key setup’s own address (S7)', () => {
	it( 'shows the drawer’s steps with a key for the app, and no stepper', async () => {
		const env = setup( SITE, {
			'GET oauth-settings': SIGN_IN_OFF,
			'GET connections': { connections: [] },
			'POST clients': NEW_KEY,
			'GET connections/pulse': { now: 1000, connections: [] },
		} );
		const { el } = await render(
			env,
			<env.ConnectWizard
				initialApp="claude-code"
				clients={ [] }
				onExit={ () => {} }
			/>
		);

		expect( el.querySelector( 'h2' ).textContent ).toBe( 'Connect an app' );
		expect( env.api.calls ).toContain( 'POST clients' );
		expect(
			el.querySelectorAll( '.saddle-connect__list > li' )
		).toHaveLength( 2 );
		expect(
			el.querySelector( '.pp-steps, .saddle-wizard__top' )
		).toBeNull();
		expect( el.textContent ).not.toContain(
			'Which app are you connecting'
		);
	} );
} );
