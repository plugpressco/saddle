/**
 * The pure parts of onboarding (#277): which first-run step a reload resumes
 * at, what the waiting line says, which tasks the Dashboard's Setup block lists, how
 * the modules route (K2) is read, and the plain-words names for tool calls.
 *
 * No React and no network here, so each rule has a unit test. The components
 * (FirstRun, WaitingLine, SetupBlock) only draw what these return.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * First-run steps after the site read, in order. The site read itself
 * ("Saddle reads your site") is step 0 and is never stored: it is shown
 * again, all at once, on a reload.
 */
export const STEPS = [ 'app', 'connect', 'try', 'choose' ];

/**
 * The step a first-run screen opens at.
 *
 * A step that needs an app (connect, try, choose) falls back to picking one
 * when the app was not stored, so a half-saved state never strands the owner.
 *
 * @param {Object} firstRun `first_run` from GET /onboarding.
 * @return {string} `read` (the site read, then the tiles) or a stored step.
 */
export function resumeStep( firstRun ) {
	const step = firstRun && firstRun.step;
	if ( ! STEPS.includes( step ) ) {
		return 'read';
	}
	if ( 'app' !== step && ! firstRun.app ) {
		return 'app';
	}
	return step;
}

/**
 * The step the welcome opens at.
 *
 * A finished run (done or skipped) shows again only when asked for with
 * `&setup=1`. Settings → "Run setup again" sends just that, and it starts at
 * the top: the step a skipped run stopped at is not where the owner wants to
 * be. Only an address that names a step (Home's next step sends
 * `&step=try&app=…`) opens a finished run part way through.
 *
 * @param {Object} firstRun `first_run` from GET /onboarding, with the step
 *                          the address named, if any, already applied.
 * @param {string} search   The address's query string.
 * @return {string} `read` (the site read, then the tiles) or a stored step.
 */
export function openingStep( firstRun, search ) {
	const run = firstRun || {};
	const finished = 'done' === run.state || 'skipped' === run.state;
	const named = new URLSearchParams( search || '' ).get( 'step' );
	if ( finished && ! named ) {
		return 'read';
	}
	return resumeStep( run );
}

/**
 * Whether this page runs in WordPress Playground's in-browser preview, the
 * Live Preview on WordPress.org. The whole site runs inside the visitor's
 * browser there, so no AI app can ever reach it.
 *
 * @param {string} hostname `window.location.hostname`.
 * @return {boolean} True on playground.wordpress.net.
 */
export function isBrowserPreview( hostname ) {
	return (
		'playground.wordpress.net' === String( hostname || '' ).toLowerCase()
	);
}

/**
 * Where a reload on the connect step resumes.
 *
 * The connect step makes a key and waits for the app. Reloaded after the app
 * had connected, it started over: the wizard found the key it had just made,
 * said the app was "already connected" and offered "Replace its key
 * (recommended)", which would cut off the connection the owner had just set
 * up. A used key for the chosen app means the step is done.
 *
 * @param {string}   step    What resumeStep() chose.
 * @param {string}   app     The app the owner chose.
 * @param {Object[]} clients Keys from GET /clients.
 * @param {Function} appOf   Maps a key's label to an app key.
 * @return {string} The step to open.
 */
export function resumeConnect( step, app, clients, appOf ) {
	if ( 'connect' !== step || ! app ) {
		return step;
	}
	const used = ( clients || [] ).some(
		( c ) => c.last_used && appOf( c.label || c.name ) === app
	);
	return used ? stepAfter( 'connect' ) : step;
}

/**
 * @param {string} step A step from STEPS.
 * @return {string|null} The step after it, or null after the last.
 */
export function stepAfter( step ) {
	const i = STEPS.indexOf( step );
	return i >= 0 && i < STEPS.length - 1 ? STEPS[ i + 1 ] : null;
}

/**
 * First run is shown while it is unfinished, or when the owner asked for it
 * again with `&setup=1`.
 *
 * @param {Object}  firstRun `first_run` from GET /onboarding.
 * @param {boolean} forced   `?setup=1` is in the address.
 * @return {boolean} Whether to draw first run instead of the Dashboard.
 */
export function showFirstRun( firstRun, forced ) {
	if ( forced ) {
		return true;
	}
	const state = firstRun && firstRun.state;
	return 'new' === state || 'active' === state;
}

/**
 * The tour runs once per user, and only for an owner who finished first run
 * by choosing: an upgraded site (migrated to done) and a skipped run do not
 * get it.
 *
 * @param {Object} onboarding GET /onboarding.
 * @return {boolean} Whether the three-stop tour is due.
 */
