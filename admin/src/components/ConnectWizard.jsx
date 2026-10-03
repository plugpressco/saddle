/**
 * Connect wizard — one step at a time, one thing to do per step.
 *
 * Pick your app → paste one thing → watch it connect, live.
 *
 * Two paths, and the wizard leads with the first once the owner has turned
 * sign-in on:
 *
 * - **Address.** The app gets the site's MCP address and nothing else. It
 *   registers itself with Saddle's sign-in server, opens the owner's browser,
 *   and the owner approves it on Saddle's consent screen. No key is minted;
 *   "listening" watches the OAuth connections list for the new grant.
 * - **Key.** The credential is created server-side the moment an app is
 *   picked (core Application Passwords, no Authorize-screen round-trip,
 *   secret never in a URL) and dropped straight into the app's config. If the
 *   user backs out before copying anything, the just-created credential is
 *   quietly revoked — no orphan keys. The fallback for apps and sites that
 *   can't sign in.
 *
 * Sign-in is off by default on purpose. The wizard surfaces the switch with
 * a labelled, one-click enable; it never flips it on its own.
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
import SelfCheck from './SelfCheck';
import { AppLogo, appKeyFromLabel } from './icons';
import {
	APPS,
	buildConfig,
	installLinks,
	MCP_URL,
	HELLO_PROMPT,
	howFor,
} from '../connect-apps';

const IS_LOCAL = /(?:localhost|127\.0\.0\.1|\.test|\.local)(?::|\/|$)/i.test(
	MCP_URL
);

// How long we listen before offering troubleshooting, in seconds. First run
// waits longer: the owner may be switching windows to approve the app.
const PATIENCE = 45;
const PATIENCE_EMBEDDED = 180;

const STEPS = [
	{ label: __( 'Choose app', 'saddle' ) },
	{ label: __( 'Paste setup', 'saddle' ) },
	{ label: __( 'Say hello', 'saddle' ) },
];

// The apps first run shows before "More apps" (#269).
const COMMON_APPS = [ 'claude', 'chatgpt', 'claude-code', 'cursor' ];

/**
 * @param {Object}   props
 * @param {Array}    props.clients
 * @param {Function} props.onExit
 * @param {Function} props.onClientsChanged
 * @param {boolean}  props.embedded         First run (#269): no step bar or Cancel,
 *                                          the common apps first, setup and live
 *                                          listening on one screen, and no done
 *                                          screen — `onConnected` takes over.
 * @param {Function} props.onConnected      Called with the app once it connects.
 * @param {string}   props.initialApp       Start on the key setup for this app
 *                                          (AI apps → Connect an app, "Make a key").
 * @param {string}   props.presetApp        First run: the app already chosen on
 *                                          its own tiles. Skips the picker and
 *                                          opens that app's setup.
 * @param {Function} props.renderWaiting    First run: draws the waiting line
 *                                          and calls `connected()` when it sees
 *                                          the app. It replaces the wizard's
 *                                          own poll. Gets `{ app, appLabel,
 *                                          keyId, connected }`.
 * @param {Function} props.onBack           First run: back to the tiles.
 */
