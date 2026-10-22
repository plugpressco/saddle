/**
 * Home (#309, the Dashboard until then; clearer in #367; one column after
 * Saddle Cloud's overview in #369): an action page.
 *
 * With no app connected, one card asks the owner to connect one, and the
 * activity feed follows only when there is history. Nothing else is drawn:
 * the week's numbers are about apps.
 *
 * Once an app is connected, one column of soft cards. "Needs your OK" while
 * something waits, and a warning when connections may not work. Then This
 * week: three numbers and the changes of each of the last seven days as
 * bars. Then the AI apps, each with what it may do, when it was last used
 * and its one next step ("Try it", "Change access"). Then the add-ons as
 * tiles (the installed modules, and Saddle Analytics while it is not on),
 * the plugins on this site the apps can work inside ("Works with"), and the
 * activity feed by day.
 *
 * No status dots and no rules between rows: tiles, chips and space carry the
 * structure. The AI switch is in the header on every page, so Home does not
 * repeat it.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, Skeleton, VisuallyHidden } from '@plugpress/ui';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { analyticsOffer } from '../analytics-addon';
import { metaLine } from '../apps-logic';
import { APPS } from '../connect-apps';
import { parseModules } from '../onboarding-logic';
import { findArea, withArg } from '../routes';
import AnalyticsTile from './AnalyticsTile';
import NeedsYourOk from './NeedsYourOk';
import { ROLES } from './ConnectedClients';
import {
	AddonIcon,
	AppLogo,
	BrandMark,
	appKeyFromLabel,
	hasAddonIcon,
} from './icons';
import { icons } from '../icons/kit';
import { NavIcon } from '../icons/iconoir';
import {
	addonTone,
	appNext,
	appTone,
	selfCheckDue,
	weekDays,
	weekStart,
	weekTiles,
	worksWith,
} from '../home-logic';

// Session cache for the connection self-check so re-opening Home doesn't
// re-run the loopback probe. Reset on full reload, which is the right
// granularity.
let healthCache = null;

// Each role's chip.
const ROLE_TONE = {
	read: 'sky',
	write: 'lilac',
	admin: 'pink',
};

// A module's state, when it asks for the owner, as a chip on its tile.
const STATE_CHIP = {
	'needs-setup': __( 'Set up', 'saddle' ),
	attention: __( 'Check', 'saddle' ),
	off: __( 'Off', 'saddle' ),
};

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
 * How many log entries of one type the week holds, from `total`, and with
 * `times` when each happened.
 *
 * @param {string}  type      'executed' or 'denied'.
 * @param {boolean} byApp     Count only what apps did, leaving out the
 *                            owner's own steps (approvals, rejections, new
 *                            roles).
 * @param {boolean} withTimes Ask for each entry's time too, for the chart.
 * @return {Promise<?Object>} `{ total, times }`, or null when it failed.
 */
function weekLog( type, byApp = false, withTimes = false ) {
	return api(
		`audit-log?per_page=1&type=${ type }&since=${ weekStart() }${
			byApp ? '&by=app' : ''
		}${ withTimes ? '&times=1' : '' }`
	).then(
		( res ) =>
			Number.isInteger( res.total )
				? { total: res.total, times: res.times || [] }
				: null,
		() => null
	);
}

/**
 * A card's heading row: its name, and its links or buttons on the right.
 *
 * @param {Object} props
 * @param {string} props.title   The card's name.
 * @param {*}      props.actions Optional controls.
 */
function CardHead( { title, actions } ) {
	return (
		<div className="saddle-soft-card__head">
			<h2 className="saddle-soft-card__title">{ title }</h2>
			{ actions && (
				<div className="saddle-soft-card__actions">{ actions }</div>
			) }
		</div>
	);
}

/**
 * The changes of each day this week as bars, Saddle Cloud's: pale pink, and
 * the brand on today's and on the one pointed at. A day with changes shows
 * its number over its bar.
 *
 * @param {Object} props
 * @param {Array}  props.days From weekDays().
 */