export function tourDue( onboarding ) {
	if ( ! onboarding || ! onboarding.first_run ) {
		return false;
	}
	const { state, tier_choice: choice } = onboarding.first_run;
	return (
		'done' === state &&
		'' !== ( choice || '' ) &&
		! ( onboarding.user && onboarding.user.tour_done )
	);
}

/* ------------------------------------------------------- the waiting line */

export const POLL_START = 3000;
export const POLL_CAP = 30000;

/**
 * The next wait between polls: 3 s, growing by half each quiet poll up to
 * 30 s, and back to 3 s the moment something changes.
 *
 * @param {number}  previous Milliseconds waited last time (0 for the first).
 * @param {boolean} changed  Whether the last poll saw something new.
 * @return {number} Milliseconds to wait.
 */
export function nextDelay( previous, changed ) {
	if ( changed || ! previous ) {
		return POLL_START;
	}
	return Math.min( POLL_CAP, Math.round( previous * 1.5 ) );
}

/**
 * How the connect step's pulse reader treats its first answer.
 *
 * On the address path an earlier connection from the same app could match,
 * so the first answer is only a baseline. A key made on this screen has no
 * history: any row for it is the app connecting. Treating that first answer
 * as a baseline lost the connection whenever it landed before the first poll
 * (the tab was hidden when the key appeared, or the app was quicker than
 * the poll), and the screen said "Waiting" for an app that had connected.
 *
 * @param {string|null} keyId `key:<uuid>` when a key was made.
 * @return {{ignoreExisting: boolean}} Options for createPulse().
 */
export function connectPulseOptions( keyId ) {
	return { ignoreExisting: ! keyId };
}

/**
 * The finest true thing to say while waiting for an app to connect.
 *
 * `rows` are connections the pulse returned since the screen opened, so any
 * matching row is a real request from the app. A connection made for another
 * app (a different app key) does not count. On the key path only the key this
 * screen made counts.
 *
 * @param {Object}      args
 * @param {Object[]}    args.rows     `connections` from the pulse, merged.
 * @param {Object[]}    args.pending  `pending` from the pulse.
 * @param {string}      args.app      The app key the owner chose.
 * @param {string}      args.appLabel Its name, for the sentence.
 * @param {string|null} args.keyId    `key:<uuid>` when a key was made.
 * @return {{phase: string, text: string, connection: (Object|null)}} Phase is
 *         `waiting`, `asked` or `done`.
 */
export function connectStatus( { rows, pending, app, appLabel, keyId } ) {
	const fits = ( appKey ) => ! appKey || ! app || appKey === app;

	const match = ( rows || [] ).find( ( row ) => {
		if ( keyId ) {
			return row.id === keyId;
		}
		return 'oauth' === row.kind && fits( row.app );
	} );
	if ( match ) {
		const client = match.client || match.name || '';
		return {
			phase: 'done',
			connection: match,
			text: client
				? sprintf(
						/* translators: 1: app name, 2: what the app calls itself, e.g. claude-ai 0.1.0. */
						__( '%1$s connected (%2$s)', 'saddle' ),
						appLabel,
						client
				  )
				: sprintf(
						/* translators: %s: app name. */
						__( '%s connected', 'saddle' ),
						appLabel
				  ),
		};
	}

	const asked = ( pending || [] ).find( ( p ) => fits( p.app ) );
	if ( asked && ! keyId ) {
		return {
			phase: 'asked',
			connection: null,
			text: sprintf(
				/* translators: %s: app name. */
				__(
					'%s asked to connect. Approve it on the screen that opened.',
					'saddle'
				),
				appLabel
			),
		};
	}

	return {
		phase: 'waiting',
		connection: null,
		text: sprintf(
			/* translators: %s: app name. */
			__( 'Waiting for %s…', 'saddle' ),
			appLabel
		),
	};
}

/* ---------------------------------------------------------- try-it lines */

