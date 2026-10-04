/**
 * Activity — the full record of what connected apps have done through Saddle,
 * Home's feed since #309.
 *
 * Every executed change and every blocked attempt, newest first, grouped by
 * day. Each row is one line: the logo of the app that did it (a dot when no
 * app did), what happened, and the time; who did it ("via Claude Code", or
 * "by you" for the owner's own steps) is the row's tooltip.
 * Filterable to just changes or just blocked attempts, and to rehearsals when
 * the first page holds one; pages in with "Show older". Reads are never
 * logged (see Saddle_Log); the empty state says so.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Spinner,
	Notice,
	FilterTabs,
	EmptyState,
	VisuallyHidden,
} from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { actionLabel, clock, groupByDay, madeBy } from '../activity-format';
import { AppLogo, appKeyFromLabel } from './icons';

const PER_PAGE = 25;

/**
 * Who made an entry, for the row's tooltip: "via Claude Code", "by you".
 *
 * @param {Object} e Audit-log entry.
 * @return {string|undefined} The line, or undefined when no one is named.
 */
const via = ( e ) => madeBy( e, saddleData.user || '' ) || undefined;

/**
 * @param {Object}   props
 * @param {Object[]} props.caps      The capabilities list, for tool names.
 * @param {string=}  props.title     A heading drawn on the filters' row (Home).
 * @param {boolean=} props.hideEmpty Draw nothing at all, not even while
 *                                   loading, when the log is empty (Home with
 *                                   no app connected).
 */
export default function Activity( { caps = [], title, hideEmpty = false } ) {
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

			{ loading && (
				<div className="saddle-activity__loading">
					<Spinner />
				</div>
			) }

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
							{ g.items.map( ( e, i ) => (
								<li
									key={ `${ g.label }-${ i }` }
									className={ `saddle-activity__row${
										e.type === 'denied' ? ' is-denied' : ''
									}` }
									title={ via( e ) }
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
									{ /* A blocked or rehearsed call reads as the tool's
									     own name ("Blocked · Update option · needs
									     Admin"). */ }
									<span className="saddle-activity__summary">
										{ 'denied' === e.type ||
										'rehearsed' === e.type
											? actionLabel( e, caps )
											: e.summary }
										{ /* The logo is decorative and the tooltip is
										     not read out, so say who did it here. */ }
										{ via( e ) && (
											<VisuallyHidden>
												{ ` ${ via( e ) }` }
											</VisuallyHidden>
										) }
									</span>
									<time
										className="saddle-activity__time"
										dateTime={
											e.d ? e.d.toISOString() : undefined
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
							) ) }
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
		</div>
	);
}
