/**
 * The pure rules of onboarding (#277): resume, the waiting line's backoff and
 * status, the plain-words tool names, Home's Setup block and the K2 fixture.
 */
import {
	STEPS,
	resumeStep,
	resumeConnect,
	stepAfter,
	showFirstRun,
	tourDue,
	nextDelay,
	POLL_START,
	POLL_CAP,
	connectStatus,
	connectPulseOptions,
	tryStatus,
	toolPhrase,
	bareToolName,
	timeoutTip,
	tryPrompt,
	selfCheckFindings,
	parseModules,
	moduleTasks,
	coreTasks,
	setupBlock,
} from '../../admin/src/onboarding-logic';

describe( 'the step machine', () => {
	it( 'opens at the site read when nothing is stored', () => {
		expect( resumeStep( { step: '' } ) ).toBe( 'read' );
		expect( resumeStep( undefined ) ).toBe( 'read' );
		expect( resumeStep( { step: 'nonsense', app: 'claude' } ) ).toBe(
			'read'
		);
	} );

	it( 'resumes at the stored step when the app is known', () => {
		STEPS.forEach( ( step ) =>
			expect( resumeStep( { step, app: 'claude' } ) ).toBe( step )
		);
	} );

	it( 'falls back to choosing an app when the app was not stored', () => {
		[ 'connect', 'try', 'choose' ].forEach( ( step ) =>
			expect( resumeStep( { step, app: '' } ) ).toBe( 'app' )
		);
		expect( resumeStep( { step: 'app', app: '' } ) ).toBe( 'app' );
	} );

	it( 'goes app, connect, try, choose, then ends', () => {
		expect( stepAfter( 'app' ) ).toBe( 'connect' );
		expect( stepAfter( 'connect' ) ).toBe( 'try' );
		expect( stepAfter( 'try' ) ).toBe( 'choose' );
		expect( stepAfter( 'choose' ) ).toBeNull();
		expect( stepAfter( 'bogus' ) ).toBeNull();
	} );

	it( 'shows first run while unfinished, or when asked for again', () => {
		expect( showFirstRun( { state: 'new' }, false ) ).toBe( true );
		expect( showFirstRun( { state: 'active' }, false ) ).toBe( true );
		expect( showFirstRun( { state: 'done' }, false ) ).toBe( false );
		expect( showFirstRun( { state: 'skipped' }, false ) ).toBe( false );
		expect( showFirstRun( { state: 'done' }, true ) ).toBe( true );
	} );

	it( 'runs the tour once, only after a real first run', () => {
		const done = {
			first_run: { state: 'done', tier_choice: 'read' },
			user: { tour_done: false },
		};
		expect( tourDue( done ) ).toBe( true );
		expect( tourDue( { ...done, user: { tour_done: true } } ) ).toBe(
			false
		);
		// Migrated (no choice on record) and skipped runs get no tour.
		expect(
			tourDue( {
				...done,
				first_run: { state: 'done', tier_choice: '' },
			} )
		).toBe( false );
		expect(
			tourDue( {
				...done,
				first_run: { state: 'skipped', tier_choice: '' },
			} )
		).toBe( false );
		expect( tourDue( null ) ).toBe( false );
	} );
} );

describe( 'poll backoff', () => {
	it( 'starts at 3 s, grows, and caps at 30 s', () => {
		expect( nextDelay( 0, false ) ).toBe( POLL_START );
		let wait = POLL_START;
		const seen = [ wait ];
		for ( let i = 0; i < 12; i++ ) {
			wait = nextDelay( wait, false );
			seen.push( wait );
		}
		expect( Math.max( ...seen ) ).toBe( POLL_CAP );
		expect( seen ).toEqual( [ ...seen ].sort( ( a, b ) => a - b ) );
		expect( POLL_START ).toBe( 3000 );
		expect( POLL_CAP ).toBe( 30000 );
	} );

	it( 'drops back to 3 s when something changed', () => {
		expect( nextDelay( 30000, true ) ).toBe( POLL_START );
	} );
} );

