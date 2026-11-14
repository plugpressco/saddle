/**
 * How an AI agent connects: one way in, through a SaddleWP account (#432,
 * Saddle 2.0; Fahim, 2026-11-13: "main onboarding now focus connect saddle
 * cloud service", "single site connection we try hide"). From 2026-11-06 to
 * then it was two cards of the same size.
 *
 * - **Your SaddleWP account:** the Connect this site button. One click turns
 *   on sign-in for agents on this site (the line under the button says so;
 *   sign-in stays off until an owner clicks) and opens the account's Connect
 *   page in a new tab, this site's address filled in (non-negotiable #1's
 *   Cloud amendment). Saddle sends nothing to the account; the owner signs
 *   up there, then this site shows its own Allow screen. Once the account
 *   is connected, the card says so instead.
 * - **Connect directly:** the site's own address, through the per-agent
 *   steps (ConnectApps on Agent setup, the agent picker in the welcome). In
 *   the welcome a small link, on Agent setup a closed row.
 *
 * Where an account cannot reach the site (on this computer, Plain
 * permalinks, no HTTPS: accountReach()), the card says why in one line and
 * offers the one thing that helps instead.
 *
 * At the top of Agent setup, and as the welcome's connect step. Agents are
 * never told about it.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, Notice, VisuallyHidden } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { cloudConnectUrl, connectedToCloud } from '../get-pro-logic';
import { IS_LOCAL } from '../connect-apps';
import { accountReach, isBrowserPreview } from '../onboarding-logic';
import { NavIcon } from '../icons/iconoir';
import { icons } from '../icons/kit';
import { AppLogo } from './icons';

/**
 * Whether Saddle Cloud is connected to this site: undefined while it asks,
 * then true or false. A failed first look counts as not connected.
 *
 * @param {boolean} watch Look again each time the owner comes back to this
 *                        tab (after opening Saddle Cloud in another one).
 * @return {boolean|undefined} Connected.
 */
export function useCloudConnected( watch = false ) {
	const [ connected, setConnected ] = useState( undefined );
	useEffect( () => {
		let alive = true;
		let busy = false;
		const look = () => {
			if ( busy ) {
				return;
			}
			busy = true;
			api( 'connections' )
				.then(
					( res ) =>
						alive &&
						setConnected( connectedToCloud( res.connections ) )
				)
				// A failed second look keeps what the first one found.
				.catch(
					() =>
						alive &&
						setConnected( ( was ) =>
							undefined === was ? false : was
						)
				)
				.finally( () => {
					busy = false;
				} );
		};
		const back = () => {
			if ( ! document.hidden ) {
				look();
			}
		};
		look();
		if ( watch ) {
			window.addEventListener( 'focus', back );
			document.addEventListener( 'visibilitychange', back );
		}
		return () => {
			alive = false;
			window.removeEventListener( 'focus', back );
			document.removeEventListener( 'visibilitychange', back );
		};
	}, [ watch ] );
	return connected;
}

/** The link's words for a screen reader, said once. */
export const NEW_TAB = __( '(opens in a new tab)', 'saddle' );

/** Where the button goes: the account's Connect a site page, this site filled in. */
export const cloudHref = () => cloudConnectUrl( saddleData.homeUrl );

/**
 * Whether no SaddleWP account can reach this site from the internet: on
 * this computer, or WordPress.org's preview in the visitor's own browser.
 */
const UNREACHABLE =
	IS_LOCAL || isBrowserPreview( window.location.hostname || '' );

/**
 * Whether an account can connect this site, and if not, why.
 *
 * @param {Object|null} oauth GET /oauth-settings, or null while it loads.
 * @return {string} See accountReach().
 */
export const reachOf = ( oauth ) => accountReach( oauth, UNREACHABLE );

/**
 * Turn on sign-in for agents, unless it is on (#432). The owner's click on
 * Connect this site is the labelled step, like the direct wizard's Turn on
 * sign-in button; nothing turns it on without a click.
 *
 * @param {Object|null} oauth The settings as the page knows them.
 * @return {Promise<Object>} The settings after.
 */
export function turnOnSignIn( oauth ) {
	if ( oauth && oauth.enabled ) {
		return Promise.resolve( oauth );
	}
	return api( 'oauth-settings', {
		method: 'POST',
		data: { enabled: true },
	} );
}

