/**
 * Permissions — "What your AI can do", in two plain choices.
 *
 * Reading vs. reading & writing, each described in a sentence. The full list of
 * 18 tools lives behind a disclosure for anyone who wants to verify exactly
 * what's included — invisible for everyone else. Nothing saves until you apply.
 */
import { useState, useMemo } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
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

export default function Permissions( {
	caps,
	savedTier,
	onTierSaved,
	onCapsChanged,
} ) {
	const [ choice, setChoice ] = useState( levelKey( savedTier ) );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ showAll, setShowAll ] = useState( false );
	const [ localDisabled, setLocalDisabled ] = useState( () =>
		savedDisabledSet( caps )
	);

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
		setNotice( null );

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
					setNotice( {
						type: 'success',
						message: __( 'Saved.', 'saddle' ),
					} );
				} else {
					setNotice( {
						type: 'error',
						message:
							failed[ 0 ].reason?.message ||
							__(
								'Some changes could not be saved — they’re still marked below.',
								'saddle'
							),
					} );
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
			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<h2 className="saddle-perm__title">
				{ __( 'What your AI can do', 'saddle' ) }
			</h2>
			<p className="saddle-perm__lead">
				{ __(
					'Pick how much your connected apps are allowed to do. You can change this whenever you like.',
					'saddle'
				) }
			</p>

			<div
				className="saddle-levels"
				role="radiogroup"
				aria-label={ __( 'What your AI can do', 'saddle' ) }
			>
				{ LEVELS.map( ( lvl ) => (
					<button
						key={ lvl.key }
						type="button"
						role="radio"
						aria-checked={ choice === lvl.key }
						className={ `saddle-levelcard${
							choice === lvl.key ? ' is-active' : ''
						}` }
						onClick={ () => setChoice( lvl.key ) }
					>
						<span className="saddle-levelcard__glyph">
							<LevelIcon name={ lvl.icon } />
						</span>
						<span className="saddle-levelcard__title">
							{ lvl.title }
						</span>
						<span className="saddle-levelcard__desc">
							{ lvl.short }
						</span>
					</button>
				) ) }
			</div>

			<button
				type="button"
				className="saddle-disclosure"
				aria-expanded={ showAll }
				onClick={ () => setShowAll( ( v ) => ! v ) }
			>
				<span className="saddle-disclosure__caret" aria-hidden="true">
					{ showAll ? '▾' : '▸' }
				</span>
				{ sprintf(
					/* translators: %d: number of tools active at the chosen level. */
					_n(
						'See everything it can do (%d tool)',
						'See everything it can do (%d tools)',
						enabledCount,
						'saddle'
					),
					enabledCount
				) }
			</button>

			{ showAll && (
				<p className="saddle-lanes__hint">
					{ __(
						'Click any tool below to turn it off individually — that stays off no matter which level above is chosen.',
						'saddle'
					) }
				</p>
			) }

			{ showAll && (
				<div className="saddle-lanes">
					{ LANES.map( ( lane ) => {
						const items = byLane[ lane.key ] || [];
						const laneOn = items.some( ( c ) =>
							tierUnlocks( choice, c.tier )
						);
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
								<ul className="saddle-chips">
									{ items.map( ( c ) => {
										const on = tierUnlocks(
											choice,
											c.tier
										);
										const disabled = localDisabled.has(
											c.short
										);
										return (
											<li key={ c.name }>
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
													aria-pressed={ ! disabled }
													title={ c.description }
													onClick={ () =>
														toggleAbility( c.short )
													}
												>
													<span className="saddle-chip__label">
														{ c.label }
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
											</li>
										);
									} ) }
								</ul>
							</section>
						);
					} ) }
				</div>
			) }

			{ pending && (
				<div className="saddle-applybar">
					<div className="saddle-applybar__summary">
						<span>
							{ [
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
								.join( ' ' ) }{ ' ' }
							{ __( 'Not saved yet.', 'saddle' ) }
						</span>
					</div>
					<div className="saddle-applybar__actions">
						<Button
							variant="tertiary"
							onClick={ cancel }
							disabled={ saving }
						>
							{ __( 'Cancel', 'saddle' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ apply }
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Save', 'saddle' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}
