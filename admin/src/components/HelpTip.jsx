/**
 * HelpTip — a small "?" affordance that reveals help text on hover or focus,
 * so long explanations stay one gesture away instead of cluttering the layout.
 * Relies on the app-root <TooltipProvider/> (mounted in App.jsx).
 */
import { Tooltip, HelpIcon } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';

export default function HelpTip( { label, side = 'top', className } ) {
	return (
		<Tooltip content={ label } side={ side }>
			<button
				type="button"
				className={ `saddle-helptip${
					className ? ` ${ className }` : ''
				}` }
				aria-label={ __( 'More information', 'saddle' ) }
			>
				<HelpIcon aria-hidden="true" />
			</button>
		</Tooltip>
	);
}