describe( 'connect status: the finest true thing to say', () => {
	const base = { app: 'claude', appLabel: 'Claude', keyId: null };

	it( 'waits when nothing has happened', () => {
		const s = connectStatus( { ...base, rows: [], pending: [] } );
		expect( s.phase ).toBe( 'waiting' );
		expect( s.text ).toBe( 'Waiting for Claude…' );
	} );

	it( 'says the app asked to connect once it has registered', () => {
		const s = connectStatus( {
			...base,
			rows: [],
			pending: [ { client_id: 'x', app: 'claude' } ],
		} );
		expect( s.phase ).toBe( 'asked' );
		expect( s.text ).toMatch(
			/asked to connect\. Approve it on the screen/
		);
	} );

	it( 'ignores another app that registered', () => {
		const s = connectStatus( {
			...base,
			rows: [],
			pending: [ { client_id: 'x', app: 'cursor' } ],
		} );
		expect( s.phase ).toBe( 'waiting' );
	} );

	it( 'treats an unrecognised pending app as possibly ours', () => {
		const s = connectStatus( {
			...base,
			rows: [],
			pending: [ { client_id: 'x', app: '' } ],
		} );
		expect( s.phase ).toBe( 'asked' );
	} );

	it( 'is done when an address connection for this app reaches the site', () => {
		const row = {
			id: 'oauth:1',
			kind: 'oauth',
			app: 'claude',
			client: 'claude-ai 0.1.0',
		};
		const s = connectStatus( { ...base, rows: [ row ], pending: [] } );
		expect( s.phase ).toBe( 'done' );
		expect( s.connection ).toBe( row );
		expect( s.text ).toBe( 'Claude connected (claude-ai 0.1.0)' );
	} );

	it( 'does not count another app’s connection', () => {
		const s = connectStatus( {
			...base,
			rows: [ { id: 'oauth:2', kind: 'oauth', app: 'cursor' } ],
			pending: [],
		} );
		expect( s.phase ).toBe( 'waiting' );
	} );

	it( 'on the key path, only the key this screen made counts', () => {
		const other = { id: 'key:other', kind: 'key', app: 'claude' };
		const mine = { id: 'key:mine', kind: 'key', app: 'claude' };
		const args = { ...base, keyId: 'key:mine', pending: [] };
		expect( connectStatus( { ...args, rows: [ other ] } ).phase ).toBe(
			'waiting'
		);
		expect(
			connectStatus( { ...args, rows: [ other, mine ] } ).phase
		).toBe( 'done' );
	} );

	it( 'never says asked on the key path', () => {
		const s = connectStatus( {
			...base,
			keyId: 'key:mine',
			rows: [],
			pending: [ { client_id: 'x', app: 'claude' } ],
		} );
		expect( s.phase ).toBe( 'waiting' );
	} );
} );

describe( 'try-it status', () => {
	const row = ( extra ) => ( {
		app: 'claude',
		first_tool_at: 0,
		last_tool_at: 0,
		recent_tools: [],
		...extra,
	} );

	it( 'does not tick on a handshake alone', () => {
		const s = tryStatus( {
			rows: [ row( { last_seen_at: 5 } ) ],
			app: 'claude',
			appLabel: 'Claude',
		} );
		expect( s.phase ).toBe( 'waiting' );
		expect( s.items ).toEqual( [] );
	} );

	it( 'ticks on the first tool call and lists the calls oldest first', () => {
		const s = tryStatus( {
			rows: [
				row( {
					first_tool_at: 10,
					last_tool_at: 12,
					recent_tools: [
						'saddle/list-media',
						'saddle/get-site-info',
					],
				} ),
			],
			app: 'claude',
			appLabel: 'Claude',
		} );
		expect( s.phase ).toBe( 'done' );
		expect( s.text ).toBe( 'It works.' );
		expect( s.items ).toEqual( [
			'Claude read the site summary',
			'Claude listed media',
		] );
	} );

	it( 'ignores another app’s tool calls', () => {
		const s = tryStatus( {
			rows: [
				row( { app: 'cursor', first_tool_at: 10, last_tool_at: 10 } ),
			],
			app: 'claude',
			appLabel: 'Claude',
		} );
		expect( s.phase ).toBe( 'waiting' );
	} );
} );