// Each phrase is a whole sentence with the app as its subject, so translators
// see it whole. Keys are tool names without the `saddle/` or `saddle-` prefix.
const PHRASES = {
	'get-site-info': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s read the site summary', 'saddle' ), app ),
	'context-bundle': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s read the site overview', 'saddle' ), app ),
	'get-instructions': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s read your instructions', 'saddle' ), app ),
	'list-media': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed media', 'saddle' ), app ),
	'get-media': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s looked at a media item', 'saddle' ), app ),
	'list-posts': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed posts', 'saddle' ), app ),
	'list-pages': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed pages', 'saddle' ), app ),
	'get-post': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s read a post', 'saddle' ), app ),
	'get-page': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s read a page', 'saddle' ), app ),
	'search-content': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s searched your content', 'saddle' ), app ),
	'list-categories': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed categories', 'saddle' ), app ),
	'list-tags': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed tags', 'saddle' ), app ),
	'list-users': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed users', 'saddle' ), app ),
	'list-plugins': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed plugins', 'saddle' ), app ),
	'list-themes': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed themes', 'saddle' ), app ),
	'get-blocks': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s read a page’s blocks', 'saddle' ), app ),
	'lint-page': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s checked a page’s design', 'saddle' ), app ),
	'list-skills': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s looked at your skills', 'saddle' ), app ),
	recall: ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s read what it remembers', 'saddle' ), app ),
	'get-option': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s read a setting', 'saddle' ), app ),
	'list-modules': ( app ) =>
		/* translators: %s: app name. */
		sprintf( __( '%s listed your Saddle modules', 'saddle' ), app ),
};

/**
 * A tool name without its `saddle/` or `saddle-` prefix.
 *
 * @param {string} tool A tool or ability name, e.g. `saddle/list-media`.
 * @return {string} `list-media`.
 */
export function bareToolName( tool ) {
	return String( tool || '' ).replace( /^saddle[/-]/, '' );
}

/**
 * One tool call in plain words: "Claude listed media". Anything not in the
 * table falls back to the tool's own name.
 *
 * @param {string} tool     Tool name.
 * @param {string} appLabel The app's name.
 * @return {string} A sentence.
 */
export function toolPhrase( tool, appLabel ) {
	const name = bareToolName( tool );
	if ( Object.prototype.hasOwnProperty.call( PHRASES, name ) ) {
		return PHRASES[ name ]( appLabel );
	}
	return sprintf(
		/* translators: 1: app name, 2: a tool name such as list-menus. */
		__( '%1$s used %2$s', 'saddle' ),
		appLabel,
		name
	);
}

/**
 * The state of the try-it step, from the connection the pulse returned.
 *
 * A tool call is what counts: `first_tool_at` is stamped on the first
 * successful `tools/call`, never on the handshake. `recent_tools` is newest
 * first, so the lines are shown oldest first, the order they happened in.
 *
 * @param {Object}   args
 * @param {Object[]} args.rows     `connections` from the pulse, merged.
 * @param {string}   args.app      The app key the owner chose.
 * @param {string}   args.appLabel Its name.
 * @return {{phase: string, text: string, items: string[]}} Phase is
 *         `waiting` or `done`; `items` are the calls seen so far.
 */
export function tryStatus( { rows, app, appLabel } ) {
	const used = ( rows || [] )
		.filter(
			( row ) =>
				row.first_tool_at > 0 &&
				( ! row.app || ! app || row.app === app )
		)
		.sort( ( a, b ) => b.last_tool_at - a.last_tool_at )[ 0 ];

	if ( ! used ) {
		return {
			phase: 'waiting',
			items: [],
			text: sprintf(
				/* translators: %s: app name. */
				__( 'Waiting for %s to use Saddle…', 'saddle' ),
				appLabel
			),
		};
	}

	const tools = ( used.recent_tools || [] ).slice().reverse();
	return {
		phase: 'done',
		items: tools.map( ( tool ) => toolPhrase( tool, appLabel ) ),
		text: __( 'It works.', 'saddle' ),
		// The connection that made the call: first run sets its access.
		connection: used.id || '',
	};
}

/**
 * What to try if the first tool call does not arrive: one tip per app.
 *
 * @param {string} app      App key.
 * @param {string} appLabel App name.
 * @return {string} A sentence to say after the wait runs out.
 */
export function timeoutTip( app, appLabel ) {
	switch ( app ) {
		case 'claude':
			return __(
				'In Claude, make sure Saddle is switched on in the chat’s tools menu, then send the message again.',
				'saddle'
			);
		case 'chatgpt':
			return __(
				'In ChatGPT, pick the Saddle app in the + menu before you send the message.',
				'saddle'
			);
		case 'claude-code':
			return __(
				'In Claude Code, run /mcp and check the Saddle server says connected, then ask again.',
				'saddle'
			);
		case 'cursor':
			return __(
				'In Cursor, open Settings → MCP and check the Saddle server has a green dot, then ask in a new chat.',
				'saddle'
			);
		case 'codex':
			return __(
				'In Codex, start a new thread after the login so it loads the Saddle tools, then ask again.',
				'saddle'
			);
		default:
			return sprintf(
				/* translators: %s: app name. */
				__(
					'Check that Saddle is switched on in %s, then send the message again in a new chat.',
					'saddle'
				),
				appLabel
			);
	}
}

