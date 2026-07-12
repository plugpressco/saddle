/**
 * Home — the calm status screen. Answers, at a glance: what can the AI do right
 * now, what's connected, and what (if anything) it has done. Adapts when nothing
 * is set up yet.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	CalloutCard,
	CardGrid,
	Card,
	CardHeader,
	CardContent,
	Badge,
	RowList,
	Row,
	StatusDot,
	StatCard,
	StatGrid,
} from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api, levelFor } from '../api';
import { parseEntryDate, relativeWhen, shortLabel } from '../activity-format';
import HelpTip from './HelpTip';

// Stat label + inline "?" — the explanation rides a tooltip so the tile stays
// a single clean number.
const StatLabel = ( { children, help } ) => (
	<span className="saddle-stat__label">
		{ children }
		<HelpTip label={ help } />
	</span>
);

// How many recent entries the Home preview shows; the full record lives on the
// Activity screen. Kept small so the fetch stays light.
const PREVIEW_COUNT = 6;

// Session cache for the connection self-check so re-opening Home doesn't re-run
// the loopback probe. Reset on full reload, which is the right granularity.
let healthCache = null;

// Compact tile labels for the access level — the shared LEVELS titles
// ("Just reading" …) read as sentences; a stat value wants one word.
const LEVEL_STAT_LABEL = {
	read: __( 'Read-only', 'saddle' ),
	write: __( 'Read & write', 'saddle' ),
	admin: __( 'Admin', 'saddle' ),
};

export default function Home( { tier, clients, onNavigate, onConnect } ) {
	const hasApps = clients.length > 0;

	const [ activity, setActivity ] = useState( null );
	const [ health, setHealth ] = useState( healthCache );

	useEffect( () => {
		// Only the preview page is needed here; `total` is the full count for
		// the "Actions logged" tile regardless of page size.
		api( `audit-log?per_page=${ PREVIEW_COUNT }` )
			.then( ( res ) =>
				setActivity( {
					enabled: !! res.enabled,
					entries: res.entries || [],
					total: res.total || 0,
				} )
			)
			.catch( () =>
				setActivity( { enabled: false, entries: [], total: 0 } )
			);
	}, [] );

	useEffect( () => {
		if ( healthCache ) {
			return; // Already probed this session.
		}
		// Runs the loopback header probe once; the tile shows a skeleton until
		// it lands, so Home never blocks on it.
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

	const level = levelFor( tier );
	const healthOk = health && health.status === 'ok';
	const healthProblem =
		health && health.status !== 'ok' && health.status !== 'unknown';
	let healthValue = '—';
	if ( healthOk ) {
		healthValue = __( 'Healthy', 'saddle' );
	} else if ( healthProblem ) {
		healthValue = __( 'Needs a fix', 'saddle' );
	}

	return (
		<div className="saddle-home">
			{ /* At-a-glance tiles: what's connected, what power it has, and how
			     much it has done. Values come from data already loaded. */ }
			<StatGrid className="saddle-stats" min={ 180 }>
				<StatCard
					label={ __( 'Connected apps', 'saddle' ) }
					value={ clients.length }
				/>
				<StatCard
					label={
						<StatLabel
							help={ __(
								'The most any connected app can do right now. Change it on the Permissions tab.',
								'saddle'
							) }
						>
							{ __( 'Access level', 'saddle' ) }
						</StatLabel>
					}
					value={ LEVEL_STAT_LABEL[ level.key ] || level.title }
				/>
				<StatCard
					label={
						<StatLabel
							help={ __(
								'Changes and blocked attempts recorded so far. Reading your site is never logged.',
								'saddle'
							) }
						>
							{ __( 'Actions logged', 'saddle' ) }
						</StatLabel>
					}
					value={ activity ? activity.total : '—' }
				/>
				<StatCard
					label={
						<StatLabel
							help={ __(
								'Whether apps can reach your site. If your host strips the Authorization header, connections fail — fix it from the Connect tab.',
								'saddle'
							) }
						>
							{ __( 'Connection', 'saddle' ) }
						</StatLabel>
					}
					value={ healthValue }
				/>
			</StatGrid>

			{ /* A stripped Authorization header (or app passwords off) breaks
			     every connection — surface it here with a path to the fix. */ }
			{ healthProblem && (
				<CalloutCard
					tone="warning"
					title={ __( 'Connections may not work', 'saddle' ) }
					description={ __(
						'Your server looks like it blocks the sign-in header apps need, or Application Passwords are turned off. Open Connect to check and fix it.',
						'saddle'
					) }
					action={
						<Button
							variant="primary"
							onClick={ () => onNavigate( 'connect' ) }
						>
							{ __( 'Check connection', 'saddle' ) }
						</Button>
					}
				/>
			) }

			{ /* When no apps yet, make connecting the clear next step */ }
			{ ! hasApps && (
				<CalloutCard
					title={ __( 'Connect your first app', 'saddle' ) }
					description={ __(
						'Add an AI app like Claude, Cursor, or VS Code so it can work with your site.',
						'saddle'
					) }
					action={
						<Button variant="primary" onClick={ onConnect }>
							{ __( 'Connect an app', 'saddle' ) }
						</Button>
					}
				/>
			) }

			<CardGrid className="saddle-cards" min={ 300 }>
				{ /* Connected apps — only once there's something to show; the
				     next-step section above owns the empty state. */ }
				{ hasApps && (
					<Card>
						<CardHeader
							title={ __( 'Connected apps', 'saddle' ) }
						/>
						<CardContent>
							<RowList>
								{ clients.slice( 0, 4 ).map( ( c ) => (
									<Row
										key={ c.uuid }
										icon={ <StatusDot tone="success" /> }
										title={ c.label || c.name }
									/>
								) ) }
							</RowList>
							<Button
								variant="link"
								onClick={ () => onNavigate( 'connect' ) }
							>
								{ __( 'Manage apps', 'saddle' ) }
							</Button>
						</CardContent>
					</Card>
				) }

				{ /* Recent activity */ }
				<Card>
					<CardHeader
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
					<CardContent>
						{ activity &&
						activity.enabled &&
						activity.entries.length > 0 ? (
							<ul className="saddle-activitylist">
								{ activity.entries
									.slice( 0, PREVIEW_COUNT )
									.map( ( e, i ) => (
										<li
											key={ i }
											className={
												e.type === 'denied'
													? 'is-denied'
													: undefined
											}
										>
											<span
												className="saddle-activitylist__summary"
												title={ e.summary || undefined }
											>
												{ e.type === 'denied' && (
													<Badge tone="danger">
														{ __(
															'Blocked',
															'saddle'
														) }
													</Badge>
												) }
												{ shortLabel( e ) }
											</span>
											<span className="saddle-activitylist__target">
												{ relativeWhen(
													parseEntryDate( e.date )
												) }
											</span>
										</li>
									) ) }
							</ul>
						) : (
							<p className="saddle-card__empty">
								{ __(
									'Nothing yet. Changes your AI makes will show up here.',
									'saddle'
								) }
							</p>
						) }
					</CardContent>
				</Card>
			</CardGrid>
		</div>
	);
}
