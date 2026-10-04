#!/usr/bin/env node
/**
 * The six-client connection matrix (#223), as a script.
 *
 * Real clients can't run in CI or in an agent's sandbox, so this replays the
 * handshake each one sends against a Saddle site: the same headers, protocol
 * version, clientInfo and order of requests. Each run checks what the client
 * would trip on: initialize, the initialized notification, the optional event
 * stream, tools/list, a read call, and a gated write that must preview and
 * change nothing. tests/CLIENTS.md says where each shape comes from and how
 * to record a run.
 *
 * Node 22 or newer, no dependencies.
 *
 *   node scripts/client-matrix.mjs --site https://example.test --user admin --key "abcd efgh ..."
 *
 * Run `--help` for every option. Credentials can come from the environment
 * instead (SADDLE_SITE, SADDLE_USER, SADDLE_KEY, SADDLE_TOKEN), which keeps
 * them out of shell history. Nothing here prints a credential.
 */

import { parseArgs } from 'node:util';

/* ------------------------------------------------------------ the clients */

/**
 * What each client sends. `paths` is how it can connect: `key` sends an
 * Application Password as Basic auth, `sign-in` signs in through the site's
 * OAuth server and sends a Bearer token. The first path is the one the connect
 * wizard leads with when sign-in is off.
 *
 * clientInfo names marked `verified` were read from each client's own code on
 * 2026-09-30 (see includes/class-saddle-connection-apps.php). Everything else
 * is the client's documented behaviour or its SDK's default; CLIENTS.md lists
 * which, and a run against the real client is what confirms it.
 */
const CLIENTS = [
	{
		id: 'claude-code',
		name: 'Claude Code',
		paths: [ 'key', 'sign-in' ],
		clientInfo: { name: 'claude-code', version: '2.1.0' }, // verified
		protocolVersion: '2025-06-18',
		// TypeScript SDK client: echoes the session, sends the version header,
		// opens the optional GET stream after `initialized`, lists prompts
		// (they become slash commands), and ends the session with DELETE.
		echoesSession: true,
		sendsVersionHeader: true,
		opensStream: true,
		listsPrompts: true,
		deletesSession: true,
	},
	{
		id: 'claude',
		name: 'Claude (web and desktop)',
		// A custom connector takes an address only. Older desktop builds use a
		// key through the mcp-remote bridge, which is the Claude Code shape.
		paths: [ 'sign-in' ],
		clientInfo: { name: 'claude-ai', version: '0.1.0' }, // name verified
		protocolVersion: '2025-06-18',
		echoesSession: true,
		sendsVersionHeader: true,
		opensStream: false,
		listsPrompts: false,
		deletesSession: false,
	},
	{
		id: 'chatgpt',
		name: 'ChatGPT',
		// Its connector form has no header field, so sign-in is the only path.
		paths: [ 'sign-in' ],
		clientInfo: { name: 'openai-mcp', version: '1.0.0' },
		protocolVersion: '2025-06-18',
		echoesSession: true,
		sendsVersionHeader: true,
		opensStream: false,
		listsPrompts: false,
		deletesSession: false,
		// "Refresh actions" asks for tools/list later, with no session header.
		refreshesWithoutSession: true,
	},
	{
		id: 'codex',
		name: 'Codex',
		paths: [ 'key', 'sign-in' ],
		clientInfo: { name: 'codex-mcp-client', version: '0.157.0' }, // verified
		protocolVersion: '2025-06-18',
		echoesSession: true,
		sendsVersionHeader: true,
		opensStream: false,
		listsPrompts: false,
		deletesSession: false,
		// Rust client: it JSON-parses any 200 body, so an empty 200 for a
		// notification is "EOF while parsing a value" (#155). 202 is the answer.
		strictNotification: true,
	},
	{
		id: 'gemini-cli',
		name: 'Gemini CLI',
		paths: [ 'key', 'sign-in' ],
		clientInfo: { name: 'gemini-cli-mcp-client', version: '0.0.1' },
		protocolVersion: '2025-06-18',
		echoesSession: true,
		sendsVersionHeader: true,
		opensStream: true,
		listsPrompts: true,
		deletesSession: false,
	},
	{
		id: 'cursor',
		name: 'Cursor',
		paths: [ 'key', 'sign-in' ],
		clientInfo: { name: 'cursor-vscode', version: '1.0.0' }, // verified
		protocolVersion: '2025-06-18',
		echoesSession: true,
		sendsVersionHeader: true,
		opensStream: true,
		listsPrompts: true,
		deletesSession: false,
	},
];