describe( 'tool names in plain words', () => {
	it( 'strips either prefix', () => {
		expect( bareToolName( 'saddle/list-media' ) ).toBe( 'list-media' );
		expect( bareToolName( 'saddle-list-media' ) ).toBe( 'list-media' );
		expect( bareToolName( 'list-media' ) ).toBe( 'list-media' );
	} );

	it( 'maps the common reads', () => {
		expect( toolPhrase( 'saddle/get-site-info', 'Cursor' ) ).toBe(
			'Cursor read the site summary'
		);
		expect( toolPhrase( 'saddle-list-media', 'Cursor' ) ).toBe(
			'Cursor listed media'
		);
		expect( toolPhrase( 'saddle/list-posts', 'Claude' ) ).toBe(
			'Claude listed posts'
		);
	} );

	it( 'falls back to the tool name', () => {
		expect( toolPhrase( 'saddle/list-menus', 'Claude' ) ).toBe(
			'Claude used list-menus'
		);
	} );

	it( 'is not fooled by inherited object keys', () => {
		expect( toolPhrase( 'constructor', 'Claude' ) ).toBe(
			'Claude used constructor'
		);
	} );
} );

describe( 'timeout tips and the first prompt', () => {
	it( 'has a tip per common app and a generic one', () => {
		expect( timeoutTip( 'claude', 'Claude' ) ).toMatch( /tools menu/ );
		expect( timeoutTip( 'chatgpt', 'ChatGPT' ) ).toMatch( /\+ menu/ );
		expect( timeoutTip( 'grok', 'Grok' ) ).toMatch( /Grok/ );
	} );

	it( 'builds a read-only prompt from what step 0 found', () => {
		expect( tryPrompt( { findings: { missing_alt: 4 } } ) ).toMatch(
			/alt text\. Don’t change anything\.$/
		);
		expect(
			tryPrompt( {
				findings: { missing_alt: 0, missing_description: 3 },
			} )
		).toMatch( /search description/ );
		[ null, {}, { findings: {} } ].forEach( ( look ) =>
			expect( tryPrompt( look ) ).toMatch( /Don’t change anything\.$/ )
		);
	} );
} );

describe( 'the self-check after three minutes', () => {
	const keys = ( rows ) => rows.map( ( r ) => r.key );

	it( 'names the likely causes and always ends on the firewall', () => {
		const rows = selfCheckFindings( {
			ssl: false,
			permalinks: false,
			local: true,
			app: 'chatgpt',
			authHeader: 'auth_header_stripped',
		} );
		expect( keys( rows ) ).toEqual( [
			'https',
			'permalinks',
			'header',
			'local',
			'plan',
			'firewall',
		] );
		expect( rows.filter( ( r ) => false === r.ok ).length ).toBe( 4 );
	} );

	it( 'is quiet about what is fine', () => {
		const rows = selfCheckFindings( {
			ssl: true,
			permalinks: true,
			local: false,
			app: 'claude',
			authHeader: 'ok',
		} );
		expect( keys( rows ) ).toEqual( [
			'https',
			'permalinks',
			'header',
			'firewall',
		] );
		expect( rows.find( ( r ) => r.key === 'https' ).hint ).toBe( '' );
	} );

	it( 'skips the header row when the probe could not say', () => {
		const rows = selfCheckFindings( {
			ssl: true,
			permalinks: true,
			local: false,
			app: 'claude',
			authHeader: 'unknown',
		} );
		expect( keys( rows ) ).not.toContain( 'header' );
	} );
} );

/* ---------------------------------------------------- modules and Setup */

