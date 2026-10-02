/**
 * Home (#309, the Dashboard until then): what the apps did, what waits for
 * the owner, and which apps are connected.
 *
 * Two columns. The main one holds "Needs your OK" while something waits, one
 * line from Saddle with the next setup step while setup is unfinished, then
 * the activity feed: every change and blocked attempt by day, filterable,
 * with "Show more". The side one lists each connected app with what it may
 * do, a warning when connections may not work, and the installed modules.
 * The AI switch is in the header on every page, so Home does not repeat it,
 * and there are no tabs: the feed is the page.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, Row, RowList, StatusDot } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { APPS } from '../connect-apps';
import { parseModules } from '../onboarding-logic';
import SetupBlock from './SetupBlock';
import NeedsYourOk from './NeedsYourOk';
import SectionHeader from './SectionHeader';
import Activity from './Activity';
import { ROLES } from './ConnectedClients';
import { AppLogo, appKeyFromLabel } from './icons';

// Session cache for the connection self-check so re-opening Home doesn't
// re-run the loopback probe. Reset on full reload, which is the right
// granularity.
let healthCache = null;

/**
 * A connection's name as the owner knows it: the app Saddle recognised, else
 * the name it was connected under, else what the app calls itself.
 *
 * @param {Object} c A row from GET /connections.
 * @return {string} Label.
 */
function connectionLabel( c ) {
	const app = APPS.find( ( a ) => a.key === c.app );
	return ( app && app.label ) || c.name || c.client;
}

/**
 * What a connection may do, in the AI apps page's words.
 *
 * @param {Object} c A row from GET /connections.
 * @return {string} "Read only", "Edit content" or "Manage the site", or ''.
 */
function roleLabel( c ) {
	const role = ROLES.find( ( r ) => r.key === c.role );
	return role ? role.label : '';
}

const STATE_TONE = {
	ready: 'success',
	'needs-setup': 'warning',
	attention: 'warning',
	off: 'neutral',
};

export default function Home( {
	tier,
	clients,
	caps,
	onNavigate,
	onConnect,
	onboarding,
	onHideSetup,
	homeUrl,
} ) {
	// Keys and address (OAuth) connections, from the connection registry.
	// `false` means the route failed: fall back to the keys the app already
	// loaded rather than claim there are none.
	const [ connections, setConnections ] = useState( null );
	// Every page and module, fetched once for the next step and Modules.
	const [ areas, setAreas ] = useState( null );
	const [ health, setHealth ] = useState( healthCache );
	// Bumped after an approve or reject, so the feed shows the decision.
	const [ feedKey, setFeedKey ] = useState( 0 );

	useEffect( () => {
		api( 'connections' )
			.then( ( res ) => setConnections( res.connections || [] ) )
			.catch( () => setConnections( false ) );
	}, [] );

	useEffect( () => {
		let alive = true;
		api( 'modules' )
			.then( ( res ) => alive && setAreas( parseModules( res ) ) )
			.catch( () => alive && setAreas( [] ) );
		return () => {
			alive = false;
		};
	}, [] );

	useEffect( () => {
		if ( healthCache ) {
			return; // Already probed this session.
		}
		// Runs the loopback header probe once; Home never waits on it.
		api( 'self-check' )
			.then( ( res ) => {
				healthCache = { status: res.status || 'unknown' };
				setHealth( healthCache );
			} )
			.catch( () => {
				healthCache = { status: 'unknown' };
				setHealth( healthCache );
			} );
	}, [] );

	const apps =
		false === connections
			? clients.map( ( c ) => ( {
					id: c.uuid,
					name: c.label || c.name,
			  } ) )
			: connections;

	// Whether connected apps can reach the site. A stripped X-WP-Nonce header
	// only affects this screen's own requests, which Saddle already works
	// around — so it counts as healthy here.
	const healthProblem =
		health &&
		health.status !== 'ok' &&
		health.status !== 'unknown' &&
		health.status !== 'nonce_header_stripped';

	const modules = ( areas || [] ).filter( ( a ) => 'module' === a.kind );
	const toApps = () => onNavigate( 'connect' );

	return (
		<div className="saddle-home">
			<div className="saddle-home__main">
				{ /* Big changes an app asked for, waiting for the owner (#287).
				     Draws nothing when there are none. */ }
				<NeedsYourOk onChange={ () => setFeedKey( ( k ) => k + 1 ) } />

				<SetupBlock
					tier={ tier }
					connections={
						Array.isArray( connections ) ? connections : null
					}
					areas={ areas }
					onboarding={ onboarding }
					homeUrl={ homeUrl }
					onConnect={ onConnect }
					onNavigate={ onNavigate }
					onHide={ onHideSetup }
				/>

				<section id="activity">
					<Activity
						key={ feedKey }
						caps={ caps }
						title={ __( 'Activity', 'saddle' ) }
					/>
				</section>
			</div>

			<aside className="saddle-home__side">
				{ healthProblem && (
					<RowList>
						<Row
							icon={ <StatusDot tone="warning" /> }
							title={ __( 'Connections may not work', 'saddle' ) }
							description={ __(
								'Your server may block the sign-in header apps need, or Application Passwords are off.',
								'saddle'
							) }
							actions={
								<Button
									variant="link"
									size="sm"
									onClick={ () =>
										onNavigate( {
											area: 'settings',
											tab: 'general',
										} )
									}
								>
									{ __( 'Check', 'saddle' ) }
								</Button>
							}
						/>
					</RowList>
				) }

				<section className="saddle-stack saddle-home__apps">
					<SectionHeader
						title={ __( 'Apps', 'saddle' ) }
						actions={
							apps && apps.length > 0 ? (
								<Button
									variant="link"
									size="sm"
									onClick={ toApps }
								>
									{ __( 'Manage', 'saddle' ) }
								</Button>
							) : null
						}
					/>
					<RowList loading={ null === apps } loadingRows={ 1 }>
						{ ( apps || [] ).map( ( c ) => (
							<Row
								key={ c.id }
								icon={
									<AppLogo
										app={
											c.app ||
											appKeyFromLabel(
												c.name || c.client
											)
										}
									/>
								}
								title={ connectionLabel( c ) }
								description={ roleLabel( c ) || undefined }
							/>
						) ) }
						{ apps && (
							<Row
								title={
									<Button
										variant="link"
										size="sm"
										onClick={ onConnect }
									>
										{ apps.length
											? __(
													'Connect another app',
													'saddle'
											  )
											: __( 'Connect an app', 'saddle' ) }
									</Button>
								}
							/>
						) }
					</RowList>
				</section>

				{ modules.length > 0 && (
					<section className="saddle-stack">
						<SectionHeader title={ __( 'Modules', 'saddle' ) } />
						<RowList>
							{ modules.map( ( m ) => (
								<Row
									key={ m.key }
									icon={
										m.state ? (
											<StatusDot
												tone={
													STATE_TONE[ m.state ] ||
													'neutral'
												}
											/>
										) : undefined
									}
									title={ m.product || m.title }
									description={ m.line || undefined }
									actions={
										<Button
											variant="link"
											size="sm"
											href={ m.admin_url }
										>
											{ __( 'Open', 'saddle' ) }
										</Button>
									}
								/>
							) ) }
						</RowList>
					</section>
				) }
			</aside>
		</div>
	);
}
