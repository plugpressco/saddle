/**
 * Slim, persistent top bar: brand, the current safety status in plain words,
 * and the three-section nav. Calm and quiet — the status is the point.
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { levelFor } from '../api';

export default function TopBar( {
	tier,
	tabs,
	active,
	onSelect,
	paused,
	onTogglePause,
	pausing,
} ) {
	const level = levelFor( tier );
	let tone = level.key === 'read' ? 'safe' : 'active';
	if ( paused ) {
		tone = 'paused';
	}

	return (
		<header className={ `saddle-top saddle-top--${ tone }` }>
			<div className="saddle-top__row">
				<div className="saddle-top__brand">
					<span className="saddle-top__mark" aria-hidden="true">
						S
					</span>
					<span className="saddle-top__name">
						{ __( 'Saddle', 'saddle' ) }
					</span>
				</div>

				<div className="saddle-top__right">
					<span
						className={ `saddle-top__status saddle-top__status--${ tone }` }
					>
						<span className="saddle-top__dot" aria-hidden="true" />
						{ paused ? __( 'Paused', 'saddle' ) : level.title }
					</span>
					{ onTogglePause && (
						<Button
							variant="tertiary"
							size="small"
							onClick={ onTogglePause }
							isBusy={ pausing }
							disabled={ pausing }
						>
							{ paused
								? __( 'Resume', 'saddle' )
								: __( 'Pause', 'saddle' ) }
						</Button>
					) }
				</div>
			</div>

			{ tabs && tabs.length > 0 && (
				<nav
					className="saddle-nav"
					aria-label={ __( 'Sections', 'saddle' ) }
				>
					{ tabs.map( ( t ) => (
						<button
							key={ t.name }
							type="button"
							aria-current={
								active === t.name ? 'page' : undefined
							}
							className={ `saddle-nav__item${
								active === t.name ? ' is-active' : ''
							}` }
							onClick={ () => onSelect( t.name ) }
						>
							{ t.title }
						</button>
					) ) }
				</nav>
			) }
		</header>
	);
}
