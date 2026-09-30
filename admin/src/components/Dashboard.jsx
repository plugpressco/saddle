/**
 * Dashboard → Overview: is AI on, and what has it been doing.
 *
 * Top to bottom: a Status block (the AI access switch, the connected apps
 * and a third row only when connections are broken), the slot where "Needs
 * your OK" sits, the Setup section while it is unfinished, the five latest
 * things the apps did, and the modules when any are installed. What each app
 * may do is chosen on AI apps, not here.
 */
import { useState, useEffect } from '@wordpress/element';
import { Badge, Button, Row, RowList, StatusDot, Switch } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { APPS } from '../connect-apps';
import { parseModules, setupBlock } from '../onboarding-logic';
import SetupBlock from './SetupBlock';
import NeedsYourOk from './NeedsYourOk';
import SectionHeader from './SectionHeader';
import { actionLabel, parseEntryDate, relativeWhen } from '../activity-format';

// Rows each list shows; the full record lives on its own screen.
const ACTIVITY_ROWS = 5;

// Session cache for the connection self-check so re-opening the Dashboard doesn't
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

const STATE_TONE = {
	ready: 'success',
	'needs-setup': 'warning',
	attention: 'warning',
	off: 'neutral',
};

export default function Dashboard( {
	tier,
	clients,
	paused,
	pausing,
	onTogglePause,
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
	// Every page and module, fetched once for Setup and the Modules block.
	const [ areas, setAreas ] = useState( null );
	const [ activity, setActivity ] = useState( null );
	const [ health, setHealth ] = useState( healthCache );

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
		api( `audit-log?per_page=${ ACTIVITY_ROWS }` )
			.then( ( res ) =>
				setActivity( {
					enabled: !! res.enabled,
					entries: res.entries || [],
				} )
			)
			.catch( () => setActivity( { enabled: false, entries: [] } ) );
	}, [] );

	useEffect( () => {
		if ( healthCache ) {
			return; // Already probed this session.
		}
		// Runs the loopback header probe once; the Dashboard never waits on it.
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
	const names = ( apps || [] ).map( connectionLabel );

	// Whether connected apps can reach the site. A stripped X-WP-Nonce header
	// only affects this screen's own requests, which Saddle already works
	// around — so it counts as healthy here.
	const healthProblem =
		health &&
		health.status !== 'ok' &&
		health.status !== 'unknown' &&
		health.status !== 'nonce_header_stripped';

	const modules = ( areas || [] ).filter( ( a ) => 'module' === a.kind );
	const entries =
		activity && activity.enabled
			? activity.entries.slice( 0, ACTIVITY_ROWS )
			: [];

	// Setup's first row already offers "Connect" while it shows and nothing is
	// connected, so the status row stays quiet then.
	const setupShowing =
		Array.isArray( connections ) &&
		!! areas &&
		!! onboarding &&
		setupBlock( { connections, onboarding, tier, areas } ).visible;
	const hideConnect = setupShowing && 0 === names.length;

	const toApps = () => onNavigate( 'connect' );
	const toSettings = () => onNavigate( { area: 'settings', tab: 'general' } );

	return (
		<>
			<section className="saddle-stack">
				<RowList>
					<Row
						title={ __( 'AI access', 'saddle' ) }
						description={
							paused
								? __(
										'Paused. Every app is stopped until you turn it back on.',
										'saddle'
								  )
								: __(
										'On. Your apps can work within their access.',
										'saddle'
								  )
						}
						actions={
							<Switch
								id="saddle-ai-switch"
								checked={ ! paused }
								disabled={ pausing }
								onChange={ onTogglePause }
								aria-label={ __( 'AI access', 'saddle' ) }
							/>
						}
					/>
					{ apps && (
						<Row
							title={ __( 'Connected apps', 'saddle' ) }
							description={
								names.length
									? names.join( ', ' )
									: __( 'None yet', 'saddle' )
							}
							actions={
								hideConnect ? null : (
									<Button
										variant="link"
										size="sm"
										onClick={
											names.length ? toApps : onConnect
										}
									>
										{ names.length
											? __( 'Manage', 'saddle' )
											: __( 'Connect', 'saddle' ) }
									</Button>
								)
							}
						/>
					) }
					{ healthProblem && (
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
									onClick={ toSettings }
								>
									{ __( 'Check', 'saddle' ) }
								</Button>
							}
						/>
					) }
				</RowList>
			</section>

			{ /* Big changes an app asked for, waiting for the owner (#287). Draws
			     nothing when there are none. */ }
			<NeedsYourOk />

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

			<section className="saddle-stack">
				<SectionHeader
					title={ __( 'Recent activity', 'saddle' ) }
					actions={
						<Button
							variant="link"
							size="sm"
							onClick={ () => onNavigate( 'activity' ) }
						>
							{ __( 'View all', 'saddle' ) }
						</Button>
					}
				/>
				<RowList>
					{ entries.length > 0 ? (
						entries.map( ( e, i ) => (
							<Row
								key={ i }
								title={
									<>
										{ 'denied' === e.type && (
											<Badge tone="danger">
												{ __( 'Blocked', 'saddle' ) }
											</Badge>
										) }{ ' ' }
										{ actionLabel( e, caps ).replace(
											/^Blocked · /,
											''
										) }
									</>
								}
								actions={
									<span className="saddle-home__value">
										{ relativeWhen(
											parseEntryDate( e.date )
										) }
									</span>
								}
							/>
						) )
					) : (
						<Row
							title={ __(
								'Nothing yet. Changes your AI makes will show up here.',
								'saddle'
							) }
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
		</>
	);
}
