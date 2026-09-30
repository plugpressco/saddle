/**
 * Connections → Apps: connect an AI app to this site (#274).
 *
 * Laid out like the hosted MCP pages people already know (one address, the
 * apps down the side, the steps for the one you pick), but it is the site's
 * own way: the address is this site's, and the app is approved on this
 * site's own screen. Nothing goes through anyone else's server.
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
	Badge,
	Button,
	CodeBlock,
	CopyButton,
	Notice,
	StatusDot,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { APPS, MCP_URL, buildConfig, installLinks } from '../connect-apps';
import { AppLogo } from './icons';

// A site on this computer: web apps (their servers) cannot reach it.
const IS_LOCAL = /(?:localhost|127\.0\.0\.1|\.test|\.local)(?::|\/|$)/i.test(
	MCP_URL
);

// Apps that connect from their own servers, not from this computer.
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
 * @param {Object}   props
 * @param {Object}   props.oauth       The sign-in settings (SignInCard's hook),
 *                                     or null while they load. The switch
 *                                     itself is the card further down.
 * @param {Function} props.onKey       Open the key setup for an app key.
 * @param {Function} props.onConnected Called when a new app connects.
 */
export default function ConnectApps( { oauth, onKey, onConnected } ) {
	const [ selected, setSelected ] = useState( 'claude' );
	const [ connections, setConnections ] = useState( [] );

	const loadConnections = () =>
		api( 'connections' )
			.then( ( res ) => setConnections( res.connections || [] ) )
			.catch( () => {} );

	useEffect( () => {
		loadConnections();
	}, [] );

	const app = APPS.find( ( a ) => a.key === selected ) || APPS[ 0 ];
	const signInOn = !! ( oauth && oauth.enabled );
	const byAddress = signInOn && app.viaAddress;
	const unreachable = IS_LOCAL && WEB_APPS.includes( app.key );

	const arrived = usePulse( byAddress && ! unreachable, () => {
		loadConnections();
		if ( onConnected ) {
			onConnected();
		}
	} );

	// There is one sign-in switch on this page, in the card below the apps.
	// This hint points the owner at it rather than repeating it.
	const showSignIn = () => {
		const card = document.getElementById( 'saddle-signin' );
		const control = document.getElementById( 'saddle-oauth-switch' );
		if ( card ) {
			card.scrollIntoView( { block: 'center' } );
		}
		if ( control ) {
			control.focus();
		}
	};

	const connectedApps = new Set( connections.map( ( c ) => c.app ) );
	const count = connections.filter( ( c ) => c.first_seen_at ).length;

	const config = byAddress ? buildConfig( app.key, null, 'address' ) : '';
	const links = byAddress ? installLinks( app.key, null, 'address' ) : [];

	return (
		<div className="saddle-connect" id="saddle-connect">
			<div className="saddle-connect__intro">
				<div className="saddle-connect__intro-text">
					<h2 className="saddle-connect__title">
						{ __( 'Work on this site from your AI app', 'saddle' ) }
						{ count > 0 ? (
							<Badge tone="success">
								{ sprintf(
									/* translators: %d: number of connected apps. */
									__( '%d connected', 'saddle' ),
									count
								) }
							</Badge>
						) : (
							<Badge>{ __( 'Not connected', 'saddle' ) }</Badge>
						) }
					</h2>
					<p className="saddle-connect__lede">
						{ __(
							'Claude, ChatGPT, Cursor and other AI apps connect with this site’s own address, and you approve each one here. Nothing goes through anyone else’s server.',
							'saddle'
						) }
					</p>
				</div>
				<div className="saddle-connect__logos" aria-hidden="true">
					{ [ 'claude', 'chatgpt', 'cursor' ].map( ( key ) => (
						<span key={ key } className="saddle-connect__logo">
							<AppLogo app={ key } />
						</span>
					) ) }
				</div>
			</div>

			<div className="saddle-connect__address">
				<span className="saddle-connect__label">
					{ __( 'This site’s MCP address', 'saddle' ) }
				</span>
				<div className="saddle-connect__url">
					<code>{ MCP_URL }</code>
					<CopyButton
						value={ MCP_URL }
						size="sm"
						variant="secondary"
					/>
				</div>
			</div>

			{ oauth && ! signInOn && (
				<Notice tone="info">
					{ oauth.ready
						? __(
								'To connect with the address alone, turn on sign-in for apps (below, under your connected apps). Each app then opens a screen here where you approve it. Until then, apps connect with a key.',
								'saddle'
						  )
						: __(
								'This site can’t use sign-in for apps yet: it needs HTTPS and a permalink setting other than Plain. Apps connect with a key instead.',
								'saddle'
						  ) }
					{ oauth.ready && (
						<span className="saddle-notice__actions">
							<Button
								variant="secondary"
								size="sm"
								onClick={ showSignIn }
							>
								{ __( 'Go to the sign-in setting', 'saddle' ) }
							</Button>
						</span>
					) }
				</Notice>
			) }

			<div className="saddle-connect__picker">
				<div
					className="saddle-connect__apps"
					role="listbox"
					aria-label={ __( 'AI apps', 'saddle' ) }
				>
					{ APPS.map( ( a ) => (
						<button
							key={ a.key }
							type="button"
							role="option"
							aria-selected={ a.key === app.key }
							className="saddle-connect__app"
							onClick={ () => setSelected( a.key ) }
						>
							<AppLogo app={ a.key } />
							<span className="saddle-connect__app-label">
								{ a.label }
							</span>
							{ connectedApps.has( a.key ) && (
								<StatusDot
									tone="success"
									aria-label={ __( 'Connected', 'saddle' ) }
								/>
							) }
						</button>
					) ) }
				</div>

				<div className="saddle-connect__steps">
					<h3 className="saddle-connect__steps-title">
						<AppLogo app={ app.key } />
						{ sprintf(
							/* translators: %s: app name, such as Claude. */
							__( 'Connect %s', 'saddle' ),
							app.label
						) }
					</h3>

					{ unreachable && (
						<Notice tone="warning">
							{ sprintf(
								/* translators: %s: app name, such as ChatGPT. */
								__(
									'%s connects from its own servers, and they can’t reach a site on this computer. Use Claude Code, Cursor or Codex here, or connect once the site is online.',
									'saddle'
								),
								app.label
							) }
						</Notice>
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
								{ config && (
									<CodeBlock
										className="saddle-connect__config"
										code={ config }
									/>
								) }
							</li>
							<li>
								<p>
									{ sprintf(
										/* translators: %s: app name, such as Claude. */
										__(
											'%s opens a screen on this site. Choose what it may do and click Allow.',
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
							<p>
								{ app.viaKey
									? sprintf(
											/* translators: %s: app name, such as Cursor. */
											__(
												'%s connects with a key here: Saddle makes one for it and shows the exact setup to paste.',
												'saddle'
											),
											app.label
									  )
									: sprintf(
											/* translators: %s: app name, such as ChatGPT. */
											__(
												'%s connects with the address only, so it needs sign-in for apps turned on.',
												'saddle'
											),
											app.label
									  ) }
							</p>
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
			</div>
		</div>
	);
}