// The logos beside Agent setup's heading, overlapping.
const LOGOS = [ 'claude', 'chatgpt', 'cursor', 'gemini-cli' ];

/**
 * The card: its icon on a tile, its name with an optional chip, a line or
 * two, then its actions.
 *
 * @param {Object} props
 * @param {string} props.icon     An Iconoir name from the allowlist.
 * @param {string} props.tone     The tile's pastel tone.
 * @param {string} props.name     The card's name.
 * @param {*}      props.tag      A chip beside the name, or null.
 * @param {*}      props.text     The card's words.
 * @param {*}      props.children The actions.
 */
function Card( { icon, tone, name, tag = null, text, children } ) {
	return (
		<div className="saddle-way saddle-account">
			<div className="saddle-account__head">
				<span
					className={ `saddle-tile saddle-tile--${ tone }` }
					aria-hidden="true"
				>
					<NavIcon name={ icon } size={ 22 } />
				</span>
				<div className="saddle-account__intro">
					<h3 className="saddle-way__name">
						{ name }
						{ tag }
					</h3>
					<div className="saddle-way__text" aria-live="polite">
						{ text }
					</div>
				</div>
			</div>
			{ children }
		</div>
	);
}

/**
 * The account's button: a link to the account in a new tab that also turns
 * sign-in on.
 *
 * @param {Object}   props
 * @param {?Object}  props.oauth     The sign-in settings.
 * @param {Function} props.onStart   Called with the promise of sign-in.
 * @param {boolean}  props.secondary Drawn as the quieter button.
 * @param {*}        props.children  The words.
 */
export function AccountButton( {
	oauth,
	onStart,
	secondary = false,
	children,
} ) {
	return (
		<Button
			variant={ secondary ? 'secondary' : 'primary' }
			href={ cloudHref() }
			target="_blank"
			rel="noopener noreferrer"
			onClick={ () => onStart( turnOnSignIn( oauth ) ) }
		>
			{ children }
			<VisuallyHidden>{ ` ${ NEW_TAB }` }</VisuallyHidden>
		</Button>
	);
}

/**
 * "No account? Connect directly instead", the welcome's small way out.
 *
 * @param {Object}   props
 * @param {Function} props.onSite Connect directly was clicked.
 */
export function DirectLink( { onSite } ) {
	return (
		<p className="saddle-account__direct">
			{ __( 'No account?', 'saddle' ) }{ ' ' }
			<Button variant="link" onClick={ onSite }>
				{ __( 'Connect directly instead', 'saddle' ) }
			</Button>
		</p>
	);
}

/**
 * Why an account can't reach this site, and the one thing that helps.
 *
 * @param {Object}   props
 * @param {string}   props.reach    `local`, `permalinks` or `https`.
 * @param {Function} props.onSite   Connect directly was clicked.
 * @param {?boolean} props.siteOpen On Agent setup, whether the direct steps
 *                                  are open (the button then toggles them).
 * @param {boolean}  props.bare     In the welcome (draws the direct link).
 */
