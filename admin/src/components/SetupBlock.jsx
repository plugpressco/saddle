/**
 * Home's next step (#277, one line since #309): once an app is connected,
 * Saddle suggests the single next thing to do with it, in one sentence beside
 * its mark, with one button. It replaces the Setup checklist.
 *
 * Only two steps draw: "try" (an app that has not used Saddle yet) and
 * "choose" (an app that can only read). Connecting is Home's own block, so
 * the "connect" step draws nothing here, and neither does a module's task:
 * module pages carry their own setup.
 *
 * The step is worked out from the connection registry and the onboarding
 * state (`nextStep()`), never stored. Hiding it hides setup for good
 * (`setup.hide`); it is gone by itself once everything is done.
 */
import { Button, IconButton, XIcon } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { nextStep, setupBlock } from '../onboarding-logic';
import { APPS } from '../connect-apps';
import { BrandMark } from './icons';
import { withArg } from '../routes';

/**
 * @param {Object}   props
 * @param {string}   props.tier        The site's tier.
 * @param {?Array}   props.areas       From parseModules(), or null while
 *                                     loading (nothing is drawn yet).
 * @param {?Array}   props.connections From GET /connections, or null while
 *                                     loading (nothing is drawn yet).
 * @param {Object}   props.onboarding  GET /onboarding.
 * @param {string}   props.homeUrl     Home's address, for reopening the welcome.
 * @param {Function} props.onNavigate  Go to another place (old section names).
 * @param {Function} props.onHide      Hide setup.
 */
export default function SetupBlock( {
	tier,
	connections,
	areas,
	onboarding,
	homeUrl,
	onNavigate,
	onHide,
} ) {
	if ( null === connections || null === areas || ! onboarding ) {
		return null;
	}
	const step = nextStep(
		setupBlock( { connections, onboarding, tier, areas } ),
		connections
	);
	if ( ! step || ( 'try' !== step.id && 'choose' !== step.id ) ) {
		return null;
	}

	const app = APPS.find( ( a ) => a.key === step.app );
	const label = app ? app.label : __( 'your AI', 'saddle' );
	let text;
	let action;

	if ( 'try' === step.id ) {
		text = sprintf(
			/* translators: %s: the app name. */
			__( 'Want to try %s on this site? It takes a minute.', 'saddle' ),
			label
		);
		// The welcome reopens at "try it", for this app.
		action = (
			<Button
				variant="secondary"
				size="sm"
				href={ withArg(
					withArg( withArg( homeUrl, 'setup', '1' ), 'step', 'try' ),
					'app',
					step.app
				) }
			>
				{ __( 'Continue', 'saddle' ) }
			</Button>
		);
	} else {
		text = sprintf(
			/* translators: %s: the app name. */
			__(
				'%s can only look right now. Want it to edit content too?',
				'saddle'
			),
			label
		);
		action = (
			<Button
				variant="secondary"
				size="sm"
				onClick={ () => onNavigate( 'connect' ) }
			>
				{ __( 'Choose', 'saddle' ) }
			</Button>
		);
	}

	return (
		<div className="saddle-nudge">
			<span className="saddle-nudge__mark" aria-hidden="true">
				<BrandMark />
			</span>
			<p className="saddle-nudge__text">{ text }</p>
			{ action }
			<IconButton
				size="sm"
				aria-label={ __( 'Hide this', 'saddle' ) }
				onClick={ onHide }
			>
				<XIcon size={ 16 } />
			</IconButton>
		</div>
	);
}
