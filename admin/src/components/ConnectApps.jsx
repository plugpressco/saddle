/**
 * AI apps → "Connect an app": the picker, in a drawer (#285).
 *
 * The tiles, grouped by where you use the AI; then, in their place, the
 * short steps for the one you pick; then a line that
 * says the moment it connects. It is the site's own way: the address is this
 * site's, and the app is approved on this site's own screen. Nothing goes
 * through anyone else's server. The address is one quiet line on the page
 * itself.
 *
 * The address path needs "sign-in for apps" (off by default, one labelled
 * click to turn on). Without it, or for an app that only takes a key, the
 * key setup opens in the connect wizard, which makes and manages the key.
 *
 * While an app is picked on the address path, a line watches
 * `/connections/pulse` and says the moment that app connects.
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	CodeBlock,
	CopyButton,
	KeyValueList,
	StatusDot,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import {
	APPS,
	APP_GROUPS,
	MCP_URL,
	SLUG,
	buildConfig,
	installLinks,
} from '../connect-apps';
import { AppLogo } from './icons';
import { areaUrl } from '../routes';

// A site on this computer: web apps (their servers) cannot reach it.
const IS_LOCAL = /(?:localhost|127\.0\.0\.1|\.test|\.local)(?::|\/|$)/i.test(
	MCP_URL
);

// Apps that connect from their own servers, not from this computer. They
// also take the address in a form (name, address, authentication) rather
// than a config file or a command.
const WEB_APPS = [ 'claude', 'chatgpt', 'grok' ];

// Every 3 seconds while the tab is visible, for up to 3 minutes.
const PULSE_MS = 3000;
const PULSE_LIMIT_MS = 3 * 60 * 1000;

const labelFor = ( key ) => {
	const app = APPS.find( ( a ) => a.key === key );
	return app ? app.label : '';
};

/**
 * Watch for a new connection while `active`.
 *
 * @param {boolean}  active      Whether to watch.
 * @param {Function} onConnected Called with the connection row.
 * @return {Object|null} The connection that arrived.
 */
