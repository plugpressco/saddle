/**
 * Permissions — "What your AI can do", in two plain choices.
 *
 * Reading vs. reading & writing, each described in a sentence. The full list of
 * 18 tools lives behind a disclosure for anyone who wants to verify exactly
 * what's included — invisible for everyone else. Nothing saves until you apply.
 */
import { useState, useMemo } from '@wordpress/element';
import {
	CardRadioGroup,
	Collapsible,
	ApplyBar,
	toast,
	PageHeader,
	Tooltip,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { api, LEVELS, levelKey, tierUnlocks } from '../api';
import { LevelIcon } from './icons';

// The saved (server-side) set of individually-disabled ability short names.
const savedDisabledSet = ( caps ) =>
	new Set(
		caps.filter( ( c ) => c.enabled === false ).map( ( c ) => c.short )
	);

const LANES = [
	{ key: 'look', title: __( 'Read', 'saddle' ) },
	{ key: 'change', title: __( 'Create & edit', 'saddle' ) },
	{ key: 'remove', title: __( 'Delete', 'saddle' ) },
];

// Group a lane's capabilities into alphabetically-ordered category buckets so a
// crowded lane (≈100 chips once add-ons like Divi are active) stays scannable.
const groupByCategory = ( items ) => {
	const map = new Map();
	items.forEach( ( c ) => {
		const key = c.category || __( 'Other', 'saddle' );
		if ( ! map.has( key ) ) {
			map.set( key, [] );
		}
		map.get( key ).push( c );
	} );
	return [ ...map.entries() ]
		.map( ( [ category, list ] ) => ( { category, list } ) )
		.sort( ( a, b ) => a.category.localeCompare( b.category ) );
};

export default function Permissions( {
	caps,
	savedTier,
	onTierSaved,
	onCapsChanged,
} ) {
	const [ choice, setChoice ] = useState( levelKey( savedTier ) );
	const [ saving, setSaving ] = useState( false );
	const [ showAll, setShowAll ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ localDisabled, setLocalDisabled ] = useState( () =>
		savedDisabledSet( caps )
	);

	// Free text filter across a tool's name/id/description. Empty matches all.
	const q = query.trim().toLowerCase();
	const matchesQuery = ( c ) =>
		! q ||
		c.label.toLowerCase().includes( q ) ||
		c.short.toLowerCase().includes( q ) ||
		( c.description || '' ).toLowerCase().includes( q );

	const dirty = choice !== levelKey( savedTier );

	const savedDisabled = useMemo( () => savedDisabledSet( caps ), [ caps ] );
	const abilityDelta =
		[ ...localDisabled ].filter( ( s ) => ! savedDisabled.has( s ) )
			.length +
		[ ...savedDisabled ].filter( ( s ) => ! localDisabled.has( s ) ).length;
	const abilitiesDirty = abilityDelta > 0;
	const pending = dirty || abilitiesDirty;

	const toggleAbility = ( short ) => {
		setLocalDisabled( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( short ) ) {
				next.delete( short );
			} else {
				next.add( short );
			}
			return next;
		} );
	};

	const byLane = useMemo( () => {
		const groups = { look: [], change: [], remove: [] };
		caps.forEach( ( c ) => groups[ c.lane ]?.push( c ) );
		Object.values( groups ).forEach( ( list ) =>
			list.sort( ( a, b ) => a.label.localeCompare( b.label ) )
		);
		return groups;
	}, [ caps ] );

	// One Save applies everything pending — the level and any individual tool
	// changes — in a single gesture. A half that fails stays dirty and says so;
	// the half that saved is settled.
	const apply = () => {
		setSaving( true );

		const jobs = [];
		if ( dirty ) {
			jobs.push(
				api( 'settings', {
					method: 'POST',
					data: { tier: choice },
				} ).then( ( res ) => {
					onTierSaved( res.tier, res.domain_warning );
					setChoice( levelKey( res.tier ) );
				} )
			);
		}
		if ( abilitiesDirty ) {
			jobs.push(
				api( 'abilities', {
					method: 'POST',
					data: { disabled: [ ...localDisabled ] },
				} ).then( () => {
					if ( onCapsChanged ) {
						onCapsChanged();
					}
				} )
			);
		}

		Promise.allSettled( jobs )
			.then( ( results ) => {
				const failed = results.filter(
					( r ) => r.status === 'rejected'
				);
				if ( ! failed.length ) {
					toast.success( __( 'Saved.', 'saddle' ) );
				} else {
					toast.error(
						failed[ 0 ].reason?.message ||
							__(
								'Some changes could not be saved — they’re still marked below.',
								'saddle'
							)
					);
				}
			} )
			.finally( () => setSaving( false ) );
	};

	const cancel = () => {
		setChoice( levelKey( savedTier ) );
		setLocalDisabled( savedDisabled );
	};

	const enabledCount = caps.filter(
		( c ) => tierUnlocks( choice, c.tier ) && ! localDisabled.has( c.short )
	).length;

	let deltaLine;
	if ( choice === 'admin' ) {
		deltaLine = __(
			'This also lets your AI manage plugins, themes, and site settings. Overwrites and deletions always ask you first.',
			'saddle'
		);
	} else if ( choice === 'write' ) {
		deltaLine = __(
			'This lets your AI create and edit content. Deleting will always ask you first.',
			'saddle'
		);
	} else {
		deltaLine = __(
			'Your AI will only be able to read. It won’t be able to make any changes.',
			'saddle'
		);
	}

	return (
		<div className="saddle-perm">
			<PageHeader
				title={ __( 'Permissions', 'saddle' ) }
				description={ __(
					'Pick how much your connected apps are allowed to do. You can change this whenever you like.',
					'saddle'
				) }
			/>

			<CardRadioGroup
				aria-label={ __( 'What your AI can do', 'saddle' ) }
				value={ choice }
				onChange={ setChoice }
				options={ LEVELS.map( ( lvl ) => ( {
					value: lvl.key,
					icon: <LevelIcon name={ lvl.icon } />,
					title: lvl.title,
					description: lvl.short,
				} ) ) }
			/>

			<Collapsible
				className="saddle-perm__all"
				open={ showAll }
				onOpenChange={ setShowAll }
				trigger={ sprintf(
					/* translators: %d: number of tools active at the chosen level. */
					_n(
						'See everything it can do (%d tool)',
						'See everything it can do (%d tools)',
						enabledCount,
						'saddle'
					),
					enabledCount
				) }
			>
				<p className="saddle-lanes__hint">
					{ __(
						'Click any tool below to turn it off individually — that stays off no matter which level above is chosen.',
						'saddle'
					) }
				</p>

				<input
					type="search"
					className="saddle-lanes__filter"
					value={ query }
					onChange={ ( e ) => setQuery( e.target.value ) }
					placeholder={ __( 'Filter tools…', 'saddle' ) }
					aria-label={ __( 'Filter tools by name', 'saddle' ) }
				/>

				<div className="saddle-lanes">
					{ LANES.map( ( lane ) => {
						const all = byLane[ lane.key ] || [];
						const items = all.filter( matchesQuery );
						const laneOn = all.some( ( c ) =>
							tierUnlocks( choice, c.tier )
						);
						const groups = groupByCategory( items );
						return (
							<section
								key={ lane.key }
								className={ `saddle-lane saddle-lane--${
									lane.key
								}${ laneOn ? ' is-on' : ' is-off' }` }
							>
								<header className="saddle-lane__head">
									<h3 className="saddle-lane__title">
										{ lane.title }
									</h3>
									<span className="saddle-lane__count">
										{ laneOn
											? __( 'on', 'saddle' )
											: __( 'off', 'saddle' ) }
									</span>
								</header>
								<div
									className="saddle-lane__rail"
									aria-hidden="true"
								/>
								{ groups.length === 0 && q ? (
									<p className="saddle-lane__empty">
										{ __( 'No matches', 'saddle' ) }
									</p>
								) : (
									groups.map( ( { category, list } ) => (
										<div
											key={ category }
											className="saddle-catgroup"
										>
											<h4 className="saddle-catgroup__title">
												{ category }
												<span className="saddle-catgroup__count">
													{ list.length }
												</span>
											</h4>
											<ul className="saddle-chips">
												{ list.map( ( c ) => {
													const on = tierUnlocks(
														choice,
														c.tier
													);
													const disabled =
														localDisabled.has(
															c.short
														);
													return (
														<li key={ c.name }>
															<Tooltip
																content={
																	c.description
																}
															>
																<button
																	type="button"
																	className={ `saddle-chip${
																		on
																			? ' is-on'
																			: ' is-off'
																	}${
																		disabled
																			? ' is-disabled'
																			: ''
																	}` }
																	aria-pressed={
																		! disabled
																	}
																	onClick={ () =>
																		toggleAbility(
																			c.short
																		)
																	}
																>
																	<span className="saddle-chip__label">
																		{
																			c.label
																		}
																	</span>
																	{ disabled ? (
																		<span className="saddle-chip__off">
																			{ __(
																				'off',
																				'saddle'
																			) }
																		</span>
																	) : (
																		c.destructive && (
																			<span className="saddle-chip__shield">
																				{ __(
																					'asks first',
																					'saddle'
																				) }
																			</span>
																		)
																	) }
																</button>
															</Tooltip>
														</li>
													);
												} ) }
											</ul>
										</div>
									) )
								) }
							</section>
						);
					} ) }
				</div>
			</Collapsible>

			{ pending && (
				<ApplyBar
					message={
						[
							dirty ? deltaLine : null,
							abilitiesDirty
								? sprintf(
										/* translators: %d: number of individually-toggled tools. */
										_n(
											'%d tool changed.',
											'%d tools changed.',
											abilityDelta,
											'saddle'
										),
										abilityDelta
								  )
								: null,
						]
							.filter( Boolean )
							.join( ' ' ) +
						' ' +
						__( 'Not saved yet.', 'saddle' )
					}
					saveLabel={ __( 'Save', 'saddle' ) }
					discardLabel={ __( 'Cancel', 'saddle' ) }
					saving={ saving }
					onSave={ apply }
					onDiscard={ cancel }
				/>
			) }
		</div>
	);
}