function WeekBars( { days } ) {
	const max = Math.max( 1, ...days.map( ( d ) => d.count ) );
	return (
		<figure className="saddle-bars">
			<ul
				className="saddle-bars__plot"
				aria-label={ __( 'Changes each day this week', 'saddle' ) }
			>
				{ days.map( ( d ) => (
					<li
						key={ d.date.getTime() }
						className={ `saddle-bars__day${
							d.today ? ' is-today' : ''
						}` }
					>
						<VisuallyHidden>
							{ sprintf(
								/* translators: 1: a day, such as "Monday, October 20", 2: how many changes. */
								_n(
									'%1$s: %2$d change',
									'%1$s: %2$d changes',
									d.count,
									'saddle'
								),
								d.date.toLocaleDateString( undefined, {
									weekday: 'long',
									month: 'long',
									day: 'numeric',
								} ),
								d.count
							) }
						</VisuallyHidden>
						{ d.count > 0 && (
							<span
								className="saddle-bars__count"
								aria-hidden="true"
							>
								{ d.count.toLocaleString() }
							</span>
						) }
						<span
							className="saddle-bars__bar"
							aria-hidden="true"
							style={ {
								blockSize: `${ Math.max(
									3,
									Math.round( ( d.count / max ) * 100 )
								) }%`,
							} }
						/>
					</li>
				) ) }
			</ul>
			<div className="saddle-bars__ticks" aria-hidden="true">
				{ days.map( ( d ) => (
					<span key={ d.date.getTime() }>
						{ d.date.toLocaleDateString( undefined, {
							weekday: 'short',
						} ) }
					</span>
				) ) }
			</div>
		</figure>
	);
}

/**
 * This week: three numbers, then the changes of each day as bars. Until
 * they arrive the numbers are placeholders, so the card keeps its place; a
 * number that failed to load is left out, and nothing is drawn when all
 * three failed.
 *
 * @param {Object}    props
 * @param {?Object}   props.week   `{ changes, blocked, waiting, times }`, or null while loading.
 * @param {Function=} props.onLogs Opens Logs, every change and blocked attempt.
 */
function WeekCard( { week, onLogs } ) {
	const loading = null === week;
	const tiles = weekTiles(
		loading ? { changes: 0, blocked: 0, waiting: 0 } : week
	);
	if ( ! tiles.length ) {
		return null;
	}
	return (
		<section
			className="saddle-soft-card saddle-week"
			aria-busy={ loading || undefined }
		>
			<CardHead
				title={ __( 'This week', 'saddle' ) }
				actions={
					onLogs && (
						<Button variant="link" size="sm" onClick={ onLogs }>
							{ __( 'See logs', 'saddle' ) }
						</Button>
					)
				}
			/>
			<div className="saddle-week__numbers">
				{ tiles.map( ( t ) => (
					<div className="saddle-week__number" key={ t.key }>
						<span className="saddle-week__value">
							{ loading ? (
								<Skeleton height={ 32 } width={ 40 } />
							) : (
								t.value.toLocaleString()
							) }
						</span>
						<span className="saddle-week__label">{ t.label }</span>
					</div>
				) ) }
			</div>
			{ loading || ! Array.isArray( week.times ) ? (
				<Skeleton height={ 120 } aria-hidden="true" />
			) : (
				<WeekBars days={ weekDays( week.times ) } />
			) }
		</section>
	);
}

/**
 * Home's shape while it loads (P12): the week, the apps and the feed, as
 * placeholders. No h1 or h2 in it: the app draws this before WordPress has
 * moved its notices (see Frame).
 */
export function HomeSkeleton() {
	return (
		<div className="saddle-home">
			<div className="saddle-soft-card" aria-hidden="true">
				<Skeleton height={ 15 } width={ 72 } />
				<div className="saddle-week__numbers">
					{ [ 0, 1, 2 ].map( ( i ) => (
						<Skeleton key={ i } height={ 32 } width={ 56 } />
					) ) }
				</div>
				<Skeleton height={ 120 } />
			</div>
			<div className="saddle-soft-card" aria-hidden="true">
				<Skeleton height={ 15 } width={ 80 } />
				<div className="saddle-home__apps">
					{ [ 0, 1, 2 ].map( ( i ) => (
						<Skeleton key={ i } height={ 132 } />
					) ) }
				</div>
			</div>
		</div>
	);
}

