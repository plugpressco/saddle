/**
 * Home → Overview: what your AI can do right now, and nothing more.
 *
 * Top to bottom: a Status block (access level, AI access, connected apps, and
 * a fourth row only when connections are broken), the Setup section while it
 * is unfinished, the connected apps, the latest activity, and the modules.
 * Each block is a short list of rows with one link to where the thing is
 * changed; the full record lives on Connections and Activity.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, Row, RowList, StatusDot } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, levelFor } from '../api';
import { APPS } from '../connect-apps';
import { parseModules, setupBlock } from '../onboarding-logic';
import SetupBlock from './SetupBlock';
import SectionHeader from './SectionHeader';
import { actionLabel, parseEntryDate, relativeWhen } from '../activity-format';

// Rows each list shows; the full record lives on its own screen.
const APP_ROWS = 4;
const ACTIVITY_ROWS = 5;

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
 * "Address · 2 hours ago": how the app signs in, and when it last did
 * anything.
 *
 * @param {Object} c A row from GET /connections.
 * @return {string} One line.
 */
function connectionMeta( c ) {
	const how =
		'oauth' === c.kind ? __( 'Address', 'saddle' ) : __( 'Key', 'saddle' );
	const last = c.last_tool_at || c.last_seen_at;
	return [
		how,
		last
			? relativeWhen( new Date( last * 1000 ) )
			: __( 'Not used yet', 'saddle' ),
	].join( ' · ' );
}

const STATE_TONE = {
	ready: 'success',
	'needs-setup': 'warning',
	attention: 'warning',
	off: 'neutral',
};

/**
 * A row whose right side is a value and the link that changes it.
 *
 * @param {Object}   props
 * @param {*}        props.title    Label.
 * @param {*}        props.value    Current value.
 * @param {string}   props.link     Link text.
 * @param {boolean}  props.hideLink Show the value without the link.
 * @param {Function} props.onClick  Called when the link is used.
 */
function StatusRow( { title, value, link, hideLink, onClick } ) {
	return (
		<Row
			title={ title }
			actions={
				<>
					<span className="saddle-home__value">{ value }</span>
					{ ! hideLink && (
						<Button variant="link" size="sm" onClick={ onClick }>
							{ link }
						</Button>
					) }
				</>
			}
		/>
	);
}

export default function Dashboard( {
	tier,
	clients,
	paused,
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

	const toPermissions = () => onNavigate( 'permissions' );
	const toApps = () => onNavigate( 'connect' );

	return (
		<>
			<section className="saddle-stack">
				<RowList>
					<StatusRow
						title={ __( 'Access level', 'saddle' ) }
						value={ levelFor( tier ).title }
						link={ __( 'Change', 'saddle' ) }
						onClick={ toPermissions }
					/>
					<StatusRow
						title={ __( 'AI access', 'saddle' ) }
						value={
							paused
								? __( 'Paused', 'saddle' )
								: __( 'Active', 'saddle' )
						}
						link={ __( 'Change', 'saddle' ) }
						onClick={ toPermissions }
					/>
					{ apps && (
						<StatusRow
							title={ __( 'Connected apps', 'saddle' ) }
							value={
								names.length
									? sprintf(
											/* translators: 1: number of apps, 2: their names. */
											__( '%1$d · %2$s', 'saddle' ),
											names.length,
											names.join( ', ' )
									  )
									: __( 'None yet', 'saddle' )
							}
							link={
								names.length
									? __( 'Manage', 'saddle' )
									: __( 'Connect', 'saddle' )
							}
							hideLink={ hideConnect }
							onClick={ names.length ? toApps : onConnect }
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
									onClick={ toApps }
								>
									{ __( 'Check', 'saddle' ) }
								</Button>
							}
						/>
					) }
				</RowList>
			</section>

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

			{ Array.isArray( connections ) && connections.length > 0 && (
				<section className="saddle-stack">
					<SectionHeader
						title={ __( 'Connected apps', 'saddle' ) }
						actions={
							<Button variant="link" size="sm" onClick={ toApps }>
								{ __( 'Manage', 'saddle' ) }
							</Button>
						}
					/>
					<RowList>
						{ connections.slice( 0, APP_ROWS ).map( ( c ) => (
							<Row
								key={ c.id }
								icon={
									<StatusDot
										tone={
											c.last_tool_at || c.last_seen_at
												? 'success'
												: 'neutral'
										}
									/>
								}
								title={ connectionLabel( c ) }
								description={ connectionMeta( c ) }
							/>
						) ) }
					</RowList>
				</section>
			) }

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
								title={ actionLabel( e, caps ) }
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
