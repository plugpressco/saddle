/**
 * What to look at when an app has not connected after three minutes (#277).
 *
 * It replaces the spinner in first run with the likely causes, in reading
 * order: HTTPS, permalinks, whether the Authorization header reaches PHP (with
 * the existing one-click fix), a local site, a ChatGPT plan note and a
 * firewall. Then the two ways out: a key, or skip for now.
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
 * @param {boolean}   props.local      The site looks like a local one.
 * @param {boolean}   props.permalinks Pretty permalinks are on.
 * @param {?Function} props.onUseKey   "Use a key instead", or null when the
 *                                     app cannot take one.
 * @param {Function}  props.onSkip     "Skip for now".
 */
export default function SelfCheck( {
	app,
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
		ssl: !! saddleData.ssl,
		permalinks,
		local,
		app,
		authHeader: status,
	} );

	return (
		<CalloutCard
			className="saddle-selfcheck"
			tone="warning"
			title={ __( 'Taking longer than expected?', 'saddle' ) }
			description={ __(
				'Nothing has reached this site from the app yet. These are the usual reasons.',
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
					'The app only connects when it is used, so ask it something about your site once you have added Saddle.',
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
