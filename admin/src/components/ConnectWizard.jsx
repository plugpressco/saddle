/**
 * The connect step, in two places.
 *
 * - **The welcome** (`embedded`, #269): first run has already asked which
 *   AI. This shows that app's setup inside Saddle's message: the address,
 *   or a key made the moment the step opens, the one-click links, and the
 *   waiting line FirstRun draws (`renderWaiting`). It never asks for the app
 *   again (S7): an error or an older key for the app shows its own choice,
 *   and Back returns to the app chips.
 * - **The key setup's own address** (`&key=<app>` on AI apps): the same
 *   steps as the Connect an app drawer, on the page, so both look alike.
 *   AI apps itself makes keys inside the drawer.
 *
 * Two paths, and the welcome leads with the address whenever the site can
 * turn sign-in on (`connectPath`):
 *
 * - **Address.** The app gets the site's MCP address and nothing else. It
 *   registers itself with Saddle's sign-in server, opens the owner's browser,
 *   and the owner approves it on Saddle's consent screen. No key is minted.
 * - **Key.** The credential is created server-side (core Application
 *   Passwords, secret never in a URL) and dropped straight into the app's
 *   config. A key nobody copied is deleted when the owner leaves, even by a
 *   reload (`useKeyMaker`). The fallback for apps and sites that can't sign
 *   in, and the only way to Claude on a site on this computer.
 *
 * Sign-in is off by default on purpose. The welcome surfaces the switch with
 * a labelled, one-click enable; it never flips it on its own.
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	CodeBlock,
	CalloutCard,
	useCopy,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import SectionHeader from './SectionHeader';
import SelfCheck from './SelfCheck';
import ConnectApps, { useKeyMaker } from './ConnectApps';
import { useOauthSettings } from './SignInCard';
import { appKeyFromLabel } from './icons';
import {
	APPS,
	IS_LOCAL,
	buildConfig,
	connectPath,
	howFor,
	installLinks,
	unreachableHere,
} from '../connect-apps';

/**
 * @param {Object}  props
 * @param {boolean} props.embedded The welcome's connect step; otherwise the
 *                                 key setup's own address on AI apps.
 */
export default function ConnectWizard( props ) {
	return props.embedded ? (
		<WelcomeConnect { ...props } />
	) : (
		<KeyPage { ...props } />
	);
}

/**
 * `&key=<app>`: the Connect an app drawer's steps, on the page, starting
 * with a key for that app.
 *
 * @param {Object}   props
 * @param {string}   props.initialApp       The app a key is for, or null.
 * @param {Function} props.onExit           Back to the list of apps.
 * @param {Function} props.onClientsChanged Reloads the keys.
 */
function KeyPage( { initialApp = null, onExit, onClientsChanged } ) {
	const { oauth } = useOauthSettings();
	return (
		<section className="saddle-stack saddle-connect-page">
			<SectionHeader
				title={ __( 'Connect an app', 'saddle' ) }
				actions={
					<Button
						variant="secondary"
						size="sm"
						onClick={ () => onExit() }
					>
						{ __( 'Done', 'saddle' ) }
					</Button>
				}
			/>
			<ConnectApps
				oauth={ oauth }
				initialApp={ initialApp }
				startWithKey
				onConnected={ onClientsChanged }
			/>
		</section>
	);
}

/**
 * The welcome's connect step.
 *
 * @param {Object}   props
 * @param {Array}    props.clients          Keys (GET /clients), for an older
 *                                          key for the same app.
 * @param {Function} props.onExit           Skip the welcome.
 * @param {Function} props.onClientsChanged Reloads the keys.
 * @param {Function} props.onConnected      Called with the app once it
 *                                          connects.
 * @param {string}   props.presetApp        The app first run chose.
 * @param {Function} props.renderWaiting    Draws the waiting line and calls
 *                                          `connected()` when it sees the
 *                                          app, and `onSlow()` once the wait
 *                                          has run long, which shows the
 *                                          checks. Gets `{ app, appLabel,
 *                                          keyId, connected, onSlow }`.
 * @param {Function} props.onBack           Back to the app choice.
 */
