/**
 * Run without asking on Agent setup (2026-11-06): only an agent set to
 * Manage the site has the switch; it starts off; turning it on asks the
 * owner once and then saves; turning it off saves at once; moving the agent
 * below Manage the site takes the switch away.
 */

global.IS_REACT_ACT_ENVIRONMENT = true;

/* eslint-disable import/no-extraneous-dependencies -- React comes with
   @wordpress/scripts here; in wp-admin the screens get it from WordPress as
   wp.element, which Jest stands in for below. */

const ADMIN = {
	id: 'key:aaaa-1111',
	kind: 'key',
	role: 'admin',
	app: 'claude-code',
	name: 'Claude Code',
	run_without_asking: false,
	created_at: 100,
	last_seen_at: 200,
};

const WRITER = {
	id: 'key:bbbb-2222',
	kind: 'key',
	role: 'write',
	app: 'cursor',
	name: 'Cursor',
	run_without_asking: false,
	created_at: 100,
	last_seen_at: 200,
};

/**
 * A fake REST API that records each call as "METHOD path" with its data.
 *
 * @param {Object} routes "METHOD path" → value, or function( options ).
 * @return {Function} The fake `api()`, with `.calls`.
 */
function fakeApi( routes ) {
	const calls = [];
	const api = ( path, options = {} ) => {
		const method = options.method || 'GET';
		const key = `${ method } ${ path.split( '?' )[ 0 ] }`;
		calls.push( { key, data: options.data } );
		if ( ! ( key in routes ) ) {
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

function setup( routes ) {
	jest.resetModules();
	window.saddleData = { mcpUrl: 'https://example.com/wp-json/saddle/v1/mcp' };
	const api = fakeApi( routes );
	jest.doMock( '@wordpress/element', () => require( 'react' ), {
		virtual: true,
	} );
	jest.doMock( '../../admin/src/api', () => ( {
		api,
		saddleData: window.saddleData,
		connectionPath: ( id, rest = '' ) =>
			[ 'connections', id, rest ].filter( Boolean ).join( '/' ),
	} ) );
	jest.doMock( '../../admin/src/icons/kit', () => ( {
		icons: new Proxy(
			{},
			{
				get: () => ( props ) =>
					require( 'react' ).createElement( 'span', {
						'aria-label': props[ 'aria-label' ],
					} ),
			}
		),
	} ) );
	jest.doMock( '../../admin/src/components/icons', () => ( {
		AppLogo: () => null,
		BrandMark: () => null,
		appKeyFromLabel: ( label ) =>
			String( label || '' )
				.toLowerCase()
				.replace( /\s+/g, '-' ),
	} ) );
	const React = require( 'react' );
	const { createRoot } = require( 'react-dom/client' );
	const ui = require( '@plugpress/ui' );
	return {
		React,
		api,
		createRoot,
		ui,
		Apps: require( '../../admin/src/components/ConnectedClients' ).default,
	};
}

async function settle( env ) {
	for ( let i = 0; i < 6; i++ ) {
		await env.React.act( async () => {
			await Promise.resolve();
		} );
	}
}

async function render( env ) {
	const el = document.createElement( 'div' );
	document.body.appendChild( el );
	const root = env.createRoot( el );
	await env.React.act( async () => {
		root.render(
			<env.ui.TooltipProvider>
				<env.ui.ConfirmProvider>
					<env.Apps
						clients={ [] }
						siteTier="read"
						onGuide={ () => {} }
						onRotate={ () => {} }
					/>
				</env.ui.ConfirmProvider>
			</env.ui.TooltipProvider>
		);
	} );
	await settle( env );
	return el;
}

async function click( env, node ) {
	await env.React.act( async () => {
		node.dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true } )
		);
	} );
	await settle( env );
}

const switches = () => [ ...document.querySelectorAll( '[role="switch"]' ) ];

const buttonNamed = ( text ) =>
	[ ...document.querySelectorAll( 'button' ) ].find(
		( b ) => b.textContent.trim() === text
	);

const posts = ( env ) =>
	env.api.calls.filter( ( c ) => c.key.endsWith( '/run-without-asking' ) );

beforeEach( () => {
	document.body.innerHTML = '';
} );

describe( 'Run without asking on Agent setup', () => {
	it( 'shows the switch, off, only for an agent set to Manage the site', async () => {
		const env = setup( {
			'GET connections': { connections: [ ADMIN, WRITER ] },
		} );
		const el = await render( env );

		expect( el.textContent ).toContain( 'Run without asking' );
		expect( el.textContent ).toContain(
			'This agent’s changes and deletes run right away, with no preview and no OK from you. Deleted posts and pages go to the Trash. Every change is logged and can be undone in Logs.'
		);
		expect( switches() ).toHaveLength( 1 );
		expect( switches()[ 0 ].getAttribute( 'aria-checked' ) ).toBe(
			'false'
		);
		// The switch is named by its visible label.
		const label = el.querySelector(
			`label[for="${ switches()[ 0 ].id }"]`
		);
		expect( label.textContent ).toBe( 'Run without asking' );
	} );

	it( 'asks the owner once before turning it on, then saves it', async () => {
		const env = setup( {
			'GET connections': { connections: [ ADMIN ] },
			'POST connections/key:aaaa-1111/run-without-asking': {
				...ADMIN,
				run_without_asking: true,
			},
		} );
		await render( env );

		await click( env, switches()[ 0 ] );
		expect( document.body.textContent ).toContain(
			'Let Claude Code run without asking?'
		);
		expect( posts( env ) ).toHaveLength( 0 );

		await click( env, buttonNamed( 'Run without asking' ) );
		expect( posts( env ) ).toEqual( [
			{
				key: 'POST connections/key:aaaa-1111/run-without-asking',
				data: { on: true },
			},
		] );
	} );

	it( 'saves nothing when the owner keeps asking', async () => {
		const env = setup( {
			'GET connections': { connections: [ ADMIN ] },
		} );
		await render( env );

		await click( env, switches()[ 0 ] );
		await click( env, buttonNamed( 'Keep asking' ) );

		expect( posts( env ) ).toHaveLength( 0 );
		expect( switches()[ 0 ].getAttribute( 'aria-checked' ) ).toBe(
			'false'
		);
	} );

	it( 'turns it off at once', async () => {
		const env = setup( {
			'GET connections': {
				connections: [ { ...ADMIN, run_without_asking: true } ],
			},
			'POST connections/key:aaaa-1111/run-without-asking': ADMIN,
		} );
		await render( env );

		expect( switches()[ 0 ].getAttribute( 'aria-checked' ) ).toBe( 'true' );
		await click( env, switches()[ 0 ] );

		expect( posts( env ) ).toEqual( [
			{
				key: 'POST connections/key:aaaa-1111/run-without-asking',
				data: { on: false },
			},
		] );
	} );
} );
