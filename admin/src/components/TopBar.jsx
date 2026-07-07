/**
 * Slim, persistent top bar: brand, the current safety status in plain words,
 * and the three-section nav. Calm and quiet — the status is the point.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '../ui';
import { levelFor } from '../api';
import { ThemeIcon, BrandMark } from './icons';

const THEME_LABELS = {
	system: __( 'System theme', 'saddle' ),
	light: __( 'Light theme', 'saddle' ),
	dark: __( 'Dark theme', 'saddle' ),
};

export default function TopBar( {
	tier,
	tabs,
	active,
	onSelect,
	paused,
	onTogglePause,
	pausing,
	theme,
	onCycleTheme,
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
						<BrandMark />
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
					{ onCycleTheme && (
						<button
							type="button"
							className="saddle-top__theme"
							onClick={ onCycleTheme }
							title={ sprintf(
								/* translators: %s: current theme label. */
								__( '%s — click to change', 'saddle' ),
								THEME_LABELS[ theme ] || THEME_LABELS.system
							) }
							aria-label={
								THEME_LABELS[ theme ] || THEME_LABELS.system
							}
						>
							<ThemeIcon mode={ theme } />
						</button>
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
