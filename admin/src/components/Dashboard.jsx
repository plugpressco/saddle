/**
 * Dashboard — the calm status screen.
 *
 * One idea, stated in a sentence: what your AI can do right now. Everything
 * else is quieter than that — the counts are a single supporting line, and the
 * only things allowed to interrupt are the ones that need a decision.
 *
 * It used to open with four equal tiles of three different kinds (two counts, a
 * setting and a health state), which read as a metrics dashboard for a question
 * that is not a metric. The health tile in particular said "—" on most installs
 * while a real problem already had its own callout with an explanation and a
 * fix, so it cost a quarter of the page to say nothing.
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
	PageHeader,
} from '@plugpress/ui';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, levelFor } from '../api';
import { parseEntryDate, relativeWhen, shortLabel } from '../activity-format';

// How many recent entries the Dashboard preview shows; the full record lives on
// the Activity screen. Kept small so the fetch stays light.
const PREVIEW_COUNT = 6;

// Session cache for the connection self-check so re-opening the Dashboard
// doesn't re-run the loopback probe. Reset on full reload, which is the right
// granularity.
let healthCache = null;

export default function Dashboard( { tier, clients, onNavigate, onConnect } ) {
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
	// This tile is about whether connected apps can reach the site. A stripped
	// X-WP-Nonce header only affects this dashboard's own requests, which Saddle
	// already works around — so it counts as healthy here, and the note about
	// telling the host lives on the Connect tab where the detail belongs.
	const healthProblem =
		health &&
		health.status !== 'ok' &&
		health.status !== 'unknown' &&
		health.status !== 'nonce_header_stripped';

	// The one thing the page is for, said in a sentence. `level.one` is already
	// written for exactly this ("Your AI can create and edit content. Deleting
	// always asks you first.") — the tiles were paraphrasing it into one word.
	const facts = [];
	if ( hasApps ) {
		facts.push(
			sprintf(
				/* translators: %d: number of connected apps. */
				_n(
					'%d app connected',
					'%d apps connected',
					clients.length,
					'saddle'
				),
				clients.length
			)
		);
	}
	if ( activity && activity.total > 0 ) {
		facts.push(
			sprintf(
				/* translators: %d: number of actions recorded in the activity log. */
				_n(
					'%d action logged',
					'%d actions logged',
					activity.total,
					'saddle'
				),
				activity.total
			)
		);
	}

	return (
		<div className="saddle-home">
			<PageHeader title={ __( 'Dashboard', 'saddle' ) } />

			{ /* The lead. Everything below it is quieter on purpose: the counts
			     are a supporting line, not tiles, and when there are no apps the
			     count is dropped entirely — the callout underneath already says
			     it, and saying it twice is the opposite of clean. */ }
			<section className="saddle-lede">
				<p className="saddle-lede__headline">{ level.one }</p>
				{ facts.length > 0 && (
					<p className="saddle-lede__facts">
						{ facts.join( ' · ' ) }
					</p>
				) }
			</section>

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
								{ __( 'Manage connections', 'saddle' ) }
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
