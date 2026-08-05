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
 *
 * OAuth apps (ChatGPT) are the exception on both counts: no Application
 * Password is minted (ChatGPT's connector form has no field for one — it
 * signs in through Saddle's consent screen), the wizard surfaces the
 * off-by-default sign-in switch with a one-click enable, and "listening"
 * watches the OAuth connections list instead of a key's last_used.
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	Steps,
	CardRadioGroup,
	CodeBlock,
	Snippet,
	LiveIndicator,
	CalloutCard,
	useCopy,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData, levelFor } from '../api';
import ConnectionHealth from './ConnectionHealth';
import { AppLogo, appKeyFromLabel } from './icons';
import {
	APPS,
	buildConfig,
	buildGuideConfig,
	MCP_URL,
	HELLO_PROMPT,
} from '../connect-apps';

const IS_LOCAL = /(?:localhost|127\.0\.0\.1|\.test|\.local)(?::|\/|$)/i.test(
	MCP_URL
);

// How long we listen before offering troubleshooting, in seconds.
const PATIENCE = 45;

const STEPS = [
	{ label: __( 'Choose app', 'saddle' ) },
	{ label: __( 'Paste setup', 'saddle' ) },
	{ label: __( 'Say hello', 'saddle' ) },
];

export default function ConnectWizard( {
	tier,
	clients = [],
	onExit,
	onClientsChanged,
} ) {
	const [ step, setStep ] = useState( 0 ); // 0 pick, 1 setup, 2 hello, 3 done
	const [ app, setApp ] = useState( null );
	const [ creating, setCreating ] = useState( null ); // app key mid-create
	const [ cred, setCred ] = useState( null ); // { uuid, password, label }
	const [ error, setError ] = useState( null );
	// A picked app that already has a connection — the replace/add choice is
	// rendered instead of silently stacking "Claude Code 2".
	const [ duplicateOf, setDuplicateOf ] = useState( null ); // { key, existing }
	// The gate for "I've pasted it": the setup must have been copied at least
	// once. Also what decides whether backing out revokes the fresh credential.
	const [ everCopied, setEverCopied ] = useState( false );
	const { copied: configCopied, copy: copyConfig } = useCopy();
	const [ patienceUp, setPatienceUp ] = useState( false );
	const level = levelFor( tier );

	const activeApp = APPS.find( ( a ) => a.key === app );
	const isOauthApp = 'oauth' === activeApp?.auth;

	// Live OAuth server state for oauth apps — fetched fresh (saddleData.oauth
	// is a page-load snapshot and the whole point here is flipping it on).
	const [ oauthState, setOauthState ] = useState( null );
	const [ enablingOauth, setEnablingOauth ] = useState( false );
	const [ oauthError, setOauthError ] = useState( null );

	/* ----- create the credential the moment an app is picked ----- */

	const adopt = ( key ) => ( res ) => {
		setApp( key );
		setCred( res );
		setDuplicateOf( null );
		discardedRef.current = false; // fresh credential, fresh cleanup slate
		setStep( 1 );
		if ( onClientsChanged ) {
			onClientsChanged();
		}
	};

	const createFresh = ( key ) => {
		const chosen = APPS.find( ( a ) => a.key === key );
		setCreating( key );
		setError( null );
		api( 'clients', {
			method: 'POST',
			data: { name: chosen.label },
		} )
			.then( adopt( key ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setCreating( null ) );
	};

	// Rotate the existing connection's key in place: the old key stops working
	// the moment the new one is issued, so no stale credential lingers.
	const replaceExisting = () => {
		const { key, existing } = duplicateOf;
		setCreating( key );
		setError( null );
		api( `clients/${ existing.uuid }/rotate`, { method: 'POST' } )
			.then( adopt( key ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setCreating( null ) );
	};

	const pick = ( key ) => {
		// OAuth apps never use a pasted key — ChatGPT signs in through
		// Saddle's consent screen — so minting an Application Password here
		// would only leave an unused credential on the account.
		const chosen = APPS.find( ( a ) => a.key === key );
		if ( 'oauth' === chosen?.auth ) {
			setApp( key );
			setCred( null );
			setDuplicateOf( null );
			setStep( 1 );
			return;
		}

		// Same app already connected? Offer replace-vs-add instead of quietly
		// creating a look-alike ("Claude Code 2") nobody remembers issuing.
		if ( 'other' !== key ) {
			const existing = clients
				.filter( ( c ) => appKeyFromLabel( c.label || c.name ) === key )
				.sort( ( a, b ) => ( b.created || 0 ) - ( a.created || 0 ) );
			if ( existing.length ) {
				setDuplicateOf( { key, existing: existing[ 0 ] } );
				return;
			}
		}
		createFresh( key );
	};

	/* ----- leaving: never strand an orphan credential ----- */

	// Latest values for the unmount cleanup below — a cleanup closure only
	// sees its render's state, so it reads these refs instead.
	const credRef = useRef( null );
	const everCopiedRef = useRef( false );
	const discardedRef = useRef( false );
	credRef.current = cred;
	everCopiedRef.current = everCopied;

	const discardIfUntouched = useCallback( () => {
		// Nothing was copied anywhere, so the credential can't be in use —
		// remove it rather than leaving a mystery key on the account.
		if ( cred && ! everCopied ) {
			discardedRef.current = true;
			api( `clients/${ cred.uuid }`, { method: 'DELETE' } )
				.then( () => onClientsChanged && onClientsChanged() )
				.catch( () => {} );
			return true;
		}
		return false;
	}, [ cred, everCopied, onClientsChanged ] );

	// Browser-back / hash navigation unmounts the wizard without running the
	// Cancel/Back handlers — clean up the never-copied credential here too.
	useEffect( () => {
		return () => {
			if (
				credRef.current &&
				! everCopiedRef.current &&
				! discardedRef.current
			) {
				api( `clients/${ credRef.current.uuid }`, {
					method: 'DELETE',
				} ).catch( () => {} );
			}
		};
	}, [] );

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

	/* ----- OAuth apps: live server state + a consent-list baseline ----- */

	// Snapshot of the OAuth connections BEFORE this attempt, so the poll below
	// can tell a fresh consent (new grant id) or fresh activity (a last_used
	// newer than anything in the snapshot) from what was already there. Server
	// timestamps only — never compared against the browser clock.
	const oauthBaselineRef = useRef( null );

	const snapshotConnections = ( rows ) => ( {
		ids: new Set( ( rows || [] ).map( ( r ) => r.id ) ),
		maxLastUsed: Math.max(
			0,
			...( rows || [] ).map( ( r ) =>
				Math.max( r.last_used || 0, r.created || 0 )
			)
		),
	} );

	useEffect( () => {
		if ( ! isOauthApp ) {
			oauthBaselineRef.current = null;
			return undefined;
		}
		let alive = true;
		api( 'oauth-settings' )
			.then( ( res ) => alive && setOauthState( res ) )
			.catch( () => {} );
		api( 'oauth-connections' )
			.then( ( rows ) => {
				if ( alive && ! oauthBaselineRef.current ) {
					oauthBaselineRef.current = snapshotConnections( rows );
				}
			} )
			.catch( () => {} );
		return () => {
			alive = false;
		};
	}, [ isOauthApp ] );

	// Explicit, labeled enable — never silent (OAuth is off by default on
	// purpose). Only ever sends enabled: true; the disable path lives in
	// Settings, where its purge-all-grants consequence is explained.
	const enableOauth = () => {
		setEnablingOauth( true );
		setOauthError( null );
		api( 'oauth-settings', { method: 'POST', data: { enabled: true } } )
			.then( ( res ) => setOauthState( res ) )
			.catch( ( e ) => setOauthError( e.message ) )
			.finally( () => setEnablingOauth( false ) );
	};

	/* ----- live listening: flip to done on the app's first request ----- */

	const pollRef = useRef( null );
	useEffect( () => {
		if ( step === 0 || step === 3 || ( ! cred && ! isOauthApp ) ) {
			return undefined;
		}
		pollRef.current = window.setInterval( () => {
			if ( isOauthApp ) {
				// The minted-key signal can never fire for an OAuth app — the
				// connected event is a grant that wasn't in the baseline (the
				// consent screen was completed) or bearer activity newer than
				// anything the baseline saw (a reconnect).
				api( 'oauth-connections' )
					.then( ( rows ) => {
						const base = oauthBaselineRef.current;
						if ( ! base ) {
							oauthBaselineRef.current =
								snapshotConnections( rows );
							return;
						}
						const connected = ( rows || [] ).some(
							( r ) =>
								! base.ids.has( r.id ) ||
								( r.last_used || 0 ) > base.maxLastUsed
						);
						if ( connected ) {
							setStep( 3 );
							if ( onClientsChanged ) {
								onClientsChanged();
							}
						}
					} )
					.catch( () => {} );
				return;
			}
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
	}, [ cred, step, onClientsChanged, isOauthApp ] );

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

	let config = '';
	if ( isOauthApp ) {
		config = buildGuideConfig( app );
	} else if ( cred ) {
		config = buildConfig( app, cred.password );
	}

	return (
		<div className="saddle-wizard">
			<div className="saddle-wizard__top">
				<Steps
					aria-label={ __( 'Setup progress', 'saddle' ) }
					steps={ STEPS }
					current={ step }
				/>
				{ step < 3 && (
					<Button
						variant="ghost"
						className="saddle-wizard__cancel"
						onClick={ () => exit( false ) }
					>
						{ __( 'Cancel', 'saddle' ) }
					</Button>
				) }
			</div>

			{ error && (
				<Notice tone="danger" onDismiss={ () => setError( null ) }>
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
						<Notice tone="warning">
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

					{ duplicateOf && (
						<CalloutCard
							className="saddle-wizard__duplicate"
							tone="warning"
							title={ sprintf(
								/* translators: %s: the existing connection's name. */
								__( '“%s” is already connected', 'saddle' ),
								duplicateOf.existing.label ||
									duplicateOf.existing.name
							) }
							description={
								duplicateOf.existing.last_used
									? __(
											'That connection has been used. Replacing its key issues a fresh one and the old key stops working instantly — you just paste the new setup into the app. Add a separate connection only for a second computer.',
											'saddle'
									  )
									: __(
											'That connection has never been used — it probably didn’t finish setup. Replacing its key is the clean way to try again; nothing extra is left behind.',
											'saddle'
									  )
							}
						>
							<div className="saddle-wizard__actions">
								<Button
									variant="primary"
									onClick={ replaceExisting }
									loading={ !! creating }
									disabled={ !! creating }
								>
									{ __(
										'Replace its key (recommended)',
										'saddle'
									) }
								</Button>
								<Button
									variant="secondary"
									onClick={ () =>
										createFresh( duplicateOf.key )
									}
									disabled={ !! creating }
								>
									{ __( 'Add another connection', 'saddle' ) }
								</Button>
								<Button
									variant="ghost"
									onClick={ () => setDuplicateOf( null ) }
									disabled={ !! creating }
								>
									{ __( 'Never mind', 'saddle' ) }
								</Button>
							</div>
						</CalloutCard>
					) }

					<CardRadioGroup
						className="saddle-wizard__apps"
						aria-label={ __(
							'Which app are you connecting?',
							'saddle'
						) }
						onChange={ pick }
						options={ APPS.map( ( a ) => ( {
							value: a.key,
							icon:
								creating === a.key ? (
									<Spinner />
								) : (
									<AppLogo app={ a.key } />
								),
							title: a.label,
							description: a.kind,
							disabled: !! creating,
						} ) ) }
					/>
				</div>
			) }

			{ /* ---------- Step 2: paste one thing ---------- */ }
			{ step === 1 && activeApp && ( cred || isOauthApp ) && (
				<div className="saddle-wizard__step" key="setup">
					<h2 className="saddle-wizard__title">
						{ sprintf(
							/* translators: %s: the app name. */
							__( 'Set up %s', 'saddle' ),
							activeApp.label
						) }
					</h2>
					<p className="saddle-wizard__lead">{ activeApp.how }</p>

					{ /* The sign-in switch is off by default — surface it here
					     with a labeled, one-click enable instead of letting the
					     instructions point at a server that isn't there. */ }
					{ isOauthApp &&
						oauthState &&
						! oauthState.enabled &&
						( oauthState.ready ? (
							<CalloutCard
								className="saddle-wizard__oauth-gate"
								tone="warning"
								title={ __(
									'One switch first: sign-in for ChatGPT',
									'saddle'
								) }
								description={ __(
									'ChatGPT signs in through your WordPress instead of using a pasted key, and that sign-in is currently off (it’s off by default). Turn it on and ChatGPT can ask to connect — you’ll still approve it on screen before it gets any access.',
									'saddle'
								) }
							>
								{ oauthError && (
									<Notice
										tone="danger"
										onDismiss={ () =>
											setOauthError( null )
										}
									>
										{ oauthError }
									</Notice>
								) }
								<Button
									variant="primary"
									onClick={ enableOauth }
									loading={ enablingOauth }
									disabled={ enablingOauth }
								>
									{ __( 'Turn on sign-in', 'saddle' ) }
								</Button>
							</CalloutCard>
						) : (
							<CalloutCard
								className="saddle-wizard__oauth-gate"
								tone="warning"
								title={ __(
									'Your site can’t offer sign-in yet',
									'saddle'
								) }
								description={
									oauthState.permalinks
										? __(
												'This needs your site to be served over HTTPS first — a sign-in token sent over plain HTTP can be read in transit.',
												'saddle'
										  )
										: __(
												'This needs pretty permalinks. Go to Settings → Permalinks, choose anything other than Plain, and come back.',
												'saddle'
										  )
								}
							/>
						) ) }
					{ isOauthApp && oauthState?.enabled && (
						<p className="saddle-wizard__hint">
							{ __(
								'Sign-in for ChatGPT is on — it can ask to connect, and you approve it on screen.',
								'saddle'
							) }
						</p>
					) }

					<div className="saddle-wizard__config">
						<CodeBlock
							dark
							copy={ false }
							label={
								isOauthApp
									? __( 'Your connection details', 'saddle' )
									: sprintf(
											/* translators: %s: the connection label. */
											__(
												'Made for “%s” just now',
												'saddle'
											),
											cred.label
									  )
							}
							code={ config }
						/>
						<Button
							variant="primary"
							className="saddle-wizard__copy"
							onClick={ () => {
								copyConfig( config );
								setEverCopied( true );
							} }
						>
							{ configCopied
								? __( 'Copied ✓', 'saddle' )
								: __( 'Copy setup', 'saddle' ) }
						</Button>
					</div>

					<CalloutCard
						className="saddle-wizard__cando"
						title={ sprintf(
							/* translators: %s: the app name. */
							__( 'What %s will be able to do', 'saddle' ),
							activeApp.label
						) }
						description={ level.one }
					>
						{ isOauthApp ? (
							<p className="saddle-wizard__cando-note">
								{ __(
									'ChatGPT signs in with your approval — you’ll see a consent screen here before it gets any access, and disconnecting it ends that access instantly.',
									'saddle'
								) }
							</p>
						) : (
							<>
								<p className="saddle-wizard__cando-note">
									{ __(
										'Its sign-in key works only for this app, only on this site, and only through Saddle — it can’t touch the rest of WordPress. Disconnect it anytime and access ends instantly. Go back without copying and the key is discarded.',
										'saddle'
									) }
								</p>
								<p className="saddle-wizard__cando-note">
									{ __(
										'The key appears only this once, inside the setup above. Saddle keeps just its name and last four characters — never the key itself.',
										'saddle'
									) }
								</p>
							</>
						) }
					</CalloutCard>

					{ IS_LOCAL && (
						<p className="saddle-wizard__hint">
							{ __(
								'This site runs on a local address, so the app must run on this same computer to reach it.',
								'saddle'
							) }
						</p>
					) }

					<div className="saddle-wizard__actions">
						<Button variant="ghost" onClick={ backToPick }>
							{ __( 'Back', 'saddle' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => setStep( 2 ) }
							disabled={
								! everCopied ||
								( isOauthApp && ! oauthState?.enabled )
							}
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

					<Snippet
						className="saddle-wizard__prompt"
						value={ HELLO_PROMPT }
						label={ __( 'Try asking', 'saddle' ) }
					/>

					<div
						className="saddle-wizard__listening"
						role="status"
						aria-live="polite"
					>
						<LiveIndicator>
							{ sprintf(
								/* translators: %s: the app name. */
								__(
									'Listening for %s — this updates by itself the moment it connects.',
									'saddle'
								),
								activeApp.label
							) }
						</LiveIndicator>
					</div>

					{ patienceUp && (
						<CalloutCard
							className="saddle-wizard__trouble"
							tone="warning"
							title={ __(
								'Taking longer than expected?',
								'saddle'
							) }
						>
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
								{ isOauthApp && (
									<li>
										{ __(
											'If ChatGPT says it can’t fetch the sign-in details, a page cache may be serving old pages — clear your site’s cache and recreate the connector.',
											'saddle'
										) }
									</li>
								) }
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
								variant="ghost"
								onClick={ () => setStep( 1 ) }
							>
								{ __( 'Show the setup again', 'saddle' ) }
							</Button>
						</CalloutCard>
					) }

					<div className="saddle-wizard__actions">
						<Button variant="ghost" onClick={ () => setStep( 1 ) }>
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
						{ isOauthApp
							? sprintf(
									/* translators: %s: the human-readable access level. */
									__(
										'You approved it, and it’s connected. Right now it can %s — change that anytime in Permissions.',
										'saddle'
									),
									level.key === 'read'
										? __(
												'only read your content',
												'saddle'
										  )
										: __(
												'read and edit your content',
												'saddle'
										  )
							  )
							: sprintf(
									/* translators: %s: the human-readable access level. */
									__(
										'It just made its first request. Right now it can %s — change that anytime in Permissions.',
										'saddle'
									),
									level.key === 'read'
										? __(
												'only read your content',
												'saddle'
										  )
										: __(
												'read and edit your content',
												'saddle'
										  )
							  ) }
					</p>
					<p className="saddle-wizard__lead saddle-wizard__lead--muted">
						{ __(
							'Manage or disconnect it anytime from the Connect tab.',
							'saddle'
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
