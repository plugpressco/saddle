/**
 * Activity — the full record of what connected apps have done through Saddle,
 * Home's feed since #309.
 *
 * Every executed change and every blocked attempt, newest first, grouped by
 * day. Each row is one line: the logo of the app that did it (a dot when no
 * app did), what happened, and the time. The row's tooltip holds the whole
 * line, which the row may cut short, and who did it ("via Claude Code", or
 * "by you" for the owner's own steps).
 * Filterable to just changes or just blocked attempts, and to rehearsals when
 * the first page holds one; pages in with "Show older". Reads are never
 * logged (see Saddle_Log); the empty state says so.
 *
 * A change Saddle recorded can be undone from its row (shown on hover and
 * focus). Undo previews what comes back in a drawer, through the same journal
 * the apps' undo tool uses, and the owner confirms there (POST /undo). The
 * owner's undo then shows in the feed as their own step.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Drawer,
	Notice,
	FilterTabs,
	EmptyState,
	Skeleton,
	VisuallyHidden,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import {
	canUndo,
	clock,
	groupByDay,
	madeBy,
	rowText,
	rowTitle,
	undoOutcome,
	undoPlan,
	wasUndone,
} from '../activity-format';
import { AppLogo, appKeyFromLabel } from './icons';

const PER_PAGE = 25;

/**
 * Who made an entry, for the row's tooltip: "via Claude Code", "by you".
 *
 * @param {Object} e Audit-log entry.
 * @return {string} The line, or '' when no one is named.
 */
const via = ( e ) => madeBy( e, saddleData.user || '' );

/**
 * The feed's shape while it loads: a day label and a few rows.
 *
 * @param {Object} props
 * @param {number} props.rows How many rows to draw.
 */
