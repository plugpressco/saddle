/**
 * Activity — the full record of what connected apps have done through Saddle,
 * Home's feed since #309.
 *
 * Every executed change and every blocked attempt, newest first, grouped by
 * day, each row led by the logo of the app that did it. Filterable to just
 * changes or just blocked attempts; pages in with "Show older". Reads are
 * never logged (see Saddle_Log); a tip beside the filters says so.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Spinner,
	Notice,
	FilterTabs,
	EmptyState,
	HelpTip,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { actionLabel, clock, groupByDay } from '../activity-format';
import { AppLogo, appKeyFromLabel } from './icons';

const PER_PAGE = 25;

const FILTERS = [
	{ key: '', label: __( 'Everything', 'saddle' ) },
	{ key: 'executed', label: __( 'Changes', 'saddle' ) },
	{ key: 'denied', label: __( 'Blocked', 'saddle' ) },
	{ key: 'rehearsed', label: __( 'Rehearsed', 'saddle' ) },
];

/**
 * @param {Object}   props
 * @param {Object[]} props.caps  The capabilities list, for tool names.
 * @param {string=}  props.title A heading drawn on the filters' row (Home).
 */
export default function Activity( { caps = [], title } ) {
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
	const groups = groupByDay( entries );

	return (
		<div className="saddle-activity">
			<div className="saddle-activity__filters">
				{ title && (
					<h2 className="saddle-activity__heading">{ title }</h2>
				) }
				<FilterTabs
					aria-label={ __( 'Filter activity', 'saddle' ) }
					items={ FILTERS.map( ( f ) => ( {
						value: f.key,
						label: f.label,
					} ) ) }
					value={ filter }
					onChange={ pickFilter }
				/>
				<HelpTip>
					{ __(
						'Reading is never logged; only changes are.',
						'saddle'
					) }
				</HelpTip>
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
									<div className="saddle-activity__body">
										{ /* A blocked or rehearsed call reads as the tool's
										     own name ("Blocked · Update option · needs
										     Admin"); the raw tool name stays in the title
										     for anyone who wants it. */ }
										<span
											className="saddle-activity__summary"
											title={ e.action || undefined }
										>
											{ 'denied' === e.type ||
											'rehearsed' === e.type
												? actionLabel( e, caps )
												: e.summary }
										</span>
										<span className="saddle-activity__meta">
											{ ( e.app || e.user ) &&
												sprintf(
													/* translators: %s: the app that made the change ("Claude Code"), or the user login when no app did. */
													__( 'via %s', 'saddle' ),
													e.app || e.user
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
						{ __( 'Show older', 'saddle' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