/**
 * A read-only first prompt from what step 0 found. Every branch ends by
 * saying not to change anything: this step is proof, not work.
 *
 * @param {Object|null} look GET /first-look, or null when it failed.
 * @return {string} The prompt.
 */
export function tryPrompt( look ) {
	const findings = ( look && look.findings ) || {};
	if ( findings.missing_alt > 0 ) {
		return __(
			'Tell me about this WordPress site, then list five images that have no alt text. Don’t change anything.',
			'saddle'
		);
	}
	if ( findings.missing_description > 0 ) {
		return __(
			'Tell me about this WordPress site, then list five pages or posts that have no search description. Don’t change anything.',
			'saddle'
		);
	}
	return __(
		'Tell me about this WordPress site, then list my five newest posts. Don’t change anything.',
		'saddle'
	);
}

/**
 * The checks shown when a connection has not arrived after two minutes.
 * `ok` is true (fine), false (a likely cause) or null (nothing to say).
 *
 * @param {Object}  args
 * @param {boolean} args.ssl        The site is served over HTTPS.
 * @param {boolean} args.permalinks Pretty permalinks are on.
 * @param {boolean} args.local      The address looks like a local site.
 * @param {string}  args.app        The app key.
 * @param {string}  args.authHeader `ok`, or a stripped-header status.
 * @return {Object[]} `{ key, ok, label, hint }` rows, in reading order.
 */
export function selfCheckFindings( {
	ssl,
	permalinks,
	local,
	app,
	authHeader,
} ) {
	const rows = [
		{
			key: 'https',
			ok: !! ssl,
			label: __( 'The site uses HTTPS', 'saddle' ),
			hint: ssl
				? ''
				: __(
						'Apps that sign in by address need HTTPS. Use a key instead, or move the site to HTTPS.',
						'saddle'
				  ),
		},
		{
			key: 'permalinks',
			ok: !! permalinks,
			label: __( 'Pretty permalinks are on', 'saddle' ),
			hint: permalinks
				? ''
				: __(
						'Go to Settings → Permalinks and choose anything other than Plain.',
						'saddle'
				  ),
		},
	];

	if ( authHeader && 'unknown' !== authHeader ) {
		const fine =
			'ok' === authHeader || 'nonce_header_stripped' === authHeader;
		rows.push( {
			key: 'header',
			ok: fine,
			label: __( 'Sign-in details reach WordPress', 'saddle' ),
			hint: fine
				? ''
				: __(
						'Your server removes the Authorization header before WordPress sees it. There is a one-click fix below.',
						'saddle'
				  ),
		} );
	}

	if ( local ) {
		rows.push( {
			key: 'local',
			ok: false,
			label: __( 'This is a local site', 'saddle' ),
			hint: __(
				'Apps that run on the web, like claude.ai and ChatGPT, cannot reach a site on your own computer. Use an app on this same computer, or a key.',
				'saddle'
			),
		} );
	}

	if ( 'chatgpt' === app ) {
		rows.push( {
			key: 'plan',
			ok: null,
			label: __( 'Your ChatGPT plan', 'saddle' ),
			hint: __(
				'Custom connectors that can change things have been limited to Business, Enterprise and Edu workspaces. Reading works on more plans.',
				'saddle'
			),
		} );
	}

	rows.push( {
		key: 'firewall',
		ok: null,
		label: __( 'A firewall may be in the way', 'saddle' ),
		hint: __(
			'A security plugin or your host’s firewall can block the app. Ask your host to allow requests to this site’s /wp-json/saddle/ address.',
			'saddle'
		),
	} );

	return rows;
}

/* ------------------------------------------------- modules (K2) and Setup */

/**
 * The areas from `GET /modules`, or none when the route is absent or its
 * answer is not the shape K2 promises.
 *
 * @param {Object|null} res Response body.
 * @return {Object[]} Areas.
 */
export function parseModules( res ) {
	if ( ! res || ! Array.isArray( res.areas ) ) {
		return [];
	}
	return res.areas.filter( ( a ) => a && 'string' === typeof a.key );
}

/**
 * Each module's unfinished setup tasks, ready to list.
 *
 * A task that waits on another (`after`) is shown only once that one is done.
 *
 * @param {Object[]} areas From parseModules().
 * @return {Object[]} `{ module, product, task }` rows, in menu order.
 */
