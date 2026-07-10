/**
 * Activity — the full record of what connected apps have done through Saddle.
 *
 * Every executed change and every blocked attempt, newest first, grouped by
 * day. Filterable to just changes or just blocked attempts; pages in with
 * "Show more". Reads are never logged (see Saddle_Log), and the page says so.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Spinner,
	Notice,
	FilterTabs,
	EmptyState,
	Badge,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';

const PER_PAGE = 25;

const timeOf = ( gmt ) => {
	const d = new Date( gmt.endsWith( 'Z' ) ? gmt : gmt + 'Z' );
	return isNaN( d.getTime() ) ? null : d;
};

const dayLabel = ( d ) => {
	const today = new Date();
	const that = new Date( d.getFullYear(), d.getMonth(), d.getDate() );
	const now = new Date(
		today.getFullYear(),
		today.getMonth(),
		today.getDate()
	);
	const days = Math.round( ( now - that ) / 86400000 );
	if ( days === 0 ) {
		return __( 'Today', 'saddle' );
	}
	if ( days === 1 ) {
		return __( 'Yesterday', 'saddle' );
	}
	return d.toLocaleDateString( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		...( d.getFullYear() !== today.getFullYear()
			? { year: 'numeric' }
			: {} ),
	} );
};

const clock = ( d ) =>
	d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );

const FILTERS = [
	{ key: '', label: __( 'Everything', 'saddle' ) },
	{ key: 'executed', label: __( 'Changes', 'saddle' ) },
	{ key: 'denied', label: __( 'Blocked', 'saddle' ) },
];

export default function Activity() {
	const [ entries, setEntries ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ filter, setFilter ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ more, setMore ] = useState( false );
	const [ error, setError ] = useState( null );

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
				setEntries( ( prev ) =>
					append ? [ ...prev, ...res.entries ] : res.entries
				);
				setTotal( res.total || 0 );
				setPage( nextPage );
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

	const pickFilter = ( key ) => {
		if ( key === filter ) {
			return;
		}
		setFilter( key );
		load( 1, key, false );
	};

	// Group into days, preserving order.
	const groups = [];
	entries.forEach( ( e ) => {
		const d = timeOf( e.date );
		const label = d ? dayLabel( d ) : __( 'Earlier', 'saddle' );
		const last = groups[ groups.length - 1 ];
		if ( last && last.label === label ) {
			last.items.push( { ...e, d } );
		} else {
			groups.push( { label, items: [ { ...e, d } ] } );
		}
	} );

	return (
		<div className="saddle-activity">
			<h2 className="saddle-activity__title">
				{ __( 'Activity', 'saddle' ) }
			</h2>
			<p className="saddle-activity__lead">
				{ __(
					'Everything your connected apps have changed through Saddle — and every attempt that was blocked. Reading is never logged; only changes are.',
					'saddle'
				) }
			</p>

			<div className="saddle-activity__filters">
				<FilterTabs
					aria-label={ __( 'Filter activity', 'saddle' ) }
					items={ FILTERS.map( ( f ) => ( {
						value: f.key,
						label: f.label,
					} ) ) }
					value={ filter }
					onChange={ pickFilter }
				/>
				{ total > 0 && (
					<span className="saddle-activity__total">
						{ sprintf(
							/* translators: 1: entries shown, 2: total entries. */
							__( '%1$d of %2$d', 'saddle' ),
							entries.length,
							total
						) }
					</span>
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
									'When an app tries something outside its permissions, the attempt shows up here.',
									'saddle'
							  )
							: __(
									'Once a connected app makes a change, it shows up here.',
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
								>
									<span
										className="saddle-activity__mark"
										aria-hidden="true"
									/>
									<div className="saddle-activity__body">
										<span className="saddle-activity__summary">
											{ e.summary }
										</span>
										<span className="saddle-activity__meta">
											{ e.type === 'denied' && (
												<Badge tone="danger">
													{ __(
														'Blocked',
														'saddle'
													) }
												</Badge>
											) }
											{ e.action && (
												<code className="saddle-activity__action">
													{ e.action }
												</code>
											) }
											{ e.user &&
												sprintf(
													/* translators: %s: user login. */
													__( 'via %s', 'saddle' ),
													e.user
												) }
										</span>
									</div>
									<time
										className="saddle-activity__time"
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
						{ __( 'Show more', 'saddle' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
