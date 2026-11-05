/**
 * The two ways to connect an AI agent (Fahim, 2026-11-06: "give offer
 * connect saddle cloud.. then ask to connect with saddle local mcp.. 2 type
 * of mcp we have right 1 mcp for all site also induvuaial site mcp.. make it
 * cleaner to present"). Two cards of the same size, Saddle Cloud first:
 *
 * - **All your sites:** one plain link to Saddle Cloud with this site's
 *   address filled in, opened in a new tab (non-negotiable #1's Cloud
 *   amendment). Saddle sends nothing to Cloud; the owner signs up there, then
 *   this site shows its own Allow screen. Once Cloud is connected, the card
 *   says so instead.
 * - **Just this site:** the site's own address, through the per-agent steps
 *   (ConnectApps on Agent setup, the agent picker in the welcome).
 *
 * At the top of Agent setup, and as the welcome's connect step. Agents are
 * never told about it.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, VisuallyHidden } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { cloudConnectUrl, connectedToCloud } from '../get-pro-logic';
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

/** Where the button goes: Cloud's Connect a site page, this site filled in. */
export const cloudHref = () => cloudConnectUrl( saddleData.homeUrl );

// The logos beside the heading, overlapping.
const LOGOS = [ 'claude', 'chatgpt', 'cursor', 'gemini-cli' ];

/**
 * One of the two cards: its icon on a tile, its name, a line or two, and
 * its one action at the foot, so both cards' buttons line up.
 *
 * @param {Object} props
 * @param {string} props.icon     An Iconoir name from the allowlist.
 * @param {string} props.tone     The tile's pastel tone.
 * @param {string} props.name     The card's name.
 * @param {*}      props.tag      A chip beside the tile, or null.
 * @param {*}      props.children The card's words.
 * @param {*}      props.action   The button, or what replaced it.
 */
function Way( { icon, tone, name, tag = null, children, action } ) {
	return (
		<div className="saddle-way">
			<div className="saddle-way__top">
				<span
					className={ `saddle-tile saddle-tile--${ tone }` }
					aria-hidden="true"
				>
					<NavIcon name={ icon } size={ 22 } />
				</span>
				{ tag }
			</div>
			<h3 className="saddle-way__name">{ name }</h3>
			<div className="saddle-way__text" aria-live="polite">
				{ children }
			</div>
			<div className="saddle-way__action">{ action }</div>
		</div>
	);
}

/**
 * @param {Object}   props
 * @param {boolean}  props.heading Draw the block's own heading and logos
 *                                 (Agent setup). The welcome's line says it
 *                                 instead.
 * @param {Function} props.onSite  "Connect this site" was clicked.
 * @param {Function} props.onCloud The Saddle Cloud link was opened.
 */
export default function ConnectWays( { heading = true, onSite, onCloud } ) {
	// The owner opened Saddle Cloud: say what happens next, and look again
	// for its connection when they come back.
	const [ opened, setOpened ] = useState( false );
	const connected = useCloudConnected( opened );

	let cloudAction = null;
	if ( true === connected ) {
		cloudAction = (
			<p className="saddle-way__done">
				<icons.CheckCircle size={ 16 } />
				{ __( 'Connected to Saddle Cloud', 'saddle' ) }
			</p>
		);
	} else if ( false === connected ) {
		// Once opened it steps down, so what comes next (Finish in the
		// welcome) is the one primary button; it can still open Cloud again.
		cloudAction = (
			<Button
				variant={ opened ? 'secondary' : 'primary' }
				href={ cloudHref() }
				target="_blank"
				rel="noopener noreferrer"
				onClick={ () => {
					setOpened( true );
					if ( onCloud ) {
						onCloud();
					}
				} }
			>
				{ __( 'Connect with Saddle Cloud', 'saddle' ) }
				<VisuallyHidden>{ ` ${ NEW_TAB }` }</VisuallyHidden>
			</Button>
		);
	}

	const cards = (
		<div className="saddle-ways__cards">
			<Way
				icon="cloud"
				tone="sky"
				name={ __( 'All your sites', 'saddle' ) }
				tag={
					<span className="saddle-chip saddle-chip--sky">
						{ __( 'Saddle Cloud', 'saddle' ) }
					</span>
				}
				action={ cloudAction }
			>
				<p>
					{ opened && true !== connected
						? __(
								'Saddle Cloud opened in a new tab, with this site’s address filled in. Approve it on this site when it asks.',
								'saddle'
						  )
						: __(
								'One address connects Claude, ChatGPT or Cursor to every site you run. Updates in one click and uptime alerts too. Free up to 500 AI agent calls a month.',
								'saddle'
						  ) }
				</p>
			</Way>
			<Way
				icon="link"
				tone="mint"
				name={ __( 'Just this site', 'saddle' ) }
				action={
					<Button variant="secondary" onClick={ onSite }>
						{ __( 'Connect this site', 'saddle' ) }
					</Button>
				}
			>
				<p>
					{ __(
						'Connect your AI agent straight to this site’s own address. Free, never counted.',
						'saddle'
					) }
				</p>
			</Way>
		</div>
	);

	if ( ! heading ) {
		return (
			<section
				className="saddle-ways saddle-ways--bare"
				aria-label={ __( 'Connect your AI agent', 'saddle' ) }
			>
				{ cards }
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
			{ cards }
		</section>
	);
}