/**
 * Every version Saddle accepts on initialize (Saddle_MCP::SUPPORTED_PROTOCOL_VERSIONS),
 * plus 2025-03-26, which it accepts in the header but does not negotiate to.
 */
const VERSIONS = [ '2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25' ];

/** The read call and the gated write every client makes. */
const READ_TOOL = 'saddle-get-site-info';
const LIST_TOOL = 'saddle-list-posts';
const GATED_TOOL = 'saddle-delete-post';

/** Tool names Claude's and OpenAI's APIs both accept. */
const TOOL_NAME = /^[a-zA-Z0-9_-]{1,64}$/;

/* ------------------------------------------------------------ options */

const HELP = `Saddle client matrix: replay each AI app's MCP handshake against a site.

Usage:
  node scripts/client-matrix.mjs --site <url> [--user <login> --key <application password>] [--token <bearer>]

Options:
  --site <url>       The WordPress site address (or set SADDLE_SITE).
  --endpoint <url>   The full MCP address, if not <site>/wp-json/saddle/v1/mcp
                     (plain permalinks: <site>/?rest_route=/saddle/v1/mcp).
  --user <login>     The WordPress user the key belongs to (or SADDLE_USER).
  --key <password>   A Saddle key, i.e. an Application Password (or SADDLE_KEY).
  --token <token>    A sign-in access token, for the sign-in path (or SADDLE_TOKEN).
                     Without one, the sign-in path checks discovery only.
  --clients <ids>    Comma-separated: ${ CLIENTS.map( ( c ) => c.id ).join(
		', '
  ) }.
  --path <path>      Only "key" or only "sign-in".
  --write            Also run the gated write: a delete-post preview with no
                     token. It changes nothing, but the preview waits under
                     Needs your OK on Home for 15 minutes.
  --timeout <sec>    Per request. Default 30; shared hosting can take 15.
  --insecure         Accept a self-signed certificate (local sites).
  --markdown         Print the results as rows for tests/CLIENTS.md.
  --json             Print the results as JSON.
  --help             This text.

Exit code: 0 when nothing failed, 1 when a check failed, 2 for bad options.`;