function NotReachable( { reach, onSite, siteOpen, bare } ) {
	if ( 'local' === reach ) {
		return (
			<Card
				icon="link"
				tone="mint"
				name={ __( 'Just this site', 'saddle' ) }
				text={
					<p>
						{ __(
							'This site runs on your computer, so a SaddleWP account can’t reach it. Connect your AI agent straight to its own address. Free, never counted.',
							'saddle'
						) }
					</p>
				}
			>
				<div className="saddle-account__action">
					<Button
						variant="primary"
						onClick={ onSite }
						aria-expanded={
							undefined === siteOpen ? undefined : siteOpen
						}
						aria-controls={
							undefined === siteOpen
								? undefined
								: 'saddle-site-connect'
						}
					>
						{ __( 'Connect directly', 'saddle' ) }
					</Button>
				</div>
			</Card>
		);
	}

	if ( 'permalinks' === reach ) {
		return (
			<Card
				icon="settings"
				tone="butter"
				name={ __( 'One setting first', 'saddle' ) }
				text={
					<p>
						{ __(
							'AI agents need pretty permalinks to sign in. Choose Post name in Settings → Permalinks, then come back.',
							'saddle'
						) }
					</p>
				}
			>
				<div className="saddle-account__action">
					<Button
						variant="primary"
						href={ saddleData.permalinksUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Open Permalinks', 'saddle' ) }
						<VisuallyHidden>{ ` ${ NEW_TAB }` }</VisuallyHidden>
					</Button>
				</div>
				{ bare && <DirectLink onSite={ onSite } /> }
			</Card>
		);
	}

	// No HTTPS: WordPress turns off Application Passwords without it too,
	// so there is no direct way to offer here either.
	return (
		<Card
			icon="lock"
			tone="butter"
			name={ __( 'HTTPS first', 'saddle' ) }
			text={
				<p>
					{ __(
						'This site has no HTTPS yet, so AI agents can’t sign in to it safely. Ask your host to turn on HTTPS, then come back.',
						'saddle'
					) }
				</p>
			}
		/>
	);
}

/**
 * The SaddleWP account card on Agent setup: the button, then what happened.
 *
 * @param {Object}   props
 * @param {?Object}  props.oauth   The sign-in settings.
 * @param {Function} props.onOauth Takes the settings after sign-in is on.
 */
function AccountCard( { oauth, onOauth } ) {
	// The owner opened the account: say what happens next, and look again
	// for its connection when they come back.
	const [ opened, setOpened ] = useState( false );
	const [ error, setError ] = useState( '' );
	const connected = useCloudConnected( opened );

	const start = ( started ) => {
		setOpened( true );
		setError( '' );
		started
			.then( onOauth )
			.catch( ( e ) =>
				setError(
					( e && e.message ) ||
						__( 'Could not turn on sign-in for agents.', 'saddle' )
				)
			);
	};

	let action = null;
	if ( true === connected ) {
		action = (
			<p className="saddle-way__done">
				<icons.CheckCircle size={ 16 } />
				{ __( 'Connected to your SaddleWP account', 'saddle' ) }
			</p>
		);
	} else if ( false === connected ) {
		action = (
			<>
				<AccountButton
					oauth={ oauth }
					onStart={ start }
					secondary={ opened }
				>
					{ opened
						? __( 'Open my SaddleWP account again', 'saddle' )
						: __( 'Connect this site', 'saddle' ) }
				</AccountButton>
				{ ! opened && (
					<p className="saddle-account__hint">
						{ oauth && oauth.enabled
							? __(
									'Opens your SaddleWP account in a new tab.',
									'saddle'
							  )
							: __(
									'This turns on sign-in for agents here, then opens your SaddleWP account in a new tab.',
									'saddle'
							  ) }
					</p>
				) }
			</>
		);
	}

	return (
		<Card
			icon="cloud"
			tone="sky"
			name={ __( 'Your SaddleWP account', 'saddle' ) }
			tag={
				<span className="saddle-chip saddle-chip--mint">
					{ __( 'Free', 'saddle' ) }
				</span>
			}
			text={
				<p>
					{ opened && true !== connected
						? __(
								'Your SaddleWP account opened in a new tab. Sign up or log in there, then approve this site when it asks.',
								'saddle'
						  )
						: __(
								'One address for Claude, ChatGPT, Cursor and other AI agents. Free for one site, up to 500 AI agent calls a month.',
								'saddle'
						  ) }
				</p>
			}
		>
			{ error && (
				<Notice tone="danger" onDismiss={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<div className="saddle-account__action">{ action }</div>
		</Card>
	);
}

/**
 * The welcome's card: the account and its button. What follows the click
 * is the welcome's own conversation.
 *
 * @param {Object}   props
 * @param {?Object}  props.oauth   The sign-in settings.
 * @param {Function} props.onCloud Called with the promise of sign-in.
 * @param {Function} props.onSite  Connect directly was clicked.
 */
function WelcomeCard( { oauth, onCloud, onSite } ) {
	return (
		<Card
			icon="cloud"
			tone="sky"
			name={ __( 'Your SaddleWP account', 'saddle' ) }
			tag={
				<span className="saddle-chip saddle-chip--mint">
					{ __( 'Free', 'saddle' ) }
				</span>
			}
			text={
				<p>
					{ __(
						'One address for Claude, ChatGPT, Cursor and other AI agents. Free for one site, up to 500 AI agent calls a month.',
						'saddle'
					) }
				</p>
			}
		>
			<div className="saddle-account__action">
				<AccountButton oauth={ oauth } onStart={ onCloud }>
					{ __( 'Connect this site', 'saddle' ) }
				</AccountButton>
				<p className="saddle-account__hint">
					{ oauth && oauth.enabled
						? __(
								'Opens your SaddleWP account in a new tab.',
								'saddle'
						  )
						: __(
								'This turns on sign-in for agents here, then opens your SaddleWP account in a new tab.',
								'saddle'
						  ) }
				</p>
			</div>
			<DirectLink onSite={ onSite } />
		</Card>
	);
}

/**
 * Agent setup's closed "Connect directly" row; it opens the per-agent steps
 * below the card.
 *
 * @param {Object}   props
 * @param {boolean}  props.open   Whether the steps are open.
 * @param {Function} props.onSite Toggles them.
 */
function DirectRow( { open, onSite } ) {
	return (
		<button
			type="button"
			className="saddle-direct"
			aria-expanded={ open }
			aria-controls="saddle-site-connect"
			onClick={ onSite }
		>
			<span className="saddle-direct__icon" aria-hidden="true">
				<NavIcon name="link" size={ 18 } />
			</span>
			<span className="saddle-direct__words">
				<span className="saddle-direct__name">
					{ __( 'Connect directly', 'saddle' ) }
				</span>
				<span className="saddle-direct__text">
					{ __(
						'Your AI agent talks to this site’s own address. No account, never counted.',
						'saddle'
					) }
				</span>
			</span>
			<span className="saddle-direct__chevron" aria-hidden="true">
				<NavIcon
					name={ open ? 'nav-arrow-down' : 'nav-arrow-right' }
					size={ 18 }
				/>
			</span>
		</button>
	);
}

/**
 * @param {Object}   props
 * @param {boolean}  props.heading  Agent setup: draw the block's heading,
 *                                  logos and the Connect directly row. The
 *                                  welcome's line says it instead.
 * @param {?Object}  props.oauth    GET /oauth-settings, or null while it loads.
 * @param {Function} props.onOauth  Takes the settings after sign-in is on.
 * @param {Function} props.onSite   Connect directly was clicked.
 * @param {Function} props.onCloud  The welcome: Connect this site was
 *                                  clicked, with the promise of sign-in.
 * @param {boolean}  props.siteOpen Agent setup: the direct steps are open.
 * @param {Function} props.onReload Reads the sign-in settings again.
 */
export default function ConnectWays( {
	heading = true,
	oauth = null,
	onOauth = () => {},
	onSite,
	onCloud,
	siteOpen = false,
	onReload = null,
} ) {
	const reach = reachOf( oauth );
	const reachable = 'ok' === reach || 'wait' === reach;

	// Back from Settings → Permalinks (or the host, for HTTPS): read the
	// settings again, so the card follows the fix without a reload.
	const fixable = 'permalinks' === reach || 'https' === reach;
	useEffect( () => {
		if ( ! fixable || ! onReload ) {
			return undefined;
		}
		const back = () => {
			if ( ! document.hidden ) {
				onReload();
			}
		};
		window.addEventListener( 'focus', back );
		document.addEventListener( 'visibilitychange', back );
		return () => {
			window.removeEventListener( 'focus', back );
			document.removeEventListener( 'visibilitychange', back );
		};
	}, [ fixable, onReload ] );

	if ( ! heading ) {
		return (
			<section
				className="saddle-ways saddle-ways--bare"
				aria-label={ __( 'Connect your AI agent', 'saddle' ) }
			>
				{ reachable ? (
					<WelcomeCard
						oauth={ oauth }
						onCloud={ onCloud }
						onSite={ onSite }
					/>
				) : (
					<NotReachable reach={ reach } onSite={ onSite } bare />
				) }
			</section>
		);
	}

	return (
		<section
			className="saddle-soft-card saddle-ways"
			aria-labelledby="saddle-ways-title"
		>
			<div className="saddle-ways__head">
				<h2 className="saddle-soft-card__title" id="saddle-ways-title">
					{ __( 'Connect your AI agent', 'saddle' ) }
				</h2>
				<span className="saddle-setup__logos" aria-hidden="true">
					{ LOGOS.map( ( key ) => (
						<span className="saddle-setup__logo" key={ key }>
							<AppLogo app={ key } />
						</span>
					) ) }
				</span>
			</div>
			{ reachable ? (
				<AccountCard oauth={ oauth } onOauth={ onOauth } />
			) : (
				<NotReachable
					reach={ reach }
					onSite={ onSite }
					siteOpen={ siteOpen }
				/>
			) }
			{ 'local' !== reach && (
				<DirectRow open={ siteOpen } onSite={ onSite } />
			) }
		</section>
	);
}