export default function Home( { clients, onNavigate, onConnect, homeUrl } ) {
	// Keys and address (OAuth) connections, from the connection registry.
	// `false` means the route failed: fall back to the keys the app already
	// loaded rather than claim there are none.
	const [ connections, setConnections ] = useState( null );
	// Every page and module, fetched once for Add-ons.
	const [ areas, setAreas ] = useState( null );
	const [ health, setHealth ] = useState( healthCache );
	// Bumped after an approve or reject, so the counts show it.
	const [ feedKey, setFeedKey ] = useState( 0 );
	// This week: changes and when each happened, blocked attempts, and what
	// waits for the owner.
	const [ week, setWeek ] = useState( null );
	// The Services records, for "Works with".
	const [ services, setServices ] = useState( [] );
	// Whether Add-ons offers Saddle Analytics, as the page first knew it: its
	// tile stays after the add, to say it is on.
	const [ offerAnalytics ] = useState( () => analyticsOffer().canAdd );

	const known = Array.isArray( connections );
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
			weekLog( 'executed', true, true ),
			weekLog( 'denied' ),
			api( 'approvals' ).then(
				( res ) => ( res.approvals || [] ).length,
				() => null
			),
		] ).then(
			( [ changes, blocked, waiting ] ) =>
				alive &&
				setWeek( {
					changes: changes ? changes.total : null,
					times: changes ? changes.times : null,
					blocked: blocked ? blocked.total : null,
					waiting,
				} )
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

	// The loopback header probe runs last (selfCheckDue).
	const probeDue = selfCheckDue( connected, week );
	useEffect( () => {
		if ( healthCache || ! probeDue ) {
			return; // Already probed this session, or not yet.
		}
		api( 'self-check' )
			.then( ( res ) => {
				healthCache = { status: res.status || 'unknown' };
				setHealth( healthCache );
			} )
			.catch( () => {
				healthCache = { status: 'unknown' };
				setHealth( healthCache );
			} );
	}, [ probeDue ] );

	// Until the connections are in, which page this is is unknown: draw its
	// shape.
	if ( ! Array.isArray( apps ) ) {
		return <HomeSkeleton />;
	}

	// Nothing connected: one card. The history is on Logs.
	if ( ! connected ) {
		return (
			<div className="saddle-home">
				<div className="saddle-soft-card saddle-home__connect">
					<span
						className="saddle-tile saddle-tile--pink saddle-tile--lg"
						aria-hidden="true"
					>
						<BrandMark width={ 28 } height={ 28 } />
					</span>
					<p className="saddle-home__connect-text">
						{ __( 'No AI app is connected yet.', 'saddle' ) }
					</p>
					<Button variant="primary" onClick={ onConnect }>
						{ __( 'Connect an app', 'saddle' ) }
					</Button>
				</div>
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
	const plugins = worksWith(
		services,
		modules.map( ( m ) => m.key )
	);

	// The app's one next step, on its own row.
	const appAction = ( c ) => {
		const next = known ? appNext( c ) : '';
		if ( 'try' === next ) {
			// The welcome reopens at "try it", for this app.
			return (
				<Button
					variant="secondary"
					size="sm"
					href={ withArg(
						withArg(
							withArg( homeUrl, 'setup', '1' ),
							'step',
							'try'
						),
						'app',
						c.app
					) }
				>
					{ __( 'Try it', 'saddle' ) }
				</Button>
			);
		}
		if ( 'access' === next ) {
			return (
				<Button
					variant="link"
					size="sm"
					onClick={ () => onNavigate( 'connect' ) }
				>
					{ __( 'Change access', 'saddle' ) }
				</Button>
			);
		}
		return null;
	};

	return (
		<div className="saddle-home">
			{ healthProblem && (
				<div className="saddle-soft-card saddle-home__alert">
					<span
						className="saddle-tile saddle-tile--butter"
						aria-hidden="true"
					>
						<icons.AlertTriangle size={ 20 } />
					</span>
					<div className="saddle-home__alert-text">
						<p className="saddle-home__alert-title">
							{ __( 'Connections may not work', 'saddle' ) }
						</p>
						<p className="saddle-home__alert-line">
							{ __(
								'Your server may block the sign-in header apps need, or Application Passwords are off.',
								'saddle'
							) }
						</p>
					</div>
					<Button
						variant="secondary"
						size="sm"
						onClick={ () =>
							onNavigate( { area: 'settings', tab: 'general' } )
						}
					>
						{ __( 'Check', 'saddle' ) }
					</Button>
				</div>
			) }

			{ /* Big changes an app asked for, waiting for the owner (#287).
			     Draws nothing when there are none. */ }
			<NeedsYourOk onChange={ () => setFeedKey( ( k ) => k + 1 ) } />

			<WeekCard
				week={ week }
				onLogs={ () => onNavigate( { area: 'logs', tab: 'overview' } ) }
			/>

			<section className="saddle-soft-card">
				<CardHead
					title={ __( 'Agent setup', 'saddle' ) }
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
				{ /* One card per app, three to a row on a wide screen, and a
				     last card that connects another (Fahim, 2026-10-23: "make
				     those as card, 2 or 3 in a row"). */ }
				<ul className="saddle-home__apps">
					{ apps.map( ( c ) => {
						const app =
							c.app || appKeyFromLabel( c.name || c.client );
						const role = ROLES.find( ( r ) => r.key === c.role );
						const meta = known ? metaLine( c ) : '';
						const next = appAction( c );
						return (
							<li className="saddle-app-card" key={ c.id }>
								<div className="saddle-app-card__top">
									<span
										className={ `saddle-tile saddle-tile--${ appTone(
											app
										) }` }
										aria-hidden="true"
									>
										<AppLogo app={ app } />
									</span>
									{ role && (
										<span
											className={ `saddle-chip saddle-chip--${
												ROLE_TONE[ role.key ] || 'sky'
											}` }
										>
											{ role.label }
										</span>
									) }
								</div>
								<span className="saddle-app-card__name">
									{ connectionLabel( c ) }
								</span>
								{ meta && (
									<span className="saddle-app-card__meta">
										{ meta }
									</span>
								) }
								{ next && (
									<span className="saddle-app-card__next">
										{ next }
									</span>
								) }
							</li>
						);
					} ) }
					<li className="saddle-app-card saddle-app-card--add">
						<button
							type="button"
							className="saddle-app-card__add"
							onClick={ onConnect }
						>
							<span
								className="saddle-app-card__plus"
								aria-hidden="true"
							>
								<icons.Plus size={ 18 } />
							</span>
							{ __( 'Connect an app', 'saddle' ) }
						</button>
					</li>
				</ul>
			</section>

			{ ( modules.length > 0 || offerAnalytics ) && (
				<section className="saddle-soft-card">
					<CardHead title={ __( 'Add-ons', 'saddle' ) } />
					<ul className="saddle-home__addons">
						{ modules.map( ( m ) => {
							const area = findArea( saddleData.areas, m.key );
							const chip = STATE_CHIP[ m.state ];
							return (
								<li className="saddle-addon" key={ m.key }>
									{ hasAddonIcon( m.key ) ? (
										<AddonIcon
											addon={ m.key }
											className="saddle-addon__icon"
										/>
									) : (
										<span
											className={ `saddle-tile saddle-tile--${ addonTone(
												m.key
											) }` }
											aria-hidden="true"
										>
											<NavIcon
												name={
													( area && area.icon ) ||
													'view-grid'
												}
												size={ 20 }
											/>
										</span>
									) }
									<span className="saddle-addon__text">
										<a
											className="saddle-addon__name saddle-addon__link"
											href={ m.admin_url }
										>
											{ m.product || m.title }
										</a>
										{ m.line && (
											<span className="saddle-addon__line">
												{ m.line }
											</span>
										) }
									</span>
									{ chip && (
										<span className="saddle-chip saddle-chip--butter">
											{ chip }
										</span>
									) }
									<icons.ChevronRight
										size={ 16 }
										className="saddle-addon__go"
									/>
								</li>
							);
						} ) }
						{ offerAnalytics && <AnalyticsTile /> }
					</ul>
				</section>
			) }

			{ plugins.length > 0 && (
				<section className="saddle-soft-card">
					<CardHead title={ __( 'Works with', 'saddle' ) } />
					<ul className="saddle-home__chips">
						{ plugins.map( ( p ) => (
							<li className="saddle-chip" key={ p.key }>
								{ p.name }
							</li>
						) ) }
					</ul>
				</section>
			) }
		</div>
	);
}