function options() {
	let parsed;
	try {
		parsed = parseArgs( {
			options: {
				site: { type: 'string' },
				endpoint: { type: 'string' },
				user: { type: 'string' },
				key: { type: 'string' },
				token: { type: 'string' },
				clients: { type: 'string' },
				path: { type: 'string' },
				write: { type: 'boolean', default: false },
				timeout: { type: 'string', default: '30' },
				insecure: { type: 'boolean', default: false },
				markdown: { type: 'boolean', default: false },
				json: { type: 'boolean', default: false },
				help: { type: 'boolean', default: false },
			},
		} ).values;
	} catch ( error ) {
		usage( error.message );
	}

	if ( parsed.help ) {
		process.stdout.write( HELP + '\n' );
		process.exit( 0 );
	}

	const env = process.env;
	const site = ( parsed.site || env.SADDLE_SITE || '' ).replace( /\/+$/, '' );
	const endpoint =
		parsed.endpoint || ( site ? `${ site }/wp-json/saddle/v1/mcp` : '' );
	if ( ! endpoint ) {
		usage( 'Give --site (or --endpoint).' );
	}

	const ids = parsed.clients
		? parsed.clients.split( ',' ).map( ( s ) => s.trim() )
		: CLIENTS.map( ( c ) => c.id );
	const unknown = ids.filter(
		( id ) => ! CLIENTS.some( ( c ) => c.id === id )
	);
	if ( unknown.length ) {
		usage( `Unknown client: ${ unknown.join( ', ' ) }.` );
	}
	if ( parsed.path && ! [ 'key', 'sign-in' ].includes( parsed.path ) ) {
		usage( '--path is "key" or "sign-in".' );
	}

	const user = parsed.user || env.SADDLE_USER || '';
	const key = parsed.key || env.SADDLE_KEY || '';
	if ( ( user && ! key ) || ( key && ! user ) ) {
		usage( 'A key needs its user: give both --user and --key.' );
	}

	return {
		endpoint,
		basic:
			user && key
				? 'Basic ' +
				  Buffer.from( `${ user }:${ key }` ).toString( 'base64' )
				: '',
		bearer:
			parsed.token || env.SADDLE_TOKEN
				? 'Bearer ' + ( parsed.token || env.SADDLE_TOKEN )
				: '',
		clients: CLIENTS.filter( ( c ) => ids.includes( c.id ) ),
		path: parsed.path || '',
		write: !! parsed.write,
		timeout: Math.max( 1, parseInt( parsed.timeout, 10 ) || 30 ) * 1000,
		insecure: parsed.insecure,
		markdown: parsed.markdown,
		json: parsed.json,
	};
}

function usage( message ) {
	process.stderr.write( `${ message }\n\n${ HELP }\n` );
	process.exit( 2 );
}

/* ------------------------------------------------------------ HTTP */

/**
 * One HTTP request, with the body read and parsed as JSON or as an SSE
 * stream of JSON-RPC messages.
 *
 * @param {string} url
 * @param {Object} init    fetch() init.
 * @param {number} timeout Milliseconds.
 * @return {Promise<{status:number, headers:Headers, text:string, json:any, ms:number}>} The response, read.
 */
async function http( url, init, timeout ) {
	const started = Date.now();
	const response = await fetch( url, {
		...init,
		redirect: 'manual',
		signal: AbortSignal.timeout( timeout ),
	} );
	const type = response.headers.get( 'content-type' ) || '';

	// A GET stream stays open by design: read the headers, then let it go.
	if ( 'GET' === init.method && type.includes( 'text/event-stream' ) ) {
		response.body?.cancel();
		return {
			status: response.status,
			headers: response.headers,
			text: '',
			json: null,
			ms: Date.now() - started,
		};
	}

	const text = await response.text();
	let json = null;
	if ( type.includes( 'text/event-stream' ) ) {
		json =
			text
				.split( /\r?\n\r?\n/ )
				.map( ( event ) =>
					event
						.split( /\r?\n/ )
						.filter( ( line ) => line.startsWith( 'data:' ) )
						.map( ( line ) => line.slice( 5 ).trim() )
						.join( '\n' )
				)
				.filter( Boolean )
				.map( ( data ) => {
					try {
						return JSON.parse( data );
					} catch {
						return null;
					}
				} )
				.find(
					( message ) =>
						message && ( 'result' in message || 'error' in message )
				) || null;
	} else if ( text.trim() ) {
		try {
			json = JSON.parse( text );
		} catch {
			json = null;
		}
	}

	return {
		status: response.status,
		headers: response.headers,
		text,
		json,
		ms: Date.now() - started,
	};
}

/**
 * A JSON-RPC session as one client runs it.
 */
class Session {
	constructor( client, endpoint, auth, timeout ) {
		this.client = client;
		this.endpoint = endpoint;
		this.auth = auth;
		this.timeout = timeout;
		this.id = 0;
		this.sessionId = '';
		this.version = '';
	}

