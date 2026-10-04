/**
 * AI apps → "Connect an app": the picker, in a drawer (#285).
 *
 * The tiles, grouped by where you use the AI; then, in their place, the
 * short steps for the one you pick; then a line that says the moment it
 * connects. It is the site's own way: the address is this site's, and the
 * app is approved on this site's own screen. Nothing goes through anyone
 * else's server.
 *
 * Both ways to connect read the same (#312, 1.5.0 S7): the app's name and
 * "All apps" on one row, a muted line of fact where one applies, one line
 * per step, and the waiting line.
 *
 * - **Address.** Needs "sign-in for apps" (off by default; Settings has the
 *   switch). The app gets the address and the owner approves it here.
 * - **Key.** Sign-in is off, the app can't sign in, or the owner chose
 *   "Use a key instead". "Make a key for X" makes it here, in the drawer,
 *   and the steps carry it. A key nobody copied is deleted when the owner
 *   leaves: All apps, the address instead, closing the drawer, or a reload.
 *
 * A web app (Claude, ChatGPT, Grok) can't reach a site on this computer, so
 * there the drawer leads with the apps that run here (P7).
 *
 * Until the sign-in setting is known, an app that can use either path shows
 * a spinner rather than a path it may not take. Until the connections are
 * known, the tiles wait too, so no "Connected" dot pops in late (R10).
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	CodeBlock,
	CopyButton,
	KeyValueList,
	Notice,
	Skeleton,
	Spinner,
	StatusDot,
	useAsyncCache,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import {
	APPS,
	APP_GROUPS,
	IS_LOCAL,
	LOCAL_APPS,
	MCP_URL,
	SLUG,
	WEB_APPS,
	buildConfig,
	connectPath,
	existingKey,
	forgetPendingKey,
	howFor,
	installLinks,
	pendingKey,
	rememberPendingKey,
	unreachableHere,
} from '../connect-apps';
import { AppLogo, appKeyFromLabel } from './icons';
import { areaUrl, withArg } from '../routes';
import { hasBeenUsed, usedAppKeys } from '../apps-logic';

// Every 3 seconds while the tab is visible, for up to 3 minutes.
const PULSE_MS = 3000;
const PULSE_LIMIT_MS = 3 * 60 * 1000;

// One cache for GET /connections: the AI apps list fills it, so the drawer
// opens with the right dots at once.
const CONNECTIONS = 'saddle-connections';

const loadConnections = () =>
	api( 'connections' ).then( ( res ) => res.connections || [] );

/**
 * GET /connections through the shared cache.
 *
 * @return {Object} useAsyncCache's `{ data, phase, refresh, mutate }`.
 */
export function useConnections() {
	return useAsyncCache( CONNECTIONS, loadConnections );
}

const labelFor = ( key ) => {
	const app = APPS.find( ( a ) => a.key === key );
	return app ? app.label : '';
};

/* ------------------------------------------------------- making a key */

/**
 * Remove the key an earlier load made and never copied (R1): a reload runs
 * no clean-up, so the next screen does it. A key an app has used since
 * stays.
 *
 * @param {string}   uuid    The pending key, or ''.
 * @param {Function} onSwept Called once a key was removed.
 */
function sweepPendingKey( uuid, onSwept ) {
	if ( ! uuid ) {
		return;
	}
	api( 'clients' )
		.then( ( res ) => {
			const key = ( res.clients || [] ).find( ( c ) => c.uuid === uuid );
			if ( ! key || key.last_used ) {
				forgetPendingKey( uuid );
				return null;
			}
			return api( `clients/${ uuid }`, { method: 'DELETE' } ).then(
				() => {
					forgetPendingKey( uuid );
					onSwept();
				}
			);
		} )
		.catch( () => {} );
}

/**
 * A key made on this screen: make one, replace an app's old one, keep it
 * once its setup leaves the page, and delete it on the way out when it
 * never did. Shared by the connect drawer and the welcome.
 *
 * @param {Function} onChanged Called when a key is made or removed.
 * @return {Object} `{ made, busy, error, stale, make, replace, keep,
 *         discard, clearError }`. `made` is `{ uuid, password, label }` or
 *         null; `stale` is the uuid a previous load left behind, which is
 *         being removed and is never offered as "already has a key".
 */
