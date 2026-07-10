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
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { api } from '../api';

const WHEN_FMT = new Intl.DateTimeFormat( undefined, {
	dateStyle: 'medium',
	timeStyle: 'short',
} );
const RELATIVE_FMT = new Intl.RelativeTimeFormat( undefined, {
	numeric: 'auto',
} );

// "5 minutes ago" for fresh entries, the plain date once it's history.
const timeAgo = ( d ) => {
	const mins = Math.round( ( d.getTime() - Date.now() ) / 60000 );
	if ( mins > -1 ) {
		return RELATIVE_FMT.format( 0, 'minute' ); // "now"-ish
	}
	if ( mins > -60 ) {
		return RELATIVE_FMT.format( mins, 'minute' );
	}
	const hours = Math.round( mins / 60 );
	if ( hours > -24 ) {
		return RELATIVE_FMT.format( hours, 'hour' );
	}
	const days = Math.round( hours / 24 );
	if ( days > -7 ) {
		return RELATIVE_FMT.format( days, 'day' );
	}
	return WHEN_FMT.format( d );
};

// The API returns GMT timestamps as "YYYY-MM-DD HH:MM:SS".
const formatWhen = ( gmt ) => {
	if ( ! gmt ) {
		return '';
	}
	const d = new Date( `${ gmt.replace( ' ', 'T' ) }Z` );
	return isNaN( d.getTime() ) ? '' : timeAgo( d );
};

const VERBS = {
	create: __( 'Created', 'saddle' ),
	update: __( 'Updated', 'saddle' ),
	delete: __( 'Deleted', 'saddle' ),
	upload: __( 'Uploaded', 'saddle' ),
};

// The stored summary doubles as the approval-preview text, so it's verbose
// ("Move post #635 … It can be restored from Trash."). For the activity feed we
// derive a short label from the action + target and keep the full summary as a
// hover title. Denied entries already carry a "Blocked" badge, so we drop the
// redundant "Blocked: " prefix from their summary.
const shortLabel = ( entry ) => {
	if ( entry.type === 'denied' ) {
		return (
			( entry.summary || '' ).replace( /^Blocked:\s*/i, '' ) ||
			__( 'Refused', 'saddle' )
		);
	}
	const m = ( entry.action || '' ).match(
		/^(create|update|delete|upload)[-_](post|page|media|category|tag)/
	);
	if ( m && VERBS[ m[ 1 ] ] ) {
		const at = entry.target ? ` #${ entry.target }` : '';
		return `${ VERBS[ m[ 1 ] ] } ${ m[ 2 ] }${ at }`;
	}
	return entry.summary || entry.action || '—';
};

export default function Home( { clients, onNavigate, onConnect } ) {
	const hasApps = clients.length > 0;

	const [ activity, setActivity ] = useState( null );

	useEffect( () => {
		api( 'audit-log' )
			.then( ( res ) =>
				setActivity( {
					enabled: !! res.enabled,
					entries: res.entries || [],
				} )
			)
			.catch( () => setActivity( { enabled: false, entries: [] } ) );
	}, [] );

	return (
		<div className="saddle-home">
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
							actions={
								<Badge>
									{ sprintf(
										/* translators: %d: number of apps. */
										_n(
											'%d app',
											'%d apps',
											clients.length,
											'saddle'
										),
										clients.length
									) }
								</Badge>
							}
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
									.slice( 0, 6 )
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
												title={
													e.summary || undefined
												}
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
												{ formatWhen( e.date ) }
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