	headers( {
		session = this.client.echoesSession,
		accept = 'application/json, text/event-stream',
	} = {} ) {
		const headers = {
			Accept: accept,
			'Content-Type': 'application/json',
			'User-Agent': `saddle-client-matrix/1.0 (${ this.client.id })`,
		};
		if ( this.auth ) {
			headers.Authorization = this.auth;
		}
		if ( session && this.sessionId ) {
			headers[ 'Mcp-Session-Id' ] = this.sessionId;
		}
		if ( this.client.sendsVersionHeader && this.version ) {
			headers[ 'MCP-Protocol-Version' ] = this.version;
		}
		return headers;
	}

	async request( method, params, headerOptions = {} ) {
		const body = { jsonrpc: '2.0', id: ++this.id, method };
		if ( params ) {
			body.params = params;
		}
		return http(
			this.endpoint,
			{
				method: 'POST',
				headers: this.headers( headerOptions ),
				body: JSON.stringify( body ),
			},
			this.timeout
		);
	}

	async notify( method ) {
		return http(
			this.endpoint,
			{
				method: 'POST',
				headers: this.headers(),
				body: JSON.stringify( { jsonrpc: '2.0', method } ),
			},
			this.timeout
		);
	}

	async initialize( version = this.client.protocolVersion ) {
		const response = await this.request(
			'initialize',
			{
				protocolVersion: version,
				capabilities: {},
				clientInfo: this.client.clientInfo,
			},
			{ session: false }
		);
		this.sessionId = response.headers.get( 'mcp-session-id' ) || '';
		this.version = response.json?.result?.protocolVersion || '';
		return response;
	}

	async call( name, args = {} ) {
		return this.request( 'tools/call', { name, arguments: args } );
	}
}

/* ------------------------------------------------------------ checks */

/**
 * A step's outcome.
 *
 * @param {string}                      step
 * @param {'pass'|'fail'|'skip'|'note'} outcome
 * @param {string}                      detail
 */
function result( step, outcome, detail ) {
	return { step, outcome, detail };
}

/**
 * The text of a tools/call result, whatever its content shape.
 *
 * @param {Object} response A response from http().
 * @return {string} The text parts, joined.
 */
function textOf( response ) {
	const content = response.json?.result?.content;
	return Array.isArray( content )
		? content.map( ( part ) => part?.text || '' ).join( '\n' )
		: '';
}

/**
 * The sign-in path before any token: an unauthenticated initialize must
 * answer 401 with a Bearer challenge that names the protected-resource
 * document, which names the authorization server, which must offer dynamic
 * registration and S256.
 *
 * @param {Object} client A CLIENTS entry.
 * @param {Object} opts   The parsed options.
 * @return {Promise<Object[]>} The steps.
 */
