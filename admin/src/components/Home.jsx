/**
 * Home — the calm status screen. Answers, at a glance: what can the AI do right
 * now, what's connected, and what (if anything) it has done. Adapts when nothing
 * is set up yet.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { api, levelFor } from '../api';

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

export default function Home( {
	tier,
	clients,
	onNavigate,
	onConnect,
	paused,
	onResume,
} ) {
	const level = levelFor( tier );
	let tone = level.key === 'read' ? 'safe' : 'active';
	if ( paused ) {
		tone = 'paused';
	}
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
			{ /* What the AI can do — the hero statement */ }
			<section className={ `saddle-hero saddle-hero--${ tone }` }>
				<span className="saddle-hero__label">
					{ __( 'Right now', 'saddle' ) }
				</span>
				<p className="saddle-hero__statement">
					{ paused
						? __(
								'Saddle is paused. Your AI can’t read or change anything until you resume.',
								'saddle'
						  )
						: level.one }
				</p>
				{ paused ? (
					<Button variant="secondary" onClick={ onResume }>
						{ __( 'Resume', 'saddle' ) }
					</Button>
				) : (
					<Button
						variant="secondary"
						onClick={ () => onNavigate( 'permissions' ) }
					>
						{ __( 'Change what it can do', 'saddle' ) }
					</Button>
				) }
			</section>

			{ /* When no apps yet, make connecting the clear next step */ }
			{ ! hasApps && (
				<section className="saddle-nextstep">
					<h2>{ __( 'Connect your first app', 'saddle' ) }</h2>
					<p>
						{ __(
							'Add an AI app like Claude, Cursor, or VS Code so it can work with your site.',
							'saddle'
						) }
					</p>
					<Button variant="primary" onClick={ onConnect }>
						{ __( 'Connect an app', 'saddle' ) }
					</Button>
				</section>
			) }

			<div
				className={ `saddle-cards${
					hasApps ? '' : ' saddle-cards--single'
				}` }
			>
				{ /* Connected apps — only once there's something to show; the
				     next-step section above owns the empty state. */ }
				{ hasApps && (
					<section className="saddle-card">
						<div className="saddle-card__head">
							<h2>{ __( 'Connected apps', 'saddle' ) }</h2>
							<span className="saddle-card__count">
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
							</span>
						</div>
						<ul className="saddle-applist">
							{ clients.slice( 0, 4 ).map( ( c ) => (
								<li key={ c.uuid }>
									<span
										className="saddle-applist__dot"
										aria-hidden="true"
									/>
									{ c.label || c.name }
								</li>
							) ) }
						</ul>
						<Button
							variant="link"
							onClick={ () => onNavigate( 'connect' ) }
						>
							{ __( 'Manage apps', 'saddle' ) }
						</Button>
					</section>
				) }

				{ /* Recent activity */ }
				<section className="saddle-card">
					<div className="saddle-card__head">
						<h2>{ __( 'Recent activity', 'saddle' ) }</h2>
					</div>
					{ activity &&
					activity.enabled &&
					activity.entries.length > 0 ? (
						<ul className="saddle-activitylist">
							{ activity.entries.slice( 0, 6 ).map( ( e, i ) => (
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
											<span className="saddle-activitylist__badge">
												{ __( 'Blocked', 'saddle' ) }
											</span>
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
				</section>
			</div>
		</div>
	);
}