export default function ConnectWizard( {
	clients = [],
	onExit,
	onClientsChanged,
	embedded = false,
	onConnected,
	initialApp = null,
	presetApp = null,
	renderWaiting = null,
	onBack = null,
} ) {
	const [ step, setStep ] = useState( 0 ); // 0 pick, 1 setup, 2 hello, 3 done
	const [ app, setApp ] = useState( null );
	const [ allApps, setAllApps ] = useState( ! embedded );
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
	// A new app starts at Read only; what it may do is chosen per app (#285).
	const level = levelFor( 'read' );

	// Live sign-in server state — fetched fresh on mount (saddleData.oauth is
	// a page-load snapshot and the whole point here is flipping it on).
	const [ oauthState, setOauthState ] = useState( null );
	const [ enablingOauth, setEnablingOauth ] = useState( false );
	const [ oauthError, setOauthError ] = useState( null );
	// Whether the sign-in state has arrived (or failed): a preset app is
	// picked only then, because the path it takes depends on it.
	const [ oauthSettled, setOauthSettled ] = useState( false );

	// Which path the user prefers this session. null = not chosen: follow the
	// switch (address when sign-in is on, key otherwise). "Use a key instead"
	// and "Use the address instead" set it explicitly.
	const [ preferAddress, setPreferAddress ] = useState( null );
	const signInOn = !! oauthState?.enabled;
	const wantsAddress =
		null === preferAddress ? signInOn : preferAddress && signInOn;

	// The path a given app takes, honouring what it supports.
	const modeFor = ( meta ) => {
		if ( ! meta ) {
			return wantsAddress ? 'address' : 'key';
		}
		if ( ! meta.viaKey ) {
			return 'address';
		}
		if ( ! meta.viaAddress ) {
			return 'key';
		}
		return wantsAddress ? 'address' : 'key';
	};

	const activeApp = APPS.find( ( a ) => a.key === app );
	const mode = modeFor( activeApp );
	const byAddress = 'address' === mode;

	useEffect( () => {
		let alive = true;
		api( 'oauth-settings' )
			.then( ( res ) => alive && setOauthState( res ) )
			.catch( () => {} )
			.finally( () => alive && setOauthSettled( true ) );
		return () => {
			alive = false;
		};
	}, [] );

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

	const pickByKey = ( key ) => {
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

	const pick = ( key ) => {
		const chosen = APPS.find( ( a ) => a.key === key );
		// The address path mints nothing: the app signs in through Saddle's
		// consent screen, and an Application Password would only be an unused
		// credential left on the account.
		if ( 'address' === modeFor( chosen ) ) {
			setApp( key );
			setCred( null );
			setDuplicateOf( null );
			setStep( 1 );
			return;
		}
		pickByKey( key );
	};

	// AI apps → Connect an app sends the owner here to make a key for one app: skip
	// the picker and go straight to that app's key setup.
	useEffect( () => {
		if ( initialApp && APPS.some( ( a ) => a.key === initialApp ) ) {
			setPreferAddress( false );
			pickByKey( initialApp );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, on arrival.
	}, [] );

	// First run chose the app on its own tiles: open that app's setup.
	const presetDone = useRef( false );
	useEffect( () => {
		if (
			presetApp &&
			oauthSettled &&
			! presetDone.current &&
			APPS.some( ( a ) => a.key === presetApp )
		) {
			presetDone.current = true;
			pick( presetApp );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, when sign-in state is known.
	}, [ presetApp, oauthSettled ] );

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
		if ( onBack ) {
			onBack();
		}
	};

	// The waiting line saw the app: the same outcome as the poll below.
	const markConnected = () => {
		everCopiedRef.current = true;
		setEverCopied( true );
		setStep( 3 );
		if ( onClientsChanged ) {
			onClientsChanged();
		}
	};

	/* ----- switching paths on step 2 ----- */

	// "Use a key instead": mint a key for the same app and show the key setup.
	const switchToKey = () => {
		setPreferAddress( false );
		setEverCopied( false );
		pickByKey( app );
	};

	// "Use the address instead": drop an uncopied key and show the address.
	const switchToAddress = () => {
		discardIfUntouched();
		setCred( null );
		setEverCopied( false );
		setPreferAddress( true );
	};

	/* ----- address path: a consent-list baseline ----- */

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
		if ( ! byAddress || ! app ) {
			oauthBaselineRef.current = null;
			return undefined;
		}
		let alive = true;
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
	}, [ byAddress, app ] );

	// Explicit, labeled enable — never silent (sign-in is off by default on
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
		if ( step === 0 || step === 3 || ( ! cred && ! byAddress ) ) {
			return undefined;
		}
		// First run's waiting line does the watching.
		if ( embedded && renderWaiting ) {
			return undefined;
		}
		pollRef.current = window.setInterval( () => {
			if ( byAddress ) {
				// The minted-key signal can never fire on the address path —
				// the connected event is a grant that wasn't in the baseline
				// (the consent screen was completed) or bearer activity newer
				// than anything the baseline saw (a reconnect).
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
						// The key is in use now, however it left the page (a
						// hand-copied key never pressed Copy). Leaving must
						// not discard it; first run unmounts the wizard at
						// once, before another render would set the ref.
						everCopiedRef.current = true;
						setEverCopied( true );
						setStep( 3 );
						if ( onClientsChanged ) {
							onClientsChanged();
						}
					}
				} )
				.catch( () => {} );
		}, 3000 );
		return () => window.clearInterval( pollRef.current );
	}, [ cred, step, onClientsChanged, byAddress, embedded, renderWaiting ] );

	// Embedded, the caller carries on from here instead of the done screen.
	useEffect( () => {
		if ( embedded && 3 === step && onConnected ) {
			onConnected( activeApp );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, on arrival.
	}, [ embedded, step ] );

	// Offer troubleshooting once the wait step has been up a while. Embedded,
	// the setup step is the wait step.
	const waiting = 2 === step || ( embedded && 1 === step );
	useEffect( () => {
		if ( ! waiting ) {
			return undefined;
		}
		setPatienceUp( false );
		const t = window.setTimeout(
			() => setPatienceUp( true ),
			( embedded ? PATIENCE_EMBEDDED : PATIENCE ) * 1000
		);
		return () => window.clearTimeout( t );
	}, [ step, waiting, embedded ] );

	let config = '';
	let links = [];
	if ( activeApp && byAddress ) {
		config = buildConfig( app, null, 'address' );
		links = installLinks( app, null, 'address' );
	} else if ( activeApp && cred ) {
		config = buildConfig( app, cred.password, 'key' );
		links = installLinks( app, cred.password, 'key' );
	}

	// The sign-in switch, as step 1 shows it: on, off-but-ready, or blocked.
	const renderSignInGate = () => {
		if ( ! oauthState ) {
			return null;
		}
		if ( oauthState.enabled ) {
			return (
				<p className="saddle-wizard__hint">
					{ __(
						'Sign-in for apps is on. Pick your app, paste one address into it, and approve the connection here when your browser opens. No key to copy.',
						'saddle'
					) }
				</p>
			);
		}
		if ( oauthState.ready ) {
			return (
				<CalloutCard
					className="saddle-wizard__oauth-gate"
					title={ __( 'Turn on sign-in for apps', 'saddle' ) }
					description={ __(
						'With sign-in on, an app needs only this site’s address. You approve it here when your browser opens, with no key to copy. Keys still work.',
						'saddle'
					) }
				>
					{ oauthError && (
						<Notice
							tone="danger"
							onDismiss={ () => setOauthError( null ) }
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
			);
		}
		return (
			<p className="saddle-wizard__hint">
				{ oauthState.permalinks
					? __(
							'Apps connect with a key here. Sign-in by address needs this site to be served over HTTPS first.',
							'saddle'
					  )
					: __(
							'Apps connect with a key here. Sign-in by address needs pretty permalinks (Settings → Permalinks, anything other than Plain).',
							'saddle'
					  ) }
			</p>
		);
	};

	// Troubleshooting, offered once the wait has run a while.
	const renderTrouble = () =>
		patienceUp &&
		( embedded && renderWaiting ? (
			<SelfCheck
				app={ app }
				local={ IS_LOCAL }
				permalinks={ oauthState ? oauthState.permalinks : true }
				onUseKey={ byAddress && activeApp.viaKey ? switchToKey : null }
				onSkip={ () => exit( false ) }
			/>
		) : (
			<CalloutCard
				className="saddle-wizard__trouble"
				tone="warning"
				title={ __( 'Still waiting', 'saddle' ) }
			>
				<ul>
					<li>
						{ sprintf(
							/* translators: %s: the app name. */
							__(
								'Save the setup, then restart or reload %s.',
								'saddle'
							),
							activeApp.label
						) }
					</li>
					<li>
						{ __(
							'The app connects only when you use it. Ask it something about your site.',
							'saddle'
						) }
					</li>
					{ byAddress && (
						<li>
							{ sprintf(
								/* translators: %s: the app name. */
								__(
									'If %s can’t fetch the sign-in details, a page cache may be serving old pages. Clear your site’s cache and add the server again.',
									'saddle'
								),
								activeApp.label
							) }
						</li>
					) }
					{ byAddress && 'slow' === oauthState?.discovery && (
						<li>
							{ __(
								'This site answers too slowly for some apps. They wait a few seconds, then report that the site doesn’t support sign-in. Turn on page caching or move to a faster host, then add the server again.',
								'saddle'
							) }
						</li>
					) }
					{ IS_LOCAL && (
						<li>
							{ __(
								'This is a local site. The app must run on this computer.',
								'saddle'
							) }
						</li>
					) }
				</ul>
				<ConnectionHealth />
				{ 2 === step && (
					<Button variant="ghost" onClick={ () => setStep( 1 ) }>
						{ __( 'Show the setup again', 'saddle' ) }
					</Button>
				) }
			</CalloutCard>
		) );

	const shownApps = allApps
		? APPS
		: APPS.filter( ( a ) => COMMON_APPS.includes( a.key ) );

	// The welcome has already chosen the app. Until the sign-in state is known
	// and the key (or address) is ready, the picker would flash as the wrong
	// step inside the conversation, so wait quietly instead. An error or a
	// duplicate still draws the normal step, where the owner can act on it.
	if ( embedded && presetApp && 0 === step && ! error && ! duplicateOf ) {
		return (
			<div className="saddle-wizard saddle-wizard--embedded">
				<Spinner />
			</div>
		);
	}

	return (
		<div
			className={
				embedded
					? 'saddle-wizard saddle-wizard--embedded'
					: 'saddle-wizard'
			}
		>
			{ ! embedded && (
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
			) }

			{ error && (
				<Notice tone="danger" onDismiss={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ /* ---------- Step 1: choose the app ---------- */ }
			{ step === 0 && (
				<div className="saddle-wizard__step" key="pick">
					<h2 className="saddle-wizard__title">
						{ embedded
							? __( 'Which AI do you use?', 'saddle' )
							: __( 'Which app are you connecting?', 'saddle' ) }
					</h2>
					<p className="saddle-wizard__lead">
						{ wantsAddress
							? __(
									'Every app gets the same address. Pick yours to see where it goes.',
									'saddle'
							  )
							: __(
									'WordPress creates a sign-in key for the app you pick, and Saddle prepares the setup. If you leave before using the key, it is removed.',
									'saddle'
							  ) }
					</p>

					{ renderSignInGate() }

					{ ! wantsAddress && ! saddleData.appPasswords && (
						<Notice tone="warning">
							{ saddleData.ssl
								? __(
										'Application Passwords seem to be off, often because of a security plugin. Turn them on under Users → Profile before connecting.',
										'saddle'
								  )
								: __(
										'WordPress turns off app connections on sites without HTTPS, such as http://localhost. They work once the site uses HTTPS.',
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
											'That connection has been used. Replacing its key stops the old one at once, then you paste the new setup into the app. Add a separate connection only for a second computer.',
											'saddle'
									  )
									: __(
											'That connection was never used, so its setup probably didn’t finish. Replace its key to try again without leaving an extra key behind.',
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
									{ __( 'Cancel', 'saddle' ) }
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
						options={ shownApps.map( ( a ) => ( {
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
					{ ! allApps && (
						<Button
							variant="link"
							className="saddle-wizard__more"
							onClick={ () => setAllApps( true ) }
						>
							{ sprintf(
								/* translators: %d: how many more apps are listed. */
								__( 'More apps (%d)', 'saddle' ),
								APPS.length - shownApps.length
							) }
						</Button>
					) }
				</div>
			) }

			{ /* ---------- Step 2: paste one thing ---------- */ }
			{ step === 1 && activeApp && ( cred || byAddress ) && (
				<div className="saddle-wizard__step" key="setup">
					<h2 className="saddle-wizard__title">
						{ sprintf(
							/* translators: %s: the app name. */
							__( 'Set up %s', 'saddle' ),
							activeApp.label
						) }
					</h2>
					<p className="saddle-wizard__lead">
						{ howFor( activeApp, mode ) }
					</p>

					{ /* An address-only app (ChatGPT) with sign-in still off:
					     surface the switch here with a labelled, one-click
					     enable instead of pointing at a server that isn't there. */ }
					{ byAddress &&
						oauthState &&
						! oauthState.enabled &&
						( oauthState.ready ? (
							<CalloutCard
								className="saddle-wizard__oauth-gate"
								tone="warning"
								title={ __(
									'One switch first: sign-in for apps',
									'saddle'
								) }
								description={ sprintf(
									/* translators: %s: the app name. */
									__(
										'%s signs in through your WordPress instead of a pasted key, and that sign-in is off. Turn it on, and the app gets no access until you approve it here.',
										'saddle'
									),
									activeApp.label
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
												'This needs HTTPS first. A sign-in token sent over plain HTTP can be read in transit.',
												'saddle'
										  )
										: __(
												'This needs pretty permalinks. Go to Settings → Permalinks, choose anything other than Plain, and come back.',
												'saddle'
										  )
								}
							/>
						) ) }

					<div className="saddle-wizard__config">
						<CodeBlock
							dark
							copy={ false }
							label={
								byAddress
									? __( 'Your connection address', 'saddle' )
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
								? __( 'Copied', 'saddle' )
								: __( 'Copy setup', 'saddle' ) }
						</Button>
					</div>

					{ /* One click instead of a paste, for apps that install a
					     server from a link. Opening a link counts as copying:
					     the setup has left this page either way. */ }
					{ links.length > 0 && (
						<div className="saddle-wizard__install">
							<p className="saddle-wizard__hint">
								{ sprintf(
									/* translators: %s: the app name. */
									__(
										'Or open %s with the server filled in, and confirm it there.',
										'saddle'
									),
									activeApp.label
								) }
							</p>
							<div className="saddle-wizard__actions">
								{ links.map( ( link ) => (
									<Button
										key={ link.key }
										variant="secondary"
										href={ link.href }
										onClick={ () => setEverCopied( true ) }
									>
										{ link.label }
									</Button>
								) ) }
							</div>
						</div>
					) }

					{ byAddress && activeApp.viaKey && (
						<p className="saddle-wizard__hint">
							{ __(
								'A key also works, including from a script.',
								'saddle'
							) }{ ' ' }
							<Button
								variant="link"
								onClick={ switchToKey }
								disabled={ !! creating }
							>
								{ __( 'Use a key instead', 'saddle' ) }
							</Button>
						</p>
					) }
					{ ! byAddress && activeApp.viaAddress && signInOn && (
						<p className="saddle-wizard__hint">
							{ __(
								'This app can also connect with the address and a sign-in screen.',
								'saddle'
							) }{ ' ' }
							<Button variant="link" onClick={ switchToAddress }>
								{ __( 'Use the address instead', 'saddle' ) }
							</Button>
						</p>
					) }

					<CalloutCard
						className="saddle-wizard__cando"
						title={ sprintf(
							/* translators: %s: the app name. */
							__( 'What %s will be able to do', 'saddle' ),
							activeApp.label
						) }
						description={ level.one }
					>
						{ byAddress ? (
							<p className="saddle-wizard__cando-note">
								{ sprintf(
									/* translators: %s: the app name. */
									__(
										'%s gets access only after you approve it on a consent screen here. Disconnecting it ends that access at once.',
										'saddle'
									),
									activeApp.label
								) }
							</p>
						) : (
							<>
								<p className="saddle-wizard__cando-note">
									{ __(
										'Its key works only for this app, on this site, through Saddle. It can’t reach the rest of WordPress. Disconnecting it ends access at once. If you go back without copying, the key is deleted.',
										'saddle'
									) }
								</p>
								<p className="saddle-wizard__cando-note">
									{ __(
										'The key is shown once, in the setup above. Saddle keeps only its name and last four characters.',
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

					{ /* Embedded, the poll is already running on this step:
					     say so here and skip the separate hello screen. */ }
					{ embedded &&
						renderWaiting &&
						( ! byAddress || oauthState?.enabled ) &&
						renderWaiting( {
							app,
							appLabel: activeApp.label,
							keyId: cred ? `key:${ cred.uuid }` : null,
							connected: markConnected,
						} ) }
					{ embedded &&
						! renderWaiting &&
						( ! byAddress || oauthState?.enabled ) && (
							<div
								className="saddle-wizard__listening"
								role="status"
								aria-live="polite"
							>
								<LiveIndicator>
									{ sprintf(
										/* translators: %s: the app name. */
										__(
											'Waiting for %s. This step moves on when it connects.',
											'saddle'
										),
										activeApp.label
									) }
								</LiveIndicator>
							</div>
						) }
					{ embedded && renderTrouble() }

					<div className="saddle-wizard__actions">
						<Button variant="ghost" onClick={ backToPick }>
							{ __( 'Back', 'saddle' ) }
						</Button>
						{ ! embedded && (
							<Button
								variant="primary"
								onClick={ () => setStep( 2 ) }
								disabled={
									! everCopied ||
									( byAddress && ! oauthState?.enabled )
								}
							>
								{ __( 'I’ve pasted it', 'saddle' ) }
							</Button>
						) }
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
								__( 'Waiting for %s to connect.', 'saddle' ),
								activeApp.label
							) }
						</LiveIndicator>
					</div>

					{ renderTrouble() }

					<div className="saddle-wizard__actions">
						<Button variant="ghost" onClick={ () => setStep( 1 ) }>
							{ __( 'Back', 'saddle' ) }
						</Button>
						<Button variant="link" onClick={ () => exit( false ) }>
							{ byAddress
								? sprintf(
										/* translators: %s: the app name. */
										__(
											'Close and approve it in %s',
											'saddle'
										),
										activeApp.label
								  )
								: __( 'Finish later', 'saddle' ) }
						</Button>
					</div>
				</div>
			) }

			{ /* ---------- Done ---------- */ }
			{ step === 3 && activeApp && ! embedded && (
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
						{ byAddress
							? __(
									'You approved it. It starts at Read only.',
									'saddle'
							  )
							: __(
									'It made its first request. It starts at Read only.',
									'saddle'
							  ) }
					</p>
					<p className="saddle-wizard__lead saddle-wizard__lead--muted">
						{ __(
							'Change its access or disconnect it on the AI apps page.',
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