async function discover( client, opts ) {
	const steps = [];
	const anonymous = new Session( client, opts.endpoint, '', opts.timeout );
	const first = await anonymous.initialize();
	const challenge = first.headers.get( 'www-authenticate' ) || '';

	if ( 401 !== first.status ) {
		steps.push(
			result(
				'discover',
				'fail',
				`An unauthenticated initialize answered ${ first.status }, not 401.`
			)
		);
		return steps;
	}
	const match = /resource_metadata="([^"]+)"/.exec( challenge );
	if ( ! /^Bearer\b/i.test( challenge ) || ! match ) {
		steps.push(
			result(
				'discover',
				'skip',
				'Sign-in for apps is off on this site (no Bearer challenge). Turn it on under Saddle → AI apps to test this path.'
			)
		);
		return steps;
	}

	const resource = await http(
		match[ 1 ],
		{ method: 'GET', headers: { Accept: 'application/json' } },
		opts.timeout
	);
	const issuer = resource.json?.authorization_servers?.[ 0 ];
	if ( 200 !== resource.status || ! issuer ) {
		steps.push(
			result(
				'discover',
				'fail',
				`The protected-resource document answered ${ resource.status } without an authorization server.`
			)
		);
		return steps;
	}

	// RFC 8414 puts the well-known segment before the issuer's path; some
	// clients also try it appended, and OIDC discovery last.
	const url = new URL( issuer );
	const path = url.pathname.replace( /\/+$/, '' );
	const candidates = [
		`${ url.origin }/.well-known/oauth-authorization-server${ path }`,
		`${ issuer.replace(
			/\/+$/,
			''
		) }/.well-known/oauth-authorization-server`,
		`${ url.origin }/.well-known/openid-configuration${ path }`,
	];
	let metadata = null;
	let found = '';
	for ( const candidate of candidates ) {
		const response = await http(
			candidate,
			{ method: 'GET', headers: { Accept: 'application/json' } },
			opts.timeout
		);
		if (
			200 === response.status &&
			response.json?.authorization_endpoint
		) {
			metadata = response.json;
			found = candidate;
			break;
		}
	}
	if ( ! metadata ) {
		steps.push(
			result(
				'discover',
				'fail',
				`No authorization-server metadata at ${ candidates[ 0 ] } or its fallbacks.`
			)
		);
		return steps;
	}

	const problems = [];
	if ( ! metadata.registration_endpoint ) {
		problems.push(
			'no registration_endpoint, so the app cannot register itself'
		);
	}
	if (
		! ( metadata.code_challenge_methods_supported || [] ).includes( 'S256' )
	) {
		problems.push( 'S256 is not offered' );
	}
	steps.push(
		problems.length
			? result( 'discover', 'fail', problems.join( '; ' ) + '.' )
			: result(
					'discover',
					'pass',
					`401 with a Bearer challenge; metadata at ${
						new URL( found ).pathname
					}; registration and S256 offered.`
			  )
	);
	return steps;
}

/**
 * The client's handshake and first calls, with one credential.
 *
 * @param {Object} client A CLIENTS entry.
 * @param {string} auth   The Authorization header value.
 * @param {Object} opts   The parsed options.
 * @return {Promise<Object[]>} The steps.
 */
