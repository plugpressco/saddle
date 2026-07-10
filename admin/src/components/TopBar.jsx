/**
 * Slim, persistent top bar: brand, the current safety status in plain words,
 * and the three-section nav. Calm and quiet — the status is the point.
 */
import { __ } from '@wordpress/i18n';
import { Button, Tabs, StatusDot } from '@plugpress/ui';
import { levelFor } from '../api';
import { BrandMark } from './icons';

// Safety tone → design-system dot tone. Read-only is the calm state; any
// write power shows as "attention", paused as switched-off.
const DOT_TONES = {
	safe: 'success',
	active: 'warning',
	paused: 'neutral',
};

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
						<StatusDot tone={ DOT_TONES[ tone ] } />
						{ paused ? __( 'Paused', 'saddle' ) : level.title }
					</span>
					{ onTogglePause && (
						<Button
							variant="ghost"
							size="sm"
							onClick={ onTogglePause }
							loading={ pausing }
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
				<Tabs
					className="saddle-nav"
					aria-label={ __( 'Sections', 'saddle' ) }
					items={ tabs.map( ( t ) => ( {
						value: t.name,
						label: t.title,
					} ) ) }
					value={ active }
					onChange={ onSelect }
				/>
			) }
		</header>
	);
}