export function ActivitySkeleton( { rows = 4 } ) {
	return (
		<div className="saddle-activity__day" aria-busy="true">
			<VisuallyHidden>
				{ __( 'Loading activity', 'saddle' ) }
			</VisuallyHidden>
			<Skeleton
				className="saddle-activity__daylabel"
				height={ 12 }
				width={ 64 }
			/>
			<ul className="saddle-activity__list" aria-hidden="true">
				{ Array.from( { length: rows }, ( _, i ) => (
					<li key={ i } className="saddle-activity__row">
						<Skeleton round width={ 20 } height={ 20 } />
						<span className="saddle-activity__summary">
							<Skeleton
								height={ 12 }
								width={ `${ 70 - i * 10 }%` }
							/>
						</span>
						<Skeleton height={ 12 } width={ 48 } />
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * The undo drawer's body: what comes back and the confirm, or why nothing
 * can.
 *
 * @param {Object}   props
 * @param {Object}   props.plan       From undoPlan().
 * @param {boolean}  props.confirming The confirm is running.
 * @param {Function} props.onConfirm  Undo it.
 * @param {Function} props.onCancel   Close without undoing.
 */
function UndoPreview( { plan, confirming, onConfirm, onCancel } ) {
	if ( ! plan.ready ) {
		return (
			<div className="saddle-doc saddle-doc--bare">
				<h3 className="saddle-doc__h">
					{ __( 'This change can’t be undone', 'saddle' ) }
				</h3>
				<ul className="saddle-doc__list">
					{ plan.reasons.map( ( r, i ) => (
						<li key={ i }>{ r }</li>
					) ) }
				</ul>
				<div className="saddle-needs-ok__actions">
					<Button variant="secondary" size="sm" onClick={ onCancel }>
						{ __( 'Close', 'saddle' ) }
					</Button>
				</div>
			</div>
		);
	}
	return (
		<div className="saddle-doc saddle-doc--bare">
			{ plan.steps.length > 0 && (
				<>
					<h3 className="saddle-doc__h">
						{ __( 'What comes back', 'saddle' ) }
					</h3>
					<ul className="saddle-doc__list">
						{ plan.steps.map( ( s, i ) => (
							<li key={ i }>{ s }</li>
						) ) }
					</ul>
				</>
			) }
			<div className="saddle-needs-ok__actions">
				<Button
					variant="secondary"
					size="sm"
					disabled={ confirming }
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'saddle' ) }
				</Button>
				<Button
					size="sm"
					loading={ confirming }
					disabled={ confirming }
					onClick={ onConfirm }
				>
					{ __( 'Undo change', 'saddle' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * @param {Object}    props
 * @param {Object[]}  props.caps      The capabilities list, for tool names.
 * @param {string=}   props.title     A heading drawn on the filters' row (Home).
 * @param {boolean=}  props.hideEmpty Draw nothing at all, not even while
 *                                    loading, when the log is empty (Home with
 *                                    no app connected).
 * @param {Function=} props.onChange  Called after the owner undid a change, so
 *                                    the page can refresh what it counts.
 */
export default function Activity( {
	caps = [],
	title,
	hideEmpty = false,
	onChange,
} ) {
	const [ entries, setEntries ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ filter, setFilter ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ more, setMore ] = useState( false );
	const [ error, setError ] = useState( null );
	// What the unfiltered first page held: whether there is any history, and
	// whether a rehearsal is in it (the Rehearsed filter shows only then).
	const [ first, setFirst ] = useState( null );
	// The entry whose undo preview is loading, and the open preview.
	const [ asking, setAsking ] = useState( 0 );
	const [ undo, setUndo ] = useState( null );
	const [ confirming, setConfirming ] = useState( false );

	const load = useCallback( ( nextPage, nextFilter, append ) => {
		if ( append ) {
			setMore( true );
		} else {
			setLoading( true );
		}
		api(
			`audit-log?per_page=${ PER_PAGE }&page=${ nextPage }${
				nextFilter ? `&type=${ nextFilter }` : ''
			}`
		)
			.then( ( res ) => {
				const got = res.entries || [];
				setEntries( ( prev ) =>
					append ? [ ...prev, ...got ] : got
				);
				setTotal( res.total || 0 );
				setPage( nextPage );
				if ( 1 === nextPage && ! nextFilter ) {
					setFirst( {
						any: got.length > 0,
						rehearsed: got.some( ( e ) => 'rehearsed' === e.type ),
					} );
				}
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => {
				setLoading( false );
				setMore( false );
			} );
	}, [] );

	useEffect( () => {
		load( 1, '', false );
	}, [ load ] );

	if ( hideEmpty && ! ( first && first.any ) ) {
		return null;
	}

	const pickFilter = ( key ) => {
		if ( key === filter ) {
			return;
		}
		setFilter( key );
		load( 1, key, false );
	};

	// Step one: ask what undoing this entry would bring back. Nothing changes.
	const askUndo = ( entry, text ) => {
		setAsking( entry.id );
		api( 'undo', { method: 'POST', data: { entries: [ entry.id ] } } )
			.then( ( res ) =>
				setUndo( { entry, text, plan: undoPlan( res, entry.id ) } )
			)
			.catch( ( e ) => toast.error( e.message ) )
			.finally( () => setAsking( 0 ) );
	};

	// Step two: the owner said yes to what the preview showed.
	const confirmUndo = () => {
		if ( ! undo ) {
			return;
		}
		setConfirming( true );
		api( 'undo', {
			method: 'POST',
			data: {
				entries: [ undo.entry.id ],
				confirm_token: undo.plan.token,
			},
		} )
			.then( ( res ) => {
				const outcome = undoOutcome( res );
				if ( outcome.done ) {
					toast.success( outcome.message );
				} else {
					toast.error( outcome.message );
				}
			} )
			.catch( ( e ) => toast.error( e.message ) )
			.finally( () => {
				setConfirming( false );
				setUndo( null );
				load( 1, filter, false );
				if ( onChange ) {
					onChange();
				}
			} );
	};

	const filters = [
		{ value: '', label: __( 'All', 'saddle' ) },
		{ value: 'executed', label: __( 'Changes', 'saddle' ) },
		{ value: 'denied', label: __( 'Blocked', 'saddle' ) },
	];
	if ( first && first.rehearsed ) {
		filters.push( {
			value: 'rehearsed',
			label: __( 'Rehearsed', 'saddle' ),
		} );
	}

	// Group into days, preserving order.
	const groups = groupByDay( entries );

	return (
		<div className="saddle-activity">
			<div className="saddle-activity__filters">
				{ title && (
					<h2 className="saddle-activity__heading">{ title }</h2>
				) }
				{ /* Nothing to filter until there is history. */ }
				{ first && first.any && (
					<FilterTabs
						aria-label={ __( 'Filter activity', 'saddle' ) }
						items={ filters }
						value={ filter }
						onChange={ pickFilter }
					/>
				) }
			</div>

			{ error && <Notice tone="danger">{ error }</Notice> }

			{ loading && <ActivitySkeleton /> }

			{ ! loading && entries.length === 0 && ! error && (
				<EmptyState
					title={
						filter === 'denied'
							? __( 'Nothing has been blocked', 'saddle' )
							: __( 'No activity yet', 'saddle' )
					}
					description={
						filter === 'denied'
							? __(
									'Attempts outside an app’s access appear here.',
									'saddle'
							  )
							: __(
									'Changes your apps make appear here. Reading is not logged.',
									'saddle'
							  )
					}
				/>
			) }

			{ ! loading &&
				groups.map( ( g ) => (
					<section className="saddle-activity__day" key={ g.label }>
						<h3 className="saddle-activity__daylabel">
							{ g.label }
						</h3>
						<ul className="saddle-activity__list">
							{ g.items.map( ( e, i ) => {
								const text = rowText( e, caps );
								const who = via( e );
								return (
									<li
										key={ `${ g.label }-${ i }` }
										className={ `saddle-activity__row${
											e.type === 'denied'
												? ' is-denied'
												: ''
										}` }
										title={ rowTitle( text, who ) }
									>
										{ e.app ? (
											<AppLogo
												className="saddle-activity__logo"
												app={ appKeyFromLabel( e.app ) }
											/>
										) : (
											<span
												className="saddle-activity__mark"
												aria-hidden="true"
											/>
										) }
										{ /* The whole line stays in the
										     text, so a screen reader reads
										     it all even when the row cuts it
										     short. */ }
										<span className="saddle-activity__summary">
											{ text }
											{ /* The logo is decorative and the
											     tooltip is not read out, so say
											     who did it here. */ }
											{ who && (
												<VisuallyHidden>
													{ ` ${ who }` }
												</VisuallyHidden>
											) }
										</span>
										{ canUndo( e ) && (
											<Button
												variant="link"
												size="sm"
												className="saddle-activity__undo"
												loading={ asking === e.id }
												disabled={ !! asking }
												onClick={ () =>
													askUndo( e, text )
												}
												aria-label={ sprintf(
													/* translators: %s: the change, as the activity log shows it. */
													__( 'Undo: %s', 'saddle' ),
													text
												) }
											>
												{ __( 'Undo', 'saddle' ) }
											</Button>
										) }
										{ wasUndone( e ) && (
											<span className="saddle-activity__undone">
												{ __( 'Undone', 'saddle' ) }
											</span>
										) }
										<time
											className="saddle-activity__time"
											dateTime={
												e.d
													? e.d.toISOString()
													: undefined
											}
											title={
												e.d
													? e.d.toLocaleString()
													: undefined
											}
										>
											{ e.d ? clock( e.d ) : '' }
										</time>
									</li>
								);
							} ) }
						</ul>
					</section>
				) ) }

			{ ! loading && entries.length < total && (
				<div className="saddle-activity__more">
					<Button
						variant="secondary"
						onClick={ () => load( page + 1, filter, true ) }
						loading={ more }
						disabled={ more }
					>
						{ __( 'Show older', 'saddle' ) }
					</Button>
				</div>
			) }

			<Drawer
				open={ !! undo }
				onOpenChange={ ( open ) =>
					! open && ! confirming && setUndo( null )
				}
				title={ __( 'Undo this change', 'saddle' ) }
				description={ undo ? undo.text : undefined }
				closeLabel={ __( 'Close', 'saddle' ) }
				size="md"
			>
				{ undo && (
					<UndoPreview
						plan={ undo.plan }
						confirming={ confirming }
						onConfirm={ confirmUndo }
						onCancel={ () => setUndo( null ) }
					/>
				) }
			</Drawer>
		</div>
	);
}