async function handshake( client, auth, opts ) {
	const steps = [];
	const session = new Session( client, opts.endpoint, auth, opts.timeout );

	// 1. initialize.
	const init = await session.initialize();
	const negotiated = init.json?.result?.protocolVersion;
	if ( 200 !== init.status || ! init.json?.result ) {
		const location = init.headers.get( 'location' );
		const said = location
			? `redirected to ${ location }. Clients do not follow a redirect on POST; use the address it points to.`
			: init.json?.message ||
			  init.json?.error?.message ||
			  init.text.slice( 0, 160 );
		steps.push(
			result( 'initialize', 'fail', `HTTP ${ init.status }: ${ said }` )
		);
		return steps;
	}
	const capabilities = init.json.result.capabilities || {};
	const instructions = init.json.result.instructions || '';
	const problems = [];
	if ( negotiated !== client.protocolVersion ) {
		problems.push(
			`asked for ${ client.protocolVersion }, got ${ negotiated }`
		);
	}
	if ( ! capabilities.tools ) {
		problems.push( 'no tools capability' );
	}
	if ( ! instructions ) {
		problems.push( 'no instructions' );
	}
	steps.push(
		problems.length
			? result( 'initialize', 'fail', problems.join( '; ' ) + '.' )
			: result(
					'initialize',
					'pass',
					`${ negotiated }, ${
						session.sessionId ? 'session issued' : 'no session'
					}, ${ instructions.length } characters of instructions, ${
						init.ms
					} ms.`
			  )
	);

	// 2. notifications/initialized: 202 with no body, per the spec.
	const ack = await session.notify( 'notifications/initialized' );
	if ( 202 === ack.status ) {
		steps.push( result( 'initialized', 'pass', '202.' ) );
	} else if (
		200 === ack.status &&
		'' === ack.text.trim() &&
		client.strictNotification
	) {
		steps.push(
			result(
				'initialized',
				'fail',
				'200 with an empty body: this client fails to parse it (#155).'
			)
		);
	} else if ( ack.status >= 200 && ack.status < 300 ) {
		steps.push(
			result(
				'initialized',
				'note',
				`${ ack.status }, where the spec says 202.`
			)
		);
	} else {
		steps.push( result( 'initialized', 'fail', `HTTP ${ ack.status }.` ) );
	}

	// 3. The optional server-to-client stream: an event stream or 405.
	if ( client.opensStream ) {
		const stream = await http(
			opts.endpoint,
			{
				method: 'GET',
				headers: session.headers( { accept: 'text/event-stream' } ),
			},
			opts.timeout
		);
		const type = stream.headers.get( 'content-type' ) || '';
		steps.push(
			405 === stream.status ||
				( 200 === stream.status &&
					type.includes( 'text/event-stream' ) )
				? result(
						'stream',
						'pass',
						405 === stream.status
							? '405, so the client goes on without one.'
							: 'An event stream.'
				  )
				: result(
						'stream',
						'fail',
						`GET answered ${ stream.status } (${
							type || 'no type'
						}); the spec allows an event stream or 405.`
				  )
		);
	}

	// 4. tools/list.
	const list = await session.request( 'tools/list' );
	const tools = list.json?.result?.tools;
	if ( ! Array.isArray( tools ) ) {
		steps.push(
			result(
				'tools/list',
				'fail',
				`HTTP ${ list.status }: ${
					list.json?.error?.message || list.text.slice( 0, 160 )
				}`
			)
		);
		return steps;
	}
	const badNames = tools
		.filter( ( tool ) => ! TOOL_NAME.test( tool.name || '' ) )
		.map( ( tool ) => tool.name );
	const badSchemas = tools
		.filter(
			( tool ) =>
				'object' !== tool.inputSchema?.type ||
				[ 'anyOf', 'oneOf', 'allOf' ].some(
					( k ) => k in ( tool.inputSchema || {} )
				)
		)
		.map( ( tool ) => tool.name );
	const listProblems = [];
	if ( ! tools.length ) {
		listProblems.push( 'no tools' );
	}
	if ( badNames.length ) {
		listProblems.push(
			`names an API would refuse: ${ badNames.join( ', ' ) }`
		);
	}
	if ( badSchemas.length ) {
		listProblems.push(
			`input schemas that are not a plain object: ${ badSchemas.join(
				', '
			) }`
		);
	}
	steps.push(
		listProblems.length
			? result( 'tools/list', 'fail', listProblems.join( '; ' ) + '.' )
			: result(
					'tools/list',
					'pass',
					`${ tools.length } tools, ${ list.text.length } bytes.`
			  )
	);
	const offered = new Set( tools.map( ( tool ) => tool.name ) );

	// 5. ChatGPT's "Refresh actions": tools/list again, without the session.
	if ( client.refreshesWithoutSession ) {
		const refresh = await session.request( 'tools/list', null, {
			session: false,
		} );
		const again = refresh.json?.result?.tools;
		steps.push(
			Array.isArray( again ) && again.length === tools.length
				? result(
						'refresh',
						'pass',
						'tools/list without a session header lists the same tools.'
				  )
				: result(
						'refresh',
						'fail',
						`tools/list without a session header answered ${
							refresh.status
						}: ${
							refresh.json?.error?.message ||
							refresh.text.slice( 0, 160 )
						}`
				  )
		);
	}

	// 6. prompts/list, when the server offers prompts and the client shows them.
	if ( client.listsPrompts && capabilities.prompts ) {
		const prompts = await session.request( 'prompts/list' );
		steps.push(
			Array.isArray( prompts.json?.result?.prompts )
				? result(
						'prompts/list',
						'pass',
						`${ prompts.json.result.prompts.length } prompts.`
				  )
				: result(
						'prompts/list',
						'fail',
						`HTTP ${ prompts.status }: ${
							prompts.json?.error?.message ||
							prompts.text.slice( 0, 160 )
						}`
				  )
		);
	}

	// 7. A read call.
	const read = await session.call( READ_TOOL );
	let site = null;
	try {
		site = JSON.parse( textOf( read ) );
	} catch {
		site = null;
	}
	steps.push(
		false === read.json?.result?.isError && site
			? result(
					'read',
					'pass',
					`${ READ_TOOL }: "${
						site.name || site.site?.name || 'site'
					}".`
			  )
			: result(
					'read',
					'fail',
					`${ READ_TOOL }: ${
						textOf( read ) ||
						read.json?.error?.message ||
						`HTTP ${ read.status }`
					}`
			  )
	);

	// 8. A gated write: delete-post without a confirm token previews, or is
	// refused with a reason. Either way nothing changes.
	if ( opts.write ) {
		steps.push( await gatedWrite( session, offered ) );
	}

	// 9. Ending the session: anything but an error is fine.
	if ( client.deletesSession && session.sessionId ) {
		const closed = await http(
			opts.endpoint,
			{ method: 'DELETE', headers: session.headers() },
			opts.timeout
		);
		steps.push(
			[ 200, 202, 204, 405 ].includes( closed.status )
				? result(
						'close',
						'pass',
						`DELETE answered ${ closed.status }.`
				  )
				: result(
						'close',
						'fail',
						`DELETE answered ${ closed.status }.`
				  )
		);
	}

	return steps;
}

