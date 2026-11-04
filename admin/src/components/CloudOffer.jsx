/**
 * Saddle Cloud, offered where an owner sets up agents (planning
 * PRICING-2026-11.md; non-negotiable #3 as amended 2026-11-02): one plain
 * link to Saddle Cloud with this site's address filled in, opened in a new
 * tab. Saddle sends nothing to Cloud; the owner signs up there, then this
 * site shows its own Allow screen. Gone once Cloud is connected. Agents are
 * never told about it.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, VisuallyHidden } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { cloudConnectUrl, connectedToCloud } from '../get-pro-logic';

/**
 * Whether Saddle Cloud is connected to this site: undefined while it asks,
 * then true or false. A failed look counts as not connected.
 *
 * @return {boolean|undefined} Connected.
 */
export function useCloudConnected() {
	const [ connected, setConnected ] = useState( undefined );
	useEffect( () => {
		let alive = true;
		api( 'connections' )
			.then(
				( res ) =>
					alive && setConnected( connectedToCloud( res.connections ) )
			)
			.catch( () => alive && setConnected( false ) );
		return () => {
			alive = false;
		};
	}, [] );
	return connected;
}

/** The link's words for a screen reader, said once. */
export const NEW_TAB = __( '(opens in a new tab)', 'saddle' );

/** Where the button goes: Cloud's Connect a site page, this site filled in. */
export const cloudHref = () => cloudConnectUrl( saddleData.homeUrl );

/** Agent setup's card, after the agents: for owners of more than one site. */
export default function CloudOffer() {
	const connected = useCloudConnected();
	if ( false !== connected ) {
		return null;
	}
	return (
		<section
			className="saddle-cloud-offer"
			aria-labelledby="saddle-cloud-offer"
		>
			<div className="saddle-cloud-offer__copy">
				<h2
					className="saddle-cloud-offer__title"
					id="saddle-cloud-offer"
				>
					{ __( 'More than one site?', 'saddle' ) }
				</h2>
				<p>
					{ __(
						'Saddle Cloud connects your AI agent to all of them with one address. It’s free for any number of sites, with 500 AI agent calls a month.',
						'saddle'
					) }
				</p>
				<p className="saddle-cloud-offer__note">
					{ __(
						'Calls then pass through Saddle Cloud, and this site still approves each one.',
						'saddle'
					) }
				</p>
			</div>
			<Button
				variant="secondary"
				href={ cloudHref() }
				target="_blank"
				rel="noopener noreferrer"
			>
				{ __( 'Connect with Saddle Cloud', 'saddle' ) }
				<VisuallyHidden>{ ` ${ NEW_TAB }` }</VisuallyHidden>
			</Button>
		</section>
	);
}
