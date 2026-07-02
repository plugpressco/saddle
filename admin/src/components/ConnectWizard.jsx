/**
 * Connect wizard — one step at a time, one thing to do per step.
 *
 * Pick your app → copy one ready-made setup → watch it connect, live.
 *
 * The credential is created server-side the moment an app is picked (core
 * Application Passwords, no Authorize-screen round-trip, secret never in a
 * URL) and dropped straight into the app's config, so there is no password to
 * save, carry, or lose. If the user backs out before copying anything, the
 * just-created credential is quietly revoked — no orphan keys.
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData, levelFor } from '../api';
import ConnectionHealth from './ConnectionHealth';
import { AppLogo } from './icons';

const MCP_URL = saddleData.mcpUrl || '';
const USER = saddleData.user || '';
const WHITESPACE = /\s/g;
const IS_LOCAL = /(?:localhost|127\.0\.0\.1|\.test|\.local)(?::|\/|$)/i.test(
	MCP_URL
);

// How long we listen before offering troubleshooting, in seconds.
const PATIENCE = 45;

const APPS = [
	{
		key: 'claude-code',
		label: __( 'Claude Code', 'saddle' ),
		kind: __( 'Terminal', 'saddle' ),
		how: __(
			'Paste this into your terminal and press Enter. That’s the whole setup.',
			'saddle'
		),
		next: __(
			'Run claude in any folder and ask it about your site.',
			'saddle'
		),
	},
	{
		key: 'claude-desktop',
		label: __( 'Claude Desktop', 'saddle' ),
		kind: __( 'Desktop app', 'saddle' ),
		how: __(
			'In Claude Desktop: Settings → Developer → Edit Config. Paste this inside, save, and restart the app.',
			'saddle'
		),
		next: __(
			'Restart Claude Desktop, then ask it about your site.',
			'saddle'
		),
	},
	{
		key: 'cursor',
		label: __( 'Cursor', 'saddle' ),
		kind: __( 'Code editor', 'saddle' ),
		how: __(
			'In Cursor: Settings → MCP → Add new server. Paste this (or save it as .cursor/mcp.json).',
			'saddle'
		),
		next: __( 'Open Cursor’s chat and ask it about your site.', 'saddle' ),
	},
	{
		key: 'vscode',
		label: __( 'VS Code', 'saddle' ),
		kind: __( 'Code editor', 'saddle' ),
		how: __(
			'Create a file named .vscode/mcp.json in your project and paste this in.',
			'saddle'
		),
		next: __(
			'Open Copilot Chat in agent mode and ask it about your site.',
			'saddle'
		),
	},
	{
		key: 'codex',
		label: __( 'Codex', 'saddle' ),
		kind: __( 'Terminal', 'saddle' ),
		how: __(
			'Open the file ~/.codex/config.toml and paste this at the bottom.',
			'saddle'
		),
		next: __( 'Run codex and ask it about your site.', 'saddle' ),
	},
	{
		key: 'antigravity',
		label: __( 'Antigravity', 'saddle' ),
		kind: __( 'Code editor', 'saddle' ),
		how: __(
			'In Antigravity, open MCP settings, choose “Add server”, and paste this.',
			'saddle'
		),
		next: __(
			'Open Antigravity’s agent panel and ask it about your site.',
			'saddle'
		),
	},
	{
		key: 'other',
		label: __( 'Another app', 'saddle' ),
		kind: __( 'Anything MCP', 'saddle' ),
		how: __(
			'Most AI apps accept this standard setup — look for “Add MCP server” in their settings and paste it there.',
			'saddle'
		),
		next: __( 'Open your app and ask it about your site.', 'saddle' ),
	},
];

// Build the copy-pasteable setup for the given app, credentials filled in.
function buildConfig( app, password ) {
	const auth = btoa( `${ USER }:${ password.replace( WHITESPACE, '' ) }` );
	const header = `Authorization: Basic ${ auth }`;

	switch ( app ) {
		// One CLI command, native HTTP transport.
		case 'claude-code':
			return `claude mcp add saddle --transport http ${ MCP_URL } \\\n  --header "${ header }"`;

		// TOML, bridged through mcp-remote.
		case 'codex':
			return [
				'[mcp_servers.saddle]',
				'command = "npx"',
				`args = ["-y", "mcp-remote", "${ MCP_URL }", "--header", "${ header }"]`,
			].join( '\n' );

		// Native HTTP with headers.
		case 'cursor':
		case 'other':
			return JSON.stringify(
				{
					mcpServers: {
						saddle: {
							url: MCP_URL,
							headers: { Authorization: `Basic ${ auth }` },
						},
					},
				},
				null,
				2
			);

		// Native HTTP, `servers` shape.
		case 'vscode':
			return JSON.stringify(
				{
					servers: {
						saddle: {
							type: 'http',
							url: MCP_URL,
							headers: { Authorization: `Basic ${ auth }` },
						},
					},
				},
				null,
				2
			);

		// Claude Desktop & Antigravity — stdio via mcp-remote.
		default:
			return JSON.stringify(
				{
					mcpServers: {
						saddle: {
							command: 'npx',
							args: [
								'-y',
								'mcp-remote',
								MCP_URL,
								'--header',
								header,
							],
						},
					},
				},
				null,
				2
			);
	}
}

const STEPS = [
	__( 'Choose app', 'saddle' ),
	__( 'Paste setup', 'saddle' ),
	__( 'Say hello', 'saddle' ),
];

function Progress( { current } ) {
	return (
		<ol
			className="saddle-wizard__progress"
			aria-label={ __( 'Setup progress', 'saddle' ) }
		>
			{ STEPS.map( ( label, i ) => {
				let state = 'todo';
				if ( i < current ) {
					state = 'done';
				} else if ( i === current ) {
					state = 'now';
				}
				return (
					<li key={ label } className={ `is-${ state }` }>
						<span className="saddle-wizard__dot" aria-hidden="true">
							{ i < current ? '✓' : i + 1 }
						</span>
						{ label }
					</li>
				);
			} ) }
		</ol>
	);
}

/**
 * The example first message. Kept short so it fits one line in most apps.
 */