async function gatedWrite( session, offered ) {
	const list = await session.call( LIST_TOOL, { per_page: 1 } );
	let post = 0;
	try {
		const data = JSON.parse( textOf( list ) );
		post = ( data.posts || data.items || [] )[ 0 ]?.id || 0;
	} catch {
		post = 0;
	}
	if ( ! post ) {
		return result(
			'gated write',
			'skip',
			'No post to aim the preview at.'
		);
	}

	const response = await session.call( GATED_TOOL, { id: post } );
	const text = textOf( response );
	if (
		false === response.json?.result?.isError &&
		/confirm_token/.test( text )
	) {
		return result(
			'gated write',
			'pass',
			`${ GATED_TOOL } on post #${ post } previewed and asked for a confirm token. Nothing changed.`
		);
	}
	if (
		true === response.json?.result?.isError &&
		text &&
		! /^Permission denied\.?$/i.test( text.trim() )
	) {
		const hidden = offered.has( GATED_TOOL )
			? ''
			: ' (not offered at this access level, called by name)';
		return result(
			'gated write',
			'pass',
			`Refused with a reason${ hidden }: "${ text.slice( 0, 120 ) }"`
		);
	}
	return result(
		'gated write',
		'fail',
		`${ GATED_TOOL } answered neither a preview nor a refusal with a reason: ${
			text || response.json?.error?.message || `HTTP ${ response.status }`
		}`
	);
}

/**
 * initialize with every version Saddle knows, to show what each negotiates.
 *
 * @param {string} auth The Authorization header value.
 * @param {Object} opts The parsed options.
 * @return {Promise<Object[]>} One step per version.
 */
async function versions( auth, opts ) {
	const client = {
		...CLIENTS[ 0 ],
		id: 'versions',
		echoesSession: false,
		sendsVersionHeader: false,
	};
	const rows = [];
	for ( const version of VERSIONS ) {
		const session = new Session(
			client,
			opts.endpoint,
			auth,
			opts.timeout
		);
		const init = await session.initialize( version );
		const got = init.json?.result?.protocolVersion;
		if ( 200 !== init.status || ! got ) {
			rows.push( result( version, 'fail', `HTTP ${ init.status }.` ) );
		} else if ( got === version ) {
			rows.push( result( version, 'pass', `Negotiated ${ got }.` ) );
		} else {
			rows.push(
				result(
					version,
					'note',
					`Answered ${ got }; a client that speaks only ${ version } disconnects here.`
				)
			);
		}
	}
	return rows;
}

/* ------------------------------------------------------------ run */