export function moduleTasks( areas ) {
	const rows = [];
	( areas || [] ).forEach( ( area ) => {
		const setup = area.setup;
		if ( ! setup || ! Array.isArray( setup.tasks ) ) {
			return;
		}
		const done = new Set(
			setup.tasks.filter( ( t ) => t.done ).map( ( t ) => t.id )
		);
		setup.tasks.forEach( ( task ) => {
			if ( task.done || ( task.after && ! done.has( task.after ) ) ) {
				return;
			}
			rows.push( {
				module: area.key,
				product: area.product || area.title || area.key,
				task,
			} );
		} );
	} );
	return rows;
}

/**
 * The three Core setup tasks, derived and never stored.
 *
 * - Connect an app: any connection exists.
 * - Try a first prompt: any connection has made a tool call.
 * - Choose what it can do: the owner chose in first run, the tier is above
 *   read, or first run finished without a choice on record (an upgraded site
 *   that was migrated to done).
 *
 * @param {Object}   args
 * @param {Object[]} args.connections `connections` from GET /connections.
 * @param {Object}   args.firstRun    `first_run` from GET /onboarding.
 * @param {string}   args.tier        The site's tier.
 * @return {Object[]} `{ id, title, done }` in order.
 */
export function coreTasks( { connections, firstRun, tier } ) {
	const list = connections || [];
	const run = firstRun || {};
	const chose =
		'' !== ( run.tier_choice || '' ) ||
		'read' !== tier ||
		// Access is per app now (#285): any app above Read only is a choice.
		list.some( ( c ) => c.role && 'read' !== c.role ) ||
		( 'done' === run.state && '' === ( run.tier_choice || '' ) );

	return [
		{
			id: 'connect',
			title: __( 'Connect an app', 'saddle' ),
			done: list.length > 0,
		},
		{
			id: 'try',
			title: __( 'Try a first prompt', 'saddle' ),
			done: list.some( ( c ) => c.first_tool_at > 0 ),
		},
		{
			id: 'choose',
			title: __( 'Choose what it can do', 'saddle' ),
			done: chose,
		},
	];
}

/**
 * Everything the Dashboard's Setup block lists, and whether it should show at all.
 *
 * @param {Object}   args
 * @param {Object[]} args.connections From GET /connections.
 * @param {Object}   args.onboarding  GET /onboarding.
 * @param {string}   args.tier        The site's tier.
 * @param {Object[]} args.areas       From parseModules().
 * @return {{core: Object[], modules: Object[], done: number, total: number,
 *         visible: boolean}} `visible` is false once everything is done or
 *         the owner hid the block.
 */
export function setupBlock( { connections, onboarding, tier, areas } ) {
	const firstRun = ( onboarding && onboarding.first_run ) || {};
	const core = coreTasks( { connections, firstRun, tier } );
	const modules = moduleTasks( areas );
	const coreDone = core.filter( ( t ) => t.done ).length;
	const modulesLeft = modules.length;
	const withSetup = ( areas || [] ).filter( ( a ) => a.setup );
	const modulesDone = withSetup.reduce( ( sum, a ) => sum + a.setup.done, 0 );
	const modulesTotal = withSetup.reduce(
		( sum, a ) => sum + a.setup.total,
		0
	);

	const hidden =
		onboarding &&
		onboarding.modules &&
		onboarding.modules.home &&
		onboarding.modules.home.setup_hidden_at > 0;
	const everything = coreDone === core.length && 0 === modulesLeft;

	return {
		core,
		modules,
		done: coreDone + modulesDone,
		total: core.length + modulesTotal,
		visible: ! hidden && ! everything,
	};
}

/**
 * The one next step Home suggests (#309), instead of the whole Setup list:
 * the first unfinished Core task, else the first unfinished module task.
 *
 * A Core step names the app it is about: for "try", an app that has not used
 * Saddle yet; for "choose", an app that is still Read only; else the first.
 *
 * @param {Object}   block       From setupBlock().
 * @param {Object[]} connections From GET /connections.
 * @return {Object|null} `{ id, app }` for a Core task (`connect`, `try`,
 *         `choose`), `{ id: 'module', module, product, task }` for a module
 *         task, or null when there is nothing to suggest.
 */
export function nextStep( block, connections ) {
	if ( ! block || ! block.visible ) {
		return null;
	}
	const core = ( block.core || [] ).find( ( t ) => ! t.done );
	if ( core ) {
		const list = ( connections || [] ).filter( ( c ) => c.app );
		const pick = {
			try: list.find( ( c ) => ! c.first_tool_at ),
			choose: list.find( ( c ) => ! c.role || 'read' === c.role ),
		}[ core.id ];
		return { id: core.id, app: ( pick || list[ 0 ] || {} ).app || '' };
	}
	const mod = ( block.modules || [] )[ 0 ];
	return mod ? { id: 'module', ...mod } : null;
}
