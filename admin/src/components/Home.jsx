/**
 * Home (#309, the Dashboard until then): an action page.
 *
 * With no app connected, one block asks the owner to connect one, and the
 * activity feed follows only when there is history. Nothing else is drawn:
 * the week's numbers are about apps, and so is the side column.
 *
 * Once an app is connected, two columns. The main one holds "Needs your OK"
 * while something waits, one line from Saddle with the next step for an app
 * (try it, or let it edit), the week's three numbers, and the activity feed
 * by day. The side one lists each connected app with what it may do, the
 * plugins on this site the apps can work inside ("Works with"), a warning
 * when connections may not work, and the installed modules.
 *
 * The AI switch is in the header on every page, so Home does not repeat it,
 * and there are no tabs: the feed is part of the page.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Card,
	CardHeader,
	Row,
	RowList,
	StatCard,
	StatGrid,
	StatusDot,
} from '@plugpress/ui';
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
import { weekStart, weekTiles, worksWith } from '../home-logic';

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

/**
 * How many log entries of one type the week holds, from `total`.
 *
 * @param {string}  type  'executed' or 'denied'.
 * @param {boolean} byApp Count only what apps did, leaving out the owner's
 *                        own steps (approvals, rejections, new roles).
 * @return {Promise<number|null>} The count, or null when it failed.
 */
function weekTotal( type, byApp = false ) {
	return api(
		`audit-log?per_page=1&type=${ type }&since=${ weekStart() }${
			byApp ? '&by=app' : ''
		}`
	).then(
		( res ) => ( Number.isInteger( res.total ) ? res.total : null ),
		() => null
	);
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
	// Bumped after an approve or reject, so the feed and the counts show it.
	const [ feedKey, setFeedKey ] = useState( 0 );
	// This week: changes, blocked attempts, and what waits for the owner.
	const [ week, setWeek ] = useState( null );
	// The Services records, for "Works with".
	const [ services, setServices ] = useState( [] );

	const apps =
		false === connections
			? clients.map( ( c ) => ( {
					id: c.uuid,
					name: c.label || c.name,
			  } ) )
			: connections;
	const connected = Array.isArray( apps ) && apps.length > 0;

	useEffect( () => {
		let alive = true;
		api( 'services' )
			.then( ( res ) => alive && setServices( res.services || [] ) )
			.catch( () => {} );
		return () => {
			alive = false;
		};
	}, [] );

	useEffect( () => {
		if ( ! connected ) {
			return;
		}
		let alive = true;
		Promise.all( [
			weekTotal( 'executed', true ),
			weekTotal( 'denied' ),
			api( 'approvals' ).then(
				( res ) => ( res.approvals || [] ).length,
				() => null
			),
		] ).then(
			( [ changes, blocked, waiting ] ) =>
				alive && setWeek( { changes, blocked, waiting } )
		);
		return () => {
			alive = false;
		};
	}, [ feedKey, connected ] );

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

	// Until the connections are in, which page this is is unknown.
	if ( ! Array.isArray( apps ) ) {
		return null;
	}

	// Nothing connected: one block, and the feed only when there is history.
	if ( ! connected ) {
		return (
			<div className="saddle-home saddle-home--single">
				<Card className="saddle-home__connect">
					<p className="saddle-home__connect-text">
						{ __( 'No AI app is connected yet.', 'saddle' ) }
					</p>
					<Button variant="primary" onClick={ onConnect }>
						{ __( 'Connect an app', 'saddle' ) }
					</Button>
				</Card>

				<section id="activity">
					<Activity
						caps={ caps }
						title={ __( 'Activity', 'saddle' ) }
						hideEmpty
					/>
				</section>
			</div>
		);
	}

	// Whether connected apps can reach the site. A stripped X-WP-Nonce header
	// only affects this screen's own requests, which Saddle already works
	// around — so it counts as healthy here.
	const healthProblem =
		health &&
		health.status !== 'ok' &&
		health.status !== 'unknown' &&
		health.status !== 'nonce_header_stripped';

	const modules = ( areas || [] ).filter( ( a ) => 'module' === a.kind );
	const plugins = worksWith( services );
	const tiles = weekTiles( week );

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
					onNavigate={ onNavigate }
					onHide={ onHideSetup }
				/>

				{ tiles.length > 0 && (
					<Card className="saddle-home__week">
						<CardHeader title={ __( 'This week', 'saddle' ) } />
						<StatGrid columns={ tiles.length } divided>
							{ tiles.map( ( t ) => (
								<StatCard
									key={ t.key }
									flush
									label={ t.label }
									value={ String( t.value ) }
								/>
							) ) }
						</StatGrid>
					</Card>
				) }

				<section id="activity">
					<Activity
						key={ feedKey }
						caps={ caps }
						title={ __( 'Activity', 'saddle' ) }
					/>
				</section>
			</div>

			<aside className="saddle-home__side">
				<section className="saddle-stack">
					<SectionHeader
						title={ __( 'Apps', 'saddle' ) }
						actions={
							<Button
								variant="link"
								size="sm"
								onClick={ () => onNavigate( 'connect' ) }
							>
								{ __( 'Manage', 'saddle' ) }
							</Button>
						}
					/>
					<RowList>
						{ apps.map( ( c ) => (
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
						<Row
							title={
								<Button
									variant="link"
									size="sm"
									onClick={ onConnect }
								>
									{ __( 'Connect another app', 'saddle' ) }
								</Button>
							}
						/>
					</RowList>
				</section>

				{ plugins.length > 0 && (
					<section className="saddle-stack">
						<SectionHeader
							title={ __( 'Works with', 'saddle' ) }
							actions={
								<Button
									variant="link"
									size="sm"
									onClick={ () =>
										onNavigate( {
											area: 'services',
											tab: 'overview',
										} )
									}
								>
									{ __( 'Services', 'saddle' ) }
								</Button>
							}
						/>
						<RowList>
							{ plugins.map( ( p ) => (
								<Row key={ p.key } title={ p.name } />
							) ) }
						</RowList>
					</section>
				) }

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