async function main() {
	const opts = options();
	if ( opts.insecure ) {
		process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
	}

	const runs = [];
	for ( const client of opts.clients ) {
		for ( const path of client.paths ) {
			if ( opts.path && opts.path !== path ) {
				continue;
			}
			const run = {
				client: client.id,
				name: client.name,
				path,
				steps: [],
			};
			try {
				if ( 'key' === path ) {
					run.steps = opts.basic
						? await handshake( client, opts.basic, opts )
						: [
								result(
									'key',
									'skip',
									'No key given: pass --user and --key.'
								),
						  ];
				} else {
					run.steps = await discover( client, opts );
					run.steps.push(
						...( opts.bearer
							? await handshake( client, opts.bearer, opts )
							: [
									result(
										'token',
										'skip',
										'No sign-in token given: pass --token to run the calls.'
									),
							  ] )
					);
				}
			} catch ( error ) {
				run.steps.push(
					result(
						'request',
						'fail',
						error.name === 'TimeoutError'
							? `Timed out after ${ opts.timeout / 1000 } s.`
							: error.message
					)
				);
			}
			runs.push( run );
		}
	}

	const auth = opts.basic || opts.bearer;
	const negotiation = auth
		? await versions( auth, opts ).catch( ( error ) => [
				result( 'versions', 'fail', error.message ),
		  ] )
		: [];

	const failed = [
		...runs.flatMap( ( run ) => run.steps ),
		...negotiation,
	].some( ( step ) => 'fail' === step.outcome );
	const date = new Date().toISOString().slice( 0, 10 );

	if ( opts.json ) {
		process.stdout.write(
			JSON.stringify(
				{ date, endpoint: opts.endpoint, runs, versions: negotiation },
				null,
				2
			) + '\n'
		);
	} else if ( opts.markdown ) {
		process.stdout.write( markdown( date, runs, negotiation ) );
	} else {
		process.stdout.write(
			report( date, opts.endpoint, runs, negotiation )
		);
	}

	process.exit( failed ? 1 : 0 );
}

const MARK = { pass: 'PASS', fail: 'FAIL', skip: 'SKIP', note: 'NOTE' };

function report( date, endpoint, runs, negotiation ) {
	const lines = [ `Saddle client matrix, ${ date }`, endpoint, '' ];
	for ( const run of runs ) {
		lines.push( `${ run.name } (${ run.path })` );
		for ( const step of run.steps ) {
			lines.push(
				`  ${ MARK[ step.outcome ] }  ${ step.step.padEnd( 13 ) } ${
					step.detail
				}`
			);
		}
		lines.push( '' );
	}
	if ( negotiation.length ) {
		lines.push( 'Protocol versions' );
		for ( const step of negotiation ) {
			lines.push(
				`  ${ MARK[ step.outcome ] }  ${ step.step.padEnd( 13 ) } ${
					step.detail
				}`
			);
		}
		lines.push( '' );
	}
	return lines.join( '\n' );
}

function markdown( date, runs, negotiation ) {
	const cell = ( run ) => {
		const failed = run.steps
			.filter( ( s ) => 'fail' === s.outcome )
			.map( ( s ) => s.step );
		if ( failed.length ) {
			return `Fail: ${ failed.join( ', ' ) }`;
		}
		const skipped = run.steps
			.filter( ( s ) => 'skip' === s.outcome )
			.map( ( s ) => s.step );
		return skipped.length
			? `Pass, skipped ${ skipped.join( ', ' ) }`
			: 'Pass';
	};
	const rows = runs.map(
		( run ) =>
			`| ${ date } | ${ run.name } | ${ run.path } | ${ cell( run ) } | |`
	);
	const versionsRow = negotiation.length
		? [
				'',
				`Versions: ${ negotiation
					.map(
						( s ) =>
							`${ s.step } ${ MARK[ s.outcome ].toLowerCase() }`
					)
					.join( ', ' ) }.`,
		  ]
		: [];
	return [
		'| Date | Client | Path | Script result | Real client and version |',
		'|---|---|---|---|---|',
		...rows,
		...versionsRow,
		'',
	].join( '\n' );
}

main();