// What GET /modules returns (K2), trimmed to what Home reads.
const MODULES = {
	areas: [
		{ key: 'home', kind: 'core', title: 'Home', state: null, setup: null },
		{
			key: 'analytics',
			kind: 'module',
			title: 'Analytics',
			product: 'Saddle Analytics',
			state: 'needs-setup',
			setup: {
				done: 1,
				total: 3,
				tasks: [
					{ id: 'tracking', title: 'Counting visits', done: true },
					{
						id: 'first-visit',
						title: 'First visit recorded',
						done: false,
						waiting: true,
						action: {
							label: 'Open site',
							url: 'https://x.test/',
							external: true,
						},
					},
					{
						id: 'insight',
						title: 'Your first insight',
						done: false,
						after: 'first-visit',
					},
				],
			},
		},
		{
			key: 'crm',
			kind: 'module',
			title: 'CRM',
			product: 'Saddle CRM',
			state: 'ready',
			setup: { done: 2, total: 2, tasks: [] },
		},
		{ key: 'connections', kind: 'core', title: 'Connections', setup: null },
	],
};

describe( 'K2 fixture parsing', () => {
	it( 'reads the areas', () => {
		expect( parseModules( MODULES ) ).toHaveLength( 4 );
	} );

	it( 'treats a missing route or a bad body as no modules', () => {
		expect( parseModules( null ) ).toEqual( [] );
		expect( parseModules( {} ) ).toEqual( [] );
		expect( parseModules( { areas: 'x' } ) ).toEqual( [] );
		expect( parseModules( { areas: [ null, { nokey: 1 } ] } ) ).toEqual(
			[]
		);
	} );

	it( 'lists unfinished tasks, holding back one that waits on another', () => {
		const rows = moduleTasks( parseModules( MODULES ) );
		expect( rows.map( ( r ) => r.task.id ) ).toEqual( [ 'first-visit' ] );
		expect( rows[ 0 ].product ).toBe( 'Saddle Analytics' );
		expect( rows[ 0 ].task.action.external ).toBe( true );
	} );

	it( 'lists the dependent task once its first task is done', () => {
		const areas = parseModules( MODULES );
		areas[ 1 ].setup.tasks[ 1 ].done = true;
		expect( moduleTasks( areas ).map( ( r ) => r.task.id ) ).toEqual( [
			'insight',
		] );
	} );
} );

