/**
 * What to look at when an app has not connected after two minutes in the
 * welcome (#277).
 *
 * The likely causes, in reading order, for the path in use (R2): on the
 * address path HTTPS, permalinks, a local site (web apps only) and a ChatGPT
 * plan note; on both paths whether the Authorization header reaches PHP
 * (with the one-click fix) and a firewall. Then the two ways out: a key, or
 * skip for now.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, CalloutCard, ChecklistItem } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { selfCheckFindings } from '../onboarding-logic';
import ConnectionHealth from './ConnectionHealth';

const stripped = ( status ) =>
	'auth_header_stripped' === status || 'bearer_header_stripped' === status;

/**
 * @param {Object}    props
 * @param {string}    props.app        App key.
 * @param {string}    props.path       'address' or 'key'.
 * @param {boolean}   props.ssl        The site counts as secure for sign-in.
 * @param {boolean}   props.local      The site looks like a local one.
 * @param {boolean}   props.permalinks Pretty permalinks are on.
 * @param {?Function} props.onUseKey   "Use a key instead", or null when the
 *                                     app cannot take one.
 * @param {Function}  props.onSkip     "Skip for now".
 */
export default function SelfCheck( {
	app,
	path = 'address',
	ssl = !! saddleData.ssl,
	local,
	permalinks,
	onUseKey,
	onSkip,
} ) {
	const [ status, setStatus ] = useState( null );

	useEffect( () => {
		let alive = true;
		api( 'self-check' )
			.then( ( res ) => alive && setStatus( res.status || 'unknown' ) )
			.catch( () => alive && setStatus( 'unknown' ) );
		return () => {
			alive = false;
		};
	}, [] );

	const rows = selfCheckFindings( {
		ssl,
		permalinks,
		local,
		app,
		authHeader: status,
		path,
	} );

	return (
		<CalloutCard
			className="saddle-selfcheck"
			tone="warning"
			title={ __( 'Still waiting', 'saddle' ) }
			description={ __(
				'Nothing has reached this site from the app yet.',
				'saddle'
			) }
		>
			<div className="saddle-selfcheck__rows">
				{ rows.map( ( row ) => (
					<ChecklistItem
						key={ row.key }
						status={ true === row.ok ? 'done' : 'todo' }
						label={ row.label }
						hint={ row.hint || undefined }
					/>
				) ) }
			</div>

			{ stripped( status ) && <ConnectionHealth /> }

			<p className="saddle-wizard__hint">
				{ __(
					'The app connects only when you use it. Once you add Saddle, ask it something about your site.',
					'saddle'
				) }
			</p>

			<div className="saddle-wizard__actions">
				{ onUseKey && (
					<Button variant="secondary" onClick={ onUseKey }>
						{ __( 'Use a key instead', 'saddle' ) }
					</Button>
				) }
				<Button variant="ghost" onClick={ onSkip }>
					{ __( 'Skip for now', 'saddle' ) }
				</Button>
			</div>
		</CalloutCard>
	);
}