const HELLO_PROMPT = __( 'What can you see on my WordPress site?', 'saddle' );

export default function ConnectWizard( { tier, onExit, onClientsChanged } ) {
	const [ step, setStep ] = useState( 0 ); // 0 pick, 1 setup, 2 hello, 3 done
	const [ app, setApp ] = useState( null );
	const [ creating, setCreating ] = useState( null ); // app key mid-create
	const [ cred, setCred ] = useState( null ); // { uuid, password, label }
	const [ error, setError ] = useState( null );
	const [ copied, setCopied ] = useState( null ); // 'config' | 'prompt'
	const [ everCopied, setEverCopied ] = useState( false );
	const [ patienceUp, setPatienceUp ] = useState( false );
	const level = levelFor( tier );

	const activeApp = APPS.find( ( a ) => a.key === app );

	/* ----- create the credential the moment an app is picked ----- */

	const pick = ( key ) => {
		const chosen = APPS.find( ( a ) => a.key === key );
		setCreating( key );
		setError( null );
		api( 'clients', {
			method: 'POST',
			data: { name: chosen.label },
		} )
			.then( ( res ) => {
				setApp( key );
				setCred( res );
				setStep( 1 );
				if ( onClientsChanged ) {
					onClientsChanged();
				}
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setCreating( null ) );
	};

	/* ----- leaving: never strand an orphan credential ----- */

	const discardIfUntouched = useCallback( () => {
		// Nothing was copied anywhere, so the credential can't be in use —
		// remove it rather than leaving a mystery key on the account.
		if ( cred && ! everCopied ) {
			api( `clients/${ cred.uuid }`, { method: 'DELETE' } )
				.then( () => onClientsChanged && onClientsChanged() )
				.catch( () => {} );
			return true;
		}
		return false;
	}, [ cred, everCopied, onClientsChanged ] );

	const exit = ( connected ) => {
		const discarded = discardIfUntouched();
		onExit( { connected: !! connected, kept: !! cred && ! discarded } );
	};

	const backToPick = () => {
		discardIfUntouched();
		setCred( null );
		setApp( null );
		setEverCopied( false );
		setStep( 0 );
	};

	/* ----- live listening: flip to done on the app's first request ----- */

	const pollRef = useRef( null );
	useEffect( () => {
		if ( ! cred || step === 0 || step === 3 ) {
			return undefined;
		}
		pollRef.current = window.setInterval( () => {
			api( 'clients' )
				.then( ( res ) => {
					const me = ( res.clients || [] ).find(
						( c ) => c.uuid === cred.uuid
					);
					if ( me && me.last_used ) {
						setStep( 3 );
						if ( onClientsChanged ) {
							onClientsChanged();
						}
					}
				} )
				.catch( () => {} );
		}, 3000 );
		return () => window.clearInterval( pollRef.current );
	}, [ cred, step, onClientsChanged ] );

	// Offer troubleshooting once the wait step has been up a while.
	useEffect( () => {
		if ( step !== 2 ) {
			return undefined;
		}
		setPatienceUp( false );
		const t = window.setTimeout(
			() => setPatienceUp( true ),
			PATIENCE * 1000
		);
		return () => window.clearTimeout( t );
	}, [ step ] );

	/* ----- clipboard ----- */

	const copy = ( what, text ) => {
		if ( window.navigator && window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( text );
		}
		setCopied( what );
		setEverCopied( true );
		window.setTimeout( () => setCopied( null ), 1600 );
	};

	const config = cred ? buildConfig( app, cred.password ) : '';

	return (
		<div className="saddle-wizard">
			<div className="saddle-wizard__top">
				<Progress current={ step } />
				{ step < 3 && (
					<Button
						variant="tertiary"
						className="saddle-wizard__cancel"
						onClick={ () => exit( false ) }
					>
						{ __( 'Cancel', 'saddle' ) }
					</Button>
				) }
			</div>

			{ error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			{ /* ---------- Step 1: choose the app ---------- */ }
			{ step === 0 && (
				<div className="saddle-wizard__step" key="pick">
					<h2 className="saddle-wizard__title">
						{ __( 'Which app are you connecting?', 'saddle' ) }
					</h2>
					<p className="saddle-wizard__lead">
						{ __(
							'When you pick an app, WordPress creates a sign-in key just for it and Saddle prepares the whole setup. If you leave before using the key, it’s removed automatically — nothing is left behind.',
							'saddle'
						) }
					</p>

					{ ! saddleData.appPasswords && (
						<Notice status="warning" isDismissible={ false }>
							{ saddleData.ssl
								? __(
										'Application Passwords appear to be turned off — often by a security plugin. Enable them under Users → Profile before connecting.',
										'saddle'
								  )
								: __(
										'WordPress turns off app connections on sites that aren’t served over HTTPS (like http://localhost), so this won’t work until then.',
										'saddle'
								  ) }
						</Notice>
					) }

					<div className="saddle-wizard__apps">
						{ APPS.map( ( a ) => (
							<button
								key={ a.key }
								type="button"
								className="saddle-appcard"
								disabled={ !! creating }
								onClick={ () => pick( a.key ) }
							>
								<span
									className="saddle-appcard__mark"
									aria-hidden="true"
								>
									{ creating === a.key ? (
										<Spinner />
									) : (
										<AppLogo app={ a.key } />
									) }
								</span>
								<span className="saddle-appcard__label">
									{ a.label }
								</span>
								<span className="saddle-appcard__kind">
									{ a.kind }
								</span>
							</button>
						) ) }
					</div>
				</div>
			) }

			{ /* ---------- Step 2: paste one thing ---------- */ }
			{ step === 1 && activeApp && cred && (
				<div className="saddle-wizard__step" key="setup">
					<h2 className="saddle-wizard__title">
						{ sprintf(
							/* translators: %s: the app name. */
							__( 'Set up %s', 'saddle' ),
							activeApp.label
						) }
					</h2>
					<p className="saddle-wizard__lead">{ activeApp.how }</p>

					<div className="saddle-wizard__config">
						<div className="saddle-wizard__configbar">
							<span>
								{ sprintf(
									/* translators: %s: the connection label. */
									__( 'Made for “%s” just now', 'saddle' ),
									cred.label
								) }
							</span>
							<Button
								variant="primary"
								onClick={ () => copy( 'config', config ) }
							>
								{ copied === 'config'
									? __( 'Copied ✓', 'saddle' )
									: __( 'Copy setup', 'saddle' ) }
							</Button>
						</div>
						<pre className="saddle-code saddle-code--dark">
							{ config }
						</pre>
					</div>

					<div className="saddle-wizard__cando">
						<span className="saddle-wizard__cando-label">
							{ sprintf(
								/* translators: %s: the app name. */
								__( 'What %s will be able to do', 'saddle' ),
								activeApp.label
							) }
						</span>
						<p>{ level.one }</p>
						<p className="saddle-wizard__cando-note">
							{ __(
								'Its sign-in key works only for this app, only on this site, and only through Saddle — it can’t touch the rest of WordPress. Disconnect it anytime and access ends instantly. Go back without copying and the key is discarded.',
								'saddle'
							) }
						</p>
					</div>

					{ IS_LOCAL && (
						<p className="saddle-wizard__hint">
							{ __(
								'This site runs on a local address, so the app must run on this same computer to reach it.',
								'saddle'
							) }
						</p>
					) }

					<div className="saddle-wizard__actions">
						<Button variant="tertiary" onClick={ backToPick }>
							{ __( 'Back', 'saddle' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => setStep( 2 ) }
							disabled={ ! everCopied }
						>
							{ __( 'I’ve pasted it', 'saddle' ) }
						</Button>
					</div>
				</div>
			) }

			{ /* ---------- Step 3: say hello (live) ---------- */ }
			{ step === 2 && activeApp && (
				<div className="saddle-wizard__step" key="hello">
					<h2 className="saddle-wizard__title">
						{ sprintf(
							/* translators: %s: the app name. */
							__( 'Now say hello from %s', 'saddle' ),
							activeApp.label
						) }
					</h2>
					<p className="saddle-wizard__lead">{ activeApp.next }</p>

					<button
						type="button"
						className="saddle-wizard__prompt"
						onClick={ () => copy( 'prompt', HELLO_PROMPT ) }
						title={ __( 'Click to copy', 'saddle' ) }
					>
						<span>“{ HELLO_PROMPT }”</span>
						<span className="saddle-wizard__prompt-copy">
							{ copied === 'prompt'
								? __( 'Copied ✓', 'saddle' )
								: __( 'Copy', 'saddle' ) }
						</span>
					</button>

					<div
						className="saddle-wizard__listening"
						role="status"
						aria-live="polite"
					>
						<span
							className="saddle-wizard__pulse"
							aria-hidden="true"
						/>
						{ sprintf(
							/* translators: %s: the app name. */
							__(
								'Listening for %s — this updates by itself the moment it connects.',
								'saddle'
							),
							activeApp.label
						) }
					</div>

					{ patienceUp && (
						<div className="saddle-wizard__trouble">
							<h3>
								{ __(
									'Taking longer than expected?',
									'saddle'
								) }
							</h3>
							<ul>
								<li>
									{ sprintf(
										/* translators: %s: the app name. */
										__(
											'Make sure you saved the setup and %s was restarted or reloaded after pasting.',
											'saddle'
										),
										activeApp.label
									) }
								</li>
								<li>
									{ __(
										'The app only connects when it’s actually used — ask it something about your site.',
										'saddle'
									) }
								</li>
								{ IS_LOCAL && (
									<li>
										{ __(
											'This is a local site — the app must run on this same computer.',
											'saddle'
										) }
									</li>
								) }
							</ul>
							<ConnectionHealth />
							<Button
								variant="tertiary"
								onClick={ () => setStep( 1 ) }
							>
								{ __( 'Show the setup again', 'saddle' ) }
							</Button>
						</div>
					) }

					<div className="saddle-wizard__actions">
						<Button
							variant="tertiary"
							onClick={ () => setStep( 1 ) }
						>
							{ __( 'Back', 'saddle' ) }
						</Button>
						<Button variant="link" onClick={ () => exit( false ) }>
							{ __(
								'Finish later — it’ll connect on first use',
								'saddle'
							) }
						</Button>
					</div>
				</div>
			) }

			{ /* ---------- Done ---------- */ }
			{ step === 3 && activeApp && (
				<div
					className="saddle-wizard__step saddle-wizard__step--done"
					key="done"
				>
					<span className="saddle-wizard__check" aria-hidden="true">
						<svg viewBox="0 0 52 52">
							<circle cx="26" cy="26" r="24" fill="none" />
							<path fill="none" d="M15 27l7.5 7.5L37 19" />
						</svg>
					</span>
					<h2 className="saddle-wizard__title">
						{ sprintf(
							/* translators: %s: the app name. */
							__( '%s is connected', 'saddle' ),
							activeApp.label
						) }
					</h2>
					<p className="saddle-wizard__lead">
						{ sprintf(
							/* translators: %s: the human-readable access level. */
							__(
								'It just made its first request. Right now it can %s — change that anytime in Permissions.',
								'saddle'
							),
							level.key === 'read'
								? __( 'only read your content', 'saddle' )
								: __( 'read and edit your content', 'saddle' )
						) }
					</p>
					<div className="saddle-wizard__actions saddle-wizard__actions--center">
						<Button
							variant="primary"
							onClick={ () => exit( true ) }
						>
							{ __( 'Done', 'saddle' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}