describe( 'Home’s Setup block', () => {
	const onboarding = ( run = {}, modules = {} ) => ( {
		first_run: { state: 'skipped', tier_choice: '', ...run },
		modules,
	} );
	const used = { id: 'oauth:1', first_tool_at: 5 };

	it( 'starts with all three undone on a skipped, unconnected site', () => {
		const tasks = coreTasks( {
			connections: [],
			firstRun: { state: 'skipped', tier_choice: '' },
			tier: 'read',
		} );
		expect( tasks.map( ( t ) => [ t.id, t.done ] ) ).toEqual( [
			[ 'connect', false ],
			[ 'try', false ],
			[ 'choose', false ],
		] );
	} );

	it( 'derives connect and try from the registry', () => {
		const connected = coreTasks( {
			connections: [ { id: 'oauth:1', first_tool_at: 0 } ],
			firstRun: { state: 'skipped' },
			tier: 'read',
		} );
		expect( connected.map( ( t ) => t.done ) ).toEqual( [
			true,
			false,
			false,
		] );

		const tried = coreTasks( {
			connections: [ used ],
			firstRun: { state: 'skipped' },
			tier: 'read',
		} );
		expect( tried.map( ( t ) => t.done ) ).toEqual( [ true, true, false ] );
	} );

	it( 'counts access as chosen once any app is above read only', () => {
		const choose = ( role ) =>
			coreTasks( {
				connections: [ { id: 'key:1', role } ],
				firstRun: { state: 'skipped', tier_choice: '' },
				tier: 'read',
			} )[ 2 ].done;
		expect( choose( 'write' ) ).toBe( true );
		expect( choose( 'read' ) ).toBe( false );
	} );

	it( 'counts the level as chosen once it is on record or above read', () => {
		const choose = ( firstRun, tier ) =>
			coreTasks( { connections: [], firstRun, tier } )[ 2 ].done;
		expect( choose( { state: 'done', tier_choice: 'read' }, 'read' ) ).toBe(
			true
		);
		expect( choose( { state: 'skipped', tier_choice: '' }, 'write' ) ).toBe(
			true
		);
		// A site migrated to done has no choice on record, and is not nagged.
		expect( choose( { state: 'done', tier_choice: '' }, 'read' ) ).toBe(
			true
		);
		expect( choose( { state: 'skipped', tier_choice: '' }, 'read' ) ).toBe(
			false
		);
	} );

	it( 'shows the block with counts across Core and modules', () => {
		const block = setupBlock( {
			connections: [ used ],
			onboarding: onboarding( { state: 'done', tier_choice: 'read' } ),
			tier: 'read',
			areas: parseModules( MODULES ),
		} );
		expect( block.visible ).toBe( true );
		// 3 core + 3 analytics + 2 crm tasks; 3 core + 1 + 2 done.
		expect( block.total ).toBe( 8 );
		expect( block.done ).toBe( 6 );
		expect( block.modules ).toHaveLength( 1 );
	} );

	it( 'disappears when everything is done', () => {
		const areas = parseModules( MODULES ).filter(
			( a ) => a.key !== 'analytics'
		);
		const block = setupBlock( {
			connections: [ used ],
			onboarding: onboarding( { state: 'done', tier_choice: 'write' } ),
			tier: 'write',
			areas,
		} );
		expect( block.visible ).toBe( false );
	} );

	it( 'disappears when the owner hid it', () => {
		const block = setupBlock( {
			connections: [],
			onboarding: onboarding(
				{},
				{ home: { setup_hidden_at: 1700000000 } }
			),
			tier: 'read',
			areas: [],
		} );
		expect( block.visible ).toBe( false );
	} );

	it( 'works with no modules route at all', () => {
		const block = setupBlock( {
			connections: [],
			onboarding: onboarding(),
			tier: 'read',
			areas: parseModules( null ),
		} );
		expect( block.visible ).toBe( true );
		expect( block.modules ).toEqual( [] );
		expect( block.total ).toBe( 3 );
	} );
} );

describe( 'the connect step pulse', () => {
	it( 'keeps the first answer for a key made on this screen', () => {
		// A key has no history, so a connection in the first answer is real.
		expect( connectPulseOptions( 'key:abc' ) ).toEqual( {
			ignoreExisting: false,
		} );
	} );

	it( 'treats the first answer as a baseline on the address path', () => {
		expect( connectPulseOptions( null ) ).toEqual( {
			ignoreExisting: true,
		} );
	} );
} );

describe( 'resuming the connect step', () => {
	const appOf = ( label ) =>
		label.toLowerCase().includes( 'claude code' ) ? 'claude-code' : 'x';

	it( 'moves on when the chosen app already used its key', () => {
		const clients = [ { label: 'Claude Code', last_used: 1790849110 } ];
		expect(
			resumeConnect( 'connect', 'claude-code', clients, appOf )
		).toBe( 'try' );
	} );

	it( 'stays when the key was never used', () => {
		const clients = [ { label: 'Claude Code', last_used: null } ];
		expect(
			resumeConnect( 'connect', 'claude-code', clients, appOf )
		).toBe( 'connect' );
	} );

	it( 'ignores another app’s key and other steps', () => {
		const clients = [ { label: 'Cursor', last_used: 1 } ];
		expect(
			resumeConnect( 'connect', 'claude-code', clients, appOf )
		).toBe( 'connect' );
		expect(
			resumeConnect(
				'app',
				'claude-code',
				[ { label: 'Claude Code', last_used: 1 } ],
				appOf
			)
		).toBe( 'app' );
	} );
} );