export function useKeyMaker( onChanged ) {
	const [ made, setMade ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const latest = useRef( { made: null, kept: false } );
	// False once the screen is gone: a key that arrives after that is
	// deleted at once, since nobody can see or copy it.
	const alive = useRef( true );
	const changed = useRef( onChanged );
	changed.current = onChanged;
	const stale = useRef( null );
	if ( null === stale.current ) {
		stale.current = pendingKey();
	}

	const notify = () => {
		if ( changed.current ) {
			changed.current();
		}
	};

	// Delete the key made here unless it was kept. `keepalive` lets the
	// request finish while the page closes.
	const discard = ( keepalive = false ) => {
		const { made: key, kept } = latest.current;
		latest.current = { made: null, kept: false };
		setMade( null );
		if ( ! key || kept ) {
			return;
		}
		forgetPendingKey( key.uuid );
		api( `clients/${ key.uuid }`, {
			method: 'DELETE',
			...( keepalive ? { keepalive: true } : {} ),
		} )
			.then( notify )
			.catch( () => {} );
	};

	const run = ( request ) => {
		setBusy( true );
		setError( null );
		return request()
			.then( ( res ) => {
				if ( ! alive.current ) {
					api( `clients/${ res.uuid }`, { method: 'DELETE' } )
						.then( notify )
						.catch( () => {} );
					return null;
				}
				latest.current = { made: res, kept: false };
				rememberPendingKey( res.uuid );
				setMade( res );
				notify();
				return res;
			} )
			.catch( ( e ) => {
				setError( e.message );
				return null;
			} )
			.finally( () => setBusy( false ) );
	};

	// A new key for the app, under its name.
	const make = ( label ) => {
		discard();
		return run( () =>
			api( 'clients', { method: 'POST', data: { name: label } } )
		);
	};

	// The app's old key stops at once, and a new one takes its name.
	const replace = ( uuid ) => {
		discard();
		return run( () =>
			api( `clients/${ uuid }/rotate`, { method: 'POST' } )
		);
	};

	// The setup left the page (copied, or opened in the app), or the app
	// used the key: it stays from now on.
	const keep = () => {
		if ( latest.current.made && ! latest.current.kept ) {
			latest.current.kept = true;
			forgetPendingKey( latest.current.made.uuid );
		}
	};

	useEffect( () => {
		alive.current = true;
		sweepPendingKey( stale.current, notify );
		const onHide = () => discard( true );
		window.addEventListener( 'pagehide', onHide );
		return () => {
			alive.current = false;
			window.removeEventListener( 'pagehide', onHide );
			discard();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, for the life of the screen.
	}, [] );

	return {
		made,
		busy,
		error,
		stale: stale.current,
		make,
		replace,
		keep,
		discard,
		clearError: () => setError( null ),
	};
}

/**
 * Watch for an app to connect while `watch` is set.
 *
 * @param {string}   watch       'address' for any new connection, a
 *                               `key:<uuid>` id for that key only, or ''
 *                               to stop.
 * @param {Function} onConnected Called with the connection row.
 * @return {Object|null} The connection that arrived.
 */
function usePulse( watch, onConnected ) {
	const [ arrived, setArrived ] = useState( null );
	const done = useRef( onConnected );
	done.current = onConnected;

	useEffect( () => {
		setArrived( null );
		if ( ! watch ) {
			return undefined;
		}
		let alive = true;
		const started = Date.now();
		// The server's own clock: a first call with a future `since` returns
		// nothing but `now`, which every later call compares against.
		let since = null;
		const fits = ( c ) =>
			'address' === watch ? c.first_seen_at > since : c.id === watch;

		const tick = () => {
			if ( ! alive || document.hidden ) {
				return;
			}
			const after = null === since ? 9999999999 : since;
			api( `connections/pulse?since=${ after }` )
				.then( ( res ) => {
					if ( ! alive ) {
						return;
					}
					if ( null === since ) {
						since = res.now;
						return;
					}
					const fresh = ( res.connections || [] ).find( fits );
					if ( fresh ) {
						setArrived( fresh );
						alive = false;
						if ( done.current ) {
							done.current( fresh );
						}
					}
				} )
				.catch( () => {} );
		};

		tick();
		const timer = window.setInterval( () => {
			if ( Date.now() - started > PULSE_LIMIT_MS ) {
				window.clearInterval( timer );
				return;
			}
			tick();
		}, PULSE_MS );

		return () => {
			alive = false;
			window.clearInterval( timer );
		};
	}, [ watch ] );

	return arrived;
}

/**
 * One app tile: logo, name, and a green dot once the app has connected.
 *
 * @param {Object}   props
 * @param {string}   props.app       App key.
 * @param {boolean}  props.connected The app has connected and been used.
 * @param {Function} props.onPick    Called with the app key.
 */
function AppTile( { app, connected = false, onPick } ) {
	return (
		<button
			type="button"
			className="saddle-connect__app"
			onClick={ () => onPick( app ) }
		>
			<AppLogo app={ app } />
			<span className="saddle-connect__app-label">
				{ labelFor( app ) }
			</span>
			{ connected && (
				<StatusDot
					tone="success"
					aria-label={ __( 'Connected', 'saddle' ) }
				/>
			) }
		</button>
	);
}

/**
 * The app tiles, in three groups (chat apps, agents, code editors), and
 * "Any MCP app" as a quiet link under them.
 *
 * @param {Object}   props
 * @param {Function} props.onPick    Called with an app key.
 * @param {Set}      props.connected App keys that have connected and been
 *                                   used (a key never used does not count).
 * @param {boolean}  props.loading   Which apps are connected is not known
 *                                   yet: the tiles wait as placeholders.
 */
export function AppGrid( { onPick, connected = new Set(), loading = false } ) {
	return (
		<div
			id="saddle-connect-apps"
			className="saddle-connect__groups"
			aria-busy={ loading || undefined }
		>
			{ APP_GROUPS.map( ( g ) => (
				<div key={ g.key } className="saddle-connect__group">
					<h3
						className="saddle-connect__group-title"
						id={ `saddle-apps-${ g.key }` }
					>
						{ g.label }
					</h3>
					<div
						className="saddle-connect__apps"
						role="group"
						aria-labelledby={ `saddle-apps-${ g.key }` }
					>
						{ g.apps.map( ( key ) =>
							loading ? (
								<Skeleton
									key={ key }
									className="saddle-connect__app-skeleton"
									height={ 36 }
									delay
								/>
							) : (
								<AppTile
									key={ key }
									app={ key }
									connected={ connected.has( key ) }
									onPick={ onPick }
								/>
							)
						) }
					</div>
				</div>
			) ) }
			<p className="saddle-connect__other">
				<Button variant="link" onClick={ () => onPick( 'other' ) }>
					{ __( 'Another MCP app', 'saddle' ) }
				</Button>
			</p>
		</div>
	);
}

/**
 * The waiting line under the steps: a spinner and "Waiting for X to
 * connect…", then a green dot and "X connected (what it calls itself)".
 *
 * @param {Object}      props
 * @param {Object}      props.app     The APPS entry.
 * @param {Object|null} props.arrived The connection that arrived.
 */
function WaitingFor( { app, arrived } ) {
	return (
		<p className="saddle-connect__waiting" role="status">
			{ arrived ? (
				<>
					<StatusDot tone="success" />
					{ sprintf(
						/* translators: 1: app name, 2: what the app calls itself, e.g. claude-ai 0.1.0. */
						__( '%1$s connected (%2$s)', 'saddle' ),
						labelFor( arrived.app ) || arrived.name || app.label,
						arrived.client || arrived.name
					) }
				</>
			) : (
				<>
					<span className="saddle-connect__spinner" />
					{ sprintf(
						/* translators: %s: app name, such as Claude. */
						__( 'Waiting for %s to connect…', 'saddle' ),
						app.label
					) }
				</>
			) }
		</p>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.oauth        The sign-in settings (SignInCard's
 *                                      hook), or null while they load.
 * @param {Function} props.onConnected  Called when the apps change: one
 *                                      connected, or a key made here was
 *                                      made or removed.
 * @param {string}   props.initialApp   An app to start on (`&add=<app>`).
 * @param {boolean}  props.startWithKey Make a key for `initialApp` at once
 *                                      (the key setup's own address,
 *                                      `&key=<app>`).
 */
export default function ConnectApps( {
	oauth,
	onConnected,
	initialApp = null,
	startWithKey = false,
} ) {
	// Nothing is picked until the owner picks: no steps, warnings or code yet.
	const [ selected, setSelected ] = useState(
		APPS.some( ( a ) => a.key === initialApp ) ? initialApp : null
	);
	// The owner chose "Use a key instead" for this app.
	const [ preferKey, setPreferKey ] = useState(
		startWithKey && APPS.some( ( a ) => a.key === initialApp )
	);
	// A key for this app already exists: replace it, or add another.
	const [ duplicate, setDuplicate ] = useState( null );

	const conns = useConnections();
	const connections = Array.isArray( conns.data ) ? conns.data : [];
	const known = 'loading' !== conns.phase;

	const keys = useKeyMaker( () => {
		conns.refresh();
		if ( onConnected ) {
			onConnected();
		}
	} );

	const app = APPS.find( ( a ) => a.key === selected ) || null;
	const signInOn = !! ( oauth && oauth.enabled );
	// The sign-in setting is still loading: which path this app takes is
	// not known yet, so neither is offered.
	const deciding = !! app && ! oauth && app.viaAddress;
	const unreachable = unreachableHere( app, IS_LOCAL );
	const path = connectPath( app, {
		signIn: oauth,
		local: IS_LOCAL,
		prefer: preferKey ? false : null,
	} );
	const byAddress = !! app && ! deciding && 'address' === path;
	const steps = byAddress && signInOn && ! unreachable;
	const made = keys.made;

	// What the waiting line watches: the key made here, else any new
	// sign-in while the address steps show.
	let watch = '';
	if ( made ) {
		watch = `key:${ made.uuid }`;
	} else if ( steps ) {
		watch = 'address';
	}
	const arrived = usePulse( watch, () => {
		// The app used the key: it stays, whatever happens next.
		keys.keep();
		conns.refresh();
		if ( onConnected ) {
			onConnected();
		}
	} );

	// The sign-in switch lives in Settings → Advanced, collapsed:
	// `&section=signin` opens it and scrolls to it.
	const settingsPage = areaUrl( saddleData.areas || [], 'settings' );
	const settingsUrl = settingsPage
		? withArg( settingsPage, 'section', 'signin' )
		: '';

	// A key made but never used has not connected: no green dot for it.
	const connectedApps = usedAppKeys( connections );

	// Make a key for the picked app, unless it already has one: then ask.
	const makeKey = () => {
		const old = existingKey(
			connections.filter( ( c ) => c.id !== `key:${ keys.stale }` ),
			app.key,
			appKeyFromLabel
		);
		if ( old ) {
			setDuplicate( old );
			return;
		}
		keys.make( app.label );
	};

	// `&key=<app>`: the key is the reason the owner is here. Wait for the
	// connections, so an old key for the app is offered first.
	const started = useRef( false );
	useEffect( () => {
		if ( startWithKey && app && known && ! started.current ) {
			started.current = true;
			makeKey();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, when the connections are known.
	}, [ known ] );

	const pick = ( key ) => {
		keys.discard();
		setDuplicate( null );
		setPreferKey( false );
		setSelected( key );
	};

	const switchToKey = () => {
		setPreferKey( true );
		makeKey();
	};

	const switchToAddress = () => {
		keys.discard();
		setDuplicate( null );
		setPreferKey( false );
	};

	if ( ! app ) {
		return (
			<div className="saddle-connect" id="saddle-connect">
				<AppGrid
					onPick={ pick }
					connected={ connectedApps }
					loading={ ! known }
				/>
			</div>
		);
	}

	// Why this app connects with a key, while the owner hasn't chosen one.
	let why = null;
	if ( oauth && ! signInOn && app.viaAddress && ! unreachable ) {
		if ( app.viaKey ) {
			why = oauth.ready
				? sprintf(
						/* translators: %s: app name, such as Claude. */
						__(
							'Sign-in for apps is off, so %s connects with a key.',
							'saddle'
						),
						app.label
				  )
				: sprintf(
						/* translators: %s: app name, such as Claude. */
						__(
							'Sign-in for apps needs HTTPS and pretty permalinks, so %s connects with a key.',
							'saddle'
						),
						app.label
				  );
		} else {
			why = oauth.ready
				? sprintf(
						/* translators: %s: app name, such as ChatGPT. */
						__(
							'%s connects only through sign-in for apps, which is off.',
							'saddle'
						),
						app.label
				  )
				: sprintf(
						/* translators: %s: app name, such as ChatGPT. */
						__(
							'%s connects only through sign-in for apps, which needs HTTPS and pretty permalinks.',
							'saddle'
						),
						app.label
				  );
		}
	}

	const keyPath = !! app && ! deciding && 'key' === path;
	const keyButton = ( variant ) =>
		saddleData.appPasswords === false ? (
			<p className="saddle-connect__hint">
				{ saddleData.ssl
					? __(
							'Application Passwords seem to be off, often because of a security plugin. Turn them on under Users → Profile before connecting.',
							'saddle'
					  )
					: __(
							'WordPress turns off app connections on sites without HTTPS, such as http://localhost. They work once the site uses HTTPS.',
							'saddle'
					  ) }
			</p>
		) : (
			<div className="saddle-connect__key">
				<Button
					variant={ variant }
					onClick={ makeKey }
					loading={ keys.busy }
					// Until the connections are known, an old key for the app
					// can't be offered first.
					disabled={ keys.busy || ! known }
				>
					{ sprintf(
						/* translators: %s: app name, such as Cursor. */
						__( 'Make a key for %s', 'saddle' ),
						app.label
					) }
				</Button>
			</div>
		);

	return (
		<div className="saddle-connect" id="saddle-connect">
			<div className="saddle-connect__steps">
				<div className="saddle-connect__steps-head">
					<h3 className="saddle-connect__steps-title">
						<AppLogo app={ app.key } />
						{ app.label }
					</h3>
					<Button
						variant="link"
						size="sm"
						onClick={ () => pick( null ) }
					>
						{ __( 'All apps', 'saddle' ) }
					</Button>
				</div>

				{ deciding && (
					<p className="saddle-connect__waiting">
						<Spinner label={ __( 'Loading', 'saddle' ) } />
					</p>
				) }

				{ keys.error && (
					<Notice tone="danger" onDismiss={ keys.clearError }>
						{ keys.error }
					</Notice>
				) }

				{ /* A web app and a site on this computer: lead with the
				     apps that can reach it (P7). */ }
				{ unreachable && ! deciding && ! made && ! duplicate && (
					<>
						<p className="saddle-connect__hint">
							{ sprintf(
								/* translators: %s: app name, such as Claude. */
								__(
									'%s runs on its own servers, so it can’t reach a site on this computer.',
									'saddle'
								),
								app.label
							) }
						</p>
						<div className="saddle-connect__group">
							<h4
								className="saddle-connect__group-title"
								id="saddle-apps-here"
							>
								{ __( 'On this computer', 'saddle' ) }
							</h4>
							<div
								className="saddle-connect__apps"
								role="group"
								aria-labelledby="saddle-apps-here"
							>
								{ LOCAL_APPS.map( ( key ) => (
									<AppTile
										key={ key }
										app={ key }
										connected={ connectedApps.has( key ) }
										onPick={ pick }
									/>
								) ) }
							</div>
						</div>
						{ app.viaKey && (
							<>
								<p className="saddle-connect__hint">
									{ sprintf(
										/* translators: %s: app name, such as Claude. */
										__(
											'The %s desktop app can reach it through a small bridge.',
											'saddle'
										),
										app.label
									) }
								</p>
								{ keyButton( 'secondary' ) }
							</>
						) }
					</>
				) }

				{ why && ! made && ! duplicate && (
					<p className="saddle-connect__hint">
						{ why }
						{ oauth.ready && settingsUrl && (
							<>
								{ ' ' }
								<a href={ settingsUrl }>
									{ __( 'Turn it on', 'saddle' ) }
								</a>
							</>
						) }
					</p>
				) }

				{ /* ---------- The address ---------- */ }
				{ steps && (
					<ol className="saddle-connect__list">
						<li>
							<AddressStep app={ app } />
						</li>
						<li>
							<p>
								{ sprintf(
									/* translators: %s: app name, such as Claude. */
									__(
										'Approve it on the screen %s opens here.',
										'saddle'
									),
									app.label
								) }
							</p>
						</li>
						<li>
							<p>{ app.next }</p>
						</li>
					</ol>
				) }

				{ /* ---------- A key: one already exists ---------- */ }
				{ keyPath && duplicate && ! made && (
					<>
						<p className="saddle-connect__hint">
							{ sprintf(
								/* translators: %s: app name, such as Claude Code. */
								__( '%s already has a key.', 'saddle' ),
								app.label
							) }{ ' ' }
							{ hasBeenUsed( duplicate )
								? __(
										'That connection has been used. Replacing its key stops the old one at once, then you paste the new setup into the app. Add a separate connection only for a second computer.',
										'saddle'
								  )
								: __(
										'That connection was never used, so its setup probably didn’t finish. Replace its key to try again without leaving an extra key behind.',
										'saddle'
								  ) }
						</p>
						<div className="saddle-connect__links">
							<Button
								variant="primary"
								onClick={ () => {
									setDuplicate( null );
									keys.replace(
										duplicate.id.replace( /^key:/, '' )
									);
								} }
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
								onClick={ () => {
									setDuplicate( null );
									keys.make( app.label );
								} }
								disabled={ keys.busy }
							>
								{ __( 'Add another connection', 'saddle' ) }
							</Button>
							<Button
								variant="link"
								onClick={ () => setDuplicate( null ) }
								disabled={ keys.busy }
							>
								{ __( 'Cancel', 'saddle' ) }
							</Button>
						</div>
					</>
				) }

				{ /* ---------- A key: not made yet ---------- */ }
				{ keyPath &&
					! unreachable &&
					! duplicate &&
					! made &&
					keyButton( 'primary' ) }

				{ /* ---------- A key: made here ---------- */ }
				{ made && (
					<ol className="saddle-connect__list">
						<li>
							<KeyStep app={ app } made={ made } keys={ keys } />
						</li>
						<li>
							<p>{ app.next }</p>
						</li>
					</ol>
				) }

				{ ( steps || made ) && (
					<WaitingFor app={ app } arrived={ arrived } />
				) }

				{ steps && app.viaKey && (
					<Button
						variant="link"
						size="sm"
						onClick={ switchToKey }
						disabled={ keys.busy }
					>
						{ __( 'Use a key instead', 'saddle' ) }
					</Button>
				) }

				{ keyPath &&
					preferKey &&
					signInOn &&
					app.viaAddress &&
					! unreachable && (
						<Button
							variant="link"
							size="sm"
							onClick={ switchToAddress }
						>
							{ __( 'Use the address instead', 'saddle' ) }
						</Button>
					) }
			</div>
		</div>
	);
}

/**
 * Step one on the address path: where the address goes, a one-click link
 * where the app has one, and the values (a form app) or the setup.
 *
 * @param {Object} props
 * @param {Object} props.app The APPS entry.
 */
function AddressStep( { app } ) {
	const config = buildConfig( app.key, null, 'address' );
	const links = installLinks( app.key, null, 'address' );
	return (
		<>
			<p>{ app.howAddress }</p>
			{ links.length > 0 && (
				<div className="saddle-connect__links">
					{ links.map( ( l ) => (
						<Button
							key={ l.key }
							variant="primary"
							size="sm"
							href={ l.href }
							target="_blank"
							rel="noreferrer"
						>
							{ l.label }
						</Button>
					) ) }
				</div>
			) }
			{ WEB_APPS.includes( app.key ) ? (
				<KeyValueList
					className="saddle-connect__values"
					layout="stacked"
					items={ [
						{
							label: __( 'Name', 'saddle' ),
							value: <code>{ SLUG }</code>,
						},
						{
							label: __( 'Address', 'saddle' ),
							value: (
								<>
									<code>{ MCP_URL }</code>
									<CopyButton
										value={ MCP_URL }
										variant="link"
										size="sm"
									>
										{ __( 'Copy', 'saddle' ) }
									</CopyButton>
								</>
							),
						},
						{
							label: __( 'Authentication', 'saddle' ),
							value: __(
								'OAuth, client ID and secret blank',
								'saddle'
							),
						},
					] }
				/>
			) : (
				config && (
					<CodeBlock
						className="saddle-connect__config"
						code={ config }
					/>
				)
			) }
		</>
	);
}

/**
 * Step one on the key path: where the setup goes, a one-click link where
 * the app has one, and the setup with the key in it. Copying it, or opening
 * a link, keeps the key.
 *
 * @param {Object} props
 * @param {Object} props.app  The APPS entry.
 * @param {Object} props.made The key: `{ uuid, password, label }`.
 * @param {Object} props.keys useKeyMaker()'s result.
 */
function KeyStep( { app, made, keys } ) {
	const config = buildConfig( app.key, made.password, 'key' );
	const links = installLinks( app.key, made.password, 'key' );
	return (
		<>
			<p>{ howFor( app, 'key', IS_LOCAL ) }</p>
			{ links.length > 0 && (
				<div className="saddle-connect__links">
					{ links.map( ( l ) => (
						<Button
							key={ l.key }
							variant="primary"
							size="sm"
							href={ l.href }
							onClick={ keys.keep }
						>
							{ l.label }
						</Button>
					) ) }
				</div>
			) }
			<CodeBlock
				className="saddle-connect__config"
				code={ config }
				// The kit's copy button, or the owner's own copy of the text.
				onClick={ ( e ) =>
					e.target.closest &&
					e.target.closest( '.pp-code__copy' ) &&
					keys.keep()
				}
				onCopy={ keys.keep }
			/>
			<p className="saddle-connect__hint">
				{ __(
					'The key is shown once. If you leave without copying it, Saddle deletes it.',
					'saddle'
				) }
			</p>
		</>
	);
}