function WelcomeConnect( {
	clients = [],
	onExit,
	onClientsChanged,
	onConnected,
	presetApp = null,
	renderWaiting = null,
	onBack = null,
} ) {
	const [ step, setStep ] = useState( 0 ); // 0 getting ready, 1 setup, 3 connected
	const [ app, setApp ] = useState( null );
	// A picked app that already has a key: replace it or add another,
	// instead of silently stacking "Claude Code 2".
	const [ duplicateOf, setDuplicateOf ] = useState( null ); // { key, existing }
	const { copied: configCopied, copy: copyConfig } = useCopy();
	const [ patienceUp, setPatienceUp ] = useState( false );
	const keys = useKeyMaker( onClientsChanged );
	const cred = keys.made;

	// Live sign-in server state, fetched fresh on mount (saddleData.oauth is
	// a page-load snapshot and the whole point here is flipping it on).
	const [ oauthState, setOauthState ] = useState( null );
	const [ enablingOauth, setEnablingOauth ] = useState( false );
	const [ oauthError, setOauthError ] = useState( null );
	// Whether the sign-in state has arrived (or failed): the app is set up
	// only then, because the path it takes depends on it.
	const [ oauthSettled, setOauthSettled ] = useState( false );

	// Which path the owner prefers this session. null = not chosen: follow
	// `connectPath`. "Use a key instead" and "Use the address instead" set it.
	const [ preferAddress, setPreferAddress ] = useState( null );

	const modeFor = ( meta, prefer = preferAddress ) =>
		connectPath( meta || null, {
			signIn: oauthState,
			offer: true,
			local: IS_LOCAL,
			prefer,
		} );

	const activeApp = APPS.find( ( a ) => a.key === app );
	const mode = modeFor( activeApp );
	const byAddress = 'address' === mode;
	const unreachable = unreachableHere( activeApp, IS_LOCAL );

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

	/* ----- the key, made the moment the app's key setup opens ----- */

	const adopt = ( key ) => ( res ) => {
		if ( res ) {
			setApp( key );
			setDuplicateOf( null );
			setStep( 1 );
		}
	};

	const createFresh = ( key ) => {
		const chosen = APPS.find( ( a ) => a.key === key );
		keys.make( chosen.label ).then( adopt( key ) );
	};

	// Rotate the older key in place: it stops working the moment the new one
	// is issued, so no stale credential lingers.
	const replaceExisting = () => {
		const { key, existing } = duplicateOf;
		keys.replace( existing.uuid ).then( adopt( key ) );
	};

	const pickByKey = ( key ) => {
		if ( 'other' !== key ) {
			const existing = clients
				.filter(
					( c ) =>
						c.uuid !== keys.stale &&
						appKeyFromLabel( c.label || c.name ) === key
				)
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
		// consent screen, and a key would only be an unused credential.
		if ( 'address' === modeFor( chosen ) ) {
			setApp( key );
			setDuplicateOf( null );
			setStep( 1 );
			return;
		}
		pickByKey( key );
	};

	// Open the chosen app's setup once the sign-in state is known.
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

	/* ----- leaving ----- */

	const exit = () => {
		keys.discard();
		onExit();
	};

	const backToPick = () => {
		keys.discard();
		setApp( null );
		setDuplicateOf( null );
		setStep( 0 );
		if ( onBack ) {
			onBack();
		}
	};

	// The waiting line saw the app.
	const markConnected = () => {
		keys.keep();
		setStep( 3 );
		if ( onClientsChanged ) {
			onClientsChanged();
		}
	};

	useEffect( () => {
		if ( 3 === step && onConnected ) {
			onConnected( activeApp );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, on arrival.
	}, [ step ] );

	/* ----- switching paths ----- */

	// "Use a key instead": make a key for the same app and show its setup.
	// The getting-ready step waits while the key is made, and is where an
	// older key for the app offers replace or add.
	const switchToKey = () => {
		setPreferAddress( false );
		setPatienceUp( false );
		setStep( 0 );
		pickByKey( app );
	};

	// "Use the address instead": drop an uncopied key and show the address.
	const switchToAddress = () => {
		keys.discard();
		setPatienceUp( false );
		setPreferAddress( true );
	};

	// Cancel on "already has a key": back to the address the owner came
	// from, or to the app choice.
	const cancelDuplicate = () => {
		setDuplicateOf( null );
		if ( app ) {
			setPreferAddress( null );
			setStep( 1 );
			return;
		}
		backToPick();
	};

	// Explicit, labelled enable, never silent (sign-in is off by default on
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

	let config = '';
	let links = [];
	if ( activeApp && byAddress ) {
		config = buildConfig( app, null, 'address' );
		links = installLinks( app, null, 'address' );
	} else if ( activeApp && cred ) {
		config = buildConfig( app, cred.password, 'key' );
		links = installLinks( app, cred.password, 'key' );
	}

	// Getting ready: the sign-in state, and the key on the key path. An error
	// or an older key draws its own choice below instead.
	if ( presetApp && 0 === step && ! keys.error && ! duplicateOf ) {
		return (
			<div className="saddle-wizard saddle-wizard--embedded">
				<Spinner />
			</div>
		);
	}

	// The app's own name, as the drawer says it, not "Claude Code 2".
	const duplicateLabel = duplicateOf
		? ( APPS.find( ( a ) => a.key === duplicateOf.key ) || {} ).label
		: '';

	return (
		<div className="saddle-wizard saddle-wizard--embedded">
			{ keys.error && (
				<Notice tone="danger" onDismiss={ keys.clearError }>
					{ keys.error }
				</Notice>
			) }

			{ /* ---------- An older key, or an error: no app picker ---------- */ }
			{ 0 === step && (
				<div className="saddle-wizard__step" key="ready">
					{ duplicateOf && (
						<p className="saddle-wizard__lead">
							{ sprintf(
								/* translators: %s: app name, such as Claude Code. */
								__( '%s already has a key.', 'saddle' ),
								duplicateLabel
							) }{ ' ' }
							{ duplicateOf.existing.last_used
								? __(
										'That connection has been used. Replacing its key stops the old one at once, then you paste the new setup into the app. Add a separate connection only for a second computer.',
										'saddle'
								  )
								: __(
										'That connection was never used, so its setup probably didn’t finish. Replace its key to try again without leaving an extra key behind.',
										'saddle'
								  ) }
						</p>
					) }
					<div className="saddle-wizard__actions saddle-wizard__actions--start">
						{ duplicateOf && (
							<>
								<Button
									variant="primary"
									onClick={ replaceExisting }
									loading={ keys.busy }
									disabled={ keys.busy }
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
									disabled={ keys.busy }
								>
									{ __( 'Add another connection', 'saddle' ) }
								</Button>
								<Button
									variant="ghost"
									onClick={ cancelDuplicate }
									disabled={ keys.busy }
								>
									{ __( 'Cancel', 'saddle' ) }
								</Button>
							</>
						) }
						{ ! duplicateOf && keys.error && (
							<>
								<Button
									variant="secondary"
									onClick={ () => {
										keys.clearError();
										pickByKey( app || presetApp );
									} }
								>
									{ __( 'Try again', 'saddle' ) }
								</Button>
								<Button variant="ghost" onClick={ backToPick }>
									{ __( 'Back', 'saddle' ) }
								</Button>
							</>
						) }
					</div>
				</div>
			) }

			{ /* ---------- A web app can't reach this computer (P7) ---------- */ }
			{ 1 === step && activeApp && unreachable && ! activeApp.viaKey && (
				<div className="saddle-wizard__step" key="unreachable">
					<p className="saddle-wizard__lead">
						{ sprintf(
							/* translators: %s: app name, such as ChatGPT. */
							__(
								'%s runs on its own servers, so it can’t reach a site on this computer. Try Claude Code, Cursor or Codex here, or connect once the site is online.',
								'saddle'
							),
							activeApp.label
						) }
					</p>
					<div className="saddle-wizard__actions">
						<Button variant="ghost" onClick={ backToPick }>
							{ __( 'Back', 'saddle' ) }
						</Button>
					</div>
				</div>
			) }

			{ /* ---------- The setup ---------- */ }
			{ 1 === step &&
				activeApp &&
				( cred || byAddress ) &&
				! ( unreachable && ! activeApp.viaKey ) && (
					<div className="saddle-wizard__step" key="setup">
						{ unreachable && (
							<p className="saddle-wizard__lead">
								{ sprintf(
									/* translators: %s: app name, such as Claude. */
									__(
										'%s runs on its own servers, so it can’t reach a site on this computer.',
										'saddle'
									),
									activeApp.label
								) }{ ' ' }
								{ sprintf(
									/* translators: %s: app name, such as Claude. */
									__(
										'The %s desktop app can reach it through a small bridge.',
										'saddle'
									),
									activeApp.label
								) }
							</p>
						) }
						<p className="saddle-wizard__lead">
							{ howFor( activeApp, mode, IS_LOCAL ) }
						</p>

						{ /* The address path with sign-in still off: the switch
						     first, with a labelled one-click enable, instead of
						     pointing at a server that isn't there. */ }
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
									action={
										<Button
											variant="primary"
											onClick={ enableOauth }
											loading={ enablingOauth }
											disabled={ enablingOauth }
										>
											{ __(
												'Turn on sign-in',
												'saddle'
											) }
										</Button>
									}
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
										? __(
												'Your connection address',
												'saddle'
										  )
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
								// The owner's own copy of the text counts too.
								onCopy={ keys.keep }
							/>
							<Button
								variant="primary"
								className="saddle-wizard__copy"
								onClick={ () => {
									copyConfig( config );
									keys.keep();
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
								{ links.map( ( link ) => (
									<Button
										key={ link.key }
										variant="secondary"
										size="sm"
										href={ link.href }
										onClick={ keys.keep }
									>
										{ link.label }
									</Button>
								) ) }
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
									disabled={ keys.busy }
								>
									{ __( 'Use a key instead', 'saddle' ) }
								</Button>
							</p>
						) }
						{ ! byAddress &&
							activeApp.viaAddress &&
							'address' === modeFor( activeApp, true ) && (
								<p className="saddle-wizard__hint">
									{ __(
										'This app can also connect with the address and a sign-in screen.',
										'saddle'
									) }{ ' ' }
									<Button
										variant="link"
										onClick={ switchToAddress }
									>
										{ __(
											'Use the address instead',
											'saddle'
										) }
									</Button>
								</p>
							) }

						{ IS_LOCAL && ! unreachable && (
							<p className="saddle-wizard__hint">
								{ __(
									'This site runs on a local address, so the app must run on this same computer to reach it.',
									'saddle'
								) }
							</p>
						) }

						{ renderWaiting &&
							( ! byAddress || oauthState?.enabled ) &&
							renderWaiting( {
								app,
								appLabel: activeApp.label,
								keyId: cred ? `key:${ cred.uuid }` : null,
								connected: markConnected,
								onSlow: () => setPatienceUp( true ),
							} ) }

						{ /* Troubleshooting, once the wait has run long: only
						     the checks that matter for this path (R2). */ }
						{ patienceUp && (
							<SelfCheck
								app={ app }
								path={ mode }
								ssl={
									oauthState
										? !! oauthState.ssl
										: !! saddleData.ssl
								}
								local={ IS_LOCAL }
								permalinks={
									oauthState ? oauthState.permalinks : true
								}
								onUseKey={
									byAddress && activeApp.viaKey
										? switchToKey
										: null
								}
								onSkip={ exit }
							/>
						) }

						<div className="saddle-wizard__actions">
							<Button variant="ghost" onClick={ backToPick }>
								{ __( 'Back', 'saddle' ) }
							</Button>
						</div>
					</div>
				) }
		</div>
	);
}