function usePulse( active, onConnected ) {
	const [ arrived, setArrived ] = useState( null );
	const since = useRef( null );
	const done = useRef( onConnected );
	done.current = onConnected;

	useEffect( () => {
		setArrived( null );
		if ( ! active ) {
			return undefined;
		}
		let alive = true;
		const started = Date.now();
		// The server's own clock: a first call with a future `since` returns
		// nothing but `now`, which every later call compares against.
		since.current = null;

		const tick = () => {
			if ( ! alive || document.hidden ) {
				return;
			}
			const after = null === since.current ? 9999999999 : since.current;
			api( `connections/pulse?since=${ after }` )
				.then( ( res ) => {
					if ( ! alive ) {
						return;
					}
					if ( null === since.current ) {
						since.current = res.now;
						return;
					}
					const fresh = ( res.connections || [] ).find(
						( c ) => c.first_seen_at > since.current
					);
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
	}, [ active ] );

	return arrived;
}

/**
 * The app tiles, in three groups (chat apps, agents, code editors), and
 * "Any MCP app" as a quiet link under them. First run shows the same grid.
 *
 * @param {Object}   props
 * @param {Function} props.onPick    Called with an app key.
 * @param {Set}      props.connected App keys that already have a connection.
 */
export function AppGrid( { onPick, connected = new Set() } ) {
	return (
		<div id="saddle-connect-apps" className="saddle-connect__groups">
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
						{ g.apps.map( ( key ) => (
							<button
								key={ key }
								type="button"
								className="saddle-connect__app"
								onClick={ () => onPick( key ) }
							>
								<AppLogo app={ key } />
								<span className="saddle-connect__app-label">
									{ labelFor( key ) }
								</span>
								{ connected.has( key ) && (
									<StatusDot
										tone="success"
										aria-label={ __(
											'Connected',
											'saddle'
										) }
									/>
								) }
							</button>
						) ) }
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
 * @param {Object}   props
 * @param {Object}   props.oauth       The sign-in settings (SignInCard's hook),
 *                                     or null while they load. The switch
 *                                     itself is the card further down.
 * @param {Function} props.onKey       Open the key setup for an app key.
 * @param {Function} props.onConnected Called when a new app connects.
 * @param {string}   props.initialApp  An app to start on (`&add=<app>`).
 */
export default function ConnectApps( {
	oauth,
	onKey,
	onConnected,
	initialApp = null,
} ) {
	// Nothing is picked until the owner picks: no steps, warnings or code yet.
	const [ selected, setSelected ] = useState(
		APPS.some( ( a ) => a.key === initialApp ) ? initialApp : null
	);
	const [ connections, setConnections ] = useState( [] );

	const loadConnections = () =>
		api( 'connections' )
			.then( ( res ) => setConnections( res.connections || [] ) )
			.catch( () => {} );

	useEffect( () => {
		loadConnections();
	}, [] );

	const app = APPS.find( ( a ) => a.key === selected ) || null;
	const signInOn = !! ( oauth && oauth.enabled );
	const byAddress = !! app && signInOn && app.viaAddress;
	const unreachable = !! app && IS_LOCAL && WEB_APPS.includes( app.key );

	const arrived = usePulse( byAddress && ! unreachable, () => {
		loadConnections();
		if ( onConnected ) {
			onConnected();
		}
	} );

	// The sign-in switch lives in Settings → Advanced.
	const settingsUrl = areaUrl( saddleData.areas || [], 'settings' );

	const connectedApps = new Set( connections.map( ( c ) => c.app ) );

	const config = byAddress ? buildConfig( app.key, null, 'address' ) : '';
	const links = byAddress ? installLinks( app.key, null, 'address' ) : [];

	const pick = ( key ) => setSelected( key );

	return (
		<div className="saddle-connect" id="saddle-connect">
			{ ! app && <AppGrid onPick={ pick } connected={ connectedApps } /> }

			{ app && (
				<div className="saddle-connect__steps">
					<div className="saddle-connect__steps-head">
						<h3 className="saddle-connect__steps-title">
							<AppLogo app={ app.key } />
							{ app.label }
						</h3>
						<Button
							variant="link"
							size="sm"
							onClick={ () => setSelected( null ) }
						>
							{ __( 'All apps', 'saddle' ) }
						</Button>
					</div>

					{ oauth && ! signInOn && app.viaAddress && (
						<p className="saddle-connect__hint">
							{ oauth.ready
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
								  ) }
							{ oauth.ready && (
								<>
									{ ' ' }
									<a href={ settingsUrl }>
										{ __( 'Turn it on', 'saddle' ) }
									</a>
								</>
							) }
						</p>
					) }

					{ unreachable && (
						<p className="saddle-connect__hint">
							{ sprintf(
								/* translators: %s: app name, such as ChatGPT. */
								__(
									'%s runs on its own servers, so it can’t reach a site on this computer. Try Claude Code, Cursor or Codex here, or connect once the site is online.',
									'saddle'
								),
								app.label
							) }
						</p>
					) }

					{ byAddress ? (
						<ol className="saddle-connect__list">
							<li>
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
												label: __(
													'Address',
													'saddle'
												),
												value: (
													<>
														<code>{ MCP_URL }</code>
														<CopyButton
															value={ MCP_URL }
															variant="link"
															size="sm"
														>
															{ __(
																'Copy',
																'saddle'
															) }
														</CopyButton>
													</>
												),
											},
											{
												label: __(
													'Authentication',
													'saddle'
												),
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
					) : (
						<div className="saddle-connect__key">
							{ app.viaKey && (
								<Button
									variant="primary"
									onClick={ () => onKey( app.key ) }
								>
									{ sprintf(
										/* translators: %s: app name, such as Cursor. */
										__( 'Make a key for %s', 'saddle' ),
										app.label
									) }
								</Button>
							) }
						</div>
					) }

					{ byAddress && ! unreachable && (
						<p className="saddle-connect__waiting" role="status">
							{ arrived ? (
								<>
									<StatusDot tone="success" />
									{ sprintf(
										/* translators: 1: app name, 2: what the app calls itself, e.g. claude-ai 0.1.0. */
										__( '%1$s connected (%2$s)', 'saddle' ),
										labelFor( arrived.app ) ||
											arrived.name ||
											app.label,
										arrived.client || arrived.name
									) }
								</>
							) : (
								<>
									<span className="saddle-connect__spinner" />
									{ sprintf(
										/* translators: %s: app name, such as Claude. */
										__(
											'Waiting for %s to connect…',
											'saddle'
										),
										app.label
									) }
								</>
							) }
						</p>
					) }

					{ byAddress && app.viaKey && (
						<Button
							variant="link"
							size="sm"
							onClick={ () => onKey( app.key ) }
						>
							{ __( 'Use a key instead', 'saddle' ) }
						</Button>
					) }
				</div>
			) }
		</div>
	);
}
